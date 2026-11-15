<?php

defined( 'ABSPATH' ) || exit;

final class Order_Integrity {
    private static ?self $instance = null;

    public static function instance(): self {
        return self::$instance ??= new self();
    }

    public static function activate(): void {
        if ( ! function_exists( 'wc_get_container' ) ) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'oi_incidents';
        $charset = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            order_id BIGINT UNSIGNED NULL,
            type VARCHAR(100) NOT NULL,
            severity VARCHAR(20) NOT NULL DEFAULT 'warning',
            status VARCHAR(20) NOT NULL DEFAULT 'open',
            root_cause TEXT NULL,
            consequences TEXT NULL,
            first_seen DATETIME NOT NULL,
            last_seen DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY order_id (order_id),
            KEY status (status),
            KEY severity (severity)
        ) {$charset};" );

        update_option( 'oi_version', OI_VERSION );
    }

    public static function deactivate(): void {
        // Keep incident data on deactivation. Uninstall will be handled separately.
    }

    private function __construct() {
        add_action( 'init', array( $this, 'load_textdomain' ) );
        add_action( 'admin_menu', array( $this, 'admin_menu' ) );
        add_action( 'woocommerce_payment_complete', array( $this, 'payment_completed' ) );
        add_action( 'woocommerce_order_status_changed', array( $this, 'order_status_changed' ), 10, 4 );
        add_action( 'woocommerce_new_order', array( $this, 'new_order' ) );
    }

    public function load_textdomain(): void {
        load_plugin_textdomain( 'order-integrity', false, dirname( plugin_basename( OI_FILE ) ) . '/languages' );
    }

    public function admin_menu(): void {
        add_menu_page(
            __( 'Order Integrity', 'order-integrity' ),
            __( 'Order Integrity', 'order-integrity' ),
            'manage_woocommerce',
            'order-integrity',
            array( $this, 'render_dashboard' ),
            'dashicons-shield',
            56
        );
    }

    public function render_dashboard(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'oi_incidents';
        $open = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'open'" );
        $critical = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'open' AND severity = 'critical'" );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Order Integrity', 'order-integrity' ); ?></h1>
            <p><?php esc_html_e( 'Überwachung der Bestellintegrität von Zahlung bis Fulfillment.', 'order-integrity' ); ?></p>
            <div style="display:flex;gap:16px;max-width:900px;">
                <div class="card" style="padding:20px;flex:1;"><strong><?php esc_html_e( 'Offene Vorfälle', 'order-integrity' ); ?></strong><br><span style="font-size:32px;"><?php echo esc_html( $open ); ?></span></div>
                <div class="card" style="padding:20px;flex:1;"><strong><?php esc_html_e( 'Kritisch', 'order-integrity' ); ?></strong><br><span style="font-size:32px;"><?php echo esc_html( $critical ); ?></span></div>
            </div>
            <h2><?php esc_html_e( 'MVP-Regeln', 'order-integrity' ); ?></h2>
            <ul>
                <li><?php esc_html_e( 'Zahlung erfolgreich, Bestellung bleibt ausstehend.', 'order-integrity' ); ?></li>
                <li><?php esc_html_e( 'Bestellung wechselt in einen neuen Status und wird für weitere Integritätsprüfungen erfasst.', 'order-integrity' ); ?></li>
            </ul>
        </div>
        <?php
    }

    public function new_order( int $order_id ): void {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }
        $this->record_event( $order, 'order_created' );
    }

    public function payment_completed( int $order_id ): void {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        $this->record_event( $order, 'payment_completed' );

        if ( $order->has_status( array( 'pending', 'on-hold' ) ) ) {
            $this->create_or_update_incident(
                $order,
                'payment_order_mismatch',
                'critical',
                __( 'Zahlung wurde erfolgreich bestätigt, aber der Bestellstatus ist weiterhin ausstehend.', 'order-integrity' ),
                __( 'Die weitere Verarbeitung, Lagerreservierung oder Fulfillment-Auslösung kann blockiert sein.', 'order-integrity' )
            );
        }
    }

    public function order_status_changed( int $order_id, string $from, string $to, $order ): void {
        if ( ! $order instanceof WC_Order ) {
            $order = wc_get_order( $order_id );
        }
        if ( ! $order ) {
            return;
        }

        $this->record_event( $order, sprintf( 'status:%s->%s', $from, $to ) );
    }

    private function record_event( WC_Order $order, string $event ): void {
        $order->add_order_note(
            sprintf( '[Order Integrity] Event erfasst: %s', sanitize_text_field( $event ) ),
            false,
            false
        );
    }

    private function create_or_update_incident( WC_Order $order, string $type, string $severity, string $root_cause, string $consequences ): void {
        global $wpdb;
        $table = $wpdb->prefix . 'oi_incidents';
        $now = current_time( 'mysql', true );

        $existing = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id FROM {$table} WHERE order_id = %d AND type = %s AND status = 'open' LIMIT 1",
                $order->get_id(),
                $type
            )
        );

        if ( $existing ) {
            $wpdb->update(
                $table,
                array( 'last_seen' => $now ),
                array( 'id' => (int) $existing->id ),
                array( '%s' ),
                array( '%d' )
            );
            return;
        }

        $wpdb->insert(
            $table,
            array(
                'order_id' => $order->get_id(),
                'type' => $type,
                'severity' => $severity,
                'status' => 'open',
                'root_cause' => $root_cause,
                'consequences' => $consequences,
                'first_seen' => $now,
                'last_seen' => $now,
            ),
            array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
        );
    }
}
