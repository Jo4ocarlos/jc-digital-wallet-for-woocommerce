<?php
namespace WooDigitalWallet\Frontend;

use WooDigitalWallet\Core\WalletManager;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CheckoutCashback {

    // Status em que o pedido está "morto": promessa cancelada e saldo usado devolvido.
    const DEAD_STATUSES = [ 'cancelled', 'refunded' ];

    // Status em que o pedido segue adiante sem o checkout ter rodado (rede de segurança do débito).
    const ACTIVE_STATUSES = [ 'processing', 'completed', 'on-hold' ];

    public function register() {
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_checkout_scripts' ] );
        add_action( 'woocommerce_review_order_before_payment', [ $this, 'render_wallet_checkbox' ] );
        add_action( 'woocommerce_review_order_before_order_total', [ $this, 'render_cashback_notice' ] );
        add_action( 'woocommerce_checkout_update_order_review', [ $this, 'save_checkbox_session' ] );
        add_action( 'woocommerce_cart_calculate_fees', [ $this, 'apply_wallet_discount' ] );
        add_action( 'woocommerce_checkout_create_order_fee_item', [ $this, 'tag_wallet_fee_item' ], 10, 4 );

        // Débito do saldo ANTES do pagamento, nos dois checkouts do WooCommerce.
        // A Store API (/wp-json/wc/store/v1/checkout, usada pelo checkout em blocos) fica no ar mesmo
        // em lojas com checkout clássico e não dispara woocommerce_checkout_order_processed.
        add_action( 'woocommerce_checkout_order_processed', [ $this, 'deduct_balance_on_order' ], 10, 3 );
        add_action( 'woocommerce_store_api_checkout_order_processed', [ $this, 'deduct_balance_on_store_api_order' ], 10, 1 );

        // ====================================================================
        // SHADOW LEDGER: Máquina de Estados do Cashback
        // ====================================================================

        // 1. A PROMESSA: Pedido processado no checkout. Injeta saldo 'pending'.
        add_action( 'woocommerce_checkout_order_processed', [ $this, 'prometer_cashback' ], 20, 1 );
        add_action( 'woocommerce_store_api_checkout_order_processed', [ $this, 'prometer_cashback_store_api' ], 20, 1 );

        // 2. A LIQUIDAÇÃO: Pagamento confirmado (Gateway bateu ou admin mudou pra completed).
        add_action( 'woocommerce_payment_complete', [ $this, 'liquidar_cashback' ], 10, 1 );
        add_action( 'woocommerce_order_status_completed', [ $this, 'liquidar_cashback' ], 10, 1 );

        // 3. CANCELAMENTO, REEMBOLSO E REATIVAÇÃO: toda mudança de status reconcilia o ledger.
        // 'failed' NÃO cancela nada: pedido falho pode ser pago de novo pelo cliente com o mesmo desconto.
        add_action( 'woocommerce_order_status_changed', [ $this, 'on_status_changed' ], 10, 4 );
        add_action( 'woocommerce_order_refunded', [ $this, 'on_order_refunded' ], 10, 2 );
    }

    /* =========================================================
       SHADOW LEDGER
    ========================================================= */

    public function prometer_cashback_store_api( $order ) {
        if ( $order instanceof \WC_Order ) {
            $this->prometer_cashback( $order->get_id() );
        }
    }

    public function prometer_cashback( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order || 'yes' === $order->get_meta( '_wcw_promised' ) ) return;

        $user_id = $order->get_customer_id();
        if ( ! $user_id ) return;

        $recharge_id     = (int) get_option( 'wcw_recharge_product_id', 0 );
        $recharge_amount = 0;

        //  Verifica se o cliente está comprando uma RECARGA MANUAL
        foreach ( $order->get_items() as $item ) {
            if ( (int) $item->get_product_id() === $recharge_id ) {
                $recharge_amount += (float) $item->get_total();
            }
        }

        // SE FOR RECARGA: Promete a Recarga
        if ( $recharge_amount > 0 ) {
            $type        = 'recarga';
            $amount      = $recharge_amount;
            $description = 'Recarga de Saldo (Pedido #' . $order_id . ')';
            $note        = 'Recarga registrada como Pendente (Shadow Ledger).';
        }
        // SE FOR COMPRA NORMAL: Calcula e Promete o Cashback
        else {
            $type        = 'cashback';
            $amount      = $this->calculate_eligible_cashback( $order->get_items(), $this->get_order_wallet_fee( $order ) );
            $description = 'Cashback Pendente (Pedido #' . $order_id . ')';
            $note        = 'Cashback registrado como Pendente aguardando liquidação.';
        }

        $amount = WalletManager::normalize_amount( $amount );

        // A chave única impede promessa duplicada mesmo com duas requisições simultâneas
        if ( $amount > 0 && false !== WalletManager::add_funds( $user_id, $amount, $description, $order_id, 'pending', 'promise:' . $order_id ) ) {
            $order->update_meta_data( '_wcw_promised_amount', $amount );
            $order->update_meta_data( '_wcw_promise_type', $type );
            if ( 'cashback' === $type ) {
                $order->update_meta_data( '_wcw_earned_cashback', $amount );
            }
            $order->add_order_note( $note );
        }

        $order->update_meta_data( '_wcw_promised', 'yes' );
        $order->save();
    }

    public function liquidar_cashback( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) return;

        // Sobrescreve a string de Promessa com a string de Liquidação
        $nova_descricao = 'recarga' === $order->get_meta( '_wcw_promise_type' )
            ? 'Recarga de Saldo (Pedido #' . $order_id . ')'
            : 'Cashback Liberado (Pedido #' . $order_id . ')';

        // UPDATE ... WHERE status = 'pending' é atômico: webhook duplicado não libera duas vezes
        $atualizado = WalletManager::transition_order_entries( $order_id, 'pending', 'cleared', $nova_descricao );

        if ( $atualizado > 0 ) {
            $order->update_meta_data( '_wcw_cleared', 'yes' );
            $order->add_order_note( 'Liquidação confirmada. Saldo Pendente movido para Saldo Disponível.' );
            $order->save();
        }
    }

    public function on_status_changed( $order_id, $from, $to, $order = null ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) return;

        $this->ensure_wallet_debit( $order, $to );
        $this->sync_used_balance( $order );
        $this->sync_promised_credit( $order );

        // woocommerce_order_status_completed roda ANTES deste hook. Num pedido cancelado que volta
        // direto para concluído, a promessa só voltou a 'pending' agora, então liquida aqui.
        if ( 'completed' === $to ) {
            $this->liquidar_cashback( $order_id );
        }
    }

    public function on_order_refunded( $order_id, $refund_id ) {
        // Recarrega o pedido para ler o total reembolsado já atualizado
        $order = wc_get_order( $order_id );
        if ( ! $order ) return;

        $this->sync_promised_credit( $order );
    }

    /**
     * Rede de segurança: pedido com desconto da carteira que chegou a um status ativo sem débito registrado
     * (fluxo de checkout de terceiros, por exemplo). Debita à força, o saldo pode ficar negativo.
     */
    private function ensure_wallet_debit( $order, $to ) {
        if ( ! in_array( $to, self::ACTIVE_STATUSES, true ) || '' !== (string) $order->get_meta( '_wcw_used_balance' ) ) {
            return;
        }

        $fee     = $this->get_order_wallet_fee( $order );
        $user_id = $order->get_customer_id();
        if ( $fee <= 0 || ! $user_id ) return;

        $order_id = $order->get_id();
        if ( false !== WalletManager::revert_funds( $user_id, $fee, 'Pagamento do Pedido #' . $order_id, $order_id, 'cleared', 'pay:' . $order_id . ':1' ) ) {
            $order->update_meta_data( '_wcw_used_balance', $fee );
            $order->update_meta_data( '_wcw_pay_seq', 1 );
            $order->add_order_note( 'ALERTA: desconto da carteira sem débito registrado no checkout. Saldo debitado agora; confira se ficou negativo.' );
            $order->save();
        }
    }

    /**
     * Devolve o saldo usado quando o pedido morre e debita de novo se ele for reativado.
     * Sem a segunda parte, cancelar e depois pagar o mesmo pedido dava o desconto de graça.
     */
    private function sync_used_balance( $order ) {
        $used    = (float) $order->get_meta( '_wcw_used_balance' );
        $user_id = $order->get_customer_id();
        if ( $used <= 0 || ! $user_id ) return;

        $order_id = $order->get_id();
        $dead     = in_array( $order->get_status(), self::DEAD_STATUSES, true );
        $refunded = 'yes' === $order->get_meta( '_wcw_balance_refunded' );
        $seq      = (int) $order->get_meta( '_wcw_used_seq' ) + 1;

        if ( $dead && ! $refunded ) {
            $ok = WalletManager::add_funds( $user_id, $used, 'Estorno de Pagamento (Pedido Cancelado #' . $order_id . ')', $order_id, 'cleared', 'used-back:' . $order_id . ':' . $seq );
            if ( false !== $ok ) {
                $order->update_meta_data( '_wcw_balance_refunded', 'yes' );
                $order->update_meta_data( '_wcw_used_seq', $seq );
                $order->add_order_note( 'Pedido cancelado/reembolsado. Saldo utilizado foi devolvido.' );
                $order->save();
            }
        } elseif ( ! $dead && $refunded ) {
            $ok = WalletManager::revert_funds( $user_id, $used, 'Pagamento do Pedido #' . $order_id . ' (pedido reativado)', $order_id, 'cleared', 'used-again:' . $order_id . ':' . $seq );
            if ( false !== $ok ) {
                $order->delete_meta_data( '_wcw_balance_refunded' );
                $order->update_meta_data( '_wcw_used_seq', $seq );
                $order->add_order_note( 'Pedido reativado. O saldo devolvido no cancelamento foi debitado de novo (pode ficar negativo).' );
                $order->save();
            }
        }
    }

    /**
     * Mantém o crédito prometido (cashback ou recarga) coerente com o estado do pedido:
     * cancelado/reembolsado zera, reembolso parcial reduz na proporção, reativação devolve.
     * Créditos já liberados são revertidos com débito forçado (o cliente pode ter gastado).
     */
    private function sync_promised_credit( $order ) {
        $promised = (float) $order->get_meta( '_wcw_promised_amount' );
        $user_id  = $order->get_customer_id();
        if ( $promised <= 0 || ! $user_id ) return;

        $order_id = $order->get_id();
        $dead     = in_array( $order->get_status(), self::DEAD_STATUSES, true );
        $cleared  = 'yes' === $order->get_meta( '_wcw_cleared' );
        $label    = 'recarga' === $order->get_meta( '_wcw_promise_type' ) ? 'Recarga' : 'Cashback';
        $dirty    = false;

        if ( ! $cleared ) {
            if ( $dead ) {
                if ( WalletManager::transition_order_entries( $order_id, 'pending', 'cancelled', $label . ' Cancelado (Pedido #' . $order_id . ')' ) > 0 ) {
                    $order->add_order_note( 'Pedido cancelado/reembolsado. ' . $label . ' pendente foi destruído.' );
                    $order->save();
                }
                return;
            }

            if ( WalletManager::transition_order_entries( $order_id, 'cancelled', 'pending', $label . ' Pendente (Pedido #' . $order_id . ')' ) > 0 ) {
                $order->add_order_note( 'Pedido reativado. ' . $label . ' voltou para Pendente.' );
                $dirty = true;
            }
        }

        // Quanto do crédito já deveria ter sido revertido, dado o estado atual do pedido
        $wanted   = $dead ? $promised : min( $promised, round( $promised * $this->get_refund_ratio( $order ), 2 ) );
        $reverted = (float) $order->get_meta( '_wcw_credit_reverted' );
        $delta    = round( $wanted - $reverted, 2 );
        $seq      = (int) $order->get_meta( '_wcw_credit_seq' ) + 1;
        $ok       = false;

        if ( $delta > 0 ) {
            // Enquanto pendente, o débito também nasce pendente e acompanha o crédito na liquidação
            $ok = WalletManager::revert_funds( $user_id, $delta, 'Reversão de ' . $label . ' (Pedido #' . $order_id . ' reembolsado/cancelado)', $order_id, $cleared ? 'cleared' : 'pending', 'credit-rev:' . $order_id . ':' . $seq );
        } elseif ( $delta < 0 && $cleared ) {
            $ok = WalletManager::add_funds( $user_id, -$delta, $label . ' Restabelecido (Pedido #' . $order_id . ' reativado)', $order_id, 'cleared', 'credit-fix:' . $order_id . ':' . $seq );
        }

        if ( false !== $ok ) {
            $order->update_meta_data( '_wcw_credit_reverted', $wanted );
            $order->update_meta_data( '_wcw_credit_seq', $seq );
            $order->add_order_note( sprintf( '%s ajustado na carteira em %s.', $label, wp_strip_all_tags( wc_price( -$delta ) ) ) );
            $dirty = true;
        }

        if ( $dirty ) {
            $order->save();
        }
    }

    /**
     * Fração do valor pago em dinheiro que já foi reembolsada (0 a 1).
     */
    private function get_refund_ratio( $order ) {
        $total = (float) $order->get_total();
        if ( $total <= 0 ) return 0;

        return min( 1, max( 0, (float) $order->get_total_refunded() / $total ) );
    }

    /* =========================================================
       INTERFACE E SCRIPTS
    ========================================================= */

    public function enqueue_checkout_scripts() {
        if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || ! is_user_logged_in() ) return;

        wp_enqueue_style( 'wcw-frontend-style', WCW_PLUGIN_URL . 'assets/css/frontend.css', [], WCW_VERSION );
        wp_enqueue_script( 'wcw-frontend-script', WCW_PLUGIN_URL . 'assets/js/frontend.js', ['jquery'], WCW_VERSION, true );
    }

    public function render_wallet_checkbox() {
        if ( ! is_user_logged_in() || null === WC()->cart ) return;

        $balance = WalletManager::get_balance( get_current_user_id() );

        if ( $balance > 0 && ! $this->cart_has_recharge( WC()->cart ) ) {
            $is_checked = ( isset( WC()->session ) && WC()->session->get( 'wcw_use_balance' ) ) ? 'checked' : '';
            ?>
            <div class="wcw-wallet-box">
                <label class="wcw-wallet-label">
                    <input type="checkbox" id="wcw_checkbox_saldo" name="wcw_use_balance" value="1" <?php echo esc_attr( $is_checked ); ?>>
                    💳 <?php esc_html_e( 'Usar meu saldo de', 'jc-digital-wallet-for-woocommerce' ); ?> <?php echo wp_kses_post( wc_price( $balance ) ); ?>
                </label>
            </div>
            <?php
        }
    }

    public function render_cashback_notice() {
        if ( ! is_user_logged_in() || null === WC()->cart ) return;

        // Usa o desconto que de fato entrou no carrinho, não uma simulação paralela
        $wallet_discount   = $this->get_cart_wallet_fee( WC()->cart );
        $eligible_cashback = $this->calculate_eligible_cashback( WC()->cart->get_cart(), $wallet_discount );

        if ( $eligible_cashback > 0 ) {
            ?>
            <tr class="wcw-cashback-row">
                <th><?php esc_html_e( 'Bônus de Retorno na Carteira', 'jc-digital-wallet-for-woocommerce' ); ?></th>
                <td><strong class="wcw-cashback-amount">+ <?php echo wp_kses_post( wc_price( $eligible_cashback ) ); ?></strong></td>
            </tr>
            <?php
        }
    }

    /* =========================================================
       PROCESSAMENTO DO DESCONTO E IDENTIFICAÇÃO (CORE ARCHITECTURE)
    ========================================================= */

    public function save_checkbox_session( $post_data ) {
        if ( ! isset( WC()->session ) ) return;
        parse_str( (string) $post_data, $data );
        WC()->session->set( 'wcw_use_balance', isset( $data['wcw_use_balance'] ) && $data['wcw_use_balance'] === '1' );
    }

    private function get_wallet_fee_id() {
        return sanitize_title( __( 'Pagamento via Carteira', 'jc-digital-wallet-for-woocommerce' ) );
    }

    private function cart_has_recharge( $cart ) {
        $recharge_id = (int) get_option( 'wcw_recharge_product_id', 0 );

        foreach ( $cart->get_cart() as $cart_item ) {
            if ( isset( $cart_item['wcw_custom_recharge_value'] ) || ( isset( $cart_item['product_id'] ) && (int) $cart_item['product_id'] === $recharge_id ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Total a pagar no momento em que as taxas são calculadas: itens já com cupom, frete,
     * impostos e taxas de outros plugins. O get_subtotal() antigo ignorava cupons e debitava a mais.
     */
    private function get_cart_payable_total( $cart ) {
        $total = (float) $cart->get_cart_contents_total() + (float) $cart->get_cart_contents_tax()
               + (float) $cart->get_shipping_total() + (float) $cart->get_shipping_tax();

        foreach ( $cart->fees_api()->get_fees() as $fee ) {
            if ( $fee->id !== $this->get_wallet_fee_id() ) {
                $total += (float) $fee->amount;
            }
        }

        return max( 0, round( $total, 2 ) );
    }

    private function get_cart_wallet_fee( $cart ) {
        foreach ( $cart->get_fees() as $fee ) {
            if ( $fee->id === $this->get_wallet_fee_id() && $fee->amount < 0 ) {
                return round( abs( (float) $fee->amount ), 2 );
            }
        }
        return 0;
    }

    private function get_order_wallet_fee( $order ) {
        $total = 0;
        foreach ( $order->get_fees() as $fee_item ) {
            if ( 'yes' === $fee_item->get_meta( '_is_wcw_wallet_payment' ) ) {
                $total += round( abs( (float) $fee_item->get_total() ), 2 );
            }
        }
        return round( $total, 2 );
    }

    public function apply_wallet_discount( $cart ) {
        if ( is_admin() && ! defined( 'DOING_AJAX' ) ) return;
        if ( ! is_user_logged_in() || ! isset( WC()->session ) || ! WC()->session->get( 'wcw_use_balance' ) ) return;

        // A checkbox some na tela quando há recarga, mas a sessão é gravada por qualquer POST.
        // A regra precisa valer aqui no servidor.
        if ( $this->cart_has_recharge( $cart ) ) return;

        $balance = WalletManager::get_balance( get_current_user_id() );

        if ( $balance > 0 ) {
            $discount = round( min( $balance, $this->get_cart_payable_total( $cart ) ), 2 );

            if ( $discount > 0 ) {
                $cart->add_fee( __( 'Pagamento via Carteira', 'jc-digital-wallet-for-woocommerce' ), -$discount, false );
            }
        }
    }

    /**
     * Injeta um metadado na taxa para não dependermos de traduções de strings na validação.
     */
    public function tag_wallet_fee_item( $item, $fee_key, $fee, $order ) {
        // Defesa: Verifica se a sessão realmente existe antes de tentar ler
        if ( isset( WC()->session ) && WC()->session->get( 'wcw_use_balance' ) && $fee->amount < 0 && $fee->id === $this->get_wallet_fee_id() ) {
            $item->add_meta_data( '_is_wcw_wallet_payment', 'yes' );
        }
    }

    /**
     * Calcula o cashback aplicando rateio proporcional sobre o que foi pago em dinheiro real.
     */
    private function calculate_eligible_cashback( $items, $wallet_discount_applied = 0 ) {
        if ( 'yes' !== get_option( 'wcw_cashback_status', 'yes' ) ) return 0;

        // Clamp também na leitura: protege contra valor gravado direto no banco
        $percent      = min( 100, max( 0, (float) get_option( 'wcw_cashback_percent', 5 ) ) );
        $min_val      = max( 0, (float) get_option( 'wcw_cashback_min_val', 0 ) );
        $allowed_cats = get_option( 'wcw_cashback_categories', [] );
        $allowed_cats = is_array( $allowed_cats ) ? array_filter( array_map( 'intval', $allowed_cats ) ) : [];
        $recharge_id  = (int) get_option( 'wcw_recharge_product_id', 0 );

        $order_subtotal = 0;
        $eligible_total = 0;

        foreach ( $items as $item ) {
            if ( is_array( $item ) ) {
                $product_id = isset( $item['product_id'] ) ? (int) $item['product_id'] : 0;
                $line_total = isset( $item['line_total'] ) ? (float) $item['line_total'] : 0.0;
            } else {
                $product_id = (int) $item->get_product_id();
                $line_total = (float) $item->get_total();
            }

            if ( $product_id === $recharge_id ) continue;

            $order_subtotal += $line_total;

            if ( empty( $allowed_cats ) || has_term( $allowed_cats, 'product_cat', $product_id ) ) {
                $eligible_total += $line_total;
            }
        }

        //  Prevenção contra Máquina de Dinheiro Infinito
        if ( $order_subtotal > 0 && $order_subtotal >= $min_val && $eligible_total > 0 && $percent > 0 ) {

            // Se houve uso de saldo, fazemos o rateio justo
            if ( $wallet_discount_applied > 0 ) {
                $proportion           = $eligible_total / $order_subtotal;
                $discount_on_eligible = $wallet_discount_applied * $proportion;
                $eligible_total       = max( 0, $eligible_total - $discount_on_eligible );
            }

            // Arredonda para baixo: centavo de bônus nunca é arredondado a favor do cliente
            return floor( round( $eligible_total * $percent, 6 ) ) / 100;
        }

        return 0;
    }

    public function deduct_balance_on_store_api_order( $order ) {
        if ( $order instanceof \WC_Order ) {
            $this->deduct_balance_on_order( $order->get_id(), [], $order );
        }
    }

    /**
     * Debita o saldo antes do gateway. Se o saldo não cobre, o checkout é ABORTADO com exceção:
     * só colocar em espera não bastava, porque pedido com total zero vira "processando" logo depois.
     */
    public function deduct_balance_on_order( $order_id, $posted_data, $order ) {
        if ( ! $order instanceof \WC_Order ) {
            $order = wc_get_order( $order_id );
        }
        if ( ! $order ) return;

        $order_id = $order->get_id();
        $fee      = $this->get_order_wallet_fee( $order );
        $user_id  = $order->get_customer_id();

        // Pedido reaproveitado (nova tentativa após falha de pagamento): considera o que já foi debitado
        $already = 'yes' === $order->get_meta( '_wcw_balance_refunded' ) ? 0 : (float) $order->get_meta( '_wcw_used_balance' );
        $seq     = (int) $order->get_meta( '_wcw_pay_seq' );

        if ( abs( $fee - $already ) >= 0.01 ) {
            if ( $already > 0 ) {
                WalletManager::add_funds( $user_id, $already, 'Ajuste de Pagamento (Pedido #' . $order_id . ')', $order_id, 'cleared', 'pay-undo:' . $order_id . ':' . $seq );
                $order->update_meta_data( '_wcw_used_balance', 0 );
            }

            if ( $fee > 0 ) {
                $seq++;
                $deducted = $user_id ? WalletManager::deduct_funds( $user_id, $fee, 'Pagamento do Pedido #' . $order_id, $order_id, 'pay:' . $order_id . ':' . $seq ) : false;

                if ( false === $deducted ) {
                    $order->update_meta_data( '_wcw_pay_seq', $seq );
                    $order->save();
                    $order->update_status( 'cancelled', 'Saldo da carteira insuficiente no momento do pagamento (Bloqueio Transacional). Checkout abortado.' );

                    if ( isset( WC()->session ) ) {
                        WC()->session->__unset( 'wcw_use_balance' );
                    }

                    throw new \Exception( __( 'Seu saldo na carteira mudou e não cobre mais este desconto. Revise o pedido e tente novamente.', 'jc-digital-wallet-for-woocommerce' ) );
                }

                $order->add_order_note( sprintf( 'Utilizou %s do saldo da carteira.', wp_strip_all_tags( wc_price( $fee ) ) ) );
                $order->update_meta_data( '_wcw_used_balance', $fee );
            }

            $order->update_meta_data( '_wcw_pay_seq', $seq );
            $order->delete_meta_data( '_wcw_balance_refunded' );
            $order->save();
        }

        if ( isset( WC()->session ) ) {
            WC()->session->__unset( 'wcw_use_balance' );
        }
    }
}
