# WooCommerce integration

The ERP keeps a WooCommerce shop in step with the showrooms. **The ERP owns products, prices and stock; WooCommerce owns web orders.** Each side only ever writes what it owns:

| Data | Owner | Direction |
|---|---|---|
| Products, variations, categories, images, dimensions | ERP | ERP → Woo (`woo:push`) |
| Prices (optional, see *Settings*) | ERP | ERP → Woo |
| Stock quantity | ERP | ERP → Woo |
| Orders, customers' web details, payment status | Woo | Woo → ERP (webhook, then `woo:reconcile` as a safety net) |
| "Completed" status on a web order | ERP | ERP → Woo, once the order is fulfilled here |
| Showroom availability, visit requests | ERP | Read by the WordPress plugin |

Never edit stock or prices in WooCommerce by hand. The next push overwrites them, and a product whose payload hasn't changed won't be re-sent, so the shop would stay wrong until something changes here.

## 1. Set up

### 1.1 WooCommerce REST keys

1. In WordPress, go to **WooCommerce → Settings → Advanced → REST API → Add key**.
2. Set *Description* to `ERP` and *User* to an administrator, and give it **Read/Write** permissions.
3. Copy the *Consumer key* (`ck_…`) and *Consumer secret* (`cs_…`). They are shown only once.

### 1.2 Enter the credentials in the ERP

As an admin, go to **Settings → Integrations → WooCommerce** and set each of these:

| Key | Value |
|---|---|
| `WOO_STORE_URL` | The shop's address, `https://…` (http is refused) |
| `WOO_CONSUMER_KEY` | `ck_…` |
| `WOO_CONSUMER_SECRET` | `cs_…` |
| `WOO_WEBHOOK_SECRET` | A long random string you make up (for example `openssl rand -hex 32`). You'll paste it into WooCommerce in 1.3. |

Values are stored encrypted in `integration_credentials` and are never shown back in full. Each change can be undone one step and is audited. Use **Test connection** to check them.

If the keys are already in the server's `.env`, run `php artisan integrations:migrate-from-env` once to move them in.

### 1.3 Webhooks

In **WooCommerce → Settings → Advanced → Webhooks**, add two webhooks with the same settings apart from the topic:

| Field | Value |
|---|---|
| Status | Active |
| Topic | `Order created`, then a second one with `Order updated` |
| Delivery URL | The one shown on the Integrations page: `https://<erp>/api/woo/webhook` |
| Secret | The same value as `WOO_WEBHOOK_SECRET` |
| API version | WP REST API Integration v3 |

When you save, WooCommerce sends a ping, and the ERP answers it.

### 1.4 Settings (Online store page, admins)

| Setting | Meaning |
|---|---|
| **Online stock locations** | The locations whose stock counts towards what the shop shows. Usually the showrooms. Leave the warehouse out unless web orders really ship from it. |
| **Web fulfilment location** | Where web orders are created, reserved and fulfilled. |
| **Reserve at** | `fulfilment`: reserve at the web fulfilment location. `stock`: reserve at the online location with the most available stock. |
| **Sync prices** | On: the shop's regular price = the variant's online price, else its price. Off: prices are managed in WooCommerce. |
| **Paid statuses** | Woo statuses that mean the money is in. Default: `processing`, `completed`. Only these record a payment. |
| **Back-order text** | Shown for made-to-order items, e.g. "Made to order: 3–4 weeks". |

Saving re-queues every online product, so the shop is re-sent in full.

Per variant, **Online buffer** (Online store → Stock table) holds back that many units from the web, so the last few pieces stay in the showroom.

### 1.5 First link (existing shop)

If the shop already has products, link them by SKU **before** the first push so nothing is duplicated:

```sh
php artisan woo:link --dry-run   # report only: what would link, Woo-only SKUs, ERP-only SKUs, conflicts
php artisan woo:link             # write the links (never creates or changes anything in Woo)
php artisan woo:push --all       # then send everything
```

The last report is shown on the Online store page under *Unmatched*. SKUs are compared case-insensitively. A duplicate SKU on either side is reported as a conflict and not linked: fix it at the source.

Even without `woo:link`, the pusher looks up a product's SKU in WooCommerce before creating it, and links it if found.

### 1.6 WordPress plugin (showroom availability and visit requests)

1. **Online store → Download plugin** (or `php artisan woo:plugin-package`, which writes `storage/app/private/woo-plugin/mvrk-woo-connector-<version>.zip` plus a `.sha256` file).
2. In WordPress, go to **Plugins → Add New → Upload Plugin** and activate it.
3. On the ERP server, create a token: `php artisan woo:storefront-token` (it can only read availability and file visit requests). Revoke it with `--revoke`.
4. In WordPress, go to **Settings → MVRK Connector**, enter the ERP address (`https://…`) and the token, then press **Test connection**.

What the plugin adds:

- **Availability per showroom** on product pages: under *Add to cart* automatically (can be turned off), as the block *Available at our showrooms*, or with the shortcode `[mvrk_showroom_availability sku="…"]`. It follows the chosen variation. Labels: *In stock* (3 or more), *Only N left*, *Made to order*, *Ask us*. Answers are fetched by AJAX and cached for 5 minutes per SKU in a transient.
- **Reserve to view in showroom**: a visit request form (name, phone, email, showroom, preferred date, message). It has a honeypot field and is limited to 5 requests per hour per visitor IP. Requests appear on the Online store page.
- **An admin notice** when the ERP can't be reached or the token is refused.
- Uninstalling removes only its own options (`mvrk_wc_*`) and cached answers.
- HPOS (custom order tables) compatibility is declared. Requirements: WordPress 6.4+, PHP 8.1+, WooCommerce 8.0+.

The plugin has no client names in its code. The credit line comes from `config/brand.php` when the zip is built (`BRAND_CREDIT=false` hides it).

## 2. How it works

### 2.1 Outbound: products and stock

- **What gets queued.** Any product, variant, image or price change, and any stock or reservation change, marks the product dirty in `woo_sync_queue`. It's marked **after the transaction commits**, so a rolled-back sale never reaches the shop. Changes are coalesced: one row per product, whatever happens to it.
- **Online stock** per variant = Σ over online stock locations of max(on hand − reserved, 0), minus the online buffer, floored at 0, in whole units. A location with negative stock never cancels out another location.
- **Product shapes.**
  - **Simple:** a product with one variant and no attributes.
  - **Variable:** the attributes are sent as `variation: true` options, and the variants go through `products/{id}/variations/batch`.
  - **Made-to-order:** sent with `manage_stock: false` and `onbackorder`.
  - **Taken offline (or deleted):** a linked product is set to **draft**. A product that was never linked is simply dropped from the queue.
- **Categories** are created in WooCommerce on first use (matched by slug first) and linked.
- **`woo:push`** runs every minute from the scheduler (without overlapping), within a 45 s budget:
  - Batches of up to 100 products go to `products/batch`, and their variations go in batches of up to 100.
  - Each payload's sha256 hash is stored in `woo_links.last_pushed_hash`. An unchanged payload is **skipped** without any call.
  - HTTP retries (0.5 s, then 2 s) on connection errors, 429 and 5xx.
  - An item that still fails waits **1, 5, 15, then 60 minutes** (`attempts`, `available_at`, `last_error`), and is listed under *Failing* with a **Retry** button.
  - A new change to the product resets its backoff.
  - `php artisan woo:push --all` re-queues everything. `--budget=` changes the time limit.

### 2.2 Inbound: web orders

1. **Webhook** `POST /api/woo/webhook`:
   - The signature (base64 HMAC-SHA256 of the raw body with `WOO_WEBHOOK_SECRET`) is checked first. If it's bad or missing, the response is **401** and a warning is logged, without the body.
   - The delivery ID (`X-WC-Webhook-Delivery-ID`) is stored once in `woo_webhook_events`. A redelivery answers `duplicate` and does nothing.
   - The order is then processed by a queued job (3 tries). The payload is kept only if processing finally fails, so it can be retried from the Online store page.
2. **`OrderIntake`** (one lock per Woo order, so a webhook and the reconciler never process the same order at once):
   - **Customer:** found by phone (normalised to `+94…`), then by email, else created. If there's no usable phone, the order gets *needs attention*.
   - **Lines:** matched by SKU. An **unknown or missing SKU** becomes a custom line and the order gets *needs attention*; it is never guessed. Woo fees become service lines.
   - **Delivery:** a `local_pickup` shipping method means *collect*. Otherwise, a shipping address means *deliver*, with the address and the shipping charge.
   - **Order record:** saved as channel **web**, numbered like any other order, and carrying the Woo order number. A total that differs from Woo's by more than LKR 1 is flagged.
   - **Confirm and reserve:** the order is confirmed and stock reserved only when nothing needs attention. If there isn't enough stock, the order is flagged instead.
   - **Payment:** when the Woo status is one of the *paid statuses*, the payment is recorded once, with method **Online** (no cash shift) and the Woo transaction id as reference.
3. **Updates to an existing order:**
   - **Becomes paid:** the payment is recorded, once.
   - **Cancelled, failed or refunded, and unpaid and unfulfilled here:** the order is cancelled and its reservations released.
   - **Cancelled, failed or refunded, but already paid or fulfilled:** the reservations are released and the order is flagged. **Money is never moved automatically.**
   - **Refunded (fully or partly):** the order is flagged so staff can settle it here (return or credit note).
4. **Push-back:** when the order is fulfilled or completed in the ERP, the Woo order is set to `completed` and a note with the delivery-note number is added.

### 2.3 Safety net: `woo:reconcile`

Runs hourly (at :17), within a 45 s budget:

- It pulls every Woo order modified since the stored cursor and runs it through the same intake. Missed orders are created, and orders already seen are no-ops.
- It compares WooCommerce's stock with what the ERP would send for linked products. Any difference clears the product's hash and re-queues it.
- The Online store page has a **Reconcile now** button.

## 3. Day to day: the Online store page

Admins and managers can use it.

| Section | What to do |
|---|---|
| Status | Last push, last reconcile, queue size, failing items. |
| Needs attention | Web orders with an unknown SKU, an unusable phone, a total mismatch, not enough stock, or a cancellation or refund after payment. Open the order, fix it, then press **Mark handled**. |
| Failing | Items in backoff, with the last error. **Retry** puts one back in the next run. |
| Webhooks | Deliveries that failed after 3 tries. **Retry** processes the kept payload again. |
| Unmatched / not linked | SKUs on only one side. |
| Visit requests | From the plugin. Mark them *contacted* or *closed*. |
| Stock | Every online variant: online quantity, buffer (editable), and whether it's linked. |
| Push all / Reconcile now | Run immediately (20 s and 25 s budgets), useful after big changes. |

Everything is logged in `woo_sync_log`. Warnings and errors are shown on the page.

## 4. Troubleshooting

| Symptom | Check |
|---|---|
| Nothing reaches the shop | Is cron running `schedule:run` every minute (see DEPLOYMENT.md)? Does **Test connection** pass? Look at the *Failing* list. |
| `WooCommerce 401` | The keys are wrong or lack Read/Write. Create new ones (1.1). |
| Webhooks rejected (401 warnings in the log) | `WOO_WEBHOOK_SECRET` doesn't match the webhook's *Secret*. |
| Orders appear only hourly | Webhooks are disabled or failing in WooCommerce (WooCommerce → Webhooks shows the delivery log). Reconcile is catching them. |
| A product is duplicated in Woo | Run `woo:link --dry-run`, delete the duplicate in Woo, run `woo:link`, then `woo:push --all`. |
| Shop stock differs | Stock outside online locations doesn't count, and the buffer is subtracted. `woo:reconcile` re-sends any difference. |
| Plugin: "token was refused" | Run `php artisan woo:storefront-token` again and paste the new token. |

## 5. For developers

| Piece | Where |
|---|---|
| Credentials | `App\Facades\Integrations` (`IntegrationStore`, encrypted, one-step undo); registry in `App\Services\Integrations\CredentialRegistry` |
| API client | `App\Services\Woo\WooClient` (REST v3, basic auth over https, retry, `each()` pages through `X-WP-TotalPages`, `BATCH_LIMIT` 100) |
| Dirty marking | `App\Services\Woo\WooDirty` (model events → `DB::afterCommit` → `WooSyncItem::mark`) |
| Payloads | `ProductPayloads` (shapes and hash), `OnlineStock`, `CategorySync` |
| Push | `WooPusher` (`woo:push`), `WooLinker` (`woo:link`) |
| Orders | `Api\WooWebhookController` → `Jobs\ProcessWooWebhook` → `OrderIntake`; `WooReconciler` (`woo:reconcile`) |
| Payments | `PaymentService::recordExternal()` (no shift, capped at the balance due, audited `payment.external`) |
| Storefront API | `Api\StorefrontController`: Sanctum token with the ability `storefront:read`, `GET /api/storefront/{ping,availability?sku=}`, `POST /api/storefront/enquiries` |
| Tables | `woo_links`, `woo_sync_queue`, `woo_webhook_events`, `woo_sync_log`, `storefront_enquiries`, `integration_credentials`, plus Woo columns on `company_settings`, `product_variants.online_buffer`, `sales_orders.woo_*` / `needs_attention` |
| Plugin | `wordpress-plugin/mvrk-woo-connector/` (`Mvrk_Wc_Api` is plain PHP with an injectable transport); zip via `woo:plugin-package` |
| Tests | `tests/Feature/WooCommerceTest.php` (Http::fake), `tests/Unit/WooPluginApiTest.php`; CI lints every plugin file with `php -l` |
