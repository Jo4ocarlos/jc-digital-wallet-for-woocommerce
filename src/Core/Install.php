<?php
namespace WooDigitalWallet\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Install {

    // Suba este número sempre que o schema da tabela mudar.
    const DB_VERSION = '1.1.0';

    public static function activate() {
        self::create_custom_tables();

        // Sem WooCommerce ativo, WC_Product_Simple não existe e a ativação daria fatal error.
        if ( class_exists( 'WC_Product_Simple' ) ) {
            self::create_recharge_product();
        }

        // Registra o endpoint ANTES do flush, senão a aba "Minha Carteira" nasce com 404.
        add_rewrite_endpoint( 'minha-carteira', EP_ROOT | EP_PAGES );
        flush_rewrite_rules();
    }

    /**
     * O hook de ativação não roda em atualização do plugin.
     * Sem isso, quem atualiza fica sem a trava de idempotência no banco.
     */
    public static function maybe_upgrade() {
        if ( get_option( 'wcw_db_version' ) !== self::DB_VERSION ) {
            self::create_custom_tables();
        }
    }

    private static function create_custom_tables() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'wcw_transactions';
        $charset_collate = $wpdb->get_charset_collate();

        // Ledger de histórico. Só INSERT, exceto a troca de status pending -> cleared/cancelled.
        // idempotency_key UNIQUE é a tranca do banco: o mesmo evento financeiro nunca grava duas vezes,
        // mesmo com webhook duplicado ou duas requisições no mesmo milissegundo.
        $sql = "CREATE TABLE $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            user_id bigint(20) NOT NULL,
            order_id bigint(20) DEFAULT 0,
            type varchar(20) NOT NULL,
            amount decimal(10,2) NOT NULL,
            description text NOT NULL,
            status varchar(20) DEFAULT 'cleared' NOT NULL,
            idempotency_key varchar(64) DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY idempotency_key (idempotency_key),
            KEY user_id (user_id),
            KEY order_id (order_id),
            KEY status (status)
        ) $charset_collate;";

        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
        dbDelta( $sql );

        update_option( 'wcw_db_version', self::DB_VERSION );
    }

    private static function create_recharge_product() {
        $product_id = get_option( 'wcw_recharge_product_id', 0 );

        if ( $product_id > 0 && get_post_type( $product_id ) === 'product' ) {
            return;
        }

        $product = new \WC_Product_Simple();
        $product->set_name( __( 'Recarga de Saldo - Carteira Digital', 'jc-digital-wallet-for-woocommerce' ) );
        $product->set_status( 'publish' );
        $product->set_catalog_visibility( 'hidden' );
        $product->set_regular_price( '1.00' ); // String padrão do Woo para Decimais
        $product->set_virtual( true );
        $product->set_sold_individually( true );
        $product->save();

        update_option( 'wcw_recharge_product_id', $product->get_id() );
    }
}
