# Go-live checklist

Tick each box on the site being checked (`https://<domain>`: first the staging subdomain for the rehearsal, then the live site), never on a local copy. Section numbers (§) refer to [DEPLOYMENT.md](DEPLOYMENT.md); the data steps to [DATA-IMPORT.md](DATA-IMPORT.md).

**Phase 1 is done when this list passes on the staging subdomain and a full rehearsal import has been run there** (§16). Then repeat it on the live site on go-live day.

## Server and deploy

- [ ] `https://<domain>` has a valid padlock; `http://` redirects to `https://` (§1)
- [ ] PHP 8.4+ on the web **and** in SSH/cron (`<php> -v`), all extensions ticked, php.ini limits set (§2)
- [ ] `shared/.env` is filled in, `chmod 600`, `APP_DEBUG=false`, `REQUIRE_TWO_FACTOR=true`, and `APP_KEY` is saved in the password manager (§7)
- [ ] All GitHub secrets and variables are set for this environment; the last **Deploy** run is green (§8, staging: §16)
- [ ] `ls -l ~/domains/<domain>/` shows `public_html` → `app/current/public` (or forward mode works) (§6)
- [ ] `https://<domain>/.env`, `/composer.json`, `/storage/logs/` and `/artisan` all return 403/404
- [ ] `curl -sI https://<domain>/login` shows `Cache-Control: no-store`, `Strict-Transport-Security` and `Content-Security-Policy`
- [ ] Cron line added; after 2 minutes `/health?token=…` shows `scheduler: ok` and `queue: ok` (§10)
- [ ] With the CDN on: `TRUSTED_PROXIES=REMOTE_ADDR`, app paths excluded, two staff on two devices each see their own name (§11)

## Accounts and security

- [ ] `app:install` and `app:create-admin` done; no demo users or demo data exist (§9)
- [ ] Every admin and accountant has two-factor login set up (the app insists) and their recovery codes stored safely
- [ ] Every staff member has their own account, home location and PIN; nobody shares a login
- [ ] Shared counter tablets are set up as terminals (user menu → **Use as shared terminal**) at the right location, with the auto-lock time set
- [ ] 10 wrong PINs lock a PIN for 15 minutes; a manager can unlock it on the Staff page (try it once)
- [ ] The deploy key (`~/.ssh/erp_deploy`) and `erp_known_hosts.txt` are deleted from your computer, or kept in a safe place

## Company and locations

- [ ] Settings: company name, logo, address, phone, VAT registered + VAT number (invoices then say **Tax Invoice**), prices include VAT, document prefixes, return window, credit-note expiry
- [ ] Locations: warehouse and every showroom with code, address, phone, receipt header/footer and business-day start
- [ ] Shift & cash → Registers & settings: registers per showroom, cash categories, variance and pay-out limits; **Payments need an open shift** on
- [ ] Reports email addresses and send time set; *Managers see profit* as the owner wants

## Go-live data (DATA-IMPORT.md)

- [ ] Products imported (dry run clean, report understood), prices and minimum prices spot-checked
- [ ] Suppliers, customers (de-duplicated by phone) imported; the reports' skipped rows explained
- [ ] Opening stock per location from the physical count imported and committed; the stock matrix matches the count sheets for 10 random items
- [ ] Open customer orders imported with their advances (method Other, flagged imported); totals checked against the old system's list of open orders
- [ ] Supplier balances imported; the supplier ledger total equals the old system's creditors total
- [ ] Every report file from the rehearsal kept, and every non-plain row explained or fixed at source

## Selling

- [ ] POS at each showroom: scan a barcode, search, browse a category; "available here / at Kandy" figures are right
- [ ] Cash & carry: cash with change, card with approval code, split card + cash; the 80 mm receipt prints from the browser
- [ ] An order to deliver with an advance → confirm → deliver → collect the balance by cheque; the delivery note prints
- [ ] A discount over the cashier limit asks for a manager PIN; a price below the minimum too
- [ ] A return to stock (resaleable) and one as damaged; a refund and a credit note; the credit note pays for a new sale
- [ ] The invoice PDF shows the logo, VAT number and QR; the QR / WhatsApp link opens the digital copy on a phone

## Stock and purchasing

- [ ] A transfer warehouse → showroom: dispatch, receive, the stock matrix updates at both ends
- [ ] A purchase order → GRN (with one damaged piece) → supplier bill → supplier payment
- [ ] A stock count at one location: blind count, variance review, manager post
- [ ] Low stock and reorder suggestions show the expected items

## Shifts, cash and end of day

- [ ] Open a shift with a counted float → cash, card and split payments → a pay-out over the limit (manager PIN) → a cash drop → close blind; the Z-report adds up
- [ ] A difference over the limit waits for a manager, who approves it
- [ ] Bank deposit from the safe with the pending cheques; the cheques show *Deposited*; mark one *Cleared*
- [ ] End of day at each location: open shifts block, the warnings make sense, the day closes and locks (a manager can't void that day's payment; an admin can, and it's in the audit log)
- [ ] The daily owner email arrives after the last location closes (or at the set time), not in spam (`app:test-mail` first, §5)

## Reports and dashboard

- [ ] Dashboard on the owner's phone: today vs last week per location, open orders, deliveries, low stock, cheques, follow-ups
- [ ] Sales, products, gross profit, payments, stock valuation and cheque reports show the test day correctly; CSV and Excel exports open
- [ ] A manager doesn't see profit unless *Managers see profit* is on
- [ ] Test sales are cancelled, voided or refunded (or the staging database is reset before go-live) so they don't count

## Backups and monitoring

- [ ] Off-site bucket configured; `backup:run` succeeds; `backup:list` shows the backup on the `backups` disk (§13)
- [ ] **Restore tested** once on staging: sales, payments, stock and product photos come back
- [ ] Backup archive password (if used) is saved in the password manager
- [ ] Uptime monitor on `/health?token=…` alerts the owner/manager; a keyword monitor for `"degraded"` (§12)
- [ ] Error alert email arrives: trigger one from `<php> artisan tinker` with `report(new RuntimeException('go-live test'));`

## Rollback drill

- [ ] `bash current/scripts/deploy/remote.sh list` shows the releases (§14)
- [ ] You know the one command to roll back: `scripts/deploy/rollback.sh` or `remote.sh rollback`
- [ ] The person on call has SSH access and this document

## First trading day

- [ ] Staff briefed: shared terminal and PIN switch, open/close shift, the POS pay pad, returns, end of day
- [ ] Someone checks `/health`, the dashboard and the end-of-day screen at each location every day for the first week
