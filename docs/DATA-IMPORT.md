# Go-live data import

How to load the business's existing data into the ERP: first as a **full rehearsal on the staging subdomain** ([DEPLOYMENT.md](DEPLOYMENT.md) §16), then for real on the live site on go-live day.

Every importer works the same way:

- **Dry run first.** Nothing is written; every row is checked and you get a report.
- **A report file of every row that isn't plain**: skipped, failed, or created with something worth knowing (with the reason). Plain rows (a straightforward create) are left out, so the report is the to-do list.
- **Re-runnable without duplicates.** Running the same file again creates nothing twice: products match by SKU, customers by phone, suppliers by name, opening stock refuses an item that already has opening stock at that location, orders and balances remember what was imported (`import_keys`).
- **Never guesses.** Anything unclear (an unknown SKU, a phone number that isn't Sri Lankan, a date like 03/04/26, two names for one phone) fails or is skipped with the reason. Fix the **source file** and run it again; don't patch the data in the app.

Files: `.xlsx` (first sheet) or UTF-8 `.csv`, one header row. Header names are matched without caring about case or spaces (`Customer Phone` = `customer_phone`). Amounts in rupees (`125000` or `125,000.50`); dates `2026-10-31` or `31/10/2026` (day first); quantities up to 3 decimals.

## Import order

Each step needs the ones before it.

| # | What | Where | Matched / de-duplicated by |
|---|---|---|---|
| 1 | Locations, staff, company settings | By hand (DEPLOYMENT.md §9) | — |
| 2 | Products and variants | Products → Import (screen) | SKU |
| 3 | Suppliers | `golive:import suppliers` | name (case and spaces ignored) |
| 4 | Customers | `golive:import customers` | phone (normalised to +94…) |
| 5 | Opening stock per location (physical count) | Stock → Opening stock (screen), once per location | item + location |
| 6 | Open customer orders with advances paid | `golive:import open-orders` | order_ref |
| 7 | Supplier balances | `golive:import supplier-balances` | supplier + reference |

The command-line importers run in SSH on the server:

```bash
cd ~/domains/<domain>/app/current
<php> artisan golive:import --template=customers                                   # empty template (storage/app/private/go-live/)
<php> artisan golive:import customers ~/imports/customers.xlsx --user=owner@example.lk            # dry run
<php> artisan golive:import customers ~/imports/customers.xlsx --user=owner@example.lk --commit   # import
```

`--user` is an active admin: records are created (and audited) in their name. Each run prints a summary and the path of its report: `storage/app/private/go-live/<kind>-<date>-<time>-dry-run.csv` (or `-commit.csv`). Upload files with `scp`/SFTP into a folder **outside** `public_html` (e.g. `~/imports/`) and delete them after go-live: they contain customer data.

## 2. Products

Products → **Import**: download the template, fill it in, upload, map the columns, **Dry run**, read the counts (create / update / unchanged / fail), download the **report**, fix the file, upload again, then **Commit**. Large files are processed in the background. Matching is by SKU: running it again updates the same variants and never deletes anything.

Columns: `sku` (required), `product_slug` (rows with the same slug are variants of one product), `product_name`, `category` (full path, e.g. `Living room > Sofas`), `brand`, `type` (stock / made_to_order / service), `description`, `width_mm`, `depth_mm`, `height_mm`, `weight_g`, `assembly_required`, `requires_delivery`, `warranty_months`, `is_serialized`, `publish_online`, `is_active`, `tags`, `barcode` (blank = generated), `price`, `min_price`, `online_price`, `reorder_level`, `reorder_qty`, `variant_active`.

Check afterwards: 10 random items' price and minimum price against the price list; barcodes scan at the POS.

## 3. Suppliers

Columns: `name` (required), `contact_person`, `phone`, `secondary_phone`, `email`, `address`, `vat_number`, `payment_terms_days` (whole days, default 30), `credit_limit`, `bank_details` (stored encrypted), `notes`.

A supplier whose name already exists (ignoring case and extra spaces) is **skipped** and left unchanged; the same name twice in the file: the second is skipped.

## 4. Customers

Columns: `name` and `phone` (required), `secondary_phone`, `email`, `nic`, `type` (individual / business), `vat_number`, `address`, `city`, `district`, `credit_limit`, `notes`.

De-duplicated **by phone** (`077 123 4567`, `0771234567` and `+94 77 123 4567` are the same number):

- a phone already in the system → skipped, and the report shows both names when they differ (`Already a customer with this phone: "Kamala Perera" (file says "Kamala P.")`);
- the same phone twice in the file → the second is skipped, pointing at the first line;
- a phone that isn't a Sri Lankan number → fails. Customers without a phone can't be imported: add them at the till when they next buy.

Decide each skipped row by hand (usually: correct the name in the app or merge in the old system before exporting again).

## 5. Opening stock (per location, from a physical count)

1. Count every location on the day before go-live (closed, nothing moving). One count sheet per location.
2. Stock → **Opening stock**, choose the location, download the template. Columns: `sku`, `quantity`, `unit_cost` (the average cost to start with: it values the stock and drives gross profit), `serials` (one per piece for serialized items, comma separated), `note`.
3. Upload → the dry run lists every row; **Report of rows to check (CSV)** has every failed row with the reason (unknown SKU, service item, missing cost, serial count wrong, serial already in stock, SKU twice in the file).
4. Commit. Repeat for each location.

An item that **already has opening stock at that location** is refused, so uploading the same sheet twice can't double the stock. To correct a committed quantity, use a stock count or an adjustment (audited), not a second opening import.

Check afterwards: the stock matrix against the count sheets for 10 random items per location; Reports → *Stock on hand & valuation* total against the count's total value.

## 6. Open customer orders with advances already paid

Orders the customer has ordered (and often partly paid) but not yet received. One line per order line; the order-level columns repeat on each line or are filled on the first line only.

Columns: `order_ref` (the old order number, required), `location` (selling location code, e.g. `KDY`), `customer_phone` (an imported customer), `sku` **or** `description` (a custom made-to-order item), `quantity`, `unit_price` (as agreed, incl. any discount), `source` (stock / make / supplier_order; default stock for stock items, make otherwise), `order_date`, `delivery_date`, `fulfilment` (collect / deliver), `advance_paid`, `advance_date`, `notes`.

Each order becomes a **confirmed sales order** at that location: it gets an invoice number, stock lines are **reserved** (so import opening stock first), it is dated `order_date` (so today's sales aren't inflated), and the notes say which old order it was. The advance becomes a payment flagged **imported** (method *Other*, reference `Imported advance <order_ref>`, dated `advance_date`, on no shift: it is in no drawer today). Balances are then collected normally at delivery.

Fails with the reason (the whole order, never half of it): unknown location, SKU or customer phone; a blacklisted customer; quantity or price not a number; order-level columns that differ between lines; an advance larger than the order; **not enough stock to reserve** (fix the opening stock first). A price below an item's minimum is allowed (the importing admin approves it) and noted in the report.

`supplier_order` lines create draft purchase orders to the preferred supplier on confirmation: delete the drafts if the order was already placed with the supplier in the old system, or use `make` / `stock` instead.

Check afterwards: the number of open orders and the sum of advances against the old system's open-orders list; Sales → filter *Confirmed*.

## 7. Supplier balances

What the business owes each supplier at go-live. Columns: `supplier` (an imported supplier's name), `amount` (more than zero), `as_of`, `due_date` (blank = as_of + the supplier's credit days), `reference` (when one supplier has several open invoices, one line each with its own reference), `note`.

Each line becomes an unpaid supplier bill `OPENING-<reference>` (or `OPENING-GO-LIVE`), paid later like any bill. Credit balances (the supplier owes you) aren't imported: note them and settle by hand.

Check afterwards: Purchasing → Bills total against the old system's creditors list.

## The rehearsal (staging)

1. On staging: `app:install`, `app:create-admin`, set up company, locations and staff (DEPLOYMENT.md §16).
2. Export everything from the old system as on go-live day (a recent copy is fine for the rehearsal).
3. Run steps 2–7 in order: **dry run, read the report, fix the source file, repeat** until every non-plain row is understood, then commit.
4. Do the checks under each step, and the *Go-live data* section of the checklist.
5. Note the time each step takes: that's the go-live day plan.
6. Wipe staging and run it once more end to end from the final files, to be sure the procedure (not just the data) works.

On go-live day: the same files from a fresh export, the same order, on the live site, after the final physical count.
