<?php
namespace WooDigitalWallet\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Settings {

    public function register() {
        add_action( 'admin_menu', [ $this, 'add_menu_page' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );

        // O options.php exige manage_options por padrão. Alinha com a capability do menu.
        add_filter( 'option_page_capability_wcw_wallet_options_group', [ $this, 'settings_capability' ] );

        //  Link direto para as configurações na lista de plugins
        $plugin_basename = plugin_basename( WCW_PLUGIN_DIR . 'jc-digital-wallet-for-woocommerce.php' );
        add_filter( 'plugin_action_links_' . $plugin_basename, [ $this, 'add_plugin_action_links' ] );
    }

    public function enqueue_admin_assets( $hook_suffix ) {
        // Só carrega os scripts se estivermos na página do nosso plugin
        if ( strpos( $hook_suffix, 'wcw-carteira-settings' ) === false ) {
            return;
        }
        wp_enqueue_style( 'wcw-admin-style', WCW_PLUGIN_URL . 'assets/css/admin.css', [], WCW_VERSION );

        wp_enqueue_style( 'wp-color-picker' );
        wp_enqueue_script( 'wp-color-picker' );

        wp_enqueue_script( 'wcw-admin-script', WCW_PLUGIN_URL . 'assets/js/admin.js', ['jquery', 'wp-color-picker'], WCW_VERSION, true );

        // Carrega o SelectWoo nativo do WooCommerce para o campo de categorias
        wp_enqueue_style( 'woocommerce_admin_styles' );
        wp_enqueue_script( 'wc-enhanced-select' );
    }

    /**
     * Injeta o link de Configurações na página de Plugins do WordPress.
     */
    public function add_plugin_action_links( $links ) {
        // array_unshift coloca o nosso link como o PRIMEIRO da lista (antes de Desativar)
        $settings_link = '<a href="' . esc_url( admin_url( 'admin.php?page=wcw-carteira-settings' ) ) . '" style="font-weight: bold; color: #2271b1;">' . esc_html__( 'Configurações', 'jc-digital-wallet-for-woocommerce' ) . '</a>';
        array_unshift( $links, $settings_link );
        
        return $links;
    }

    public function add_menu_page() {
        add_menu_page(
            __( 'Carteira & Cashback', 'jc-digital-wallet-for-woocommerce' ), 
            __( 'Carteira Digital', 'jc-digital-wallet-for-woocommerce' ),          
            'manage_woocommerce',        
            'wcw-carteira-settings',     
            [ $this, 'render_settings_page' ], 
            'dashicons-wallet',          
            56                           
        );
    }

    public function register_settings() {
        $group = 'wcw_wallet_options_group';

        register_setting( $group, 'wcw_cashback_status', [ 'type' => 'string', 'sanitize_callback' => [ $this, 'sanitize_yes_no' ], 'default' => 'yes' ] );
        // Cashback acima de 100% devolve mais do que o cliente pagou: máquina de dinheiro
        register_setting( $group, 'wcw_cashback_percent', [ 'type' => 'number', 'sanitize_callback' => function( $val ) {
            return min( 100, absint( $val ) );
        }, 'default' => 5 ] );
        register_setting( $group, 'wcw_cashback_min_val', [ 'type' => 'number', 'sanitize_callback' => function( $val ) {
            return max( 0, round( (float) wc_format_decimal( $val ), 2 ) );
        }, 'default' => 0 ] );
        register_setting( $group, 'wcw_shortcode_color_title', [ 'type' => 'string', 'sanitize_callback' => 'sanitize_hex_color', 'default' => '#333333' ] );
        register_setting( $group, 'wcw_shortcode_color_value', [ 'type' => 'string', 'sanitize_callback' => 'sanitize_hex_color', 'default' => '#2271b1' ] );
        
        // Novo campo: Categorias permitidas (Array de IDs)
        register_setting( $group, 'wcw_cashback_categories', [
            'type'              => 'array',
            'sanitize_callback' => function( $val ) {
                return is_array( $val ) ? array_values( array_filter( array_map( 'absint', $val ) ) ) : [];
            },
            'default'           => []
        ] );
        // Trava da Recarga Manual
        register_setting( $group, 'wcw_wallet_recharge_status', [
            'type' => 'string',
            'sanitize_callback' => [ $this, 'sanitize_yes_no' ],
            'default' => 'yes'
        ] );
    }

    public function sanitize_yes_no( $val ) {
        return 'no' === $val ? 'no' : 'yes';
    }

    public function settings_capability() {
        return 'manage_woocommerce';
    }



    public function render_settings_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        // Garante que o produto de recarga exista sempre que o admin abrir a tela
        $recharge_product_id = get_option( 'wcw_recharge_product_id', 0 );
        
        // Puxa as categorias do WooCommerce para montar o select
        $product_categories = get_terms( [
            'taxonomy'   => 'product_cat',
            'hide_empty' => false,
        ] );

        // Separa a interface HTML em um template
        include WCW_PLUGIN_DIR . 'templates/admin-settings.php';
    }
}