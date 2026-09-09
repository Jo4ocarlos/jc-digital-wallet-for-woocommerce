<?php
namespace WooDigitalWallet;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Init {

    public static function get_services() {
        return [
             Admin\Settings::class,
             Frontend\CartRecharge::class,
             Frontend\CheckoutCashback::class,
             Frontend\MyAccountTab::class,
             Frontend\Shortcodes::class
        ];
    }

    public static function run() {
        foreach ( self::get_services() as $class ) {
            $service = new $class();
            if ( method_exists( $service, 'register' ) ) {
                $service->register();
            }
        }
    }
}