<?php
namespace WooDigitalWallet\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CartRecharge {

    public function register() {
        add_action( 'template_redirect', [ $this, 'process_recharge_request' ] );
        add_action( 'woocommerce_before_calculate_totals', [ $this, 'set_custom_recharge_price' ], 10, 1 );
        add_filter( 'woocommerce_cart_item_name', [ $this, 'rename_recharge_product' ], 10, 3 );
        add_filter( 'woocommerce_cart_item_permalink', [ $this, 'remove_recharge_permalink' ], 10, 3 );
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

        
        $valor_raw   = sanitize_text_field( wp_unslash( $_POST['wcw_valor_recarga'] ) );
        $valor_float = (float) wc_format_decimal( $valor_raw );
        
        $recharge_product_id = (int) get_option( 'wcw_recharge_product_id', 0 );

        if ( $valor_float > 0 && $recharge_product_id > 0 ) {
            WC()->cart->add_to_cart( $recharge_product_id, 1, 0, [], [ 'wcw_custom_recharge_value' => $valor_float ] );
            wp_safe_redirect( wc_get_checkout_url() );
            exit;
        } else {
            wc_add_notice( __( 'Por favor, insira um valor válido para recarga.', 'jc-digital-wallet-for-woocommerce' ), 'error' );
        }
    }

    public function set_custom_recharge_price( $cart_obj ) {
        if ( is_admin() && ! defined( 'DOING_AJAX' ) ) return;
        if ( did_action( 'woocommerce_before_calculate_totals' ) >= 2 ) return;
        
        foreach ( $cart_obj->get_cart() as $cart_item ) {
            if ( isset( $cart_item['wcw_custom_recharge_value'] ) ) {
                $cart_item['data']->set_price( $cart_item['wcw_custom_recharge_value'] );
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