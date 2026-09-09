<?php
namespace WooDigitalWallet\Frontend;

use WooDigitalWallet\Core\WalletManager;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Shortcodes {

    public function register() {
        add_shortcode( 'wcw_saldo_carteira', [ $this, 'render_wallet_balance' ] );
    }

    /**
     * Renderiza o shortcode [wcw_saldo_carteira]
     * Permite sobrescrever cores via atributos: [wcw_saldo_carteira cor_titulo="#000" cor_valor="#ff0000"]
     */
    public function render_wallet_balance( $atts ) {
        if ( ! is_user_logged_in() ) {
            return '';
        }

        $user_id = get_current_user_id();
        $balance = WalletManager::get_balance( $user_id );
        $formatted_balance = number_format( $balance, 2, ',', '.' );

        $default_title_color = get_option( 'wcw_shortcode_color_title', '#333333' );
        $default_value_color = get_option( 'wcw_shortcode_color_value', '#2271b1' );

        $params = shortcode_atts( [
            'cor_titulo' => $default_title_color, 
            'cor_valor'  => $default_value_color
        ], $atts );

        // Garantimos que o CSS do frontend carregue se o shortcode for usado em qualquer outra página
        wp_enqueue_style( 'wcw-frontend-style', WCW_PLUGIN_URL . 'assets/css/frontend.css', [], WCW_VERSION );

        $html  = '<div class="wcw-wallet-widget">';
        $html .= '<span class="wcw-wallet-title" style="color: ' . esc_attr( $params['cor_titulo'] ) . ';">' . esc_html__( 'Seu Saldo', 'woo-digital-wallet' ) . '</span>';
        $html .= '<span class="wcw-wallet-value" style="color: ' . esc_attr( $params['cor_valor'] ) . ';">R$ ' . esc_html( $formatted_balance ) . '</span>';
        $html .= '</div>';

        return $html;
    }
}