=== Order Integrity for WooCommerce ===
Contributors: order-integrity
Tags: woocommerce, orders, monitoring, reconciliation, diagnostics
Requires at least: 6.6
Requires PHP: 8.1
Requires Plugins: woocommerce
Stable tag: 0.2.0
License: GPLv3

Überwacht die Integrität von WooCommerce-Bestellungen zwischen Zahlung, Bestellung und Fulfillment.

== Description ==

Order Integrity ist ein lokales Monitoring- und Diagnose-Plugin für WooCommerce.

Die erste Version erfasst wichtige Bestellereignisse und erkennt einen kritischen Zustand:
Eine Zahlung wurde erfolgreich bestätigt, während die Bestellung weiterhin auf ausstehend/on-hold steht.

== Roadmap ==

* Payment → Order Integrity
* Order → Fulfillment Integrity
* Root-Cause-Analyse
* Incident Dashboard
* Connectoren für Stripe, PayPal, Xero und Versand
