<?php
namespace WooDigitalWallet\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Install {

    public static function activate() {
        self::create_custom_tables();
        self::create_recharge_product();
        flush_rewrite_rules();
    }

    private static function create_custom_tables() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'wcw_transactions';
        $charset_collate = $wpdb->get_charset_collate();

        // Tabela imutável para histórico. Sem UPDATE ou DELETE, apenas INSERT.
        $sql = "CREATE TABLE $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            user_id bigint(20) NOT NULL,
            order_id bigint(20) DEFAULT 0,
            type varchar(20) NOT NULL,
            amount decimal(10,2) NOT NULL,
            description text NOT NULL,
            status varchar(20) DEFAULT 'cleared' NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id),
            KEY user_id (user_id),
            KEY status (status)
        ) $charset_collate;";

        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
        dbDelta( $sql );
    }

    private static function create_recharge_product() {
        $product_id = get_option( 'wcw_recharge_product_id', 0 );

        if ( $product_id > 0 && get_post_type( $product_id ) === 'product' ) {
            return;
        }

        $product = new \WC_Product_Simple();
        $product->set_name( __( 'Recarga de Saldo - Carteira Digital', 'digital-wallet-for-woocommerce' ) );
        $product->set_status( 'publish' );
        $product->set_catalog_visibility( 'hidden' );
        $product->set_regular_price( '1.00' ); // String padrão do Woo para Decimais
        $product->set_virtual( true );
        $product->set_sold_individually( true );
        $product->save();

        update_option( 'wcw_recharge_product_id', $product->get_id() );
    }
}