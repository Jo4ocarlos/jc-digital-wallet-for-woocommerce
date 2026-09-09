<?php
namespace WooDigitalWallet\Frontend;

use WooDigitalWallet\Core\WalletManager;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MyAccountTab {

    public function register() {
        // Registra o endpoint da nova página
        add_action( 'init', [ $this, 'add_endpoint' ] );
        
        // Adiciona o link no menu lateral do Minha Conta
        add_filter( 'woocommerce_account_menu_items', [ $this, 'add_menu_item' ] );
        
        // Renderiza o conteúdo quando o cliente acessa a aba
        add_action( 'woocommerce_account_minha-carteira_endpoint', [ $this, 'render_endpoint_content' ] );
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_account_assets' ] );
    }

    public function enqueue_account_assets() {
        // Carrega o CSS apenas se estivermos dentro da página Minha Conta
        if ( function_exists( 'is_account_page' ) && is_account_page() ) {
            wp_enqueue_style( 'wcw-frontend-style', WCW_PLUGIN_URL . 'assets/css/frontend.css', [], WCW_VERSION );
        }
    }

    public function add_endpoint() {
        add_rewrite_endpoint( 'minha-carteira', EP_ROOT | EP_PAGES );
    }

    public function add_menu_item( $items ) {
        $new_menu = [];
        
        // Injeta a aba "Minha Carteira" logo abaixo de "Pedidos"
        foreach ( $items as $key => $value ) {
            $new_menu[$key] = $value;
            if ( 'orders' === $key ) {
                $new_menu['minha-carteira'] = __( 'Minha Carteira', 'woo-digital-wallet' );
            }
        }
        
        // Fallback de segurança caso 'orders' tenha sido removido pelo tema
        if ( ! isset( $new_menu['minha-carteira'] ) ) {
            $new_menu['minha-carteira'] = __( 'Minha Carteira', 'woo-digital-wallet' );
        }
        
        return $new_menu;
    }

    public function render_endpoint_content() {
        $user_id = get_current_user_id();
        
        // 1. Extração de Dados (Model)
        $balance         = WalletManager::get_balance( $user_id );
        $pending_balance = WalletManager::get_pending_balance( $user_id ); 
        $history         = WalletManager::get_history( $user_id );
        
        // 2. Injeção de Dados na View (Template Engine nativa do Woo)
     
        wc_get_template( 
            'my-account-wallet.php', 
            [ 
                'balance'         => $balance, 
                'pending_balance' => $pending_balance, // Passando a variável para o HTML
                'history'         => $history 
            ], 
            'woo-digital-wallet/', 
            WCW_PLUGIN_DIR . 'templates/' 
        );
    }
}