<?php

defined( 'ABSPATH' ) || exit;

final class Order_Integrity {
    private static ?self $instance = null;

    public static function instance(): self {
        return self::$instance ??= new self();
    }

    public static function activate(): void {
        global $wpdb;

        $incidents = $wpdb->prefix . 'oi_incidents';
        $events    = $wpdb->prefix . 'oi_events';
        $charset   = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta( "CREATE TABLE {$incidents} (
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
            KEY severity (severity),
            KEY type (type)
        ) {$charset};" );

        dbDelta( "CREATE TABLE {$events} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            order_id BIGINT UNSIGNED NOT NULL,
            event_type VARCHAR(100) NOT NULL,
            event_data LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY order_id (order_id),
            KEY event_type (event_type),
            KEY created_at (created_at)
        ) {$charset};" );

        if ( false === get_option( 'oi_payment_order_timeout', false ) ) {
            add_option( 'oi_payment_order_timeout', 10 );
        }

        if ( false === get_option( 'oi_fulfillment_timeout', false ) ) {
            add_option( 'oi_fulfillment_timeout', 1440 );
        }

        if ( ! wp_next_scheduled( 'oi_integrity_scan' ) ) {
            wp_schedule_event( time() + 300, 'oi_fifteen_minutes', 'oi_integrity_scan' );
        }

        update_option( 'oi_version', OI_VERSION );
    }

    public static function deactivate(): void {
        wp_clear_scheduled_hook( 'oi_integrity_scan' );
    }

    private function __construct() {
        add_filter( 'cron_schedules', array( $this, 'cron_schedules' ) );
        add_action( 'init', array( $this, 'load_textdomain' ) );
        add_action( 'admin_menu', array( $this, 'admin_menu' ) );
        add_action( 'woocommerce_payment_complete', array( $this, 'payment_completed' ) );
        add_action( 'woocommerce_order_status_changed', array( $this, 'order_status_changed' ), 10, 4 );
        add_action( 'woocommerce_new_order', array( $this, 'new_order' ) );
        add_action( 'oi_integrity_scan', array( $this, 'scheduled_scan' ) );
    }

    public function cron_schedules( array $schedules ): array {
        $schedules['oi_fifteen_minutes'] = array(
            'interval' => 15 * MINUTE_IN_SECONDS,
            'display'  => __( 'Alle 15 Minuten', 'order-integrity' ),
        );
        return $schedules;
    }

    public function load_textdomain(): void {
        load_plugin_textdomain(
            'order-integrity',
            false,
            dirname( plugin_basename( OI_FILE ) ) . '/languages'
        );
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
        $incidents = $wpdb->prefix . 'oi_incidents';

        $open = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$incidents} WHERE status = 'open'"
        );
        $critical = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$incidents} WHERE status = 'open' AND severity = 'critical'"
        );
        $warnings = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$incidents} WHERE status = 'open' AND severity = 'warning'"
        );

        $recent = $wpdb->get_results(
            "SELECT order_id, type, severity, root_cause, first_seen
             FROM {$incidents}
             WHERE status = 'open'
             ORDER BY first_seen DESC
             LIMIT 10"
        );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Order Integrity', 'order-integrity' ); ?></h1>
            <p><?php esc_html_e( 'Prüft den Bestellfluss von Zahlung über Bestellung bis Fulfillment.', 'order-integrity' ); ?></p>

            <div style="display:flex;gap:16px;max-width:1100px;margin:20px 0;">
                <?php $this->metric_card( __( 'Offene Vorfälle', 'order-integrity' ), $open ); ?>
                <?php $this->metric_card( __( 'Kritisch', 'order-integrity' ), $critical ); ?>
                <?php $this->metric_card( __( 'Warnungen', 'order-integrity' ), $warnings ); ?>
            </div>

            <h2><?php esc_html_e( 'Order Flow', 'order-integrity' ); ?></h2>
            <p>
                <strong><?php esc_html_e( 'Payment', 'order-integrity' ); ?></strong>
                →
                <strong><?php esc_html_e( 'Order', 'order-integrity' ); ?></strong>
                →
                <strong><?php esc_html_e( 'Fulfillment', 'order-integrity' ); ?></strong>
            </p>

            <h2><?php esc_html_e( 'Offene Vorfälle', 'order-integrity' ); ?></h2>
            <table class="widefat striped" style="max-width:1100px;">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Bestellung', 'order-integrity' ); ?></th>
                        <th><?php esc_html_e( 'Typ', 'order-integrity' ); ?></th>
                        <th><?php esc_html_e( 'Schweregrad', 'order-integrity' ); ?></th>
                        <th><?php esc_html_e( 'Ursache', 'order-integrity' ); ?></th>
                        <th><?php esc_html_e( 'Erkannt', 'order-integrity' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php if ( empty( $recent ) ) : ?>
                    <tr><td colspan="5"><?php esc_html_e( 'Keine offenen Vorfälle.', 'order-integrity' ); ?></td></tr>
                <?php else : ?>
                    <?php foreach ( $recent as $incident ) : ?>
                        <tr>
                            <td>#<?php echo esc_html( $incident->order_id ); ?></td>
                            <td><?php echo esc_html( $this->human_incident_type( $incident->type ) ); ?></td>
                            <td><?php echo esc_html( ucfirst( $incident->severity ) ); ?></td>
                            <td><?php echo esc_html( $incident->root_cause ); ?></td>
                            <td><?php echo esc_html( $incident->first_seen ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>

            <h2><?php esc_html_e( 'Aktive Regeln', 'order-integrity' ); ?></h2>
            <ul>
                <li><?php esc_html_e( 'Payment erfolgreich, Order bleibt länger als 10 Minuten Pending/On-hold → kritisch.', 'order-integrity' ); ?></li>
                <li><?php esc_html_e( 'Order bleibt länger als 24 Stunden Processing → Fulfillment-Warnung.', 'order-integrity' ); ?></li>
                <li><?php esc_html_e( 'Integritätsprüfung läuft automatisch alle 15 Minuten.', 'order-integrity' ); ?></li>
            </ul>
        </div>
        <?php
    }

    private function metric_card( string $label, int $value ): void {
        ?>
        <div class="card" style="padding:20px;flex:1;max-width:260px;">
            <strong><?php echo esc_html( $label ); ?></strong>
            <br>
            <span style="font-size:32px;"><?php echo esc_html( $value ); ?></span>
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
        $this->evaluate_order( $order );
    }

    public function order_status_changed( int $order_id, string $from, string $to, $order ): void {
        if ( ! $order instanceof WC_Order ) {
            $order = wc_get_order( $order_id );
        }

        if ( ! $order ) {
            return;
        }

        $this->record_event(
            $order,
            sprintf( 'status:%s->%s', sanitize_key( $from ), sanitize_key( $to ) )
        );

        $this->evaluate_order( $order );
    }

    public function scheduled_scan(): void {
        $orders = wc_get_orders(
            array(
                'limit'        => 100,
                'orderby'      => 'date',
                'order'        => 'DESC',
                'date_created' => '>' . gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS ),
                'return'       => 'objects',
            )
        );

        foreach ( $orders as $order ) {
            if ( $order instanceof WC_Order ) {
                $this->evaluate_order( $order );
            }
        }
    }

    private function evaluate_order( WC_Order $order ): void {
        $date_paid = $order->get_date_paid();

        if ( $date_paid && $order->has_status( array( 'pending', 'on-hold' ) ) ) {
            $timeout = max( 1, (int) get_option( 'oi_payment_order_timeout', 10 ) );
            $age = time() - $date_paid->getTimestamp();

            if ( $age >= $timeout * MINUTE_IN_SECONDS ) {
                $this->create_or_update_incident(
                    $order,
                    'payment_order_mismatch',
                    'critical',
                    __( 'Zahlung ist bestätigt, aber der WooCommerce-Bestellstatus wurde nicht weiterverarbeitet.', 'order-integrity' ),
                    __( 'Fulfillment, Lagerverarbeitung und Kundenkommunikation können dadurch blockiert oder verspätet sein.', 'order-integrity' )
                );
            }
        }

        $date_created = $order->get_date_created();

        if (
            $date_created &&
            $order->has_status( 'processing' ) &&
            ( time() - $date_created->getTimestamp() ) >= (int) get_option( 'oi_fulfillment_timeout', 1440 ) * MINUTE_IN_SECONDS
        ) {
            $this->create_or_update_incident(
                $order,
                'fulfillment_timeout',
                'warning',
                __( 'Die Bestellung befindet sich ungewöhnlich lange im Status Processing.', 'order-integrity' ),
                __( 'Eine manuelle Prüfung des Fulfillment-Prozesses ist erforderlich.', 'order-integrity' )
            );
        }
    }

    private function record_event( WC_Order $order, string $event ): void {
        global $wpdb;

        $table = $wpdb->prefix . 'oi_events';

        $wpdb->insert(
            $table,
            array(
                'order_id'   => $order->get_id(),
                'event_type' => sanitize_text_field( $event ),
                'event_data' => wp_json_encode(
                    array(
                        'status'       => $order->get_status(),
                        'payment_date' => $order->get_date_paid()
                            ? $order->get_date_paid()->date( 'c' )
                            : null,
                    )
                ),
                'created_at' => current_time( 'mysql', true ),
            ),
            array( '%d', '%s', '%s', '%s' )
        );
    }

    private function human_incident_type( string $type ): string {
        $labels = array(
            'payment_order_mismatch' => __( 'Payment → Order', 'order-integrity' ),
            'fulfillment_timeout'    => __( 'Order → Fulfillment', 'order-integrity' ),
        );

        return $labels[ $type ] ?? $type;
    }

    private function create_or_update_incident(
        WC_Order $order,
        string $type,
        string $severity,
        string $root_cause,
        string $consequences
    ): void {
        global $wpdb;

        $table = $wpdb->prefix . 'oi_incidents';
        $now   = current_time( 'mysql', true );

        $existing = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id FROM {$table}
                 WHERE order_id = %d AND type = %s AND status = 'open'
                 LIMIT 1",
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
                'order_id'     => $order->get_id(),
                'type'         => $type,
                'severity'     => $severity,
                'status'       => 'open',
                'root_cause'   => $root_cause,
                'consequences' => $consequences,
                'first_seen'   => $now,
                'last_seen'    => $now,
            ),
            array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
        );
    }
}
