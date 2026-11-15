<?php

defined( 'ABSPATH' ) || exit;

final class Order_Integrity {
    private static ?self $instance = null;

    public static function instance(): self {
        return self::$instance ??= new self();
    }

    public static function activate(): void {
        add_filter( 'cron_schedules', array( __CLASS__, 'register_cron_schedules' ) );
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

    public static function register_cron_schedules( array $schedules ): array {
        $schedules['oi_fifteen_minutes'] = array(
            'interval' => 15 * MINUTE_IN_SECONDS,
            'display'  => __( 'Alle 15 Minuten', 'order-integrity' ),
        );
        return $schedules;
    }

    public static function deactivate(): void {
        wp_clear_scheduled_hook( 'oi_integrity_scan' );
    }

    private function __construct() {
        add_filter( 'cron_schedules', array( $this, 'cron_schedules' ) );
        add_action( 'init', array( $this, 'maybe_upgrade' ), 5 );
        add_action( 'init', array( $this, 'load_textdomain' ) );
        add_action( 'admin_menu', array( $this, 'admin_menu' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'admin_assets' ) );
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

    public function maybe_upgrade(): void {
        if ( OI_VERSION !== get_option( 'oi_version' ) ) {
            self::activate();
        }
    }

    public function load_textdomain(): void {
        load_plugin_textdomain(
            'order-integrity',
            false,
            dirname( plugin_basename( OI_FILE ) ) . '/languages'
        );
    }


    public function admin_assets( string $hook ): void {
        if ( false === strpos( $hook, 'order-integrity' ) ) {
            return;
        }

        wp_register_style( 'order-integrity-admin', false, array(), OI_VERSION );
        wp_enqueue_style( 'order-integrity-admin' );

        $css = <<<'CSS'
.oi-admin {
    --oi-text:#172033;
    --oi-muted:#6b7280;
    --oi-border:#e7eaf0;
    --oi-surface:#ffffff;
    --oi-bg:#f6f7fb;
    --oi-primary:#635bff;
    --oi-success:#16a34a;
    --oi-danger:#dc2626;
    --oi-warning:#d97706;
    max-width:1500px;
    margin:24px 24px 40px 0;
    color:var(--oi-text);
}
.oi-admin * { box-sizing:border-box; }
.oi-breadcrumb { color:var(--oi-muted); font-size:13px; margin-bottom:10px; }
.oi-breadcrumb a { color:var(--oi-primary); text-decoration:none; }
.oi-header { display:flex; justify-content:space-between; align-items:flex-start; gap:20px; margin-bottom:22px; }
.oi-title { display:flex; align-items:center; gap:12px; margin:0; font-size:30px; line-height:1.2; letter-spacing:-.02em; }
.oi-subtitle { color:var(--oi-muted); margin:8px 0 0; }
.oi-badge { display:inline-flex; align-items:center; gap:6px; border-radius:999px; padding:6px 11px; font-size:12px; font-weight:700; }
.oi-badge-danger { background:#fee2e2; color:#b91c1c; }
.oi-badge-warning { background:#fef3c7; color:#a16207; }
.oi-badge-success { background:#dcfce7; color:#15803d; }
.oi-badge-muted { background:#eef0f5; color:#667085; }
.oi-button { display:inline-flex!important; align-items:center; gap:7px; min-height:38px!important; border-radius:9px!important; padding:0 14px!important; }
.oi-card { background:var(--oi-surface); border:1px solid var(--oi-border); border-radius:14px; box-shadow:0 2px 10px rgba(16,24,40,.04); }
.oi-order-summary { display:grid; grid-template-columns:1.3fr 1fr 1fr 1fr; overflow:hidden; margin-bottom:18px; }
.oi-summary-item { padding:18px 22px; border-right:1px solid var(--oi-border); }
.oi-summary-item:last-child { border-right:0; }
.oi-label { display:block; color:var(--oi-muted); font-size:12px; margin-bottom:5px; }
.oi-value { font-weight:700; font-size:15px; }
.oi-main-grid { display:grid; grid-template-columns:minmax(0,2fr) minmax(300px,1fr); gap:18px; align-items:start; }
.oi-flow-card { padding:22px; margin-bottom:18px; }
.oi-section-title { margin:0 0 18px; font-size:19px; }
.oi-flow { display:grid; grid-template-columns:1fr 34px 1fr 34px 1fr 34px 1fr; align-items:center; }
.oi-flow-arrow { text-align:center; color:#a1a8b7; font-size:22px; }
.oi-step { min-height:105px; border:1px solid var(--oi-border); border-radius:12px; padding:14px; text-align:center; background:#fbfcfe; }
.oi-step-success { border-color:#bbf7d0; background:#f6fff8; }
.oi-step-danger { border-color:#fecaca; background:#fff7f7; }
.oi-step-warning { border-color:#fde68a; background:#fffbeb; }
.oi-step-muted { background:#f8f9fc; }
.oi-step-icon { width:30px; height:30px; border-radius:50%; display:flex; align-items:center; justify-content:center; margin:-29px auto 9px; font-weight:800; border:4px solid #fff; }
.oi-step-success .oi-step-icon { background:#16a34a; color:#fff; }
.oi-step-danger .oi-step-icon { background:#dc2626; color:#fff; }
.oi-step-warning .oi-step-icon { background:#d97706; color:#fff; }
.oi-step-muted .oi-step-icon { background:#cbd5e1; color:#fff; }
.oi-step-name { font-weight:700; font-size:14px; }
.oi-step-source { color:var(--oi-muted); font-size:12px; margin:3px 0 7px; }
.oi-step-state { font-size:12px; font-weight:700; }
.oi-side-card { padding:20px; margin-bottom:18px; }
.oi-side-card h2 { margin:0 0 13px; font-size:17px; }
.oi-cause { background:#fff7f7; border:1px solid #fecaca; border-radius:11px; padding:15px; }
.oi-impact { background:#fffbeb; border:1px solid #fde68a; border-radius:11px; padding:15px; }
.oi-impact ul { margin:8px 0 0 18px; padding:0; }
.oi-details { display:grid; gap:0; }
.oi-detail-row { display:flex; justify-content:space-between; gap:20px; padding:10px 0; border-top:1px solid var(--oi-border); font-size:13px; }
.oi-detail-row:first-child { border-top:0; }
.oi-detail-row span:first-child { color:var(--oi-muted); }
.oi-timeline-card { padding:22px; }
.oi-timeline-filter { float:right; display:flex; gap:4px; background:#f5f6fa; border-radius:9px; padding:3px; }
.oi-timeline-filter span { padding:6px 9px; border-radius:7px; font-size:12px; color:var(--oi-muted); }
.oi-timeline-filter .active { background:#fff; color:var(--oi-primary); box-shadow:0 1px 3px rgba(0,0,0,.08); font-weight:700; }
.oi-timeline { position:relative; margin-top:8px; }
.oi-timeline:before { content:""; position:absolute; left:82px; top:14px; bottom:12px; width:2px; background:#e9ebf1; }
.oi-event { position:relative; display:grid; grid-template-columns:62px 40px minmax(0,1fr); gap:0; min-height:72px; }
.oi-event-time { padding-top:4px; color:var(--oi-muted); font-size:11px; text-align:right; padding-right:12px; }
.oi-event-dot { width:28px; height:28px; margin:0 auto; border-radius:50%; background:#fff; border:2px solid #16a34a; color:#16a34a; display:flex; align-items:center; justify-content:center; z-index:2; font-size:12px; font-weight:800; }
.oi-event-danger .oi-event-dot { border-color:#dc2626; color:#dc2626; }
.oi-event-warning .oi-event-dot { border-color:#d97706; color:#d97706; }
.oi-event-content { padding:0 0 20px 12px; }
.oi-event-title { font-weight:700; font-size:13px; }
.oi-event-text { color:var(--oi-muted); font-size:12px; margin-top:4px; line-height:1.5; }
.oi-event-highlight { background:#fff5f5; border:1px solid #fee2e2; border-radius:10px; padding:11px 12px; margin-top:-3px; }
.oi-footer-actions { display:flex; gap:9px; margin-top:14px; flex-wrap:wrap; }
@media (max-width:900px) {
    .oi-main-grid { grid-template-columns:1fr; }
    .oi-order-summary { grid-template-columns:1fr 1fr; }
    .oi-summary-item:nth-child(2) { border-right:0; }
    .oi-flow { grid-template-columns:1fr; gap:8px; }
    .oi-flow-arrow { transform:rotate(90deg); }
}
CSS;
        wp_add_inline_style( 'order-integrity-admin', $css );
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

        add_submenu_page(
            'order-integrity',
            __( 'Vorfälle', 'order-integrity' ),
            __( 'Vorfälle', 'order-integrity' ),
            'manage_woocommerce',
            'order-integrity-incidents',
            array( $this, 'render_incidents' )
        );
    }

    public function render_incidents(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        $incident_id = isset( $_GET['incident_id'] ) ? absint( $_GET['incident_id'] ) : 0;

        if ( $incident_id > 0 && isset( $_GET['resolve'] ) && '1' === $_GET['resolve'] ) {
            check_admin_referer( 'oi_resolve_incident_' . $incident_id );
            global $wpdb;
            $wpdb->update(
                $wpdb->prefix . 'oi_incidents',
                array( 'status' => 'resolved', 'last_seen' => current_time( 'mysql', true ) ),
                array( 'id' => $incident_id ),
                array( '%s', '%s' ),
                array( '%d' )
            );
        }

        if ( $incident_id > 0 ) {
            $this->render_incident_detail( $incident_id );
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'oi_incidents';
        $items = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY first_seen DESC LIMIT 100" );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Vorfälle', 'order-integrity' ); ?></h1>
            <table class="widefat striped">
                <thead><tr>
                    <th>ID</th><th><?php esc_html_e( 'Bestellung', 'order-integrity' ); ?></th>
                    <th><?php esc_html_e( 'Problem', 'order-integrity' ); ?></th>
                    <th><?php esc_html_e( 'Schweregrad', 'order-integrity' ); ?></th>
                    <th><?php esc_html_e( 'Status', 'order-integrity' ); ?></th>
                    <th><?php esc_html_e( 'Erkannt', 'order-integrity' ); ?></th>
                </tr></thead>
                <tbody>
                <?php if ( empty( $items ) ) : ?>
                    <tr><td colspan="6"><?php esc_html_e( 'Keine Vorfälle vorhanden.', 'order-integrity' ); ?></td></tr>
                <?php else : foreach ( $items as $item ) : ?>
                    <tr>
                        <td>#<?php echo esc_html( $item->id ); ?></td>
                        <td>#<?php echo esc_html( $item->order_id ); ?></td>
                        <td><a href="<?php echo esc_url( admin_url( 'admin.php?page=order-integrity-incidents&incident_id=' . (int) $item->id ) ); ?>"><?php echo esc_html( $this->human_incident_type( $item->type ) ); ?></a></td>
                        <td><?php echo esc_html( ucfirst( $item->severity ) ); ?></td>
                        <td><?php echo esc_html( ucfirst( $item->status ) ); ?></td>
                        <td><?php echo esc_html( $item->first_seen ); ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }


    private function render_incident_detail( int $incident_id ): void {
        global $wpdb;

        $incidents = $wpdb->prefix . 'oi_incidents';
        $events    = $wpdb->prefix . 'oi_events';

        $incident = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$incidents} WHERE id = %d", $incident_id )
        );

        if ( ! $incident ) {
            echo '<div class="wrap"><div class="notice notice-error"><p>' . esc_html__( 'Vorfall nicht gefunden.', 'order-integrity' ) . '</p></div></div>';
            return;
        }

        $order = wc_get_order( (int) $incident->order_id );
        $timeline = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT event_type, event_data, created_at FROM {$events} WHERE order_id = %d ORDER BY created_at ASC LIMIT 100",
                (int) $incident->order_id
            )
        );

        $is_critical = 'critical' === $incident->severity;
        $is_warning  = 'warning' === $incident->severity;
        $status_label = 'open' === $incident->status ? __( 'Offen', 'order-integrity' ) : __( 'Behoben', 'order-integrity' );
        $severity_label = $is_critical ? __( 'Kritisch', 'order-integrity' ) : __( 'Warnung', 'order-integrity' );

        $payment_state = 'success';
        $order_state = 'success';
        $stock_state = 'success';
        $fulfillment_state = 'muted';

        if ( 'payment_order_mismatch' === $incident->type ) {
            $order_state = 'danger';
            $stock_state = 'danger';
            $fulfillment_state = 'muted';
        } elseif ( 'fulfillment_timeout' === $incident->type ) {
            $fulfillment_state = 'warning';
        }

        $payment_label = $order && $order->get_date_paid()
            ? __( 'Bezahlt', 'order-integrity' )
            : __( 'Ausstehend', 'order-integrity' );
        $order_label = $order
            ? wc_get_order_status_name( $order->get_status() )
            : __( 'Unbekannt', 'order-integrity' );

        $stock_label = 'danger' === $stock_state
            ? __( 'Nicht verarbeitet', 'order-integrity' )
            : __( 'Verarbeitet', 'order-integrity' );
        $fulfillment_label = 'warning' === $fulfillment_state
            ? __( 'Überfällig', 'order-integrity' )
            : __( 'Wartet', 'order-integrity' );

        $payment_method = $order && $order->get_payment_method_title()
            ? $order->get_payment_method_title()
            : __( 'Unbekannt', 'order-integrity' );

        $order_total = $order ? $order->get_formatted_order_total() : '—';
        $order_date = $order && $order->get_date_created()
            ? wc_format_datetime( $order->get_date_created(), 'd.m.Y, H:i' )
            : '—';

        ?>
        <div class="wrap oi-admin">
            <div class="oi-breadcrumb">
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=order-integrity-incidents' ) ); ?>"><?php esc_html_e( 'Vorfälle', 'order-integrity' ); ?></a>
                <span>›</span>
                <strong><?php printf( esc_html__( 'Vorfall #%d', 'order-integrity' ), (int) $incident->id ); ?></strong>
            </div>

            <div class="oi-header">
                <div>
                    <h1 class="oi-title">
                        <?php printf( esc_html__( 'Vorfall #%d – Bestellung #%d', 'order-integrity' ), (int) $incident->id, (int) $incident->order_id ); ?>
                        <span class="oi-badge <?php echo $is_critical ? 'oi-badge-danger' : 'oi-badge-warning'; ?>">
                            <?php echo esc_html( $severity_label ); ?>
                        </span>
                    </h1>
                    <p class="oi-subtitle">
                        <?php echo esc_html( $this->human_incident_type( $incident->type ) ); ?>
                        · <?php echo esc_html( $status_label ); ?>
                    </p>
                </div>

                <div>
                    <?php if ( 'open' === $incident->status ) : ?>
                        <a class="button button-primary oi-button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=order-integrity-incidents&incident_id=' . (int) $incident->id . '&resolve=1' ), 'oi_resolve_incident_' . (int) $incident->id ) ); ?>">
                            ✓ <?php esc_html_e( 'Als behoben markieren', 'order-integrity' ); ?>
                        </a>
                    <?php else : ?>
                        <span class="oi-badge oi-badge-success">✓ <?php esc_html_e( 'Behoben', 'order-integrity' ); ?></span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="oi-card oi-order-summary">
                <div class="oi-summary-item">
                    <span class="oi-label"><?php esc_html_e( 'Bestellung', 'order-integrity' ); ?></span>
                    <span class="oi-value">#<?php echo esc_html( $incident->order_id ); ?></span>
                </div>
                <div class="oi-summary-item">
                    <span class="oi-label"><?php esc_html_e( 'Kunde', 'order-integrity' ); ?></span>
                    <span class="oi-value"><?php echo $order ? esc_html( $order->get_formatted_billing_full_name() ?: '—' ) : '—'; ?></span>
                </div>
                <div class="oi-summary-item">
                    <span class="oi-label"><?php esc_html_e( 'Betrag', 'order-integrity' ); ?></span>
                    <span class="oi-value"><?php echo wp_kses_post( $order_total ); ?></span>
                </div>
                <div class="oi-summary-item">
                    <span class="oi-label"><?php esc_html_e( 'Bestelldatum', 'order-integrity' ); ?></span>
                    <span class="oi-value"><?php echo esc_html( $order_date ); ?></span>
                </div>
            </div>

            <div class="oi-main-grid">
                <div>
                    <section class="oi-card oi-flow-card">
                        <h2 class="oi-section-title"><?php esc_html_e( 'Bestellablauf', 'order-integrity' ); ?></h2>

                        <div class="oi-flow">
                            <?php
                            $steps = array(
                                array( 'name' => __( 'Zahlung', 'order-integrity' ), 'source' => $payment_method, 'state' => $payment_state, 'label' => $payment_label, 'icon' => '✓' ),
                                array( 'name' => __( 'Bestellung', 'order-integrity' ), 'source' => 'WooCommerce', 'state' => $order_state, 'label' => $order_label, 'icon' => 'danger' === $order_state ? '×' : '✓' ),
                                array( 'name' => __( 'Lagerbestand', 'order-integrity' ), 'source' => 'WooCommerce', 'state' => $stock_state, 'label' => $stock_label, 'icon' => 'danger' === $stock_state ? '×' : '✓' ),
                                array( 'name' => __( 'Auftragsabwicklung', 'order-integrity' ), 'source' => __( 'Versand', 'order-integrity' ), 'state' => $fulfillment_state, 'label' => $fulfillment_label, 'icon' => 'muted' === $fulfillment_state ? '○' : '!' ),
                            );

                            foreach ( $steps as $index => $step ) :
                                $class = 'oi-step-' . $step['state'];
                                ?>
                                <div class="oi-step <?php echo esc_attr( $class ); ?>">
                                    <div class="oi-step-icon"><?php echo esc_html( $step['icon'] ); ?></div>
                                    <div class="oi-step-name"><?php echo esc_html( $step['name'] ); ?></div>
                                    <div class="oi-step-source"><?php echo esc_html( $step['source'] ); ?></div>
                                    <div class="oi-step-state"><?php echo esc_html( $step['label'] ); ?></div>
                                </div>
                                <?php if ( $index < count( $steps ) - 1 ) : ?>
                                    <div class="oi-flow-arrow">→</div>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                    </section>

                    <section class="oi-card oi-timeline-card">
                        <h2 class="oi-section-title">
                            <?php esc_html_e( 'Zeitverlauf', 'order-integrity' ); ?>
                            <span class="oi-timeline-filter"><span class="active"><?php esc_html_e( 'Alle', 'order-integrity' ); ?></span></span>
                        </h2>

                        <div class="oi-timeline">
                            <?php if ( empty( $timeline ) ) : ?>
                                <p><?php esc_html_e( 'Noch keine Ereignisse erfasst.', 'order-integrity' ); ?></p>
                            <?php else : ?>
                                <?php foreach ( $timeline as $event ) :
                                    $event_meta = json_decode( (string) $event->event_data, true );
                                    $event_info = $this->human_event( (string) $event->event_type, is_array( $event_meta ) ? $event_meta : array() );
                                    $event_class = 'error' === $event_info['severity'] ? 'oi-event-danger' : ( 'warning' === $event_info['severity'] ? 'oi-event-warning' : '' );
                                    ?>
                                    <div class="oi-event <?php echo esc_attr( $event_class ); ?>">
                                        <div class="oi-event-time"><?php echo esc_html( wp_date( 'H:i:s', strtotime( $event->created_at ) ) ); ?></div>
                                        <div class="oi-event-dot"><?php echo 'error' === $event_info['severity'] ? '×' : ( 'warning' === $event_info['severity'] ? '!' : '✓' ); ?></div>
                                        <div class="oi-event-content">
                                            <div class="<?php echo 'error' === $event_info['severity'] ? 'oi-event-highlight' : ''; ?>">
                                                <div class="oi-event-title"><?php echo esc_html( $event_info['title'] ); ?></div>
                                                <div class="oi-event-text"><?php echo esc_html( $event_info['description'] ); ?></div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>

                                <div class="oi-event <?php echo $is_critical ? 'oi-event-danger' : 'oi-event-warning'; ?>">
                                    <div class="oi-event-time"><?php echo esc_html( wp_date( 'H:i:s', strtotime( $incident->first_seen ) ) ); ?></div>
                                    <div class="oi-event-dot"><?php echo $is_critical ? '×' : '!'; ?></div>
                                    <div class="oi-event-content">
                                        <div class="oi-event-highlight">
                                            <div class="oi-event-title"><?php esc_html_e( 'Integritätsverletzung erkannt', 'order-integrity' ); ?></div>
                                            <div class="oi-event-text"><?php echo esc_html( $incident->root_cause ); ?></div>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </section>
                </div>

                <aside>
                    <section class="oi-card oi-side-card">
                        <h2>◎ <?php esc_html_e( 'Ursache', 'order-integrity' ); ?></h2>
                        <div class="oi-cause">
                            <?php echo esc_html( $incident->root_cause ); ?>
                        </div>
                    </section>

                    <section class="oi-card oi-side-card">
                        <h2>⚠ <?php esc_html_e( 'Auswirkungen', 'order-integrity' ); ?></h2>
                        <div class="oi-impact">
                            <div><?php echo esc_html( $incident->consequences ); ?></div>
                        </div>
                    </section>

                    <section class="oi-card oi-side-card">
                        <h2>ⓘ <?php esc_html_e( 'Details', 'order-integrity' ); ?></h2>
                        <div class="oi-details">
                            <div class="oi-detail-row"><span><?php esc_html_e( 'Bestellstatus', 'order-integrity' ); ?></span><strong><?php echo esc_html( $order_label ); ?></strong></div>
                            <div class="oi-detail-row"><span><?php esc_html_e( 'Zahlungsstatus', 'order-integrity' ); ?></span><strong><?php echo esc_html( $payment_label ); ?></strong></div>
                            <div class="oi-detail-row"><span><?php esc_html_e( 'Zahlungsanbieter', 'order-integrity' ); ?></span><strong><?php echo esc_html( $payment_method ); ?></strong></div>
                            <div class="oi-detail-row"><span><?php esc_html_e( 'Erkannt', 'order-integrity' ); ?></span><strong><?php echo esc_html( wp_date( 'd.m.Y H:i', strtotime( $incident->first_seen ) ) ); ?></strong></div>
                            <div class="oi-detail-row"><span><?php esc_html_e( 'Zuletzt geprüft', 'order-integrity' ); ?></span><strong><?php echo esc_html( wp_date( 'd.m.Y H:i', strtotime( $incident->last_seen ) ) ); ?></strong></div>
                        </div>
                        <div class="oi-footer-actions">
                            <?php if ( $order ) : ?>
                                <a class="button oi-button" href="<?php echo esc_url( $order->get_edit_order_url() ); ?>">↗ <?php esc_html_e( 'Bestellung anzeigen', 'order-integrity' ); ?></a>
                            <?php endif; ?>
                        </div>
                    </section>
                </aside>
            </div>
        </div>
        <?php
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
                <strong><?php esc_html_e( 'Zahlung', 'order-integrity' ); ?></strong>
                →
                <strong><?php esc_html_e( 'Bestellung', 'order-integrity' ); ?></strong>
                →
                <strong><?php esc_html_e( 'Auftragsabwicklung', 'order-integrity' ); ?></strong>
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


    private function human_event( string $event_type, array $data ): array {
        if ( 'order_created' === $event_type ) {
            return array(
                'title'       => __( 'Bestellung erstellt', 'order-integrity' ),
                'description' => __( 'Die Bestellung wurde in WooCommerce angelegt.', 'order-integrity' ),
                'severity'    => 'success',
            );
        }

        if ( 'payment_completed' === $event_type ) {
            $amount = isset( $data['payment_date'] ) && $data['payment_date'] ? $data['payment_date'] : '';
            return array(
                'title'       => __( 'Zahlung erhalten', 'order-integrity' ),
                'description' => $amount
                    ? sprintf( __( 'Die Zahlung wurde erfolgreich bestätigt. Zahlungszeitpunkt: %s.', 'order-integrity' ), $amount )
                    : __( 'Die Zahlung wurde erfolgreich bestätigt.', 'order-integrity' ),
                'severity'    => 'success',
            );
        }

        if ( 0 === strpos( $event_type, 'status:' ) ) {
            $parts = explode( '->', substr( $event_type, 7 ) );
            $from = isset( $parts[0] ) ? wc_get_order_status_name( $parts[0] ) : '';
            $to   = isset( $parts[1] ) ? wc_get_order_status_name( $parts[1] ) : '';
            return array(
                'title'       => __( 'Bestellstatus geändert', 'order-integrity' ),
                'description' => sprintf( __( 'Status geändert von „%1$s“ zu „%2$s“.', 'order-integrity' ), $from, $to ),
                'severity'    => 'success',
            );
        }

        return array(
            'title'       => __( 'Ereignis', 'order-integrity' ),
            'description' => __( 'Ein Bestellereignis wurde erfasst.', 'order-integrity' ),
            'severity'    => 'success',
        );
    }

    private function human_incident_type( string $type ): string {
        $labels = array(
            'payment_order_mismatch' => __( 'Zahlung → Bestellung', 'order-integrity' ),
            'fulfillment_timeout'    => __( 'Bestellung → Auftragsabwicklung', 'order-integrity' ),
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
