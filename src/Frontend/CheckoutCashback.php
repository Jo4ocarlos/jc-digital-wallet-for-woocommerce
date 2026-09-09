<?php
namespace WooDigitalWallet\Frontend;

use WooDigitalWallet\Core\WalletManager;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CheckoutCashback {

    public function register() {
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_checkout_scripts' ] );
        add_action( 'woocommerce_review_order_before_payment', [ $this, 'render_wallet_checkbox' ] );
        add_action( 'woocommerce_review_order_before_order_total', [ $this, 'render_cashback_notice' ] );
        add_action( 'woocommerce_checkout_update_order_review', [ $this, 'save_checkbox_session' ] );
        add_action( 'woocommerce_cart_calculate_fees', [ $this, 'apply_wallet_discount' ] );
        add_action( 'woocommerce_checkout_create_order_fee_item', [ $this, 'tag_wallet_fee_item' ], 10, 4 );
        add_action( 'woocommerce_checkout_order_processed', [ $this, 'deduct_balance_on_order' ], 10, 3 );
        
        // ====================================================================
        // SHADOW LEDGER: Máquina de Estados do Cashback
        // ====================================================================
        
        // 1. A PROMESSA: Pedido processado no checkout. Injeta saldo 'pending'.
        add_action( 'woocommerce_checkout_order_processed', [ $this, 'prometer_cashback' ], 20, 1 );
        
        // 2. A LIQUIDAÇÃO: Pagamento confirmado (Gateway bateu ou admin mudou pra completed).
        add_action( 'woocommerce_payment_complete', [ $this, 'liquidar_cashback' ], 10, 1 );
        add_action( 'woocommerce_order_status_completed', [ $this, 'liquidar_cashback' ], 10, 1 );
        
        // 3. O CALOTE/ESTORNO: Pedido cancelado ou falhou. Destrói a promessa.
        add_action( 'woocommerce_order_status_cancelled', [ $this, 'cancelar_cashback' ], 10, 1 );
        add_action( 'woocommerce_order_status_failed', [ $this, 'cancelar_cashback' ], 10, 1 );
        
        // Estorno de saldo usado
        add_action( 'woocommerce_order_status_cancelled', [ $this, 'refund_used_balance' ] );
        add_action( 'woocommerce_order_status_failed', [ $this, 'refund_used_balance' ] );
    }

    public function prometer_cashback( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order || $order->get_meta( '_wcw_promised' ) ) return;

        $user_id = $order->get_customer_id();
        if ( ! $user_id ) return;

        $lock_key = 'wcw_promise_lock_' . $order_id;
        if ( ! add_option( $lock_key, 'locked', '', 'no' ) ) return;

        $recharge_id     = (int) get_option( 'wcw_recharge_product_id', 0 );
        $recharge_amount = 0;
        $wallet_discount = 0;

        // Extrai descontos da carteira
        foreach ( $order->get_fees() as $fee_item ) {
            if ( 'yes' === $fee_item->get_meta( '_is_wcw_wallet_payment' ) ) {
                $wallet_discount += abs( $fee_item->get_total() );
            }
        }

        //  Verifica se o cliente está comprando uma RECARGA MANUAL
        foreach ( $order->get_items() as $item ) {
            $product_id = is_object( $item ) ? $item->get_product_id() : (int) $item['product_id'];
            if ( $product_id === $recharge_id ) {
                $recharge_amount += is_object( $item ) ? $item->get_total() : (float) $item['line_total'];
            }
        }

        // SE FOR RECARGA: Promete a Recarga
        if ( $recharge_amount > 0 ) {
            WalletManager::add_funds( $user_id, $recharge_amount, 'Recarga de Saldo (Pedido #' . $order_id . ')', $order_id, 'pending' );
            $order->add_order_note( 'Recarga registrada como Pendente (Shadow Ledger).' );
        } 
        // SE FOR COMPRA NORMAL: Calcula e Promete o Cashback
        else {
            $cashback_amount = $this->calculate_eligible_cashback( $order->get_items(), $wallet_discount );
            
            if ( $cashback_amount > 0 ) {
                WalletManager::add_funds( $user_id, $cashback_amount, 'Cashback Pendente (Pedido #' . $order_id . ')', $order_id, 'pending' );
                $order->update_meta_data( '_wcw_earned_cashback', $cashback_amount );
                $order->add_order_note( 'Cashback registrado como Pendente aguardando liquidação.' );
            }
        }

        $order->update_meta_data( '_wcw_promised', 'yes' );
        $order->save();
        delete_option( $lock_key );
    }
    public function liquidar_cashback( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order || $order->get_meta( '_wcw_cleared' ) ) return;

        $lock_key = 'wcw_clear_lock_' . $order_id;
        if ( ! add_option( $lock_key, 'locked', '', 'no' ) ) return;

        // Sobrescreve a string de Promessa com a string de Liquidação
        $nova_descricao = 'Cashback Liberado (Pedido #' . $order_id . ')';
        $atualizado     = WalletManager::update_transaction_status_by_order( $order_id, 'cleared', $nova_descricao );

        if ( $atualizado ) {
            $order->update_meta_data( '_wcw_cleared', 'yes' );
            $order->add_order_note( 'Liquidação confirmada. Saldo Pendente movido para Saldo Disponível.' );
            $order->save();
        }
        delete_option( $lock_key );
    }

    public function cancelar_cashback( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order || $order->get_meta( '_wcw_promise_cancelled' ) ) return;

        // Documenta o cancelamento para auditoria futura
        $nova_descricao = 'Cashback Cancelado (Pedido #' . $order_id . ')';
        $atualizado     = WalletManager::update_transaction_status_by_order( $order_id, 'cancelled', $nova_descricao );

        if ( $atualizado ) {
            $order->update_meta_data( '_wcw_promise_cancelled', 'yes' );
            $order->add_order_note( 'Pedido falhou/cancelado. Cashback pendente foi destruído.' );
            $order->save();
        }
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
        
        $recharge_id = (int) get_option( 'wcw_recharge_product_id', 0 );
        $has_recharge = false;
        
        foreach ( WC()->cart->get_cart() as $cart_item ) {
            if ( isset( $cart_item['product_id'] ) && $cart_item['product_id'] === $recharge_id ) {
                $has_recharge = true; break;
            }
        }

        if ( $balance > 0 && ! $has_recharge ) {
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

        // Descobre o desconto simulado no carrinho
        $wallet_discount = 0;
        if ( isset( WC()->session ) && WC()->session->get( 'wcw_use_balance' ) ) {
            $balance         = WalletManager::get_balance( get_current_user_id() );
            $cart_total      = round( WC()->cart->get_subtotal() + WC()->cart->get_shipping_total() + WC()->cart->get_taxes_total(), 2 ); 
            $wallet_discount = min( $balance, $cart_total );
        }

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
        parse_str( $post_data, $data );
        WC()->session->set( 'wcw_use_balance', isset( $data['wcw_use_balance'] ) && $data['wcw_use_balance'] === '1' );
    }

    public function apply_wallet_discount( $cart ) {
        if ( is_admin() && ! defined( 'DOING_AJAX' ) ) return;
        if ( ! is_user_logged_in() || ! isset( WC()->session ) || ! WC()->session->get( 'wcw_use_balance' ) ) return;
        
        $balance = WalletManager::get_balance( get_current_user_id() );
        
        if ( $balance > 0 ) {
            $cart_total = round( $cart->get_subtotal() + $cart->get_shipping_total() + $cart->get_taxes_total(), 2 ); 
            $discount   = min( $balance, $cart_total );
            
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
        if ( isset( WC()->session ) && WC()->session->get( 'wcw_use_balance' ) && $fee->amount < 0 ) {
            $expected_id = sanitize_title( __( 'Pagamento via Carteira', 'jc-digital-wallet-for-woocommerce' ) );
            if ( $fee->id === $expected_id ) {
                $item->add_meta_data( '_is_wcw_wallet_payment', 'yes' );
            }
        }
    }

    /**
     * Calcula o cashback aplicando rateio proporcional sobre o que foi pago em dinheiro real.
     */
    private function calculate_eligible_cashback( $items, $wallet_discount_applied = 0 ) {
        if ( 'yes' !== get_option( 'wcw_cashback_status', 'yes' ) ) return 0;

        $percent      = (float) get_option( 'wcw_cashback_percent', 5 );
        $min_val      = (float) get_option( 'wcw_cashback_min_val', 0 );
        $allowed_cats = get_option( 'wcw_cashback_categories', [] );
        $recharge_id  = (int) get_option( 'wcw_recharge_product_id', 0 );

        $order_subtotal = 0;
        $eligible_total = 0;

        foreach ( $items as $item ) {
            if ( is_array( $item ) ) {
                $product_id = (int) $item['product_id'];
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
        if ( $order_subtotal >= $min_val && $eligible_total > 0 && $percent > 0 ) {
            
            // Se houve uso de saldo, fazemos o rateio justo
            if ( $wallet_discount_applied > 0 ) {
                $proportion           = $eligible_total / $order_subtotal;
                $discount_on_eligible = $wallet_discount_applied * $proportion;
                $eligible_total       = max( 0, $eligible_total - $discount_on_eligible );
            }

            return round( $eligible_total * ( $percent / 100 ), 2 );
        }

        return 0;
    }

    public function deduct_balance_on_order( $order_id, $posted_data, $order ) {
        $total_discount = 0;
        
        foreach ( $order->get_fees() as $fee_item ) {
            if ( 'yes' === $fee_item->get_meta( '_is_wcw_wallet_payment' ) ) {
                $total_discount += round( abs( $fee_item->get_total() ), 2 );
            }
        }

        if ( $total_discount > 0 ) {
            $user_id = $order->get_customer_id();
            
            $deducted = WalletManager::deduct_funds( $user_id, $total_discount, 'Pagamento do Pedido #' . $order_id, $order_id );
            
            if ( $deducted !== false ) {
                // Formatação blindada
                $formatted_discount = strip_tags( wc_price( $total_discount ) );
                $order->add_order_note( sprintf( 'Utilizou %s do saldo da carteira.', $formatted_discount ) );
                $order->update_meta_data( '_wcw_used_balance', $total_discount );
                $order->save();
            } else {
                $order->add_order_note( 'ALERTA: Tentativa de uso de saldo além do disponível (Bloqueio Transacional).' );
                $order->update_status( 'on-hold', 'Aguardando verificação manual de saldo.' );
            }
        }
        
        if ( isset( WC()->session ) ) {
            WC()->session->__unset( 'wcw_use_balance' );
        }
    }

    public function process_cashback_and_recharge( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order || $order->get_meta( '_wcw_processed' ) ) return;

        $user_id = $order->get_customer_id();
        if ( ! $user_id ) return;

        //  Mutex Atômico restaurado! Nunca mais apague isso.
        $lock_key = 'wcw_processing_lock_' . $order_id;
        if ( ! add_option( $lock_key, 'locked', '', 'no' ) ) {
            $order->add_order_note( 'Bloqueio de Race Condition: Webhook duplicado evitado pelo Mutex.' );
            return; 
        }

        $recharge_id     = (int) get_option( 'wcw_recharge_product_id', 0 );
        $recharge_amount = 0;
        $wallet_discount = 0;

        // Extrai as taxas da carteira para subtrair do cálculo de cashback
        foreach ( $order->get_fees() as $fee_item ) {
            if ( 'yes' === $fee_item->get_meta( '_is_wcw_wallet_payment' ) ) {
                $wallet_discount += abs( $fee_item->get_total() );
            }
        }

        foreach ( $order->get_items() as $item ) {
            $product_id = is_object( $item ) ? $item->get_product_id() : (int) $item['product_id'];
            if ( $product_id === $recharge_id ) {
                $recharge_amount += is_object( $item ) ? $item->get_total() : (float) $item['line_total'];
            }
        }

        if ( $recharge_amount > 0 ) {
            WalletManager::add_funds( $user_id, $recharge_amount, 'Recarga de Saldo (Pedido #' . $order_id . ')', $order_id );
            $formatted_recharge = strip_tags( wc_price( $recharge_amount ) );
            $order->add_order_note( sprintf( 'Recarga de %s adicionada à carteira.', $formatted_recharge ) );
        } else {
            // Chamamos a função passando o desconto da carteira para barrar o dinheiro infinito!
            $cashback_amount = $this->calculate_eligible_cashback( $order->get_items(), $wallet_discount );
            
            if ( $cashback_amount > 0 ) {
                WalletManager::add_funds( $user_id, $cashback_amount, 'Cashback Recebido (Pedido #' . $order_id . ')', $order_id );
                $order->update_meta_data( '_wcw_earned_cashback', $cashback_amount );
                $formatted_cashback = strip_tags( wc_price( $cashback_amount ) );
                $order->add_order_note( sprintf( 'Cashback de %s liberado na carteira.', $formatted_cashback ) );
            }
        }

        $order->update_meta_data( '_wcw_processed', 'yes' );
        $order->save();

        delete_option( $lock_key );
    }

    public function refund_used_balance( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order || $order->get_meta( '_wcw_balance_refunded' ) ) return;

        $used_balance = (float) $order->get_meta( '_wcw_used_balance' );
        if ( $used_balance > 0 ) {
            WalletManager::add_funds( $order->get_customer_id(), $used_balance, 'Estorno de Pagamento (Pedido Cancelado #' . $order_id . ')', $order_id );
            $order->update_meta_data( '_wcw_balance_refunded', 'yes' );
            $order->add_order_note( 'Pedido cancelado. Saldo utilizado foi devolvido.' );
            $order->save();
        }
    }

    public function revoke_earned_cashback( $order_id, $refund_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order || $order->get_meta( '_wcw_cashback_revoked' ) ) return;

        $earned_cashback = (float) $order->get_meta( '_wcw_earned_cashback' );
        if ( $earned_cashback > 0 ) {
            WalletManager::revert_funds( $order->get_customer_id(), $earned_cashback, 'Reversão de Cashback (Pedido #' . $order_id . ' Reembolsado)', $order_id );
            $order->update_meta_data( '_wcw_cashback_revoked', 'yes' );
            $order->add_order_note( 'Pedido reembolsado. Cashback revertido da carteira.' );
            $order->save();
        }
    }
}