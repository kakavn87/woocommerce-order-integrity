<?php

defined( 'ABSPATH' ) || exit;

/**
 * Stripe reconciliation connector.
 *
 * No customer/order data is sent to a third-party service other than Stripe's
 * own API request using the merchant's Stripe secret key.
 */
final class OI_Stripe_Connector {
    public static function is_enabled(): bool {
        return (bool) get_option( 'oi_stripe_enabled', false ) && '' !== self::get_secret_key();
    }

    public static function get_secret_key(): string {
        return (string) get_option( 'oi_stripe_secret_key', '' );
    }

    public static function save_settings( bool $enabled, string $secret_key ): void {
        update_option( 'oi_stripe_enabled', $enabled );
        if ( '' !== $secret_key ) {
            update_option( 'oi_stripe_secret_key', sanitize_text_field( $secret_key ) );
        }
    }

    public static function test_connection(): array {
        $response = self::request( '/v1/account' );

        if ( is_wp_error( $response ) ) {
            return array( 'success' => false, 'message' => $response->get_error_message() );
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code >= 200 && $code < 300 ) {
            $name = isset( $body['business_profile']['name'] ) ? $body['business_profile']['name'] : '';
            return array(
                'success' => true,
                'message' => $name
                    ? sprintf( __( 'Verbindung erfolgreich. Konto: %s', 'order-integrity' ), $name )
                    : __( 'Verbindung zu Stripe erfolgreich hergestellt.', 'order-integrity' ),
            );
        }

        return array(
            'success' => false,
            'message' => isset( $body['error']['message'] )
                ? sanitize_text_field( $body['error']['message'] )
                : __( 'Stripe hat die Anfrage abgelehnt.', 'order-integrity' ),
        );
    }

    public static function get_payment_intent( string $transaction_id ) {
        if ( '' === $transaction_id ) {
            return new WP_Error( 'oi_stripe_no_transaction', __( 'Keine Stripe-Transaktions-ID vorhanden.', 'order-integrity' ) );
        }

        if ( 0 === strpos( $transaction_id, 'ch_' ) ) {
            return self::request( '/v1/charges/' . rawurlencode( $transaction_id ) );
        }

        return self::request( '/v1/payment_intents/' . rawurlencode( $transaction_id ) );
    }

    private static function request( string $path ) {
        $secret = self::get_secret_key();

        if ( '' === $secret ) {
            return new WP_Error( 'oi_stripe_no_key', __( 'Kein Stripe-Schlüssel hinterlegt.', 'order-integrity' ) );
        }

        return wp_remote_get(
            'https://api.stripe.com' . $path,
            array(
                'timeout' => 15,
                'headers' => array(
                    'Authorization' => 'Bearer ' . $secret,
                ),
            )
        );
    }

    public static function reconcile_order( WC_Order $order ): array {
        $transaction_id = (string) $order->get_transaction_id();

        if ( '' === $transaction_id ) {
            return array( 'status' => 'skipped', 'reason' => 'missing_transaction' );
        }

        $response = self::get_payment_intent( $transaction_id );

        if ( is_wp_error( $response ) ) {
            return array( 'status' => 'error', 'message' => $response->get_error_message() );
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code < 200 || $code >= 300 || ! is_array( $body ) ) {
            return array(
                'status'  => 'error',
                'message' => isset( $body['error']['message'] )
                    ? sanitize_text_field( $body['error']['message'] )
                    : __( 'Stripe-Zahlung konnte nicht geladen werden.', 'order-integrity' ),
            );
        }

        $woo_amount = (float) $order->get_total();
        $stripe_amount = isset( $body['amount_received'] ) ? (float) $body['amount_received'] / 100 : (float) ( $body['amount'] ?? 0 ) / 100;
        $woo_currency = strtolower( (string) $order->get_currency() );
        $stripe_currency = strtolower( (string) ( $body['currency'] ?? '' ) );
        $stripe_status = sanitize_key( (string) ( $body['status'] ?? '' ) );

        if ( isset( $body['payment_intent'] ) && is_string( $body['payment_intent'] ) ) {
            $body['id'] = $body['payment_intent'];
        }

        return array(
            'status'           => 'ok',
            'stripe_status'    => $stripe_status,
            'stripe_amount'    => $stripe_amount,
            'woo_amount'       => $woo_amount,
            'stripe_currency'  => $stripe_currency,
            'woo_currency'     => $woo_currency,
            'amount_mismatch'  => abs( $stripe_amount - $woo_amount ) > 0.01,
            'currency_mismatch'=> '' !== $stripe_currency && $stripe_currency !== $woo_currency,
            'payment_mismatch' => 'succeeded' !== $stripe_status && $order->is_paid(),
            'raw_id'           => sanitize_text_field( (string) ( $body['id'] ?? $transaction_id ) ),
        );
    }
}
