<?php
/**
 * Plugin Name:       JC Digital Wallet & Cashback for WooCommerce
 * Plugin URI:        https://github.com/Jo4ocarlos/jc-digital-wallet-for-woocommerce.git
 * Description:       Carteira digital com sistema de cashback, painel de controle e pagamento com saldo.
 * Version:           1.0.0
 * Author:            João Carlos de Almeida Silva
 * Author URI:        https://www.linkedin.com/in/joão-carlos-de-almeida-silva-724579171
 * Text Domain:       jc-digital-wallet-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'WCW_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WCW_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WCW_VERSION', '1.0.0' );

if ( file_exists( WCW_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
    require_once WCW_PLUGIN_DIR . 'vendor/autoload.php';
}

// Hook de ativação (Roda APENAS uma vez)
register_activation_hook( __FILE__, [ '\WooDigitalWallet\Core\Install', 'activate' ] );

// Hook de inicialização do sistema
add_action( 'plugins_loaded', function() {
    if ( class_exists( 'WooCommerce' ) ) {
        \WooDigitalWallet\Init::run();
    }
} );