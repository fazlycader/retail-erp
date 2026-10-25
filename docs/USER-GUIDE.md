# Retail ERP User Guide

This guide explains the workflows currently available in Retail ERP. Your account's role and the selected location determine which screens and records you can access.

## 1. Sign in and choose a location

Sign in with the account provided by your administrator. Public self-registration is disabled. If two-factor authentication is enabled or required for your role, complete the second step after entering your password.

Use the location switcher in the top bar to select the showroom or warehouse you are working in. Stock, sales, cash shifts, purchasing activity and most documents are location-scoped. Administrators can select **All locations** on supported pages; other roles see only locations they are allowed to work at.

On a shared till, a manager can enable terminal mode for the current location. Staff with a PIN can unlock the terminal. Lock it when leaving; automatic locking follows the location's configured timeout.

## 2. Roles and access

The administrator assigns each user a role and one or more locations. The main roles are:

| Role | Typical work |
|---|---|
| Admin | All locations, staff and company setup, access control, and administration |
| Manager | Day-to-day operations and approvals at assigned locations |
| Cashier | POS, sales, installment collections, and own cash shifts |
| Storekeeper | Products, stock, transfers, purchasing, workshop and delivery tasks as permitted |
| Accountant | Sales, customers, purchasing, reports and installment records as permitted |
| Workshop | Workshop job board and job details |
| Driver | Assigned delivery route, delivery proof and permitted collection actions |

Menus and server-side access are role-controlled. Cost and profit information is restricted to permitted roles. A manager PIN may be required for overrides such as discounts, stock discrepancies, credit exceptions or other configured approvals.

## 3. Recommended initial setup

An administrator should complete these steps before normal trading:

1. Confirm company details, VAT registration and document prefixes in **Settings**.
2. Set up active locations and their receipt settings, business-day start time and terminal auto-lock.
3. Create staff accounts, assign roles and locations, and configure PINs for shared terminals or approvals as needed.
4. Configure registers, payment methods, cash categories and shift rules in **Shift & cash**.
5. Create product categories, attributes, products, variants, prices, barcodes, suppliers and reorder levels.
6. Load opening stock for each location and verify it before trading.
7. Configure customers, supplier terms and any company-specific approval limits.
8. Before enabling installment plans, obtain the approved accounting/tax treatment and agreement wording, then set the corresponding policy. Do not activate a plan while required policy choices are blank.
9. If using WooCommerce, configure credentials and fulfilment/stock settings in **Settings → Integrations** and review the WooCommerce integration guide.

Use a staging environment to rehearse imports and end-to-end workflows before using real trading data.

## 4. Products and stock

### Product catalogue

In **Products**, maintain categories, reusable attributes, products and variants. A variant can have its own SKU, barcode, price, minimum price, cost, reorder settings and online price. Product types include stocked items, made-to-order items and services.

Use the catalogue import workflow to upload a CSV/XLSX file, map columns and review the dry-run report before committing. Imports match existing variants by SKU and do not delete catalogue records. For large files, processing may continue through the database queue.

Print barcode labels from the product catalogue where available. Product cost fields are omitted from screens that are not permitted to view costs.

### Stock operations

- **Stock** shows on-hand and reserved quantities by location, movement history, adjustments and stock counts.
- Record an adjustment with its reason; a manager approval may be required when its value exceeds the configured threshold.
- A stock count freezes the system quantity when started. Enter counted quantities, review variances and have an authorized manager post the count.
- For initial balances, use the opening-stock import for the correct location. Review its dry run before committing; duplicate opening stock for an item/location is rejected.
- Stock transfers move through request, dispatch, receipt and discrepancy resolution. The receiving location records what actually arrived; a manager resolves shortages or overages.

Use the stock screens and workflows rather than editing stock records outside the application. The movement ledger is the audit history for inventory changes.

## 5. Suppliers and purchasing

Create supplier records before raising purchase orders. Purchasing staff can create and send a purchase order, receive all or part of it into a location, record supplier bills and allocate supplier payments. A goods receipt updates stock and cost records; damaged items are recorded separately. Over-receiving may require manager approval.

Supplier returns record stock leaving the location and any expected supplier credit. Reorder suggestions use variant reorder levels, current stock and quantities already on open purchase orders; review suggested supplier and quantities before creating a draft order.

## 6. Customers, quotations and sales orders

Customer records support normalized Sri Lankan phone numbers, contact details, NIC, addresses, business/VAT details, credit limits and blacklist notes. Check that the correct customer is selected before confirming an order or installment agreement.

Quotations can be drafted, shared, followed up, accepted, marked lost or converted to a sales order. When prices have changed since a quotation was prepared, review whether to honour the quoted prices or reprice during conversion.

Sales orders reserve stock at their fulfilment location when confirmed. Fulfilment can be partial or complete. Cancelled orders release their reservations; cancellation of a paid order follows the configured payment/credit-note workflow and may require manager approval.

## 7. POS, payments and returns

At the POS:

1. Confirm the active location and, if prompted, the register/shift.
2. Scan a barcode or search/browse the catalogue. Exact SKU/barcode matches can be added directly.
3. Select or create the customer when needed and review the server-calculated cart total, discounts and tax.
4. Add one or more payment methods. Enter required references or cheque details. Cash tender may include change.
5. Complete checkout and print or share the receipt or invoice.

Cash-and-carry sales are fulfilled at checkout. Other sales may remain open with stock reserved for later fulfilment. Held carts are location-specific.

Payment receipts can be recorded from a sales order as well as through POS. Voids, returns after the allowed return window, discounts below configured limits and other exceptions can require a manager PIN. Returns capture item condition and restock location. Damaged goods are not returned to saleable stock. The system settles only the amount actually due after returns; a credit note or refund may be used according to the selected outcome and policy.

## 8. Shifts, cash and end of day

Cashiers open a shift for their register with a float and denomination count. Record pay-ins, pay-outs and cash drops against the appropriate category; approvals may be required above configured thresholds. Use the shift's X-report for a live view.

At close, count the drawer and submit the denominations. Blind-close settings may hide expected cash until the count is submitted. A variance over the configured threshold remains pending until an authorized manager approves it. The Z-report is frozen at close.

Managers can record bank deposits from a shift or the safe and process eligible cheque deposits. At **End of day**, review blocking items and warnings, resolve open shifts, then close the business day. Once closed, the day is locked; edits to locked-day payments and orders are restricted and audited.

If the location requires an open shift for payments, open the shift before taking payments or cash refunds.

## 9. Dashboard and reports

The dashboard summarizes activity for the current location and day. Authorized management and accounting roles can view reports, choose dates and location scope, and export supported reports to CSV/XLSX or print.

Profit figures and costs appear only for roles with the relevant access. A missing profit card or column can therefore be an access restriction, not missing sales data. Summary values for recent or unclosed days may be calculated from live transaction data.

## 10. Custom orders and workshop

Made-to-order lines can carry specifications and private design/approval files. Workshop jobs can be assigned, prioritized and moved through configured stages; materials, labour, overhead and notes can be recorded. Completion creates finished stock for the workshop flow, which can then be transferred to fulfilment.

The workshop implementation is present, but its focused validation is not yet clean. Test the complete workflow, notifications and permissions in staging before relying on it for live operations.

## 11. Deliveries and installations

Delivery staff can plan zones, delivery runs, vehicles, stop order and installation tasks. The driver app shows assigned stops and supports proof of delivery and permitted collection actions. Delivery and installation messages use configured templates.

The delivery implementation is present, but end-to-end validation of stock loading/returns, collection handover, driver retries and manager permissions remains outstanding. Rehearse these cases in staging before using them for live deliveries.

## 12. Installment plans

Installments are configured by an administrator or authorized manager under **Installments → Setup**. Plan terms, minimum down payment, charges, fees, grace periods, reminders, settlement rules and agreement policy are settings rather than fixed values.

### Before activating a plan

- Confirm the scheme with the business owner.
- Obtain accountant-approved tax treatment and contract wording. Those answers have not yet been supplied for this implementation, so the related policy must remain unset until approved.
- Decide the ownership-until-paid wording/rule and configure it explicitly.
- Configure permitted terms, down payment, flat charge (if used), document fee, late-fee rule and early-settlement rule.
- VAT-based installment treatment is available only when company VAT registration is configured.

The working scheme provided for development uses a configurable 10% flat charge on the financed amount, optional guarantors, and owner-configured terms, down payment, late fees and early settlement. The actual plan settings and approved terms must be confirmed before use; do not treat these working assumptions as legal or tax advice.

### Create and manage an agreement

1. Start an installment agreement from an eligible sales order.
2. Select an active plan, term and start date; enter the down payment and required customer details. The customer's NIC is required. Add guarantors only when the business policy calls for them.
3. Review the live schedule preview, financed amount, charges and total payable before saving.
4. Complete and upload the signed agreement using the approved wording. Draft and signed agreement PDFs are available from the agreement workflow.
5. On the agreement detail page, review the installment schedule and payments. Record a collection using an available payment method and keep any required reference.

Collections apply payments to outstanding late fees first and then the oldest installment. The schedule records payments, partial payments, late fees and overdue status. Early-settlement quotes apply the agreement's saved settlement policy.

The collections screen supports due/overdue follow-up, contact notes and promise-to-pay dates. Reminder and late-fee processing depends on the scheduled daily task being configured and running. Use the arrears report to group outstanding agreements by age and the customer statement to review that customer's installment ledger.

## 13. WooCommerce

The ERP is the source of product, price and stock data; WooCommerce supplies web orders. Configure the secure connection, online stock locations, fulfilment location, price sync and paid statuses before syncing. Review link/reconcile reports and resolve orders that need attention.

The integration does not automatically move money. A web order with a refund or uncertain external status is flagged for staff review rather than silently refunded or paid through the ERP. For setup and troubleshooting, see [WOOCOMMERCE.md](./WOOCOMMERCE.md).

## 14. Documents, security and support

Invoices, receipts, credit notes, delivery notes and other supported records can be printed or shared from their relevant screens. Public digital document links are tokenized and intended only for the specific document; do not post them publicly.

Never share passwords, PINs, recovery codes, customer data, integration credentials or signed agreements. Report unexpected access, incorrect balances or missing records to an administrator. Administrators should use the audit log to investigate changes and keep backups and deployment procedures current.

## 15. Current release notes and limits

- The current system includes the implemented foundation, catalogue, inventory, purchasing, sales/POS, shifts and cash, reports, production/deployment tooling, WooCommerce, workshop, delivery and installment modules.
- Workshop and delivery are implemented but still need the end-to-end staging validation described above.
- Installment tax treatment and approved legal wording, plus ownership-until-paid policy, are deliberately not assumed. Configure them only after business/accountant approval.
- Production launch still requires the staging checklist and rehearsal import in [GO-LIVE-CHECKLIST.md](./GO-LIVE-CHECKLIST.md) and [DATA-IMPORT.md](./DATA-IMPORT.md).
