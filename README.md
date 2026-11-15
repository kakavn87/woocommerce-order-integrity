# Order Integrity for WooCommerce

WooCommerce plugin for monitoring order integrity across payment, order, stock and fulfillment.

## Product

**Plugin only — no SaaS.**

Order Integrity checks whether a transaction follows the expected process:

`Payment → Order → Stock → Invoice → Shipping → Completed`

It detects deviations, identifies the likely root cause, shows downstream consequences and will quantify affected order value.

## Current version

**0.6.0**

## Current functionality

- Payment → Order integrity checks
- Order → Stock integrity checks
- Order → Fulfillment timeout checks
- Incident lifecycle and automatic resolution
- Local incident/event storage
- German admin dashboard
- Configurable process flow
- Manual and scheduled scans
- Stripe reconciliation
- HPOS-compatible WooCommerce CRUD access

## Roadmap

1. **Phase 1 — Integrity Engine**
2. **Phase 3 — Money Impact**
3. **Phase 5 — Notifications**
4. **Beta validation**
5. **Phase 4 — Auto-Reconcile**
6. **Phase 2 — Advanced Fulfillment**
7. **Phase 6 — German Integrations**

The detailed product strategy, roadmap, pricing and validation plan is maintained in:

**[docs/PROJECT-PLAN.md](docs/PROJECT-PLAN.md)**

## Commercial direction

- Free: core integrity + money impact
- Pro: target €59/year
- Agency: target €179/year
- No SaaS subscription infrastructure

Pricing is a hypothesis and will be validated with real merchants.

## Development

Plugin path: `order-integrity/`
