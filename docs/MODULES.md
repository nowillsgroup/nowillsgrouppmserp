# Nowills PMS — complete module list

Extracted from `config/pms.php` → `menu`, the registry the sidebar renders
(`resources/views/partials/sidebar-nav.blade.php`). **Version 7.54.0** — the value of
`config('pms.version')` — the asset cache-buster and the stamp in the sidebar. It moves with
the release notes in `deploy/dev/serve.mjs`, and `deploy/dev/audit/modules_doc_check.py` fails if the two disagree.

> Every entry carries a `perm` key checked by `RequirePermission` middleware, so what a
> given user sees depends on their role. Some entries also carry a `badge` for live counts.

| | |
|---|---|
| **5** | sections |
| **12** | top-level groups |
| **53** | modules |
| **361** | pages |

Counted from the live `config('pms.menu')` registry and reconciled against it page for page on
14 Oct 2026: a page is any node below module level that carries a `route`; a module that is itself a
link (Dashboard, Group Dashboard, Night Audit) counts as a module, not a page. Until this pass the
per-module lists were the historical v7.36–v7.38 inventory and named four things the sidebar does not
menu; **every count, name and order below is now the live menu**, and each group heading carries its
own page count. A page that is routed but not menued (Desktop Sync), a document that is routed per
record rather than menued (a ticket sale receipt), and a module that is routed but absent from the
sidebar altogether (Car Rental) are named in prose under the group or module they belong to and are
*not* counted, because the sidebar is what this document inventories.

`deploy/dev/audit/modules_doc_check.py` diffs this file against the live registry and the route list —
counts, names, order, section keys, permission keys and every URI the prose names — and reports
0 discrepancies. Run it after any change to `config/pms.php`.

---


## Dashboard & Operations

`DASHBOARD` section · **4 modules** · 4 menu pages

- **Dashboard** — direct link
- **Group Dashboard** — direct link
- **Night Audit** — direct link
- **Reports** — 4 pages
  - All Reports · My Reports & Saved Views · Scheduled Reports · KPI Alerts

## Front Office & Guest Services

`OPERATIONS` section · **10 modules** · 63 menu pages

- **Front Office** — 8 pages
  - Room View · Tape Chart · Walk-in Check-in · Arrivals · In-House Guests · Departures · Guest Requests · Offline Request Capture
  - Routed but not menued: Desktop Sync, `/offline/desktop` (workspace, delta, pack, sync) — a real, working page, reachable by URL and from Offline Request Capture, that the sidebar does not list. Verified end to end in `docs/FRONT-OFFICE-AUDIT.md` (370 named routes, 228 of them writes).
- **Desk Operations** — 7 pages
  - Shift Log Book · Shift Hand-over · Wake-up Calls · Messages & Parcels · Traces · Lost & Found · Desk Settings
- **Reservations** — 8 pages
  - Reservation Workspace · New Reservation · Reservation List · Availability · Group Blocks · Rate Calendar · Channel Manager · iCal Feeds
- **Central Reservations** — 6 pages
  - CRO Desk · Enquiries · Guest Lookup · Lost Business · CRO Performance · CRO Settings
- **Concierge** — 6 pages
  - Concierge Desk · Activity Bookings · Activities & Vendors · Local Guide · Vendor Settlements · Concierge Settings
- **Guest Messaging** — 6 pages
  - Inbox · Messaging Dashboard · Broadcasts · Quick Replies · Routing Rules · Messaging Settings
- **Self-Service Kiosk** — 4 pages
  - Kiosk Dashboard · Kiosks · Guest Visits · Kiosk Settings
- **IPTV & Guest Welcome** — 9 pages
  - TV Dashboard · TVs & Screens · Channels & Guide · Movies & Rentals · Welcome Screens · Information Pages · TV Messages · Usage Analytics · IPTV Settings
- **Foreign Guests** — 6 pages
  - Form C (FRRO) · Currency Exchange · Exchange Rates · Currency Position · Currencies · Foreign Guest Settings
- **Interfaces** — 3 pages
  - Call Accounting · Key Cards · Interface Hub

## Guest Relations & Commercial

`OPERATIONS` section · **7 modules** · 49 menu pages

- **Guests & CRM** — 12 pages
  - Guest Profiles · Companies / Agents · Rate Contracts · Feedback & Reputation · Guest Cases · Loyalty Program · Privacy & Data · Marketing Dashboard · Campaigns · Guest Segments · Automations · Promo Codes
- **Sales & Marketing** — 9 pages
  - Sales Dashboard · Accounts · Opportunities · Sales Diary · Sales Targets · Marketing Calendar · Marketing Budget · Collateral Library · Sales Settings
- **Digital Marketing** — 7 pages
  - Digital Dashboard · Content Calendar · Paid Campaigns · Tracked Links · Channels & Listings · Influencers · Digital Settings
- **Business Development** — 6 pages
  - BD Dashboard · Competitive Set · Market Mix · Partnerships · Initiatives · BD Settings
- **Gift Vouchers** — 4 pages
  - Vouchers · Sell Voucher · Check / Redeem · Voucher Types & Campaigns
- **Partner Portal** — 5 pages
  - Partner Dashboard · Requests & Approvals · Partner Accounts · Portal Activity · Portal Settings
- **Organiser Portal** — 6 pages
  - Organiser Dashboard · Requests & Approvals · Portal Events · Organiser Logins · Portal Activity · Portal Settings

## Revenue & Financial Management

`FINANCE` section · **7 modules** · 52 menu pages

- **Revenue** — 4 pages
  - Revenue Dashboard · Pricing Grid · Pricing Rules · Upselling
- **Billing** — 6 pages
  - Folios · Non-Resident Bill · Invoices · GRA VAT & E-VAT · Online Payments · Gateway Payouts
- **Cashiering** — 2 pages
  - Cashier Dashboard · Shift Register
- **Income Audit** — 4 pages
  - Audit Days · Authorisations · Room Count · Room Discrepancies
- **Accounts** — 24 pages
  - Vouchers · City Ledger · Credit Control · Agent Commissions · Supplier Bills (AP) · Supplier Payments · Withholding Tax (GRA) · AP Ageing · Cash Book · Bank Reconciliation (imports CSV, Excel .xlsx, SWIFT MT940 and ISO 20022 camt.052/053 — v7.48, see [`BANK-STATEMENT-FORMATS.md`](BANK-STATEMENT-FORMATS.md)) · Petty Cash · Expense Claims · Profit & Loss (operational) · General Ledger · Budgets · Budget vs Actual · Journal Entries · Period Close · Accruals & Prepayments · Recurring Journals · Year-end Close · Balance Sheet · Chart of Accounts · GL Mapping
- **Treasury** — 6 pages
  - Cash Position · Banking & Transfers · Cheque Registers · 13-week Cash Forecast · Cash-flow Statement · Bank Accounts
- **Owner Reporting** — 6 pages
  - Owner Dashboard · USALI Statement · Monthly Owner Packs · Management Fees · FF&E Reserve & Capex · Agreement & Mapping

- Routed but not menued: **Car Rental** (`OPERATIONS`) — `/rentals` (Rental Desk) and `/rentals/fleet` (Rental Fleet), with writes for a vehicle, pickup, return, billing and cancellation. Both pages render and both are permissioned; neither is in the sidebar, so neither is counted in the 7 modules or the 52 pages above.

## Housekeeping & Laundry

`OPERATIONS` section · **2 modules** · 9 menu pages

- **Housekeeping** — 5 pages
  - Room Status · HK Tasks & Inspection · My Rooms (mobile) · Minibar Items · Maintenance
- **Laundry & Linen** — 4 pages
  - Laundry Orders · Laundry Price List · Linen Stock & Par · Linen Batches

## Food & Beverage

`OPERATIONS` section · **6 modules** · 57 menu pages

- **POS / Restaurant** — 14 pages
  - POS Terminal · Orders & Bills · Floor Plan & Waitlist · Table Reservations · Kitchen Display · KOT Printers · Print Station · Menu Items · Menu Categories · Outlets · Tables · Table QR Codes · Modifiers & Add-ons · Reservation Settings
- **Packages & Meals** — 4 pages
  - Package Dashboard · Meal Desk · Entitlements · Catalogue & Inclusions
- **Banquet** — 7 pages
  - Events & Enquiries · Function Diary · Function Sheet (BEO) · Venues · Menu Packages · Services & Equipment · Banquet Settings
- **Food Safety** — 7 pages
  - Food Safety Dashboard · Temperature Logs · HACCP Checklists · Allergen Matrix · Food Waste Log · Prep Labels · Food Handler Certificates
- **F&B Cost Control** — 9 pages
  - Cost Dashboard · Recipe Cards & Plate Cost · Menu Engineering · Food & Beverage Cost · Usage Variance · Menu Pricing · Yield Tests · Staff Meals · Cost Settings
- **Inventory** — 16 pages
  - Stock Summary · Requisitions · Budgets & Par Levels · Purchase Orders · RFQs & Quotations · Goods Receipts (GRN) · Supplier Price Lists · Supplier Scorecard · Stock Issue / Adjust · Stock Ledger · Stock Take (Physical Count) · Re-order Suggestions · Recipes (F&B costing) · Items · Suppliers · Stores

## Accommodation & Property

`OPERATIONS` section · **3 modules** · 16 menu pages

- **Apartments & Leases** — 5 pages
  - Leasing Dashboard · Leases · Units · Billing & Arrears · Meter Readings
- **Fixed Assets** — 4 pages
  - Asset Register · Depreciation · Asset Categories · PM Schedules
- **Parking & Valet** — 7 pages
  - Parking Desk · Valet Board · Tickets · Vehicles · Permits · Zones · Tariff & Settings

## Facilities & Security

`OPERATIONS` section · **3 modules** · 16 menu pages

- **Engineering** — 3 pages
  - Utilities & Energy · Daily Meter Readings · Licences & Compliance
- **Security** — 4 pages
  - Visitors · Gate Passes · Incident Register · Watch List
- **Transport** — 9 pages
  - Dispatch Board · Trips · My Trips (driver) · Fleet & Papers · Vehicle Expenses · Vehicles · Drivers · Tariff Card · Transport Settings

## Wellness & Recreation

`OPERATIONS` section · **3 modules** · 13 menu pages

- **Health Club** — 7 pages
  - Club Dashboard · Check-in Desk · Members · Billing & Renewals · Class Timetable · Day Passes · Plans & Settings
- **Recreation Outlet** — 3 pages · v7.38
  - Ticket Outlet · Ticket Sales · Ticket Types
  - POS ticketing for walk-ins; club memberships are untouched by it. Each sale prints its own receipt at `/recreation/sales/{sale}/receipt`, which is a document per record rather than a menu page.
- **Spa & Activities** — 3 pages
  - Appointments · Services & Activities · Treatment Rooms

## HR & Workforce

`PEOPLE` section · **3 modules** · 36 menu pages

- **Talent Management** — 9 pages
  - Talent Dashboard · Vacancies · Applicants · New Starters · Appraisals · Discipline & Grievances · Exits & Turnover · Establishment · Talent Settings
- **HR & Payroll** — 20 pages · v7.43 reconciled (was listed as 23)
  - Employees · Attendance · Monthly Register · **Public Holidays** (v7.43, Act 601 / Act 1142 calendar) · Leave Requests · Duty Roster · Time Clock · Time Clock Kiosk · Service Charge & Tips · Payroll Runs · Salary Advances · Statutory Returns · Pay Components · Leave Types · Departments · My Payslips & Leave · My Roster & Time Clock · My Training & SOPs · My Appraisal · My Expense Claims
  - Routed but not in the sidebar: My Profile (`/me/profile`), My Documents (`/me/documents`), My Announcements (`/me/announcements`) and the HR Self-Service Desk (`/hr/ess`) — all four render; v7.43 stopped the first three from returning a bare 404 for a login that is not linked to an employee record.
- **Learning & SOPs** — 7 pages
  - L&D Dashboard · Training Matrix · Training Sessions · Training Records · Courses & Requirements · SOP Library · L&D Settings

## Quality, Safety & Sustainability

`GOVERNANCE` section · **3 modules** · 27 menu pages

- **Quality Assurance** — 6 pages
  - QA Dashboard · Audits · Corrective Actions · Standards · Checklists · QA Settings
- **Health & Safety** — 8 pages
  - HSE Dashboard · Fire Equipment · Inspection Round · Permits to Work · Accidents & Near Misses · Risk Assessments · Drills & Wardens · HSE Settings
- **Sustainability & ESG** — 13 pages
  - ESG Dashboard · Carbon & HCMI · Waste · Refrigerants (F-gas) · Green Stay · Targets · Initiatives · People & Community · Certification · Policies · Carbon Statements · Annual ESG Report · ESG Settings

## Administration & Configuration

`GOVERNANCE` section · **2 modules** · 19 menu pages

- **Masters** — 8 pages
  - Room Types · Rooms · Floors · Rate Plans · Taxes · Business Sources · Payment Modes · Item Categories
- **Administration** — 11 pages
  - Users · Roles & Permissions · Hotel Settings · Activity Log · E-mail Log · SMS / WhatsApp Settings · Online Payment Settings · SMS / WhatsApp Log · API & Webhooks · Database Backup · Data Migration
