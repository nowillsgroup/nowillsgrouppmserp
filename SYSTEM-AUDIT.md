# Nowills PMS ERP — System Audit

**Audit date:** 10 October 2026
**Audited by:** Arena agent (pre-development audit)
**Repository:** `nowillsgroup/nowillsgrouppmserp` (branch `arena/e34cdd76-nowillsgrouppmserp`, from `main` @ `83def70`)
**Product version audited:** Nowills PMS **7.34.0** (build reported as "from commit `103c96a`")

---

## 1. Repository state — read this first

The GitHub repository is currently **empty of application code**.

| Check | Result |
|---|---|
| `git log origin/main` | 1 commit — `83def70` "Initial commit" |
| Files tracked on `main` | `.gitattributes` **only** (66 bytes) |
| Branches | `main` only |
| Pull requests / issues | none |
| Other repos in the org | `nowillsgroup/farmer24-release` — also empty |

So `git pull` returns nothing to audit. The **actual application source exists only as an uncommitted working tree** that is mirrored to Google Drive (see §2). Everything in §3–§8 below was audited from that mirror plus the project's own documentation.

> **Blocker for development:** until the working tree is committed and pushed (or uploaded here), this repo contains no PHP to build on. See §9 for the three ways to fix that.

## 2. Where the source actually lives

Google Drive mirror of the project root (owner: `nowillsgroupgh@gmail.com`):

| Item | Drive ID | Last modified | Notes |
|---|---|---|---|
| Project root mirror (`nowills-pms`) | `1cNEyXzGZNiZfc6YNM2MolPeM5XdrA-KH` | 2026-10-10 20:19 | **Current state** — full Laravel tree, `app/`, `resources/`, `build/vendor`, docs |
| Older packaged snapshot | `12Run0k9i9a365-D0ZvZ7kExRrIhxpEg4` (`NowillsPMS-ERP-.zip`) | 2026-10-04 | 211 MB, superseded by the mirror |
| Older packaged snapshot | `160ONk576J9axWw_nUgzTSC7EktMgNoAz` (`nowills-pmserp.zip`) | 2026-10-03 | 102 MB |
| Older packaged snapshot | `1Qj3wZUODDxhVoTZrZ-6wwr0tvWciITXG` (`pms erp 3.zip`) | 2026-10-03 | 217 MB |

The mirror's own `.git` directory is the same single "Initial commit" repo — i.e. the working tree was never committed, it was copied (not cloned) into Drive.

## 3. Stack and runtime

| Layer | Technology |
|---|---|
| Framework | Laravel `^13.17` (PHP `^8.3`), package name `nowills/pms` |
| Database | MySQL (SQLite used for local/dev runs) |
| Extra packages | `chillerlan/php-qrcode` ^5 (QR on folios/invoices/E-VAT), `laravel/tinker` ^3 |
| Dev/test | PHPUnit ^12.5, Mockery, Collision, Pint, Pail, `laravel/pao`, Faker |
| Composer vendor dir | `build/vendor` (vendored on purpose — cPanel install needs **no Composer, no SSH**) |
| Front end | Blade views (`resources/views`, 100+ module folders), no separate SPA build in the shipped tree |
| Deployment | cPanel-oriented: `START-HERE.txt`, `deploy/crontab.txt`, `deploy/apache-vhost.conf`, `deploy/nginx.conf`, `deploy/package-cpanel.sh`, root `.htaccess` forwarding into `public/` |
| Installer | `public/install.php` (DB + property + admin account), removed after install |
| Scheduler | one cron entry running `artisan schedule:run` each minute |

## 4. Codebase scale (verified counts)

| Area | Count |
|---|---|
| Modules in the sidebar registry | **52** |
| Pages (registered routes/menu targets) | **358** |
| Menu sections / top-level groups | 5 sections / 12 groups |
| Eloquent models (`app/Models`) | **398** |
| Controllers (`app/Http/Controllers`, incl. `Auth/`, `Api/`) | **149** |
| Services (`app/Services`, incl. `Payments/`) | **126** |
| Console commands (`app/Console/Commands`) | **27** |
| Migrations (`database/migrations`) | **85** |
| Route files | `routes/web.php` (~236 KB), `routes/console.php` |
| Config files | 12, incl. `config/pms.php` (menu + permission registry) and `config/masters.php` |
| View folders | 107 top-level entries under `resources/views` |
| Documentation | `README.md` (~500 KB full manual), `docs/MODULES.md`, `docs/FEATURE-GAP-AUDIT.md`, `docs/TEST-STATUS.md`, `docs/INSTALL-CPANEL.md` |
| Automated tests (last green CI, older repo) | 84 classes / 651 tests across six MySQL shards |

## 5. Module catalogue

Source of truth: `config/pms.php` → `menu`, rendered by `resources/views/partials/sidebar-nav.blade.php`. Every entry carries a `perm` key enforced by `RequirePermission` middleware, so the visible menu is role-dependent.

### 5.1 Dashboard & Operations — section `DASHBOARD` — 4 modules

| Module | Pages | Page list |
|---|---|---|
| Dashboard | direct link | — |
| Group Dashboard | direct link | — |
| Night Audit | direct link | — |
| Reports | 4 | All Reports · My Reports & Saved Views · Scheduled Reports · KPI Alerts |

### 5.2 Front Office & Guest Services — section `OPERATIONS` — 10 modules

| Module | Pages | Page list |
|---|---|---|
| Front Office | 8 | Room View · Tape Chart · Walk-in Check-in · Arrivals · In-House Guests · Departures · Guest Requests · Offline Request Capture |
| Desk Operations | 7 | Shift Log Book · Shift Hand-over · Wake-up Calls · Messages & Parcels · Traces · Lost & Found · Desk Settings |
| Reservations | 7 | New Reservation · Reservation List · Availability · Group Blocks · Rate Calendar · Channel Manager · iCal Feeds |
| Central Reservations | 6 | CRO Desk · Enquiries · Guest Lookup · Lost Business · CRO Performance · CRO Settings |
| Concierge | 6 | Concierge Desk · Activity Bookings · Activities & Vendors · Local Guide · Vendor Settlements · Concierge Settings |
| Guest Messaging | 6 | Inbox · Messaging Dashboard · Broadcasts · Quick Replies · Routing Rules · Messaging Settings |
| Self-Service Kiosk | 4 | Kiosk Dashboard · Kiosks · Guest Visits · Kiosk Settings |
| IPTV & Guest Welcome | 9 | TV Dashboard · TVs & Screens · Channels & Guide · Movies & Rentals · Welcome Screens · Information Pages · TV Messages · Usage Analytics · IPTV Settings |
| Foreign Guests | 6 | Form C (FRRO) · Currency Exchange · Exchange Rates · Currency Position · Currencies · Foreign Guest Settings |
| Interfaces | 3 | Call Accounting · Key Cards · Interface Hub |

### 5.3 Guest Relations & Commercial — section `OPERATIONS` — 7 modules

| Module | Pages | Page list |
|---|---|---|
| Guests & CRM | 12 | Guest Profiles · Companies / Agents · Rate Contracts · Feedback & Reputation · Guest Cases · Loyalty Program · Privacy & Data · Marketing Dashboard · Campaigns · Guest Segments · Automations · Promo Codes |
| Sales & Marketing | 9 | Sales Dashboard · Accounts · Opportunities · Sales Diary · Sales Targets · Marketing Calendar · Marketing Budget · Collateral Library · Sales Settings |
| Digital Marketing | 7 | Digital Dashboard · Content Calendar · Paid Campaigns · Tracked Links · Channels & Listings · Influencers · Digital Settings |
| Business Development | 6 | BD Dashboard · Competitive Set · Market Mix · Partnerships · Initiatives · BD Settings |
| Gift Vouchers | 4 | Vouchers · Sell Voucher · Check / Redeem · Voucher Types & Campaigns |
| Partner Portal | 5 | Partner Dashboard · Requests & Approvals · Partner Accounts · Portal Activity · Portal Settings |
| Organiser Portal | 6 | Organiser Dashboard · Requests & Approvals · Portal Events · Organiser Logins · Portal Activity · Portal Settings |

### 5.4 Revenue & Financial Management — section `FINANCE` — 7 modules

| Module | Pages | Page list |
|---|---|---|
| Revenue | 4 | Revenue Dashboard · Pricing Grid · Pricing Rules · Upselling |
| Billing | 6 | Folios · Non-Resident Bill · Invoices · GRA VAT & E-VAT · Online Payments · Gateway Payouts |
| Cashiering | 2 | Cashier Dashboard · Shift Register |
| Income Audit | 4 | Audit Days · Authorisations · Room Count · Room Discrepancies |
| Accounts | 24 | Vouchers · City Ledger · Credit Control · Agent Commissions · Supplier Bills (AP) · Supplier Payments · Withholding Tax (GRA) · AP Ageing · Cash Book · Bank Reconciliation · Petty Cash · Expense Claims · Profit & Loss · General Ledger · Budgets · Budget vs Actual · Journal Entries · Period Close · Accruals & Prepayments · Recurring Journals · Year-end Close · Balance Sheet · Chart of Accounts · GL Mapping |
| Treasury | 6 | Cash Position · Banking & Transfers · Cheque Registers · 13-week Cash Forecast · Cash-flow Statement · Bank Accounts |
| Owner Reporting | 6 | Owner Dashboard · USALI Statement · Monthly Owner Packs · Management Fees · FF&E Reserve & Capex · Agreement & Mapping |

### 5.5 Housekeeping & Laundry — section `OPERATIONS` — 2 modules

| Module | Pages | Page list |
|---|---|---|
| Housekeeping | 5 | Room Status · HK Tasks & Inspection · My Rooms (mobile) · Minibar Items · Maintenance |
| Laundry & Linen | 4 | Laundry Orders · Laundry Price List · Linen Stock & Par · Linen Batches |

### 5.6 Food & Beverage — section `OPERATIONS` — 6 modules

| Module | Pages | Page list |
|---|---|---|
| POS / Restaurant | 14 | POS Terminal · Orders & Bills · Floor Plan & Waitlist · Table Reservations · Kitchen Display · KOT Printers · Print Station · Menu Items · Menu Categories · Outlets · Tables · Table QR Codes · Modifiers & Add-ons · Reservation Settings |
| Packages & Meals | 4 | Package Dashboard · Meal Desk · Entitlements · Catalogue & Inclusions |
| Banquet | 7 | Events & Enquiries · Function Diary · Function Sheet (BEO) · Venues · Menu Packages · Services & Equipment · Banquet Settings |
| Food Safety | 7 | Food Safety Dashboard · Temperature Logs · HACCP Checklists · Allergen Matrix · Food Waste Log · Prep Labels · Food Handler Certificates |
| F&B Cost Control | 9 | Cost Dashboard · Recipe Cards & Plate Cost · Menu Engineering · Food & Beverage Cost · Usage Variance · Menu Pricing · Yield Tests · Staff Meals · Cost Settings |
| Inventory | 16 | Stock Summary · Requisitions · Budgets & Par Levels · Purchase Orders · RFQs & Quotations · Goods Receipts (GRN) · Supplier Price Lists · Supplier Scorecard · Stock Issue / Adjust · Stock Ledger · Stock Take · Re-order Suggestions · Recipes · Items · Suppliers · Stores |

### 5.7 Accommodation & Property — section `OPERATIONS` — 3 modules

| Module | Pages | Page list |
|---|---|---|
| Apartments & Leases | 5 | Leasing Dashboard · Leases · Units · Billing & Arrears · Meter Readings |
| Fixed Assets | 4 | Asset Register · Depreciation · Asset Categories · PM Schedules |
| Parking & Valet | 7 | Parking Desk · Valet Board · Tickets · Vehicles · Permits · Zones · Tariff & Settings |

### 5.8 Facilities & Security — section `OPERATIONS` — 3 modules

| Module | Pages | Page list |
|---|---|---|
| Engineering | 3 | Utilities & Energy · Daily Meter Readings · Licences & Compliance |
| Security | 4 | Visitors · Gate Passes · Incident Register · Watch List |
| Transport | 9 | Dispatch Board · Trips · My Trips · Fleet & Papers · Vehicle Expenses · Vehicles · Drivers · Tariff Card · Transport Settings |

### 5.9 Wellness & Recreation — section `OPERATIONS` — 2 modules

| Module | Pages | Page list |
|---|---|---|
| Health Club | 7 | Club Dashboard · Check-in Desk · Members · Billing & Renewals · Class Timetable · Day Passes · Plans & Settings |
| Spa & Activities | 3 | Appointments · Services & Activities · Treatment Rooms |

### 5.10 HR & Workforce — section `PEOPLE` — 3 modules

| Module | Pages | Page list |
|---|---|---|
| Talent Management | 9 | Talent Dashboard · Vacancies · Applicants · New Starters · Appraisals · Discipline & Grievances · Exits & Turnover · Establishment · Talent Settings |
| HR & Payroll | 19 | Employees · Attendance · Monthly Register · Leave Requests · Duty Roster · Time Clock · Time Clock Kiosk · Service Charge & Tips · Payroll Runs · Salary Advances · Statutory Returns · Pay Components · Leave Types · Departments · My Payslips & Leave · My Roster & Time Clock · My Training & SOPs · My Appraisal · My Expense Claims |
| Learning & SOPs | 7 | L&D Dashboard · Training Matrix · Training Sessions · Training Records · Courses & Requirements · SOP Library · L&D Settings |

### 5.11 Quality, Safety & Sustainability — section `GOVERNANCE` — 3 modules

| Module | Pages | Page list |
|---|---|---|
| Quality Assurance | 6 | QA Dashboard · Audits · Corrective Actions · Standards · Checklists · QA Settings |
| Health & Safety | 8 | HSE Dashboard · Fire Equipment · Inspection Round · Permits to Work · Accidents & Near Misses · Risk Assessments · Drills & Wardens · HSE Settings |
| Sustainability & ESG | 13 | ESG Dashboard · Carbon & HCMI · Waste · Refrigerants (F-gas) · Green Stay · Targets · Initiatives · People & Community · Certification · Policies · Carbon Statements · Annual ESG Report · ESG Settings |

### 5.12 Administration & Configuration — section `GOVERNANCE` — 2 modules

| Module | Pages | Page list |
|---|---|---|
| Masters | 8 | Room Types · Rooms · Floors · Rate Plans · Taxes · Business Sources · Payment Modes · Item Categories |
| Administration | 10 | Users · Roles & Permissions · Hotel Settings · Activity Log · E-mail Log · SMS / WhatsApp Settings · Online Payment Settings · SMS / WhatsApp Log · API & Webhooks · Database Backup |

**Totals: 5 sections · 12 groups · 52 modules · 358 pages.**

## 6. Known gaps (from `docs/FEATURE-GAP-AUDIT.md`, 8 Oct 2026)

The catalogue is broad; the open work is in **real-world integrations and migration**, not empty menus.

| Priority | Area | Status |
|---|---|---|
| **P1** | PBX / door-lock hardware | Only `demo` and `offline` drivers exist; no live vendor driver — card encoding and PABX commands never reach hardware |
| **P1** | Direct OTA / channel integrations | iCal, signed inbound webhooks and a generic ARI hub exist; Booking.com / Expedia provider adapters are placeholders — only `hub` + `simulator` are selectable |
| **P2** | Social publishing & platform analytics | No social APIs; posts marked published manually, metrics hand-entered or CSV-imported |
| **P2** | Online review ingestion / replies | Manual + CSV intake, templates, approval; no Google/TripAdvisor/Booking review or reply API |
| **P2** | Bank / Mobile Money live feeds | CSV/typed statement reconciliation only; no live bank/MoMo/acquirer feed |
| **P2** | Legacy PMS migration & cutover | No importer for guests, reservations, folios, rooms, inventory, suppliers, employees or opening balances |
| **P2** | GRA E-VAT production readiness | Configurable HTTP driver, QR/stamp rendering and credit notes exist; payload still needs GRA authority UAT/certification |
| **P3** | Broader offline operation | Only housekeeping status/tasks/inspections/maintenance and guest requests use the IndexedDB outbox; no offline check-in, POS, folio or payments |
| Optional | Native mobile clients | Responsive web + PWA only; no Android/iOS/Flutter source |

**Built but needs deployment configuration (do not rebuild):** Paystack / Stripe / Razorpay payment adapters, Twilio / Hubtel / generic SMS-WhatsApp gateways, SMTP mail, generic signed channel-hub protocol. These need live credentials, merchant accounts, webhooks, DNS/HTTPS and UAT.

**Test status carried forward:** 84 test classes / 651 tests, six MySQL shards green on application commit `d857feb` (CI run `37752732099`, older `NowillsPMS-ERP` repo). The later build referenced by `START-HERE.txt` is commit `103c96a` — that commit's suite has **not** been verified in this audit.

## 7. Operational notes

- **Install:** point the domain at `nowills-pms/public`, or rely on the root `.htaccess` forwarder; run `install.php`; then delete it.
- **Permissions:** `storage/`, `bootstrap/cache/`, `public/uploads/` → 755.
- **Cron:** `*/1 * * * *` → `php /home/USER/nowills-pms/artisan schedule:run`.
- **No Composer / no SSH needed** on the host — packages ship in `build/vendor`.
- 27 scheduled commands cover night audit, GL sync, channel push, iCal sync, e-invoice retry, payments expiry, CRM/feedback/upsell/transport runs, backups, upgrades and more.

## 8. Audit method and limits

Static audit of the Drive mirror tree (directory listings and file byte sizes), plus full reads of `docs/MODULES.md`, `docs/FEATURE-GAP-AUDIT.md`, `composer.json` and `START-HERE.txt`.
Limits: no PHP/MySQL runtime was executed; no production credentials, hardware, provider contracts or user acceptance were verified; the GitHub repo was not the source of this audit because it contains no application code.

## 9. Blockers and how to unblock development

1. **Push the real tree to GitHub (recommended).** On the machine that holds the working copy: `git add -A && git commit -m "Import Nowills PMS 7.34.0" && git push origin main`. The GitHub repo and the Drive mirror are the same code, and the mirror has no extra commit history to recover.
2. **Upload a zip into this Arena session.** Attach `NowillsPMS-ERP-.zip` (or a fresh export) and it will be extracted and committed onto this branch — no vendor/build folders needed if you prefer a lean repo.
3. **Selective restore.** I can reconstruct specific areas (e.g. `config/pms.php`, routes, a module's controllers/services/views) from the Drive mirror on request, but a full ~3,000-file byte-for-byte restore through the Drive API is impractical.

Suggested first development steps once code is in place: (a) run the test suite to confirm the 651-test baseline on the current commit; (b) choose the P1 target — one lock/PBX vendor **or** one channel hub/OTA; (c) settle whether legacy/Exceed data migration is in scope before any production data entry.

---

*Generated during the pre-development audit. Supersedes nothing; complements `docs/MODULES.md` and `docs/FEATURE-GAP-AUDIT.md` once those files are committed to the repo.*
