<?php
/**
 * Plugin Name: Order Integrity for WooCommerce
 * Description: Prüft die Integrität von WooCommerce-Bestellungen zwischen Zahlung, Bestellung und Fulfillment.
 * Version: 0.6.0
 * Author: Order Integrity
 * Requires at least: 6.6
 * Requires PHP: 8.1
 * Requires Plugins: woocommerce
 * Text Domain: order-integrity
 * Domain Path: /languages
 * WC requires at least: 8.0
 * WC tested up to: 11.2
 */

defined( 'ABSPATH' ) || exit;

define( 'OI_VERSION', '0.6.0' );
define( 'OI_FILE', __FILE__ );
define( 'OI_PATH', plugin_dir_path( __FILE__ ) );

require_once OI_PATH . 'includes/class-order-integrity.php';

add_action( 'plugins_loaded', static function () {
    if ( ! class_exists( 'WooCommerce' ) ) {
        return;
    }
    Order_Integrity::instance();
} );

register_activation_hook( __FILE__, array( 'Order_Integrity', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Order_Integrity', 'deactivate' ) );
