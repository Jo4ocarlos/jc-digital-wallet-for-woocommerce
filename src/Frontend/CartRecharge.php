<?php
namespace WooDigitalWallet\Frontend;

use WooDigitalWallet\Core\WalletManager;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CartRecharge {

    /** Liga a validação de add-to-cart só durante o nosso próprio fluxo de recarga. */
    private $adding_recharge = false;

    public function register() {
        add_action( 'template_redirect', [ $this, 'process_recharge_request' ] );
        add_action( 'woocommerce_before_calculate_totals', [ $this, 'set_custom_recharge_price' ], 10, 1 );
        add_filter( 'woocommerce_cart_item_name', [ $this, 'rename_recharge_product' ], 10, 3 );
        add_filter( 'woocommerce_cart_item_permalink', [ $this, 'remove_recharge_permalink' ], 10, 3 );

        // O produto de recarga é público (status publish). Sem estas travas, qualquer um compra
        // por ?add-to-cart=ID, pela Store API ou por "pedir novamente", mesmo com a recarga desligada.
        add_filter( 'woocommerce_is_purchasable', [ $this, 'restrict_recharge_purchasable' ], 10, 2 );
        add_filter( 'woocommerce_add_to_cart_validation', [ $this, 'block_direct_add_to_cart' ], 10, 2 );
    }

    public static function get_min_amount() {
        return (float) apply_filters( 'wcw_recharge_min_amount', 1.00 );
    }

    public static function get_max_amount() {
        return min( WalletManager::MAX_AMOUNT, (float) apply_filters( 'wcw_recharge_max_amount', WalletManager::MAX_AMOUNT ) );
    }

    public function restrict_recharge_purchasable( $purchasable, $product ) {
        $recharge_id = (int) get_option( 'wcw_recharge_product_id', 0 );
        if ( ! $recharge_id || ! $product || (int) $product->get_id() !== $recharge_id ) {
            return $purchasable;
        }

        // Recarga desligada no painel ou visitante sem conta (o crédito precisa de um user_id)
        if ( 'yes' !== get_option( 'wcw_wallet_recharge_status', 'yes' ) || ! is_user_logged_in() ) {
            return false;
        }

        return $purchasable;
    }

    public function block_direct_add_to_cart( $passed, $product_id ) {
        $recharge_id = (int) get_option( 'wcw_recharge_product_id', 0 );

        if ( $recharge_id && (int) $product_id === $recharge_id && ! $this->adding_recharge ) {
            wc_add_notice( __( 'Use a página Minha Carteira para adicionar saldo.', 'jc-digital-wallet-for-woocommerce' ), 'error' );
            return false;
        }

        return $passed;
    }

    public function process_recharge_request() {
        //  Trava absoluta. Se a recarga estiver desativada no painel,
        // aborta a requisição POST imediatamente.
        if ( 'yes' !== get_option( 'wcw_wallet_recharge_status', 'yes' ) ) {
            return;
        }

        if ( ! isset( $_POST['wcw_add_credito_submit'] ) || ! is_user_logged_in() ) {
            return;
        }

        if ( ! isset( $_POST['wcw_saldo_security'] ) || ! wp_verify_nonce( sanitize_key( $_POST['wcw_saldo_security'] ), 'wcw_adicionar_saldo_nonce' ) ) {
            wc_add_notice( __( 'Requisição inválida. Falha de segurança.', 'jc-digital-wallet-for-woocommerce' ), 'error' );
            return;
        }

        if ( null === WC()->cart ) {
            return;
        }

        $valor_raw   = isset( $_POST['wcw_valor_recarga'] ) ? sanitize_text_field( wp_unslash( $_POST['wcw_valor_recarga'] ) ) : '';
        $valor_float = WalletManager::normalize_amount( wc_format_decimal( $valor_raw, 2 ) );

        $recharge_product_id = (int) get_option( 'wcw_recharge_product_id', 0 );
        $min = self::get_min_amount();
        $max = self::get_max_amount();

        if ( $recharge_product_id <= 0 || $valor_float < $min || $valor_float > $max ) {
            wc_add_notice(
                sprintf(
                    /* translators: 1: valor mínimo, 2: valor máximo */
                    __( 'Informe um valor de recarga entre %1$s e %2$s.', 'jc-digital-wallet-for-woocommerce' ),
                    wp_strip_all_tags( wc_price( $min ) ),
                    wp_strip_all_tags( wc_price( $max ) )
                ),
                'error'
            );
            return;
        }

        // O produto é "vendido individualmente": sem remover a recarga anterior, o add_to_cart
        // falharia e o cliente seguiria para o checkout com o valor antigo.
        foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
            if ( isset( $cart_item['product_id'] ) && (int) $cart_item['product_id'] === $recharge_product_id ) {
                WC()->cart->remove_cart_item( $cart_item_key );
            }
        }

        $this->adding_recharge = true;
        $added = WC()->cart->add_to_cart( $recharge_product_id, 1, 0, [], [ 'wcw_custom_recharge_value' => $valor_float ] );
        $this->adding_recharge = false;

        if ( ! $added ) {
            wc_add_notice( __( 'Não foi possível adicionar a recarga ao carrinho.', 'jc-digital-wallet-for-woocommerce' ), 'error' );
            return;
        }

        wp_safe_redirect( wc_get_checkout_url() );
        exit;
    }

    public function set_custom_recharge_price( $cart_obj ) {
        if ( is_admin() && ! defined( 'DOING_AJAX' ) ) return;

        foreach ( $cart_obj->get_cart() as $cart_item ) {
            if ( isset( $cart_item['wcw_custom_recharge_value'] ) ) {
                $valor = WalletManager::normalize_amount( $cart_item['wcw_custom_recharge_value'] );
                if ( $valor > 0 ) {
                    $cart_item['data']->set_price( $valor );
                }
            }
        }
    }

    public function rename_recharge_product( $item_name, $cart_item, $cart_item_key ) {
        if ( isset( $cart_item['wcw_custom_recharge_value'] ) ) {
            return esc_html__( 'Recarga de Saldo na Carteira Digital', 'jc-digital-wallet-for-woocommerce' );
        }
        return $item_name;
    }

    public function remove_recharge_permalink( $permalink, $cart_item, $cart_item_key ) {
        return isset( $cart_item['wcw_custom_recharge_value'] ) ? '' : $permalink;
    }
}
