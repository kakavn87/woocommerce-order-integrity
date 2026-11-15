# WooCommerce Order Integrity — Product & Project Plan

> Central project plan for product strategy, roadmap, technical scope, monetization, validation and next steps.
>
> **Current direction:** WordPress/WooCommerce plugin only. No SaaS.

## 1. Product vision

**WooCommerce Order Integrity** is a local-first WooCommerce plugin that checks whether an order moves correctly through the merchant's business process:

`Payment → Order → Stock → Invoice → Shipping → Completed`

The plugin should detect deviations, identify the likely root cause, show downstream consequences and quantify the affected order value.

### Core message

> **Order Integrity prüft, ob eine Bestellung vom Zahlungseingang bis zum Versand korrekt durch Ihre Prozesse läuft – und zeigt Ihnen die Ursache, wenn etwas hängen bleibt.**

### Key distinction

- **Automation:** WHEN X → DO Y
- **Integrity/Audit:** EXPECTED X → OBSERVED Y → FIND DEVIATION

The product is therefore not primarily another automation plugin. It is an **observability, reconciliation and integrity layer** for WooCommerce transactions.

---

## 2. Product principles

1. **Plugin only — no SaaS.**
2. **Local-first:** checks and business logic run on the merchant's WordPress/WooCommerce installation whenever possible.
3. **No mandatory AI API cost for the core product.**
4. **Free version must deliver real value.**
5. **Paid features should solve expensive operational problems.**
6. **German market is an initial target, but the architecture should remain internationalizable.**
7. **Build once, sell many times.**
8. **Do not overbuild before validation.**

---

## 3. The problem

Typical WooCommerce failures are not always visible as a complete system outage:

- Payment succeeds but order remains pending/on-hold.
- Stock is not reduced correctly.
- Order processing stops after one step.
- Invoice generation/synchronization fails.
- Shipping/fulfillment is not triggered.
- External systems receive incomplete or delayed data.
- A merchant discovers the problem only after a customer complains.

The important question is not only:

> "Is WooCommerce running?"

but:

> "Did this business transaction complete correctly from payment to fulfillment?"

---

## 4. Example user experience

### Dashboard

```
ORDER FLOW AUDIT

Heute geprüft: 1.284

🟢 1.247 vollständig
🟡 29 mit Warnungen
🔴 8 blockiert

🔴 3 Payment → Order
🔴 2 Order → Invoice
🔴 4 Order → Stock
🟠 6 Order → Shipping
🟠 3 Order → Email
```

### Single order

```
#10482   €349

Payment       ✓
Order         ✓
Stock         🔴
Invoice       ⚪
Shipping      ⚪
```

### Root cause

```
Payment callback not fully processed
        ↓
Order status not updated
        ↓
Stock remains incorrect
        ↓
Invoice missing
        ↓
Shipping trigger missing
```

The plugin should explain both the **root cause** and the **downstream consequences**.

---

# 5. Current technical foundation

Repository:

`kakavn87/woocommerce-order-integrity`

Current plugin version:

**0.6.0**

Main architecture:

- WordPress / WooCommerce plugin
- PHP
- WooCommerce APIs
- WordPress cron
- Local database tables
- Admin dashboard
- Rule-based integrity engine
- Connector architecture for external systems

### Current database

`wp_oi_incidents`

Stores detected incidents:

- order_id
- type
- severity
- status
- root_cause
- consequences
- first_seen
- last_seen

`wp_oi_events`

Stores order-related events:

- order_id
- event_type
- event_data
- created_at

### Current options

- payment/order timeout
- fulfillment timeout
- flow definition
- Stripe enabled
- Stripe secret key

---

# 6. Current implemented functionality

## Phase 1 — Integrity Engine

Implemented:

- Payment → Order mismatch detection
- Order → Stock integrity detection
- Order → Fulfillment timeout detection
- Incident creation
- Incident severity
- Root cause
- Consequences
- Incident lifecycle
- Automatic resolution when the condition disappears
- Manual scan
- Scheduled scan
- Dashboard metrics
- Incident list/detail
- Order flow timeline
- Configurable process flow
- German admin UI
- Stripe connector
- Stripe amount reconciliation
- Stripe currency reconciliation
- Stripe payment-status reconciliation

Current flow configuration supports:

- Zahlung
- Bestellung
- Lagerbestand
- Auftragsabwicklung

Latest Phase 1 commits:

- `2b6c1e8a6b42ef0fd65be3de69d39ee3f336c552`
- `eee5d36dab6d113791e019e0ad691f5758232e2e`

---

# 7. Product roadmap

The roadmap combines our previous discussion with the external Google-style recommendation.

## PHASE 1 — Integrity Engine

**Goal:** Detect real transaction failures.

### Scope

- Payment → Order
- Order → Stock
- Order → Fulfillment
- Incident lifecycle
- Root cause
- Consequences
- Dashboard
- Flow configuration
- Stripe reconciliation

**Status: IN PROGRESS / FOUNDATION IMPLEMENTED**

---

## PHASE 3 — Money Impact

**Move this earlier.**

This was one of the strongest recommendations from the Google comparison.

The free plugin should answer:

> "Wie viel Geld ist aktuell von erkannten Problemen betroffen?"

### Features

- Affected order value
- Affected revenue
- Number of affected orders
- Critical financial incidents
- Potentially lost/blocked revenue
- Time period filters

### Example

```
⚠️ ORDER INTEGRITY

3 bezahlte Bestellungen
wurden nicht korrekt verarbeitet.

Betroffener Bestellwert:
420,50 €

🔴 2 Zahlung → Bestellung
🟠 1 Bestellung → Lager
```

**Priority: HIGH**

Reason: this makes the free version immediately useful and creates a strong upgrade/retention argument.

---

## PHASE 5 — Notifications

**Move notifications earlier than originally planned.**

Reason: merchants do not continuously watch the WordPress admin.

### Possible channels

- Email
- Slack
- Telegram

### First implementation

Start with **email** because it has the lowest integration complexity.

Example:

```
Order Integrity Alert

3 kritische Bestellungen erkannt.

Betroffener Bestellwert: 420,50 €

→ Dashboard öffnen
```

**Priority: HIGH**

---

## PHASE 4 — Auto-Reconcile / Repair

After detection, help the merchant repair the problem.

### Stage 1

Manual one-click repair:

```
[Problem erkannt]

[Jetzt reparieren]
```

### Stage 2

Optional automatic repair.

Examples:

- Retry synchronization
- Re-run safe WooCommerce operation
- Re-check payment state
- Correct safe order state transitions

### Safety rule

Never automatically change financial/order data unless the operation is explicitly classified as safe and reversible.

**Priority: HIGH / AFTER VALIDATION**

---

## PHASE 2 — Advanced Fulfillment

Expand beyond the core order flow.

Potential checks:

- shipping status
- tracking
- fulfillment delays
- warehouse state
- delivery state
- email notification state

This becomes more valuable once the core integrity engine is proven.

**Priority: MEDIUM**

---

## PHASE 6 — German Integrations

This is the potential German-market moat.

Planned connectors:

### Accounting

- Sevdesk
- Lexoffice
- Xero

### ERP / warehouse

- JTL

### Shipping

- DHL
- DPD
- ShipStation

### WooCommerce ecosystem

- Germanized

Example:

```
WooCommerce
    ↓
Germanized
    ↓
Invoice
    ↓
JTL
    ↓
Warehouse
    ↓
DHL
```

**Priority: AFTER CORE PRODUCT VALIDATION**

---

# 8. Recommended commercial model

## Free

**1 site**

Core integrity functionality:

- Order integrity
- Payment → Order
- Order → Stock
- Basic fulfillment checks
- Dashboard
- Incident/root cause
- Money impact
- Stripe integration
- Manual scan

The free product must be genuinely useful:

> **Install → Scan → Find problems → See financial impact**

---

## Pro

Target price:

**€59/year**

Expected range discussed:

**€49–69/year**

Possible features:

- Advanced fulfillment
- Notifications
- Advanced reconciliation
- Auto-repair
- More integrations
- Extended history
- Advanced reporting

---

## Agency

Target price:

**€179/year**

Expected range discussed:

**€149–199/year**

Possible scope:

- 10 sites
- Agency-oriented management
- Centralized configuration
- Extended reporting
- Priority support

Important: this is still a **plugin business**, not SaaS.

---

# 9. Validation before heavy development

Do not build every planned integration before validating demand.

## Validation target

Create a simple product/landing page with:

1. Product explanation
2. Three strong screenshots
3. Example incident
4. Money-impact example
5. CTA: **Kostenlos testen**

### Initial validation target

Aim for:

- 20–30 qualified merchant clicks/signups

or

- 5–10 beta installations

before investing heavily in Phase 2/4/6.

---

# 10. Competitive positioning

Known adjacent products/categories include:

- payment/order reconciliation
- payment monitoring
- WooCommerce diagnostics
- order event logging
- uptime/store monitoring
- WooCommerce ↔ accounting synchronization

The positioning should not be:

> "Another WooCommerce diagnostics plugin."

Instead:

> **"Business Transaction Integrity for WooCommerce."**

The differentiation is the complete chain:

`Payment → Order → Stock → Invoice → Shipping`

and the ability to explain:

**Expected → Observed → Deviation → Root Cause → Consequences → Money Impact**

---

# 11. Product architecture direction

## Core

```
WooCommerce
    ↓
Integrity Engine
    ↓
Rule Evaluation
    ↓
Incident Engine
    ↓
Dashboard / Notifications / Repair
```

## Connector layer

```
                 ┌─ Stripe
                 ├─ PayPal
                 ├─ Xero
Integrity Engine ├─ JTL
                 ├─ DHL
                 ├─ Sevdesk
                 └─ Lexoffice
```

Each integration should be isolated behind a connector interface.

This keeps the core plugin independent from external services.

---

# 12. Flow Builder

The merchant should eventually be able to define an expected process.

Example 1:

`Payment → WooCommerce → Xero → DHL → Customer`

Example 2:

`Payment → WooCommerce → JTL → Warehouse → DHL`

The engine then checks whether reality matches the configured process.

This is a key long-term differentiator.

---

# 13. AI strategy

AI is **not required for the core MVP**.

The initial product should use deterministic PHP rules.

Advantages:

- no mandatory OpenAI/API costs
- predictable behavior
- easier debugging
- easier compliance story
- lower operating cost
- works locally

AI may be introduced later for:

- root-cause explanation
- incident summarization
- anomaly detection
- suggested repair
- natural-language reports

But AI should remain an optional enhancement, not a dependency of the core product.

---

# 14. What we should NOT build yet

Avoid:

- SaaS backend
- mandatory cloud infrastructure
- complex AI pipeline
- too many integrations before validation
- automatic risky repairs
- overly complex workflow builder
- enterprise features before merchant validation

The product should remain a focused WooCommerce plugin.

---

# 15. Immediate next steps

## Step 1 — Finish Phase 1

- Verify current 0.6.0 deployment
- Test payment → order
- Test order → stock
- Test fulfillment timeout
- Test incident resolution
- Test manual scan
- Test Stripe reconciliation

## Step 2 — Money Impact

Implement:

- affected order value
- affected revenue
- dashboard financial summary
- incident financial impact

## Step 3 — Notifications

Start with:

- email notification
- critical incident notification
- daily summary

## Step 4 — Validation

Create:

- landing page
- screenshots
- demo shop
- beta installation package
- simple feedback mechanism

## Step 5 — Auto-Reconcile

Start with:

- safe manual repair
- repair log
- before/after state
- explicit confirmation

## Step 6 — Advanced integrations

Only after validation:

- JTL
- DHL
- accounting
- Germanized
- other connectors

---

# 16. Definition of MVP

The MVP is ready when a merchant can:

1. Install the plugin.
2. Open the dashboard.
3. Run a scan.
4. See problematic orders.
5. Understand the root cause.
6. See downstream consequences.
7. See affected money.
8. Receive an alert.
9. Open the affected order.
10. Resolve the problem safely.

At this point the plugin should already provide standalone value without AI, SaaS infrastructure or external vendor APIs.

---

# 17. Strategic decision log

### Decision: Plugin, not SaaS
**Status: FINAL**

### Decision: Local-first
**Status: FINAL**

### Decision: PHP rule engine first
**Status: FINAL**

### Decision: Money Impact moves into MVP
**Status: AGREED**

### Decision: Notifications move earlier
**Status: AGREED**

### Decision: Auto-Reconcile follows detection
**Status: AGREED**

### Decision: German integrations become later moat
**Status: AGREED**

### Decision: Free → Pro → Agency
**Status: AGREED**

### Initial pricing hypothesis

- Free: €0
- Pro: €59/year
- Agency: €179/year

Pricing remains subject to validation.

---

# 18. Current priority order

```
1. Phase 1 — Integrity Engine
          ↓
2. Phase 3 — Money Impact
          ↓
3. Phase 5 — Notifications
          ↓
4. Validation / Beta
          ↓
5. Phase 4 — Auto-Reconcile
          ↓
6. Phase 2 — Advanced Fulfillment
          ↓
7. Phase 6 — German Integrations
```

---

# 19. Working rule for future development

For every new feature, ask:

1. Does it detect a real merchant problem?
2. Does it save money, time or customer complaints?
3. Can it run locally?
4. Does it make the plugin easier to sell?
5. Can it be built without creating SaaS infrastructure?
6. Is it more important than validating the existing product?

If the answer to #6 is yes, validate first.

---

## Project status

**Product:** WooCommerce Order Integrity  
**Model:** Plugin-only  
**Version:** 0.6.0  
**Current focus:** Phase 1 verification → Money Impact → Notifications  
**Target market:** WooCommerce merchants, initially Germany  
**Long-term moat:** Cross-system transaction integrity + German ecosystem connectors
