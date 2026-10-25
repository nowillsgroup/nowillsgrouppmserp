# Nowills PMS — Hotel Property Management System

A complete, multi-property hotel PMS built with **PHP 8.3+ / Laravel 13 / MySQL (or MariaDB)**.
**No Node.js, no Docker, no build step** — plain Blade templates, one CSS file and one vanilla-JS file.
It runs on any shared host with PHP and MySQL.

It follows the module layout of the Exceed-based Nowills ERP (Hotel ID / Group ID login, front office,
POS, banquet, inventory, accounts, night audit, reports). The original system is behind a login, so this
is an original implementation built from its public login page and published feature lists.

---

## Offline-first operations

The PMS now includes an offline-ready housekeeping workspace at **Housekeeping → Offline workspace**. A staff device must open it once while online so the service worker can cache the workspace and the latest room/task snapshot. Room housekeeping statuses (clean, dirty, inspected and touch-up), assigned task actions (start, DND and complete with checklist), supervisor inspections, and maintenance issue reports can then be recorded without a connection; changes are stored in the browser's IndexedDB outbox and replayed automatically when the connection returns.

Every queued change has a unique operation id and a server-side receipt. Replays are idempotent, and a room changed by another user while the device was offline is reported as a conflict instead of being silently overwritten. This first slice deliberately does not queue reservations, check-in / check-out, POS, folio postings or payments; those require module-specific conflict and fiscal controls before they can be safely used offline.

The guest-request slice provides a tenant-scoped, permission-checked capture screen at `offline/guest-requests` with a snapshot at `offline/guest-requests/snapshot`. It queues `guest_request.create` through the authenticated offline outbox, shows queued and synced requests, and reports rejected or stale stays without silently losing the capture. The snapshot contains only checked-in stay identifiers, room numbers, open request status, and request-category choices; guest names and other guest PII are intentionally excluded. Creation reuses the online request service, requires `requests.manage`, accepts only currently checked-in stays, and records an idempotent server receipt before acknowledging the browser.

---

## Modules

| Area | Features |
|---|---|
| **Login & security** | Hotel ID login, **Group Login** (group admin switches between properties), lockout after 5 failed logins, roles & granular permissions, activity log, CSP/security headers, CSRF, throttled public endpoints |
| **Dashboard** | Occupancy, arrivals/departures, in-house, revenue/collections/ADR, 14-day revenue chart, room-status donut, quick actions |
| **Group dashboard** *(v1.1)* | For group admins only: every property side by side (occupancy, in-house, arrivals/departures, revenue today/MTD, ADR, OOO/dirty/open-POS alerts), group totals, 14-day consolidated revenue, one-click switch into a property |
| **Front Office** | Room view (grid by floor, with context actions), tape chart with **drag & drop** (drop a booking or an unassigned reservation onto another room row; in-house guests go through the audited room move, and conflicts are rejected), walk-in check-in, arrivals/in-house/departures lists, check-in (ID capture, advance), check-out with settlement (payment / bill to company / refund), room move, amend stay, GRC (registration card) print |
| **Reservations** | Individual & group bookings with multiple rooms, rate plans (EP/CP/MAP/AP), extra adults, advances, room assignment, confirm / cancel / no-show, availability matrix, confirmation voucher print |
| **Rate management** *(v1.1)* | Rate calendar (room types × up to 60 days), bulk seasonal rates by date range, Fri/Sat weekend rates, minimum stay, stop-sell. Rates feed the reservation form, walk-in and booking engine automatically. Where rules overlap, the newest rule that sets a price wins, and restrictions combine |
| **Revenue management** *(v1.9)* | Demand dashboard (on the books, group holds, forecast, pickup vs yesterday / last week, pickup by source), pricing grid with rule-based recommendations to publish or accept, dynamic-pricing rules (occupancy, lead time, weekday, dates), rate floors & ceilings, fixed-price protection, and an **auto-pilot** mode that prices every channel live. |
| **Channel manager (iCal)** *(v1.2)* | One channel per OTA listing / room type (Airbnb, Booking.com, Vrbo, Expedia…). **Export**: a secret feed at `/ical/{token}.ics` closes the dates when the room type is sold out or stop-sold. **Import**: the OTA's calendar URL is fetched every 15 minutes (or with *Sync now*). New events become reservations, changed dates are followed and removed/cancelled events are cancelled, matched idempotently by the event UID. Nothing is ever overbooked: clashes are reported as conflicts. Includes a private-address (SSRF) guard, loop protection and a last-sync status |
| **GRA VAT & E-VAT** *(v3.0, Ghana since v4.3)* | Ghana TIN validation (`C/P/G/Q/V` + 10 digits, or a Ghana Card PIN `GHA-XXXXXXXXX-X`); every invoice keeps its date and a frozen copy of the buyer (company or guest TIN, address), B2B or B2C; **VAT 15 % + NHIL 2.5 % + GETFund 2.5 %** (VAT Act 2025, Act 1151) plus the **1 % Tourism Levy** (Act 817), itemised on every document; **GRA E-VAT** clearance through a Virtual SDC (or a built-in sandbox): SDC ID, receipt number, internal data, signature and QR printed on the invoice, with automatic retries; **credit notes** as E-VAT refunds or company-account credits; the monthly **VAT return** (sales register, levy summary, documents issued, pre-filing checks, CSV export, filed months locked) and the Tourism Levy return to the GTA. |
| **Feedback & reputation** *(v3.1)* | **Post-stay surveys** scheduled at check-out and sent by e-mail / SMS after a delay, with one reminder, expiry, a per-guest cooldown and recorded skip reasons; a mobile survey page (NPS 0-10, star ratings per department, yes/no, choices, comments, publish consent); promoters are invited to review on Google / TripAdvisor; comments analysed for sentiment and complaint areas; **service-recovery cases** opened automatically for detractors, low ratings, safety words and bad reviews, routed to department owners with an SLA, contact log, goodwill points, outcome and escalation; an **online review inbox** (CSV import or manual, de-duplicated, 10-point scales normalised, matched to the guest's stay) with templates and four-eyes approval of responses to critical reviews; NPS / CSAT / online-rating dashboard. |
| **Guest transport** *(v3.3)* | Airport / station **pick-ups, drops and local transfers** with a tariff card (per-km beyond the included km), a per-vehicle **dispatch board** with busy windows and clash detection (turnaround buffer, supervised override), blockers for workshop cars, expired insurance / permit / fitness / PUC, expired licences and too few seats; SMS / WhatsApp to the guest (driver, car) and the driver (trip sheet), reminders; room-bill / direct / complimentary billing with the check-out guard; a driver phone screen with name board, odometer and fuel log; guest-portal booking; printable trip sheet; running costs; utilisation, driver and revenue reports. |
| **Foreign guests: arrivals register & forex** *(v3.4)* | A **foreign-guest arrival register** (originally India's Form C; switched off by default for a Ghana property, enable it under Foreign Guests › Settings if your immigration or police returns need it): reports opened when a non-Ghanaian guest checks in, with deadline, completeness check, companions and departures. A **forex desk** that buys currency from foreign guests at the day's board rate in **cedis**: configurable cash limits (USD 3,000, 30-day aggregation, ID copy above GH₵ 5,000), **no VAT** (financial services are exempt), bill settlement in foreign currency, same-day voids, numbered **encashment certificates**, the currency position (FIFO age) and **surrender to the bank** with realised gain / loss, all posted to the ledger. |
| **Front desk operations** *(v3.5)* | A shift **log book** with read receipts, follow-ups, pins, comments and resolution, plus a printable **shift hand-over**. **Guest messages & parcels** with message slips, SMS / WhatsApp / call notification, delivery with the collector's name, and the guest-portal inbox. A **wake-up call** queue with clash checks, retries, escalation after no answer, daily series and portal booking. Dated **traces** that carry forward at night audit. An upgraded **lost & found**: numbered and categorised items, valuables in the safe, retention periods, the probable owner, supervised release against photo ID, witnessed disposal, and loss reports matched to found items. |
| **Open REST API & webhooks** *(v4.0)* | A versioned per-property **REST API** (`/api/v1`) with scoped, hashed API keys (IP allow-list, rate limit, expiry, rotate / revoke), availability, rates, **idempotent bookings** and cancellations, folios, guest lookup, rooms and housekeeping updates, daily statistics and an OpenAPI 3 spec. **HMAC-signed webhooks** for 8 events with retries, auto-disable, delivery inspection and a full request log. |
| **Upselling & pre-arrival offers** *(v4.1)* | An offer catalogue of **room upgrades** (fixed price or a % of the rate difference), **add-ons** (per stay / night / person), **early check-in** and **late check-out**, with channels, room types, sources, minimum nights, lead time, daily limits and validity. Personalised offers are **e-mailed before arrival** with a private offers page, shown on the **check-in screen** and in the **guest portal**. Confirmed orders become fixed charges on the stay (upgrades switch the room type), early / late requests are confirmed or declined by the desk, desk sales earn **staff incentives**, and a conversion funnel and three reports measure it all. |
| **Security & gate management** *(v4.2)* | A **visitor register** with printable badges (QR), visits to in-house guests confirmed by phone before the visitor goes up, **guest privacy** ("no visitors" / "call me first", set by the desk or by the guest in the portal), **visiting hours**, pass validity with **overstay alerts**, and **pre-registration by the guest** from the stay portal with a shareable pass link. A **watch list** (barred / alert) matched on phone, a keyed hash of the ID number or the name, with manager overrides that are logged. ID numbers are never stored: only the type, last 4 and a hash, anonymised after the retention period. **Material gate passes** (returnable / non-returnable) with segregated approval, the gate check-out, partial returns, overdue chasing and fixed-asset status (*under repair* while away). An **incident register** with severity escalation to the log book and an SMS to the duty manager, police details, follow-up notes and a closing resolution. Four reports. |
| **Engineering: utilities & compliance** *(v3.9)* | Daily **meter readings** (CT multiplier, register roll-over, meter replacement, estimates) for electricity, DG, solar, water, LPG, PNG and diesel; usage **anomaly flags**; **energy KPIs** per occupied room and guest night; a **carbon footprint** (Scope 1 & 2, CEA factor); utility **bills reconciled** against the meters and booked to AP. A **licences & statutory compliance register** (26 standard items) with renewals, inspections, documents, reminder e-mails, a renewal calendar and night-audit warnings. |
| **Gift vouchers & stored-value cards** *(v3.8)* | Sell open-amount gift cards, fixed-value experience vouchers (single use) and reloadable stored-value cards with GV-XXXX-XXXX codes. Vouchers are e-mailed with a public voucher page and QR code. They are redeemed on room folios (payment mode *Gift Voucher*, reference = the code) and on POS bills, in part or in full; refunds and voids go back on the voucher. Also covers promotional campaigns, suspend / cancel & refund, expiry breakage, reminders, GL liability (2120) and 5 reports |
| **Hotel system interfaces** *(v3.7, live PABX driver v7.36)* | **PABX call accounting**: SMDR import, a live push API or a **live TCP SMDR capture** (`pms:pbx:listen`), longest-prefix tariffs with pulses, markup and minimum charges, free and emergency numbers, the guest's calls posted to the right folio automatically (room moves and late records handled), a review queue, department costing for admin extensions, and phone class of service set at check-in. **PMS → PABX commands** (check-in, check-out, class of service, room move) go out through a **vendor-neutral TCP command driver** with configurable templates and reply matching — see `docs/PBX-ADAPTER.md`. **Door-lock key cards**: new, duplicate and replacement cards; validity to check-out time plus grace; lost cards with a fee; read a card; cards re-validated on extension, re-encoded on a room move and expired at check-out. A **master-key register** with overdue alerts and lost-key security incidents. An **interface hub** with the PMS → device command log and retry. |
| **Budgeting & forecasting** *(v3.6)* | Annual **budgets** per income / expense account and month, built blank, from last year's actuals, from the run-rate or by copying a version with growth %. A spreadsheet-style **grid** with live totals, GOP and net profit; spread and % adjustments; rooms **statistics** (occupancy × ADR) that drive room revenue; CSV import and export. **Four-eyes approval** with one active version per year. **Re-forecasts** whose closed months are locked to ledger actuals. **Budget vs actual** from the general ledger: a pro-rated current month, a go-live date, favourable / adverse variances and variance commentary. **Spend control** on supplier expense bills (warn, or block with an override and a reason). |
| **Restaurant reservations** *(v3.2)* | **Table bookings** by phone, front desk or the public page `/dine/{hotel}`, with service periods, slot intervals, dining time by party size, best-fit table allocation (joining neighbouring tables for big groups) and no double booking; a **live floor plan** (drag-and-drop layout, table states free / reserved / late / seated / billed / to clear / blocked, joined tables, bill so far, next booking) where the host seats bookings, walk-ins and **waitlist** parties (quoted wait, "table ready" SMS) straight into a POS order; the bill closes the party and flags the table for clearing; confirmations, reminders, guest manage / cancel link, automatic no-shows with a repeat-offender flag; day sheet with timeline; covers, no-shows, turns and waitlist reports. |
| **Guest data privacy & compliance** *(v2.9)* | DPDP Act 2023 / GDPR: data-principal requests (access, correction, erasure, consent withdrawal, grievance) with statutory deadlines and one extension; identity verification; JSON / printable access exports; erasure by anonymisation with **legal holds** (tax invoices, police / Form-C register) and restricted profiles; nightly retention policies with dry run; masked ID / passport / DOB with logged reveals; public privacy page with e-mail confirmation; personal-data breach register with the 72-hour notification clock. |
| **Channel manager (two-way)** *(v2.8)* | Per-OTA connections with room / rate mapping, max-sell, markup, commission and payment model. Delta ARI pushes (availability, rates, stop-sell / min-stay / CTA), signed with HMAC and sent every minute, with retry back-off and a sync log. A signed booking webhook handles new / modify / cancel idempotently. Overbookings are accepted and flagged for resolution; unmapped messages go to an inbox and are reprocessed after mapping. Also: rate parity grid, sandbox test channel, and 3 reports |
| **Cashiering** *(v1.2)* | Dedicated **Finance › Cashiering** workspace: open / close a cashier shift with a starting float, receive folio and POS payments by mode, record controlled cash-in / cash-out drawer movements with reasons and references, count notes and coins at close, reconcile expected cash to counted cash with a permanent variance snapshot, print the shift handover report, and let Accounts review all cashiers. Optional setting: payments are refused until a shift is open. |
| **Guest e-mails** *(v1.2)* | Booking confirmation/cancellation (sent automatically from the reservation form and booking engine; can be switched off) and invoice / folio statement e-mails. Each send is logged as sent or failed in **Administration › E-mail Log**, with template previews. A mail failure never blocks a booking. Uses Laravel Mail (`log` in the demo, SMTP in production) |
| **Billing / Folio** | Guest folios in **GH₵**, charges by category with VAT / NHIL / GETFund / Tourism Levy, discounts, payments/refunds, voids with a reason (never deleted), city-ledger transfer, **split / route lines** *(v1.2)* to another open folio or a new split folio (with a reason, logged on both folios), non-resident bills, VAT invoice print with the levy summary and E-VAT stamp, invoice register; **Billing Reconciliation** snapshots issued folio invoices/credit notes against folio receipts/refunds, keeps company-ledger transfers separate, records manual settlement evidence, and uses four-eyes finalisation. This is a non-posting workpaper; direct POS, company-AR allocation, and gateway/bank batch attribution are out of scope (`docs/BILLING-RECONCILIATION.md`) |
| **Guests & CRM** | Guest profiles with stay history, VIP/blacklist flags, **loyalty programme** *(v1.1)*: points earned automatically when a folio is settled, redeemed as a folio discount (voiding it refunds the points), manual adjustments, tiers and a full points ledger, companies/travel agents with credit limits, public guest-feedback form and feedback dashboard |
| **Booking engine** | Public page `/book/{HOTELID}`: live availability, online booking, cancellation policy, honeypot + throttling |
| **Housekeeping** | Room status board (bulk clean/dirty/inspected/OOO), HK tasks (auto-generate, assign, track), maintenance tickets (can block rooms); found items logged by attendants go to the lost & found register (Desk Operations, v3.5) |
| **POS / Restaurant** | Multiple outlets, table plan, touch terminal, KOTs (with auto-print), item cancellation, bills with discount / service charge / tax, settle by payment, **post to room**, or complimentary, room service, bill & KOT thermal prints. **QR table menu** *(v1.1)*: guests scan the table QR code, browse a mobile menu and order. Orders reach the POS as *pending*, with no KOT and no charge until staff click **Accept & send KOT**. Guests see live status. Includes a printable QR sheet, per-table code regeneration and an on/off setting |
| **Contracts & Credit Control** *(v2.7)* | **Corporate / travel-agent rate contracts**: net rates per room type (single / double / extra adult, seasons) or % off the public rate, meal plan and inclusions, **blackout dates**, **last-room availability**, **allotments** with release days, booking-engine **corporate codes**, contract cancellation terms and commission; drafted by sales, **approved by someone else**, suspend / renew with uplift, printable agreement letter, production vs room-night target. **Credit control**: exposure (city ledger + in-house) vs limit, FIFO ageing against credit days, manual and **automatic credit holds**, override with reason at check-out / folio transfer, terms log, e-mailed statements |
| **Guest Marketing & CRM** *(v2.6)* | **Rule-based guest segments** (stays, nights, lifetime revenue, days since last stay, loyalty, VIP, company, upcoming booking, country / city, tags, birthday / anniversary month; all / any) with a live count; **e-mail / SMS / WhatsApp campaigns** with personalisation tokens, test sends, scheduling and per-recipient delivery (consent, blacklist, address and a frequency cap enforced); **automated journeys** (birthday, pre-arrival, post-stay, win-back, anniversary) that contact each guest once per occasion; **promo codes** for the booking engine; tracked links with **booking attribution** (click / promo / influenced) and campaign ROI; per-channel **consent** with an audit trail, a guest preferences / unsubscribe page and RFC 8058 one-click unsubscribe; RFM guest value |
| **Banquet & Conference Sales** *(v2.5)* | Enquiry → tentative (option date, auto-release) → definite (deposit) pipeline; **multi-function events** across venues with set-up-specific capacity, menu packages and priced services / equipment; venue clash detection with turnaround; guarantees with a cut-off; deposits and refunds on the event folio; cancellation scale; **function diary**, BEO, proposal / contract and daily function sheet; billing on guarantee-or-actual |
| **Inventory** | Stores, items, stock summary with reorder alerts, purchase orders → approve → goods receipt (updates stock & cost), issue / transfer / adjustment / wastage, stock ledger. **Recipes / F&B costing** *(v1.2)*: ingredients per menu item, portion cost and food-cost % against a target; settling a POS bill deducts the ingredients from the outlet's store as `consumption` movements (these appear in the ledger and the consumption report) |
| **Accounts** | Payment/receipt vouchers, city ledger (company receivables, receipts, statement), daily cash book by payment mode, operational profit & loss |
| **Workforce: rosters, time clock, service charge** *(v4.9)* | Shift templates (overnight aware) and a weekly **duty roster** grid per department with coverage counts, working-time warnings (rest between shifts, consecutive days, 40 h week – Labour Act 651), copy last week, publish / changed-after-publishing and a print sheet; **shift swaps** colleague → manager; a **time clock** from self-service (optional IP allow-list) or a shared **kiosk** with employee code + PIN (lockout after 5 wrong PINs); late / early / break maths, overnight shifts, manager corrections with a reason, a live day board (on duty, late, no-show, missed clock-out) and **day approval that writes attendance** for payroll; the **service charge & tips pool (tronc)**: tips register, house retention, points × days sharing, exclusions, second-person approval, paid as a taxable earning through the draft payroll run, with ledger postings |
| **Income audit & revenue integrity** *(v5.3)* | Every closed business date is audited: automated checks (missing / duplicate tariffs, comps, rate variance against plan / contract / block / rack, discounts, voids, cashier variance, open bills, unsettled departures, credit limits, E-VAT, room-count discrepancies, figures changed after close), exceptions explained / corrected / escalated, auditor sign-off and controller close (four-eyes), closed days locked against voids; comp / house-use / rate / discount **authorisations** with authority limits; blind housekeeping **room count**; revenue reconciliation and a printable audit report |
| **Serviced apartments & leases** *(v5.2)* | Lettable **units** (standalone or linked to a hotel room, which then leaves nightly sale), long-stay **leases** with draft → signed → move-in → notice → move-out → deposit settlement, Ghana **tax classes** (serviced 21 % incl. tourism levy / residential VAT-exempt / commercial 20 %), **Rent Act 1963 s.25(5)** advance-rent cap, monthly-to-yearly invoicing billed ahead by the night audit, rent reviews, pro-rating, company **WHT** on receipts, security deposits, metered utility recharges, inspections with damage charges, late fees, arrears ageing, printable agreement / invoice / receipt / statement / settlement, rent roll |
| **Health club & memberships** *(v5.1)* | Membership **plans** (individual / couple / family / student / corporate / off-peak; term, price, joining fee, persons, facilities, access hours, free guest passes, F&B and spa discounts). **Enrolment** with card holders, PAR-Q health declarations, photos and printable CR80 cards with QR codes. A **members' account** holds charges, receipts, credit notes and refunds, settled oldest-due first. **Deferred membership revenue** is released day by day. **Auto-renewals** and expiry run in the night audit, alongside freezes and cancellations (end-of-term notice, or immediate with pro-rata credit). The **check-in desk** handles members, in-house residents, day passes and members' guests, with capacity per facility, off-peak hours, arrears blocking and manager override. **Class timetable** with bookings, waitlist, register and no-show rules. **POS member discount**. |
| **Food safety & HACCP** *(v5.0)* | The 14 regulated **allergens** on ingredients and dishes: recipe ingredients flag undeclared allergens, a chef verifies the declarations, and there's a printable allergen matrix per outlet. **Guest allergies** on POS and QR orders: conflicting dishes are highlighted and need an explicit confirmation, then are flagged on the KOT and the kitchen display. **Temperature logs** for fridges, freezers and hot-holding, plus deliveries, cooking, reheating and 2-stage cooling, with critical limits. An out-of-range reading needs a corrective action and a manager review. **HACCP checklists** (daily / weekly / monthly) with failed items and a manager sign-off. A **food waste log** posts to stock and to Food Waste 5042. **Food handler certificates** give roster warnings. **Prep / use-by labels**, a compliance score and a printable inspection pack |
| **Procurement II** *(v4.8)* | Supplier **price lists** (rate contracts with validity, minimum qty, lead time) that pre-fill POs and flag lines priced above the agreed rate; a **3-level PO approval matrix** by value; **RFQs** sent to several suppliers, each with a private quotation link (no login) or keyed-in quotes; a **comparative statement** with the lowest bid (L1) highlighted and landed cost, split award with justification → draft POs; **partial goods receipt notes** with rejections, over-delivery tolerance and short-close; bills and the GL per GRN; a **supplier scorecard** (on-time, fill rate, quality, price compliance, responsiveness → grade A–D) |
| **Petty cash & expense claims** *(v4.7)* | Imprest floats with their own ledger account and custodian, vouchers with receipt photo / input VAT / approval over a limit, IOUs with settlement (change back or overspend), cash counts by cedi denomination with shortage / overage write-off, top-ups restoring the imprest with segregation of duties; staff expense claims from self-service (mileage, meals, transport, airtime …) → approve / send back → pay by bank, MoMo, cash or petty cash |
| **Bank reconciliation** *(v4.6)* | Bank / Mobile Money / card statements (CSV import with column detection or typed in), auto-match by reference, amount and daily settlement batch, one-to-many manual matching, bank charges / MoMo fees / interest booked from the statement, ignore with reason, reconciliation statement (BRS) with nil-difference finalise, reopen, overdue alerts |
| **General ledger** *(v1.6)* | Double-entry books derived automatically from front office, POS, vouchers, goods receipts and stores: chart of accounts, trial balance with control-account reconciliation, account ledgers, manual journals with reversal, period lock, GL mapping, balance sheet, P&L and journal register |
| **Guest mobile portal** *(v1.7)* | A private link and QR code for each stay, with no app and no password. Guests can request housekeeping, amenities, repairs, wake-up calls, transport and late check-out, and follow the status of each request. They can also order in-room dining (it goes to the POS as a pending order), book spa slots (a free therapist and room are assigned automatically), see their live bill, request express check-out and leave feedback. Staff work from a **Guest Requests** queue that flags requests past the SLA |
| **HR & Payroll** *(v2.0)* | Departments, employees, daily attendance (with in/out times and overtime), a monthly attendance register, leave types with yearly entitlement and carry-forward, leave requests with approval, monthly payroll runs (earnings, statutory deductions, progressive income tax, employer contributions, overtime, loss of pay, salary-advance recovery), maker-checker approval, bank advice, printable payslips with the amount in words, ledger postings, and staff self-service (my payslips, leave balance, apply for leave). |
| **Online Payments** *(v2.1)* | Payment links for deposits and bill balances, sent by e-mail / SMS / WhatsApp or shown as a QR code; hosted checkout through **Paystack** (cards & mobile money – Ghana, Nigeria, Kenya, South Africa), **Stripe** or **Razorpay**, plus a built-in test gateway; signed webhooks, exactly-once posting to the folio, review of mismatched payments, gateway refunds, booking-engine deposits with room holds, pay-your-bill in the guest portal, gateway payouts and fees in the general ledger. |
| **Laundry & Linen** *(v2.2)* | Guest and walk-in **laundry orders** from pick-up to delivery. Items are priced from a configurable price list (wash & iron / dry clean / press), with an express surcharge and turnaround times, a printable bag docket and overdue tracking. Delivery posts one charge to the room folio, or produces a paid walk-in invoice. Guest-portal pick-up requests link to their order, and check-out warns about open orders. **Linen control** tracks stock by location (linen room, floors, soiled, at the laundry) against par levels, with movements, physical counts and write-offs at cost. **Laundry batches** go out to an external or in-house laundry and come back in part, damaged or short. Closed batches become supplier bills in payables, posted to *6130 Laundry & Linen*. |
| **Fixed Assets & PM** *(v2.3)* | An **asset register** covering furniture, kitchen & laundry equipment, HVAC/plant, IT, vehicles and building improvements. Each asset records its location (room / area / department), custodian, serial, warranty and AMC, and gets a **QR asset tag**. Assets are acquired as opening balances, paid directly (Dr Fixed assets / Cr cash or bank) or **capitalised from a supplier bill** without double posting. **Monthly depreciation runs** use straight-line or written-down value, with first-month pro-rata and a residual floor. They run in sequence, one journal per month, and only the latest run can be reversed. **Disposals** (sold / scrapped / written off) post the gain or loss to *4980*. **Preventive maintenance** schedules (every n days / weeks / months, with a lead time and checklist) raise maintenance tickets automatically each morning and at night audit. Breakdown tickets are linked to the asset, mark it *under repair* and add up repair costs. |
| **Night Audit** | Pre-checks, no-show marking, overstay extension, room-tariff posting, statistics (occupancy, ADR, RevPAR), business-date roll, audit report |
| **Store Requisitions** *(v2.4)* | Department **indents** with HOD approval (quantities can be cut, never your own), **monthly stores budgets** per department (warn / block, over-budget approval with a reason) and optional auto-approval of small requests. The store issues against them in one or more **issue notes**: a **transfer** to a sub-store (kitchen / bar) posts nothing, while a **direct issue** is charged to the **department's own cost account**. Also covers close short, receipt acknowledgement, printable indents and issue notes, **purchase orders for shortages** from the last supplier, and **sub-store par levels** with a one-click top-up requisition. |
| **Reports (90)** | Form C register, foreign arrivals by nationality, currency exchange register, currency position & surrenders, transfer register, vehicle utilisation, Table booking register, covers & no-shows by day, table turns & utilisation, waitlist performance, NPS & satisfaction trend, guest feedback register, service recovery cases, online review register, department scorecard, E-invoice (IRN) register, credit note register, HSN / SAC summary, Privacy request register, PII access log, retention & erasure log, OTA channel production, channel sync log, rate parity, Contract production, allotment utilisation, credit exposure, Campaign performance, promo code redemptions, guest value (RFM), Banquet event report, banquet sales pipeline, venue utilisation, function forecast, Requisition register, departmental consumption vs budget, outstanding requisition lines, fixed asset register (as at), depreciation schedule (opening / additions / charge / disposals / closing), PM compliance, laundry register, linen stock & par, linen losses & write-offs, online payments, Payroll register, statutory deductions (PF / ESI / tax / professional tax), leave register, revenue forecast & on the books, booking pace (pickup), AP ageing (suppliers), supplier bill register, guest requests & response times, trial balance, balance sheet, GL profit & loss, journal register (day book), flash report (Day/MTD/YTD), AR ageing (city ledger), attendant productivity, minibar sales, spa & activity revenue, arrivals, departures, in-house, reservations, cancellations/no-shows, occupancy, business source, company-wise, nationality, police/Form-C, room status, maintenance, daily revenue, collections, GST, invoice register, voids & discounts, outstanding, POS outlet/item/bill registers, banquet, purchases, consumption, expenses, night-audit MIS, top guests, user activity. **All reports can be printed and exported to CSV/Excel.** |
| **Report automation** *(v5.4)* | Saved report views (relative periods, chosen columns, text filter, shared / pinned to the dashboard), e-mailed report schedules (daily / weekly / monthly / after night audit, HTML table + CSV, owner-permission runs, domain allow-list, auto-pause), delivery log with re-download, KPI threshold alerts (flash and live metrics, e-mail / SMS / in-app, acknowledgement) |
| **Rate packages & meal plans** *(v5.5)* | A **package catalogue** (breakfast, lunch, dinner allowance, airport drop, premium Wi-Fi, spa credit …) priced per person / adult / room, earned every night, on arrival, on the last night or once, used the same day or the next morning; **rate-plan inclusions** with automatic EP / CP / MAP / AP codes; nightly **entitlements** allocated out of the room tariff (the folio still shows one room line); **add-on packages** sold from the folio; a **Meal Desk** at the restaurant door (covers or allowance, extras charged to the room / paid / waived, voids); night-audit **expiry & breakage**; a daily GL journal (room revenue → 2135 package liability → F&B revenue / 4092 breakage); a 7-day **meal cover forecast** and three reports |
| **Financial close** *(v5.6)* | Accounting **periods** with a month-end **checklist** (14 automatic checks – night audit, income audit, GL posting, sub-ledger controls, trial balance, suspense, bank rec, claims, bills on hold, depreciation, payroll, PAYE/SSNIT, stock take – plus configurable manual tasks, waivers with reasons), **four-eyes** submit / approve / send back that drives the GL lock, four-eyes **reopen** of the latest month, **accruals, prepayments and deferred income** schedules, **recurring journals** (monthly / quarterly / yearly, auto-reversing), catch-up and night-audit auto-posting, the **year-end closing entry** to retained earnings |
| **Treasury & cash management** *(v5.7)* | **Cash position** across every bank, MoMo wallet, cash box, float and clearing account (minimums, unpresented cheques, last reconciliation); **banking, settlement sweeps and bank transfers** through **Cash in Transit** with **four-eyes approval** above a threshold, fees and short / over banking; **cheques received** register (post-dated, in hand, banked, cleared, bounced back to the city ledger) and **cheque books** for cheques written (void / stop / spoilt / stale); **13-week cash forecast** from live receivables, payables, payroll, taxes and manual lines; **IAS 7 cash-flow statement** (indirect method) |
| **Owner reporting & USALI** *(v5.8)* | **USALI 11th-edition Summary Operating Statement** (operator and owner formats, month / YTD against budget and last year, % of revenue, departmental schedule drill-down) mapped from the general ledger; **hotel KPIs** (occupancy, ADR, RevPAR, TRevPAR, GOPPAR, CPOR, labour and F&B cost %, flow-through); **hotel management agreement** with base, incentive (cumulative, owner's priority, true-down) and FF&E fees accrued and paid through the GL; **FF&E reserve ledger** and **capex projects** with owner approval; **monthly owner packs** issued on closed books, frozen, queried and acknowledged by the owner's representative |
| **Concierge & activities** *(v5.9)* | **Activities & excursions desk**: vendors (tour operators, guides, attractions) with commission and *folio* or *vendor* collection; activities with schedules, capacity, cut-offs and cancellation / no-show fees; departures with **waitlist auto-promotion** and printable manifests; bookings charged to the room as agent (vendor payable, commission in USALI misc. income) with QR **vouchers**; **guest-portal Explore** page for booking during the stay; curated **local guide**; netted **vendor settlements** through the GL |
| **Learning & SOPs** *(v6.0)* | **SOP library** with Markdown procedures, drafts, numbered **versions** (minor changes keep signatures, re-acknowledged versions need everyone to sign again), review cycles and retirement; **staff sign-off** online (self-service), from a paper **sign-off sheet** or through training; a **training catalogue** with validity, pass marks and SOP / food-hygiene links; **requirements** by department and / or job title with new-starter grace periods; the colour-coded **training matrix** (current / expiring / expired / overdue / due); **sessions** with enrolment suggestions, capacity, attendance, scores, **certificates** and automatic expiry; external records and exemptions; **My Training & SOPs** for every linked employee |
| **Sales & Marketing** *(v6.1)* | **Accounts** (corporate / agency master with tier, sales status, industry, account manager, potential; prospects are never auto-approved for direct billing), **contacts** with one primary and birthdays, **opportunity pipeline** (prospect → qualified → proposal → negotiation → won / lost, default stage probabilities, weighted forecast, Kanban board + list, printable proposal), **won** deals activate the account and **draft the rate contract** or link the room block / banquet event, **lost** deals record reason & competitor, reopen; **sales diary** (calls, visits, site inspections, meetings, entertainment with spend; plan / log / complete with follow-up / cancel), ownership rules (salespeople work their own deals, managers all), alerts (overdue, past close date, stale deals, key accounts going quiet, birthdays) + nav badge, **account production** (actual stays & folio room revenue vs last year, on-the-books), monthly **targets vs actual**; **marketing plan**: calendar of trade shows / roadshows / blitzes / fam trips / sponsorships / advertising with budget, cost, leads and **ROI from attributed opportunities**, **marketing budget vs actual** (events, diary entertainment, digital), **collateral library** (files or links, versions, expiry, download counts); 7 reports |
| **Digital Marketing** *(v6.2)* | **Channels & listings** (Facebook, Instagram, X, LinkedIn, TikTok, YouTube, WhatsApp; Google Business Profile, Tripadvisor, Booking.com / Expedia pages, the hotel website; Google / Meta / TikTok ad accounts) with **monthly follower / reach / engagement figures** and an 8-point **listing-health audit** (NAP, photos, description, amenities, hours, reviews answered, booking link, rate parity) due every 90 days; a **social content calendar** (month grid, pillars, per-channel caption limits) with **four-eyes approval** (draft → review → approved / changes requested → published, never your own), missed-slot alerts and post results; **paid campaigns** (`DMC-00001`, UTM tag, budget, target ROAS, CRM promo code) with daily spend / delivery entered by hand or **imported from Meta / Google / TikTok CSV exports**, pacing and over-budget alerts; **tracked short links** `/go/{code}` with UTM tagging, QR codes, bot- and repeat-filtered click logs and **booking-engine attribution** (link code in the URL or a 30-day cookie) → *PMS ROAS* from real room revenue; **influencer collaborations** (`INF-00001`: proposed → agreed → hosted → delivered, fee + comp, content links, reach, CPE, bookings through their link); digital spend feeds the v6.1 **marketing budget**; 5 reports |
| **Central Reservations** *(v6.4)* | **Group availability search** across every hotel of the group the agent sells (room types, free rooms incl. blocks / allotments / leases, live rate after seasons & revenue management, stop-sell, minimum stay, occupancy; cheapest first) and **booking into any property** from one screen (tenant switched for the call and always restored; locked re-check against double-selling; guest matched on e-mail / phone or created; blacklist check; rate override for managers; confirmation e-mail / SMS from that hotel); **reservations enquiry log** (`ENQ-00001`: caller, channel, stay, party, room type, quote, call length, follow-up) closed as **booked**, **referred** to a sister hotel (booking made there; linked enquiries at both hotels) or **lost** as a **denial** (sold out, room type full, restrictions, group / party size) or a **regret** (price, location, product, dates, competitor named from the comp set, payment terms, no response, shopping); follow-up contacts, reopen, automatic lapse; **cross-property guest lookup** (name, phone, e-mail, reservation no.; stays, revenue, upcoming bookings; erased profiles never shown); **lost business** by reason and competitor, **unconstrained demand** by stay date (on the books + denials vs capacity); **agent / channel KPIs** (conversion vs target, group retention, booked / referred / lost revenue, average call); referral matrix; nav badge; 5 reports |
| **Business Development** *(v6.3)* | **Competitive set** (up to 10 active hotels: stars, positioning, rooms, distance, strengths / weaknesses) with **rate shops** per stay date and channel (hotel websites, Booking.com, Expedia, Google Hotels, phone; our live BAR filled in automatically, sold-outs, comp average, gap, price rank; 14-day grid), **monthly STR-style benchmarks** → **MPI / ARI / RGI** (the report's "My Property" line or the PMS closed days); **market mix** by source market (nationality), segment and business source vs last year; **strategic partnerships** (`PTR-00001`: airlines, tour operators, embassies, banks, event organisers, universities, government …; referral / commission / co-marketing / barter / preferred / MoU) with **production tracked** through a business source, promo code or company (never double-counted), targets, commission, renewal / review / lapsed alerts, sign (approver) → expire → renew / terminate; **initiatives** (`BDI-00001`) through a stage gate (idea → assessment → business case → **four-eyes approval** → implementation → live → closed / shelved) with a **business case** (investment, annual revenue & cost, payback, **NPV at the hurdle rate**, ROI, DCF table, printable), frozen once approved, milestones (overdue alerts, all done before go-live) and **monthly actual results vs plan**; nav badge; 5 reports |
| **Quality Assurance** *(v6.5)* | **Brand standards library** (code, area, department, weight 1–5, critical flag, SOP link, fail rate) and **audit checklists** (internal, walk-through, mystery guest, brand / LQA; frequency, pass mark, next due: on track / due / booked / late); **audits** snapshotted at scheduling, conducted pass / fail / N/A with evidence notes, **weighted score** (N/A excluded) against the pass mark, **critical auto-fail**, **repeat findings**, acknowledgement by the department head, printable report; mystery-guest reports entered after the visit; **corrective actions** raised per failure (priority and due date by criticality / repeat), owner, root cause, action plan, engineering ticket, done → **verified by a different manager** (four eyes) or reopened; **QA dashboard** (average vs target, pass rate, department scores and change, 6-month trend, most-failed standards, checklist schedule, overdue actions); nav badge; 5 reports |
| **Parking & Valet** *(v6.6)* | **Zones** (guest / valet / visitor / staff / mixed) with capacity and live occupancy; **tickets** with normalised plates, vehicle history, bays; **valet** key tags, damage panels, fuel, runner; guest **QR "bring my car"** page, valet board with SLA, key board; **tariff** (free minutes, hourly, daily max, valet fee, lost ticket), **validation**, guest nightly rate posted by the **night audit** to the room folio, walk-in exit payment, manager waivers; check-out warning; gate plate lookup with bans; **permits** with expiry and revocation; nav badge; 5 reports |
| **Health & Safety** *(v6.7)* | **Fire-equipment register** with GNFS tags, checklists, expiry and contractor service; **inspection round**; failed checks raise engineering tickets; **permits to work** (hot work, height, confined space, LOTO, roof) with four-eyes issue, fire watch, gas test and detector isolation; **Act 328 accident register** with automatic notifiable test, printable notice, investigation and LTIFR; **5 × 5 risk assessments** with second-manager acceptance and reviews; **fire drills** per shift and **warden / first-aider coverage**; nav badge; 6 reports |
| **F&B Cost Control** *(v6.8)* | **Versioned recipe cards** with net quantities and yield-aware gross cost, four-eyes approval and print; **USALI food / beverage cost statement** (alcoholic-only beverage, staff meals and comps credited, theoretical vs actual); **ingredient usage variance**; **menu-engineering matrix** (star / plowhorse / puzzle / dog); **menu pricing** with suggested prices, controlled price changes and cost-creep alerts; **yield tests**; **staff meals**; nightly plate-cost snapshots; nav badge; 5 reports |
| **Talent Management** *(v6.9)* | **Establishment** and line managers; **vacancy requisitions** with four-eyes approval and over-establishment control; **recruitment pipeline** (public **careers page** with consent and CV upload, screening, panel interviews and scorecards, offers with above-range approval, hire → employee record); **onboarding** checklist, the **s. 13 written statement** and **probation reviews**; **appraisal cycles** (self → manager → HR sign-off, weighted goals + competencies, bands, PIPs); **discipline** (query, hearing, proportionate sanctions, warning expiry) and confidential **grievances**; **exits** with Labour Act notice, clearance, exit interviews and **turnover**; nav badge; 6 reports |
| **Masters** | Room types, rooms, floors, rate plans, taxes (tariff slabs), business sources, payment modes, companies, outlets, tables, menu categories/items, halls, stores, item categories, inventory items, suppliers, chart of accounts, departments, employees |
| **Administration** | Users, roles & permissions matrix, hotel settings (profile, logo, currency, timezone, policies), add properties to a group, database backup / per-property data export |

### Screenshots (v1.1)

`docs/screenshots/`: rate-calendar, tape-chart-dnd, qr-menu-mobile, pos-qr-pending, table-qr-sheet, loyalty, folio-loyalty.

### v7.34 — Room images on the reservation dashboard

The reservation dashboard now shows the property. A Rooms strip lists every room as a thumbnail from the existing room attachment files (with the room-type photo fallback the desk already uses), labelled with the room number, colour-edged by status, and opening the existing room card. Arrivals and departures show a small room photo next to the room number. Rooms with no photo on file show their type code, never a stock picture.

Full-suite finish: `searchGroups('')` now returns the `waitlist` key on the empty-query branch too, so `GET /search/suggest` no longer 500s with an empty `q` (the header search box always sends one first).

### v7.33 — Waitlist on the dashboard (finish)

The "Also today" card now lists the waiting waitlist entries (contact, room type, priority, dates, party size) using the data the desk already loads. With this, every count on the reservation dashboard has a live list or chart behind it: arrivals, departures, in house, unassigned, walk-ins, comps & house use, VIPs, groups, no-shows, waitlist, trends, pipeline, sources and room types.

### v7.32 — Group arrivals and no-show watchlist

The reservation dashboard adds a Group arrivals card: every tentative or confirmed group booking arriving in the next 7 days, with its status, room count and arrival date, opening the existing reservation screen. A No-shows card lists reservations the desk has marked no-show for the selected date, with their rooms. Both are read-only and use the desk's existing statuses.

### v7.31 — Printable day summary

The desk gains a Day summary button (desk header and dashboard quick actions) that opens a print view at /reservations/day-summary/print. It covers the selected desk date: occupancy, arrivals with ETA and check-in status, due departures with the not-posted balance to settle and holds, in-house, unassigned, and the 7-day pipeline. It reuses the desk's live data and the existing print layout; printing posts nothing.

### v7.30 — Room-type pipeline and ETA-ordered arrivals

The reservation dashboard adds a Room types table: for each room type, rooms expected tonight, bookings covering the next 14 days, and expected room revenue before tax, all from live reservations. The expected-arrivals card now lists guests in ETA order, with no-ETA arrivals last.

### v7.29 — Quick actions, walk-ins, comps & house use

The reservation dashboard opens with a quick-actions row: new reservation, walk-in, availability, tape chart and room board, each gated by its existing permission. A walk-ins card lists the day's walk-in arrivals with their readiness state and the existing check-in link. A comps & house-use card lists complimentary and house-use stays covering the date, using the timeline's own stayLabel rules.

### v7.28 — On the books, past ETA, booking sources

The reservation dashboard adds three live sections. On the books shows expected room revenue before tax for tonight, the next 14 days and the next 30 days. Past ETA — not checked in lists today's arrivals whose ETA time has passed without a check-in, with the existing check-in link. Booking sources breaks the next 14 days down by source with rooms and expected revenue. All figures come from the desk's live reservation queries.

### v7.27 — Reservation dashboard trends

The reservation dashboard now charts the fortnight. A Trends card shows expected occupancy for the next 14 days, expected room revenue before tax for the same window, and an arrivals-versus-departures bar chart for the next 7 days. Every bar is computed from live reservations on the desk's existing covering-stays rules; nothing is sampled, projected from history, or hard-coded.

### v7.26 — Reservation dashboard: pipeline, readiness, VIP

The reservation dashboard now shows the week, not just the day. A Next 7 days table lists arrivals, departures, rooms expected and room revenue before tax for each day from live reservations. The arrivals card states how many due-in guests are ready to check in (ID, phone and room on file). A VIP arrivals card names VIP stays arriving in the next 7 days with their ETA and room.

### v7.25 — The reservation dashboard is the desk's front page

Opening the reservation desk now lands on the Dashboard view: expected arrivals, due departures with the not-posted balance to settle, in house, unassigned, and the day's VIP, group, waitlist, no-show and cancelled counts. The Timeline, Room board, List and the other views remain one click away in the same tab bar, and a saved default view still takes precedence for users who set one.

### v7.24 — Reservation dashboard

The reservation desk now has a Dashboard view (Dashboard tab and Dashboard button). It puts the working day on one screen: expected arrivals with the room, ETA, what check-in still needs, and the existing check-in link; due departures with the not-posted balance to settle and a blocked flag where checkout would stop; in-house guests, occupied rooms and VIPs; unassigned stays with the assign drawer; and VIP, group, waitlist, no-show and cancelled counts linking to the existing views. Every figure comes from the same live queries the desk already runs. Opening the dashboard posts nothing.

### v7.23 — Arrivals show what check-in still needs

The arrivals list and the hotel dashboard now say whether the guest’s ID and phone are on file, and whether a room is assigned. A dirty room is named. Check-in does not clean it, and the existing check-in screen still collects what is missing. Due departures on the dashboard show the same not-posted balance to settle as the reservation desk.

### v7.22 — Departure list shows the balance to settle

The departure list still showed the folio balance already posted. It now also shows the balance checkout would have to settle, including unposted room nights and fixed charges. That figure is not posted. Transport, activities and laundry stay in the Holds column and are not added to it.

### v7.21 — Checkout holds on the departure list

The checkout quote now names the transport, activity and laundry amounts already on file. Transport and unconfirmed activities still block checkout. They are not added to the balance to settle, because confirm does not post them. Laundry is named and is not posted by checkout. The departure list shows the same hold, or says none is on file.

### v7.20 — Checkout quote includes fixed charges

The checkout quote now includes fixed charges that confirm would post, and the balance that would then have to be settled. Included packages stay inside the room charge and are not added again. The company picker uses that unsettled figure for the credit check. Nothing is posted until Settle & Check-out.

### v7.19 — Checkout screen no longer posts nights on open

Opening the existing checkout screen used to post unposted room nights before the guest was settled. It now shows the same checkout quote as the desk and writes nothing. Settle & Check-out still posts those nights, takes the payment, and marks the room vacant and dirty.

### v7.18 — Checkout quote, payment preview, and a receipt

Checkout from the stay now quotes the room, the unposted nights, the balance, and anything that would block the existing checkout. It does not post a night or change the room. Confirm still opens the existing checkout, which settles the bill and marks the room vacant and dirty. A payment or an advance is quoted before it is posted. A receipt prints only a payment already on the folio, and it is not a tax invoice. The stay also shows whether an ID is on file, the room’s housekeeping status, and any open maintenance ticket.

### v7.17 — Charge preview and key cards on file

Posting a folio charge from the stay now shows the tax from the hotel’s tax setup before anything is saved. Confirm still posts through the existing folio. The stay lists key cards already encoded for it, or says none are on file. A company on credit hold is named as such. City ledger stays off this desk.

### v7.16 — Block pickup from the desk

A group that already has a room block can preview a rooming-list pickup on the desk. The preview uses the existing block engine and creates nothing. Confirm posts to the same pickup action as the block screen. The stay card shows loyalty points and the company credit status when those are on file. The timeline shows the stored nightly rate. Print view prints the screen that is open.

### v7.15 — Amenity path, waitlist search, and group pickup

The dashboard lists the hotel’s real amenities and opens the desk already filtered by the one you choose. Grouped search includes the waitlist. A stay shows the last payment on file, including a Mobile Money reference when one was stored. A group row shows how much of its room block has been picked up, using the existing block engine. A vacant dirty room is labelled in words.

### v7.14 — Audit, ledger, and the printed plan

The desk links to the existing night audit, ledger, audit log, and permissions screens when the user is allowed to open them. A stay shows the audit lines already stored for that reservation. The room board uses a configured photo when one is on file, and the printed plan names each stay in words. Checkout still marks the room vacant and dirty through the existing checkout.

### v7.13 — Preview before split or block, and dark mode

Splitting a stay or blocking a room now shows the impact first. The split preview states both parts and the expected room and tax. The block preview states whether a vacant room would be marked out of order. Neither writes until confirm. Dark and light follow one switch across the hotel screens, not only the desk. Print stays light.

### v7.12 — Reservation desk, operational figures

The same reservation desk now has clickable room, reservation and revenue figures from the live columns. Extending or shortening a stay shows the night and tax change before the dates are saved. A timeline drag asks for confirmation, then uses the existing tape-chart check. Arrivals show the stored ETA when there is one, the room’s housekeeping status, and the folio balance. Group rows link to the existing room-block allocation. Nothing new is invented for building, wing or bed type.

### v7.11 — Reservation desk

The Reservation List is now the front-office command centre: a live room timeline, room board, arrivals, departures, in-house, unassigned, groups and waitlist, plus the original list with the same filters. Figures, amenities and photos come from the database. Assign, move, extend, shorten, split and block call the stay and availability services. Check-in, check-out, folio, payment, housekeeping, maintenance and key cards open the existing screens.

### v7.10 — Hold and fire on the POS

A waiter can tick **Hold** on a dish before sending the KOT. The kitchen gets the ticket marked **HOLD** and sees it on the display, but cannot start it. When the table is ready, **Fire** releases that dish (or every held dish on the bill). A FIRE slip goes to the station and the cook can then bump it like any other item. The guest is billed either way. A special order can be held too.

### v7.9 — Open item / special order on the POS

**Open item** on the till lets a waiter send a dish that is not on the menu. They type the name, the price, how the kitchen should prepare it, and which station cooks it (hot kitchen, tandoor, pastry or bar). It goes out on the KOT as **SPECIAL**, shows on the kitchen display for that station, and is charged on the bill like any other dish. It is not costed from a recipe. If the guest has allergies, the waiter must confirm with the chef before it is sent.

### v7.8 — Complimentary on the POS

A fired dish can be given away without cancelling it. On the till, **Comp** asks for a reason (guest recovery, VIP, manager, staff meal, birthday, quality or promotion). The kitchen still prepares it; it drops off the guest's bill and prints as COMP. **Undo** charges it again. **Complimentary / NC** settles the whole bill the same way, and the reason is kept on the bill. A complimentary bill is not sent to GRA E-VAT.

### v7.7 — Menu photos on the POS

Each dish can carry a photo. It shows as a picture tile on the POS till (and in the order cart), on the table QR menu, on in-room dining and on the guest TV. Masters → **Menu Items** is where a photo is added, replaced or removed. The demo already pictures the starters and main courses. A dish with no photo still appears, with a plain tile.

### v7.6 — Multiple KOT printers: split a ticket by station, choose printers, or send it down the kitchen LAN

POS → **KOT Printers** and **Print Station**. A kitchen order ticket is no longer one sheet.

- **Several printers per outlet:** hot kitchen, tandoor, pastry, bar, a captain / cashier copy, and a network grill pass in the demo. Each printer is browser (USB / Windows) or network (ESC/POS on a private LAN address, port 9100), with paper width, copies, a header and a beep.
- **Routing:** a dish follows its own printer, else its category, else the kitchen station (`Kitchen` when the category has none), else the fallback printer. Unmapped dishes are never dropped.
- **On the till:** sending a KOT shows which printers it will hit. You can auto-route, print one combined sheet, or tick specific printers — including forcing every item onto a printer that is still working. The captain copy opens on the till; station slips go to their own printers. Cancelling a fired item prints a **VOID** slip to that station and the captain.
- **Print page:** pick any combination, open each printer in its own window, or send the network printers again. The kitchen display has a Print button per ticket, and a **print station** page to leave open beside a USB printer.
- **Settings:** split / combined / ask each time, and whether a captain copy is printed.

### v7.5 — Event organiser portal: clients follow their events, sign the banquet event order, set guarantees, keep the guest list and pay online

A separate sign-in at **`/organiser/login`** for companies and private clients with meetings, weddings and banquets. Staff use the new **Organiser Portal** menu: *Organiser Dashboard*, *Requests & Approvals*, *Portal Events*, *Organiser Logins*, *Portal Activity* and *Portal Settings*. A sidebar badge counts organiser requests waiting for the hotel, and the banquet event page shows when the event is on the portal.

- **Organiser logins:** an administrator, an event planner and a finance contact per company, or a private client linked to one event (and anyone whose e-mail is the event contact). Invitation links, a 10-character password policy, five-strike lock-out, e-mail reset and an idle sign-out — a session of its own, never the staff login.
- **Banquet event order:** staff publish the BEO; the organiser checks every function, menu and the money and signs it online (typing their name). Re-publishing drops the old signature. Guest-number guarantees can be lowered until the cut-off, and only raised after it.
- **Requests:** change requests (date, numbers, menu), cancellation requests that quote the house fee and release the venue when the hotel approves, and new-event quotes. The organiser is e-mailed the answer.
- **Money & guests:** pay any part of the event balance by card or mobile money — it lands on the event folio as a deposit. Guest lists carry dietary needs, VIPs, speakers and crew, with a kitchen summary.
- **Reports:** *Organiser Portal Events*, *Online Payments*, *Requests & Response Times* and *Access Audit*.

### v7.4 — Corporate & travel-agent portal (B2B extranet): partners book at their negotiated rates, track their bookings, ask for changes and group quotes, see their statement and pay their account online

A separate sign-in at **`/partner/login`** for corporate clients and travel agents. Staff use the new **Partner Portal** menu: *Partner Dashboard*, *Approval Queue*, *Partner Accounts*, *Portal Activity* and *Portal Settings*. A sidebar badge counts the partner requests that are waiting.

- **Partner logins (one company each):**
  - **Roles:** *administrator* (everything, including the company's own logins), *booker* (book and manage stays) and *finance* (statement, invoices, payments, commissions).
  - **Invitations:** the hotel, or the partner's administrator, invites a colleague by e-mail. The link is valid for a set number of days, and the partner chooses a password of at least 10 characters with letters and numbers that does not contain their e-mail name.
  - **Security:** sign-in uses the hotel ID + e-mail + password. Five wrong passwords lock the login for 15 minutes, and staff can unlock it. *Forgot password* answers the same way for unknown addresses, and its link lasts one hour. Sessions end after a period without activity, when the password is changed elsewhere, when the login or the company's portal is switched off, or when the hotel switches the portal off.
  - The partner session is completely separate from staff sign-in, and partners never see another company's data.
- **Book a stay:** search by dates, rooms and adults. Every room type shows live availability and the partner's price:
  - the **contract net / discount rate** (Sales › Rate Contracts) when a contract is active;
  - otherwise the best available rate less the company's discount, with the saving against the public rate shown.

  The booking lists the travellers and the partner's own reference (PO / file number), with an optional traveller e-mail and a special request. It can be **billed to the company** (city ledger) or **paid by the traveller**, as the hotel allows per company. Limits are the hotel's booking window, the longest stay and the rooms per booking. Larger groups are sent to *group request*.
- **Approval rules:** the booking is held as *tentative* (rooms kept) and goes to the hotel's **Approval Queue** when:
  - a company-billed booking would break the **credit limit**;
  - the account is on **credit hold** or has overdue invoices;
  - the total is above the company's **approval threshold**.

  *Approve* confirms the booking. *Decline* (with a note to the partner) cancels it without a fee. The partner is e-mailed either way.
- **Bookings:** *Upcoming*, *In house*, *Past*, *Cancelled* and *All*, with search. The booking page shows the rooms, the nightly rate, the stay value, the bill-to, the status and the request history.
  - **Cancel online** under the contract's (or the hotel's) cancellation terms, with the fee shown before confirming. The hotel can switch online cancellation off.
  - **Change requests** (dates, names, rooms) go to the hotel, one open request at a time. The partner can withdraw a request.
- **Group requests:** dates, rooms, a budget and notes. The hotel answers with a quote note.
- **Finance:**
  - the **statement** of the city ledger (invoices and payments with a running balance, overdue and credit position);
  - printable **invoices** for the company's own folios;
  - **pay online** through the hotel's payment gateway (*Accounts › Online Payments*). Any part of the outstanding balance can be paid, and the payment is posted as a receipt voucher against the company;
  - **agent commissions** (travel agents only): due and paid.
- **Hotel side:**
  - **Partner Accounts:** each partner's portal terms (on / off, traveller-pays allowed, approval threshold, rooms per booking), logins (invite, resend, change role, disable, unlock), bookings, requests and recent activity.
  - **Portal Activity:** the full security / audit trail (sign-ins, failed passwords, lock-outs, time-outs, bookings, cancellations, requests, payments, user changes) with IP addresses.
  - **Reservations made in the portal** show the booker, the company and the partner's reference on the reservation page, with a link to any pending approval.
- **Reports (Partner Portal):** *Partner Portal Bookings*, *Production by Partner* (logins, sign-ins, bookings, room nights, value, cancellation %, share booked online), *Partner Requests & Response Times* and *Portal Access Audit*.
- **Permissions:** `partner.view` (dashboard, queue, accounts, activity), `partner.approve` (approve / decline / complete) and `partner.manage` (terms, logins, settings). The Front Office Manager can view and approve, the administrator can do everything, and reception has no access.
- **Demo data:**
  - **Infosys** (corporate, approval over GH₵ 40,000, traveller-pays allowed), **Thomas Cook** (agent, on credit hold with overdue invoices) and **Yatra** (agent, traveller pays) are on the portal. TCS is not.
  - **Logins:** `travel.desk@infosys.com` (admin), `rahul.iyer@infosys.com` (booker), `accounts.payable@infosys.com` (finance), `karan.shah@thomascook.in`, `bookings@yatra.com`, all with password `Partner2026` and hotel ID `NOWILLS`. There is also an invited login, a disabled login and a locked login.
  - **Portal bookings:** confirmed, held for approval (over the threshold and on credit hold), approved, declined and cancelled, plus a pending change request, a pending 12-room group request and two weeks of sign-in history.

### v7.3 — Guest messaging inbox: one shared inbox for the guest-portal chat, WhatsApp, SMS and e-mail, with keyword routing, automatic answers, response-time targets, broadcasts and signed webhooks

A new **Guest Messaging** menu with *Inbox*, *Messaging Dashboard*, *Broadcasts*, *Quick Replies*, *Routing Rules* and *Messaging Settings*. The sidebar badge counts guests waiting for an answer.

- **Channels:** guests write from the new **Chat** page of the guest portal (`/stay/{token}/chat`: speech bubbles that refresh every 4 seconds, an unread dot in the portal menu), from **WhatsApp** or **SMS** (through Twilio or any JSON gateway) or by **e-mail** (the mail provider's inbound-parse webhook). Every message lands in one conversation per guest and channel.
- **Guest matching:** numbers are normalised to international format (`0244…`, `00233…` and `+233 24…` are the same number). E-mail addresses are matched without regard to letter case. Each conversation is linked to the guest's current (or next) stay and booking. Unknown senders stay unmatched until staff link them to a profile from the side panel.
- **Three-pane inbox:**
  - **Folders:** *Open*, *Needs reply*, *Mine*, *Unassigned*, *Waiting for guest*, *Snoozed*, *Closed*, *All*. You can filter by channel and team and search by name, room, number or message text.
  - **The list** puts guests who are waiting first. It shows a waiting timer that turns red past the response-time target, urgent flags and unread counts.
  - **The thread** shows each message's delivery status (sent / delivered / read / not sent, with the gateway's error) and marks automatic and broadcast replies. It also shows *answered in …* times.
  - **The side panel** shows the guest, the stay, the booking, the open folio balance, this stay's guest requests and earlier conversations.
  - The inbox refreshes itself every 10 seconds.
- **Working a conversation:**
  - **Reply** or add an **internal note**, which the guest never sees.
  - **Quick replies:** type `/wifi`, `/late`, `/taxi`… or pick from the list. Placeholders such as `{guest}`, `{room}`, `{departure}`, `{checkout_time}`, `{wifi_name}` and `{wifi_password}` are filled in for the guest.
  - **Send & wait for guest** moves the conversation to *Waiting for guest*. It closes itself after a set number of hours without an answer.
  - You can also *Take it* / assign, change the team or priority, **snooze** (30 minutes, 2 hours, tomorrow 08:00), **close** and **reopen**.
  - **Raise a guest request** (housekeeping, amenities, maintenance, laundry…) straight from the chat. It appears in *Guest Requests* with the source *Guest message*.
  - For an in-house guest, *New conversation* starts a portal chat, SMS or e-mail.
- **Routing rules:** ordered keyword rules using whole words or phrases. The first rule that matches can:
  - route the conversation to a team (front desk, reservations, housekeeping, engineering, concierge, F&B, guest relations);
  - mark it **urgent**;
  - **raise a guest request** for an in-house guest;
  - send an **automatic answer**, which counts as the answer.

  A *Try a message* box shows which rule would handle a sample text.
- **Automatic replies:** an **outside-hours reply** outside the guest-services hours (overnight desks are supported), at most once per conversation. An optional **acknowledgement** is sent for every new conversation.
- **Response-time tracking:** the clock starts at the oldest unanswered guest message. Notes and outside-hours replies do not stop it. The **first staff answer** is stored in seconds, per message.
- **Guest rating:** closing a portal chat asks the guest for **1–5 stars**, which can be given once. SMS and WhatsApp guests can answer with a digit.
- **Messaging rules and consent:**
  - **The 24-hour WhatsApp window is respected.** Staff get a warning and cannot send free-form WhatsApp after 24 hours.
  - **STOP / START** keywords record an opt-out or opt-in in the marketing consent log. Broadcasts then skip that number.
- **Broadcasts:** send to all in-house guests, today's arrivals, today's departures or the in-house guests on one floor.
  - **Channel options:** *best available* (WhatsApp, else SMS, else portal chat) or one channel only.
  - A **live reach preview** shows how many guests are reached on which channel and why the others are skipped. Each guest gets the message, with their own placeholders filled in, in their own conversation, so replies land in the inbox.
  - A delivery page is kept for each broadcast.
- **Inbound webhooks** (stateless: no session, no CSRF, scoped to the hotel by its code):
  - `POST /webhooks/inbox/twilio/{HOTEL}` is verified with the **X-Twilio-Signature** and answers with empty TwiML.
  - `POST /webhooks/inbox/generic/{HOTEL}` takes JSON `{channel, from, body, id, name, subject}` signed with `X-Nowills-Signature: sha256=HMAC-SHA256(body, secret)`. The secret is created or rotated in settings and shown once.
  - Delivery receipts (`{"type":"status","id":…,"status":"delivered"}` or Twilio `MessageStatus`) update the staff message.
  - The gateway's message id prevents duplicates when a message is retried.
  - Unsigned or wrongly signed calls are refused, and a rate limit applies.
- **Dashboard:**
  - **Totals:** guests needing a reply and how many are past the target; open, unassigned and your own conversations.
  - **Response times:** average first response and the share answered within the target over 7 days.
  - **Activity:** guest messages today, the automated-reply share and average chat rating.
  - **Breakdowns:** the *Waiting longest* table, guest messages by hour and by channel, open conversations by team, team response times, the busiest rules and recent broadcasts.
- **Reports (4 new, 249 in total):**
  - *Guest Conversations*.
  - *Messaging Response Times by Day*.
  - *Messaging Team Performance*.
  - *Messaging Topics & Automatic Answers*.
- **Permissions:**
  - `inbox.view`: Housekeeping Supervisor and Quality Manager.
  - `inbox.view` and `inbox.reply`: Receptionist, Concierge and Reservations Agent.
  - `inbox.broadcast`.
  - `inbox.manage`, for settings, rules, quick replies and the webhook secret.
  - The Front Office Manager has all four.
- **Demo data:**
  - 8 quick replies and 6 routing rules.
  - A live inbox drawn from the in-house guests:
    - a VIP airport transfer on WhatsApp;
    - an AC fault by SMS that is past the target, urgent and has an internal note;
    - an unknown number asking for rates (unassigned and overdue);
    - towels and Wi-Fi questions answered by rules;
    - a late check-out waiting for the guest;
    - a snoozed dinner booking;
    - an e-mailed invoice request.
  - Rated chats with earlier stays over the last week.
  - Last night's check-out reminder broadcast to today's departures.

### v7.2 — Self-service kiosk: lobby check-in with ID photo, signed registration card, QR payment, room choice and key-card encoding; express check-out; walk-in booking; hand-over to the desk

A new **Self-Service Kiosk** menu with *Kiosk Dashboard*, *Kiosks*, *Guest Visits* and *Kiosk Settings*. The kiosk side is a single touch-first HTML page (vanilla JS, no framework, no Node). It runs full-screen in any modern browser on a lobby kiosk PC, an all-in-one touch screen or a wall-mounted tablet in kiosk mode. It has large targets, a step bar, *Call for help* and *Start over* on every screen, and an on-screen keyboard on touch devices.

- **Pairing:** the kiosk opens `/kiosk/setup/{HOTEL CODE}` and shows a **6-digit code** for 15 minutes. The manager enters it under *Kiosks › Pair* with a name, location, services (check-in / check-out / walk-in) and hardware (key-card encoder, ID camera). The kiosk then starts its welcome screen by itself.
  - Each kiosk keeps a secret 40-character **start address**. *New address* kills a stolen or cloned link.
  - *Take out of service* makes the kiosk show "please see the front desk".
- **Security model:** the kiosk API is stateless, with no staff session and no CSRF. Everything is scoped by the kiosk's token to its own hotel.
  - Each visit has its own 40-character token and only works on the kiosk that started it.
  - Rate limits apply per kiosk and per IP. Booking look-ups allow **3 misses per visit**, after which the kiosk hands over to the desk, and a per-kiosk limit applies on top.
  - Personal details on file (phone, e-mail, ID and passport numbers) are shown **masked**.
  - A Content-Security-Policy covers the kiosk pages.
- **Self check-in**, in eight steps: *find › details › ID › sign › pay › room › keys › done*.
  - **Find:** booking number (typed loosely: `RES-00073`, `res 00073`, `73`) or OTA reference, plus surname, or the **QR code from the pre-arrival e-mail** via a USB scanner in keyboard mode.
  - **Rules:** it checks the arrival date, the "from" time with optional early check-in into a ready room, bookings with several rooms (→ desk), an optional **VIP personal welcome** (→ desk) and the booking status.
  - **Details:** phone, e-mail, nationality, ID type and number, updated on the guest profile. When Form C reporting is on, foreign guests also give passport expiry, visa, purpose, arriving-from and next destination.
  - **ID photo:** from the kiosk camera, or a document-scanner / file fallback. It is stored as the guest's *ID front* document and skipped when a recent ID is already on file (setting).
  - **Registration card:** the guest signs with a finger and ticks the terms. The signature is stored as the stay's GRC signature.
  - **Payment:** a **QR code** that opens the hotel's online-payment link on the guest's own phone (mobile money MTN MoMo / Telecel Cash / AirtelTigo, or card). No card terminal is needed.
    - The kiosk polls the gateway result and posts the payment to the folio through the existing online-payment engine.
    - Collect nothing, the first night, or the whole stay incl. VAT / NHIL / GETFund / Tourism Levy, plus an optional incidentals deposit. Money already paid is deducted.
    - *I'd rather pay at the desk* hands over. If online payments are switched off, check-in degrades to the desk.
  - **Room:** up to 4 ready rooms of the booked type (clean-or-inspected, or inspected only; setting) to choose from, or an automatic pick. Check-in goes through the normal check-in engine, so every hook runs: portal, TV welcome, Form C, housekeeping.
  - **Keys:** 1–4 key cards (setting), encoded through the door-lock interface. A kiosk without an encoder sends the guest to the desk for keys.
  - **Welcome screen:** room and floor, Wi-Fi name and password, the **TV PIN** for movies, the check-out time and breakfast hours.
- **Express check-out:** find by room number + surname, or booking number + surname. The kiosk shows the itemised bill and balance.
  - **Complete bill:** before the bill is shown and again before check-out, the kiosk posts exactly what the desk's check-out would post (pending nights, one-off items such as a pre-booked late check-out). The guest therefore always sees and pays the final amount.
  - **Paying:** a balance is paid by QR. Split bills, credits due and anything check-out refuses (open POS bills, transport, activities, Form C) go to the desk with the reason.
  - **After check-out:** the folio closes with its tax-invoice number and the key cards stop working. The guest can leave a 1–5★ rating (recorded as feedback with source *kiosk*) and **e-mail the invoice** to the address on file.
- **Walk-in booking:** nights and adults (limits in settings) → ready room types with the **total incl. taxes** from the rate engine → name, phone, e-mail, nationality and acceptance of terms.
  - It creates a *Walk-in* reservation, then runs the check-in steps with the **whole stay paid up front** by QR.
  - Offered only when online payments are on and the kiosk allows walk-ins.
- **Hand-over to the desk:** anything the kiosk cannot finish ends with "a member of our team is on the way" and appears in the dashboard's **Assistance queue** with the reason, the guest's note and the waiting time, plus a red badge in the navigation.
  - Reasons: booking not found, VIP, pay at desk, no ready room, several rooms, keys, bill query or refund, check-out blocked, guest asked.
  - The receptionist opens the visit, sees every step with timestamps, the documents and the payment link, and marks it *dealt with* with a note.
- **Idle and privacy:** after the idle time (setting, 90 s; 5 min while a payment QR is on screen) the kiosk asks "Are you still there?" and resets after 15 s. Visits idle for 15 minutes are closed as *abandoned*. Done screens return home by themselves, and a finished visit cannot be replayed.
- **Dashboard:** kiosks online, self check-ins and check-outs today against the hotel's total (the kiosk share), money collected at the kiosk, average check-in time, the assistance queue, a **check-in funnel** (started → found → details → signed → checked in → finished), visits by hour, kiosks and their last heartbeat, who is using a kiosk now, and recent visits.
- **Roles:**
  - Front Office Manager: everything.
  - Receptionist: view and assist.
  - Settings and pairing need the new *manage* permission.
- **Demo data:**
  - **Kiosks:** Lobby Kiosk 1 (all services), Lobby Kiosk 2 (no walk-ins), and a Pool Terrace Kiosk that is out of service.
  - **Visits:** 13 days of kiosk visits drawn from **real** check-ins, check-outs and walk-ins in the demo data, with no invented payments. They include abandoned and started-over visits, resolved desk hand-overs, and one guest **waiting for help right now** (booking not found).
  - Seeded heartbeats are real timestamps, so kiosks show *offline* a few minutes after seeding unless a browser tab is open on their start address.

**Reports:** *Kiosk Guest Visits*, *Kiosk Adoption by Day*, *Kiosk Payments* and *Kiosk Assistance Requests* (245 reports).

### v7.1 — IPTV & guest welcome: in-room TV portal with a personalised welcome screen, TV pairing, live channels & programme guide, pay movies on the folio, TV messages and emergency alerts, lobby signage and usage analytics

A new **IPTV & Guest Welcome** menu with *Dashboard*, *TVs & Screens*, *Channels & Guide*, *Movies & Rentals*, *Welcome Screens*, *Information Pages*, *TV Messages*, *Usage Analytics* and *Settings*. The TV side is a single HTML5 page (ES5 + XHR, no framework, no Node) that hotel TVs open full-screen from their URL launcher: Samsung hotel TVs (Tizen hospitality mode), LG Pro:Centric (webOS), Philips MediaSuite, Android / Google TV hotel launchers or a kiosk browser on a mini-PC or set-top box.

- **Pairing a TV:** the new set opens `/tv/setup/{HOTEL CODE}` and shows a **6-digit code** for 15 minutes. Engineering enters it under *TVs & Screens › Pair a TV* and picks the room, or a public location for a lobby screen. The TV then starts the portal by itself.
  - Each TV gets a secret 40-character **start URL**, which it keeps. *Issue new URL* kills a lost or stolen set's link.
  - A TV can also be added by hand, with platform, model, MAC and serial.
- **Heartbeat:** the TV polls every 20 s (setting). Each poll carries its state and any **remote commands**: reload, reset, test message, volume, tune to a channel, power off / on. The dashboard shows each room TV by floor as online-with-guest, online-vacant, offline or no TV, and alerts for offline sets, pairing codes on screen, in-house rooms without a TV and channels whose guide has run out.
- **Personalised welcome:** when an in-house guest first switches on, the TV shows a full-screen welcome with their name, the hotel, and Wi-Fi name and password, then the home screen.
  - **Choosing the welcome screen:** the TV tries the guest's language, then the hotel's default, then English. Within a language the highest **priority** wins, then a segment-specific screen over *all guests*, then the oldest.
  - **Segments:** VIP, repeat guest, corporate, group, long stay (7+ nights) and birthday during the stay.
  - **Placeholders:** `{name}`, `{title}`, `{first_name}`, `{last_name}`, `{room}`, `{arrival}`, `{departure}`, `{nights}`, `{hotel}`, `{wifi_name}`, `{wifi_password}`, `{company}`, `{group}`, `{checkout_time}` and `{desk_ext}`. Screens can have valid-from / to dates, e.g. an Independence Day screen.
  - **Name privacy:** a setting shows the full name, title and surname, first name only, or no name at all.
- **Guest language:** English, French, Spanish, Portuguese, German and Chinese (setting). The guest chooses on the TV and it is **remembered on the guest profile** for the next stay. Otherwise it is inferred from nationality, e.g. Côte d'Ivoire → French.
- **Home screen:** Live TV, Movies, In-room dining, Services, Messages (with unread badge), My bill, Hotel guide, Explore Ghana, Express check-out and Rate your stay, plus weather and the desk extension. Everything works with the **remote's D-pad** (spatial navigation), Back / Home keys, number keys for direct channel entry and CH+ / CH−.
- **Live TV & programme guide:** channel list with now / next and an on-screen display.
  - **Line-up:** **M3U import / export** (`tvg-id`, `tvg-chno`, `group-title` → category) and an **XMLTV** guide import that links channels by id or display name. XXE-safe: DOCTYPE entities are refused and network loads are off.
  - **Playback:** `http(s)` streams play in the TV's browser. Multicast `udp://` / `rtp://` channels show the channel card, OSD and guide, but the picture itself needs the TV vendor's native tuner / player API, which is **not integrated in this release**.
- **Pay movies:** catalogue with genre, rating, runtime, price and rental window (24 h).
  - **Posting:** a rental posts **“Pay TV / Movies”** to the room folio with VAT 15 % + NHIL 2.5 % + GETFund 2.5 % + Tourism Levy 1 % (`TVP-00001`).
  - **Safeguards:** renting again inside the window never charges twice. There is a daily limit per room, and adult titles are off by default.
  - **PIN:** purchases need the stay's **4-digit TV PIN**, shown on the reservation's *In-room TV* card for the desk to hand over with the key card. The PIN can also protect the bill (setting). Five wrong PINs lock purchases and the bill for 15 minutes.
  - **Refunds:** staff refunds **void the folio charge**, with a reason, while the folio is open.
- **Guest services from the remote:** they reuse the guest-portal engine, and each shows its source as **TV**.
  - **In-room dining** creates a POS order for confirmation.
  - **Requests:** housekeeping, amenity, maintenance and laundry requests.
  - **Wake-up calls** can be single or daily.
  - **Also:** the bill with line detail and balance, **express check-out** requests, and a 1–5★ rating recorded as in-house feedback. A detailed portal / survey review is never overwritten.
- **TV messages** (`TVM-00001`) go to one room, a floor, a group booking, VIP rooms, every in-house room or the lobby screens.
  - **Delivery:** optional start / end time, normal or high priority, and optional *guest must press OK*. High-priority messages pop up one at a time, never over a PIN pad or order.
  - **Tracking:** read receipts, reach (number of TVs) and withdrawal with a reason.
- **Emergency alert:** managers with the new permission (HSE / security) type `EMERGENCY` to confirm. A full-screen alert then takes over **every TV and lobby screen** and pauses playback.
  - Only one alert can be live at a time, and it cannot be withdrawn like a message.
  - **All clear** closes it and broadcasts the all-clear message for 30 minutes.
- **Privacy:** check-out ends the TV session, stops rentals, expires room messages and queues a **reset**, so the next guest never sees the previous guest's name, rentals or bill. A room move resets the old room's TV and carries the welcome to the new one. The TV API never returns the PIN, and it only works for the TV's own hotel.
- **Lobby & signage screens:** today's banquet events with times and halls, signage messages and a scrolling welcome ticker.
- **Information pages:** dining, spa, facilities, safety, Wi-Fi, transport and offers, per language, with an icon and sort order. *Explore Ghana* uses the concierge local guide.
- **Usage analytics:** share of stays that used the TV portal, screen actions per day, what guests open, top channels, top information pages, top movies and revenue, guest languages, and messages read. Retention is configurable (180 days by default).
- **Night audit:** closes stale sessions, removes expired pairing codes, expires old commands and prunes old guide data and events.
- **Roles:**
  - Front Office Manager: everything.
  - Receptionist and Sales: view and message.
  - HSE and Security: view and emergency.
  - Chief Engineer: view and manage.
- **Demo data:**
  - **TVs:** a TV in every room of both hotels (Samsung on the ground floor, LG upstairs); two offline, one disabled (cracked screen), one showing a pairing code. Lobby, restaurant-entrance and conference-foyer screens.
  - **Channels:** 32 (Ghana & local, news, sports, movies, documentary, kids, music, radio) with a 36-hour guide.
  - **Movies:** 12 original titles; six of today's rentals (one refunded).
  - **Messages:** a fire drill with its all clear three days ago; live broadcasts (pool maintenance, a floor water interruption, a VIP invitation, a delegates' welcome on the lobby screens); a room message awaiting OK; a withdrawn notice.
  - **From the TV:** a dining order, a turn-down request, a wake-up call, an express check-out and a 5★ rating.
  - **Usage:** 14 days of sessions and usage.
  - Seeded heartbeats are real timestamps, so TVs show *offline* a few minutes after seeding unless a real set, or a browser tab on its start URL, is polling.

**Reports:** *TV & Screen Register*, *Movie Rentals (Pay TV)*, *TV Message & Emergency Log*, *TV Engagement by Day*, *Orders & Requests from the TV* and *Channel Line-up & Viewing* (241 reports).

### v7.0 — Sustainability & ESG: GHG Protocol carbon inventory with the Ghana grid factor, HCMI / HWMI per occupied room and per m² of meeting space, waste register and diversion, F-gas log, guest green stay, targets and initiatives, people & community, eco-certification self-assessment, policy register, carbon statements and the annual ESG report

A new **Sustainability & ESG** menu with *ESG Dashboard*, *Carbon & HCMI*, *Waste*, *Refrigerants (F-gas)*, *Green Stay*, *Targets*, *Initiatives*, *People & Community*, *Certification*, *Policies*, *Carbon Statements*, *Annual Report* and *ESG Settings*.

- **Localisation fix – Ghana grid factor.** The utilities module still used India's CEA grid factor (0.71 kg CO₂e/kWh), which the v4.3 Ghana localisation missed. The default is now **0.314 kg CO₂e/kWh**, from Ghana's 2023 generation mix (about 60 % gas and 38 % hydro; Ember / IEA data). `pms:upgrade` changes the setting only when it is still at the old default, so an edited value is kept.
- **Carbon inventory** (GHG Protocol, operational control), built from the utility meters with no extra data entry:
  - **Scope 1:** LPG, diesel, piped gas and refrigerant losses.
  - **Scope 2:** grid electricity.
  - **Scope 3:** water supply and wastewater, waste by disposal route, and outsourced laundry.
  - Each line shows the activity, factor, kg CO₂e and share. Solar generation is shown as avoided carbon but is not deducted from the footprint.
- **HCMI / HWMI** (Hotel Carbon / Water Measurement Initiative, Sustainable Hospitality Alliance):
  - **Boundary:** Scopes 1 and 2 plus outsourced laundry. Water and waste are outside HCMI.
  - **Allocation:** private or tenant space is removed pro-rata to floor area. The rest is split between guest rooms & corridors and meeting space by their floor areas.
  - **Outputs:** kg CO₂e and litres per occupied room-night, and per m² of meeting space per hour. Meeting-space use (m²·h) comes from banquet hall areas × function times for indoor definite / completed events.
  - **Laundry:** uses the laundry's monthly tonnage, or HCMI's default of 5.1256 kg of linen per occupied room for months without one.
  - **Period:** the benchmark covers the latest 12 months, starting no earlier than the first meter reading.
- **Waste register** (`WST-00001`): date, stream (general, food, paper & card, plastic, glass, metal, garden, e-waste, hazardous, used cooking oil), route (landfill, recycling, composting, anaerobic digestion, incineration, donation, rendering) and weight, plus department, contractor, waste transfer note, cost and income.
  - **Rules:** hazardous waste to landfill needs a transfer note. The recorder can cancel their own same-day entry; older entries need an ESG manager. Every cancellation needs a reason.
  - **Figures:** stream × route matrix, **diversion rate**, kg per occupied room, food waste, kg CO₂e and the carbon avoided compared with sending everything to landfill (DEFRA / DESNZ factors, editable), and a 12-month trend.
- **F-gas log** (`RFG-00001`): refrigerant added or recovered per system, with gas, GWP (IPCC AR5), charge, technician and certificate, linked to the asset and maintenance ticket.
  - **Emissions** (GHG Protocol refrigerant method): a new system's initial charge is not an emission; a top-up replaces leaked gas; a service loses what was put back beyond what was recovered; a scrapped system loses whatever of its charge was not recovered.
  - **Leak rate:** each system's **annual leak rate** is operating losses ÷ charge. Systems above the alert (10 %, setting) are flagged on the dashboard and in the nav badge.
- **Guest green stay:** the guest skips the stay-over clean and gets a full refresh every 3 nights (setting). The departure clean always happens.
  - **Opting in:** from the front desk or the **guest portal**, applying from the next night. The guest can switch it off at any time.
  - **Housekeeping** task generation leaves the room out on green nights.
  - **Night audit:** logs each green night with the estimated kWh, litres and CO₂e saved and credits **loyalty points** (50 a night, when loyalty is on). A night on which the room was cleaned anyway doesn't count, and nothing is credited twice. Stays that have checked out are closed.
- **Targets:** ten metrics (carbon, total carbon (annualised), energy, water and waste per room, diversion, renewable share, local purchasing, women in management, training hours).
  - Each target has a baseline year / value and a target year / value, and must move in the right direction. There is one active target per metric.
  - Progress is measured against a straight line from baseline to target, as **achieved / on track / behind**.
- **Initiatives** (`ECO-00001`): category, owner, capital cost, annual saving, **simple payback**, and kWh / m³ / waste savings. CO₂e is derived from these unless it is entered.
  - **Workflow:** proposed → approved → in progress → completed (the outcome is required), or rejected (reason required) / cancelled.
  - **Four eyes:** the proposer cannot approve their own idea (setting). Only an ESG manager changes an approved initiative.
- **People & community:**
  - **Workforce:** headcount by gender and department, women in management, Ghanaian staff and local hires, and turnover.
  - **Training and safety:** training hours per employee and **LTIFR** from HSE accidents.
  - **Pay:** the gender pay gap (mean basic), and **staff below the national minimum wage** (GH₵ 21.77 × 27), named.
  - **Local sourcing:** net supplier spend with suppliers flagged *local* (new flag in Masters → Suppliers). Gender and nationality are new fields on the employee master.
  - **Community register** (`CSR-00001`): donations, in-kind support, volunteering hours, sponsorship, training and surplus food, with evidence uploads.
- **Certification self-assessment:** 31 criteria in nine sections, 16 of them mandatory, in the style of Green Key / EarthCheck. It prepares for an audit but is not itself a certification.
  - Each criterion is marked met / partly met / not met / not applicable, with evidence. A mandatory criterion cannot be n/a, and met or partly met needs evidence.
  - **Readiness score:** (met + ½ partly met) ÷ applicable criteria. The hotel is ready when every mandatory criterion is met.
- **Policy register:** twelve starter policies (environmental, social, governance – including Act 843 data protection) with owner, version, approver, document upload and a review cycle.
  - **In force:** needs an approval date and approver. The next review is the approval date plus the cycle, and a review that has passed raises an alert.
  - **Governance panel:** data breaches from the privacy register and expired licences from the compliance register.
- **Carbon statements** for a booking, a banquet event (functions plus its room block) or a corporate account for a year: room-nights × HCMI per room plus m²·h × HCMI per m²·h, with water. The printable statement explains the method. Corporate clients and event planners increasingly ask for these.
- **Annual ESG report:** environment by scope, KPIs, targets, initiatives, social indicators, community and governance, with a print / PDF view.
- **Settings:** building areas, green-stay options, the leak alert, four eyes, Scope 3 factors (water, wastewater, waste by route, laundry) and outsourced laundry. Upgrading adds new alerts only for new data – nothing on an existing property is flagged overnight.
- **Roles:** a new **Sustainability Manager** role holds every ESG permission.
  - Front office can enrol green stays.
  - Housekeeping, kitchen, F&B, engineering and HSE can record waste and gas.
  - Managers view the module.
- **Demo data:**
  - **Waste:** 12 weeks of collections, with diversion rising after composting starts.
  - **Refrigerants:** the kitchen cold room's R404A is leaking at 33.8 % a year; an old R22 split unit has been scrapped and its gas recovered.
  - **Laundry:** one month of laundry tonnage.
  - **Targets and initiatives:** six targets (some behind); initiatives at every stage – LED retrofit completed, solar phase 2 approved, composting running late, three proposals waiting.
  - **Community:** five contributions (GH₵ 12,160).
  - **Certification and policies:** 13 of 16 mandatory criteria met; four policies in force, one past its review.
  - **Green stay:** one guest has two green nights (100 points); another opted in from the portal.

**Reports:** *Carbon Inventory by Scope (GHG Protocol / HCMI)*, *Waste Register & Diversion*, *F-gas (Refrigerant) Log*, *Green-Stay Nights*, *Green Initiative Register* and *Local Sourcing Spend* (235 reports).

### v6.9 — Talent management: establishment & requisitions, careers page and recruitment pipeline, onboarding and the s. 13 statement, probation, appraisal cycles, discipline & grievances, exits and turnover (Ghana Labour Act 2003, Act 651)

A new **Talent Management** menu, placed before HR & Payroll, with *Talent Dashboard*, *Vacancies*, *Applicants*, *New Starters*, *Appraisals*, *Discipline & Grievances*, *Exits & Turnover*, *Establishment* and *Talent Settings*. Every employee with a login also gets **My Appraisal**. The nav badge counts what needs attention: requisitions and above-range offers awaiting approval, overdue probation reviews and written statements, lapsed query deadlines, leavers past their last day and late appraisals. The HR Manager has every talent right. The FOM, HK supervisor and F&B manager can view and recruit; the chef and chief engineer can view.

- **Establishment** (`/talent/establishment`): the approved headcount per department, with actual staff, open posts and the gap. Each employee's **line manager** (a login) is set here, and appraisals go to them.
- **Vacancies** (`VAC-00001`): a requisition names the department, title, posts, type (permanent, fixed-term, weekly, casual), salary range and reason (new post or replacement).
  - **Minimum wage:** the range cannot start below the national minimum wage (GH₵ 21.77 × 27 a month).
  - **Approval:** a draft needs a justification before it is sent for approval. The approver must be a different person from the requester. A post that takes the department over its establishment needs an approval note, unless it is a replacement. A returned request goes back to draft with a comment.
  - **Status:** open → on hold / filled / cancelled (with a reason, and only once no offer is outstanding).
- **Careers page** (`/careers/{hotel code}`, public, no login): open vacancies marked *advertise* and not past their closing date.
  - **Applying:** the form takes name, phone, e-mail, experience, current employer, cover note and a CV (PDF / Word, 5 MB). **Consent** to keep the details is required (Data Protection Act 2012, Act 843). A honeypot and a rate limit stop bots, and duplicate applications (by phone or e-mail) are refused.
- **Applicants** (`APL-00001`): a pipeline board (applied → screening → interview → offer → hired / rejected / withdrawn). Staff can add applicants from referrals, agencies, job boards and walk-ins.
  - **Closing an application:** rejecting or withdrawing needs a reason, so the hiring record shows why (s. 14, no discrimination in hiring).
  - **Interviews:** phone, video, in person or trade test, with a panel of users. Only panel members can score, and only once the interview has started. Each scorecard rates communication, guest attitude, skills, experience and team fit from 1 to 5, with a recommendation.
  - **Offers** (`OFR-00001`) need a scorecard. The salary must be at least the minimum wage and the start date not in the past. An offer **above the range waits for approval** by a different approver. The offer letter prints.
  - **Hire:** accepting creates the employee (next EMP code, probation end, contract end, line manager), opens the onboarding checklist and fills the post. When the last post is filled, the remaining applicants are closed as *Position filled*.
- **New starters:** the onboarding checklist covers contract (s. 12), uniform, induction, bank, SSNIT, Ghana Card / TIN, a food-handler medical (kitchen and F&B only), buddy training and the written statement. Bank, SSNIT and TIN tick themselves once they are on the employee record.
  - **Written statement of particulars (s. 13, Schedule I):** prints with the pay, hours, leave, probation, notice and discipline terms. It is due **within two months** of starting, and recording it is the only way to tick that item. Statements are tracked for staff who join from the day the module is set up; HR can move that date back in the settings to catch up on earlier starters.
  - **Probation:** confirm, extend (once, by 1–6 months) or end, with a rating. An extension or termination must give reasons.
- **Appraisals:** HR opens a **cycle** (period, self-assessment and review due dates, up to eight common goals). An appraisal is created for every active employee who had joined by the end of the period, with their line manager as reviewer.
  - **Stages:** **self-assessment** → **manager review** → **HR sign-off** → the employee **acknowledges**.
  - **Scoring:** the manager rates each goal and six competencies (guest focus, teamwork, job knowledge, reliability & punctuality, grooming & hygiene, initiative) from 1 to 5. Goal weights must add up to 100 %. The overall score blends goals and competencies (goals 60 %, setting) into a **band** from 1 (unsatisfactory) to 5 (outstanding), and band 2 or lower flags a **performance improvement plan**.
  - **Four eyes:** the reviewer cannot sign off their own review, and no one can sign off their own appraisal. Employees see only their own appraisal, and managers only their team's.
  - **Closing:** a cycle closes once everything is signed off.
- **Discipline** (`DC-00001`): category, severity (minor / major / gross) and the incident.
  - **Query:** a written query with a reply deadline (3 days, setting), then the reply. The case can go ahead once the reply is in or the deadline has passed.
  - **Hearing:** a panel and the employee's representative; notes are required.
  - **Sanctions:** counselling, verbal, written or final warning, suspension (1–30 days) or dismissal. A final warning, suspension or dismissal needs a hearing first.
  - **Dismissal:** only for gross misconduct, or major misconduct with a live final warning. It is decided by an approver who did not raise the case and who confirms that none of the **unfair reasons in s. 63** apply. The decision opens the exit itself.
  - **Warnings** stay on file for 6 / 12 / 12 months (settings) and are shown whenever a new case is opened. Query and outcome letters print.
- **Grievances** (`GRV-00001`): pay, hours, conditions, treatment by a manager, bullying, discrimination, sexual harassment and other. A grievance can be marked **confidential**, and harassment, discrimination and bullying always are; a confidential case is visible only to the person who raised it, HR managers and approvers. It moves from open → investigating → resolved → closed.
- **Exits** (`EX-00001`): resignation, retirement, dismissal, incompetence, probation, redundancy, medical, legal restriction, contract end or death.
  - **Notice (s. 17):** 3+ years' service gives one month; under 3 years, two weeks; weekly-paid, 7 days; casual, the end of the day. The day notice is given counts. A longer contractual or collective-agreement period prevails, and pay in lieu is worked out.
  - **Protected workers:** a worker with a disability gets at least a month from the employer (**s. 51**). The employer cannot end employment during maternity leave (**s. 57(8)**).
  - **Redundancy** needs the Chief Labour Officer and the union notified **three months** before it takes effect (**s. 65**).
  - **Employer exits:** these need the s. 63 confirmation, and all except probation and dismissal need an approver.
  - **Final pay** is due on the last day, or on the next working day when there is no notice (**s. 18**).
  - **Clearance:** handover, uniform, keys (checked against the key register), assets, the salary advance (the outstanding balance is shown), the leave balance and its value, and access.
  - **Completing** needs the last day to have passed and clearance done. It records the leaving date, stops payroll and deactivates the login.
  - **Turnover:** the exit interview (main reason, satisfaction, would recommend, rehire, regretted) feeds the 12-month turnover %, the voluntary % and the leaving reasons.
- **Night audit:** unsuccessful applicants are anonymised and their CVs deleted after the retention period (6 months, setting).
- **Settings:** probation months and notice, query days, offer validity, warning periods, retention, the goals weight, the statement tracking date, and the careers page on/off with its introduction.
- **Throttling fix:** the public careers and table-booking pages now keep separate rate-limit counters for browsing and submitting. Unprefixed limiters share one counter per visitor, so viewing a few pages could use up the booking or application allowance.
- **Demo data:**
  - **Recruitment:** Guest Service Agent ×2 is open with ten applicants: Ama has an offer issued, Kwame an above-range offer awaiting approval, and Esi has an interview on Friday. The Fitness Instructor post was filled by Kofi, the Room Attendant replacement for Lata awaits approval, the Night Auditor was cancelled and a Commis Chef is in draft.
  - **New starters:** Abena's probation review and written statement are overdue; Kofi is mid-onboarding.
  - **Appraisals:** the 2026 mid-year cycle has appraisals at every stage, including Sunil on a PIP.
  - **Discipline and exits:** Sunil has a live verbal warning and Imran an expired written warning. Kiran's query reply is overdue, and Efua has a confidential grievance. Lata is working her one month's notice, and two former employees left: Yaw resigned and Kwesi was dismissed for theft.

**Reports:** *Vacancy Register & Time to Fill*, *Recruitment Funnel & Sources*, *Appraisal Results*, *Discipline & Grievance Register*, *Leavers & Turnover* and *Headcount vs Establishment* (229 reports).

### v6.8 — F&B cost control & menu engineering: versioned recipe cards, yield-aware plate cost, USALI food & beverage cost statement, theoretical vs actual variance, menu-engineering matrix, price control, yield tests and staff meals

A new **F&B Cost Control** menu, placed after Food Safety, with *Cost Dashboard*, *Recipe Cards & Plate Cost*, *Menu Engineering*, *Cost Statement*, *Usage Variance*, *Menu Pricing*, *Yield Tests*, *Staff Meals* and *Cost Settings*. Its nav badge counts what needs attention: dishes with no recipe, unapproved card versions, dishes over their target cost % and pending yield tests. It adds a new role, **Cost Controller**. Chefs gain costing rights, and the F&B manager gains pricing rights.

- **Recipe cards** (`/fbc/recipes`): every dish has a card with its ingredient lines, method, allergens, batch size, portion, a picture note and a **target cost %** (5–80, falling back to the food or beverage target).
  - **Quantities are net** (the plate). The **gross** quantity is net × 100 / ingredient **yield %**, and that is both what is costed and what POS settlement takes out of stock. So a 74 % mutton yield makes the dish cost more and draws more stock.
  - **Unit cost basis:** *last purchase* or *weighted average* from stock movements (setting).
  - **Versioning:** changing the lines bumps the version and clears the approval. The approver must be a **different person** from the last editor and hold pricing rights. The card prints for the kitchen.
- **Cost statement** (`/fbc/statement`), laid out the USALI 11th-edition way and split into **Food** and **Beverage**:
  - **Classification:** beverage means **alcoholic drinks only**. Coffee, tea, juice, soft drinks and water are *food* revenue and *food* cost. Menu categories and item categories carry an `fb_class`, set automatically by name (Bar, wine, beer, spirits, liquor, cocktails → beverage) and editable in Masters.
  - **Figures:** opening stock + purchases ± transfers − closing stock = **consumption**, plus waste and stock-take adjustments. Then **less staff meals** and **less officers' checks / comps** (at plate cost) gives the **cost of sales**, against net revenue for the cost %.
  - **Theoretical vs actual:** the theoretical cost comes from the recipes of what was sold, costed per **ingredient** class, so the lime in a mojito counts as food cost and the rum as beverage cost. Variance = cost of sales − theoretical.
  - **Trend:** a daily food-cost % trend.
- **Usage variance** (`/fbc/variance`): per ingredient, the ideal usage (recipe × sales) against the actual issues, consumption, waste and counted shortages, in quantity and cedis, worst first.
- **Menu engineering** (`/fbc/menu-engineering`): the Kasavana–Smith matrix per outlet and category.
  - **Popularity:** a dish is popular at ≥ 70 % of its fair share (1 ÷ number of dishes, setting).
  - **Profitability:** contribution margin above the weighted average.
  - **Quadrants:** **Star** (keep), **Plowhorse** (re-engineer the cost or reprice), **Puzzle** (promote or reposition) and **Dog** (replace), each with a suggestion.
  - **Chart and totals:** a scatter chart, plus the menu mix %, the CM and the totals.
- **Menu pricing** (`/fbc/pricing`): the current price, plate cost, cost %, CM and a **suggested price** (plate cost ÷ target, rounded up to GH₵ 5, setting) for each dish.
  - **Price-change rules:** a change needs a reason, must stay above the plate cost and may move at most 50 %. Each change is logged with the old and new cost %.
  - **Cost creep:** each dish is compared with its plate cost *n* days ago (default 60, from nightly snapshots). A rise above 5 % is flagged.
- **Yield tests** (`YT-00001`, `/fbc/yields`): a butcher's test records the gross weight, trim, cooking loss and usable weight, which must add up to the gross weight. The yield % and **usable cost per unit** are worked out. Applying a test sets the item's yield, and every recipe using that item is re-costed at once.
- **Staff meals** (`/fbc/staff-meals`): one record per date and meal, with covers and a cost (per-cover rate or actual). It can be logged up to 31 days back and is credited out of the food cost of sales.
- **Waste:** dish waste in Food Safety is costed at the yield-aware plate cost.
- **Nightly:** the night audit stores a plate-cost snapshot for every costed dish.
- **Ledger fix:** stock movements are now valued to the cent the same way in the GL and in the inventory control. PHP 8.4's `round()` no longer pre-rounds, so values like 0.28499… could drift by a cent from the database's exact decimal maths. `pms:gl-sync` re-posts any affected days.
- **Settings:** food target (shared with inventory), beverage target, cost basis, popularity %, creep % and days, staff-meal cost per cover, and price rounding.
- **Demo data:**
  - **Recipes:** all 30 dishes have recipes, 3 of them still unapproved. There are 28 days of POS sales (1,820 portions), a bar stock take with liquor shortages, 56 staff meals and 4 applied yield tests (a lime test is pending).
  - **Menu and costs:** Butter Chicken is a star and Kingfisher a plowhorse. Mutton Rogan Josh, Johnnie Walker and Kingfisher are over target. Mutton and tilapia show cost creep.
  - **Bar store:** the bar outlet now draws from the Bar Store.
  - **Login:** `costctl` / `Pass@123`.

**Reports:** *Recipe Costing Register*, *Menu Engineering Analysis*, *Daily Food & Beverage Cost Flash*, *Ideal vs Actual Usage Variance* and *Menu Price Change Log* (223 reports).

### v6.7 — Health, safety & fire: fire-equipment register with GNFS tags, inspection rounds, permits to work, Act 328 accident register, 5 × 5 risk assessments, drills and wardens

A new top-level **Health & Safety** menu with *HSE Dashboard*, *Fire Equipment*, *Inspection Round*, *Permits to Work*, *Accidents & Near Misses*, *Risk Assessments*, *Drills & Wardens* and *HSE Settings*. Its nav badge counts what is unsafe or unlawful right now: defective, overdue and expired equipment, permits past their end, accidents not yet notified, overdue risk reviews and shifts overdue a fire drill. It adds a new role, **Health & Safety Officer**. Existing roles gain HSE rights: engineering can issue permits; supervisors in housekeeping, kitchen, F&B, security and banquets can report and check; HR and Quality can view.

- **Fire & life-safety equipment** (`/hse/equipment`): 17 types, each with its own checklist, check interval and service interval. Types include CO₂, powder, foam and wet-chemical extinguishers, fire blankets, hose reels, hydrants, the alarm panel, detectors, call points, emergency lights, fire doors, sprinklers, first-aid kits, AEDs, the LPG bank and eyewash stations.
  - **Register details:** codes such as `FE-001`, location, floor, size, serial, the **GNFS equipment tag** (AFCAS scannable tagging, Aug 2026), install date and **expiry** (cylinder life, kit contents, AED pads).
  - **State:** each item shows *in order*, *due soon*, *overdue*, *defective* or *expired*. "Due soon" scales with the interval: 7 days for monthly items, 1 day for weekly ones.
- **Checks:**
  - **Visual checks:** tick each checklist item. Anything left unticked, or a typed fault, fails the check. A failed check marks the item defective and raises **one engineering ticket**. The ticket category follows the type (electrical, plumbing, carpentry, general); alarm panels, sprinklers, LPG, AEDs and hydrants get urgent priority. A later pass puts the item back in order.
  - **Contractor services:** recorded by a safety manager, with the contractor and certificate number. A pass sets the next service date.
  - **Inspection round** (`/hse/round`): a floor-by-floor sheet with *Pass / Fail / —* per item and a note. Blank rows are skipped.
- **Permits to work** (`PTW-00001`, `/hse/permits`) for hot work, work at height, confined spaces, electrical isolation (LOTO) and roof / façade work. Each type has a maximum duration (8 or 12 h) and its own precautions.
  - **Four-eyes:** an **authorised issuer** (hse.permit) who is not the requester must confirm **every** precaution.
  - **Type rules:** hot work needs a named **fire watch** and records any **detectors isolated**. A confined space needs an **O₂ reading between 19.5 and 23.5 %**. Electrical work needs the **isolation points**.
  - **Close-out:** confirm the area is safe, the 60-minute fire watch was kept and the isolated detectors are back on. A permit with isolated detectors cannot simply be cancelled. Live permits past their end are flagged.
  - **Printing:** the permit prints for the job site.
- **Accident & near-miss register** (`ACC-00001`, `/hse/accidents`): records the kind (injury, near miss or dangerous occurrence), who was involved (staff, contractor, guest or visitor), the injury type and body part, the outcome, days lost, the return to work, hospital and witnesses. It links to a room, a stay or a security incident.
  - **When a notice is required:** under the **Factories, Offices and Shops Act 1970 (Act 328)**, a notice is needed for a death, for a worker (staff or contractor) disabled for **more than 3 days** (s. 10), and for every **dangerous occurrence** (s. 11): fire, explosion, collapse, lift or hoist failure, machinery, or pressure vessel. This is worked out automatically.
  - **The notice:** it prints for the Factories Inspectorate (office configurable) and is recorded with its reference. A death after the first notice clears it, so the death is notified again.
  - **Investigation:** records the root cause and actions, and can raise a maintenance ticket.
  - **Close-out rules:** an accident cannot be closed before its investigation, its notice, and a return-to-work date for lost-time injuries.
  - **Dashboard figures:** days since the last lost-time injury and **LTIFR** per million hours worked.
- **Risk assessments** (`RA-00001`, `/hse/risks`): records the activity, area, department, hazard, who is at risk and the controls. Likelihood × severity is rated on a **5 × 5** matrix before and after controls.
  - **Bands:** 1–4 low, 5–9 medium, 10–14 high, 15–25 very high.
  - **Rules:** the residual risk can't exceed the initial risk. A **very high** residual is refused. A **high** residual needs further actions and waits for **acceptance by a different manager**.
  - **Reviews:** each assessment has an owner and a review date; the owner or a safety manager can review it. Assessments can be archived.
  - **Dashboard:** shows a live residual heat map.
- **Drills & wardens** (`FDR-00001`, `/hse/drills`):
  - **Drills:** fire evacuation (full or partial), table-top, bomb threat, medical and power / lift rescue, per shift. Each records the alarm and building-clear times against a target, the roll call, guests evacuated, GNFS attendance, observations and improvements. A drill passes only if the building is clear inside the target *and* everyone is accounted for.
  - **Drill interval:** each shift must evacuate every *n* days (default 180).
  - **Wardens:** chief, floor and first-aider wardens and the fire team, per shift, with a training expiry. Coverage shows the gaps (no chief warden, no floor warden, no *trained* first aider).
- **Settings:** drill interval and target, risk review interval, hours per employee for LTIFR, Inspectorate office, automatic tickets, and whether the issuer must differ from the requester.
- **Demo data:**
  - **Equipment:** 45 items with 3 months of checks. 3 are overdue, the 2nd-floor emergency light is defective with a ticket, the kitchen first-aid kit has expired, and the kitchen fire door failed and was repaired. The hose reels, hydrant and sprinklers are due their annual *Fire Guard Ghana Ltd* service.
  - **Drills and wardens:** 7 drills; the night shift is overdue. 10 wardens; the night shift has no first aider.
  - **Risks and permits:** 13 risk assessments (one review overdue, one awaiting acceptance). 6 permits, including live hot work with detectors isolated and an electrical permit past its end.
  - **Accidents:** 13 in the register. The last lost-time injury was 18 days ago, and a kitchen tandoor fire three days ago has not been notified yet.
  - **Login:** `hse` / `Pass@123`.

**Reports:** *Fire Equipment Register*, *Fire Equipment Inspection Log*, *Accident & Near-Miss Register*, *Permit-to-Work Register*, *Fire Drill Log* and *Risk Register* (218 reports).

### v6.6 — Parking & valet: car park zones, valet tickets with key tags and damage record, guest QR "bring my car", tariffs, validation, room-folio posting, permits and gate lookup

A new top-level **Parking & Valet** menu (badge: late valet requests, guest vehicles still parked after check-out, overstays and expiring permits) with *Parking Desk*, *Valet Board*, *Tickets*, *Vehicles*, *Permits*, *Zones* and *Parking Settings*. It adds a new role, **Valet / Parking Attendant**.

- **Zones** (`/parking/zones`): each zone has a code, type (*guest self-park*, *valet compound*, *visitors*, *staff & permits*, *mixed use*), capacity, covered flag and EV points. The system refuses to park a car in a full zone. Staff zones take permit holders only, and the valet compound takes valet cars only. The desk shows live occupancy per zone and the percentage full.
- **Tickets** (`PK-00001`): a vehicle is parked as an **in-house guest** (linked to the stay; in-house guests and today's arrivals only), a **visitor** or **staff / permit**.
  - **Details recorded:** plate (normalised, so `gc 5502 23` = `GC 5502-23`), make, model, colour and bay.
  - **Valet cars also get:** a **key tag** (unique among parked cars), a **damage record** by panel, the fuel level and the runner. Every plate builds a **vehicle history**.
  - **Printable ticket:** a claim stub with a **QR code**.
- **Valet workflow** (`/parking/valet`):
  - **Requests:** a guest can request the car by scanning the ticket QR (public `/valet/{token}`, no login; it shows only the plate and the status). The desk can also request it after a phone call.
  - **Board:** requests are listed oldest first. They turn **late** after the SLA (10 min). The runner marks the car *at the door*, then hands it over or re-parks it.
  - **Key board and stats:** the key board lists every key on a hook. The desk shows the average retrieval time and the percentage delivered within the SLA over 30 days.
- **Charges:**
  - **Visitors:** billed on the **tariff**: free minutes, then an hourly rate up to a **daily maximum**, plus a valet fee.
  - **Validation:** restaurants, spa or meetings can **validate** up to 3 free hours.
  - **Lost ticket:** a lost-ticket fee applies.
  - **In-house guests:** billed a **nightly rate** (self-park free by default, valet GH¢ 50). The **night audit** posts it to the room folio once per car per night. Any nights not yet posted are charged when the car leaves.
  - **Settlement:** in-house guests settle to the room. Everyone else pays at the exit by cash, card, MoMo or bank, which creates a closed walk-in invoice.
  - **Tax, GL and waivers:** all lines carry the **Ghana VAT / NHIL / GETFund** parking tax group. They post to **4075 Parking revenue** (USALI Other Operated Departments). Waiving a charge needs a parking manager and a reason.
- **Front desk link:** check-out warns when the guest still has a car in the car park or with the valet (`#parkingWarn`). The desk lists "checked out – car still here".
- **Gate lookup:** security or the desk types a plate to see whether the car is parked now, any valid permit, a **ban** (with reason) and recent visits.
- **Permits** (`PP-00001`): staff, tenant / lessee, contractor, club member or other permits. Each has a vehicle, holder, zone, validity and fee, and can be **revoked** with a reason. Permits expiring within 7 days are flagged.

**Reports:** *Parking Ticket Register*, *Parking Revenue by Day*, *Car Park Occupancy by Zone*, *Valet Retrieval Performance* and *Parking Permit Register* (212 reports).

**Upgrade:** `php artisan migrate && php artisan pms:upgrade` (tariff defaults, four zones, grants and the valet role).

### v6.5 — Quality assurance & brand standards: standards library, audit checklists, mystery guest, weighted scoring, corrective actions with four-eyes verification

A new top-level **Quality Assurance** menu (badge: overdue corrective actions, late audits and fixes waiting for verification) with *QA Dashboard*, *Audits*, *Corrective Actions*, *Standards*, *Checklists* and *QA Settings*.

- **Standards library** (`/qa/standards`): each brand / service standard has a code (e.g. `FO-03`), area, department, **weight 1–5 points**, a *how to check* note, an optional **SOP link** (v6.0 library) and a **critical** flag: food safety, fire exits, ID checks and similar, where one failure fails the whole audit. Standards can be retired (inactive) without losing history. The page shows each standard's fail rate over the last 180 days.
- **Checklists** (`/qa/checklists`): a checklist groups standards by area, with a type (*internal audit*, *department walk-through*, *mystery guest*, *brand / LQA audit*), department, **frequency** (every N days, or on demand) and its own pass mark. Each checklist shows its points, critical items, audits done, average score and **next due date**: *on track*, *due* (not yet booked), *booked* or *late*.
- **Audits** (`QA-00001`):
  - **Scheduling:** internal audits are scheduled from today. Mystery-guest and brand reports can be entered up to 90 days after the visit, naming the external assessor. The auditor must be a member of staff allowed to audit. The checklist is **copied into the audit when it is scheduled**, so later edits to the library never change an audit.
  - **Conducting:** the auditor (or a quality manager) marks each standard *Pass / Fail / N/A* with a note, saves progress (*in progress*) and then **completes**. Every item must be answered, and critical or 4–5-point failures need a note as evidence.
  - **Scoring:** **score = points of passed standards ÷ points of standards checked** (N/A excluded). The audit passes when the score reaches the pass mark and, with the auto-fail setting on, no critical standard failed.
  - **Repeat findings:** a standard that also failed on the previous audit of the same checklist is flagged as a repeat.
  - **After completion:** the department head **acknowledges** the result (never the auditor). The audit page shows the checklist's score history, and a **printable report** has signature lines. Managers can cancel an audit with a reason.
- **Corrective actions** (`CA-00001`):
  - **Raising:** every failed standard raises an action automatically for the standard's department. Priority is **critical** for critical standards, **high** for repeat findings and normal otherwise. It is due in 2 days (critical) or 14 days (settings). Walk-round findings can also be raised by hand.
  - **Owner's steps:** a quality manager assigns the owner, due date and priority. The owner records the **root cause** and **action plan**, starts the work and marks it **done** with what was done. Physical fixes can raise a linked **engineering ticket** (v1.x maintenance), with priority and due date taken from the action.
  - **Verification (four eyes):** a quality manager **verifies** the fix on the floor, but never the person who owns or completed it. If the fix does not hold, **not accepted** reopens it with a note and counts the reopening.
  - **Tabs:** open, overdue, to verify, mine, unassigned, closed.
- **QA dashboard** (`/qa`):
  - **KPIs:** average score against the target, pass rate (plus the mystery-guest average), findings (critical / repeat), open and overdue actions, actions to verify and the on-time closure rate.
  - **Department scores:** average, change against the previous period, last score, failed audits and open / overdue actions.
  - **6-month score trend**, **most-failed standards** (fail rate, repeats, open actions) and the **checklist schedule**, with one-click scheduling of checklists that are due.
  - Recent results, upcoming audits and overdue actions.

**Permissions:** `qa.view` (view, and work corrective actions you own), `qa.audit` (schedule and conduct audits, raise actions), `qa.manage` (standards, checklists, assign / verify / cancel, settings).
- The new **Quality Manager** role gets all three plus the SOP library, feedback, food-safety and housekeeping views and reports. Demo user `qa` / `Pass@123` (Esi Quaye).
- The Front Office Manager, Housekeeping Supervisor, F&B Manager and Chief Engineer can view and audit. The Chef can view.

**Settings:** default pass mark (85 %), target score (90 %), critical auto-fail (on), corrective actions due in (14 days), critical actions due in (2 days).

**Demo data:**
- 43 standards (5 critical, SOP-linked) and 7 checklists.
- 22 audits completed over five months through the scoring service: 3 critical failures, 7 repeat findings, 37 corrective actions. The actions are in every state: most verified (some closed late), done-to-verify, reopened, in progress, overdue and one unassigned, plus a broken A/C handed to engineering.
- A room inspection in progress today, a fire & safety audit 5 days late, a restaurant audit booked, a cancelled spa audit, and the Front Office checklist due.

**Reports:** *QA Audit Register*, *QA Scores by Department*, *Standards Failure Analysis*, *Corrective Action Log* and *Audit Schedule Compliance* (212 reports).

`tests/Feature/Phase55Test.php` (5 tests, 389 assertions) and `tests/e2e/phase55.py` (six Playwright journeys; screenshots `docs/screenshots/p55_*.png`). The full suite is **530 tests, 18,583 assertions, all passing** (v6.5). Upgrade: `php artisan migrate && php artisan pms:upgrade`.

### v6.4 — Central reservations & lost business: group availability & booking, enquiry log with denials / regrets, sister-hotel referrals, guest lookup, unconstrained demand

A new top-level **Central Reservations** menu (badge: open enquiries due for follow-up) with *CRO Desk*, *Enquiries*, *Guest Lookup*, *Lost Business*, *CRO Performance* and *CRO Settings*. It serves a group reservations office and each hotel's own reservations desk.

- **Selling scope:** a user whose account belongs to the hotel group (a group reservations agent or group administrator) searches and sells **every active hotel of the group**; everyone else sells only their own hotel. A request for any other property is refused. Agents do not need to sign in to the sister hotel: each search and booking there runs with that hotel as the tenant, which is always restored afterwards.
- **CRO desk** (`/cro`): month-to-date enquiries, conversion against target, CRO room revenue, lost value (denials / regrets) and follow-ups due. **One search** (arrival, departure, rooms, adults, children, *caller asked for*) checks every hotel in scope at once:
  - Each room type shows free rooms (after blocks, contract allotments and long-stay leases), the average nightly rate and the stay total after seasons and revenue management, cheapest first.
  - Room types that cannot be sold show why: *sold out*, *only N left*, *closed for sale*, *minimum stay N nights* or *sleeps N adults*.
  - The hotel the caller asked for is marked; sister hotels offer **Refer & book**.
  - *Log denial / regret* records a turnaway straight from the results.
- **Booking** from the desk:
  - **Checks:** arrival on or after that hotel's business date, stop-sell, minimum stay, adults per room, and a **locked availability re-check**, so two agents cannot sell the last room twice.
  - **Guest profile:** matched at that hotel on e-mail (or phone + first name), otherwise created. Blacklisted guests are refused unless a manager books. Only `cro.manage` can override the rate.
  - **Reservation:** booked *confirmed* or *tentative* under the business source **Central Reservations**, followed by the hotel's own confirmation e-mail / SMS.
  - **Referral:** booking at a sister hotel uses the source **Sister-hotel referral**, with purpose *Referred by …*. The asking hotel's enquiry closes as **referred**, with the reason it could not keep the guest (usually a denial), the sister's reservation number and the room revenue kept in the group. The receiving hotel gets a linked **booked** enquiry on the channel *Sister-hotel referral*. Both hotels' activity logs record it.
- **Enquiry log** (`ENQ-00001`):
  - **Details:** caller, phone, e-mail, channel (phone, e-mail, WhatsApp, walk-in, web chat, travel agent, social DM), company, stay (arrival from today, 1–30 nights, up to 20 rooms), party, room type asked, rate quoted (default: the hotel rate), call length and notes.
  - **Open** enquiries get a follow-up time (24 h by default) and **contacts** (call made / received, e-mail, WhatsApp, SMS, note), each of which can set the next follow-up.
  - **Closing:** *Find rooms & book* opens the desk pre-filled. Otherwise *Close as lost* as a **denial** (the hotel could not offer: sold out, requested room type full, minimum stay / closed to arrival, group too large, party too large for the room) or a **regret** (the guest declined: price, location, room / facilities, changed plans, **booked another hotel** with the competitor picked from the v6.3 competitive set, deposit terms, no response, only comparing).
  - **Reopen and lapse:** lost enquiries can be reopened while the stay is in the future. A denial can still be referred to a sister hotel later. Open enquiries **lapse** automatically (lost, regret, *no response*) once the arrival date passes or after 14 days (`pms:cro-expire`, daily 06:20).
  - **List:** filters by status, denial / regret, agent, dates and text; follow-ups due are highlighted.
- **Guest lookup** (`/cro/guests`): by name, phone, e-mail or reservation number across every hotel in scope. Shows the hotel, VIP / blacklist flags, preferences, stays, nights, revenue, last stay and upcoming bookings, plus *New booking* (desk pre-filled with the profile). Privacy-erased profiles are never shown.
- **Lost business** (`/cro/lost`):
  - Lost value, denials and regrets (with room nights) and revenue kept in the group.
  - **Reasons** with share, room nights, value lost and how many were referred instead.
  - **Competitors** that won our regrets.
  - **Unconstrained demand** for the next 21 nights: capacity, rooms on the books, rooms denied and regretted, occupancy and **unconstrained occupancy**. Nights above 100 % are highlighted: that is demand revenue management should price for, and overflow to refer.
- **CRO performance** (`/cro/performance`): per **agent** and per **channel**: enquiries, booked, referred, denials, regrets, open, **conversion** (booked ÷ closed, coloured against the target), booked / referred / lost revenue, average call length. **Group retention** = (booked + referred) ÷ closed. Referrals between sister hotels are listed with room nights and revenue kept.

**Permissions:** `cro.view`, `cro.book`, `cro.manage`.
- The new **Reservations Agent (CRO)** role gets view + book plus reservations view / create / edit and guest view. Demo user `cro` (Yaw Boateng) belongs to the hotel group, so he sells both the Grand Hotel and the Lake Resort.
- Receptionists get view + book (own hotel); the Front Office Manager gets all three. Sales & Marketing and the Business Development Manager get view.

**Settings:** default follow-up (24 h, 1–168), open enquiries lapse after (14 days, 1–90), conversion target (40 %).

**Reports:** *Reservations Enquiry Register*, *Lost Business (Denials & Regrets)*, *Unconstrained Demand*, *CRO Agent Performance* and *Sister-Hotel Referrals* (202 reports).

**Fix:** the Business Development pages' default date ranges (e.g. market mix year to date) collapsed to today when the page was opened without dates, because an empty date parsed as "now". Empty dates now fall back to the intended default.

`tests/Feature/Phase54Test.php` (6 tests, 233 assertions) and `tests/e2e/phase54.py` (six Playwright journeys; screenshots `docs/screenshots/p54_*.png`). The full suite is **525 tests, 18,214 assertions, all passing** (v6.4). Upgrade: `php artisan migrate && php artisan pms:upgrade`.

### v6.3 — Business development: competitive set & rate shops, STR-style benchmarks, market mix, strategic partnerships, initiatives with business cases

A new top-level **Business Development** menu (badge: business cases awaiting a decision + overdue initiative milestones + active partnerships past their end date) with *BD Dashboard*, *Competitive Set*, *Market Mix*, *Partnerships*, *Initiatives* and *BD Settings*.

- **Dashboard** (`/bd`): last month's **RGI** (with MPI / ARI), our rate position over the next 14 days, partnership production year to date, committed initiative revenue, the top source market; a 6-month benchmark table, the segment mix vs last year, business cases to decide, milestones due and source markets; alerts (approvals, overdue milestones, lapsed / expiring partnerships, reviews due, stale rate shops, last month's benchmark missing).
- **Competitive set** (`/bd/compset`): hotel profiles (stars, positioning, rooms, distance, location, website, strengths & weaknesses), at most 10 active; inactive hotels keep their history. **Rate shops** for today or a future stay date (up to a year ahead) per channel: competitor rates or *sold out*, our rate defaulting to the live BAR of the benchmark room type (after revenue management). Re-shopping a date and channel replaces it. The 14-day grid shows every hotel, the comp-set average, our gap in % and our price rank (1 = dearest); weekends shaded, cheaper / dearer competitors coloured. Shops go stale after 7 days.
- **Benchmarks:** monthly comp-set occupancy and ADR (STR / CoStar, comp-set exchange or estimate) for completed months, optionally with **our own figures from the same report** ("My Property"); otherwise our occupancy / ADR / RevPAR come from the PMS closed days. **MPI** = our occupancy ÷ comp occupancy × 100, **ARI** = ADR index, **RGI** = RevPAR index (100 = fair share); the set's fair share of rooms is shown too.
- **Market mix** (`/bd/market`): stays arriving in any period by **source market** (guest nationality), **segment** (groups, corporate, OTA, direct, travel trade) or **business source**: bookings, nights, ADR, room revenue, share and growth vs the same dates last year.
- **Partnerships** (`PTR-00001`): partner type, agreement, commission %, contact, term, review date, targets (room nights / revenue), terms. Production (bookings, nights, room revenue, commission, net, % of target, month by month, last year, recent stays) is **credited through a business source, promo code or company**; a tracker already used by another live partnership is refused so nothing is counted twice. Prospect → negotiating → **active** (needs start and end dates and a BD approver) → expired → **renewed** (new end date) or terminated (reason; the end date moves to today). Flags: past end date (*lapsed*, counts in the badge), ending within the notice period (60 days), review due, no tracker.
- **Initiatives** (`BDI-00001`): category (new segment, source market, revenue stream, product / package, partnership, expansion), target market, owner, linked partnership, description, target launch. **Business case:** investment, annual revenue and running cost, horizon (1–10 years) → net contribution, margin, **payback in months, NPV at the hurdle rate (20%), ROI**, a discounted cash-flow table and a printable business case with signature lines. **Stage gate:** idea → assessment → business case → *submit* (needs a description, revenue, a future launch date and at least one milestone) → **decision by another approver** (approve / return with a note / reject; never your own) → implementation → **live** (all milestones done; live date = today) → closed (note). Approvers can shelve (reason) and revive. The approved figures are frozen. Milestones (due today or later, owner, done by the owner or the initiative owner, overdue ones in the badge); **monthly results** (revenue, cost) once live, compared with the business case pro rata. Board (six lanes) and portfolio list.

**Permissions:** `bd.view`, `bd.edit`, `bd.approve`, `bd.manage`. The new **Business Development Manager** role gets all of them plus sales edit and digital / CRM / feedback / revenue view (demo user `bizdev` – Akosua Darko); Sales & Marketing gets view + edit; the Owner's Representative view + approve. Only managers change the competitive set and settings. **Settings:** hurdle (discount) rate (20%, 0–60), rate shops stale after (7 days), partnership renewal notice (60 days), benchmark room type (default: the cheapest).

**Reports:** *Comp-Set Benchmark (MPI / ARI / RGI)*, *Rate Shop*, *Initiative Portfolio*, *Partnership Production* and *Market Mix* (197 reports).

`tests/Feature/Phase53Test.php` (6 tests, 315 assertions) and `tests/e2e/phase53.py` (six Playwright journeys; screenshots `docs/screenshots/p53_*.png`). Phase53Test: 6 tests, 315 assertions (full-suite count under v6.4). Upgrade: `php artisan migrate && php artisan pms:upgrade`.

### v6.2 — Digital marketing: channels & listing health, social content calendar with approval, paid campaigns & CSV import, tracked links with booking attribution, influencers

A new top-level **Digital Marketing** menu (badge: posts awaiting approval + approved posts past their slot + creator content overdue) with *Digital Dashboard*, *Content Calendar*, *Paid Campaigns*, *Tracked Links*, *Channels & Listings*, *Influencers* and *Digital Settings*.

- **Dashboard** (`/digital`, any month): followers and growth across the social profiles, reach and engagement rate, posts published, paid spend + creator fees against the `digital` line of the marketing budget, link clicks, **direct bookings / nights / room revenue from digital** with ROAS, average listing health and rating; a 6-month trend, top posts, upcoming slots, campaigns and channels; alerts (approvals, missed slots, audits due, campaigns over budget, overdue creators, channels without this month's figures).
- **Channels & listings:** social profiles, listings (Google Business Profile, Tripadvisor, Booking.com, Expedia, the website) and ad accounts. Social channels get **monthly figures** (followers, reach ≤ impressions, engagements, clicks; the latest month updates the follower count, older months never overwrite it). Listings get an **8-point health audit** with the current rating and review count; an audit is due when never done or older than the audit interval (90 days).
- **Content calendar** (`/digital/calendar`): posts on one or more social channels with caption (2,200 characters; **280 when X is included**), media link, content pillar, campaign and tracked link, scheduled in the future. **Draft → review → approved / changes requested (note required) → published** (optional live-post link) → results (reach, engagements, clicks, engagement rate); cancel any time before publishing. **Four eyes:** approvers (and managers) can never approve their own post; authors edit only their own drafts. Approval can be switched off (submitting then schedules directly). An approved post still unpublished an hour after its slot is flagged *slot missed*.
- **Paid campaigns** (`DMC-00001`): objective, ad channel, **UTM campaign tag** (unique; renaming it re-tags the campaign's links), dates, budget, target ROAS, optional CRM promo code. Draft → active ⇄ paused → ended (locked). **Daily results** (impressions, clicks ≤ impressions, spend, platform conversions and value) by hand or by **CSV import** of Meta Ads Manager / Google Ads / TikTok exports: title preambles, BOMs, `;` / tab delimiters, thousands separators, `dd/mm/yyyy` dates and total rows are handled; re-importing updates days instead of duplicating; row errors are listed. Stats: CTR, CPC, CPM, platform ROAS, **PMS bookings / revenue through the campaign's links, PMS ROAS, CPA**, budget pacing; spending past the budget raises an alert.
- **Tracked links** (`/go/{code}`, public, rate-limited): destination (default: the booking engine), required `utm_source` / `utm_medium`, `utm_campaign` from the campaign, optional `utm_content`, custom or generated short code (frozen once clicked), QR code (SVG). A click is logged (device, referring site, keyed IP hash, never the raw IP) unless it comes from a bot / link-preview crawler or repeats from the same visitor within 30 seconds; a paused link still redirects (printed QR codes keep working) but tracks nothing. Links into the booking engine carry `dl=<code>`, and a cookie remembers the link for the **attribution window** (30 days). A booking made on the booking engine is credited to that link (never to another property's link), and so to its campaign / creator.
- **Influencers** (`INF-00001`): creator, platform, handle, followers, niche, contact, stay dates, content due date, deliverables, cash fee + complimentary value, linked reservation and tracked link. Proposed → **agreed** (deliverables, stay and due date required) → **hosted** (stay started) → **delivered** (at least one content link; reach, engagements, 1–5 rating) or cancelled with a reason. Hosted creators past their due date are flagged. Stats: cost, CPE, CPM, engagement rate, bookings and ROI through their link.
- **Marketing budget:** paid spend (by day) and creator fees (by stay month, once hosted) are the *actual* of the v6.1 budget's `digital` line.

**Permissions:** `digital.view`, `digital.content`, `digital.approve`, `digital.manage`. Sales & Marketing gets all; the new **Digital Marketing Executive** role gets view + content (posts, links, channel figures, audits, creator results) plus sales / CRM / feedback view. **Settings:** approval required (on), attribution window (30 days, 1–90), listing audit interval (90 days, 7–365).

**Reports:** *Social Media Performance*, *Paid Media Performance* (platform vs PMS ROAS, highlighted below target), *Tracked Link Attribution*, *Content Calendar & Approvals* and *Influencer Collaborations* (192 reports).

`tests/Feature/Phase52Test.php` (6 tests) and `tests/e2e/phase52.py` (six Playwright journeys; screenshots `docs/screenshots/p52_*.png`). Phase52Test: 6 tests, 245 assertions (full-suite count under v6.3). Upgrade: `php artisan migrate && php artisan pms:upgrade`.

### v6.1 — Sales & marketing: accounts & contacts, opportunity pipeline, sales diary, targets & production, marketing calendar / budget / collateral

**Sales & Marketing → Sales Dashboard** (`/sales`, badge = my overdue activities + my deals past their expected close date): open pipeline, weighted forecast, won this month / year, 90-day win rate; pipeline by stage; agenda (overdue + next 7 days); deals closing in 30 days; top accounts year to date; stale deals, key accounts going quiet, contact birthdays. Tabs switch between the whole team, *Mine* and each salesperson.

- **Accounts** (`/sales/accounts`) extend the companies master with **tier** (key / major / standard), **sales status** (prospect / active / dormant / lost), industry, city, website, potential room nights and sales notes. New prospects get *no direct billing* and a zero credit limit – credit control opens them. The account page shows YTD room nights & revenue vs last year, 12-month production chart (striped = on the books), credit exposure, opportunities, the activity timeline, contacts (one **primary**, mirrored to the account's contact on statements; contacts with history are deactivated, not deleted) and rate contracts.
- **Opportunities** (`OPP-00001`): account, contact, type (corporate agreement, agency, group, MICE, social, long stay), source, expected close (not in the past), dates, room nights (required behind rooms revenue), rooms / F&B / other revenue, competitors. Stage defaults: prospect 10 %, qualified 25 %, proposal 50 %, negotiation 75 % (overridable); moving to proposal or winning needs revenue. **Won** → closed today, 100 %, a prospect / dormant account becomes *active*; corporate / agency / long-stay deals **draft the rate contract** (code from the account + year, discount = the account's or 10 %, validity = the deal dates or a year, room-night target, agency commission) – groups and events link their **room block** / **banquet event** (same account only). **Lost** needs a reason (competitor / other need details) and cancels the deal's planned activities. Closed deals are frozen until reopened (into negotiation; refused while linked). Printable **proposal letter** with Ghana taxes wording.
- **Sales diary** (`/sales/activities`): plan (today or later) or log (not in the future, outcome required) calls, visits, site inspections, meetings, e-mails, entertainment (with spend) and tasks; complete with an outcome and an optional follow-up; cancel with a reason.
- **Ownership:** `sales.edit` users change only their own accounts (or unassigned ones, which they then claim), opportunities and activities and cannot assign work to others; `sales.manage` works everything, reassigns, sets targets and settings. Only this property's users can own business.
- **Targets** (`/sales/targets`): room nights, room revenue and activities per salesperson per month vs production of the accounts they manage (actual + on the books) and business won.
- **Production** = company-linked stays overlapping the period (checked in / out) with the room revenue posted to their folios, plus reserved stays from the business date × rate.
- **Marketing calendar** (`/sales/marketing`, `MKT-00001`): trade shows, roadshows, sales blitzes, fam trips, sponsorships, advertising, PR, hosted events, direct mail – segment, source market, budget, target leads; planned → confirmed → **closed with actual cost, leads and results** (not before it starts) or cancelled (reason, cost already incurred). Opportunities pick their source event, giving **pipeline, revenue won, cost per lead and ROI** per event. Month calendar + year plan.
- **Marketing budget** (`/sales/marketing-budget`): year × month × line (event types, client entertainment, digital) vs actual (closed events by start month, diary entertainment, digital spend) and committed (planned events).
- **Collateral library** (`/sales/collateral`): brochures, rate cards, fact sheets, presentations, menus… as an uploaded file (PDF / images / Office / MP4 / ZIP, 20 MB, private storage) or a link, with language, version, valid-until (expired flagged) and download counts.

**Permissions:** `sales.view`, `sales.edit`, `sales.marketing`, `sales.manage`. Sales & Marketing gets all; Banquet Manager view + edit; Front Office Manager and Owner's Representative view. **Settings:** stage probabilities (must rise stage by stage), stale-deal days (21), dormant-account days (90), default follow-up (7), birthday window (14).

**Reports:** *Sales Pipeline*, *Win / Loss Analysis*, *Sales Activity Report*, *Account Production* (vs LY, OTB), *Sales Targets vs Actual*, *Marketing Event ROI* and *Marketing Budget vs Actual* (187 reports).

`tests/Feature/Phase51Test.php` (6 tests) and `tests/e2e/phase51.py` (six Playwright journeys; screenshots `docs/screenshots/p51_*.png`). Phase51Test: 6 tests, 202 assertions (full-suite count under v6.3). Upgrade: `php artisan migrate && php artisan pms:upgrade`.

### v6.0 — Learning & development: SOP library with versioned sign-off, training catalogue & requirements, compliance matrix, sessions and certificates, staff self-service

A new top-level **Learning & SOPs** menu (badge: required training that is overdue or expired, SOPs past their review date, and sessions that have taken place without results) with *L&D Dashboard*, *Training Matrix*, *Training Sessions*, *Training Records*, *Courses & Requirements*, *SOP Library* and *L&D Settings*. Staff also get **My Training & SOPs** under self-service.

**SOP library:**
- Each SOP has a reference code (e.g. SOP-FO-001), category, owning department, owner, summary and a Markdown body (headings, numbered steps, emphasis; HTML is escaped). SOPs start as a *draft* and are **published** as numbered versions. Every version is kept and can be opened and printed.
- Editing a published SOP creates unpublished edits, which can be published as the next version (a change note is required) or discarded. A **minor change** keeps existing signatures. A version marked **"staff must sign again"** resets sign-off to that version.
- **Sign-off scope:** all staff, or only chosen departments. SOPs can also be read-only (no signature).
- **Review cycle** in months. *Mark reviewed – still correct* moves the next review date on, and is refused while there are unpublished edits. SOPs past their review date are flagged.
- *Retire* with a reason; this is refused while an active training course is based on the SOP.
- Printable SOP and a printable **sign-off sheet**. Ticking the names from a signed paper sheet records them all at once.

**Training catalogue and requirements:**
- Courses (induction, fire safety, first aid, food hygiene, chemical handling, guest service, data protection, trafficking awareness, electrical safety, leadership…) with category, delivery (classroom / on the job / e-learning / external), duration, validity in months (blank = never expires), optional pass mark, provider and cost per head.
- A course can be **based on an SOP**: passing it counts as signing that SOP's current version. A **food-hygiene** course renews the employee's food-safety hygiene certificate (v5.0), so it must have a validity period.
- **Requirements:** who must take a course, by department and / or job title, with the number of days a new starter has from joining. When several rules match, the most demanding one applies.

**Training matrix:** every current employee against every course they are required to take. Each cell is one of:
- *current*;
- *expiring* (within the warning window);
- *expired*;
- *overdue* (never trained and the grace period has passed);
- *due* (a new starter still inside the grace period).

Cells show the expiry date, and a calendar mark when a seat is already booked. Filter by department; the matrix prints landscape. Compliance % = (current + expiring) ÷ (current + expiring + expired + overdue). The dashboard shows compliance by department, the most urgent gaps, upcoming sessions, SOPs due for review and outstanding sign-offs.

**Sessions** (TRS series):
- Scheduled for a course with date, times, venue, trainer (a user or an external name), capacity and total cost. Sessions more than 30 days in the past are entered as individual records instead.
- **Enrolment** suggests the staff who need the course and have no seat booked yet, and respects capacity. Enrolled staff are told by SMS / e-mail when they are booked, removed or the session is cancelled (setting).
- *Record results & close* is only possible once the session date has passed:
  - Each attendee is attended, failed or a no-show. With a pass mark, a score is required and decides pass or fail.
  - A pass issues a **certificate** (TC series) valid for the course's validity, less one day.
  - The total cost is shared by those who attended, falling back to the course's cost per head.
- *Cancel* with a reason releases every enrolment. Session results cannot be deleted.

**Records and exemptions:**
- **External records** cover training done elsewhere or a failed attempt. Future dates are refused, scores below the pass mark cannot be recorded as passed, expiry cannot be before issue, and food-hygiene records need an expiry. The certificate number and provider are kept.
- **Exemptions** carry a reason and an optional end date, and count as held while they run.
- Mistaken external records and exemptions can be deleted with a reason, which is logged.
- Every passed record has a printable **certificate**.

**Self-service:** *My Training & SOPs* shows the employee's compliance, the procedures they still have to sign, booked sessions, the training their job requires and their history. They can read an SOP and **sign it online** (a confirmation tick is required; this can be switched off, leaving paper-only sign-off) and open their own certificates. Other people's certificates and unpublished SOPs return 404.

**Settings:** expiry warning window (days), review warning window (days), online SOP signing on/off, staff notifications, and the default venue.

**Permissions:**
- `learning.view`, `learning.train`, `learning.sop` and `learning.manage`.
- HR Manager: all. Front Office Manager, Housekeeping Supervisor, Kitchen / Chef and F&B Manager: view, train and SOP. Banquet Manager: view and train. Security Officer: view.
- Self-service needs `hr.self` and a linked employee.

**Demo data** (no GL postings):
- **10 SOPs:** fire emergency (v2, re-signed), check-in, cash handling (v3, review overdue), room cleaning, lost & found, allergens, key control, data protection (Act 843), one maintenance draft and one retired SOP.
- **10 courses** with requirements across all nine departments.
- **Training history:** a fire-safety cohort now expiring, an expired certificate, an overdue new starter, a first-aid exemption, and a failed-then-passed data-protection record.
- **Sessions:** last week's trafficking-awareness session with a no-show, yesterday's guest-service session still waiting for results, a cancelled session, and upcoming fire-safety and food-hygiene refreshers.
- **Sign-offs:** 42 SOP signatures.
- Logins `hr` / `attendant` / `Pass@123`.

**Reports:** *Training Matrix Compliance*, *Certificates Expiring*, *Training Records Register*, *Training Hours & Spend by Department* and *SOP Sign-off Status* (180 reports).

Upgrade: `php artisan migrate && php artisan pms:upgrade` (adds the seven learning tables, the TRS / TC series, the L&D settings and the role grants; `pms:upgrade` now also applies the v5.9 concierge defaults).

Version **6.0.0**. **Tests:** `tests/Feature/Phase50Test.php` has 6 tests (~169 assertions):
- the seeded matrix states, alerts, badge and pages;
- courses, requirements, scheduling guards, capacity, cancellation, pass marks, certificates and expiry, cost sharing, SOP sign-off through training and the food-hygiene certificate renewal;
- external records, exemptions and deletion;
- SOP drafts, publishing, minor vs re-sign versions, paper sheets, discard, review and retire;
- self-service sign-off and certificates;
- reports, role permissions and tenant isolation.

`tests/e2e/phase50.py` has six Playwright journeys. The full suite is **501 tests (~17,225 assertions), all passing** (SmokeTest now covers the learning pages; `pms:upgrade` also applies the v5.9 concierge defaults it had been missing).

### v5.9 — Concierge, activities & excursions desk: tours and departures, vendor commissions, guest-portal booking, local guide, vendor settlements

A new top-level **Concierge** menu (badge: vendor requests waiting longer than the confirm window, requests for today / tomorrow, and departures in the next two days below their minimum group size) with *Concierge Desk*, *Activity Bookings*, *Activities & Vendors*, *Local Guide*, *Vendor Settlements* and *Concierge Settings*.

**Vendors & activities:**
- *Vendors:* tour operators, guides, attractions, event promoters and water-sports operators, each with contact, commission % and a **collection** mode: *folio* (the hotel charges the guest and pays the vendor net) or *vendor* (the guest pays the vendor, who owes the hotel its commission).
- *Activities* (city tour, Cape Coast & Kakum, Shai Hills, Aburi gardens, Jamestown walk, surf lesson, highlife night…): category, duration, adult / child price, commission override, minimum and maximum group size, days of the week and start times, booking cut-off, free-cancellation window, late-cancel % and no-show %, pick-up point and what to bring.
- *Departures* are created on demand per activity, date and start time. Capacity is counted in guests. A departure can be **closed** and reopened, or **cancelled** with a reason (every open booking on it is cancelled free of charge and the guests are told), and each has a printable **manifest** (guests, rooms, pick-up, notes, vendor reference).

**Bookings** (ACT series) — *requested → confirmed → completed*, with *waitlisted*, *no-show* and *cancelled* branches:
- Booked by the desk, by phone or by the guest on the portal. In-house or arriving guests are linked to their stay; walk-ins are also accepted. The form shows live seats left and the gross / commission / vendor net as you type.
- A full departure puts the booking on the **waitlist**. A seat freed by a cancellation, a reopened departure or more capacity **promotes the next waiting guest** automatically, and the guest is told by SMS / e-mail.
- *Confirm* records the vendor's reference and, for folio collection, **posts the charge to the guest's folio** (category *Excursions*, no VAT: the hotel sells as agent). Past starts, off-schedule times and booking cut-offs are refused (a manager can override the cut-off).
- *Cancel* inside the free window voids the charge. Later cancellations and no-shows keep the activity's late / no-show fee; a manager can waive it.
- Every confirmed booking has a **voucher** with a QR code and a public link for the guest (the room number is hidden, and unconfirmed bookings return 404).
- The night audit completes the day's confirmed bookings (setting) and lapses unconfirmed requests and waitlists for that day. Check-out is blocked while a room-charged booking is still waiting for the vendor or on the waitlist (confirm or cancel it first; force overrides).

**Agency accounting:**
- Folio charges go to **2170 Concierge – Vendor Payable** (they are not hotel revenue).
- On completion, a no-show or a fee-bearing cancellation, the commission is recognised in **4089 Concierge Commission** (USALI Miscellaneous Income): Dr 2170 / Cr 4089 for folio bookings, Dr **1140 Concierge – Vendor Receivable** / Cr 4089 for vendor-collected ones.
- *Vendor settlements* (AST series) net what the hotel owes the vendor against commission the vendor owes the hotel, up to a date, and post Dr 2170 / Cr 1140 / ± bank. They are refused inside a locked period, printable, and can be voided with a reason (the journal is reversed and the bookings are released).
- Both sub-ledgers join the control reconciliation, and the month-end checklist gains a warn-only *Concierge* check (activity bookings dated in the month that are still requested, waitlisted or confirmed keep their commission out of the books).

**Guest portal:** a new **Explore** page lists bookable activities with prices and the local guide. Guests can book dates within their stay (subject to the portal lead time and at most six open bookings) and cancel free while the window is open; late cancellations must go through the desk.

**Local guide:** curated places (attractions, restaurants, nightlife, shopping, beaches, health, embassies, services and emergency numbers) with address, phone, distance, hours, tips and map link, shown on the desk and on the portal.

**Settings:** portal booking on/off, portal lead hours, auto-complete at the night audit, the confirm-window hours, guest notifications and the desk phone printed on vouchers.

**Permissions:**
- `concierge.view`, `concierge.book`, `concierge.settle` and `concierge.manage`.
- Front Office Manager: all. Receptionist and Transport Desk / Concierge: view and book. Accountant: view and settle. Sales & Marketing: view.

**Demo data:** five vendors (Sunshine Tours Ghana, Kakum Eco Adventures and Accra Events & Tickets collecting through the folio; Jamestown Heritage Walks and Labadi Surf School collecting themselves), seven activities (Accra city tour, Cape Coast / Elmina / Kakum, Shai Hills, Aburi, Jamestown walk, Labadi surf lesson, Friday highlife night), twenty-one bookings covering every status (including a school group, a waitlisted family, a surf no-show and a late cancellation), one posted Jamestown settlement, and twelve local-guide places. Login `concierge` / `Pass@123`.

**Reports:** *Activity Bookings Register*, *Activity Sales & Commission*, *Operator Statement & Balances* and *Concierge Sales by Staff* (175 reports).

Upgrade: `php artisan migrate && php artisan pms:upgrade` (adds accounts 1140, 2170 and 4089, the *Excursions* charge category, the ACT / AST series and the role grants).

Version **5.9.0**. **Tests:** `tests/Feature/Phase49Test.php` has 5 tests (~237 assertions): seed, pages, permissions and reports; pricing, capacity, waitlist promotion, cut-offs and timing guards; confirm / cancel / no-show / complete with folio charges, fees, waivers and commission journals; vendor settlements (netting, lock date, void and reversal) and the control reconciliation; the portal (explore, book, limits, free cancellation), the public voucher, the check-out guard, the night audit and tenant isolation. `tests/e2e/phase49.py` has six Playwright journeys. The full suite is **495 tests (~17,090 assertions), all passing** (the print layout now shows flash messages on its on-screen bar, so actions on manifests and settlement pages confirm what they did).

### v5.8 — Owner reporting & USALI management accounts: USALI statement, KPIs, management fees, FF&E reserve & capex, owner packs

A new top-level **Owner Reporting** menu (badge: packs to acknowledge, capex to approve and answered queries for the owner; open queries, fees due and closed months without a pack for the operator) with *Owner Dashboard*, *USALI Statement*, *Monthly Owner Packs*, *Management Fees*, *FF&E Reserve & Capex* and *Agreement & Mapping*.

**USALI mapping:** every income and expense account in the chart is reported under a USALI 11th-edition department (Rooms, Food & Beverage, the other operated departments Spa & Wellness, Health Club, Guest Laundry, Transportation and Apartments & Leases, Miscellaneous Income, A&G, Information & Telecommunications Systems, Sales & Marketing, Property Operation & Maintenance, Utilities, Management Fees, non-operating items, and interest / depreciation / amortisation / income taxes). Each account also gets a category: revenue, cost of sales, labour or other expense. Mapping is **automatic** (system key, then account name, then group) and can be overridden per account; only categories that make sense for the department are offered. **Payroll** accounts (salaries, employer contributions, staff welfare) are split across departments using the month's approved payslips (gross + employer cost per HR department). If there is no run, the active salary roll is used. Year-end closing entries are excluded. The statement's net result always equals the ledger's P&L for the same period.

**USALI Statement** has two formats:
- *Operator:* EBITDA − Replacement Reserve = *EBITDA less Replacement Reserve*.
- *Owner:* EBITDA − Interest, Depreciation, Amortisation − Income Taxes = *Net Income*.

It shows month and year-to-date actuals against the active budget (v4.6) and last year, with variances coloured by favourability. Departmental expenses are shown as a % of their own department's revenue; everything else as a % of total operating revenue. Clicking a department opens its **departmental schedule** (each account with its category and departmental profit). The KPI panel shows occupancy, ADR, RevPAR, TRevPAR, GOPPAR, cost per occupied room, GOP / EBITDA margins, labour cost % and F&B cost %, with flow-through to budget and last year. A 12-month revenue / GOP / EBITDA trend is included.

**Hotel management agreement:** operator, owner, contract reference, term, **base fee** (% of total operating revenue), **incentive fee** (% of adjusted GOP above an annual **owner's priority**, pro-rated through the fee year), a stepped **FF&E reserve** schedule (e.g. year 1 2%, year 2 3%, year 3+ 4%), the fee-payment terms and the reserve's Treasury bank account. Out-of-range fees are refused, and the start date is frozen once fees exist.

**Management fees** (per month, after the night audit has closed the month and before the GL locks it):
- *Base fee* = revenue × base %. *FF&E contribution* = revenue × the year's reserve %. *Adjusted GOP* = GOP − base fee − FF&E.
- *Incentive* is **cumulative**: incentive % × (fee-year AGOP to date − priority to date) − incentive already accrued. A weak month produces a **true-down** (a negative incentive that reverses part of what was accrued earlier). Earlier months are re-measured on the current books, so later adjustments to closed months are picked up. A net-credit month is settled by paying it together with another month.
- Months must be accrued in order. Accrual posts Dr 6600 base fee / 6605 incentive fee, Cr **2160 Management Fees Payable**, and credits the FF&E contribution to the reserve ledger.
- *Reverse* needs a reason and works on the latest month first; paid fees are final.
- *Pay selected* settles ticked months from a bank / MoMo / cash account (Dr 2160 / Cr bank), not before the month ends, not after the business date and not inside a locked period.

**FF&E reserve & capex:**
- *Reserve ledger:* opening balance, contributions (from the fee accrual only), interest, drawdowns against a reserve-funded project, and adjustments, with a running balance. The page compares it with the cash actually in the reserve bank account; a shortfall links to a pre-filled Treasury transfer.
- *Capex projects* (CPX-series): funded by the reserve, the owner or operating cash, with budget, justification and category. The workflow is *Draft → Awaiting owner approval → Approved / Rejected → Completed* (or cancelled). The requester cannot approve their own project, and a rejection needs a reason. Fixed assets (v2.3) are **linked** to a project, and a reserve-funded link draws the asset's cost from the reserve (refused if the reserve cannot cover it). Projects with spend cannot be cancelled.

**Monthly owner packs:**
- *Prepare* is available for a month whose last day has been audited. The GM writes commentary (trading, costs & payroll, capital projects, outlook).
- *Issue* is blocked until the month is **closed** (v5.6), its fees are accrued and the commentary is written. Issuing **freezes a snapshot**: statement, KPIs, flow-through, fee calculation and fees year to date, reserve balance vs funded, and capex, so later postings never change an issued pack.
- *Owner's representative:* reads the pack or its print / PDF view (landscape A4), raises **queries**, and **acknowledges** it once every query has been answered. Answered queries can be closed; the pack returns to *issued* when none remain open.

**Close & controls:** the month-end checklist gains a warn-only *Owner reporting* check (fees accrued for the month; GOP drift since the accrual flagged for restatement). *Management fees payable* joins the sub-ledger control reconciliation (accrued, unpaid fees = 2160).

**Permissions:**
- `owner.view`, `owner.operate`, `owner.approve` and `owner.manage`.
- New role **Owner's Representative** (view / approve), which lands on the owner dashboard. Accountant: view / operate / manage. Administrator: all.

**Demo data:**
- *Agreement:* Nowills Hospitality Management Ltd for Airport City Hotel Properties Ltd (HMA-2026-01): 3% base, 8% incentive above a GH₵ 600,000 priority, FF&E 2 / 3 / 4%.
- *Fees:* July 2026 accrued (the August accrual shows a true-down).
- *Reserve and capex:* a GH₵ 40,000 opening reserve plus July's contribution. Three projects: a reserve-funded 2nd-floor AC replacement (approved), an owner-funded kitchen hood awaiting approval, and a completed Wi-Fi upgrade drawn from the reserve.
- *Packs:* the July pack issued with a query already answered. Login `owner` / `Pass@123` (Nana Yaw Owusu).

**Reports:** *USALI Summary Operating Statement*, *USALI Departmental Schedules*, *Hotel KPIs by Month*, *Management Fee Calculation* and *FF&E Reserve Ledger & Capex* (171 reports).

Upgrade: `php artisan migrate && php artisan pms:upgrade` (adds accounts 2160, 6600, 6605 and 6700, the CPX series and the role grants).

Version **5.8.0**. **Tests:** `tests/Feature/Phase48Test.php` has 6 tests (~256 assertions): seed, pages, permissions and reports; the USALI statement reconciling to the ledger and the mapping (payroll split, overrides, allowed categories); fee accrual, restatement, true-down, reverse and pay with their journals and guards; reserve entries and the capex workflow (four-eyes, asset link refused and then allowed on reserve cover); owner pack prepare / blockers / issue / snapshot / query / acknowledge; the close check, agreement validation and tenant isolation. `tests/e2e/phase48.py` has six Playwright journeys. The full suite is **490 tests (~16,860 assertions), all passing** (Phase7's user-created account moved from 6600 to 6690, now that 6600 is the base management fee account).

### v5.7 — Treasury & cash management: cash position, banking through Cash in Transit, cheque registers, 13-week forecast, IAS 7 cash flow

A new top-level **Treasury** menu (badge: transfers waiting for approval, cheques in hand, post-dated cheques falling due) with *Cash Position*, *Banking & Transfers*, *Cheque Registers*, *13-week Cash Forecast*, *Cash-flow Statement* and *Bank Accounts*.

**Cash position:** every Cash & Bank account of the chart as a treasury account, whether bank account, Mobile Money wallet, cash box, petty-cash float, card / gateway clearing, foreign currency, cash in transit or cheques in hand. Each shows its book balance, a minimum balance, masked account number, cheques written but not yet presented and the last reconciled date. Stat tiles show the cash position, banks & MoMo, on hand & in transit, amounts due from acquirers and the forecast low point, with alerts for cash ready to bank and cheques not banked. A new **Ecobank – Collections** account (1021) is reconcilable in *Bank Reconciliation* like any other bank.

**Banking & transfers** (TRF-series), with four kinds, each restricted to sensible routes:
- *Cash / cheque banking*: cash box, forex or cheques in hand → bank.
- *Settlement sweep*: card clearing or MoMo → bank, with the acquirer / MoMo fee.
- *Bank transfer*: bank or MoMo → bank or MoMo.
- *Cash withdrawal*: bank → cash box.

The suggested banking is the cash above the **float** (default GH₵ 5,000) less what is already committed. Cheques in hand can be ticked onto the slip, and denominations can be counted. At or above the **approval threshold** (default GH₵ 50,000) a transfer needs **a second person's approval** (`treasury.approve`). Nobody approves their own.

Sending posts Dr **1090 Cash in Transit** / Cr source. **Confirm bank credit** posts Dr bank / Cr transit, with any short or over banking to **6190 Cash Over / Short** and fees to 6125 / 6126.

Guards:
- No overdraft of the source.
- No dates before the source was funded or inside a locked GL period.
- No gateway (Online Payments) accounts.
- The two sides must differ.

Transfers can be recalled, rejected (with a reason) or cancelled before they are sent. Every step is on the transfer's trail and linked journals.

**Cheques received** (CQR-series), register statuses *Post-dated (held) → In hand → Deposited → Cleared*, or *Bounced* / *Returned to drawer*:
- **Capture:** cheque payments taken on folios, city-ledger receipts and vouchers through the new **Cheque** payment mode (mapped to **1035 Cheques in Hand**) are captured automatically. Cheques can also be recorded directly against a company, with duplicate numbers refused.
- **Post-dated cheques** stay off the books until their date. The night audit then posts the receipt.
- **Clearing:** cheques clear a configurable number of days after the deposit is credited.
- **A bounce** reverses the receipt, puts the amount back on the company's city ledger and writes a `cheque_bounced` entry to the company's credit log. Folio cheques must be handled on the folio.

**Cheques written:** cheque books per bank account (leaf range, next leaf, leaves left). Supplier payments by cheque take the next leaf automatically. **Standalone cheques** can be written to an expense or balance-sheet account (control and cash accounts refused). Leaves can be **voided / stopped** (the journal is reversed; a linked supplier payment must be cancelled there first) or **spoilt**. Cheques clear when bank-reconciliation matching finds them and go **stale** after six months, when the night audit writes them back.

**13-week cash forecast:** week-by-week opening, receipts, payments and closing cash, built from live data:
- *Receipts:* card / gateway settlements after their settlement days and net of fees; city-ledger bills by due date, with a configurable share of overdue debt collected in week 1; post-dated cheques; in-house balances at departure; guest-paid arrivals at a configurable show-up rate; tenant charges.
- *Payments:* supplier bills by due date; unpresented cheques; payroll on pay day; SSNIT / Tier 2 on the 14th; PAYE on the 15th; VAT / NHIL / GETFund and the tourism levy at month end.
- *Manual lines:* one-off or recurring (weekly / monthly / quarterly), e.g. loan instalments, conference deposits, capital expenditure.

Weeks below the combined minimum balances are red, and weeks that burn cash are amber. Every item is listed under *What's in it*.

**Cash-flow statement (IAS 7, indirect):** net profit, then non-cash add-backs (depreciation), then working-capital movements per balance-sheet account, then investing and financing flows. It reconciles opening + net change = closing cash and shows the same period last year. Year-end closing entries are excluded. Each balance-sheet account's classification (cash / operating / non-cash / investing / financing) can be changed under *Bank Accounts*. The statement shows a warning if it does not reconcile.

**Close & night audit:** the month-end checklist gains a warn-only *Treasury* check (cash in transit at month end, cheques in hand, stale PDCs). *Cheques in Hand* and *Cash in Transit* join the sub-ledger control reconciliation. The night audit captures cheques, rolls due PDCs, clears deposited cheques, stales old ones and syncs presented cheques.

**Permissions:**
- `treasury.view`, `treasury.operate`, `treasury.approve` and `treasury.manage`.
- Accountant: view / operate / manage. Front Office Manager: view / operate. Administrator: all.

**Demo data:**
- *Bankings:* nine weekly bankings through Cash in Transit, one GH₵ 50 short and the last still in transit.
- *Sweeps:* four card-settlement sweeps at a 1.5% fee.
- *Awaiting approval:* a MoMo sweep of GH₵ 120,000 and an Ecobank → GCB transfer of GH₵ 300,000.
- *Cheques received:* one cleared (Thomas Cook), one bounced (Infosys), two in hand and two post-dated.
- *Cheques written:* a GCB cheque book tied to supplier payments (one cleared through bank matching, one spoilt leaf) and an Ecobank book.
- *Other:* a GH₵ 600,000 Ecobank term loan (2700 Bank Loans) with its monthly instalment, a conference deposit and a kitchen-hood capex line in the forecast.

**Reports:** *Cash-flow Statement (IAS 7)*, *Cash Position*, *Cheque Register* and *Banking & Transfer Register* (166 reports).

Upgrade: `php artisan migrate && php artisan pms:upgrade` (adds accounts 1035, 1090 and 2700, the Cheque payment mode, treasury accounts for every Cash & Bank head and the role grants).

Version **5.7.0**. **Tests:** `tests/Feature/Phase47Test.php` has 7 tests (~250 assertions); the full suite is **484 tests (~16,330 assertions), all passing** (SmokeTest opens every new page; the demo keeps over-limit companies out of the cheque story so Phase18 is unchanged). It covers: banking via transit with four-eyes approval and short banking; sweeps, validation, recall and reject; cheque capture, PDCs, bounce and return; cheque books, void, stop, spoil and stale; forecast, cash flow and the HTTP flow; the close check, night-audit hook and tenant isolation. `tests/e2e/phase47.py` walks the accountant's banking, the administrator's approval, cheques, the forecast and the cash-flow statement (6/6).

### v5.6 — Financial close: month-end checklist, four-eyes period close, accruals & prepayments, recurring journals, year-end close

The **Accounts** menu gains *Period Close* (badge: approvals waiting for approvers, overdue closes for preparers), *Accruals & Prepayments*, *Recurring Journals* and *Year-end Close*.

**Periods:** one row per month from the first managed period (a setting; defaults to the month of the first journal), each **Open → Awaiting approval → Closed**. The dashboard lists every month with its checklist progress, who prepared and approved it, an **overdue** flag (a setting: N days after month end, default 10), the current GL lock, what is due to post and the fiscal year's status.

**Checklist** per month – automatic checks re-run on demand, on submit and again on approve:
- *night audit* closed through month end; *income audit* days signed; *GL posted* without errors; *period-end postings* all made;
- *sub-ledger controls* agree to their control accounts; *trial balance* balances and assets = liabilities + equity; *suspense* is nil;
- *bank / MoMo* accounts reconciled to month end; no *expense claims* or *supplier bills on hold* left (warn);
- *depreciation* run posted; *payroll* approved; *PAYE and SSNIT* for the previous month remitted; *stock take* posted (warn).
- Plus **manual tasks** (defaults: debtor ageing and provisions, VAT / NHIL / GETFund and tourism-levy control accounts agreed to the GRA / GTA returns, GRNI accruals, management pack) – configurable, and ad-hoc tasks per month.
- Failing or pending items can be **waived with a reason**; a waiver survives re-runs while the check still fails. Everything is logged on the month's timeline.

**Four-eyes close** (`close.prepare` / `close.approve`): the preparer **submits** once nothing blocks it (month ended, previous month closed – periods close in order, checklist cleared). An approver who did **not** prepare it **approves** – the checks run again, the **GL lock moves to the month end** – or **sends it back** with a reason. The lock can no longer be moved back from *General Ledger*; only the **latest** closed month can be **reopened**, by a request with a reason approved by someone else. Reopening restores the previous lock and the month counts its reopens.

**Accruals, prepayments & deferred income:** a schedule has a type, description, supplier, P&L account (expense, or income for deferrals), balance-sheet account (defaults: 2150 Accrued Expenses, 1320 Prepayments, 2145 Deferred Income), amount, first month and number of months (the last month absorbs the rounding). An **accrual** posts at month end and **auto-reverses on the 1st** of the next month. Schedules post only after **a second person approves** them; approvers can cancel with a reason.

**Recurring journals:** balanced multi-line templates (monthly, quarterly, yearly; optional last period; optional auto-reversal on the 1st), approved four-eyes, paused / resumed / ended.

**Period-end postings:** *Post due* on the month (or automatically by the **night audit** when it closes the last day of a month – a setting) posts every schedule month and recurring journal due up to that month, dated at month end. Months that are already locked are **caught up** on the open month's end with a *(catch-up)* memo. Idempotent per schedule and month; every posting links to its journal and back.

**Year end:** approving the fiscal year's last month (April–March by default, the budgeting setting) posts the **closing entry** – every income and expense balance to **3100 Retained Earnings** – flagged as a closing journal. The *Year-end Close* page previews it, shows the result and keeps the history. Reopening the year-end month reverses it; closing it again posts a fresh one. The **P&L, budget actuals, income audit, service charge and GST/VAT figures leave closing entries out**, and the **trial balance** is pre-closing within the range.

**Permissions:** `close.view`, `close.prepare`, `close.approve`. Accountant: view / prepare; Administrator: all.

**Demo data:** sixteen months closed (Apr 2025 – Jul 2026) by the accountant and approved by the administrator; May 2026 reopened for a late ECG credit note and closed again; FY 2025-26 closed to retained earnings; a fire & burglary insurance **prepayment** (GH₵ 48,000 over 12 months), the KPMG audit fee **accrual** (reversed), August's ECG electricity accrual, a management-fee and a guarding **recurring journal** (the latter auto-reversing), and – awaiting approval – an Ecobank ATM-space **deferral** and a quarterly GNFS fire-certificate template. **August is open and overdue**: four postings due, depreciation failing, two manual tasks done.

**Reports:** *Period Close Status*, *Accruals & Prepayments Register* and *Period-end Postings* (162 reports).

Upgrade: `php artisan migrate && php artisan pms:upgrade` (adds accounts 1320, 2145, 2150, 4086, 6180, 6185). Set the first managed period under *Period Close › Settings* before closing the first month.

Version **5.6.0**. **Tests:** `tests/Feature/Phase46Test.php` has 5 tests (~280 assertions); the full suite is **477 tests (~15,960 assertions), all passing** (Phase42Test lifts the demo lock for its back-dated lease scenario; Phase7Test accepts the new "not yet closed to retained earnings" label). `tests/e2e/phase46.py` walks the accountant's close, the administrator's approval, a declined reopen and the year end (6/6).

### v5.5 — Rate packages, meal plans & inclusions: package catalogue, rate inclusions, Meal Desk, breakage, GL allocation

A new **Packages & Meals** menu: *Package Dashboard*, *Meal Desk* (badge: rooms with meals still to use today), *Entitlements* and *Catalogue & Inclusions*.

**Catalogue** (`packages.manage`): code, name, revenue department (folio charge category), meal (breakfast / lunch / dinner), outlet.
- Pricing **per person** (adult and child prices), **per adult** or **per room**.
- Earned **every night**, on the **arrival night**, on the **last night** or **once per stay**. Used the **same day** or the **next morning** (breakfast).
- **Redeemable** packages (meals, credits) are used at an outlet and lapse as breakage. Redeem by **covers** or by **amount** (an allowance such as GH₵ 300 of dinner per adult).
- **Fixed** inclusions (Wi-Fi, an airport drop) count as delivered when they are allocated.
- Walk-in price per extra cover. Flags for "sold as an add-on" and "taxed when sold".
- `pms:upgrade` adds a starter catalogue (BRKF, LNCH, DINR, AIRP, WIFI, SPA) but does **not** attach it to any rate.

**Rate-plan inclusions:** a rate plan × package matrix. Each rate plan's **meal-plan code** follows the meals it includes: breakfast = CP, breakfast + lunch or dinner = MAP, all three = AP, none = EP. The matrix shows each rate's included value (2 adults) and the room-only remainder.

**Nightly allocation:**
- When a night's tariff posts (night audit, late check-out, day use), each included package creates an **entitlement**: covers, value, service date, and the room night it came from.
- The **folio still shows the full tariff** as one line, so invoices, income-audit rate checks and flash figures are unchanged.
- When the packages are worth more than the rate (comp or discounted rates), their values are **scaled down pro rata**, so room revenue never goes negative. The guest keeps the covers.
- Voiding a room night cancels its unused entitlements. Allocation is idempotent per stay, package and night.

**Add-on packages** (`packages.sell`): a **Package** button on the folio's fixed-charges card sells a catalogue package for the stay's party size. It becomes a fixed charge with its own folio line; last-night packages move to the last night, and overlapping sales are refused. Redeemable add-ons earn entitlements the same way. The folio also shows "Rate … includes: Breakfast buffet (2 cov, every night)".

**Meal Desk** (`packages.redeem`):
- One tab per redeemable package, with the number of covers left today.
- Rooms entitled today, searchable by room or guest, showing covers or allowance left.
- **Record** covers served, or a bill amount for allowances. Anything beyond the entitlement is **charged to the room** at the walk-in price (a folio line with source `package`), **paid at the outlet** or **waived**. The default is a setting.
- A panel for in-house rooms **without** the package, plus the list of what was served today.
- Managers can **void** an entry: the extra charge is reversed and the entitlement re-opens. Voiding is refused once the entitlement has expired.

**Night audit:** entitlements whose service date plus **grace days** (0–3) has passed **expire**, and the unused value is **breakage**. The audit logs the count and amount.

**General ledger:** one journal per business date (source *Package allocation*):
- allocation: **Dr Room revenue / Cr 2135 Package Allowances – Unconsumed** (or Cr the package's department for fixed inclusions);
- paid redeemable add-ons: **Dr department revenue / Cr 2135**;
- consumption: **Dr 2135 / Cr F&B, spa … revenue**;
- breakage: **Dr 2135 / Cr 4092 Package Breakage Income**, room revenue or the package's department (setting).

The GL balance of 2135 equals the open entitlements.

**Dashboard:** covers entitled / served today, open liability, allocated and breakage month to date, extras charged. A **7-day meal cover forecast** (rooms, adults / children, covers per package, from in-house stays and confirmed reservations including add-ons), take-up by package, and the latest redemptions. **Entitlements** register with filters.

**Permissions:** `packages.view`, `packages.redeem`, `packages.sell`, `packages.manage`.
- Front Office Manager and F&B Manager: all four.
- Receptionist: view / sell / redeem.
- Restaurant Cashier: view / redeem.
- Sales & Marketing: view / manage.
- Accountant and Kitchen / Chef: view.

**Demo data:**
- Breakfast is included in every CP rate, breakfast + dinner in MAP, and premium Wi-Fi in the suites.
- Nine nights of breakfast entitlements: mostly eaten, some partly, some skipped (breakage).
- A dinner add-on sold yesterday (GH₵ 520 of GH₵ 600 used, GH₵ 80 breakage). An airport drop and a spa credit sold for tonight.
- This morning's breakfast half served: one extra cover charged to the room, one entry voided by the FOM.

**Reports:** *Meal Cover Forecast*, *Package Allocation & Breakage* and *Package Consumption Register* (159 reports).

Upgrade: `php artisan migrate && php artisan pms:upgrade`. Then tick the packages each rate includes under *Packages & Meals › Catalogue & Inclusions*.

Version **5.5.0**. **Tests:** `tests/Feature/Phase45Test.php` has 5 tests (~320 assertions); the full suite is **472 tests (~15,630 assertions), all passing**. Note that package allocations move value out of GL room revenue, so budget-vs-actual room revenue reads net of inclusions (by design). The browser walkthrough is `tests/e2e/phase45.py` (8 steps, all passing), with screenshots `docs/screenshots/p45_*.png`.

### v5.4 — Report automation: saved views, scheduled e-mail reports, delivery log, KPI threshold alerts

The **Reports** menu is now a group: *All Reports*, *My Reports & Saved Views*, *Scheduled Reports* and *KPI Alerts*. The KPI Alerts entry has a badge counting unacknowledged breaches.

**Saved views:** every report screen has a **Save view** button. A view stores the report, a **relative period**, the columns to show and an optional text filter.
- Periods: today, yesterday, last 7 / 30 days, week / month / quarter / year to date, last month, last year, next 7 / 30 / 90 days, or fixed dates. They are resolved against the current business date, so "Month to date" always means *this* month.
- Views are personal by default. Users with `reports.schedule` can **share** a view with colleagues, who see it only if they may open that report.
- A view can be **pinned** to the owner's dashboard.
- Opening `/reports/{report}?view=ID` shows the chips of available views, a "Showing … · 8 of 12 columns · filter" banner and *Edit view* for the owner.
- The report screen also gains a *More periods…* picker.

**Scheduled reports** (`reports.schedule`):
- Send any report or saved view **daily**, **weekly** (weekday), **monthly** (day 1–28 or the last day) at a set time, or **straight after the night audit**.
- Format: an HTML table in the e-mail (first *N* rows plus totals), the full CSV attached (formula-injection safe), or both.
- Recipients are colleagues, who must be allowed to see the report, plus external addresses, checked against an optional **domain allow-list** with a recipient limit.
- Options: a note in the e-mail and *don't send when empty*.
- The report always runs **with the owner's permissions, re-checked at every run**. An inactive owner, lost access or a domain that is no longer allowed fails the run.
- Failures count up, and the schedule **auto-pauses** after 5 in a row (configurable).
- Actions: *Send now*, pause / resume, edit, delete (history is kept).
- `php artisan pms:reports-run` is scheduled every 5 minutes and sends what is due. The night audit triggers the after-audit schedules and the KPI check.

**Delivery log:** every run records period, trigger (scheduled / after audit / sent now), status (sent / partial / failed / skipped), rows, recipients delivered and the error. Deliveries keep no files; *download* regenerates the CSV for the same period.

**KPI alerts** (`reports.alerts`):
- Thresholds (*below* / *above*) on flash-report metrics for the day or month to date: occupancy, ADR, RevPAR, room / F&B / net revenue, collections, discounts, no-shows, cancellations.
- Live positions: on-the-books occupancy tomorrow / next 7 nights, out-of-order rooms, in-house guest balances, city ledger over 60 days, lease arrears, open income-audit exceptions, pending revenue authorisations, items at re-order level.
- Checked after every night audit for the closed date, or on demand for any audited date. A breach creates **one event per alert per business date**, with severity info / warning / critical.
- Notification is in-app (dashboard card plus nav badge), optionally by **e-mail** and **SMS** to users and extra addresses / phones.
- Events are **acknowledged** with a note.

**Settings** (Scheduled Reports › Settings, administrators): allowed domains, max recipients, failure limit, rows in the e-mail body.

**Permissions:** `reports.schedule` and `reports.alerts` are new. Front Office Manager and Accountant get both. Sales & Marketing and F&B Manager get `reports.schedule`. Personal views need only `reports.view`.

**Demo data:**
- Views: admin's pinned *Yesterday's flash* and shared *Revenue – last 7 days*; accounts' *MTD collections*; the FOM's shared and pinned *Arrivals – next 7 days* (8 columns).
- Schedules:
  - daily flash to the GM and owner (sent now);
  - weekly revenue;
  - month-end city-ledger ageing (CSV);
  - after-audit income-audit exceptions and FOM arrivals (sent);
  - an old owner's pack to a private mailbox, blocked by the allow-list and auto-paused after 5 failures.
- Eight KPI alerts evaluated for the last two audited dates. Low occupancy, weak collections, the audit backlog and tenant arrears fire; one breach is acknowledged by the FOM.

**Reports:** *Scheduled Report Deliveries* and *KPI Alert Log* (156 reports).

Upgrade: `php artisan migrate && php artisan pms:upgrade`. Add the cron line `* * * * * php artisan schedule:run` if it is not already there.

Version **5.4.0**. **Tests:** `tests/Feature/Phase44Test.php` has 7 tests (211 assertions); the full suite is 467 tests (~15,240 assertions), all passing (one Phase 36 bank-reconciliation seed check is occasionally sensitive to the randomised demo history and passes on re-run). The browser walkthrough is `tests/e2e/phase44.py` (8 steps, all passing), with screenshots `docs/screenshots/p44_*.png`.

### v5.3 — Income audit & revenue integrity: daily checks, exceptions, sign-off, closed-day lock, authorisations, room count

**Income Audit** menu (`/income-audit`), placed after Billing, with *Audit Days*, *Authorisations*, *Room Count* and *Room Discrepancies*. The nav badge counts open exceptions, plus days waiting for the controller and authorisations to decide (for those who can act on them). The main dashboard shows an *Income audit* line.

**How a day is audited:** every night audit hands the closed business date to income audit and runs the checks. It also takes a **baseline** of the day's room revenue, other revenue, taxes and collections. The **income auditor** (Accountant) works through the exceptions, marking each one *explained*, *corrected* or *escalated* with a note. Corrections are posted today in the folio, then the checks are re-run; anything put right in the system clears itself. The auditor **signs**, which re-runs the checks and is refused while anything is open or escalated. The **financial controller / GM** reviews and **closes** the day; this must be a different person (four-eyes). A closed day is **locked**: transactions dated on it can no longer be voided, so corrections go through today's business date. Reopening needs a reason, withdraws the sign-off and is logged.

**Automated checks** (tolerances in settings):
- **Room tariff missing / duplicated** (high): every room occupied that night must have exactly one tariff posting.
- **Complimentary / zero-rate room** (high): flagged unless there is an approved comp or house-use authorisation.
- **Rate variance**: the charged tariff is compared with the **expected rate**. That is the rate plan, then the group block rate, then the corporate contract, then the rack rate (incl. RM auto pricing) less the booking discount. Channel bookings are skipped. The default tolerance is ±10 %; an approved rate authorisation accepts it.
- **Discounts / allowances** above GH₵ 200 without an authorisation.
- **Voids**: voided folio postings (payment voids rated high), voided POS bills, and items cancelled after the KOT went to the kitchen.
- **Cashier over / short** beyond GH₵ 10.
- **POS bills left open** after the day closed.
- **Departures not settled**: checked out with a balance on an open folio.
- **In-house balances over the limit** for guests billed to themselves.
- **E-VAT not cleared**: invoices and restaurant bills without a GRA signature, when E-VAT is on.
- **Room status discrepancies** from the housekeeping count.
- **Figures changed after close**: revenue, tax or collections differ from the baseline, i.e. back-dated postings or voids.

**Day screen:** status, exception counts and value at issue; a **revenue reconciliation** (night-audit report vs baseline vs now, change since close, discounts, GL income); filters by status and check; a resolve dialog; sign / close / reopen; previous / next day; and a printable **income audit report** with both signatures.

**Authorisations** (`/income-audit/authorisations`):
- **What can be requested:** a complimentary room, house use, a rate below expected, or a folio discount / allowance. Anyone with `income_audit.request` (receptionist, FOM, accountant) can ask, and must give a reason.
- **Who approves:** another manager, never the requester. Within the manager limit (GH₵ 2,000 of revenue given away, measured against the expected rate, or 2 complimentary nights) the FOM can approve. Above it, only the GM / controller.
- **What approval does:** a discount is **posted** to the folio and linked. A comp or rate changes the stay's rate for the authorised nights, and the night audit **puts the previous rate back** after the last one. Retrospective authorisations only document the rate.
- **In the audit:** open exceptions covered by an approved authorisation are accepted automatically, as "Pre-authorised AUT-… by …".

**Room count** (`/income-audit/room-count`): housekeeping records each room as occupied / vacant / out of order, with luggage. The sheet is **blind**: housekeeping does not see front-office status. The result is compared with front office:
- **sleeper**: FO occupied, room empty;
- **unregistered**: someone is in a room FO shows vacant;
- **out-of-order mismatch**.

Front office resolves each one on *Room Discrepancies* (guest out for the day, checked out now, unrecorded room move, charged, housekeeping error, status corrected, other). A changed count reopens it. Resolved discrepancies appear in the audit as corrected, and open ones block the sign-off.

**Settings** (Audit Days page, controller only): rate tolerance %, discount limit, cash tolerance, in-house balance limit, manager authority limit, comp-night limit, four-eyes on/off, closed-day lock on/off.

**Permissions:**
- `income_audit.view`: accountant, FOM.
- `income_audit.audit`: accountant; runs checks, resolves, signs.
- `income_audit.close`: administrator / GM; closes, reopens, accepts, approves above the limit, changes settings.
- `income_audit.request`: receptionist, FOM, accountant.
- `income_audit.authorise`: FOM.
- `income_audit.count`: housekeeping supervisor, FOM.

**Demo data:**
- 21–25 Sep closed (signed by *accounts*, closed by *admin*); 26 Sep signed and awaiting the controller.
- 27 Sep in audit with an unauthorised walk-in rate (escalated), a pre-authorised rate (accepted), a GH₵ 450 allowance, a cash shortage, a restaurant bill without E-VAT, an in-house balance over the limit, a minibar charge voided after close (void explained + figures changed), a resolved sleeper and an open unregistered room.
- Authorisations: FOM-approved rate, GM-approved comp for tonight, declined half-price request, pending discount.

**Reports:** *Audit Exceptions Register*, *Room Rate Variance*, *Daily Revenue Reconciliation*, *Comps, Discounts & Rate Authorisations* (154 reports).

Upgrade: `php artisan migrate && php artisan pms:upgrade`.

Version **5.3.0**. **Tests:** `tests/Feature/Phase43Test.php` has 7 tests (150 assertions); the full suite is 460 tests (~14,930 assertions), all passing. The browser walkthrough is `tests/e2e/phase43.py` (9 steps, all passing), with screenshots `docs/screenshots/p43_*.png`.

### v5.2 — Serviced apartments & long-stay leases: units, tenancy agreements, rent billing, deposits, utilities, move-out

**Apartments & Leases** menu (`/leases`), placed after Health Club. The nav badge counts tenants in arrears, tenants past their move-out date and deposits still to settle. The main dashboard shows an *Apartments* line with arrears and leases ending within 30 days without notice or renewal.

**Dashboard** (`/leases`): units let / occupancy, monthly rent roll, tenants in arrears, deposits held; a colour-coded **unit board** (vacant, leased, notice, reserved, arrears, maintenance); arrears, move-outs, upcoming move-ins, leases ending within 60 days with no decision, rent reviews due and tenants holding over.

**Units** (`/leases/units`): code, block / floor, type (studio, 1–3-bed, penthouse, office, shop), size, furnishing, default tax class, list rent and service charge, meter numbers and amenities. A unit can be **linked to a hotel room**: while a lease (or signed draft) covers the dates the room is removed from availability, the tape chart and the booking engine, and a lease is refused if the room already has hotel bookings in the period. The settings form (same page) holds the bill-ahead days, grace days, late fee %, default notice, deposit months, WHT rate, electricity / water tariffs and the Rent Act switch.

**Leases** (`/leases/new`): tenant (individual or a company from the city-ledger list, with a Ghana Card / passport), term 1–60 months, cycle (monthly / quarterly / half-yearly / yearly), rent and service charge, deposit (default 2 months), notice days, rent review (% every N months), auto-renew, utilities (included / metered / prepaid) and tariffs, inclusions and special conditions. A live calculator shows each invoice with tax.
- **Tax classes (Act 1151):** *serviced* apartments with hotel-like services are taxed like accommodation — VAT 15 % + NHIL 2.5 % + GETFund 2.5 % + Tourism Levy 1 %; *residential* lettings are exempt; *commercial* lets carry 20 % (no tourism levy).
- **Rent Act 1963 (Act 220) s.25(5):** for residential tenancies the billing cycle is capped at 6 months (1 month for tenancies of 6 months or less), and a receipt that would leave more than that paid in advance is refused. The cap can be switched off in settings.
- **Company tenants** default to 8 % WHT on rent. Receipts record the tax withheld and the certificate number, and the WHT settles the account (Dr 1315 WHT recoverable).

**Lifecycle:** print the **tenancy agreement** (numbered clauses, schedule, Rent Act, tax, deposit, utilities, review, notice, hand-back, data protection and stamp-duty clauses) → record the signature → take the deposit → **move in** (opening meter readings, move-in condition report, first invoice raised) → the night audit invoices each period *bill-ahead* days before it starts, applies rent reviews from the review date, raises optional late fees once per overdue invoice and auto-renews leases 30 days before the end → **notice** (the lease's notice period; a manager can accept short notice; withdraw) or **renew** (extra months, new rent) → **move out** (closing readings recharged, move-out inspection with damage charges, rent billed beyond the move-out date credited day by day) → **settle the deposit** (applied against what is owed, the rest refunded by the chosen mode, printable settlement statement).

**Account:** invoices (rent, service charge, electricity, water, late fee, damage, other) with the due date and settlement state; credit notes against any charge (tax at the original rate); same-day cancellation (a cancelled rent invoice hands its period back to billing); receipts allocated oldest-due first, refunds of credit, deposit received / applied / refunded, voids with guards. **Meter readings** (`/leases/meters`): bulk entry for every metered unit; consumption × tariff is recharged with tax. **Billing & arrears** (`/leases/billing`): run billing now, arrears ageing, upcoming invoices, recent charges and receipts.

**Ledger:** Dr 1130 Tenant receivables / Cr 4052 Rent income or 4053 Other lease income + output taxes; receipts Dr cash/bank/MoMo (+ 1315) / Cr 1130; deposits Cr 2140 Tenant security deposits. Both control accounts reconcile on the GL controls panel.

**Permissions:** `leases.view` (front desk, accounts), `leases.manage` (FOM: units, drafts, move-in/out, notice, inspections, meters), `leases.billing` (receipts, charges, credit notes), `leases.approve` (voids, short notice, deposit settlement, settings). **Demo data:** 9 units in Nowills Residences Block A and the ground-floor commercial block; 8 leases covering every state — Infosys executive let with WHT and metered utilities, an expat on half-yearly billing, a residential family in arrears, an office with a year-2 rent review, a retail kiosk with a late fee, a tenant on notice, a settled move-out and a signed draft starting next week.

**Reports:** *Rent Roll*, *Tenant Arrears Ageing*, *Lease Expiry & Renewals* (150 reports).

Upgrade: `php artisan migrate && php artisan pms:upgrade`.

Version **5.2.0**. **Tests:** `tests/Feature/Phase42Test.php` has 8 tests (242 assertions); the full suite is 453 tests (~14,750 assertions), all passing. The browser walkthrough is `tests/e2e/phase42.py` (9 steps, all passing), with screenshots `docs/screenshots/p42_*.png`.

### v5.1 — Health club & memberships: plans, members' accounts, deferred revenue, check-in desk, classes

**Health Club** menu (`/club`), placed before Spa & Activities. The nav badge counts memberships in arrears plus non-renewing memberships that end within 7 days. The main dashboard shows a *Health club* line with the same information.

**Plans & settings** (`/club/plans`). Nine starter plans are seeded: Gym Monthly, Full Club Monthly / Quarterly / Annual, Couple, Family, Off-Peak, Student and Corporate. Each plan sets:
- term in months, price and joining fee (excl. tax), maximum card holders;
- facilities (gym, pool, sauna & steam, tennis, squash, kids club, group classes) and optional access hours;
- free guest passes per month;
- F&B and spa discount %.

The settings are:
- grace days before arrears block entry (7);
- renewal lead time (7 days);
- auto check-out after (4 h);
- day-pass prices (adult GH₵150 / child GH₵80);
- guest-pass price (GH₵100);
- free resident use of gym, pool and sauna;
- late class-cancellation window (2 h) and no-show limit (3 in 30 days);
- a capacity for each facility.

**Tax.** Club services are taxed through the `club` folio category: VAT 15% + NHIL 2.5% + GETFund 2.5% + Tourism Levy 1% = 21%. For example, Full Club Monthly is GH₵650 + 300 joining = **GH₵1,149.50** at enrolment.

**Enrolment** (`/club/members/enrol`, perm `club.members`). Choose a plan and fill in the holder, company (required for corporate plans), start date (up to 7 days back, 60 ahead), auto-renew, and each card holder's relation, sex, date of birth, phone and PAR-Q. You can take a payment at once (MoMo / card / cash / bank). Only a manager can waive the joining fee.
- Each person gets a 10-digit card number. A printable **CR80 card** (`/club/members/{id}/cards`) carries a QR code and the photo.
- A lost card gets a new number; the old one stops working.
- Under-16s may not use the gym.

**The members' account** (member page → Account; printable statement, invoices and receipts):
- **Charges**: joining, subscription, guest pass or other, each with its tax.
- **Receipts** and **refunds** (a refund can only be made from a credit balance).
- **Credit notes**: limited to the uncredited amount of the charge; tax follows the original rate.
- **Allocation**: receipts and credits are pooled and settle charges oldest-due first, recomputed after every change.
- **Cancelling a charge**: allowed on the day it was raised, or for a subscription period that has not started (the membership end date is handed back). Otherwise issue a credit note. A receipt with a later refund can't be voided first.

**Ledger** (new heads):
- 1120 *Club Members Receivable*;
- 2130 *Membership Fees Received in Advance*;
- 4042 *Health Club Membership Revenue*;
- 4043 *Day Passes & Joining Fees*.

How documents post:
- **Subscriptions**: Dr receivable / Cr 2130 + taxes.
- **Joining fees and guest passes**: go straight to 4043.
- **Receipts**: Dr cash, bank or MoMo / Cr receivable.
- **Paid day passes**: Dr cash / Cr 4043 + taxes. Room-charged passes post through the folio.
- **Revenue recognition**: a monthly journal (`CLUB-YYYY-MM`) releases what was **earned day by day**. An immediate cancellation stops recognition on the cancellation date.
- **Controls**: the trial-balance controls now reconcile the receivable with the members' accounts and 2130 with the unearned fees.

**Renewals & expiry** (`/club/billing`). The night audit:
- raises **auto-renewal invoices** 7 days before the term ends; the next period follows on and is due from its start date;
- starts and ends freezes;
- lapses memberships whose term has passed (*expired*, or *cancelled* if notice was given);
- closes visits left open for more than 4 h;
- marks unattended class bookings as no-shows.

*Renew now* and *Run renewals* do the same on demand. Renewing an expired membership restarts it today.

The billing page lists renewals due, arrears (oldest due date, days overdue), memberships ending without renewal and lapsed members to win back.

**Freeze & cancel.** A **freeze** blocks entry for its period and extends the term by the frozen days. It can be lifted early. **Cancellation** comes in two forms:
- *At term end*: no renewal, access until the paid term ends; the notice can be withdrawn.
- *Immediately*: access stops today and future renewals are withdrawn. A **credit note** goes on the account for the unused days (pro-rata, adjustable). Refund it or leave it on account.

**Check-in desk** (`/club/desk`, perm `club.desk`). Scan a card, or search by membership no., name or phone, then pick the facility. The verdict is explained in plain words:
- Refusals: expired, frozen, cancelled, not started, **overdue balance past the grace days**, facility not in the plan, outside off-peak hours, under-16 in the gym, already checked in, facility full.
- Warnings: no PAR-Q, balance due, ending soon without renewal.

Refusals are logged. A **manager override** needs a reason and is logged in the activity log; desk staff don't see the button. The rest of the desk:
- **Members' guests** use the plan's free monthly passes first. After that, a guest pass is charged to the member's account or paid on the spot.
- **In-house guests** check in by room number and use the gym, pool and sauna free. Tennis, squash, kids club and classes need a day pass, which can be charged to the room.
- **Live occupancy** per facility, "in the club now" with check-out, and today's entries.

**Day passes** (`/club/passes`). Adults / children, paid now (MoMo, card, cash) or **charged to the room** (a folio line with tax). You can optionally check the visitor in at once. A pass can be cancelled with a reason on the day it was sold.

**Classes** (`/club/classes`). A weekly timetable with a facility, an in-house instructor (employee) or external one, capacity, and whether residents may book. Starter timetable: Aqua Aerobics, Spin Express, Vinyasa Yoga, HIIT, Pilates, Kids Swim School and Zumba.
- **Bookings** open 14 days ahead, by member card or room number. A full session puts the booking on the **waitlist**. A cancellation promotes the first person waiting.
- A late cancellation (within 2 h) counts as a **no-show**. 3 no-shows in 30 days put bookings on hold.
- The **register** can be taken from 15 minutes before the start.

**POS member discount.** The terminal has a *Club card* box next to the discount. It applies the plan's F&B discount (10% or 15%) to active members who are not in arrears, keeping the better of this and any manual discount. The member is recorded on the bill. Members in arrears are told why no discount applies.

**Permissions.** `club.view`, `club.desk`, `club.members` and `club.manage`. Manager-only actions: plans, settings, cancellations, credit notes, refunds and overrides.
- Receptionists and the Front Office Manager have view + desk.
- The Spa Desk has view + desk + members.
- The Accountant has view + members.

**Reports**: *Membership Register*, *Club Attendance & Utilisation*, *Club Revenue & Deferred Fees* (147 reports).

**Demo data** (fresh seed). Fourteen memberships across every plan:
- Kofi Mensah in arrears and refused at the desk;
- a frozen member, an expired member, an immediate cancellation with credit and an end-of-term notice;
- memberships ending this week with and without auto-renew;
- a family of four, a couple, a corporate member (Infosys) and an in-house guest who joined;
- two weeks of visits, 10 people in the club now, members' guests, resident visits and day passes;
- a full Spin Express with a waitlist;
- class history with attendance and no-shows.

Upgrade an existing install with `php artisan migrate && php artisan pms:upgrade`.

Version **5.1.0**. **Tests:** `tests/Feature/Phase41Test.php` has 8 tests (168 assertions); the full suite is 445 tests (13,914 assertions), all passing. The browser walkthrough is `tests/e2e/phase41.py` (13 steps, all passing), with screenshots `docs/screenshots/p41_*.png`.

### v5.0 — Food safety & HACCP: allergens, temperature logs, checklists, food waste, handler certificates, labels

**Food Safety** menu (`/food-safety`), placed before Spa. The nav badge counts what needs someone: unreviewed breaches, overdue cooling, failed checklists not yet signed off and overdue daily checklists.

**Dashboard**
- Stat tiles:
  - HACCP compliance (7 and 30 days): equipment checks done vs expected, plus checklists done vs due.
  - Readings within limits.
  - Items needing attention.
  - Food waste this month as a % of food & beverage cost.
- Today's equipment board, breaches to review, cooling in progress and current checklists.
- Undeclared / unverified allergens, handler certificates, labels reaching use-by in the next 24 h, and waste by reason.

**Allergen Matrix** (`/food-safety/allergens`)
- The 14 regulated allergens (Codex / EU list): gluten, crustaceans, egg, fish, peanut, soy, milk, tree nuts, celery, mustard, sesame, sulphites, lupin, molluscs.
- **Ingredients tab**: allergens on stock items. Every dish whose recipe uses the item inherits them.
- **Menu tab**: click a cell to cycle *contains → may contain (cross-contact) → blank*.
  - Allergens that come from a recipe ingredient are marked **R**.
  - A red outline means the dish declaration is missing it ("Recipe adds: Milk").
  - Changed rows, and rows ticked *confirm*, are stamped as verified by the user.
- **Print matrix** per outlet, for display at the pass or for inspectors.

**Guest allergies at the table**
- **POS terminal**: the *Allergies* button records the guests' allergens and a note on the order.
  - A red banner shows the allergy, and conflicting menu tiles are outlined.
  - Adding a conflicting dish asks for confirmation. The server refuses to send it on a KOT without that confirmation.
  - Lines already sent are re-flagged when the allergy is recorded later.
- The **KOT** prints `*** ALLERGY: MILK ***` plus the note, and `!! CONTAINS …` per line. The **kitchen display** shows the same on the ticket.
- **QR menu**: guests tick their allergies. These are remembered on the device, matching dishes are highlighted with a warning, and the allergies travel with the order.

**Temperature Logs** (`/food-safety/temperatures`)
- **Equipment register**: fridge, freezer, hot-hold, blast chiller and display units, each with its own min / max limits and a number of checks per day. The board shows the checks due today.
- Reading types:
  - equipment;
  - **deliveries** (chilled ≤ 5 °C, frozen ≤ −15 °C, hot ≥ 63 °C), linked to the supplier;
  - **cooking** and **reheating** core ≥ 75 °C;
  - **2-stage cooling**: ≤ 21 °C within 2 h, then ≤ 5 °C within 6 h. It is overdue 4 h after the first reading.
- Rules:
  - An out-of-range reading is refused until the **corrective action** is entered.
  - Readings can't be in the future or more than 3 days old.
  - Each breach is **reviewed** by a manager other than the person who recorded it.
- All limits can be edited under *Critical limits* (defaults follow Codex / Ghana FDA guidance).

**HACCP Checklists** (`/food-safety/checklists`)
- Five standard templates for every property:
  - kitchen opening (due 10:00);
  - kitchen closing & cleaning (due 23:30);
  - bar opening (due 16:00);
  - weekly deep clean;
  - monthly pest control.
- Managers can add or edit templates (one item per line).
- Each item is answered **Yes / No**; a No needs a note. There is one completion per period (day / week / month).
- A daily list becomes *overdue* after its due time.
- Failed runs are **signed off** by a manager who didn't complete them, with a follow-up note.

**Food Waste Log** (`/food-safety/waste`)
- Three kinds of entry:
  - **raw** stock item;
  - **prepared dish** (the recipe is exploded into its ingredients);
  - **plate waste** (already consumed by the sale, so it is recorded at portion cost for analysis only).
- Reasons: spoiled, expired, over-production, temperature breach, contaminated, quality, returned plate.
- Raw and dish waste write *wastage* stock movements at last cost, with stock checked first. The stores journal posts them **Dr 5042 Food Waste / Cr Inventory** (other wastage stays in 5040).
- Cancelling needs a reason and reverses the stock on the business date.
- Summary: stock waste, plate waste, waste % of kitchen & bar consumption, top items and by reason.

**Food Handler Certificates** (`/food-safety/certificates`)
- **Medical fitness** and **food hygiene training** certificates for staff in the handler departments (default: Kitchen and F&B Service), with issuer, number and dates.
- States: valid / expiring within 30 days / expired / missing, with full history.
- The **duty roster warns** when a food handler is rostered on a day without a valid medical certificate.

**Prep Labels** (`/food-safety/labels`)
- Label sheets: item or dish, storage (chilled 72 h, frozen 90 days, ambient 24 h, hot 4 h – editable), prepared time, **use-by**, batch, allergens (copied from the dish) and who prepared it.
- A day-of-week colour dot on each label. The dashboard lists labels reaching use-by in the next 24 h.

**Inspection pack** (`/food-safety/inspection-pack`): a printable record for any period. It covers compliance, every temperature reading with its corrective action and review, checklist runs with sign-offs, and waste.

**Reports**: *HACCP Temperature Log*, *HACCP Checklist Compliance*, *Food Waste Analysis* (144 reports). The dashboard shows a *Food safety* line.

**Permissions**: `foodsafety.view`, `foodsafety.record`, `foodsafety.manage`, `foodsafety.certs`. Default grants:
- Chef and F&B Manager: all four.
- Store Keeper and Restaurant Cashier: view and record.
- HR Manager: view and certificates.
- Accountant and Chief Engineer: view only.

**Demo data**:
- Allergens on 23 dishes and 12 ingredients (11 new stockless allergen ingredients). Paneer Butter Masala deliberately misses the milk that paneer brings.
- 6 units with a week of readings, including:
  - a reviewed walk-in chiller breach and a rejected frozen-prawn delivery;
  - an **unreviewed** bain-marie breach and an undercooked fish probe;
  - missed checks;
  - yesterday's completed cooling and today's cooling in progress.
- A week of checklist runs: one missed opening, a signed failed closing and last night's failed closing awaiting sign-off.
- **FW-00001…6** (chicken after the chiller breach, biryani over-production, expired paneer, a cancelled duplicate, returned plates, rice).
- Certificates: Mohan valid; **Sunil's medical expired**, so the F&B roster shows a warning.
- Four prep label batches, and an open table with a nut allergy.

Upgrade: `php artisan migrate && php artisan pms:upgrade`.

Version **5.0.0**. **Tests:** `tests/Feature/Phase40Test.php` has 8 tests (229 assertions); the full suite is 437 tests (13,417 assertions), all passing. The browser walkthrough is `tests/e2e/phase40.py` (14 steps, all passing), with screenshots `docs/screenshots/p40_*.png`.

### v4.9 — Workforce: duty rosters, time clock & kiosk, service charge / tips distribution

**HR & Payroll › Duty Roster** (`/hr/roster`)
- **Shift templates**: code, name, start / end (an end before the start runs into the next day), unpaid break, colour, department (or all) and a minimum-staff target per day. Retired templates stay on old rosters.
- A **week grid per department**: one dropdown per employee and day (shift / OFF / blank), colour-coded. It shows planned hours per employee, a per-day count per shift, and "short" when a shift is under its minimum. Past days and approved full-day leave are locked.
- **Working-time warnings** (they warn, they do not block): less than **11 h rest** between shifts, more than **6 consecutive days**, and planned hours above the **40 h normal week** (Labour Act 2003, Act 651). All three limits can be changed under *Rules*.
- **Copy last week** fills empty open days. **Publish** makes the week visible to staff. Any edit after publishing marks the week *changed – republish*. There is a printable landscape sheet with signature lines.
- **Shift swaps**: an employee asks a colleague from the same department (exchange, or hand-over if the colleague is off). The colleague accepts in self-service, then a manager approves and the roster days are exchanged. Swaps are only possible on published weeks, at least 12 h before the shift, never with someone on leave, and never decided by one of the two people involved.

**HR & Payroll › Time Clock** (`/hr/time-clock`) and **Time Clock Kiosk** (`/hr/time-clock/kiosk`)
- Staff clock in and out from **self-service** (*My Roster & Time Clock*). An optional **IP / CIDR allow-list** restricts this to the hotel network. Staff can also use the shared **kiosk** with their employee code and a 4–6 digit **PIN**. PINs are hashed; obvious PINs are refused; 5 wrong PINs lock that code for 15 minutes. Staff can change their own PIN with their login password.
- Clock-ins are matched to the rostered shift, including last night's overnight shift. Rules:
  - Clocking in is allowed from 2 h before the start.
  - An arrival more than **5 min grace** after the start counts as late.
  - Early arrival is not paid.
  - The rostered break is deducted. For unrostered stints over 6 h, a 60 min break is deducted instead.
  - Leaving before the end counts as leaving early.
  - Unrostered clocking can be switched off.
- The **day board** shows, per employee: roster vs punches with the status *on duty / completed / upcoming / no show / no clock-out / leave / day off / not rostered*, plus late and early flags and corrections. Missed clock-outs from earlier days are flagged.
- **Manager corrections**: add, edit or delete a punch, always with a reason. Corrections must be within 16 h, can't overlap another punch, can't be in the future, and can't touch a month whose payroll is approved. They are shown on the timesheet with the editor's name.
- **Approve day → attendance**: writes P / H (under half the shift) / A (rostered no-show) / WO (rostered off) with the in / out times. Leave days are left alone. Anyone still clocked in blocks their own row until the punch is closed. Payroll reads attendance as before.

**HR & Payroll › Service Charge & Tips** (`/accounts/service-charge`)
- **Tips register**: card, MoMo, cash box, banquet and other gratuities are paid into the pool (Dr cash / card clearing / MoMo, Cr **2110 Service Charge Payable**). Cancelling needs a reason and is blocked once the period has been distributed.
- **Distribution** for a closed period:
  - *Collected* is everything credited to 2110 in the period (POS / folio service charge, tips, vouchers).
  - The **house retention** (10%) is taken off; the rounding carried in from the last distribution is added.
  - The pool is shared among eligible staff by **points × days worked** (P = 1, H = ½), by points pro-rated to the days employed, or equally.
  - Shares are floored to the pesewa and the remainder is carried forward.
  - Individual staff can be excluded with a reason; their share is redistributed.
- **Staff points** (manager / head 3, supervisor 2, others 1) and eligibility can be edited per employee. Back office is not in the tronc by default.
- **Approval** is by a second person (never the preparer). The house share is posted Dr 2110 / Cr **4095 Service Charge Retained**.
- **Post to payroll** adds each share to the employee's payslip in a draft run as a taxable *Service charge* earning (PAYE applies; not part of the SSNIT basic). The line is charged to 2110, so the payroll journal clears the pool. Cancelling removes the lines while the run is still a draft.
- A printable distribution sheet with a staff signature column.

**Reports**: *Roster vs Actual*, *Timesheet (Time Clock)*, *Service Charge Distribution* (141 reports). The dashboard shows a *Workforce* line (on duty, late, no-shows, swaps to approve, time items to fix).

**Permissions**: `roster.view`, `roster.manage`, `timeclock.approve`, `timeclock.kiosk`, `servicecharge.manage`, `servicecharge.approve`. Default grants:
- HR Manager: all roster and time-clock permissions, plus service charge preparation.
- FOM, Housekeeping Supervisor, F&B Manager and Chef: roster management and time approval.
- F&B Manager: can also prepare service charge.
- Accountant: prepares and approves service charge; can view rosters.
- Security Officer: runs the kiosk.

**Demo data**:
- Shift templates M / E / N / G / SP.
- Rosters for last, this and next week. Next week's spa roster is still a draft.
- A week of punches, including:
  - late arrivals, a no-show and an early departure;
  - a missed clock-out (steward);
  - a corrected punch;
  - unrostered call-out work.
- Days D-6 … D-2 are approved into attendance.
- Swaps in three states: waiting for Ramesh (log in as `attendant`), waiting for a manager, and approved.
- Kiosk PINs EMP001 **4821** and EMP005 **3907**.
- August and September tips.
- **SCD-00001** (August) approved and ready to post to September's draft payroll.

Upgrade: `php artisan migrate && php artisan pms:upgrade`.

### v4.8 — Procurement II: RFQs, comparative statements, price lists, approval matrix, partial GRNs, supplier scorecard

**Inventory › Supplier Price Lists** (`/inventory/supplier-prices`)
- Agreed rates per supplier × item with *valid from / to*, a minimum order quantity (quantity breaks) and a lead time. The current, upcoming, expiring (≤14 days) and expired states are shown. A new price for the same supplier, item and quantity break closes the previous one. The cheapest current rate per item is marked **best**.
- New POs take the agreed rate when a supplier and item are chosen. Lines above the agreed rate by more than the **price tolerance (2%)** are flagged on the PO and need a level-2 approver.

**Purchase-order approval matrix** (settings on the Purchase Orders page)
| PO total (incl. tax) | Who may approve |
|---|---|
| up to GH₵5,000 (level 1) | store (`inventory.manage`) |
| up to GH₵25,000 (level 2) | purchasing manager (`purchasing.approve` – F&B Manager, Accountant) |
| above (level 3) | general manager (`purchasing.approve_high` – admin) |
Optional **segregation of duties** stops whoever raised a PO from approving it. The approver and time are recorded, and the approval panel on the PO explains who can approve and why.

**Inventory › RFQs & Quotations** (`/inventory/rfqs`)
- An RFQ lists items, quantities, specifications, a *quotes due* date and terms, and invites suppliers. **Send** e-mails each supplier a private link (`/quote/{40-char token}`, `noindex`, throttled). Suppliers without e-mail get a printed RFQ with the link.
- **Supplier portal:** no login. The supplier enters a unit price, tax %, the quantity they can supply and a brand/remark per item, plus a reference, validity, delivery days and payment terms. The total updates live. Suppliers may revise a quote until the deadline (revisions are counted) or decline with a reason. The buyer can also key in quotes received on paper, WhatsApp or by phone.
- **Comparative statement:** item × supplier bids, landed cost incl. tax, the **L1 (lowest)** bid for the full quantity highlighted, the last purchase rate, items quoted, delivery, terms, validity and each supplier's 12-month scorecard grade. It can be printed for the file. Fewer quotes than the minimum (3) need a written note.
- **Award:** per line (split awards allowed). Choosing anything other than L1 needs a justification. It raises **one draft PO per supplier** (then the approval matrix applies) and can add the awarded rates to the price lists until the quote expires. Declined, cancelled and closed states are tracked.

**Goods receipts (GRN)** (`/inventory/goods-receipts`)
- Receive a PO **in parts**: delivered, rejected (with a reason) and accepted quantities per line, plus delivery note, vehicle and invoice numbers. Over-delivery is refused beyond the tolerance (0% by default). The PO moves *approved → partial → received*. Stock is taken in only for accepted quantities.
- **Short-close** a part-received PO with a reason. Overdue deliveries are listed and badged. GRNs can be printed.
- **Accounts payable** bills from a GRN or for the received value not yet billed (a *part-received* badge). The ageing, statement and control balance follow the GRNs.

**General ledger:** each GRN posts *Dr Inventory (+ Dr 1300 input VAT) / Cr Accounts Payable* at its own date. A bill that differs from the received value posts the difference to Purchase Price Variance. Old single-step receipts post as before.

**Supplier scorecard** (`/inventory/supplier-scorecard`): per supplier over a period:
- **on-time %** (GRN on or before the expected date);
- **fill rate** (accepted ÷ ordered, closed POs);
- **quality** (1 − rejected ÷ delivered);
- **price compliance** (PO lines at or below the agreed rate);
- **responsiveness** (quotes ÷ invitations) and average lead days.

The weighted score gives grades A ≥ 90, B ≥ 75, C ≥ 60, else D.

**Dashboard & navigation:** a procurement alert counts POs awaiting *my* approval, overdue deliveries and RFQs ready to award, and the RFQ nav item carries a badge.
**Reports (+3 → 138):** Goods Received (GRN register with rejections), Open Purchase Orders (outstanding value and days late), Supplier Scorecard.
**Permissions:** `purchasing.rfq`, `purchasing.prices`, `purchasing.approve`, `purchasing.approve_high`. Store Keeper gets rfq and prices; Chief Engineer gets rfq; F&B Manager and Accountant get approve.
**Upgrade:** `php artisan migrate && php artisan pms:upgrade`. This adds the tables `supplier_prices`, `rfqs`, `rfq_lines`, `rfq_suppliers`, `rfq_quotes`, `rfq_quote_lines`, `goods_receipts` and `goods_receipt_lines`, plus the PO columns (approval, expected date, received qty). Existing received POs are back-filled as fully received.

Version **4.8.0**. **Tests:** `tests/Feature/Phase38Test.php` has 9 tests (~330 assertions). The browser walkthrough is `tests/e2e/phase38.py` (8 steps, all passing), with screenshots `docs/screenshots/p38_*.png`. Requisition shortfalls are now ordered from the cheapest current agreed price, falling back to the last-used supplier. The full suite is **421 tests / ~12,640 assertions**, all green.

### v4.7 — Petty cash (imprest) & staff expense claims

**Accounts › Petty Cash** (`/accounts/petty-cash`)
- **Floats on the imprest system.** Each float (demo: *Front Office* GH₵3,000 held by the FOM, *Kitchen & Stores* GH₵2,000 held by the chef) has a fixed imprest, a voucher limit, a custodian and its **own asset account** (group *Petty Cash*, codes 1011+). Opening a float transfers the imprest from main cash / bank / MoMo. Cash in the tin + IOUs out + receipts since the last top-up always equal the imprest.
- **Vouchers.** Payee, expense account, amount, receipt photo / PDF (private storage) or a written reason when there is none (above GH₵100). For a **GRA VAT invoice**, the input VAT + NHIL + GETFund defaults to 20/120 of the receipt and can be edited; it is capped at that. A voucher over the float's limit is **held for approval** and no cash leaves the tin until someone else approves it. Back-dating is limited to 31 days and never into a locked GL period.
- **IOUs (cash advances)** to named staff with a due date (3 days by default). **Settlement** records what was spent against an expense account. The change comes back, or an overspend is paid out. An overdue IOU blocks another for the same person. Cancelling a settlement re-opens the IOU.
- **Cash counts** by cedi note and coin (GH₵200 … 1 Gp) with a live total and difference. Agreed counts are recorded. Differences up to the tolerance (GH₵1.00) are written off at once. Larger ones wait for approval by someone other than the counter, and are refused if cash moved since the count.
- **Top-ups.** A request for exactly *imprest − cash − IOUs out* lists the receipts it covers. It is approved by someone other than the requester, then paid from cash / bank / MoMo. Reimbursed vouchers can no longer be cancelled. A top-up summary can be printed.
- **Controls.** Custodians operate only their own float; approvers can operate any float. Overview cards turn red when a float is low (below 25%) or an IOU is overdue, and amber when approvals are pending or a count is due (every 7 days). There is a nav badge and a dashboard alert. Voucher / IOU prints include the amount in words and signature lines.

**Staff expense claims** (`HR › My Expense Claims`, `Accounts › Expense Claims`)
- Every self-service role can claim. Categories: transport, **mileage (km × rate, GH₵2.50/km)**, fuel, accommodation, meals on duty, client entertainment, airtime & data, stationery, small repairs, other. Each category maps to its expense account (new 6160 Travel & Transport, 6165 Staff Meals & Welfare, 6170 Business Entertainment).
- Rules: receipt (file or receipt / MoMo number) above GH₵50; expenses at most 60 days old; draft → submitted → approved / sent back (edit and resubmit) → paid. Claimants and whoever raised a claim can never approve it. Approvers may raise claims for staff without a login.
- Pay by bank transfer, Mobile Money or cash, or **in cash from a petty cash float**. A printable claim form includes a certification and signatures.

**General ledger** (posted at the night audit through `LedgerService::sync`)
| Event | Dr | Cr |
|---|---|---|
| Top-up / opening imprest | Float (101x) | Cash / bank / MoMo |
| Voucher | Expense (net) + 1300 input VAT | Float |
| IOU issued | 1420 Petty Cash IOUs | Float |
| IOU settled | Expense + input VAT; float (change) | 1420; float (overspend) |
| Count shortage / overage | 6190 Cash Shortages & Overages / float | float / 6190 |
| Claim approved | Expense heads (net) + input VAT | 2260 Staff Expense Claims Payable |
| Claim paid | 2260 | Bank / MoMo / cash, or the float |

Cancelled or rejected items are reversed at the next sync. Input tax falls into the expense when *GL › input VAT recoverable* is off.

**Reports (+3 → 135):** Petty Cash Book (running balance per float), Petty Cash Spending by Account (account × float, input VAT), Staff Expense Claims Register.
**Permissions:** `petty.view / manage / approve`, `claims.submit / approve / pay`. Accountant gets all of them; Front Office Manager and Chef get `petty.view` and `petty.manage`; every role with `hr.self` gets `claims.submit`.
**Settings:** receipt thresholds, IOU days, count frequency, low-balance %, count tolerance, mileage rate, claim age limit.
**Upgrade:** `php artisan migrate && php artisan pms:upgrade` (tables, accounts 1420 / 2260 / 6160–6190, permissions, settings).

Version **4.7.0**. **Tests:** `tests/Feature/Phase37Test.php` has 12 tests (~250 assertions). The browser walkthrough is `tests/e2e/phase37.py`, with screenshots `docs/screenshots/p37_*.png`. The full suite is **412 tests / ~12,260 assertions**, all green.

### v4.6 — Bank & Mobile Money reconciliation

Version **4.6.0**. The upgrade is `php artisan migrate && php artisan pms:upgrade`. It creates the `bank_statements`, `bank_statement_lines` and `bank_matches` tables and three system accounts: **6125 Bank Charges**, **6126 Mobile Money Charges** and **4088 Interest Income**. It also grants the new permissions to the Accountant role and adds the reconciliation settings.

| Area | Where | What it does |
|---|---|---|
| **Overview** | Accounts › Bank Reconciliation | There is one card per reconcilable account: bank, Mobile Money, card settlements and payment gateway (cash and foreign currency on hand are counted, not reconciled). Each card shows the book balance and one of four states: *Not started*, *In progress*, *Up to date* or ***Overdue*** (the last reconciled statement ended more than 35 days ago; this is a setting). Below the cards is the list of statements. |
| **Statements** | *New statement* | Statements follow on from each other. Each account can have **one statement in progress**, and each statement starts the day after the previous one ends, opening at its closing balance. A statement cannot end after today. For a first statement, the form suggests the opening balance from the ledger. **CSV import** handles internet-banking and MoMo merchant exports: <br>• it skips a title / account block above the table and detects the delimiter (`,` `;` tab `|`); <br>• it recognises common headings (Date / Value Date, Narration / Details, Reference / Transaction ID, Debit / Credit or Amount, Balance, Fee); <br>• it skips opening, closing and total rows; <br>• dates can be d/m/Y, m/d/Y, ISO or 03-Sep-26, and impossible dates are rejected; <br>• amounts can be `1,234.50`, `(1,234.50)`, `1,234.50 DR`, `GH₵` or a decimal comma; <br>• a **Fee** column becomes its own money-out line; <br>• if headings aren't recognised, you can type the **column names** yourself; <br>• an **"Amounts are the bank's view"** tick flips the signs. <br>The **running balance is checked** line by line, the lines must add up to the closing balance, and **lines already imported** for the account are left out. You can also type lines in by hand. |
| **Matching** | The statement page | **Auto-match** runs three passes, and runs automatically after an import: <br>1. same amount with the statement reference found in the journal (cheque numbers, SSNIT / GRA references); <br>2. same amount, closest date within **±5 days** (a setting); <br>3. **one statement line = all of one day's receipts** (card or MoMo settlement batches). <br>To match **by hand**, pick a statement line, then tick the book entries that make it up. The *Match* button only enables when the running total equals the line. The book entries listed exclude those already cleared and reversed pairs. Matches can be undone. |
| **Bank-only items** | *Book* on a line | Bank charges, MoMo charges, interest earned, or another account of your choice. The item posts a journal on the statement date (for example **Dr 6125 / Cr Bank**) and matches the line to it. Sign checks stop you booking charges on money in, or interest on money out. Unmatching **reverses** the journal. An item can only be booked for dates the night audit has closed. Lines that aren't in the books can be **ignored** with a reason; they stay on the reconciliation. |
| **Reconciliation statement** | BRS card, *Reconciliation statement* (print) | Balance per statement **+ deposits in transit − unpresented payments ± statement items not in the books ± opening difference = balance per books**, with the **unexplained difference**, which should be nil. The printed version lists every outstanding item and has signature lines. |
| **Finalise / reopen** | Needs `bankrec.approve` | A statement can be finalised only when: its lines add up; it follows on from the previous statement; nothing is left to match; and the difference is nil. Optional **segregation of duties** means whoever loaded a statement cannot finalise it. A finalised statement is locked. Only the **latest** statement can be reopened, and a reason is required. A draft can be deleted: entries booked from it are reversed and its book entries are freed. |
| **Amended entries** | Automatic | If a matched book entry is later amended (reversed and re-posted), the match **moves to the new journal**. If the entry disappears instead, the line goes back to *unmatched* with a note, and a reconciled statement is flagged **Review needed** (nav badge, banner). |
| **Alerts** | Nav badge, dashboard | The nav badge counts overdue accounts and statements needing review. The dashboard shows *N bank statement line(s) to match* or *account(s) to reconcile*, with a *Reconcile* link. |
| **Reports (+3 → 132)** | Reports › Accounts | **Bank Reconciliation Summary** (per account at a date), **Uncleared Items** (deposits in transit, unpresented payments and statement-only items, with their age) and **Bank Statement Lines** (how each line was matched and the journals it cleared). |

**Permissions:** `bankrec.view`, `bankrec.manage` (import, match, book, ignore) and `bankrec.approve` (finalise, reopen, settings). The Accountant role has all three.

**Demo (hotel NOWILLS, user `accounts`):**
- **BST-00001 (bank, August) is reconciled.** The account maintenance fee (GH₵45.00) was booked from the statement, and the month's last deposit and cheque are in transit.
- **BST-00002 (bank, 1–25 September) is in progress:**
  - 11 of 14 lines were auto-matched, including last month's transit items;
  - still open: *SMS alert & COT charges* (−38.50), *credit interest* (+112.40) and an **unidentified transfer of GH₵1,250.00**;
  - two payments have not been presented yet.
  
  Book the first two, ignore the transfer, then finalise.
- **BST-00003 (MTN MoMo merchant wallet)** matches daily collections as batches. The 1 % service charges for earlier days are booked; the last few days are still open.

**Tests:** `tests/Feature/Phase36Test.php` has 13 tests (220 assertions). The browser walkthrough is `tests/e2e/phase36.py`, with screenshots `docs/screenshots/p36_*.png`. The full suite is **400 tests / ~11,960 assertions**, all green (the Phase 3 iCal test was made robust to a random demo booking next to its sold-out dates).

### v4.5 — Ghana payroll statutory returns (SSNIT / Tier 2 / PAYE) & the Ghana licence checklist

Version **4.5.0**. The upgrade is `php artisan migrate && php artisan pms:upgrade`. It creates the `statutory_remittances` table and adds the statutory-return settings. It also renames the India licence items on existing properties to their Ghana equivalents: items already renamed by the hotel are left alone.

| Area | Where | What it does |
|---|---|---|
| **Statutory returns** | HR & Payroll › Statutory Returns | For every payroll month, three returns are built from the **approved payslips**. **SSNIT Tier 1** is 13.5 % of basic (employee 5.5 % + employer 8 %) and is paid to SSNIT. **Tier 2** is the employer's 5 % and is paid to the corporate trustee. Both are due by the **14th of the next month**. **PAYE** is paid to GRA by the **15th**. Each return shows per-employee rows: SSNIT number / Ghana Card, basic, insurable earnings (capped at GH₵69,000), and the employee and employer shares. The PAYE rows show allowances, overtime, gross, SSNIT relief and chargeable income. Other features: status cards (*Payroll not approved*, *Due*, *Overdue*, *Paid*, *Paid late*); a check that the payroll journal credited the same amount to the liability account; and employees missing an SSNIT number or TIN. |
| **Schedules for upload** | *Schedule (CSV)* on each tab | `SSNIT_<employer no>_<YYYYMM>.csv`, `TIER2_<scheme>_<YYYYMM>.csv` and `PAYE_<TIN>_<YYYYMM>.csv`. They are UTF-8 with a BOM and protected against formula injection. |
| **Recording payments** | *Record payment* (needs `payroll.approve`) | Enter the date, the account paid from (cash, bank or Mobile Money) and the SSNIT receipt or GRA payment reference. This posts **Dr SSNIT Tier 1 / Tier 2 / PAYE Payable, Cr bank**; cancelling reverses the journal. Guards: the payroll must be approved; each return can be paid once; the payment date must fall between the end of the month and today; Tier 2 needs a trustee. A month with a payment recorded **cannot be reopened** in payroll until the payment is cancelled. |
| **Alerts** | Nav badge, dashboard, the payroll run | Returns overdue or due within 5 days over the last three months appear in the nav badge (overdue count) and the dashboard (*Pay*). The payroll run links to its returns. |
| **Minimum wage** | Statutory Returns, payroll run | Employees whose basic salary is below the **national daily minimum wage** (GH₵21.77 from 1 Jan 2026) × 27 days = **GH₵587.79 a month** are flagged. The rate and the days are configurable. |
| **Settings** | Statutory Returns › Settings (needs `payroll.manage`) | SSNIT employer number, Tier 2 trustee and scheme number, maximum insurable earnings, minimum wage and days. |
| **Ghana licence checklist** | Engineering › Licences & Compliance | The standard list is now Ghanaian: GTA Hotel Operating Licence and Star Rating; FDA Food Hygiene Permit; GNFS Fire Certificate; Assembly Business Operating Permit, Liquor, Signage and Swimming Pool permits; EPA Environmental Permit; Factories Inspectorate premises, lift and boiler certificates; Energy Commission electrical certificate; GHAMRO music licence; GSA scale verification; **Data Protection Commission registration**; compulsory commercial building fire insurance; and Food Handlers' health certificates. |
| **Reports (+3 → 129)** | Reports › HR & Payroll | **SSNIT Contribution Schedule**, **PAYE Monthly Schedule (GRA)** and the **Statutory Remittance Register** (due date, days late, reference, paid from). |

**Demo:**
- As `hr` or `accounts`, open **HR & Payroll › Statutory Returns**. July 2026 was paid on time (12–14 Aug).
- For August, SSNIT was paid on 11 Sep and PAYE on 14 Sep. **Tier 2 (GH₵2,978.24) is overdue**: record it as `accounts` to see a *Paid late* payment.
- September is still a draft payroll.
- The licence register shows the GTA operating licence expired and the GNFS fire certificate in renewal.

**Tests:** `tests/Feature/Phase35Test.php` has 17 tests. The browser walkthrough is `tests/e2e/phase35.py`, with screenshots `docs/screenshots/p35_*.png`. The full suite is **387 tests / ~11,520 assertions**, all green.

**Note:** Contribution rates and the insurable cap follow the National Pensions Act 2008 (Act 766). The minimum wage is set by the National Tripartite Committee. Confirm the SSNIT / GRA upload formats with the portals you use.

### v4.4 — Ghana compliance II: E-VAT on restaurant bills, withholding tax, Mobile Money, Act 843

Version **4.4.0**. The upgrade is `php artisan migrate && php artisan pms:upgrade`. It adds the new columns and the `wht_periods` table, and creates accounts **2250 Withholding Tax Payable (GRA)** and **2255 Withholding VAT Payable**. It also retires the UPI payment mode and seeds the WHT settings.

| Area | Where | What it does |
|---|---|---|
| **E-VAT on restaurant bills** | POS › Settle, Billing › VAT › E-VAT › *Restaurant bills* | A bill paid at an outlet is submitted to the GRA Virtual SDC when it is settled. Settlement can be by cash, card, Mobile Money or bank; room-posted bills are covered by the guest's invoice, and complimentary bills are not a supply. The settle dialog has an optional **customer TIN / Ghana Card PIN and name** for business customers; without one, the bill goes to E-VAT as a cash customer (`C0000000000`). Each item is sent tax-exclusive, with its share of the discount and its VAT / NHIL / GETFund / Tourism levy (the service charge is its own line). The printed bill shows the **SDC ID, receipt number, time, QR code and signature**. If E-VAT cannot be reached, the bill is still settled and printed "E-VAT pending", and it is retried automatically with back-off (`pms:einvoice-retry`, every 15 min). The *Restaurant bills* tab lists every bill with its status. From there you can **Retry now**, submit one bill, or **correct the customer TIN** of a refused bill and resubmit it; a stamped bill can't be changed. Unstamped bills in the month **block the VAT return** pre-filing check, and the dashboard counts failed bills. There is an on/off switch under VAT › Settings. |
| **Withholding tax (WHT)** | Accounts › Supplier Payments, Accounts › Withholding Tax (GRA) | Each supplier has a **nature of payment**: goods 3 %, works 5 %, services 7.5 %, residential rent 8 %, commercial rent 15 %, non-resident 20 %, or not subject. A supplier can also have a GRA **exemption certificate** with a validity date. When you pay a supplier, the form **quotes the tax live**. It is calculated on the VAT-exclusive part of the bills settled (money on account counts as exclusive). Goods, works and services are withheld only once the year's payments to that supplier exceed the **GH₵ 2,000 threshold** (a setting); rent and non-resident suppliers are withheld on any amount. To skip withholding, the user must give a reason, which is kept on the payment. The whole bill is cleared from the supplier's account. The bank pays the **net** and the rest is owed to GRA: *Dr Accounts Payable / Cr Bank (net) / Cr 2250 WHT Payable*. Each withheld payment gets a numbered **WHT credit certificate** (`WHTC-…`) for the supplier, and the remittance advice shows the deduction. An optional **withholding VAT agent** mode withholds 7 % of the standard-rated taxable value to 2255. |
| **Monthly WHT return** | Accounts › Withholding Tax (GRA) | For each month: payments withheld by nature, a check that the GL ties to the return, suppliers without a valid TIN, the due date (15th of the next month) and a **CSV schedule for GRA** (`WHT_<TIN>_<YYYYMM>.csv`, formula-safe). You mark the month **filed** with the GRA reference. After that, a withheld payment in that month can't be cancelled and no new withheld payment can be dated in it. The dashboard shows *WHT return due / overdue* until the last month is filed. |
| **Mobile Money** | Payment modes | India's UPI mode is retired: it is kept inactive for history, and new properties no longer get it. **Mobile Money (MoMo)** posts to 1040 Mobile Money Clearing. Demo advances and voucher sales now use MoMo references. |
| **Data protection & ID** | Privacy & Data, visitor register, guest ID | The wording follows Ghana's **Data Protection Act 2012 (Act 843)**: *data-subject* requests, and breaches notified to the **Data Protection Commission**. **Ghana Card** is the first ID type, and **ECOWAS ID card** is added. Aadhaar / PAN stay as "(India)" for existing records. |
| **Reports (+2 → 126)** | Reports › Accounts, GRA VAT & E-VAT | **Withholding Tax Register** (supplier TIN, nature, taxable amount, rate, WHT, withholding VAT, net paid, certificate). **Restaurant Bills E-VAT Register** (customer TIN, taxable value, VAT, levies, receipt number / error). |

**Demo:**
- As `accounts`, open **Accounts › Withholding Tax (GRA)**. August 2026 is filed (reference `WHT/202608/0419`) and September is running.
- Pay *TechWorld IT Solutions* to see the live quote (goods, 3 %) and the certificate.
- *SparkleWash Laundry* holds an exemption certificate, and *City Power* is not subject.
- Under **Billing › VAT › E-VAT › Restaurant bills**, this month's outlet bills are stamped. One went to *Infosys Ghana Ltd* with its TIN. The latest bill timed out and is waiting for the retry: click *Retry now* or correct its TIN.

**Tests:** `tests/Feature/Phase34Test.php` has 20 tests. The browser walkthrough is `tests/e2e/phase34.py`, with screenshots `docs/screenshots/p34_*.png`. The full suite at v4.4 was 370 tests.

**Note:** WHT rates and the threshold follow the Income Tax Act 2015 (Act 896) as amended. Confirm them, and the E-VAT API fields, with GRA when you certify.

### v4.3 — Ghana localisation: GRA taxes & Ghana cedis

The whole system now runs on **Ghana Revenue Authority** rules, with the **Ghana cedi (GHS, GH₵)** as the base currency. India's GST, TDS, PF/ESI and RBI rules have been removed from the calculations. The version sections below v4.3 are kept as a history, so they still describe the original Indian setup (₹, GST, GSTR-1, Form C…).

| Area | Where | What changed |
|---|---|---|
| **Currency** | Hotel settings, everywhere | Base currency **GHS**, symbol **GH₵**, time zone *Africa/Accra*, country Ghana. All amounts, prints, CSVs, e-mails and SMS use cedis. Room and F&B demo prices keep their numbers; salaries were rescaled to Ghana levels. |
| **Output taxes** | Masters › Taxes, Billing › VAT › Settings | New properties get them automatically, and `php artisan pms:upgrade` converts existing ones. They are **VAT 15 %, NHIL 2.5 %, GETFund levy 2.5 %** (same base, non-cascading, VAT Act 2025 / Act 1151, from 1 Jan 2026) and the **Tourism Levy 1 %** (Act 817, L.I. 2185) on rooms, F&B, banquets and other hotel services. That makes 21 % in total on a tax-exclusive rate: GH₵ 1,000 → 150 + 25 + 25 + 10 = **GH₵ 1,210**. The COVID-19 levy and the flat-rate scheme are gone. Forex and other financial services are exempt. |
| **Ledger** | GL | VAT, NHIL and GETFund go to *2100 VAT / NHIL / GETFund Payable*. The Tourism Levy goes to its own account, *2105 Tourism Levy Payable (GTA)*. Input VAT, NHIL and GETFund on purchases are claimable (*1300*). |
| **GRA E-VAT** | Billing › VAT › E-VAT, folio panel | Invoices, receipts and refunds are signed by the GRA **Virtual Sales Data Controller** before they are issued. The payload follows the E-VAT API: tax-exclusive, levies A–E per item, buyer TIN (or the cash-customer TIN `C0000000000`), exchange rate. The SDC ID, receipt number, internal data, signature, time and **QR code** are printed on the invoice. There is a built-in **sandbox** driver (stamps marked *NS*) and a *gra* driver for the certified endpoint. Failures retry with back-off and appear on the dashboard. |
| **TIN** | Companies, guests, hotel, walk-in bill | Checks a Ghana **TIN** (`C0001234567`: an individual, company, government, … prefix + 10 digits) or a **Ghana Card PIN** (`GHA-123456789-0`). An Indian GSTIN is refused. |
| **Credit notes** | Folio › *Issue credit note* | Reverse each levy at the rate charged. The credit note is signed as an E-VAT **REFUND** (receipt ending *NR*) that refers to the original invoice. |
| **VAT return** | Billing › VAT › VAT Return | A monthly VAT / NHIL / GETFund return: standard-rated supplies, output tax, input tax, **net payable to GRA**, the sales register, the Tourism Levy summary, documents issued, pre-filing checks, a **CSV export** and *Mark as filed* (with the GRA acknowledgement number, which locks the month). It is due on the **last working day of the next month**. The Tourism Levy is due to the GTA **30 days after month end**. |
| **Payroll** | HR & Payroll | **SSNIT**: employee 5.5 %, employer 13 % (13.5 % Tier 1 + 5 % Tier 2), capped at GH₵ 69,000 a month (2026). **PAYE**: the 2026 GRA resident monthly bands (0 / 5 / 10 / 17.5 / 25 / 30 / 35 %) on income after SSNIT, editable under HR & Payroll › Pay Components › *PAYE income tax (GRA)*. Payslips, statutory reports and the GL use *SSNIT Tier 1 / Tier 2 / PAYE Payable (GRA)*. |
| **Reports** | Reports › GRA VAT & E-VAT | The E-VAT register, the credit-note register and the VAT / levy summary by category, with VAT, NHIL, GETFund and Tourism Levy columns. |

**Internal names kept for compatibility.** Database columns, routes and permission keys keep their old names, so existing data, API clients and tests still work. `gst_*` settings and the `gst.*` route names / `gst.view` permission (URLs are now under `/vat`); `folios.irn*` columns hold the E-VAT SDC ID / receipt / QR; `buyer_gstin` holds the buyer TIN; columns ending in `_inr` hold base-currency (cedi) amounts; the system account keys `pf` / `esi` / `paye` are SSNIT Tier 1 / Tier 2 / PAYE; `bank_ifsc` holds the bank sort code. The *UPI* payment mode is still in the list (rename it to *Mobile Money* under Masters if you like). For cards and mobile money online, use the **Paystack** gateway.

**Demo:** log in as `accounts` and open **Billing › VAT**. The Yatra invoice failed E-VAT because the company TIN is wrong. As `admin`, correct the TIN under Companies, then click *Refresh buyer* on the folio, and the invoice is stamped. The VAT return for the previous month is marked filed.

**Not changed yet (planned):** the demo staff and guests keep their names. *(The licence list became Ghanaian in v4.5.)* *(POS E-VAT, withholding tax, Act 843 and the Ghana Card were added in v4.4.)*

### v4.2 — what's new: security & gate management

A hotel's gate and lobby are where security happens: who walks in to see a guest, what equipment leaves for repair and whether it comes back, and what happened in the car park last night. Until now all of that lived in paper registers at the security desk. v4.2 moves it into the PMS, linked to the in-house guests, the staff, the banquet functions and the fixed-asset register. The new **Security** menu has *Visitors*, *Gate Passes*, *Incident Register* and *Watch List*.

| Area | What it does |
|---|---|
| **Visitor register** | Register a visitor to an **in-house guest**, a **staff member**, a **function** or *other* (vendor, interview, delivery) with mobile, ID shown, company, vehicle, persons and items declared. A pass number (VIS-…) and a printable **badge** with QR and validity. The board shows who is on site, passes that ran out while the visitor is still inside (**overstay**, highlighted), who is waiting for the guest's OK and who is expected. Extend a pass, check a visitor out, search the full log. |
| **Guest confirmation & privacy** | By default every visit to a guest room is confirmed with the guest by phone: the visitor waits as *Awaiting guest OK* until the desk clicks **Guest says yes** or turns them away with a reason. A guest can ask for **no visitors** (the desk is told not to confirm they are staying here) or **call me first**. The desk or the guest (in the portal) sets it. **Visiting hours** (default 22:00) cap the pass for guest-room visits. Visits to a guest who has checked out are refused. A security manager can override any of these, with a reason that is logged. |
| **Guest pre-registration** | From the stay portal the guest registers a visitor (name, date, time, persons, note) and gets a **shareable pass link** with a QR for the visitor's phone. The page shows no room number. The desk sees *Expected* visitors and checks them in with **Arrived** (ID captured then). No-shows expire automatically, and expected visitors are cancelled when the guest checks out. |
| **Watch list** | Barred persons are **refused at registration**. *Alert* entries let the visitor in but post a high-priority security entry to the log book. Matching is on the phone number (last 10 digits), a **keyed hash of the ID number** (the number itself is never stored) or, as a possible match only, the name. Entries can expire or be lifted, and hits over the last 90 days are counted. Only security managers see or edit the list. |
| **Material gate passes** | Any department raises a **returnable** or **non-returnable** pass (GP-…) for goods leaving the hotel: repairs, returns to supplier, scrap, loans, laundry, events. Each pass lists up to 6 items, optionally **fixed assets** (the description fills itself; one open pass per asset; disposed assets refused). A manager **other than the requester** approves or rejects it; approval can be switched off. The gate officer checks the goods out (vehicle and carrier) and the pass prints for the driver. Returns are received **in instalments** against each line (*Partly returned* → *Returned*). Overdue passes are flagged daily; the return date can be moved or the pass closed with a note. Assets show *Under repair* while out and return to *Active* when received. |
| **Incident register** | Theft / loss, damage, trespass, disturbance, medical, fire / safety, fraud, lost child and more, with severity, time, place, room, guest involved, persons and witnesses, action taken, loss amount, injuries and police station / FIR. **High** and **critical** incidents post to the log book (urgent for critical) and SMS the duty-manager number. Follow-up notes move the incident to *Investigating*. Only a manager closes it, and only with a **resolution**. A closed incident is locked unless reopened with a reason. |
| **Automation & privacy** | `pms:security` runs every 15 minutes. It alerts once per overstaying visitor, expires no-shows, flags overdue gate passes once, and **anonymises** visitor names, phones and ID details after the retention period (default 180 days). Guest-privacy erasure (v2.9) cancels a guest's pending visitors and scrubs their name from visitor records and incidents, and the access export includes both. |

Permissions (new group *Security*): `security.view`, `security.visitors`, `security.gate`, `security.gatepass`, `security.approve`, `security.incidents`, `security.manage`. The new **Security Officer** role (demo user `security`) registers visitors, works the gate and records incidents. The front-office manager has everything. Receptionists and the concierge register visitors. The chief engineer raises and approves gate passes. Housekeeping, F&B, stores, laundry and banquets raise gate passes (and see only their own). Reports › Security: *Visitor register*, *Gate passes*, *Goods still out (returnable)* and *Security incidents*, all with CSV export.

**Demo:** log in as `security` / `Pass@123` (or `fom`, `admin`) and open **Security › Visitors**.
- Four visitors on site, one of them **overstaying**, a watch-list *alert* visitor, a contractor from the lift company. One visitor is **waiting for the guest's OK** (the guest asked to be called first), and another room is marked **no visitors**.
- Two visitors pre-registered by a guest from the portal, ten days of visitor history, and a barred person who was turned away.
- Gate passes: a dishwasher at the vendor and **overdue**, an AC unit under repair, banquet chairs **partly returned** (40 of 60), a returned and a closed pass, a DG-set part **awaiting approval**, an approved chafing-dish loan and a rejected one.
- Incidents: a wristwatch reported missing (under investigation), a trespass with an FIR, a medical call, and more.

### v4.1 — what's new: upselling & pre-arrival offers

Every guest who arrives in a Standard room is a chance to sell a better room, breakfast, an airport pick-up or a late check-out. v4.1 turns that into a process: a catalogue of offers, a personalised page for each guest, prompts on the check-in screen, charges that post themselves, and numbers that show what works. Revenue › **Upselling** is the console.

| Feature | What it does |
|---|---|
| **Offer catalogue** | Four kinds: **room upgrade**, **add-on**, **early check-in** and **late check-out**. Pricing per stay, per night, per person, per person per night, or – for upgrades – a **% of the rate difference** with a minimum per night (e.g. 60 % of the difference, at least ₹900). Each offer has a guest-facing name, description and icon, the folio charge category and tax, and rules: channels (pre-arrival / front desk / in-house portal), eligible booked room types, booking sources, minimum nights, how many hours before arrival online booking closes, a daily limit (e.g. six pick-ups a day), an arrival-date validity window and a display order. Offers that were sold can be switched off but not deleted. |
| **Eligibility** | An offer is only shown when it can really be delivered: upgrades and early check-in before check-in only, add-ons and late check-out also in house; an upgrade only to a dearer type that still has a room free for the whole stay (optionally keeping the last *n* rooms back for full-price bookings); one upgrade / early check-in / late check-out per stay; the daily limit; early check-in disappears once standard check-in time has passed. The guest sees the exact price for their stay (e.g. *₹450 × 2 guests × 2 nights*). |
| **Pre-arrival e-mail & guest page** | `pms:upsell` (hourly) e-mails confirmed guests *n* days before arrival (default 3) – once per reservation – with up to six personalised offers and a link to their private **offers page** (`/offers/{token}`). There they add items in one tap, send a note with a request (flight time), see what they chose, and can remove confirmed items until a set number of hours before arrival (requests any time). After check-in the same link shows the in-house offers; after check-out, cancellation or a no-show it closes. The desk can e-mail or copy the link from the reservation (e.g. for WhatsApp). |
| **Desk selling** | The **check-in screen** lists what this guest can still buy – with the incentive the clerk earns – and adds it in one click. The **reservation** has an Upsells card with its orders, a sell box, the e-mail button and the guest link. The guest portal shows an *Upgrade & enhance your stay* button before arrival and an *Extras & late check-out* tile in house. |
| **Orders & charges** | Confirming an order creates a **fixed charge** on the stay: nightly items (upgrades, breakfast) are posted by the night audit, one-off items on the arrival night or at check-out. An **upgrade switches the stay's room type** straight away (releasing a pre-assigned room of the old type) and bills the difference nightly. Early check-in / late check-out stamp the agreed time on the stay; they can be set to need the desk's **confirmation** (Requests tab, nav badge) – a decline needs a reason, and requests nobody answered are declined automatically once their date passes or at check-out. Cancelling stops the charge and moves an upgrade back to the original type; anything already on the folio must be voided first. Cancelling or no-showing the reservation closes its orders. |
| **Incentives & measurement** | Desk sales earn the clerk a % of the sale or a fixed amount per offer (nothing for guest self-service; a cancelled sale earns nothing). Every time offers are shown – e-mailed, opened, displayed at check-in – a *presentation* is recorded, so the console shows **conversion** by channel alongside revenue by type, channel and offer, today's early arrivals / late departures for housekeeping, and upcoming arrivals with whether offers went out. |

Permissions (new group *Upselling*): `upsell.view`, `upsell.sell`, `upsell.manage`. The front-office manager has all three; receptionists view and sell; Sales & Marketing view and manage the catalogue; accounts view. Reports › Upselling: *Upsell revenue by offer* (split by channel, with declined / cancelled counts), *Upsell conversion by channel* and *Staff upsell incentives* (for the payroll incentive run) – all with CSV export.

**Demo:** log in as `fom` / `Pass@123` (or `admin`) and open **Revenue › Upselling**.
- Eight offers: Deluxe upgrade at 60 % of the difference, an Executive room with lounge access, breakfast, airport pick-up (six a day), early check-in 10:00 and late check-out 15:00 (desk confirms), a romance package and a spa credit.
- A month of history on all three channels with about 90 guests offered, and staff incentives on desk sales.
- In house: a desk-sold breakfast, a late check-out today and a spa credit bought in the portal. Upcoming: a Deluxe upgrade with breakfast, an airport pick-up, a romance package, and one declined early check-in.
- **Two requests waiting** for the desk: an early check-in for tomorrow's red-eye arrival and an in-house late check-out.
- Two arrivals in the window have not had their e-mail yet – click **Send due offers now**. Open any upcoming reservation and use **Copy** on the Upsells card to see the guest's page.

### v4.0 — what's new: open REST API & webhooks

A modern hotel runs a dozen other systems around its PMS: the website booking widget, a revenue-management system, housekeeping tablets, an owner BI dashboard, a CRM or data warehouse. v4.0 gives them a documented, versioned, per-property **REST API** (`/api/v1`) and **signed webhooks**, so partners can integrate without database access or CSV exports. Administration › **API & Webhooks** is the console.

**REST API** (`/api/v1`, JSON)

| Feature | What it does |
|---|---|
| **API keys** | Each key belongs to one hotel and has a name, description, owner, **scopes**, a rate limit (default 120/min), an optional **IP allow-list** (addresses or CIDR ranges) and an optional expiry. The token (`nwp_…`) is shown **once**; only its SHA-256 hash is stored. Keys can be paused, **rotated** (the old token stops working at once) or **revoked** with a reason. Every write acts as the key's owner in the activity log (`via API client …`), and a deactivated owner blocks the key. |
| **Scopes & presets** | `availability:read`, `rates:read`, `reservations:read`, `reservations:write`, `guests:read`, `folios:read`, `rooms:read`, `housekeeping:write`, `reports:read`. Presets: *Booking partner*, *Read-only*, *Housekeeping app*, *BI / reporting*, *Guest app*. A missing scope returns `403 insufficient_scope` naming the scope. |
| **Endpoints** | `GET /` (who am I), `/availability` (up to 90 nights, by room type, with the stay price), `/rates` (up to 366 days, overrides and restrictions), `/room-types`, `/reservations` (filters, 25 per page, max 100), `GET/POST /reservations`, `GET /reservations/{no}`, `POST /reservations/{no}/cancel` (reason required, the hotel's cancellation policy and fee apply), `/reservations/{no}/folio`, `/guests?email=|phone=|name=` (max 20 results), `/rooms`, `PATCH /rooms/{no}/housekeeping`, `/reports/daily` (night-audit statistics, up to 93 days) and the public `openapi.json`. |
| **Safe bookings** | `POST /reservations` uses the same availability, rate and stop-sell/min-stay rules as the desk (up to 10 rooms and 60 nights), finds or creates the guest, tags the booking with the client's business source and an `external_ref` (unique per client), and can e-mail the confirmation. An **`Idempotency-Key`** header makes retries safe: a replay returns the original answer with `Idempotent-Replayed: true`; the same key with a different body is refused (`422 idempotency_key_reused`). Keys are kept for 24 hours. |
| **Errors & limits** | One error envelope `{"error": {"code", "message", "fields"}, "request_id"}` with 18 stable codes (401 / 403 / 404 / 405 / 409 / 422 / 429 / 503 / 500). `X-RateLimit-*` and `Retry-After` headers; repeated bad tokens from one IP are slowed down. Responses are `Cache-Control: no-store`. |
| **Request log** | Every call, including refused ones, is logged with client, method, path, status, error code, duration, IP and the `X-Request-Id`, so a partner's support ticket can be traced. Kept for 30 days by default. |

**Webhooks** (events pushed to a partner URL)

| Feature | What it does |
|---|---|
| **Events** | `reservation.created`, `reservation.cancelled`, `reservation.no_show`, `guest.checked_in`, `guest.checked_out`, `room.status_changed` (with the previous status), `payment.received`, `night_audit.completed`, or `*` for all. Events are recorded only after the database transaction commits, so a rolled-back booking never fires. |
| **Signature** | `X-Nowills-Signature: t=<unix>,v1=<HMAC-SHA256(secret, "t.body")>` plus `X-Nowills-Event`, `X-Nowills-Delivery` and `X-Nowills-Event-Id`. **Rotate secret** keeps the old one valid for 24 hours (two `v1` values are sent). The console has a copy-paste PHP verification snippet. |
| **Delivery & retry** | `pms:webhooks` runs every minute with a 10-second timeout. A non-2xx answer is retried after 1 min, 5 min, 30 min, 2 h, 6 h, 12 h and 24 h (8 attempts). After 20 failures in a row (configurable) the endpoint is **switched off**, the log book is told, and the console shows a red alert. Re-enabling it delivers everything still pending. Each delivery page shows the exact signed payload and every attempt; **Retry now** and **Test** (a `ping` event) are one click. |
| **Safety** | HTTPS only and **no private / loopback addresses** (checked after DNS resolution, redirects not followed), unless the hotel explicitly allows an on-premise receiver in Settings. |

Permissions (new group *API & Integrations*): `api.view` and `api.manage`. The administrator has both; Sales & Marketing can view. Reports › API & Integrations: *API usage by client* and *Webhook deliveries* (both with CSV export). The sidebar badge counts switched-off or failing endpoints and failed deliveries.

**Demo:** log in as `admin` / `Admin@123` and open **Administration › API & Webhooks**.
- Five keys: the hotel website booking widget, RevenuePro RMS (IP allow-list), the housekeeping tablets, a trial owner BI dashboard (expires in November) and a revoked lobby kiosk. There are about 300 logged calls from the last day, including some refused ones.
- Two API bookings from the website (`WEB-10288`, and `WEB-10293`, which was cancelled).
- Three webhook endpoints. The **old CRM connector was switched off** after repeated `HTTP 503` answers, so its guest events are waiting.
- Try it: create a key with the *Booking partner* preset, then
  `curl -H "Authorization: Bearer nwp_…" "http://localhost:8000/api/v1/availability?from=2026-10-07&to=2026-10-09"`.

### v3.9 — what's new: engineering – utilities & energy, licences & statutory compliance

Utilities are usually a hotel's second-largest cost after payroll, and an expired fire NOC, FSSAI licence or lift licence can close the property. Many hotels track both in a paper register or a spreadsheet. v3.9 moves them into the PMS and connects them to occupancy, accounts payable, the night audit and the log book.

**Utilities & energy** (Engineering › Utilities & Energy)

| Feature | Where | What it does |
|---|---|---|
| **Meters** | Utilities › Meters | Electricity (grid / DG set / solar), water, LPG, PNG and diesel meters with a code, location, department, a **CT multiplier** (e.g. ×10), register digits (so a 5-digit register **rolls over** at 99,999 → 0), a tariff per unit and an optional parent (sub-meters such as kitchen, laundry, chiller plant under the main incomer). An alert threshold of 0 switches anomaly checks off (standby meters). |
| **Daily readings** | Utilities › Daily readings, *Enter today's readings* | One grid for all meters with the previous reading and an expected value (≈) from the recent average. Consumption = (reading − previous) × multiplier, costed at the tariff. A lower reading is treated as a **roll-over** only when the previous value was near the register limit; otherwise it is rejected as a typo. Tick **Meter replaced** to start a new chain, or **Estimated** when the meter could not be read. Correcting or deleting a reading recomputes the next one, and a correction that would break the chain is rolled back. |
| **Anomalies** | Overview, meter page, night audit | Each new reading is compared with the meter's 14-reading average: **unusually high**, **unusually low** or **zero** consumption beyond the threshold (default 30 %) is flagged. Engineering acknowledges it with a cause (banquet, leak fixed …). Meters with intermittent use (DG set, diesel) are never flagged. |
| **Energy KPIs** | Overview, Reports › Engineering | kWh per occupied room, litres of water per guest night, utility cost per occupied room and as a % of room revenue, solar share, and a daily chart split into grid, DG set and solar. Occupancy and revenue come from the night audit. |
| **Carbon footprint** | Overview, Settings | Scope 2 (grid electricity) and Scope 1 (LPG, PNG, diesel) in kg CO₂e with editable emission factors. The default grid factor is 0.710 kg CO₂/kWh (CEA CO₂ Baseline Database v21, FY 2024-25). Diesel 2.68 kg/L, LPG 2.98 kg/kg, PNG 2.1 kg/SCM. Emissions avoided by solar are shown separately. |
| **Utility bills** | Utilities › Utility bills | Record the electricity / water board bill: period, units billed, energy and fixed charges, taxes. The PMS compares **billed units with the metered consumption** for the same period and flags the difference. **Book to AP** creates the supplier bill (Electricity, Water or Fuel expense head) through accounts payable, with the usual budget check. *Adopt rate* updates the meter tariff to the bill's effective rate. |
| **Night audit & schedule** | Night audit, `pms:engineering` (daily 07:00) | The night audit warns about meters not read today (when *require readings* is on, the default) and about expired licences. It does not block the audit. The daily command runs the compliance reminders. |

**Licences & statutory compliance** (Engineering › Licences & Compliance)

| Feature | Where | What it does |
|---|---|---|
| **Register** | Compliance › Register | Licences, permits, certificates, insurance policies, AMCs and recurring inspections with the authority, reference number, responsible person, department, issue and expiry dates, fee and a **critical** flag. The status is computed: **expired / overdue**, **due soon** (inside the item's reminder window, default 60 days), **renewal applied**, **valid** or **no expiry**. |
| **Standard items** | *Add missing standard items* | 26 items an Indian hotel usually needs: police lodging / eating-house licence, FSSAI, fire NOC, pollution CTO, lift licence, boiler (IBR), DG set approval, bar (excise), PPL/IPRS music, legal metrology, trade licence, star classification, GST and Shops & Establishments registration, insurance, AMCs, plus recurring water potability tests, pest control, fire drills, duct cleaning and staff medical certificates. |
| **Renewals** | item › *Renewal applied*, *Record renewal* | Log the application (date, application reference, fee). The item shows *renewal applied* until the new certificate arrives. Then record the renewal with the new expiry and reference and upload the certificate. Every step is kept in the item's history. |
| **Inspections** | item › *Record inspection* | For recurring tests: record pass or fail with the next due date. A **fail** opens an urgent log-book entry for engineering, and the next pass resolves it. |
| **Documents** | item page | Upload the current certificate (PDF or image, private storage) and keep earlier versions on each history event. |
| **Reminders** | daily, *Send reminders now* | Once per stage (due soon, then expired), the responsible person and the digest address (or, when that is empty, the hotel e-mail) get a **compliance digest** e-mail. The log book records a summary, marked urgent when a critical licence has expired. |
| **Calendar & print** | Renewal calendar, *Print register* | Expiries month by month for the next 12 months, overdue items, and the renewal fees for the year. A printable register can be produced as of any date, for inspections and audits. |

Permissions (new group *Engineering*): `utilities.view`, `utilities.record` (readings, acknowledge anomalies), `utilities.manage` (meters, corrections, bills and AP booking, settings), `compliance.view`, `compliance.manage`.
- The Chief Engineer has everything.
- The front-office manager records readings and views the register.
- Accounts manage utilities (bills and AP booking) and view the register.
- Chef and F&B manager view the register (FSSAI, bar licence).

Reports › Engineering: *Utility consumption*, *Energy KPIs*, *Carbon footprint*, *Utility bill reconciliation*, *Compliance register* (all with CSV export). The dashboard has an *Engineering* card, and the sidebar badges show open anomalies and expired or due items.

**Demo:** log in as `engineer` / `Pass@123` (or `admin`) and open **Engineering**.
- 9 meters with 61 days of readings. The laundry sub-meter rolls over, the chiller sub-meter was replaced, and the diesel log has an estimated reading.
- A wedding spike on the kitchen meter is acknowledged. The **water leak three days ago is open**, and today only 3 of 9 meters have been read (the night audit warns).
- Last month's electricity bill is booked to AP with a billed-vs-metered difference. The water bill (+6 %) is waiting to be booked.
- Compliance: police lodging licence, PPL music licence and the monthly water potability test are **expired**; FSSAI, lift licence, pollution CTO and public liability insurance are due; the fire NOC renewal has been applied for. Pest control failed an inspection and then passed.

### v3.8 — what's new: gift vouchers & stored-value cards

Hotels sell a lot of goodwill in advance: birthday gift cards, “dinner for two” and spa experiences, one-night stay vouchers, corporate rewards, and festive promotions for loyal guests. v3.8 turns these into real **prepaid instruments**. Each has a unique code, a balance and an expiry. Each can be spent at the front desk and in the restaurant, and each is accounted for as a liability until it is used or lapses.

| Feature | Where | What it does |
|---|---|---|
| **Voucher types** | Gift Vouchers › Voucher types | **Gift card** (open or fixed amount, spend in parts), **experience** (single use: one redemption closes it and any unused rest lapses) and **stored-value card** (reloadable). Each type sets a fixed value or min / max for open amounts, validity in months, where it can be spent (room folio, restaurant & bar POS), colour and terms. Seeded types: Gift Card, Dinner for Two, Stay Voucher – One Night, Spa Escape, Stored-Value Card. |
| **Selling** | Gift Vouchers › Sell | Pick the type, amount, payment mode (cash, card, Mobile Money or bank; never city ledger or the voucher mode itself), purchaser and recipient, and a gift message. The code **GV-XXXX-XXXX** is 40 random bits from an alphabet without 0/O/1/I, unique across all properties. The voucher can be e-mailed straight to the recipient. Limits come from the type and from the hotel settings (smallest open amount, largest balance). An open cashier shift is required, as for any money received. |
| **Voucher page & print** | `/v/{code}` (public), voucher › Print | The holder sees the balance, expiry, where it can be used, recent activity, terms and a **QR code**. The page is throttled against guessing and cannot be browsed without the full code. Staff can print a voucher card with its QR code. |
| **Redeeming on a folio** | Folio › Payment, Check-out, voucher › *Apply to a guest folio* | Choose payment mode **Gift Voucher** and type the code as the reference; lower-case, spaces and a missing “GV” are all accepted. Partial amounts are allowed. The voucher must be active, unexpired, not suspended and usable on folios, and cannot go below zero. A **refund** on the folio in voucher mode puts money back on the voucher, but only up to what that folio took from it. **Voiding** the payment line restores the balance. |
| **Redeeming in the restaurant** | POS › Settle | Choose **Gift Voucher** and enter the code. The whole bill comes off the voucher. If the code is wrong, the balance is short or the voucher is not valid at the POS, nothing is settled. |
| **Stored-value reloads** | voucher › Reload | Top up a stored-value card (also after it was fully spent). The maximum balance is enforced, and the validity restarts from today. |
| **Promotions & service recovery** | Gift Vouchers › Promotional vouchers / Campaigns | Issue *n* vouchers for a named campaign, or paste a recipient list (“Name, e-mail” per line) and each recipient gets their own voucher by e-mail. A single complimentary voucher works too (e.g. “Service recovery – room 305”). The Campaigns tab shows issued, used and outstanding value per campaign. |
| **Life-cycle** | voucher › Manage | **Suspend** a voucher reported lost (it cannot be redeemed) and re-activate it later. **Cancel**: a sold voucher's balance is refunded by a payment mode; a promotional one is simply withdrawn. Every movement is in the voucher's transaction ledger: issue, reload, redeem, reversal, expiry, cancellation. |
| **Expiry & reminders** | night audit, `pms:vouchers` (daily 06:30), Settings › *Run now* | Vouchers past their expiry lose the unused balance (**breakage**). Holders with a balance are e-mailed **once** a configurable number of days before expiry. Expiring vouchers show an alert, a dashboard line and a nav badge. |
| **Accounting** | General ledger | Sale / reload: Dr cash / card / Mobile Money, Cr **2120 Gift Vouchers Outstanding**. Promotional issue: Dr **6150 Promotional Vouchers**, Cr 2120. Redemption: the *Gift Voucher* payment mode maps to 2120, so folio and POS payments debit the liability automatically. Expiry: Dr 2120, Cr **4087 Voucher Breakage Income** (promotional vouchers release 6150 instead). Cancel & refund: Dr 2120, Cr the refund mode. The **Voucher Liability** report reconciles the voucher balances to the GL 2120 balance. |
| **GST** | — | Under CBIC Circular 243/37/2024-GST, a voucher transaction is neither a supply of goods nor of services. So there is no GST on the sale or on breakage; tax is charged on the room or food bill the voucher pays. (A closed-system prepaid instrument usable only at the issuing hotel needs no RBI authorisation.) |
| **Dashboard & reports** | Dashboard, Reports › Gift Vouchers | A *Gift vouchers* card shows outstanding liability, sold and redeemed today, expiring soon and suspended. Five reports: **Gift Voucher Register**, **Voucher Liability** (as at a date, by expiry bucket, with the GL 2120 check), **Voucher Redemptions** (by outlet / front office), **Voucher Breakage & Cancellations**, and **Voucher Sales Summary** (per type, plus sales by payment mode). |

Permissions (new group *Gift Vouchers*): `vouchers.view`, `vouchers.sell` (sell, reload, e-mail), `vouchers.redeem` (look up and apply to a bill), `vouchers.manage` (types, campaigns, suspend / cancel / refund, settings).
- Receptionists sell and redeem.
- Restaurant cashiers and the F&B manager redeem.
- The front-office manager has everything.
- Accounts and sales & marketing manage.

Staff without sell / manage rights see masked codes (`GV-XXXX-Q9ZX`) in lists. The *Gift Voucher* payment mode is only offered where a redemption hook exists (folio, check-in / check-out, reservation advance, POS). It is kept out of supplier payments, payroll, forex, banquet deposits, laundry, transport, spa and AR receipts.

**Demo:** log in as `admin` (or `frontdesk`, `cashier`, `accounts`) and open **Gift Vouchers**.
- One birthday gift card has ₹2,000 of its ₹5,000 spent on an in-house guest's folio today.
- A Dinner for Two voucher was used in the restaurant five days ago; its remainder lapsed.
- A stored-value card was reloaded, and another card expires in 18 days (reminder due).
- One gift card lapsed with breakage, one is **suspended** (reported lost) and one was cancelled & refunded.
- The **Diwali 2026** campaign has five e-mailed vouchers, one of them already used, plus a service-recovery dinner voucher.
- Open any voucher's *Guest view* to see the public page with its QR code.

### v3.7 — what's new: hotel system interfaces (PABX call accounting & door-lock key cards)

A PMS lives next to two machines that touch every stay: the **telephone exchange** and the **door-lock encoder**. v3.7 connects both. Guest phone calls are now rated and billed without anyone typing them in. Key cards are encoded from the stay they belong to and follow it through extensions, room moves and check-out. Master keys are signed out and back in against a register. Everything is under the new **Interfaces** menu.

| Feature | Where | What it does |
|---|---|---|
| **Call rating** | Interfaces › Call Accounting › Tariffs | Each tariff has a prefix, a class (local, STD, mobile, ISD, premium, toll-free), a pulse length, a carrier cost per pulse, a markup %, a minimum charge and free seconds. The **longest matching prefix** wins, and the blank-prefix tariff rates everything else. Guest charge = pulses × cost × (1 + markup), never below the minimum. Emergency and toll-free numbers (a configurable list; `*` means prefix), internal calls and calls shorter than the unanswered threshold are never charged. An outside-line access digit can be stripped first. A **rate calculator** answers "what will this call cost?". |
| **Getting the calls in** | Call Accounting › Import SMDR, `POST /api/pbx/{token}`, `pms:pbx:listen` | Paste or upload the PABX's SMDR / CSV output, with the column order configurable (`date,time,ext,trunk,number,duration`, `datetime`, `skip`…), let the call logger **push** raw lines or JSON to a per-property token URL (401 for a wrong token, 503 when switched off, rotating the token cuts the old one off), or **stream live** from the PBX with `pms:pbx:listen` (connect or listen mode, automatic reconnects — see `docs/PBX-ADAPTER.md`). Each line stands alone: bad lines are reported and good ones kept. Re-sending a line is harmless because duplicates are recognised. Durations can be written as seconds, m:ss, h:mm:ss or 3'25". |
| **Posting to the folio** | automatic | The stay **in the room at the moment of the call** is charged, even when the guest has since moved rooms. The charge is a *Telephone* line (with tax) on its own revenue account (4045), showing the number with the last digits masked, the class, the duration and the time. Admin and public extensions are **costed to their department** but never billed. |
| **Review queue** | Call Accounting › Review queue | Calls from an empty room, an unknown extension, a guest who has already checked out, or beyond the room's class of service, and all calls when auto-posting is off, wait here. **Post** them (optionally to another in-house stay) or **waive** them with a reason. Waiving a posted call (a guest dispute) voids the folio line. *Re-run* (and the scheduler) re-examines the queue after an extension or tariff has been added, but never auto-posts a class-of-service breach. |
| **Class of service** | Folio sidebar, check-in | Full, national, local only, or barred. It is set at check-in (with a configurable default), can be changed from the folio, and is barred at check-out. Each change is sent to the PABX. |
| **Key cards** | Interfaces › Key Cards, folio sidebar | Encode a **new key** (cancels every earlier card) or **duplicates** (kept alongside), up to the per-room limit, with common-area access (lift, pool, gym…). Cards work until the check-out time plus grace on the departure date; a late check-out gets a short-lived key. **Lost**: the card is cancelled, an optional fee goes on the folio, and replacement cards are encoded for the guest's other cards too. **Read** a card to see its room, holder, validity and status. Cards can be returned at the desk and can be encoded automatically at check-in (optional). |
| **Stay hooks** | automatic | An **extension** re-validates the working cards. A **room move** cancels the old-room cards, encodes the same number for the new room and moves the phone profile. **Check-out** expires the cards and bars the phone. |
| **Master-key register** | Key Cards › Master keys / Key register | Grand, floor, section and emergency keys, signed out to a staff member or a named contractor with a purpose (required for a grand master) and a due time. Overdue keys show a red alert and a nav badge. A **lost** master key raises an urgent security entry in the log book and a cancel command to the lock system. |
| **Interface hub** | Interfaces › Interface Hub | Connection status, settings (drivers, SMDR format, free numbers, masking, default class of service, key validity, card limit, lost-card fee, access areas, master-key hours), the push URL and token, and the **command log** of everything the PMS sent to the PABX and the encoder. A failed command (for example an offline driver) raises an alert and can be **retried**, one at a time or all together; the scheduler also re-sends failed PABX commands. |
| **Dashboard, badges, reports** | Dashboard, sidebar, Reports › Hotel Interfaces | A *Phones & keys* card shows today's calls, charges, department cost, calls to review and master keys out or overdue. There are nav badges for the review queue and overdue keys, and five reports: **Call Detail Log**, **Call Revenue** (by class: billed, carrier cost and margin), **Admin Extension Usage**, **Key Card Audit** and **Master Key Register**. |

Permissions (new group *Hotel Interfaces*): `calls.view`, `calls.manage` (import, post / waive, tariffs, extensions), `keys.view`, `keys.issue` (encode, read, lost, return, class of service), `keys.master` (master-key register), `interfaces.manage` (hub, settings, token, retry). By default, receptionists and the concierge encode keys, the housekeeping supervisor runs the master-key register, and accounts handle call accounting.

Real sites replace the **demo driver** with a vendor driver (FIAS / Micros-Fidelio, Assa Abloy Vision, Salto, Dormakaba, Onity…). The rest of the PMS only talks to `InterfaceService::send()`. The *offline* driver simulates an unreachable device.

**Demo:** log in as `admin` (or `accounts`, `frontdesk`, `housekeeping`) and open **Interfaces**.
- In-house guests have posted local, STD, mobile and ISD calls, plus free emergency and toll-free calls. One mobile call has been waived.
- The review queue holds a call from a vacant room, one from an unknown extension (999) and an ISD call from a room limited to national calls.
- Most in-house rooms have working cards, including a duplicate and a lost card that was replaced. Two rooms still need cards.
- Seven master keys are in the register: HK-A is out, and ENG-01 is **overdue**.
- To test the push API, copy the push URL from the hub, e.g. `curl -X POST -H 'Content-Type: text/plain' --data '2026-09-27,10:15:00,101,T1,04422334455,185' <url>`.

### v3.6 — what's new: budgeting, forecasting & budget vs actual

The general ledger says what happened. v3.6 adds what *should* have happened. Accounts can now plan the year, have the plan approved, re-forecast it as the year unfolds, and see every month exactly where the hotel is ahead of or behind plan, with the reason written next to the number. Everything is under **Accounts › Budgets** and **Accounts › Budget vs Actual**.

| Feature | Where | What it does |
|---|---|---|
| **Versions** | Budgets › New budget | A version (**BUD-**) covers one fiscal year (April–March by default; configurable). It holds twelve monthly amounts for **every income and expense account** in the chart of accounts, plus rooms statistics. It can start **blank**, from **last year's actuals** (same months), from the **run-rate** (the average of the last three closed months since go-live) or as a **copy** of another version. Revenue and expense growth % are applied to the starting figures. Assumptions go in the notes. |
| **Monthly grid** | Budget › Monthly budget | Sections in P&L order (operating revenue, cost of sales, operating expenses → **GOP**; other income, non-operating → **net profit**). Row, section and result totals **update live** as you type. Changed cells are highlighted and only those are posted. Each line has an assumption note, last year's actual and this year's actual to date. The row menu **spreads** an annual total evenly, by days in the month or **seasonally** (following budgeted room-nights), or changes the line by a percentage. **Adjust %** applies to all revenue, all expenses or everything. |
| **Rooms statistics** | Budget › Rooms statistics | Rooms available, occupancy % and ADR per month give room-nights, room revenue and RevPAR. One tick recalculates the room revenue line as rooms × occupancy × ADR. Actual occupancy, ADR and RevPAR are shown alongside. |
| **Import / export** | Budget › CSV, Import | Download a version as CSV, edit it in Excel and import it back. The first column is the account code; an optional name column is followed by twelve months. Commas and ₹ are accepted, and header and total rows are skipped. The import is **all or nothing**: an unknown account, a short row or a non-number rejects the file and lists the rows to fix. |
| **Approval** | Budget › Submit / Approve / Return | Draft → submitted → approved. Only drafts can be edited. **Four-eyes**: the approver must be someone other than whoever submitted it. *Return* sends it back with a required note, shown to the preparer. On approval the version becomes the **active** one for its year (only one active budget and one active re-forecast per year). An approved version can be switched to active, or **re-opened** with a reason, which deactivates it until it is approved again. Drafts can be deleted. Every step is in the version's history and the activity log. |
| **Re-forecast** | Approved budget › Re-forecast | Pick a closed month-end. The months up to it take the **posted actuals** from the general ledger and are **locked**; the remaining months start from the budget and can be re-estimated (grid, spread, %). Months before the go-live date keep the budget figures, because the ledger doesn't hold them. Re-forecasts go through the same approval. The active one appears as a *Forecast* column in budget vs actual. |
| **Budget vs actual** | Accounts › Budget vs Actual | For any month of an approved budget: actual, budget, variance and % for the **month** and the **year to date**, plus the full-year budget and forecast (and forecast − budget). Actuals come from the general ledger up to the last closed day, so they reconcile with the GL P&L. The **month in progress** is compared with a budget **pro-rated** to the days elapsed. **Go-live**: months before it are shown but not compared and are excluded from YTD. Variances are **green** when favourable (more revenue, less cost) and **red** when adverse. A variance of **≥ 10 % and ≥ ₹10,000** (configurable) is flagged *explain*. **Commentary** can be left on any line or on the month as a whole; it is stored with the actual and budget at the time and is shown in the table. A rooms KPI panel compares occupancy, ADR, RevPAR and room-nights, month and YTD. |
| **Spend control** | Supplier bills (AP) › Expense bill | When a supplier expense bill would take an expense account over the active budget, **warn** (the bill is saved; the warning is flashed and noted on the bill) or **block**. Block requires the `budget.override` permission **and** a reason, which is stored on the bill and in the activity log. The comparison basis is the month's budget, the **year to date** (default) or the full year, counted from go-live. Unbudgeted accounts count as over budget. |
| **Dashboard, badge, reports** | Dashboard, sidebar, Reports › Budget & Planning | A dashboard card shows revenue and GOP month-to-date against the pro-rated budget, variances waiting for an explanation, and versions awaiting approval (for approvers, who also get a nav badge). Five reports (**100 in total**): **Budget vs Actual (P&L)**, **Annual Budget – Monthly Phasing**, **Forecast vs Budget** (with actual to date and remaining forecast), **Rooms KPIs vs Budget** and the **Variance Commentary Log**, all with CSV. |

Permissions (new group *Budgeting & Planning*): `budget.view` (read, budget vs actual, comment), `budget.manage` (prepare, edit, import, submit, re-forecast), `budget.approve` (approve / return / re-open / activate, settings), `budget.override` (spend over budget when control blocks). The Accountant gets view + manage. The Front Office Manager, F&B Manager and Sales & Marketing can view. Only the Administrator approves by default. Settings (**Budgets › Settings**): fiscal-year start month, go-live date, comment thresholds (% and amount), spend control (off / warn / block) and its basis (month / YTD / annual).

**Demo:** log in as `admin`, then open **Accounts › Budgets** and **Budget vs Actual**. The go-live date is the first of the current month.
- **BUD-00001** is the budget for FY 2026-27. The accountant (`accounts`) prepared it and the administrator approved it, and it is active. It is seasonal (monsoon low, December peak), and its statistics drive room revenue. The demo budget is sized from the hotel's own current pace, so rooms land close to plan, the **restaurant is behind** (kitchen refit) and **banquets are ahead**. Those two, plus the month-end payroll timing, are already explained; a few variances are still waiting for commentary.
- **BUD-00002** is the *Re-forecast after Aug*: banquets +15 %, restaurant −10 % for the rest of the year. It is awaiting approval (approve it as `admin` to see the Forecast column).
- **BUD-00003** is next year's first cut, a draft copied at +8 % revenue / +6 % costs.
- Spend control is set to **warn**: enter a large repairs bill under Supplier Bills to see it.

### v3.5 — what's new: front desk operations

A front desk runs on things that fall between shifts: the note in the log book, the parcel behind the counter, the 06:00 wake-up call, the cake that has to be in room 103 by 18:00, the ring housekeeping found last month. v3.5 adds a **Desk Operations** menu (before *Reservations*) that puts them in the system. Lost & found moves there from Housekeeping and gets a proper register.

| Feature | Where | What it does |
|---|---|---|
| **Shift log book** | Desk Operations › Log Book | Entries have a department, category (info, guest, complaint, incident, maintenance, security, cash…) and priority. The business date and shift are stamped automatically (shift times are configurable, 07:00 / 15:00 / 23:00 by default). An entry can link a stay or room. Complaints, incidents, maintenance, urgent items and anything with a follow-up time become **open** until someone resolves them (a resolution note is required); they can be re-opened. **Read receipts**: each user sees what they haven't read (last 3 days by default), acknowledges it with one click, and the entry shows who has read it. Comments form a thread. Managers can **pin** an entry, optionally until a date. Tabs: unread, open, pinned, all, with search and filters. |
| **Shift hand-over** | Log Book › Hand-over, Print | Everything since the start of the previous shift, open items from earlier, pinned notices, and a snapshot of the house: in-house, arrivals and departures still to do, wake-ups for the next 12 hours, messages and parcels waiting, and traces due. Printable for the outgoing shift to sign. |
| **System entries** | Night audit, wake-up escalation | The night audit writes a *Night audit closed* entry. An unanswered or missed wake-up call raises an **urgent** open entry ("send someone to knock"). |
| **Guest messages & parcels** | Desk Operations › Messages | Take a message (caller, company, phone, text, channel) or log a parcel (courier, AWB, pieces, storage place) for an in-house or arriving guest. A **message slip** prints for the pigeonhole or to go under the door. The guest is told by **SMS / WhatsApp** (the text itself is only included if you allow it) or by phone, and this is logged. **Delivery** records who handed it over; parcels also need **the name of whoever collected them**. Parcels can be returned or forwarded, with a reason. Messages for an arriving guest go out at **check-in**. At **check-out**, undelivered messages are cancelled. Parcels stay on the desk, and check-out warns about them. The **guest portal** gets a *Messages* page and a banner: reading a message there marks it delivered, and a parcel becomes *guest notified*. |
| **Wake-up calls** | Desk Operations › Wake-up Calls, Print | Book a call from the desk or by phone, or the guest books it on the portal (it goes straight into the queue, and cancelling it on the portal cancels the call). The system checks that the time is in the future and within the stay, and refuses a second call within ±10 minutes. The queue shows calls due soon (15 min lead) and late ones. Record each attempt: *answered*, *no answer* or *busy*. Retries every 5 minutes, and after 3 tries the call is closed as *no answer* and **escalated** (urgent log entry, plus an SMS to the duty phone if one is set). **Daily** calls roll to the next morning until departure. `pms:frontdesk-run` (every 5 min) escalates calls not made within the grace period and closes calls 2 hours past as *missed*. Check-out cancels open calls, and a room move carries them over. The call sheet prints for the operator. |
| **Traces** | Desk Operations › Traces, reservation page | Dated to-dos for a department, optionally with a time and linked to a reservation or stay ("place a birthday cake at 18:00"). They can't be dated in the past. Completing one takes an optional note; cancelling needs a reason. The **night audit** lists open traces and **carries them to the next day** (the original date is kept and shown). The reservation page shows its traces and can add one. |
| **Lost & found** | Desk Operations › Lost & Found | Numbered items (**LF-**) with a category. Jewellery, documents and cash are **valuables**, as is anything worth ₹5,000 or more: they are stored in the safe and kept **180 days** (others **90**, perishables **1**). The **probable owner** is suggested from the stay that last used the room. Printable **tag**. **Return** to the owner, in person or by courier (AWB and charge recorded), with a printed **receipt**. A valuable can only be released by a supervisor (*lostfound.release*) and needs the claimant's **photo ID**. **Disposal** (donate, destroy, police, finder, staff auction) is only allowed once the retention date has passed, except a police hand-over. Documents may only go to the police, and cash never to the finder. Disposing of a valuable needs a **second member of staff as witness**, who enters their own password. |
| **Loss reports** | Lost & Found › Loss reports | A guest who calls about something lost gets a report (**LR-**) with contact details, what was lost, and when and where. The report lists **likely matches** from the store (scored on category, room, guest, dates and words), and *This is it* links the report to the item. Returning the item closes the report; otherwise it closes with a note. |
| **Housekeeping & mobile** | HK board, attendant phone screen | Items found by attendants (with a category on the phone screen) are logged into the same register, with a number, retention date and probable owner. |
| **Dashboard, badges, night audit** | Dashboard, sidebar, Night Audit | Dashboard lines for due / late wake-ups, waiting messages, parcels left by departed guests, traces for today, unread / open log entries and lost-property items past retention. Nav badges on each desk page. The night-audit pre-check lists open traces and urgent log items (warnings only). |
| **Reports, privacy** | Reports › Desk Operations, Privacy & Data | **Log book register**, **wake-up calls** (with answer rate and escalations), **guest messages & parcels** (time to deliver), **traces register** and **lost & found register** (95 reports in total). Messages, wake-up calls and lost property appear in guest data exports. On erasure, message text, notes and contact details are removed, and guest names are blanked out of log entries and traces. |

Permissions (new group *Front Desk Operations*): `logbook.view`, `logbook.write`, `logbook.manage` (pins, desk settings), `desk.messages`, `desk.wakeup`, `desk.traces`. `lostfound.release` (valuables) is added to the Housekeeping group. The Front Office Manager gets everything. Receptionists get log book view / write, the three desk pages and lost & found (without releasing valuables). The Concierge gets the same. HK Supervisor and Banquet Manager get log book view / write, and Accounts can read the log book. Settings are under **Log Book › Settings**: shifts, unread window, wake-up lead / grace / retry / attempts, escalation phone, message notification, and lost & found retention and valuable threshold.

**Demo:** log in as `fom` (or `admin`) and open **Desk Operations**. The data is relative to the business date:
- **Log book:** a mix of shifts. Open items: the VIP arrival and the leak under the sink in room 102; a resolved noise complaint; the *Night audit closed* entry; a pinned lift-inspection notice; the escalated wake-up for room 106.
- **Wake-ups:** room 106 has a call due within minutes and yesterday's *no answer*. Room 104 has a daily 06:00 call plus a 06:30 portal booking. Room 105 booked 05:30 on the portal.
- **Messages:** a notified message for room 101, an Amazon parcel for room 102 (plus a delivered message), a message waiting for an arriving guest, and a DTDC parcel left behind by a guest who has already checked out.
- **Traces:** one carried over from yesterday, today's front office / reservations / concierge tasks, and one done.
- **Lost & found:** the wallet (**LR-00001** from Mr Daniel Schmidt matches it), a Kindle (valuable by value), a charger, a denim jacket and a gold ring both **past retention**, sunglasses returned, and an umbrella donated. Sophie Laurent's bracelet report has no match yet.

### v3.4 — what's new: foreign guests – Form C (FRRO) & forex desk

Every hotel in India must report each foreign national to the Bureau of Immigration on **Form C** (Rule 14 of the Registration of Foreigners Rules 1992, Foreigners Act 1946 s.14) within **24 hours of arrival**, on indianfrro.gov.in, and report the departure too. Many hotels also buy foreign currency from their guests under an RBI money-changing licence or franchise. Until now both lived on paper. v3.4 adds a **Foreign Guests** menu. The FRRO portal has no public API, so the desk files there and records the reference number here. The system tracks the deadlines, gaps, late filings, departures and visas that run out during the stay.

| Feature | Where | What it does |
|---|---|---|
| **Automatic Form C** | Check-in, guest profile, desk | A Form C opens when a guest whose nationality isn't Indian checks in (*India*, *Indian*, *IN*… count as home). The deadline is check-in + 24 h. Particulars come from the guest profile (passport, gender, date of birth, address) and from what the guest entered during **online check-in** (new Form C card: passport issue / expiry, visa, arrival in India, port, next destination, purpose). Fixing a nationality on the profile or on the desk's **nationality check** (in-house guests with a passport but no nationality) opens the missing Form C. |
| **Completeness** | Form C page | The FRRO fields: name as on passport, nationality, passport and visa number, place, issue and expiry; arrival in India, port, arrived from, next destination, purpose, address abroad. **Passport and visa scans** are uploaded from the page (they must be on file unless switched off). Nepal and Bhutan need no visa, and OCI / diplomatic visas carry no expiry. Dates are checked for sense (issue after expiry, arrival after check-in and so on). Warnings flag an expired passport or visa, or a visa that ends before check-out. Corrections are written back to the guest profile. |
| **Filing** | Form C page › Record FRRO submission | Needs a complete form and the portal's reference (5–40 letters, digits, - or /, unique). The filing time can't be in the future or before check-in. **After the deadline a reason is mandatory** and the record is marked late. Re-opening for correction needs a reason and keeps an audit note, until the departure has been reported. **Exempt** (e.g. nationality recorded wrongly) needs a reason. **Companions** get their own Form C on the same stay. |
| **Departures** | Check-out, Form C desk | Check-out stamps the departure. The *Departures to report* tab lists them, and they are flagged when older than the configured hours. |
| **Guards & alerts** | Check-out, night audit, dashboard, `pms:foreign-run` hourly | Check-out shows a warning while a Form C is unsubmitted (optionally **blocks** check-out; supervisors can force). The night audit lists overdue reports. Dashboard lines and nav badges show overdue / due-soon / departures. An hourly job e-mails the desk **once** when a Form C gets within the warning window and **once** when it is overdue. |
| **Register & print** | Form C desk › Export CSV, Print | An FRRO-style register (CSV) and a printable Form C copy for the police register. |
| **Exchange rates** | Foreign Guests › Exchange Rates | Each day a reference rate (bank / RBI) and the desk's buy rate per currency (per 100 for yen). The buy rate can't be above the reference or more than the set margin below it. *Copy yesterday's* fills the gaps. The night audit **carries rates forward** flagged *carried*, so the desk sees they need confirming. Ten currencies are seeded (a master list). |
| **Buying currency** | Foreign Guests › Currency Exchange › Buy | In-house foreign guests only (residents optionally). A live quote shows the rupee value, GST (value of supply under **CGST r.32(2)(b)**: 1 % min ₹250 up to ₹1 lakh, then ₹1,000 + 0.5 %, then ₹5,500 + 0.1 %, capped at ₹60,000; 18 %), an optional fee and the net to pay. It enforces the **RBI limits**: cash at most **USD 3,000** per foreign visitor (USD 1,000 for residents), purchases within **30 days counted together**, and a **passport copy on file from ₹50,000**. The guest can instead **credit the room bill** (no conversion charges; capped at the balance). The *Guest wants to pay in foreign currency* link on check-out opens it for the stay and returns to check-out. |
| **Certificate & void** | Exchange page | A numbered **encashment certificate** (copies counted). A **same-day void** needs a reason and reverses the bill credit; it is refused after the currency has been surrendered. |
| **Position & surrender** | Foreign Guests › Currency Position | Per currency: bought, surrendered, held, average cost, market value at today's reference and the **age of the oldest unsurrendered purchase (FIFO)**, flagged after the surrender window (7 days by default). A **surrender** to the bank / authorised dealer (rate within ±15 % of reference) takes a proportional share of the cost, records the **realised gain or loss** and prints a voucher. |
| **Accounts & shifts** | GL, cashier shift | Cash purchases post Dr *Foreign Currency on Hand* (1060), Cr cash, GST payable and *Forex Commission Income* (4085). Surrenders post Dr bank, Cr 1060, gain / loss to *Exchange Gain / Loss* (4986). Bill credits post through the new *Foreign Currency (forex desk)* payment mode. Rupees paid out leave the cashier's drawer, and the shift report lists the **foreign currency to hand over**. |
| **Guest portal** | `/stay/{token}` › Currency exchange | Foreign in-house guests see today's buying rates and the rules (limits, certificate). |
| **Reports, privacy** | Reports › Foreign Guests, Privacy & Data | **Form C register** (hours taken, late, departure), **foreign arrivals by nationality**, **currency exchange register** and **currency position & surrenders** (90 reports in total). Form C and exchanges appear in guest data exports. On erasure they keep their particulars while the police-register hold runs, otherwise they lose every identifier (amounts and FRRO references are kept). |

Permissions: `formc.view`, `formc.manage` (edit, file, exempt, re-open), `forex.view`, `forex.exchange` (buy currency) and `forex.manage` (rates, voids, surrenders, forex settings). Receptionists get Form C view / manage and forex view / exchange; the Front Office Manager everything; Accounts forex view / manage and Form C view.

**Demo:** log in as `admin` and open **Foreign Guests › Form C**. The data is relative to the business date:
- **Hiroshi Tanaka** (Japan) is **overdue**; **Emily Clarke** (UK) and her companion **James** are due today; **Kwame Asante** (Ghana) is still missing his visa details and scans.
- **John Mensah** is filed. **Samuel Owusu** has checked out and his **departure is still to report**. One earlier filing was late with its reason.
- Three weeks of filed Form Cs feed the register.

On the **forex desk**: 14 days of rates (today's CHF *carried* by the night audit); cash purchases over the last two weeks, one **voided**, and a room-bill credit for John. USD, EUR and GBP were **surrendered to HDFC** at a gain. The **yen is held past the 7-day window**, so it shows on the dashboard.

### v3.3 — what's new: guest transport & fleet desk

Airport pick-ups and drops are a daily job at Indian hotels and resorts, but until now "airport pickup" was just free text in the special request. v3.3 gives the concierge a proper transport desk. It covers the hotel's cars and drivers, a tariff card, a dispatch board that stops double-booking, a phone screen for drivers, and billing straight to the room.

| Feature | Where | What it does |
|---|---|---|
| **Fleet & papers** | Transport › Vehicles / Fleet & Papers | Call-sign, make, registration, type (sedan, SUV/MUV, van, Tempo Traveller, coach, luxury, buggy), seats, luggage, fuel, odometer, workshop status and an optional fixed-asset link. Four compliance dates per vehicle: **insurance, tourist permit, fitness and PUC**. The chips turn amber within the warning window and red when a date has expired. A car with an expired paper is **off the road** and can't be assigned. |
| **Drivers** | Transport › Drivers | Mobile, licence number and **expiry** (an expired licence blocks assignment), languages, optional employee record, and an optional **login** for the driver phone screen (new *Driver* role). |
| **Tariff card** | Transport › Tariff Card | Airport, railway, local, outstation and tour fares by vehicle type, each with a flat rate, included km, a **per-km rate beyond that**, a waiting charge and a default duration. Eight fares are seeded. |
| **Trips** | Transport › New trip; reservation page (+ Pick-up / + Drop); guest-request queue | Pick-up, drop or local transfer. Choosing a stay fills in the guest, mobile, passengers, route (hotel ↔ airport) and time (arrival ETA, or check-out time on the departure day). Also records flight/train, bags, tariff, vehicle type and notes for the driver. **Billing:** room bill, direct (the guest pays the desk or driver) or complimentary. Walk-in and restaurant guests can be booked too. |
| **Busy windows & clashes** | Everywhere a car or driver is chosen | A pick-up keeps the car busy from *landing time − lead time* (drive to the airport) for the tariff's duration; a drop or transfer from its departure time. Every assignment and every time change is checked for overlaps with the **turnaround buffer**, for both the car and the driver. A clash lists the conflicting trips, and only a deliberate *Assign anyway* overrides it (the override is logged). Blockers such as the workshop, expired papers or licence, or too few seats can't be overridden. |
| **Dispatch board** | Transport › Dispatch Board | A per-vehicle timeline of the day with a now-line, colour-coded trip bars and hatched lanes for cars that are off the road. The **needs-a-car queue** has one-click assign: the dialog shows which cars and drivers are free and suggests the best fit (smallest car that seats the party, then the least-busy driver). Also shows guest transport requests from the portal, driver load, day totals and an alert strip (requests to confirm, trips leaving within 3 h without a car, overdue trips, expiring papers and licences). |
| **Lifecycle** | Trip page | Requested → confirmed → assigned → car left (opening km) → guest on board → completed (closing km, extras such as waiting, tolls or parking). Kilometres beyond the tariff's included km are charged automatically. The guest gets the driver's name, mobile and car number by SMS/WhatsApp when a car is assigned, and the driver gets the trip sheet. Re-send, change (with a clash re-check and a "time changed" message), un-assign, no-show (with an optional no-show fee to the room) and cancel (voids a fare already on the bill). |
| **Billing** | Folio, check-out | Room-billed fares post to the stay folio (category *transport*, SAC 996601, GL 4070 Transport Revenue) on completion, or earlier with *Post fare to room bill now*. **Check-out is blocked** while a room-billed trip isn't on the bill. The check-out screen lists those trips with an *Add to this bill* button, because a departure drop usually runs after check-out. Direct fares are invoiced and paid at completion (a walk-in folio, closed at zero). |
| **Driver phone screen** | Transport › My Trips (driver login) | Big cards for today and tomorrow: time, guest, route, flight with leave-by time, notes, a **name board** for arrivals, call-the-guest and map buttons. *Start trip* with odometer, *Guest on board*, *Guest not found*, and *Trip completed* with closing km, extras and cash taken. Fuel, toll and parking can be logged from the road. Drivers only see and act on their own trips; the desk can view any driver. |
| **Guest portal** | `/stay/{token}` › Transport | Upcoming guests book a pick-up and in-house guests a drop or a car in the city: date, time, flight, passengers, bags, place, mobile and notes, plus the fare list. Requests need 2 hours' notice and a date within the stay; there is a honeypot and a cap of 4 open trips. The desk confirms, and the guest sees the driver's details and can cancel up to 2 hours ahead. |
| **Trip sheet & name board** | Trip page › Trip sheet & name board | A printable sheet (times, guest, route, car, driver, collect amount, odometer and signature boxes) plus a full-page **arrivals name board**. |
| **Running costs** | Transport › Vehicle Expenses | Fuel (litres), service, repairs, tolls, parking and other costs per vehicle; the odometer is bumped automatically. |
| **Automation** | `pms:transport-run` every 5 minutes | Driver reminders before departure (sent once; the timer restarts on re-assignment) and closing guest requests that were never confirmed. |
| **Reports, dashboard, privacy** | Reports › Transport, dashboard, reservation page, Privacy & Data | **Transfer register**, **vehicle utilisation & running cost** (km, hours, revenue, fuel, cost per km, km per litre, margin), **driver performance** (on-time %, average minutes late, no-shows) and **transport revenue by day** (86 reports in total). Dashboard lines for today's transfers, requests to confirm or trips without a car, and papers and licences. A Transport card on the reservation page. Transfers are included in guest data exports; erasure cancels planned trips and anonymises the rest, keeping km and fares for statistics. |

Permissions: `transport.view` (board, trips, fleet), `transport.book` (book, assign, run, cancel, bill), `transport.manage` (vehicles, drivers, tariffs, expenses, settings) and `transport.drive` (driver phone screen). New roles: **Transport Desk / Concierge** (`concierge`) and **Driver** (`driver`). The Front Office Manager and Receptionist get view and book.

**Demo:** log in as `admin` or `concierge` and open **Transport › Dispatch Board**. The data is relative to the current time:
- INNOVA-1 is on the road for a pick-up (6E 5302, child seat fitted).
- DZIRE-1 is assigned to a later pick-up.
- A VIP pick-up (UK 811, 3 guests, 4 bags) has **no free car**. Assign it to see the clash and the override.
- INNOVA-2 has a complimentary drop for today's departure.
- A guest's portal request for tomorrow's early drop is waiting to be confirmed.
- Tomorrow there is a city tour and a restaurant guest's station drop.

On **Fleet & Papers**:
- The Tempo Traveller is off the road with an expired PUC.
- INNOVA-2's insurance is due in 12 days.
- The Mercedes is in the workshop.
- One driver's licence has expired and another is due.

Two weeks of history feed the reports. Log in as `driver` / `Pass@123` (Sunil Yadav) on a phone to see *My Trips*.

### v3.2 — what's new: restaurant table reservations, waitlist & live floor plan

Exceed is sold to restaurants and cafés as much as to hotels, but until now the POS only knew about a table once somebody opened an order on it. v3.2 adds the front-of-house side: bookings, a waitlist and a live floor plan, all wired into the POS so the host, the steward and the cashier see the same tables.

| Feature | Where | What it does |
|---|---|---|
| **Reservation settings** | POS / Restaurant › Reservation Settings | Per outlet: switch reservations and online booking on, **service periods** (e.g. Lunch 12:00–14:30, Dinner 19:00–22:30 – the end is the last seating – with weekdays), booking interval (10–60 min), reset time between parties, **dining time by party size** (2 → 90 min, 4 → 105 min…), hold time for late guests, automatic no-show, online notice / days ahead / largest party / covers per interval (kitchen pacing), auto-confirm online bookings, reminder hours, no-show flag threshold and a note for the booking page. Tables get a section, seats, minimum party, shape, "can join neighbours" and "bookable online". |
| **Allocation** | Booking form, public page | Every slot is checked against holding bookings (plus the reset time), running POS orders and blocked tables. The smallest free table that fits is chosen (a couple doesn't take a 6-top); if no single table seats the party, two or three neighbouring combinable tables of one section are joined. Staff can pick tables themselves and may book outside service periods; a clash is always refused. |
| **Staff bookings** | Table Reservations › New booking, or the floor plan | Date, party and time with **live slot pills** (grey = full / too soon / pacing), phone look-up that fills the name and shows visits and no-shows, in-house guests (charge to room), occasion, requests (high chair, wheelchair, window, quiet, vegetarian, allergy, VIP), seating preference and notes. Confirmation by e-mail or SMS / WhatsApp with a manage link. Guests with repeated no-shows are flagged. |
| **Online booking** | `/dine/NOWILLS` (mobile) | Outlet, date and party size, then only the times that really have a table; name, mobile, e-mail, occasion and requests. Honeypot, rate limit, 3 upcoming bookings per number, minimum notice, largest online party. Bookings are confirmed at once or wait for the host (setting). The guest's link `/dine/r/{token}` shows the booking and lets them cancel (hashed 40-hex token, noindex / no-referrer headers). |
| **Live floor plan** | POS / Restaurant › Floor Plan & Waitlist | Tables drawn where they stand (drag to move, double-click to change shape, saved per outlet) and coloured by state: free, reserved within the hour, guest late, seated (party, minutes, expected to leave, overdue), billed, **to clear** and blocked (with a reason). Click a table for the party, the bill so far, the next booking and actions: seat a walk-in, clear, block / back in service; Ctrl/⌘/Shift-click several tables to seat a party across them. Side panels list the waitlist and the next arrivals; refreshes itself every 30 s. |
| **Seating ↔ POS** | Floor plan, booking page, POS terminal | Seating opens the POS order on the table (covers and guest name filled in) – or adopts an order the steward already opened there. Settling or voiding the bill completes the party and marks its tables *to clear*; moving the order to another table or merging it carries the booking along. POS table tiles show *Reserved 20:30*, *To clear* and *Out of service*. |
| **Waitlist** | Floor plan › Add to waitlist | Name, party, mobile, seating preference and a quoted wait (estimated from the parties ahead and the tables that fit; walk-ins may take a bigger table). "Ready" texts the guest; seat them on the selected tables; "Left" records a walk-away (reinstate possible). Yesterday's leftovers are closed automatically. |
| **Day sheet** | POS / Restaurant › Table Reservations | A per-table timeline of the day (service periods shaded, now line, joined tables), tabs for all / to come / seated / completed / waitlist / cancelled & no-shows, search, and seat / no-show buttons. Each booking has its own page with guest history, an activity trail and change / re-send / cancel / reinstate. |
| **Automation** | `pms:tables-run` every 5 minutes | Reminders (once, hours before), automatic no-shows after the hold time, closing yesterday's waitlist, and finishing seated parties whose order has gone. |
| **Reports, dashboard, privacy** | Reports › POS, dashboard, guest profile, Privacy & Data | Table booking register, covers & no-shows by day, table turns & utilisation (sales per cover from the linked bills) and waitlist performance (82 reports in total). Dashboard lines for bookings still to arrive, late guests and the waitlist; restaurant bookings on the guest profile. Privacy exports include restaurant bookings; erasure anonymises them and cancels upcoming ones; the retention job removes contact details from old bookings (on the message-retention period) while keeping the covers statistics. |

Permissions: `tables.view` (floor plan, day sheet, bookings), `tables.book` (book, seat, walk-ins, waitlist, cancel, no-show, clear) and `tables.manage` (settings, layout, blocking tables). The new **F&B Manager** role (`restaurant`) has all three plus the POS; the Front Office Manager, Receptionist and Restaurant Cashier get view and book.

**Demo:** as `admin` or `restaurant`, open **POS / Restaurant › Floor Plan & Waitlist**. The Saffron restaurant is live relative to the current time: a regular in the T1 booth, a walk-in on T2, a party of 8 celebrating a birthday on joined T9 + T10, T6 waiting to be cleared, T11 out of service (broken umbrella), a guest 10 minutes late for T8, four arrivals in the next two hours (one unconfirmed online request and one in-house guest charging to the room) and two parties on the waitlist. Thirty days of history (lunch and dinner, walk-ins, waitlist, cancellations, no-shows – Rohit Verma has two) feed the reports, and the next week already has bookings. The Lake Resort's *Lakeside Café* has reservations switched on with a dinner period but no bookings yet. Guests book at `/dine/NOWILLS` and `/dine/NWRESORT`.

### v3.1 — what's new: guest feedback & online reputation

Knowing how guests felt – and fixing it before they post a one-star review – is as important as billing them correctly. v3.1 replaces the old star-rating form with a complete feedback loop: a survey after every stay, automatic follow-up of unhappy guests, and a single inbox for TripAdvisor, Google, Booking.com and other reviews.

| Feature | Where | What it does |
|---|---|---|
| **Post-stay survey** | Automatic at check-out; Guests & CRM › Feedback & Reputation › Surveys | The default survey is scheduled when a reservation checks out and sent after the delay (4 h by default) by e-mail, SMS / WhatsApp or both, with one reminder (48 h) and expiry (14 days). Nobody is surveyed twice within the cooldown (30 days); guests without a valid address, with restricted data or who already gave feedback in the portal are skipped, with the reason recorded. Staff can send a survey for any in-house or checked-out stay. |
| **Survey page** | `/survey/{token}` (mobile) | Recommend score 0-10 (NPS), 1-5 star ratings linked to departments, yes/no, multiple choice and comments, plus consent to quote. 40-character HMAC token (only its hash is stored), noindex / no-referrer / no-store headers, answered once. Promoters (9-10) are thanked and invited to post on Google / TripAdvisor (the click is recorded); unhappy guests are told a manager will follow up. |
| **Survey builder** | Surveys › Edit | Name, channel, timing, intro and thank-you text; add, switch off or remove questions. Answered questions keep their type and are switched off rather than deleted, so history stays readable; one NPS question per survey; a preview for staff. |
| **Text analysis** | Every response and review | Keyword sentiment with negation ("not clean") and clause splitting ("…but the breakfast was great"), complaint / praise areas per department (front office, housekeeping, room, maintenance, F&B, spa, value, location), and configurable **safety words** (bed bugs, theft, injury…) that always mean high severity. |
| **Service-recovery cases** | Guests & CRM › Guest Cases | Opened automatically for detractors (NPS ≤ 6), any rating ≤ 2★, safety words and online reviews ≤ 2.5★, or by staff for a desk complaint. Each case (`CASE-00001`) goes to the department's owner with an SLA (high 4 h / medium 24 h / low 72 h; 2 h while the guest is in the hotel), and has a timeline: acknowledge, notes, contact the guest by e-mail / SMS, resolve with the recovery action and **goodwill loyalty points** (above the limit only with the settings permission), close with the outcome (recovered / still unhappy / no reply), re-open. Overdue cases are escalated by the scheduler and flagged on the dashboard. |
| **Online review inbox** | Feedback & Reputation › Online reviews | Import a CSV export or add reviews by hand; duplicates are detected, 10-point ratings normalised to 5, and the review matched to the stay when exactly one guest of that name checked out in the previous 60 days. Response templates with placeholders (the one for the review's sentiment is suggested), a character counter, and **four-eyes approval** for responses to reviews ≤ 3★ (never by the author). Tabs: to respond, awaiting approval, ready to post, responded, no response. |
| **Overview** | Feedback & Reputation | NPS, satisfaction, survey response rate, online rating and open cases against the previous period; a six-month NPS / CSAT / online-rating trend; promoter / passive / detractor split; ratings per review site; department scores with praise vs criticism; unhappy guests, reviews waiting and open cases. |
| **Guest profile & dashboard** | Guest profile, dashboard, sidebar badges | Feedback, matched reviews and cases on the guest profile; dashboard alerts for cases past their SLA, open high-severity cases and reviews waiting > 48 h or for approval. |
| **Privacy** | Privacy & Data | Exports include survey answers, invitations, linked reviews and cases; erasure blanks feedback names and contacts, revokes survey links and unlinks reviews, keeping only anonymous scores. |
| **Reports** | Reports › Feedback & Reputation | NPS & satisfaction trend, guest feedback register, service recovery cases (SLA, recovery rate, goodwill), online review register and department scorecard (78 reports in total). |

Permissions: `feedback.view` (dashboard, responses, reviews, surveys), `feedback.cases`, `feedback.respond`, `feedback.approve` and `feedback.manage` (surveys, import, templates, settings). The Front Office Manager gets all five; Sales & Marketing gets view, respond and manage (not approve, not cases); the Receptionist, Housekeeping Supervisor and Chief Engineer get cases. The scheduler runs `pms:feedback-run` every 15 minutes (send / remind / expire surveys, escalate overdue cases).

**Demo:** as `fom`, open **Guests & CRM › Feedback & Reputation**. Eight surveys have been answered over the last months, one has been opened but not answered, and one is scheduled. `CASE-00001` shows a full recovery (e-mail, 1,500 goodwill points, guest satisfied). The latest detractor's case is past its SLA and escalated. There is an in-house AC complaint, and a 1★ Google review was matched to the guest's stay. David Miller's TripAdvisor response is waiting for your approval (written by `marketing`). To see the guest side, use *Preview* on **Surveys**, or get the open invitation's link with `php artisan tinker`: `App\Support\Tenant::set(App\Models\Hotel::first()); App\Models\SurveyInvitation::where('status','opened')->first()->url()`.

### v3.0 — what's new: GST compliance (e-invoicing, credit notes, GSTR-1)

Indian hotels issue tax invoices that must satisfy the GST rules. B2B invoices must be registered on the government's Invoice Registration Portal (e-invoicing, required above ₹5 crore turnover). Corrections after issue must be made by credit note. Each month's outward supplies are reported in GSTR-1. v3.0 builds all of this into billing.

| Feature | Where | What it does |
|---|---|---|
| **Invoice issue** | Settle & close / check-out | The invoice gets its **invoice date** and a frozen **buyer snapshot**: company GSTIN (or the guest's), name, address and state. It is classed B2B or B2C. The place of supply is the hotel's state, so every document carries CGST + SGST. Re-closing the same day keeps the number. |
| **GSTIN validation** | Companies, guests, walk-in bill, hotel settings | Checks the 15-character format, state code and the mod-36 check character. Only applies to properties in India. |
| **E-invoice (IRN)** | Billing › GST › E-invoices, folio panel | NIC schema 1.1 payload (seller, buyer, SAC per line, CGST/SGST, discount, preceding invoice for credit notes). Sent automatically on close through a **GSP / NIC-compatible API** (client id / secret / user / token; secrets never shown again) or the offline **sandbox**, which produces test IRNs = SHA-256(GSTIN + FY + type + number), 15-digit ack numbers and a signed JWT QR. **Data errors** (e.g. missing buyer PIN) are not retried: fix the profile, then *Refresh buyer*. **Network / IRP errors** are retried after 5, 15, 60, 180 and 720 minutes (`pms:einvoice-retry`, every 5 min). A duplicate-IRN answer is treated as success. The optional 30-day reporting limit is enforced. Every call is logged with its request and response. |
| **Cancel** | Folio › *Cancel invoice* | Allowed within 24 h of the IRN, before the month is filed, and while no credit note exists. The IRN is cancelled on the IRP, the number is recorded as cancelled (GSTR-1 table 13, never reused), and the folio re-opens. It gets a new number when settled again. |
| **Re-open locks** | Folio | Invoices with an IRN, from a previous business day, or in a filed month can't be re-opened. The screen says why and offers a credit note. |
| **Credit notes** | Folio › *Issue credit note* | Credit any part of each charge line (never more than is left). GST is reversed at the charged rate, or exactly the original tax on a full credit. Choose the reason and remarks, then settle as a **refund** (cash / card / UPI / bank, respecting cashier shifts) or a **credit to the company's city-ledger account**, which lowers its outstanding and ageing. Numbered `CN-00001`; B2B notes get a CRN e-invoice. Checks the section 34 deadline (30 Nov after the financial year) and the filed-month lock. Credit notes can be cancelled (lines voided). |
| **GSTR-1** | Billing › GST › GSTR-1 | 4A B2B (with IRN status), 7 B2CS (B2C invoices, restaurant bills settled at the outlet, B2C credit notes netted), 9B CDNR, 12 HSN/SAC split B2B/B2C, and 13 documents issued (invoice, POS bill and credit note series, cancellations and **gaps**). Pre-filing checks block B2B documents without an IRN and an invalid hotel GSTIN. **Portal JSON** download. *Mark as filed* with the ARN locks the month. |
| **Overview & alerts** | Billing › GST, dashboard, sidebar badge | Monthly taxable value and output tax, e-invoice status counts, failed e-invoices with next retry, the GSTR-1 due date (11th) and overdue alert, and filing history. |
| **Settings** | Billing › GST › Settings | E-invoicing on/off, auto-generation, the 30-day rule, the connection and SAC code per charge category (defaults 996311 rooms, 996331 F&B, 996334 events, 999712 laundry, 999722 spa…). |
| **Printed documents** | Invoice / credit note print | TAX INVOICE / CREDIT NOTE with SAC column, GST summary by SAC and rate (CGST / SGST at half rate), buyer GSTIN and state, place of supply, reverse charge, and the IRN / ack / QR block. Credit notes also show the reference to the original invoice. |
| **Reports** | Reports › GST & e-Invoicing | E-invoice register, credit note register and HSN / SAC summary (73 reports in total). The invoice list gets type, B2B/B2C and IRN badges, with filters. |

Permissions: `gst.view`, `gst.manage` (generate / cancel e-invoices, cancel invoices, file, settings) and `folio.credit_note`. The Accountant role gets all three; the Front Office Manager gets view + credit notes. `php artisan pms:upgrade` adds the permissions and settings, and back-fills the invoice date and buyer on existing invoices. E-invoicing stays **off** until it is switched on.

**Demo:** as `accounts`, open **Billing › GST & e-Invoicing**. The Yatra invoice was rejected because the company address has no PIN. As `admin`, add a PIN to Yatra's address, then *Refresh buyer* on the invoice: the IRN appears and prints with its QR. The TCS invoice was issued after cancelling one wrongly billed to Infosys (see *Credit notes & cancellations*). `CN-00001` is a B2B rate correction credited to Infosys' account; `CN-00002` is a guest refund. The previous four months are marked as filed.

### v2.9 — what's new: guest data privacy & compliance (DPDP Act 2023 / GDPR)

A hotel holds a lot of personal data: passport and Aadhaar scans, signatures, phone numbers, stay history and marketing consent. v2.9 lets it meet India's **Digital Personal Data Protection Act 2023** (and GDPR for European guests). Guests can exercise their rights, data is kept only as long as needed, identity data is protected on screen, and breaches are handled on the legal clock.

| Feature | Where | What it does |
|---|---|---|
| **Privacy requests** | Guests & CRM › Privacy & Data | Access, correction, erasure, consent withdrawal and grievance, logged from e-mail / phone / letter / desk or the public page. Every request gets a number (`PRQ-00001`), a **deadline** (30 days by default, one extension of 60 days with a reason), an assignee and a timeline. It is auto-linked to the guest profile when the e-mail or mobile matches exactly one profile. Tabs: open / overdue / awaiting e-mail / completed / rejected. |
| **Identity verification** | request page | Access, correction and erasure need a recorded identity check (photo ID, booking details, reply from the contact on file). A confirmed e-mail link alone is **not** enough to erase. Consent withdrawal and grievances need no ID. |
| **Access export** | request page, guest profile | A complete JSON file and a printable report. Covers the profile, consent history, stays, bills, loyalty, feedback, in-stay requests, spa, SMS / e-mails sent, campaigns, online payments, ID documents held, **who accessed the identity data**, and how long each item is kept. An access request can't be completed until an export has been generated. |
| **Erasure (anonymisation)** | request page (`privacy.erase`) | A plan shows exactly what will be cleared: profile fields, ID scans, GRC signatures, SMS / e-mail logs, portal & check-in links, special requests, feedback, POS / spa / laundry / payment contacts, OTA payloads, loyalty points and the name in the audit trail. Active bookings or open folios **block** it. With a legal hold, only the fields the law requires are kept and the profile becomes **restricted**: no booking, editing, marketing or look-ups. Holds are the tax invoice (8 years; name, address, GSTIN) and the police / Form-C register for foreign nationals (3 years; passport, visa, nationality). The retention job completes the erasure when the hold ends. Invoices and payments stay, without an identifiable person. Type `ERASE` to confirm. |
| **Retention policies** | Privacy › Retention, `pms:privacy-retention` (03:30 daily) | ID scans & signatures deleted N days after check-out (default 180, minimum 30). SMS / WhatsApp / e-mail contents purged after 365 days. Profiles inactive for 8 years anonymised. Unconfirmed web requests closed after 7 days. A **dry run** shows what is due, with examples. Every run is logged; *Run now* is available. |
| **PII masking** | guest profile, reservation page | ID number, passport, visa expiry and date of birth show as `••••••4567`. *Reveal* (`pii.reveal`, FOM / Administrator) asks for an optional reason. Every reveal, ID-document view, signature view and export goes to the **PII access log**, with filters and the top users over 30 days. |
| **Public privacy page** | `/privacy-notice/NOWILLS` (linked in every public page footer) | The privacy notice, the Data Protection / Grievance Officer, and a request form with a honeypot, a confirmation tick and a limit of 3 open requests per address. The request stays *unverified* until the e-mailed link (48 h, only its hash stored) is clicked. The deadline starts at confirmation. |
| **Breach register** | Privacy › Breach register (`privacy.breach`) | Title, severity, data categories, people affected, detected / occurred times. For notifiable breaches, a **72-hour clock** to notify the Data Protection Board is shown on the register, the breach page, the dashboard and the nav badge. Steps: containment → Board notification (reference) → people informed → close with root cause and lessons. A breach can't be closed before it is contained and the Board is notified. Non-notifiable breaches need a recorded reason. |

**Reports (+3 → 70):** Privacy request register (type, channel, identity check, due, days taken, on time / late / overdue, outcome), PII access log, Retention & erasure log (per day: scans, signatures, messages, profiles anonymised, holds completed, erasures on request).

**Roles:** `privacy.view`, `privacy.handle` and `privacy.breach` for the Front Office Manager. `pii.reveal` (reveal masked identifiers) for the FOM. The Receptionist sees masked values only. `privacy.erase` (erasure, retention settings and runs) is for the Administrator / DPO only. Sensitive permissions are never granted through `guests.*` wildcards.

**Integration:** walk-ins and new bookings never reuse an erased or restricted profile. Guest look-ups, segments, campaigns, automations and birthday journeys skip them. Consent can't be re-given on them, and ID uploads are refused.

**Demo data:**
- Last night's retention run (2 scans, 4 messages and 1 ten-year-old profile).
- Items due at the next run: 2 scans, 3 SMS, 2 e-mails and 2 legacy profiles.
- 8 requests:
  - An access request completed on time.
  - An executed erasure that left Michael Osei **restricted** until Aug 2028 (Form-C).
  - Oliver Brown's verified erasure waiting for the DPO.
  - An **overdue** correction.
  - A consent withdrawal.
  - A grievance due in 5 days.
  - An unconfirmed web request.
  - A rejected request.
- PII reveals by the FOM.
- A closed breach (notified in 30 h), plus an open phishing breach detected 30 hours ago, contained, **not yet notified**.
- DPO: Meera Iyer.

**Try it:**
1. As `fom`, open **Privacy & Data**: see the breach alert, the overdue correction and the deadlines. On a guest profile, click *Reveal ID details*, then check the **PII access log**.
2. From Carlos Rivera's profile, *Log request* (correction), verify identity and complete it.
3. Open breach `BR-…-002`: record the Board notification and the guest notice, then close it.
4. As `admin`, open `PRQ-00003` (Oliver Brown): the plan shows the police-register hold. Type `ERASE`: the profile becomes restricted.
5. Open `/privacy-notice/NOWILLS` in a private window and submit a request. The confirmation link is in `storage/logs/laravel.log` (mail driver `log`).
6. **Retention**: compare the dry run, then click *Run now*.

### v2.8 — what's new: two-way channel manager (OTA connectivity)

The iCal feeds from v1.2 only swap "sold out" ranges. v2.8 adds a real **two-way channel manager**. The PMS pushes availability, rates and restrictions (ARI) to each OTA, and receives new, modified and cancelled bookings over a signed webhook. Everything is built into the PMS, with no external service. The iCal feeds stay available for Airbnb-style calendars.

| Feature | Where | What it does |
|---|---|---|
| **Connections** | Reservations › Channel Manager | One connection per channel (Booking.com, Expedia, MakeMyTrip…). Each has a provider (*channel API / hub*, or *test channel* for the sandbox), the endpoint URL and an encrypted API key (the URL is checked against private / internal addresses). Terms per connection: commission %, rate markup % with rounding (none / ₹1 / ₹10), payment model (**pay at hotel** or **channel collect**, i.e. prepaid and billed to the OTA's company), and push horizon (days). Saving a connection creates the matching business source and OTA company, so bookings land in the right reports and the city ledger. Pause / resume; deletion is refused once the channel has bookings. |
| **Mapping** | connection page | Channel room code and **max sell** per room type (the most rooms ever offered on that channel); channel rate codes for BAR and each meal plan. Codes are unique per connection. The ARI preview shows exactly what will be sent for the next 14 days. |
| **ARI push** | `pms:channel-push` (every minute), *Push changes*, *Full sync*, night audit | Bookings, cancellations, room moves, rate / RM changes and restrictions mark the affected dates *dirty*. The push sends **only the changed dates**, signed with HMAC-SHA256, using a watermark so a change made during a push is never lost. A full refresh (365 days in 31-day chunks) runs nightly, after mapping changes and on demand. A failure keeps the queue and retries with back-off (1, 5, 15, 30 and 60 min). The HTTP status and error are kept in the **sync log**, and the dashboard flags the channel as *failing*. |
| **Channel restrictions** | connection page | Stop-sell, minimum stay and closed-to-arrival per channel, room type (or all) and date range, with a reason. These are added on top of the hotel's own rate-calendar restrictions. |
| **Inbound bookings** | `POST /api/channel/{token}/reservations` | Handles `new`, `modify` and `cancel` events. Headers: `X-Nowills-Timestamp` (±5 min) and `X-Nowills-Signature: sha256=hex(HMAC(secret, "{timestamp}.{raw body}"))`. The request is validated (dates, currency, mapped codes, guest). Repeats are **idempotent**: the same booking ID and content returns *duplicate* with the same reservation number. The guest is matched or created and the reservation is confirmed with the OTA reference, amount and commission. **Card data is stripped** before the message is stored. Replies: 200 accepted / duplicate, 202 needs attention, 401 bad signature, 404 unknown endpoint, 422 rejected, 423 paused. `GET /api/channel/{token}/ari?from=&days=` lets a hub pull ARI (signed over `from=…&days=…`). |
| **Needs attention** | OTA inbox, dashboard, reservation page | OTA bookings are guaranteed, so a booking with **no inventory left is accepted and flagged as overbooked**. The team upgrades, moves or relocates the guest, then marks it *resolved* with a note. Unmapped room / rate codes wait in the inbox; after mapping, **Reprocess** creates the booking. Modifying or cancelling a stay that is already in-house is flagged instead of changing the live stay. A modification of a cancelled booking and a cancellation of an unknown booking are also flagged. Any flagged or failed message can be closed as *resolved* with a note, and the raw payload is viewable. |
| **Rate parity** | Channel Manager › Rate parity | A 14-day grid per connection of availability and each channel rate as the PMS would publish it now, next to direct BAR. Cells where the channel last received something different are highlighted, and *Push now* sends them. |
| **Test channel** | connection page (test channel) | A built-in simulator that sends new / modify / cancel bookings through the same inbound code path, with no network, for training and go-live checks. |

**Reports (+3 → 67):** OTA channel production (bookings, room nights, revenue, commission, net, ADR, cancellations per channel), Channel sync log (pushes with status, room-nights, duration and error; CSV), Rate parity (out-of-sync cells per connection).

**Roles:** `channels.view` (dashboard, inbox, sync log, parity) for Receptionist, Accountant and Sales & Marketing. `channels.resolve` (resolve overbookings and inbox items) for Receptionist and FOM. `channels.manage` (connections, mapping, restrictions, pushes, secrets) for FOM and Administrator. The sidebar badge counts messages needing attention, and the hotel dashboard shows overbookings, inbox items and failing channels.

**Demo data:**
- Booking.com (connected, pay at hotel, 15 %, a Diwali stop-sell on Executive Suites and a weekend minimum stay).
- Expedia (channel collect, 18 %, +5 % markup; **failing** with HTTP 503 and retrying).
- MakeMyTrip (paused).
- *Test Channel (Sandbox)*.
- Six OTA bookings, including a modification, a cancellation and an Expedia prepaid stay billed to the Expedia company.
- An inbox message with an unmapped room code (`VILLA-POOL`) and a rejected USD booking.

The Booking.com / Expedia endpoints are placeholders. Point a connection at a real hub endpoint, or use the test channel.

**Try it:**
1. As `fom`, open **Channel Manager** and then *Booking.com*: see the mapping, ARI preview and restrictions. Add a stop-sell and watch the pending count rise.
2. Open *Test Channel (Sandbox)* and send a simulated booking. Open the reservation (it shows the channel, amount and commission).
3. Map the Presidential Suite on the test channel and simulate 3 rooms: the booking is accepted and flagged. As `frontdesk`, resolve it from the dashboard.
4. In the **OTA inbox**, open `TEST-10002`, map `VILLA-POOL` to a room type and click *Reprocess*.
5. Check **Sync log** (Expedia's failures) and **Rate parity**.

### v2.7 — what's new: corporate & travel-agent contracts, credit control

v2.7 adds the negotiated side of hotel sales. Each corporate account or travel agent can have a **rate contract**. Contract rates flow automatically into reservations, walk-ins and the booking engine. Rooms promised to an account are **held as an allotment** until their release date. Accounts can also stop anyone billing a company that is over its limit or behind on payment.

| Feature | Where | What it does |
|---|---|---|
| **Rate contracts** | Guests & CRM › Rate Contracts | Tabs: all / active / awaiting approval / expiring / suspended / expired, with production vs target and the period elapsed. Pricing is either **net rates** or **% off the public rate**. Net rates are set per room type (single, double from 2 adults, extra adult), with optional seasons (a season line beats the all-period line). With % off, the discount follows each night's best available rate. Also set per contract: meal plan and inclusions, **blackout dates** (public rate charged), **LRA** (last-room availability, which ignores stop-sell and minimum stay), free-cancellation days, a commission % that overrides the agent default, a room-night target, and a booking-engine **corporate code**. |
| **Approval & lifecycle** | contract page | New and amended contracts are **drafts** and apply to nothing. An approver (FOM / Administrator) who is **not the drafter** activates it. Activation is refused if it overlaps another active contract for the company. Active contracts are locked; *Suspend* (with a reason) sends new bookings back to public rates and frees the allotment. *Renew* drafts the next period with dates shifted, net rates raised by an uplift % and blackouts / allotments copied. The agreement letter can be printed for the client to sign. |
| **Allotments** | contract form & page | Rooms per night per room type for a date range, with release days. Unsold rooms are **held out of general sale** (the calendar, reservations, walk-ins and the booking engine all see less stock). The contract itself can still book them. Rooms return to general sale once the night is inside the release window. The contract page shows a 30-day grid (left / allotted, released) and utilisation over the allotment period. |
| **Contract pricing in bookings** | reservation form, walk-in, booking engine | Pick the company and rack-rate lines are priced from its contract. A banner explains the contract, and any blackout / out-of-period nights, which are charged at the public rate. The server resolves the contract again on save and links the booking (`rate_contract_id`). Contract bookings follow the contract's cancellation days, and agent commission uses the contract %. On the website, guests enter the **corporate code** (it can't be combined with a promo code). The booking is at the contract price, counted against the contract, and paid by the guest. |
| **Credit control** | Accounts › Credit Control | Per account: terms, city ledger, overdue (receipts applied to the oldest bills first, due = bill date + credit days), in-house folios that will be billed, **exposure**, available credit and limit used. The account page shows open bills with ageing, in-house folios, the **credit log**, terms (limit, credit days, direct-billing approval), *Put on hold / Release* with a reason, and *E-mail statement*. |
| **Credit guard** | check-out, folio › City Ledger, reservation form | Companies not approved for direct billing are refused outright. A company **on hold**, or a bill that takes it **over its limit**, needs a user with `credit.override` and a reason (logged with the folio). The billing forms show the account's standing live. Reservations can't be set to *Bill to company* for a company on hold (unless the user can override). |
| **Automatic hold** | night audit, `pms:credit-review` (06:10), *Credit review* button | Accounts with a bill overdue more than the grace days (default 15) are put on hold, and released automatically once cleared. Manual holds are never released automatically. |

**Reports (+3 → 64):** Contract production (room nights / revenue / ADR vs public in the period, cancellations, target), Allotment utilisation (allotted, picked up, released unsold, still held), Credit exposure.

**Roles:** Sales & Marketing draft and renew contracts (`contracts.manage`). The Front Office Manager approves / suspends (`contracts.approve`) and can override credit (`credit.override`). The Accountant has all `credit.*` (limits, holds, statements). Receptionists can view contracts. The sidebar badges count contracts expiring / awaiting approval and companies on hold, and the dashboard lists them.

**Demo data:**
- INFY-2026 (Infosys, net, CP, LRA, code `INFY26`, Christmas blackout, 2 Deluxe rooms/night allotment, bookings linked).
- TCS (15% off, **ending in 45 days**; TCS is over its ₹20,000 limit).
- TC-WINTER (Thomas Cook, **awaiting approval**; Thomas Cook is on **automatic hold**, its bills being about 3 months old on 30-day terms).
- An expired Yatra contract (Yatra is not approved for direct billing).

**Try it:**
1. As `marketing`, draft a contract and add rate lines, an allotment and a blackout. The approve button is missing for you.
2. As `fom`, open *Awaiting approval* and activate it.
3. Open a new reservation and pick *Infosys Technologies*: a Deluxe line prices at 3,800 (2 adults) or 3,400 (1 adult).
4. Open `/book/NOWILLS?corp=INFY26`.
5. On an Infosys in-house guest's check-out, choose *Bill to company*, then switch to TCS: the over-limit warning and the override box appear.
6. As `accounts`, release Thomas Cook's hold under **Credit Control**.

> **Upgrading from v2.6:** run `php artisan migrate`, then `php artisan pms:upgrade`. The migration adds `rate_contracts` (+ rates, blackouts, allotments), `credit_logs`, credit columns on companies (30 credit days, direct billing on, status open) and `rate_contract_id` on reservations. `pms:upgrade` grants the new permissions and adds the credit / expiry settings. Existing companies start **open**. Run `php artisan pms:credit-review` once to apply the automatic hold rule (or switch it off under *Credit review*). No new cron entry is needed.

### v2.6 — what's new: guest marketing & CRM campaigns

v2.6 turns the guest database into a direct-marketing tool. Marketing staff build **segments** from the stay history, send **campaigns** by e-mail, SMS or WhatsApp, and run **automations** that go out every morning. Every booking a message produces is **credited back** to it, so each campaign shows its own revenue. Consent is tracked per channel and every change is logged. Guests can manage their own preferences from any message.

| Feature | Where | What it does |
|---|---|---|
| **Marketing dashboard** | Guests & CRM › Marketing Dashboard | Reachable guests, messages delivered (30 days), click-through, attributed bookings and revenue (net of promo discounts). Recent campaigns with results, consent by channel, a 14-day delivery chart, active automations, bookings credited to marketing, birthdays in the next 14 days and the RFM mix. *Refresh guest stats* recalculates stays / nights / revenue on demand. |
| **Guest segments** | Guest Segments | Rules on 16 fields (stays, nights, lifetime revenue, days since last stay, loyalty points, profile age, VIP, company, upcoming booking, country, nationality, city, gender, tag, birthday / anniversary month) with *all* / *any* matching. The count and a plain-language description update as you type. Invalid rules are dropped on save. Segments are **dynamic**: membership is worked out when the campaign sends. You can export a CSV of names and channels (opt-ins only). |
| **Campaigns** | Campaigns (sidebar badge = scheduled) | Draft → scheduled → sent (or cancelled). Pick a channel, segment and optional promo code. Write the message with **tokens** such as `{first_name}`, `{points}`, `{last_stay}`, `{promo_code}`, `{promo_offer}`, `{book_link}` and `{unsubscribe_link}`, with a live preview for a sample guest. The page shows how many guests are eligible and why the others will be skipped. *Send test* delivers to any address and is not counted. You can *Send now* or *Schedule* (`pms:crm-run` checks every 5 minutes). A send is claimed atomically, so a double click or two schedulers can't send it twice. An interrupted send resumes without contacting anyone twice. |
| **Delivery rules** | every send | Each recipient gets a row marked *sent*, *failed* or *skipped*, with the reason: **No consent** (per channel), **Blacklisted**, **No valid e-mail / mobile**, or **Frequency cap** (default 2 campaign messages per guest per 7 days; automations are exempt). An SMS / WhatsApp message gets an opt-out line. An e-mail gets an unsubscribe footer and `List-Unsubscribe` / one-click headers. Delivery uses the existing mail and SMS / WhatsApp drivers, so it appears in their logs. |
| **Automations** | Automations | Birthday (with a leap-day catch-up), wedding anniversary, **pre-arrival** (n days before a confirmed arrival, including reservation tokens and the online check-in link), **post-stay** (n days after check-out, with a feedback link) and **win-back** (n days after the last stay, if nothing is booked). Each can be narrowed to a segment and can carry a promo. They run at 09:00 (`pms:crm-run --journeys`) and after the night audit. Each guest is contacted **at most once per occasion** (birthday year, booking, lapse). *Run now*, the log, and switching on / off need `crm.send`. |
| **Promo codes** | Promo Codes | A percentage (≤ 90 %) or amount off **per room-night**. Options: booking window, stay window, minimum nights, room types, total uses and uses per guest. Guests type the code on the booking engine, or it arrives in a campaign link. Prices show struck-through with the discount. The discount is stored on the reservation. **A cancelled booking frees its use.** |
| **Attribution** | automatic | A booking made through a tracked link (`/m/{token}`) → **click**. A booking with a promo code carried by a campaign → **promo**. Any other booking within 30 days of a delivered message → **influenced**. Campaign and automation pages show bookings, room nights, revenue, discount given and conversion. |
| **Consent & preferences** | guest profile › Marketing; booking engine; `/u/{token}` | Staff record opt-ins per channel. The booking engine has an opt-in box with your own consent text. The preferences page lets a guest switch channels **off** (never on) or unsubscribe from everything. Every change is logged with its source (front desk, booking engine, unsubscribe page), user and IP. The profile shows RFM scores, the marketing history and bookings credited to campaigns. |
| **Reports** | Reports › Guests & CRM | **Campaign performance** (recipients / delivered / skipped / failed / clicks / CTR / unsubscribes / bookings / room nights / revenue / discount per campaign and automation), **promo code redemptions** (uses, room nights, revenue, discount), and **guest value** (RFM segment, stays, nights, revenue, last stay and consent per guest). |
| **Settings** | CRM Settings | Frequency cap, attribution window, SMS opt-out line and booking-engine consent text. |

**Permissions** (group *CRM & Marketing*):

| Permission | Allows | Default roles |
|---|---|---|
| `crm.view` | Dashboard, campaigns, segments, automations, promo codes, CRM reports | Sales & Marketing, Front Office Manager |
| `crm.manage` | Create / edit segments, campaign drafts, automations and promo codes; test sends; record guest consent | Sales & Marketing, Front Office Manager |
| `crm.send` | Send / schedule campaigns, switch automations on and off, run them, CRM settings | Sales & Marketing |

**Demo data (NOWILLS):**
- 6 segments: *High-value*, *Repeat guests*, *Lapsed 6+ months*, *Ghana & West Africa*, *Birthdays next month*, *Corporate travellers*.
- 5 promo codes: `HARVEST15`, `BDAY20`, `WELCOMEBACK` (₹1,000 per night), `FESTIVE12`, and the expired `CORP10`.
- 4 campaigns:
  - *Harvest weekend escape*: sent 10 days ago; 16 delivered, 4 skipped for no consent, 8 clicks, 1 unsubscribe, 3 attributed bookings.
  - *Corporate rate refresher*: sent 40 days ago.
  - *Festive season early-bird*: SMS, scheduled for 28 Sep.
  - *Sunday jazz brunch launch*: WhatsApp draft.
- 5 automations, 3 of them active with a month of history.
- Consent spread across channels. Six imported historical guests (no folios) count at their room rate.
- Guest *Rahul Verma*'s birthday is today.
- New login `marketing` / `Pass@123` (Efua Asante, Sales & Marketing).

**Try it:** log in as `marketing`. On the **Marketing Dashboard**, open *Harvest weekend escape* to see who was skipped and why, and which bookings it produced. Build a segment "Ghana *and* at least one stay" and watch the count. Create a promo code and an e-mail campaign that uses it, then send a test, then send it for real. Open one recipient's `/m/…` link from the database (or the e-mail log) and book with it on the booking engine. The booking is credited as a **click**. Open `/u/…` for the same message and switch e-mail off. The guest's profile shows the logged change. Open **Automations › Post-stay thank you** to see one message per booking.

> **Upgrading from v2.5:** run `php artisan migrate`, then `php artisan pms:upgrade`. The migration adds consent, tag and guest-value columns to guests, plus promo / attribution columns to reservations. Existing guests start **opted out** on every channel (record consent before marketing to them). `pms:upgrade` grants the new permissions and adds the CRM settings. Then run `php artisan pms:crm-run --journeys` once to calculate guest stats. No extra cron entry is needed: `schedule:run` already picks up `pms:crm-run` (every 5 minutes, plus 09:00 with `--journeys`). The night audit also refreshes stats and runs the automations. Automations are created per property; the demo seed adds five, and a new property starts with none.

### v2.5 — what's new: banquet & conference sales

v2.5 rebuilds Banquet around how a hotel actually sells events. An event is a **client booking with one or more functions** (ceremony, reception, day-1 plenary, gala dinner…), each in its own venue, set-up, time slot and menu. It moves through a sales pipeline: **enquiry → tentative hold → definite → billed**. Deposits are real payments on the event's own folio, so the final bill only has to collect the balance.

| Feature | Where | What it does |
|---|---|---|
| **Events & enquiries** | Banquet › Events & Enquiries (sidebar badge = events to bill + options due within 2 days) | Stats for definite and tentative business, enquiries with the 90-day conversion rate, deposits held, and events to bill. Tabs: upcoming definite, tentative holds (by option date, expired ones in red), enquiries, to bill, completed, lost & cancelled, all. Filter by type or sales person. |
| **Event form** | *New enquiry* / *Edit* | Client, company (bill to), contact, source, sales person, **linked room block**, deposit required and due date, client requirements (printed) and internal notes (not printed). **Functions** are added and removed in the form. Each has a venue, date, time, set-up (theatre / classroom / rounds / U-shape / cocktail / boardroom), expected and guaranteed pax, menu package (rate filled in, can be overridden) and venue rent (half- or full-day filled in automatically). Each function also has **services & equipment lines** priced from the catalogue. A **live estimate** shows the capacity hint for the set-up, rent basis, function totals, tax and grand total. |
| **Capacity & clashes** | on save / hold / confirm | Capacity is checked **per set-up** (the Ballroom seats 450 theatre-style but 300 at rounds). A package's minimum pax, past dates and end-before-start are refused. Tentative and definite functions **hold the venue**; any overlap, allowing a configurable **turnaround** (60 min), is refused with the clashing event named. Enquiries don't hold a room, but they can't be held or confirmed over someone else. |
| **Pipeline** | event page | *Hold tentative* / *Extend option* (option date must come before the event). *Confirm definite* needs the deposit in full. *Lost* takes a reason and is blocked while money is held. *Re-open* works on lost events. **Auto-release:** at the night audit and in the daily `pms:banquet-release` job, holds past their option date are marked lost and the venue is freed. Holds with a deposit are left for the sales team. |
| **Guarantees** | edit form / event page | Final numbers are due N days before each function (3 by default). After the cut-off the guarantee can go **up but not down**. F&B is charged on **the higher of guarantee and actual attendance**. |
| **Deposits & refunds** | event page (`banquet.billing`) | Deposits are taken in cash, card, Mobile Money or bank onto a `banquet` folio opened for the client (city ledger and online modes are refused; use a payment link from the folio for online payment). Refunds are limited to the unused credit, and a cancelled or lost event's folio closes when it reaches zero. |
| **Billing** | *Bill event* once the last function is over | Actual attendance is recorded per function first. The bill posts one `banquet` charge per F&B, venue and extra line, each tagged with its function and taxed at its own rate, then completes the event. Accountants land on the folio to settle it or transfer it to the company. |
| **Cancellation** | *Cancel booking* (definite only) | A days-before scale (`60:0,30:25,14:50,7:75,0:100`) is quoted in the dialog and posted as a taxable `cancellation` charge against the deposit. Waiving the fee needs `banquet.billing` and a reason, which is recorded. |
| **Function diary** | Banquet › Function Diary | Venues × days (week / 2 weeks / month), colour-coded definite / tentative / enquiry / completed, with *+* on any free cell to start an enquiry for that venue and date. |
| **Documents** | event page / Function Sheet | **BEO** (per-function set-up, "set for" with a 5 % overset, menu, notes, services grouped by the department that delivers them, billing instructions, signatures and a distribution list). **Proposal / contract** (priced lines, menus, deposit, cancellation scale, terms, acceptance block). **Daily function sheet** for the kitchen, service, HK, engineering, FO and security. |
| **Set-up** | Venues, Menu Packages, Services & Equipment, Banquet Settings | Venues with capacity by set-up, area, half- and full-day rent and an outdoor flag. Packages with type, service style, price per pax, minimum pax, tax and menu. Services with category, unit (event / day / piece / person / hour), price, tax and delivering department. Settings: guarantee days, turnaround, half-day hours, suggested deposit %, venue / cancellation tax rate, cancellation scale (with a live preview) and the terms printed on proposals. |
| **Reports** | Reports › Banquet | **Banquet event report**, **sales pipeline** by event month (count / value per stage, cancellation fees, conversion %), **venue utilisation** (functions, days, hours, occupancy of a 16-hour day, covers, rent / F&B revenue) and **function forecast** (upcoming covers, menus, set-ups and services, for purchasing and staffing). |

**Permissions** (group *Banquet*):

| Permission | Allows | Default roles |
|---|---|---|
| `banquet.view` | Events, diary, BEO, proposals, function sheet, banquet reports | Banquet Manager, FOM, Accountant, Chef, Housekeeping Supervisor, Chief Engineer |
| `banquet.manage` | Create / edit, hold, confirm, lost, re-open, cancel, attendance | Banquet Manager |
| `banquet.billing` | Take / refund deposits, bill events, waive cancellation fees | Banquet Manager, Accountant |
| `banquet.setup` | Venues, packages, services, banquet settings | Banquet Manager, Administrator |

**Demo data (NOWILLS):** new venue *Poolside Lawn* (outdoor, 700 cocktail); capacities by set-up for all venues; 8 menu packages and 11 services. Ten events cover every state:
- `BQT-00001` *Mensah–Owusu Wedding*: definite, 3 functions over 6–8 Oct on the lawn, Ballroom and Emerald, linked to room block `BLK-00001`, ₹400,000 deposit.
- `BQT-00002` *Accra Fintech Summit*: tentative, 4 functions, linked to `BLK-00002`, option 2 Oct.
- `BQT-00003` *TCS leadership programme*: definite on 29–30 Sep, day-1 guarantee already final, 50 % deposit.
- `BQT-00004` *Rotary charter night*: billed on 20 Sep (192 attended vs 180 guaranteed), folio settled.
- `BQT-00005` *board meeting*: today, ready to bill.
- `BQT-00006`: tentative birthday with its option due in 2 days.
- `BQT-00007`: product launch with its option **expired**.
- `BQT-00008`: November engagement enquiry.
- `BQT-00009`: school annual day, lost on price.
- `BQT-00010`: sales offsite cancelled 30 days out (25 % fee, ₹1,827.50 deposit left to refund).
- New login `banquet` / `Pass@123` (Farah Khan, Banquet Manager).

**Try it:** log in as `banquet`. Open the **Function Diary**, click *+* on a free Emerald Hall evening and build a two-function enquiry, watching the estimate. Hold it, try to confirm (the deposit is missing), take the deposit and confirm. Try to put a tentative hold on the Ballroom at 18:00 on 7 Oct (refused: the wedding reception is there). On the board meeting, record 14 attendees and bill it. Print the wedding **BEO** and the Fintech **proposal**. As `accounts`, refund the offsite's unused deposit. As `chef`, open the **Function Sheet** for 7 Oct.

Screenshots: `docs/screenshots/p16_index.png`, `p16_form.png`, `p16_show.png`, `p16_diary.png`, `p16_folio.png`, `p16_cancel.png`, `p16_beo.png`, `p16_quote.png`, `p16_sheet.png`, `p16_settings.png`, `p16_report.png`.

> **Upgrading from v2.4:** run `php artisan migrate`, then `php artisan pms:upgrade`. The migration turns every existing event into an event with one function (same venue, date, time, pax, rate and hall charge). Old *confirmed* events become **definite**. The legacy *advance* figure stays on the record for reference, but it was never a folio payment and does not count as a deposit: record real deposits with *Receive* on the event page. `pms:upgrade` adds the banquet settings (without overwriting yours) and grants the new permissions. No extra cron entry is needed: `pms:banquet-release` is already scheduled daily at 06:05 and also runs in the night audit.

### v2.4 — what's new: store requisitions (indents) & departmental cost control

v2.4 replaces ad-hoc stock issues with a proper **indent workflow**. A department asks, its head approves (and can cut quantities), the store issues what it has, and the department acknowledges receipt. Every issue is charged to **the department's own cost account** and counted against its **monthly stores budget**. Stock sent to a sub-store (kitchen, bar) is a **transfer** and posts nothing to the ledger until it is consumed there.

| Feature | Where | What it does |
|---|---|---|
| **Requisitions** | Inventory › Requisitions (sidebar badge = things waiting for *you*) | Tabs: *Needs my action* (approvals for a HOD, issues for the store, receipts for the raiser), open, drafts, awaiting approval, to issue, part issued, closed and all. Filters by department and search. Each row shows priority, destination, value, fill % and age; urgent and overdue rows are flagged. |
| **Raising** | *New requisition* | Department, issuing store, **deliver to** (a sub-store = transfer; blank = used by the department = expensed), needed-by date, priority and purpose. Item lines show unit, stock in the issuing and receiving store (red when the store can't cover it) and value at last cost. A **live budget panel** shows issued + committed + this request against the monthly budget. Duplicate lines are merged; de-activated items, past dates and the same store at both ends are refused. *Save draft* or *Submit*. `REQ-00001` numbering; *Repeat* copies a past requisition into a new draft. |
| **Auto-approval** | Budgets & Par Levels › Settings | Requisitions below a value (₹500 in the demo; 0 = off) are approved on submit, **unless they would take the department over budget**. |
| **Approval** | requisition page | The **head of department** (or anyone with setup rights; anyone with approval rights if the department has no HOD) approves or rejects with a reason. **You can never approve your own requisition.** Quantities can be cut, never raised. **Over budget:** in *warn* mode the approver must tick *approve over budget* and give a reason (the requisition is flagged); in *block* mode only a user with `requisitions.manage` can approve it. |
| **Issue notes** | requisition page (store keeper) | Issue what's there now, as many times as needed (`SIN-00001`). Quantities default to what's in stock. The date can be back-dated to the requisition date, but not past the business date or into a locked period. Rate = last cost. **Transfer** → `transfer_out` / `transfer_in` movements, no journal. **Direct issue** → an `issue` movement tagged with the department, which the night-audit ledger posts as Dr *department cost account* / Cr *1310 Inventory* (back-dated issues re-post the closed day at once). Part-issued requisitions stay open; *Close short* (with a reason) releases the rest from the budget. Printable requisition and issue note with signature boxes. |
| **Shortages → purchase** | *Raise purchase order* on the shortage alert | For lines the store can't cover (net of stock and quantities already on open POs), draft POs are created **per last supplier** at the last price, noting the requisition. Items with no purchase history are listed to order by hand. |
| **Acknowledgement** | issue note on the requisition | The raiser, or anyone in that department, confirms receipt with an optional note (missing / damaged). Unacknowledged notes show on the raiser's badge. |
| **Budgets & departments** | Inventory › Budgets & Par Levels | Per department: head (approver), **cost account** (any expense head, e.g. *6140 Printing & Stationery* for front office, *6040 Repairs* for maintenance), default sub-store and **monthly budget**, with a bar for issued vs committed this month. |
| **Sub-store par levels** | Budgets & Par Levels › Par levels | Par quantity per item for each kitchen / bar / housekeeping store. Suggested top-up = par − stock in the store − quantities already on open requisitions to it. **Requisition to top up** opens a pre-filled requisition for the store's department. |
| **Reports** | Reports › Inventory | **Requisition register** (requested / approved / issued value, fill rate, turnaround), **Departmental consumption vs budget** (issued through requisitions + ad-hoc issues, budget, variance, % used, number of requisitions), **Outstanding requisition lines** (the store's to-do list with stock and age). |

**New permissions** (group *Requisitions*):

| Permission | Allows | Default roles |
|---|---|---|
| `requisitions.view` | Requisitions, issue notes, budgets and par levels (read) | every role below |
| `requisitions.raise` | Raise, submit, cancel, repeat; acknowledge receipt | FOM, Housekeeping Supervisor, Cashier, Banquet, Chef, Spa, Laundry, Chief Engineer, Accountant |
| `requisitions.approve` | Approve / reject for the department they head | FOM, Housekeeping Supervisor, Chief Engineer, Accountant |
| `requisitions.issue` | Issue and close short | Store Keeper, Accountant |
| `requisitions.manage` | Departments, budgets, cost accounts, pars, settings; approve for any department and over budget in block mode | Accountant, Administrator |

The store keeper can't raise, the raiser can't approve and only the store issues, which keeps asking, approving and handing out stock separate. Raising a purchase order still needs `inventory.manage`.

**Demo data (NOWILLS):** 13 new items (stationery, engineering spares, napkins, cleaners); budgets for Front Office ₹8,000, Housekeeping ₹25,000, Kitchen ₹60,000, F&B Service ₹15,000 and Maintenance ₹12,000; pars for the kitchen and bar stores. 11 requisitions cover every state:
- `REQ-00001`–`00006`: issued. They include transfers to the kitchen and bar, a soap line cut by the HOD, a close-short (paneer 6 of 10), and a part issue (AC filters 4 of 12).
- `REQ-00005`: front office stationery (₹7,800 on *6140*), not yet acknowledged.
- `REQ-00007`: rejected.
- `REQ-00008`: urgent kitchen request awaiting approval (chicken short → FreshFarm).
- `REQ-00009`: housekeeping request awaiting approval (floor cleaner short → CleanPro).
- `REQ-00010`: approved **over budget** for Maintenance (at 114 % of budget).
- `REQ-00011`: front office draft that would exceed its budget.

**Try it:** log in as `housekeeping` and approve `REQ-00009`, cutting the soap. Log in as `store`, issue it, and press *Raise purchase order* for the floor cleaner. Log in as `laundry` and acknowledge receipt. As `fom`, submit `REQ-00011`, then approve it as `admin` (you'll be asked to confirm going over budget). As `cashier`, open **Budgets & Par Levels › Par levels › Bar Store** and press *Requisition to top up*. Finally check **Departmental consumption vs budget** and the *6140* ledger.

Screenshots: `docs/screenshots/p15_index.png`, `p15_approve.png`, `p15_form.png`, `p15_over_budget.png`, `p15_issue.png`, `p15_issue_note.png`, `p15_pars.png`, `p15_topup.png`, `p15_setup.png`, `p15_report.png`.

> **Upgrading from v2.3:** run `php artisan migrate`, then `php artisan pms:upgrade`. That grants the new permissions and gives each department a sensible cost account (kitchen / F&B → 5010, housekeeping / spa → 5020, maintenance → *Repairs & Maintenance*, others → 5030) and default sub-store, without overwriting anything you set. Auto-approval starts switched off and over-budget mode starts as *warn*. Then set HODs and budgets under **Budgets & Par Levels**.

### v2.3 — what's new: fixed assets & preventive maintenance

v2.3 gives the hotel a proper **fixed asset register**, tied to the general ledger, and puts **engineering maintenance on a schedule**. The ledger controls *1510 Fixed assets* and *1590 Accumulated depreciation* always equal the register.

| Feature | Where | What it does |
|---|---|---|
| **Asset register** | Fixed Assets › Asset Register | Tabs for in use / working / under repair / disposed / all, with search, category and department filters and CSV export. Stats show cost, accumulated depreciation (% written off), net book value and warranties ending within 60 days. Every asset has a number (`FA-00001`), category, make / model / serial, room or area, department, custodian, warranty, AMC vendor and expiry, and notes. |
| **Asset page** | click an asset | QR tag, cost → accumulated depreciation → book value bar with the residual, source (opening / direct / bill), depreciation history per run (reversed runs greyed), a **12-month projection**, PM schedules, maintenance history and repair cost to date. Actions: *Log ticket*, *Mark under repair / working*, *Edit*, *Dispose*, *Delete* (only if never depreciated). |
| **Acquisition** | *Add asset* | **Opening balance** (existing asset at go-live, with accumulated depreciation brought in; no journal, the opening JV carries it). **Paid directly** posts Dr 1510 / Cr the payment mode's account (cash / bank / card; not city ledger or online). **Supplier bill** links a v1.8 bill with a line on 1510, which already posted Dr 1510 / Cr AP, so no second journal is made. The total of assets on a bill can't exceed its capital lines. A live preview shows the monthly charge. Cost, dates and method are locked once depreciation has been posted. |
| **Categories** | Fixed Assets › Asset Categories | Default method, useful life or WDV rate, and residual % per category. Six defaults: Furniture & fixtures (SLM 10 yr), Kitchen & laundry equipment (SLM 8 yr), HVAC/electrical/plant (SLM 15 yr), IT & electronics (WDV 40 %), Vehicles (WDV 15 %), Building improvements (SLM 25 yr, nil residual). |
| **Depreciation runs** | Fixed Assets › Depreciation (`assets.depreciation`) | Shows the **next month due** with an asset-by-asset preview, then *Post*. SLM = (cost − residual) ÷ life per month; WDV = book value × rate ÷ 12. The first month is pro-rated by days in use; the charge never takes book value below the residual; no charge in the month of disposal. Months go strictly in order, only for closed months (before the business date) and after the period lock. Each run posts **one journal**, Dr *6500 Depreciation* / Cr *1590*, numbered `DEP-YYYYMM`. **Only the latest run can be reversed** (with a reason), and not if one of its assets has since been disposed of. A re-run gets `DEP-YYYYMM-2`. |
| **Disposal** | Asset › *Dispose* | Sold (proceeds + payment mode), scrapped or written off, on a date after the last depreciated month and not in a locked period. **Depreciation has to be posted up to the month before the disposal month.** Journal: Dr 1590 (accumulated) + Dr cash/bank (proceeds), Cr 1510 (cost), with the difference to *4980 Gain / loss on disposal*. PM schedules for the asset are switched off. |
| **Preventive maintenance** | Fixed Assets › PM Schedules (sidebar badge on *Maintenance* = overdue PM) | A schedule has a task, an asset or area, a frequency (every n days / weeks / months), a next due date, a lead time, an assignee / vendor, an estimated cost and a checklist. Tabs cover overdue, due now, scheduled and paused, with on-time completion % over 90 days. A ticket (`PM: …`, with the checklist) is raised once the task is within its lead time, **one open ticket per schedule**. This runs from `pms:pm-generate` (daily 06:00), the night audit, or *Raise due tickets*. **Resolving** the ticket records the date done and moves the schedule forward one interval, skipping missed cycles rather than piling them up. |
| **Maintenance link** | Housekeeping › Maintenance | Tickets can name an asset (optionally marking it *under repair*; resolving puts it back to *in use*), and carry a PM badge, an *overdue* tab and the asset column. Repair costs add up on the asset. |
| **Asset tags** | Register › tick assets › *Print tags* | Printable QR labels (hotel, asset no., name, location). Scanning a tag opens the asset's page. |
| **Reports** | Reports › Fixed Assets | **Fixed asset register** as at a date (cost, accumulated depreciation, book value by category), **Depreciation schedule** for a period (opening → additions → charge → disposals → closing, which ties to the ledger), **PM compliance** (every PM ticket due in the period: done on time / late / still open, days late, technician or vendor, cost). |

**New permissions** (group *Fixed Assets*):

| Permission | Allows | Default roles |
|---|---|---|
| `assets.view` | Register, asset pages, depreciation runs, categories, tags, reports | Accountant, Housekeeping Supervisor, Store Keeper, Chief Engineer |
| `assets.manage` | Add / edit / move / dispose of assets, categories | Accountant, Chief Engineer, Administrator |
| `assets.depreciation` | Post and reverse depreciation runs | Accountant, Administrator |

PM schedules use `maintenance.manage`. The new role **Chief Engineer** has maintenance, assets view/manage, reports and self-service, but not depreciation, which stays with accounts.

**Demo data (NOWILLS):**
- 25 assets worth ₹1.83 cr at cost. 23 were taken on as opening balances at go-live (1 Jun 2026), with accumulated depreciation brought in.
- An espresso machine bought directly on 18 Jul (₹3,85,000 by bank), and a laptop capitalised from TechWorld IT Solutions bill `TW/26-27/0815`.
- Depreciation posted for June (`DEP-202606`, ₹1,23,600.64) and July (`DEP-202607`, ₹1,22,645.80). **August is due.**
- Two disposals: the staff van sold for ₹1,85,000 (gain ₹28,106) and a tumble dryer scrapped (loss ₹38,120).
- 8 PM schedules: two overdue (washer lubrication, AC filters), three due now (chiller, pool, DG set) with open PM tickets, and the dishwasher under repair on a breakdown ticket.
- The second property has four opening assets.
- New login `engineer` / `Pass@123` (Joseph Thomas, Chief Engineer).

**Try it:** log in as `engineer` and open **PM Schedules**. Resolve the overdue AC-filter ticket from **Maintenance** and watch the schedule move to next month. Add an asset as *Paid directly* and log a breakdown ticket from its page. Then log in as `accounts` and open **Depreciation**: preview and post August, look at the journal, reverse it and post it again. Dispose of an asset and check the gain/loss, the **Depreciation schedule** report and the balance sheet.

Screenshots: `docs/screenshots/p14_register.png`, `p14_asset.png`, `p14_form.png`, `p14_maintenance.png`, `p14_pm.png`, `p14_depreciation.png`, `p14_run.png`, `p14_disposed.png`, `p14_schedule.png`, `p14_tags.png`.

> **Upgrading from v2.2:** run `php artisan migrate`, then `php artisan pms:upgrade`. That grants the new permissions, adds the Chief Engineer role, creates the default categories and the fixed-asset accounts (1510, 1590, 6500, 4980). Then add your existing assets as *Opening balance* with the accumulated depreciation to date, and post the matching opening journal (Dr 1510 / Cr 1590 / Cr opening equity) once.

### v2.2 — what's new: laundry & linen

| Feature | Where | What it does |
|---|---|---|
| **Laundry orders** | Laundry & Linen › Laundry Orders (sidebar badge = overdue) | *Collect laundry* from an in-house room or a walk-in customer, as normal (24 h) or express (6 h, +50 %). Enter the bag tag and the piece count at pick-up. Itemise then or later on a price-list grid with a live total and per-item remarks (stains, damage). Prices always come from the price list, never from the form. Orders move *Collected → Processing → Ready → Delivered*. Tabs cover open, overdue, each status, delivered and cancelled orders, and each order has a printable **bag docket**. |
| **Charging** | Order › *Deliver* | For in-house guests, delivery posts one *Laundry* line to the room folio with laundry tax, revenue account 4060. It posts once only, and a guest who has already checked out can't be charged by delivery. Walk-in customers pay on delivery (cash, card, UPI / mobile money or bank) and get a closed, zero-balance tax invoice. Only open orders can be cancelled, and cancelling needs a reason. |
| **Guest portal & front desk** | Portal › Requests › *Laundry*; Guest Requests; Check-out | Guests see the price list and turnaround times, request a pick-up, and follow **My laundry**. Staff click *Collect* on the request, which pre-fills the room. The request is marked *in progress* when the order is saved, *done* on delivery, and *declined* if the order is cancelled. The check-out screen lists the room's open laundry orders. |
| **Price list & settings** | Laundry & Linen › Laundry Price List | 18 default items (gents / ladies / kids / household), each with a price for any of the three services (blank = not offered). Items can be added, edited and switched off. Settings: `laundry_express_pct` (50), `laundry_normal_hours` (24), `laundry_express_hours` (6). |
| **Linen stock & par** | Laundry & Linen › Linen Stock & Par | 12 default linen types. Stock is held in the **linen room, on floors, soiled and at the laundry**. Par = rooms × pieces per room × par levels (`linen_par_levels`, default 4), or a fixed par for F&B or pool linen, shown with a cover bar and the shortfall. Movements: new stock (valued), issue to floors, soiled collected, and discard or lost at unit cost (a reason is required). You can't move more than a location holds. A **physical count** records the difference as an adjustment. |
| **Laundry batches** | Laundry & Linen › Linen Batches | *Send soiled linen* to a supplier laundry or the in-house laundry. The form is pre-filled with everything soiled, and anything extra is collected from the floors first. Pieces come back in part: clean ones go to the linen room and damaged ones are discarded. A batch closes itself when complete, or can be **closed short** (missing pieces written off as lost). Late batches are flagged. The charge is (returned + damaged) × the type's per-piece rate; lost pieces aren't paid for. |
| **Supplier bill** | Batch › *Create supplier bill* (`ap.manage`) | Turns a closed supplier batch into an AP expense bill (their invoice no., date, tax %) on *6130 Laundry & Linen*. It goes through the v1.8 approval, payment and ledger flow, one bill per batch. |
| **Reports** | Reports › Laundry & Linen | **Laundry register** (orders, pieces, express, amount, status, where charged), **Linen stock & par** (by location, shortfall, stock value) and **Linen losses & write-offs** (discarded / lost at cost, by batch and reason). |

**New permissions** (group *Laundry & Linen*):

| Permission | Allows | Default roles |
|---|---|---|
| `laundry.view` | Orders, price list, linen stock, batches and reports | Front Office Manager, Receptionist, Housekeeping Supervisor, Accountant, Laundry Attendant |
| `laundry.orders` | Collect, itemise, mark ready, deliver and cancel orders | Front Office Manager, Receptionist, Housekeeping Supervisor, Laundry Attendant |
| `laundry.linen` | Linen movements, counts, send / receive / close batches | Housekeeping Supervisor, Laundry Attendant |
| `laundry.manage` | Price list, laundry settings, linen types and par levels | Housekeeping Supervisor, Administrator |

The new role **Laundry Attendant** has orders, linen and self-service. Billing a batch needs `ap.manage`.

**Demo data (NOWILLS):**
- 11 laundry orders: five delivered (three charged to rooms, two paid walk-ins), one ready, three processing (one overdue, two express, one of them from a portal request) and one collected but not yet itemised.
- One cancelled order, and a portal pick-up request still waiting.
- Two months of linen history for 12 types, with several below par.
- Supplier **SparkleWash Laundry Services** with five batches: LB-00001 returned with damage and **billed**; LB-00002 **closed short** with pieces lost and still to bill; LB-00003 part-returned and late; LB-00004 in-house F&B linen; LB-00005 sent today.
- Floor discards, a lost bathrobe and a monthly count adjustment.
- New login `laundry` / `Pass@123` (Kwame Boateng, Laundry Attendant).

**Try it:** log in as `housekeeping` and open **Laundry Orders**. Click *Collect now* on the waiting portal request, tick a few items and choose express, then save, mark it ready and deliver it to the room. Collect for a walk-in customer and take cash. On **Linen Batches**, send the soiled linen and receive it back with one piece damaged. Then log in as `accounts`, open LB-00002 and create the supplier bill.

Screenshots: `docs/screenshots/p13_laundry.png`, `p13_collect.png`, `p13_order.png`, `p13_docket.png`, `p13_prices.png`, `p13_linen.png`, `p13_batches.png`, `p13_send.png`, `p13_batch.png`, `p13_checkout.png`, `p13_portal.png`, `p13_report.png`, `p13_report_par.png`.

> **Upgrading from v2.1:** run `php artisan migrate`, then `php artisan pms:upgrade`. That grants the new permissions, adds the Laundry Attendant role, and creates the default price list, the linen types and account 6130. Set each linen type's pieces per room, cost and laundry rate under **Linen Stock & Par**, then record your opening stock as *New stock received*.

### v2.1 — what's new: online payments

v2.1 lets guests pay the hotel online. Staff send a **payment link** for a deposit or a bill balance. The guest pays on the gateway's own hosted page, so card and wallet details never touch the PMS. The payment is confirmed server-to-server, posted **exactly once** to the right folio, reconciled through a gateway clearing account and followed through to the payout into the bank. The online booking engine can take a deposit before it confirms a booking, and in-house guests can pay their bill from the mobile portal.

| Feature | Where | How it works |
|---|---|---|
| **Payment links** | Reservation › *Request payment*, Folio › *Payment link*, Billing › Online Payments | The amount is suggested automatically (first night / outstanding deposit for a reservation; balance for a folio) and can be edited, with a description and validity (default `pay_link_hours` = 72). Send by **e-mail, SMS or WhatsApp** (templates use the v1.5 messaging gateway), copy the link, or let the guest scan the **QR code** at the desk. Each link has a number (`PL-00001`), a status and a full **timeline** of staff actions, checkout attempts, returns and webhooks. |
| **Guest pay page** | `/pay/{token}` (public, mobile-first) | Shows the hotel, amount, purpose, reservation and deadline, then hands over to the gateway. After payment it becomes a printable **receipt**. Expired, cancelled or under-review links explain themselves. It's rate-limited, `noindex`, and the token is 40 random characters. |
| **Gateways** | Administration › Online Payment Settings | **Paystack** (GHS, NGN, KES, ZAR, USD – cards and mobile money), **Stripe** Checkout (cards and wallets) and **Razorpay** payment links (UPI, cards, netbanking), called directly over HTTPS with no SDKs. A **built-in test gateway** runs the whole flow (pay, decline, insufficient funds; card, mobile money or UPI) without real money; it's the default, and a banner makes clear when it's active. Secret keys are stored **encrypted**, never shown again (blank keeps, tick to remove) and never flashed back into forms. The page shows the webhook URL to paste into each gateway dashboard. |
| **Verification** | (automatic) | A payment counts only when the server confirms it: through the gateway's API on return (Paystack *verify*, Stripe *session retrieve*, Razorpay *payment link fetch*) or by a **signed webhook** (Paystack HMAC-SHA512, Stripe `t=…,v1=…` with a 5-minute tolerance, Razorpay HMAC-SHA256). The test gateway signs its returns too. Forged or unsigned calls are rejected and logged with card, e-mail and phone fields redacted. Replayed webhook events are ignored by event ID. The payment row is locked while it's posted, so the return and the webhook arriving together can't post twice. |
| **Folio posting** | (automatic) | A confirmed payment posts a payment line with the **Online Payment** mode. It goes to the in-house guest's open folio, or to the reservation's advance folio before arrival, or to the folio the link was made for. The line is tagged with the link and **can't be voided** on the folio (use a refund instead). The guest gets an e-mailed receipt (`pay_receipt_email`). |
| **Needs review** | Online Payments › *Needs review* tab (sidebar badge) | If the amount or currency received differs, or the guest pays after the link expired or was cancelled, the payment is recorded but **not posted**. Someone with `payments.refund` checks it and either *Accepts & posts* what was actually received (a note is required) or refunds it. |
| **Refunds** | Online payment › *Refund* | Full or partial refunds, up to the amount received minus earlier refunds, go back through the gateway's refund API. They post a refund line on the same folio and appear on the timeline and in the report. |
| **Expiry & booking holds** | `pms:payments-expire` (every 5 minutes) | Expires unpaid links. For a real gateway it checks with the gateway one last time first, so a late payment is never lost. For an **online booking** it then cancels the tentative reservation and releases the room. |
| **Booking-engine deposits** | Settings › *Online bookings require* | None / first night / a % of the stay / the full stay. With a deposit, the booking is created **tentative**, the room is held for `pay_booking_hold_minutes` (default 60), and the guest goes straight to the payment page, which shows the hold deadline. Paying confirms the booking and sends the usual confirmation e-mail / SMS. If payments are switched off, bookings confirm immediately as before. |
| **Pay your bill in the portal** | Guest portal › Bill | In-house guests see **Pay ₹ X online** on their open folio (`pay_portal_enabled`). A repeat tap reuses the same open link rather than creating new ones. |
| **Gateway payouts** | Billing › Gateway Payouts | When the gateway settles, record the payout (`GPO-00001`): date, gross, fees, the bank account it landed in and the settlement reference. The page shows the unsettled balance, and a payout can't exceed what was collected up to its date or fall inside a locked period. Deleting one reverses its journal. |
| **General ledger** | (automatic) | Online payments debit **1050 Payment Gateway Clearing** (the Online Payment mode's account) and credit the guest ledger. Refunds reverse that. A payout debits the bank with the net amount and **6120 Payment Gateway Fees** with the fees, and credits the clearing account with the gross. *GL › Controls* reconciles the clearing account against the online-payment sub-ledger. Both accounts are blocked for supplier bills. |
| **Report** | Reports › Billing › *Online Payments* | Every link created or paid in the period: purpose, guest, reservation, folio, gateway, transaction ID, channel, status, requested / received / refunded / net and the gateway fee. It prints and exports to CSV. |

**Settings:** `pay_enabled`, `pay_provider` (demo / paystack / stripe / razorpay), `pay_link_hours` (72), `pay_receipt_email`, `pay_portal_enabled`, `pay_booking_deposit` (none / first_night / percent / full), `pay_booking_deposit_pct` (30), `pay_booking_hold_minutes` (60), plus the gateway keys (encrypted).

**New permissions** (group *Online Payments*):

| Permission | Allows | Default roles |
|---|---|---|
| `payments.view` | See links, the timeline and the report | Front Office Manager, Receptionist, Accountant |
| `payments.request` | Create, send and cancel links | Front Office Manager, Receptionist |
| `payments.refund` | Refund, and accept payments under review | Front Office Manager, Accountant |
| `payments.settle` | Record and delete gateway payouts | Accountant |
| `payments.settings` | Gateway choice, keys and deposit policy | Administrator |

**Demo data (NOWILLS, test gateway):**
- Three paid deposits: card, mobile money, and card with half refunded yesterday.
- A bill balance paid from the guest portal last night.
- One link awaiting payment (e-mailed 5 hours ago), one expired and one cancelled.
- One payment **under review**, where the guest paid ₹ 500 short.
- An online booking (Akosua Frimpong) **held** with a deposit link running for about 45 minutes after seeding. Leave it and the expiry job releases it.
- A payout of the two oldest deposits less 1.95 % fees.
- Bookings on the NOWILLS booking engine ask for a first-night deposit. The gateway clearing control reconciles.

**Try it:** log in as `frontdesk` and open an upcoming reservation. Click **Request payment**, then open the link in a private window and pay with the test gateway. The reservation shows the payment at once. Then log in as `accounts` to accept the payment under review, refund part of a payment and record a payout. Or book on `/book/NOWILLS` and pay the deposit.

Screenshots: `docs/screenshots/p12_payments.png`, `p12_request.png`, `p12_link.png`, `p12_guest_pay.png`, `p12_checkout.png`, `p12_guest_paid.png`, `p12_review.png`, `p12_refund.png`, `p12_payout_modal.png`, `p12_payouts.png`, `p12_settings.png`, `p12_report.png`, `p12_booking_modal.png`, `p12_booking_deposit.png`.

> **Upgrading from v2.0:** run `php artisan migrate`, then `php artisan pms:upgrade`. That grants the new permissions and creates the Online Payment mode and accounts 1050 / 6120. Make sure the scheduler (`php artisan schedule:run` every minute from cron) is running so links expire and booking holds are released. To go live, choose a gateway under **Administration › Online Payment Settings**, enter its keys, paste the webhook URL into the gateway dashboard, and keep `APP_URL` set to the public HTTPS address.

### v2.0 — what's new: HR & payroll

v2.0 extends the HR module from a staff list with daily attendance into a full monthly payroll. Attendance and approved leave feed payslips automatically. A run is prepared by HR, approved by someone else, paid by payment mode and posted to the general ledger. Every member of staff with a login can see their own payslips and leave and apply for leave.

| Feature | Where | How it works |
|---|---|---|
| **Attendance** | HR & Payroll › Attendance | Daily marking (Present / Absent / Half day / Week off, in / out times, remarks). Staff not yet marked default to Present, so one *Save* covers a normal day. Days covered by approved leave appear as leave badges and can't be overwritten. Hours beyond `payroll_shift_hours` count as overtime. Once a month's payroll is approved or paid, that month is **locked**. |
| **Monthly register** | HR & Payroll › Monthly Register | An employee × day grid for any month, colour-coded, with overtime underlined and days before joining left blank. Paid days and loss-of-pay days use exactly the same calculation as payroll. Filter by department; export to CSV. |
| **Leave types** | HR & Payroll › Leave Types | Code, yearly entitlement, paid or unpaid, carry-forward cap and half-day support. Seeded: CL 12, SL 12, EL 18 (carries up to 30) and LWP (unpaid). |
| **Leave requests** | HR & Payroll › Leave Requests | Tabs for Pending / Approved / Rejected / Cancelled / All, plus a **Balances** grid (entitled + carried − taken − pending = available). Approve, reject (a reason is required) or cancel. HR can also record leave already approved. The system checks balances, overlaps, the joining date, calendar-year and half-day rules, and months locked by payroll. **Nobody can decide on their own request.** The sidebar badge counts pending requests. |
| **Pay components** | HR & Payroll › Pay Components | Earnings, deductions and employer contributions. Each is calculated as a fixed amount, % of basic, % of gross (optional cap and eligibility ceiling), a slab table, or by the system (basic, overtime, advance recovery). Components can be pro-rated by paid days, taxable or not, apply to everyone or only where assigned, and each has its own expense or liability account. Seeded: BASIC, HRA 40 %, conveyance 1,600, OT, PF 12 % (capped at 15,000), ESI 0.75 % (gross ≤ 21,000), professional tax slabs, TDS, advance recovery, and employer PF / ESI. |
| **Income tax** | Pay Components › Payroll settings | Progressive annual slabs with a standard deduction, a rebate limit (no tax up to the limit) and cess / levy %. The system projects annual taxable pay and deducts 1/12 each month. Every setting is editable, so the tax rules can be set for any country. |
| **Employee pay setup** | Employees › *Pay setup* (or the pencil on a payslip row) | Per-employee overrides: switch a component on or off, or set a custom amount or %. The page also shows the bank account, tax ID and social-security numbers used on payslips and the bank advice. |
| **Payroll runs** | HR & Payroll › Payroll Runs | *Generate* a month: one payslip per active employee using attendance (paid days, loss of pay, overtime), paid leave, joining / leaving dates and open advances. The run page shows totals by component, the split by payment mode, and every payslip with paid days / LOP / OT. You can add one-off earnings or recoveries (bonus, incentive, damage) and recalculate after changes. |
| **Approval & payment** | Payroll run › Approve / Reopen / Mark paid | **Draft → Approved → Paid.** With `payroll_segregation` on (default), the person who generated a run cannot approve it. Approval posts the salary journal. *Reopen* (a reason is required) reverses it. *Mark paid* posts the payment by mode (bank, cash, mobile money…). A paid run is final. The bank advice CSV lists every bank-transfer payee. |
| **Payslips** | Run › *Payslips* / single payslip | A print-ready payslip: employee and bank details, days, earnings / deductions, net pay **in words**, and the employer cost. Staff can see only their own approved or paid payslips. |
| **Salary advances** | HR & Payroll › Salary Advances | Record an advance with a monthly instalment (limited to `payroll_advance_limit_months` × monthly salary). The advance is paid out through a ledger journal and recovered automatically on each payslip until cleared. The page shows recovered and outstanding amounts. An advance can be cancelled only before its first recovery. |
| **General ledger** | (automatic) | Approval debits 6010 Salaries & Wages and 6015 Employer Contributions. It credits 2200 Salaries Payable, the statutory liabilities (2210 PF, 2220 ESI, 2230 income tax, 2240 professional tax) and 1400 Staff Advances for recoveries. Payment clears Salaries Payable against each payment mode's account. Salary-payable and staff-advance controls reconcile in *GL › Controls*, and both accounts are blocked for supplier bills. |
| **Self-service** | HR & Payroll › My Payslips & Leave | For any login linked to an employee record: leave balances, my requests (withdraw while pending), apply for leave, payslips, this month's attendance, and outstanding advances. Roles without a dashboard (room attendant, chef) now land on their own screen after sign-in. |
| **Reports** | Reports › HR & Payroll | *Payroll Register* (every payslip with earnings, deductions, net and employer cost), *Statutory Deductions* (PF / ESI / tax / PT by month and liability, approved and paid runs only) and *Leave Register*. All three print and export to CSV. |

**Settings:** `payroll_shift_hours` (9), `payroll_ot_multiplier` (1.5), `payroll_unmarked` (unmarked days count as present / absent), `payroll_default_mode`, `payroll_advance_limit_months` (2), the tax slabs / standard deduction / rebate limit / cess, and `payroll_segregation` (Hotel Settings).

**New permissions** (group *HR & Payroll*):

| Permission | Allows |
|---|---|
| `hr.manage` | Employees, attendance, leave types and pay setup |
| `hr.leave` | Approving leave |
| `hr.self` | Self-service |
| `payroll.view` | Viewing runs, payslips and advances |
| `payroll.manage` | Generating and adjusting runs, pay components and advances |
| `payroll.approve` | Approving, reopening and paying runs |

The new **HR Manager** role has everything except `payroll.approve`, which the **Accountant** holds. The Front Office Manager can approve leave. Every staff role gets `hr.self`.

**Demo data (NOWILLS):**
- 15 employees with salaries, bank, tax and social-security details. Kofi Boateng joined mid-August. Five are linked to staff logins: `attendant`, `spa`, `fom`, `accounts` and `hr`.
- Attendance since 1 July, including absences, half days and overtime.
- 9 leave requests: approved (some already in paid months), one rejected, and 3 pending.
- **July 2026** is paid, **August 2026** is approved and awaiting payment (with an incentive and a damage recovery), and **September 2026** is a draft.
- Two salary advances being recovered.
- The ledger and all controls reconcile.

Log in as `hr` (prepares runs, manages leave), `accounts` (approves and pays) or `attendant` (self-service).

Screenshots: `docs/screenshots/p11_runs.png`, `p11_run_draft.png`, `p11_run_paid.png`, `p11_payslip.png`, `p11_leave.png`, `p11_balances.png`, `p11_register.png`, `p11_components.png`, `p11_self.png`.

> **Upgrading from v1.9:** run `php artisan migrate`, then `php artisan pms:upgrade`. That grants the new permissions, creates the HR Manager role, the payroll ledger accounts, default pay components and leave types. Then enter each employee's salary and bank details under **Employees**, link logins for self-service, and set the tax slabs for your country under **Pay Components**.

### v1.9 — what's new: revenue management (dynamic pricing & forecasting)

v1.9 turns rates from a static calendar into a revenue tool. The hotel sees how each future night is filling compared with its own booking pattern. Rules turn that demand into prices, which are either reviewed and published or applied live.

| Feature | Where | How it works |
|---|---|---|
| **Revenue dashboard** | Revenue › Revenue Dashboard | KPIs for the next 7 / 30 days: occupancy on the books, forecast, ADR, RevPAR, revenue, and bookings / cancellations made in the last 7 days. A stacked SVG chart per night shows rooms booked, group-block holds, expected pickup and capacity, with Fri/Sat shaded. The forecast table lists lead time, booked, held, occupancy, ADR, revenue, pickup since yesterday and last week, and forecast rooms / revenue. A pickup-by-source panel is included. |
| **Forecast** | (engine) | Forecast = on the books + held + the pickup this hotel normally gets in the remaining lead time. That curve is learned from the last 6 weeks of actual stays and their booking dates, by weekday (all days are pooled until a weekday has 3 samples). It is capped at sellable capacity, which excludes out-of-order rooms. |
| **Snapshots & pickup** | Night audit / `pms:rm-snapshot` | Each night audit records the on-the-books position (rooms and revenue per room type) for the next `rm_horizon_days` (default 90). Pickup compares tonight with yesterday's and last week's snapshot. `--backfill=N` (or *Rebuild pickup history* on the dashboard) rebuilds past days from booking and cancellation times. |
| **Pricing rules** | Revenue › Pricing Rules | Each rule can have a room type (or all), an occupancy band measured on the whole hotel or the room type (inclusive %), a lead time in days, weekdays, and stay dates. The adjustment is ± % or ± amount, optionally with a minimum stay while the rule is active. Matching rules **stack** in priority order. Rules can be paused. |
| **Guard-rails** | Pricing Rules › Floors & ceilings | When a rule has changed the rate, the result is rounded to `rm_round_to` (default 50) and kept between the room type's floor and ceiling. Rate Calendar overrides ticked **Fixed price** (contracts, events) are never changed. |
| **Pricing grid** | Revenue › Pricing Grid | For 7–28 nights, each room type and night shows the live rate, the recommendation (editable), the delta, rooms left and hotel occupancy. Hover a cell for the breakdown: base, each rule and its amount, floor/ceiling, minimum stay. Actions: *Select changes*, *Publish selected*, *Accept all*, and *Reset to base* by range and room type. |
| **Publishing** | (recommend mode) | Published rates become single-day overrides marked `source = rm`. Republishing replaces them; publishing the base rate removes them. Past and fixed dates are skipped. The Rate Calendar badges them **RM** and keeps them out of the manual rules list. |
| **Auto-pilot** | Pricing Rules › Pricing mode | **Off**: forecasts only. **Recommend**: you publish. **Auto-pilot**: the booking engine, front-desk rate API, walk-in / reservation forms, group blocks and iCal pricing all quote the rule-adjusted rate live as occupancy changes. Stale RM overrides are ignored and the grid becomes read-only. |
| **Reports** | Reports › Revenue Management | *Forecast & On the Books* (per night, default next 30 days) and *Booking Pace (Pickup)* (per booking date: bookings, room nights, revenue, average lead and rate, cancellations, net nights), with print and CSV. |

**Settings:** `rm_mode` (off / recommend / auto, default **off**), `rm_round_to` and `rm_horizon_days`. They can be changed on the Pricing Rules page or in Hotel Settings. **Schedule:** a `pms:rm-snapshot` safety net runs at 03:30.

**New permissions** (group *Revenue Management*): **revenue.view** (dashboard, grid, rules, reports) and **revenue.manage** (publish, reset, rules, floors, mode). The Front Office Manager gets both; the Accountant gets view.

**Demo data (NOWILLS):**
- Booking dates are back-dated to realistic lead times; walk-ins are same-day.
- A forward book of about 90 reservations over the next 60 days that builds towards arrival, weekend-heavy, from a realistic source mix. Three were cancelled this week.
- Five rules: *Last-minute low demand* −10%, *Busy 60–79%* +10%, *Compression 80%+* +20%, *Weekend premium* +8%, *Early bird 45+ days* −5%. A paused *Year-end peak* rule adds +1,500 with a 2-night minimum.
- Floors and ceilings: STD 2,200–4,200, DLX 3,400–6,500, EXE 6,200–12,000, PRE 12,000–22,000.
- 14 days of snapshots.
- Recommend mode, with the next 3 nights already published.

Screenshots: `docs/screenshots/p10_dashboard.png`, `p10_pricing.png`, `p10_rules.png`, `p10_autopilot.png`, `p10_report.png`.

> **Upgrading from v1.8:** run `php artisan migrate`, then `php artisan pms:upgrade` to grant the permissions. Then `php artisan pms:rm-snapshot --backfill=14` builds pickup history. Revenue management stays **off** until you choose a mode, so existing rates are unaffected.

### v1.8 — what's new: accounts payable (supplier bills & payments)

Before v1.8, a goods receipt credited *Accounts Payable* in the ledger, but nothing ever settled it. v1.8 adds the supplier side of the books: bills are matched to what was actually received, payments are allocated to bills, and the AP sub-ledger reconciles to control account 2010 every day.

| Feature | Where | How it works |
|---|---|---|
| **Goods bills – three-way match** | Accounts › Supplier Bills › *Enter bill* (or *Match bill* on a received PO) | Pick a goods receipt; the page shows the items received beside the supplier's invoice subtotal and tax, with a live **variance** badge (*Matched / Within tolerance / Will be held*). One bill per receipt. The GRN already credited AP, so a **matched bill posts nothing**; only a price variance posts (Dr/Cr **5045 Purchase Price Variance** / AP). When receiving goods, a tick box records the matching bill in the same step if the invoice agrees with the PO. |
| **Variance hold & approval** | Bill page › *Approve variance* | If the invoice differs from the receipt by more than `ap_tolerance_pct` % (default 1) and more than `ap_tolerance_abs` (default 1.00), the bill is saved **on hold**. On-hold bills are not posted and cannot be paid. Someone with **ap.approve**, other than the person who entered the bill (`ap_segregation`), approves it with a note. |
| **Expense / service bills** | *Enter bill › Expense / service bill* | Utilities, repairs, rent and similar bills, with lines charged to expense or fixed-asset accounts (control, cash and clearing accounts are refused). Each line has its own tax %. The bill posts Dr expense + Dr 1300 Input Tax / Cr AP. If `gl_input_tax` is off, tax is added to the cost instead. The supplier's default expense account pre-fills the first line. |
| **Debit notes** | *Enter bill › Debit note* or from a bill | Returns, short supply and quality claims. The debit note posts Dr AP / Cr the chosen account (+ input tax reversed). Linked to a bill, it is **applied automatically**; otherwise it stays as supplier credit. |
| **Due dates & terms** | Masters › Suppliers | New supplier fields: *Payment terms (days)*, *default expense account* and *bank / mobile-money details* (shown when paying). The due date is the bill date + terms, and can be edited. Duplicate supplier invoice numbers are refused. |
| **Payments & allocation** | Accounts › Supplier Payments › *Pay supplier* | Choose the supplier to list its payable bills, oldest due first, with overdue days. Buttons: *Oldest due first* (auto-allocates the amount), *Pay all* and *Clear*. Live totals block over-allocation. Anything left over is kept as an **advance / supplier credit**. The payment posts Dr AP / Cr the mode's cash or bank account. Bills become *Part-paid → Paid*, and the PO's *paid* amount follows. A **remittance advice** can be printed for the supplier. |
| **Credits, un-apply, cancel** | Bill page / payment page | Apply unapplied payments or debit notes to any open bill, or remove a wrong allocation. **Cancel payment** (bounced cheque; a reason is required) re-opens its bills. **Cancel bill** releases anything applied back as credit. Every cancellation reverses its journal. |
| **Ageing & statements** | Accounts › AP Ageing; supplier statement | Buckets: not due / 1–30 / 31–60 / 61–90 / > 90 days past due, unapplied credits and net balance per supplier, with the oldest overdue days. Goods received but not yet billed are included, aged from the receipt date. The supplier statement lists receipts, bills, variances, debit notes and payments with a running balance, and can be printed. |
| **Ledger & control** | General Ledger › Controls | New sources *Supplier bill* (`ap_inv`) and *Supplier payment* (`ap_pay`). **Documents dated in an already-closed day post immediately** (supplier invoices are nearly always back-dated); the rest post with the night audit. A new strict control row, *Accounts payable (supplier sub-ledger)*, must equal account 2010. |
| **Reports** | Reports › Accounts | *AP Ageing (Suppliers)* and *Supplier Bill Register* (variance, paid, outstanding), with print and CSV. |

**Numbering:** `BILL-00001`, `DN-00001`, `SPY-00001`. **Settings:** `ap_tolerance_pct`, `ap_tolerance_abs` and `ap_segregation`.

**New permissions** (group *Accounts Payable*): **ap.view**, **ap.manage** (enter/cancel bills and debit notes), **ap.pay** (payments and allocation) and **ap.approve** (release held bills). They are granted to the **Accountant** role; administrators have everything.

**Demo data (NOWILLS):**
- A matched FreshFarm bill, paid in full.
- A Metro bill with a +1.9 % variance: approved, a debit note applied, part-paid.
- A CleanPro bill held at +6 %, plus a ₹3,000 advance to CleanPro.
- A Metro receipt not yet billed.
- An overdue electricity bill (25 days past due).
- An HVAC bill 50 days past due, part-paid.

Screenshots: `docs/screenshots/p9_bills.png`, `p9_match.png`, `p9_hold.png`, `p9_expense.png`, `p9_pay.png`, `p9_remittance.png`, `p9_ageing.png`, `p9_statement.png`.

> **Upgrading from v1.7:** run `php artisan migrate`, then `php artisan pms:upgrade`. This grants the AP permissions and adds account 5045. Then set payment terms on your suppliers. Receipts already in the ledger appear as *goods received, not yet billed* until you match their invoices.

### v1.7 — what's new: guest mobile portal

Every stay gets a private 40-character link, `/stay/{token}`. Guests open it from the QR code on the printed welcome card, from an SMS/WhatsApp/e-mail, or from the text sent automatically at check-in. It is a mobile-first page with a bottom tab bar, and it works on any phone browser. The link is the only credential, and it only opens that one stay.

| Feature | Where | How it works |
|---|---|---|
| **Access window** | automatic | *Upcoming* stays see booking details, the online check-in button and "message the front desk". *In-house* guests get everything. After check-out the bill and feedback stay readable for `portal_days_after` days (default 2); after that the link shows *expired*. Portal responses send `Referrer-Policy: no-referrer`, `X-Robots-Tag: noindex` and `Cache-Control: no-store`. Rate limits are **per stay token**, because guests share the hotel Wi-Fi's public IP, with a per-IP ceiling on top. Staff can **regenerate** a link (lost card / wrong guest); the old one stops working at once. |
| **Service requests** | Guest › Requests; Front Office › **Guest Requests** | Categories: housekeeping, amenities (list configurable), *something is not working*, wake-up call, transport, late check-out, express check-out and a free-text message. Each request gets a number (`GRQ-00001`). Guests can have up to 10 open requests; duplicate open requests are refused. **Maintenance** issues open a linked **maintenance ticket** (category and priority mapped; hot water, leaks, locks and power are high priority). *Clean my room / turn-down / linen change* escalate today's **housekeeping task** or create one. When the ticket is resolved or the task is completed, the guest's request is marked *done* automatically. Staff can acknowledge, start, mark done (with a note the guest sees), decline (a reason is required) and assign; assigning also acknowledges. Guests can cancel a request while it is *new*, and rate it with 1–5 ★ once done. At check-out, express/late check-out requests are fulfilled and any other open requests are closed. |
| **Queue & SLA** | Front Office › Guest Requests | Counters for new, overdue (unanswered for longer than `portal_sla_minutes`, default 15), in progress and done today (with average response time and ★). Overdue rows are highlighted. You can filter by status, type, room or *assigned to me*, and log phone/desk requests for an in-house guest. The page checks every 30 s and refreshes when a new request arrives, and the menu shows a badge with the number of new requests. |
| **In-room dining** | Guest › Dining | Shows the menu of the outlet set in `portal_dining_outlet_id` (seeded: *In-Room Dining*), with a cart and per-line notes. It creates a `room_service` POS order (`source = portal`) linked to the stay. The lines arrive **pending**, with no KOT and no charge, exactly like QR orders: the outlet sees *New guest orders (QR / portal)* and a *Guest portal · room N* badge. Staff **Accept & send KOT**, then settle by posting to the room. Further orders are added to the open ticket, and the guest sees *Awaiting confirmation → Preparing*. |
| **Spa self-booking** | Guest › Spa | The guest picks a service, a day during the stay and a free time. For spa and salon services, the slot finder picks a free **therapist and treatment room**; fitness, tours and activities need neither. Booking runs under a lock and re-checks the clash rules, so two guests can never get the same therapist. Each stay can hold up to 3 portal bookings, and a guest can cancel one online up to 2 h before it starts. Bookings appear on the spa calendar and are charged to the room through the normal settlement. |
| **Live bill & express check-out** | Guest › My bill | Every folio of the stay, excluding voided lines, with charges, tax, discounts, payments and balance. *Request express check-out* (optionally "e-mail my invoice") creates a high-priority request for the desk. |
| **Feedback** | Guest › My bill | Overall, room, service, food and cleanliness ★ plus comments, saved to Guest Feedback (`source = portal`). There is one entry per stay; submitting again updates it. |
| **Link management** | Reservation › QR icon; In-House list › QR icon | QR code, copy / open link, **send by SMS, WhatsApp or e-mail** (new *portal_link* e-mail and *welcome* SMS template with `{portal_link}` and `{room}`), a **printable welcome card** (QR, Wi-Fi, check-out time), regenerate link, "guest last opened the portal …" and this stay's requests. |
| **Report** | Reports › Front Office | *Guest Requests & Response Times*: per request type, the number raised, via portal, done, declined and still open, average response (raised → acknowledged) and completion (raised → done) in minutes, SLA breaches and average ★. |

**Settings** (Administration › Hotel Settings): `portal_enabled`, `portal_auto_welcome` (text the link at check-in; needs SMS on), `portal_spa_enabled`, `portal_dining_outlet_id`, `portal_sla_minutes`, `portal_days_after`, `portal_wifi_name` / `portal_wifi_password`, `portal_amenities` (one per line) and `portal_info` (hotel information; `Label: value` lines show as rows). The SMS text can be overridden with the `sms_tpl_welcome` setting.

New permissions: **requests.view** (queue) and **requests.manage** (handle requests, log phone requests, manage/send/regenerate portal links). They are granted to the **Front Office Manager**, **Receptionist** and **Housekeeping Supervisor** roles; administrators have everything.

Screenshots: `docs/screenshots/p8_guest_home.png`, `p8_guest_requests.png`, `p8_guest_dining.png`, `p8_guest_spa.png`, `p8_guest_bill.png`, `p8_request_queue.png`, `p8_staff_portal_link.png`, `p8_report.png`.

> **Upgrading from v1.6:** run `php artisan migrate`, then `php artisan pms:upgrade` to grant the request permissions. Set the dining outlet, Wi-Fi and hotel information under Hotel Settings. Links are created at check-in; for guests who are already in house, a link is created the first time you open their QR page. Set `APP_URL` to your public HTTPS address so that links generated outside a web request carry the correct host.

### v1.6 — what's new: double-entry general ledger

The operational modules remain the books of original entry. The ledger is **derived** from them, so nobody has to key anything twice, and it is **immutable**: nothing posted is ever edited or deleted.

| Feature | Where | How it works |
|---|---|---|
| **Chart of accounts** | Accounts › Chart of Accounts | A numbered, USALI-style chart: 1xxx assets, 2xxx liabilities, 3xxx equity, 4xxx income, 5xxx–6xxx expenses, each with a statement group. About 30 **system accounts** are created for every property (cash, bank, card and UPI/mobile-money clearing, guest ledger, city ledger, inventory, input tax, suspense, accounts payable, output tax, service charge, capital, retained earnings, opening balance equity, one revenue account per department, discounts, round-off, cost of F&B / guest supplies / general stores, stock variance, loyalty, commission). Existing account heads are adopted by name and numbered. System accounts can be renamed or re-coded, but not deleted, deactivated or re-typed. The old *City Ledger Receipts* income head is merged into *City Ledger Receivable*, because a company paying its bill settles a receivable; it is not income. |
| **Automatic posting of closed days** | General Ledger › *Sync now*; night audit; `pms:gl-sync` | Only **closed business days** (up to business date − 1) are posted. The night audit posts the day it has just closed, and the scheduler re-checks daily at 03:15. Rules: **folio charge** Dr Guest Ledger / Cr revenue (per category mapping) + Cr Output Tax. A room-posted POS bill is split into F&B revenue, service charge and round-off. **Payment** Dr the payment mode's account / Cr Guest Ledger. **Refund** the reverse. **Transfer to company** Dr City Ledger / Cr Guest Ledger. **Discount / allowance** Dr Discounts (loyalty redemption → Loyalty) / Cr Guest Ledger. **POS bill settled at the outlet** Dr mode / Cr F&B revenue, service charge, tax, round-off. Complimentary bills post nothing. **Voucher** payment Dr head / Cr mode; receipt the reverse (company receipts → City Ledger). **Goods receipt** Dr Inventory + Input Tax / Cr Accounts Payable. **Stores**, one journal per day: issues and recipe consumption Dr cost of F&B (kitchen/bar/minibar), guest supplies (housekeeping store) or general stores / Cr Inventory; wastage and stock-take adjustments → Stock Variance; opening stock → Opening Balance Equity. |
| **Corrections without rewriting history** | automatic | Each source is fingerprinted. If a posted transaction is voided, its amount changes or a voucher is deleted, the next sync **reverses** the original journal and posts the corrected one, both dated on the latest closed day (never before the lock date). Imbalances up to 1.00 go to Round-off. Anything larger is reported on the GL page and retried on every sync; it is never posted half-way. A sync is serialised per property and is safe to repeat. |
| **Trial balance & controls** | Accounts › General Ledger | Opening / debit / credit / closing for every account, grouped by type, with drill-down to the **account ledger** (running balance, Dr/Cr, link to each journal and its source document). **Control reconciliation:** Guest Ledger vs the open folio balances and City Ledger vs company receivables must agree to the cent. Inventory vs stores valuation is shown as indicative. |
| **Manual journals** | General Ledger › Manual Journal; Journal Entries | Multi-line JV form with live Dr/Cr totals: *Post* stays disabled until the journal balances. Each line is either a debit or a credit; blank rows are ignored. The date must be after the lock date and not later than the business date. Posted journals can be **copied** or **reversed** (the reason is logged) but never edited. Automatic journals cannot be reversed by hand: fix the source document instead. Journal numbers use their own series (`JV-00001`). |
| **Period lock** | General Ledger › Period close | Lock the books up to a closed date. Later changes to that period post on the first open day, and manual journals can't be dated inside it. Moving the lock back needs a reason. Every lock and unlock is in the activity log. |
| **GL mapping** | Accounts › GL Mapping | Revenue account per folio charge category, revenue account for outlet POS bills, cash/bank/clearing account per payment mode (also on the Payment Modes master), and whether input tax is claimable (otherwise it is added to inventory cost). *Re-check all open periods* re-posts everything after the lock date under the new mapping. |
| **Financial statements** | Reports › General Ledger | **Trial Balance**, **Profit & Loss (GL)** by account group, **Balance Sheet** (current-period earnings shown under equity, with a *Difference* figure that must be zero) and **Journal Register / Day Book**. All of them print and export to CSV. |

New permissions: **gl.view** (ledger and statements), **gl.journal** (manual journals and reversals) and **gl.manage** (chart of accounts, mapping, sync, period lock). The **Accountant** role gets all three; administrators have everything. The single-sided *journal* voucher type has been retired in favour of real journals.

Screenshots: `docs/screenshots/p7_gl_dashboard.png`, `p7_account_ledger.png`, `p7_manual_journal.png`, `p7_journal.png`, `p7_journals.png`, `p7_balance_sheet.png`, `p7_gl_pnl.png`, `p7_gl_mapping.png`, `p7_chart_of_accounts.png`.

> **Upgrading from v1.5:** run `php artisan migrate`, then **`php artisan pms:upgrade`** (creates the chart of accounts, maps payment modes and grants the GL permissions), then **`php artisan pms:gl-sync --full`** to post history up to yesterday. Post your opening balances (bank, cash, fixed assets, capital) as a manual journal dated before your first transaction, and lock any period you have already reported.

### v1.5 — what's new

| Feature | Where | How it works |
|---|---|---|
| **Mobile housekeeping ("My rooms")** | Housekeeping › My Rooms (mobile) | A phone-first screen for room attendants. Link the attendant's login to their employee record (**HR › Employees › Login user**) and they see today's rooms with a big room number, task type, credits, occupancy and guest, plus **Start / DND / Done**. *Done* opens the checklist (Hotel Settings › *Room-attendant checklist*); every item must be ticked unless that rule is switched off. From each room the attendant can **post minibar**, **report an issue** (creates a maintenance ticket, optionally blocking a vacant room) or **log a found item** (Lost & Found). Attendants can act only on their own rooms. Supervisors can view any attendant. |
| **Credit-based auto-assign** | HK Tasks & Inspection › *Generate & auto-assign* | Creates one task per dirty, touch-up or occupied room (daily stay-over service can be switched off). Departures and vacant-dirty rooms get *departure* credits (default 2), stay-overs 1 and deep cleans 3. Rooms are handed out in floor/room order to the attendant with the lowest credit load, keeping attendants on the floor they are already working when loads are close. Re-running it adds only new rooms. At check-out, today's stay-over task becomes a departure clean. |
| **Supervisor inspection** | HK Tasks & Inspection, or the mobile screen | With *cleaned rooms wait for inspection* on (default), a finished room becomes **Clean** and joins the *Waiting for inspection* queue. **Pass** → **Inspected**. **Fail** (a reason is required) → the room goes back to **Dirty** and the task reopens as high priority for the same attendant with the supervisor's note, and the re-clean counter goes up. With inspection off, finished rooms go straight to Inspected. A workload table shows rooms, credits, done, in progress, DND and re-cleans per attendant. |
| **Minibar** | Housekeeping › Minibar Items; *Minibar* on the mobile card | A price list of items. Quantities are posted to the in-house guest's folio as taxable **Minibar** charges (one line per item, linked to the task). Vacant rooms are refused. An item can be linked to an inventory item, in which case stock is issued from the store chosen in Hotel Settings. The *Minibar Sales* report lists every posting. |
| **Spa & activities** | Spa & Activities › Appointments / Services & Activities / Treatment Rooms | A day calendar with one column per therapist (employees in the *Spa & Wellness* department) plus a column for activities without a therapist, in 30-minute slots between opening and closing time. **Click a free slot to book.** Services have a category (spa, salon, fitness, activity, tour), duration and price per person. The end time comes from the duration. **Clash check:** the same therapist or the same treatment room cannot overlap; cancelled and no-show bookings free the slot. Bookings can be for an in-house guest or a walk-in, with pax, price override and discount. Flow: Booked → In progress → **Complete & settle**: *post to room* (in-house folio, category Spa), *direct payment* (a non-resident folio is charged, paid and closed, so it appears in collections, invoices and shift totals) or *complimentary*. Cancel (reason required) and no-show are also available. A *Spa & Activity Revenue* report and the guest's spa history are included. |
| **SMS & WhatsApp** | Administration › SMS / WhatsApp Settings / Log; Reservation › *SMS / WhatsApp* | Providers: **Log only** (default, nothing leaves the server), **Twilio** (SMS + WhatsApp), **Hubtel** (Ghana SMS) and a **generic HTTP/JSON gateway** (bearer token, SSRF-guarded). Numbers are normalised to E.164 with the default country code (`024 412 3456` → `+233244123456`). Secrets are stored **encrypted** with the app key; a blank field keeps the saved value, and secrets are never echoed back. There are templates for *confirmation*, *pre-arrival (with the online check-in link)* and *thank-you*, using placeholders `{guest} {hotel} {res_no} {arrival} {departure} {checkin_link} {amount}`. **Automatic:** confirmation when a reservation is made (desk or booking engine), thank-you after the last room checks out, and a pre-arrival message N days ahead via the scheduler (`pms:pre-arrival`, daily 10:00, **once per reservation**). Manual sends (template or custom text) come from the reservation. Every attempt, sent or failed with its reason, is kept in the message log and shown on the guest profile. Sending never breaks a booking or check-out. |

v1.5 settings: in **Hotel Settings**: *Cleaned rooms wait for inspection* (on), *Attendants must tick every checklist item* (on), *Daily service for occupied rooms* (on), HK credits for departure/stay-over/deep clean (2/1/3), *Minibar stock store*, spa opening/closing time and slot length, and the *room-attendant checklist*. In **SMS / WhatsApp Settings**: provider, default channel, sender ID, country code, provider credentials, automatic messages, pre-arrival days and templates, plus a *Send test* box.

New roles: **Room Attendant** (My rooms only) and **Spa Desk / Therapist**. Receptionists can book spa appointments and message guests; front-office managers can view spa bookings and message guests.

Screenshots: `docs/screenshots/p6_hk_mobile.png`, `p6_hk_checklist.png`, `p6_hk_tasks.png`, `p6_hk_productivity.png`, `p6_spa_calendar.png`, `p6_spa_booking.png`, `p6_spa_report.png`, `p6_messaging_settings.png`, `p6_message_log.png`.

> **Upgrading from v1.4:** run `php artisan migrate` and then **`php artisan pms:upgrade`**. That command adds the *My rooms*, *Spa* and *Send SMS/WhatsApp* permissions, the two new roles and a *Spa & Wellness* department to every property. Then link attendants' logins to their employee records. For real SMS/WhatsApp, pick a provider in SMS / WhatsApp Settings; the scheduler cron from the installation section also sends the pre-arrival messages.

### v1.4 — what's new

| Feature | Where | How it works |
|---|---|---|
| **Kitchen display (KDS)** | POS › Kitchen Display | A live board that refreshes every 5 s, with **New / Preparing / Ready** columns. There is one ticket per KOT, showing table/room, steward, covers and elapsed minutes; tickets turn amber at 10 min and red at 20. Filter by outlet and by **station**: each menu category has a *Kitchen station* (Bar, Pastry, Tandoor…, blank = Kitchen), so every section sees only its own items. Tap an item to advance it, or use the ticket button to advance all of them. *Undo* steps back. Items cancelled after the kitchen has them stay on the ticket, struck through. Optional beep, full-screen mode, and a new **Kitchen / Chef** role that sees only the KDS. The POS terminal shows each line's kitchen status and has a one-tap *✓ served*. |
| **POS split / move / merge** | POS terminal › *Table*, *Merge*, *Split bill* | **Split:** move whole lines or part quantities (e.g. 1 of 4 teas) to a new bill on the same table, settled separately. **Move:** move the order to a free table in the same outlet; occupied tables are refused, so you merge instead. **Merge:** bring another running order's items, KOTs and covers into this bill; the source closes as *merged* with a link. Money is never created or lost. Pending QR items must be accepted first, and a printed bill is reopened after any change. |
| **Online (pre-arrival) check-in** | Link in the confirmation e-mail, or Reservation › *Online check-in* (copy / e-mail) | A public page with a random 40-character token, mobile-friendly. The guest confirms their details, ETA and requests, uploads ID front/back (private storage, same checks as the e-GRC) and signs the registration card. It is saved on every reserved room of the booking. The reservation, arrivals list and check-in screen then show **Pre-checked-in**. The link closes automatically once the booking is cancelled, checked in or past. |
| **Cancellation & no-show fees** | Settings + Cancel / No-show dialogs | Policy: free until *N* hours before check-in time, then **first night / % of stay / fixed per room**. The no-show policy is set separately. The dialog previews the exact fee, per room too. The fee posts as a *Cancellation / No-show Fee* charge on the reservation's advance folio, so any advance is netted and the rest is collected or refunded there. A fee can be **waived with a reason** (logged). **The night audit posts the no-show fee automatically.** Tentative bookings are never charged. |
| **Travel-agent commissions** | Accounts › Agent Commissions | Set *Commission %* on the company/agent master. At **check-out**, commission accrues once per stay on the room revenue actually charged (tariff + extra person, net of voids; F&B and extras excluded). Dues are listed per agent. **Settle** selects an agent's stays and creates one payment voucher (head *Agent Commission*). Deleting that voucher puts the stays back to *due*. You can also void a commission with a reason. |
| **Stock take & re-order** | Inventory › Stock Take / Re-order Suggestions | Creating a count sheet per store (optionally one category) **freezes system quantities**. Print a *blind count sheet*, enter counts (live variance and value), save as you go, then **post**. Variances become *adjustment* movements referencing the sheet, and receipts made during the count stay correct. Uncounted lines are ignored unless *treat blank as zero* is ticked. You get a variance report, and one open draft per store at a time. **Re-order:** items at or below re-order level (all stores) get a suggested quantity (2× level − stock − already on order) and default to the last supplier. Tick items and it creates **one draft PO per supplier**. |

v1.4 settings: **Online pre-arrival check-in** (on), **Free cancellation until N hours before check-in** (48), **Late cancellation charge** + value, **No-show charge** + value, **Night audit posts the no-show charge automatically** (on).

Screenshots: `docs/screenshots/p5_kds.png`, `p5_split.png`, `p5_merge.png`, `p5_online_checkin.png`, `p5_online_checkin_done.png`, `p5_res_precheck.png`, `p5_cancel_fee.png`, `p5_commissions.png`, `p5_stocktake.png`, `p5_reorder.png`, `p5_settings.png`.

> **Upgrading from v1.3:** run `php artisan migrate` and then **`php artisan pms:upgrade`**. The second command registers the new *Kitchen display* permission and the *Kitchen / Chef* role for every property without touching your customised roles. Timestamps written by console tasks (seeders, scheduled night audit, iCal sync) now use the property's timezone, matching web requests.

### v1.3 — what's new

| Feature | Where | How it works |
|---|---|---|
| **Fixed charges / packages** | Folio › *Fixed charges / packages* | Breakfast, parking, extra bed or pickup, charged **per night** or **once**, with tax by category. They post with each night's tariff (night audit, late check-out, day-use), never twice for the same night. Voiding a posting counts as *waived* and it is not re-posted. A one-off still pending at checkout is posted then. *Stop* ends future postings. |
| **Group room blocks (allotments)** | Reservations › Group Blocks | Hold N rooms per type for a wedding, conference or tour. Held rooms are taken out of sellable inventory everywhere: front desk, booking engine and OTA iCal feeds. Overbooking is refused. *Rooming-list pickup* pastes names (one per line) and creates confirmed group reservations at the block rate, all-or-nothing. A picked/held grid shows progress. **The night audit releases unpicked rooms on the cut-off date.** You can also mark a block definite, release it early or cancel it. |
| **e-GRC** | Reservation › ✎ icon, or Room View › *e-GRC sign / ID* | The guest signs on a tablet or touch screen (canvas → PNG, validated server-side) and accepts the terms. The printed GRC shows the signature. ID/passport/visa scans (JPG/PNG/WebP/PDF, max 5 MB, phone camera capture) are stored in **private storage** (`storage/app/private`) under random names. Only logged-in staff of the same hotel can view them, every view is written to the activity log, and they appear on the guest profile. |
| **Flash report** | Reports › Management | Rooms, occupancy, ADR, RevPAR, room/F&B/other revenue, tax, collections, arrivals, departures, no-shows and cancellations for **Day / MTD / YTD**, computed from posted transactions. Optionally **e-mailed after every night audit** (setting below). |
| **AR ageing** | Reports › Accounts | Company balances in 0–30 / 31–60 / 61–90 / 90+ day buckets. Receipts are applied FIFO to the oldest bills. Shows on-account credit and flags *over credit limit* and *90+ overdue*. |
| **Two-factor authentication** | My Profile › Two-factor | TOTP (RFC 6238) works with Google/Microsoft Authenticator, Authy and 1Password, with no external service. QR enrolment gives 8 single-use recovery codes. The secret is encrypted at rest, codes cannot be replayed, and 5 bad codes lock the account for 15 min. Administrators can reset another user's 2FA (Users › Edit). The optional policy forces administrators to enrol. |

v1.3 settings: **Administrators must use two-factor authentication** (off) and **E-mail the daily flash report to** (comma-separated addresses, max 10).

Screenshots: `docs/screenshots/p4_block.png`, `p4_folio.png`, `p4_grc.png`, `p4_flash.png`, `p4_ar.png`, `p4_2fa.png`, `p4_challenge.png`.

> **Upgrading from v1.2:** run `php artisan migrate`. Guest scans live in `storage/app/private`, so include that folder in your file backups. It is never web-served.

### v1.2 settings (Administration › Hotel Settings)

| Setting | Default | Meaning |
|---|---|---|
| E-mail booking confirmations automatically | on | Sent on reservation create/cancel and online bookings when the guest has an e-mail |
| Target food-cost % | 35 | Recipe list shows items above target in red |
| Cashiers must open a shift | off | When on, folio payments and POS cash/card settlements need an open shift; manage the drawer in **Finance › Cashiering** |

Per outlet: **Masters › POS Outlets › "Consumes stock from"** chooses the store that recipes deduct from. If it is empty, nothing is deducted.

**E-mail:** set `MAIL_MAILER=smtp`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS` in `.env`. The hotel name is used as the sender name and the hotel e-mail as the reply-to address.

**Channel manager:** create a channel, copy its export URL into the OTA extranet (*import calendar*), and paste the OTA's *export calendar* link into the channel. Imported bookings arrive as confirmed reservations under the channel's business source. Assign rooms as usual.

### v1.1 settings (Administration › Hotel Settings)

| Setting | Default | Meaning |
|---|---|---|
| QR table menu | on | Enables `/menu/{token}` for every table |
| Loyalty programme | off (demo: on) | Earn points at settlement |
| Net spend per 1 point | 100 | Pre-tax charges minus discounts |
| Value of 1 point | 1 | Currency value when redeemed |
| Minimum points per redemption | 100 | |

## Architecture

* **Multi-tenancy:** every business table has `hotel_id`. A global scope (`BelongsToHotel`) filters every query and sets `hotel_id` on insert. A tenant-aware `owned:table` validation rule stops a user from submitting another hotel's IDs. There are feature tests for this.
* **Business date:** all postings use the hotel's business date, which only moves forward through the night audit.
* **Financial integrity:** folio transactions are voided, never deleted. Closed folios cannot be changed unless they are reopened (and the reopen is logged). Room tariff is posted once per night, and any missed nights are caught up at check-out.
* **Services:** `FolioService`, `StayService`, `AvailabilityService`, `NightAuditService`, `TaxService`, `BackupService`, `HotelProvisioner`.

---

## Installation

### Requirements
PHP ≥ 8.3 with the `pdo_mysql`, `mbstring`, `openssl`, `tokenizer`, `xml`, `ctype`, `fileinfo` and `gd` extensions; MySQL ≥ 5.7 or MariaDB ≥ 10.3; Composer 2.

> **On cPanel / shared hosting, with no SSH and no Composer?** Use the browser installer instead of the
> commands below — see **[docs/INSTALL-CPANEL.md](docs/INSTALL-CPANEL.md)**. Upload the project, create a
> database, then open `/install.php`: it checks the host, writes `.env`, generates the key, runs the
> migrations and creates your first property and administrator. Delete `public/install.php` afterwards.

```bash
composer install --no-dev --optimize-autoloader
cp .env.example .env
php artisan key:generate
# edit .env → DB_DATABASE / DB_USERNAME / DB_PASSWORD, APP_URL, APP_ENV=production, APP_DEBUG=false
php artisan migrate --force
php artisan db:seed --force          # optional: demo data (2 hotels, 1 group)
php artisan config:cache && php artisan route:cache && php artisan view:cache
chmod -R 775 storage bootstrap/cache
mkdir -p public/uploads/logos && chmod 775 public/uploads/logos
```

Composer installs its packages into **`build/vendor`**, not `vendor/`; this is set by `config.vendor-dir` in `composer.json`. `artisan`, `public/index.php` and `tests/bootstrap.php` all load `build/vendor/autoload.php`, and PHPUnit is run from `build/vendor/bin/phpunit`. There is no `vendor/` directory in this project. Deploy the `build/vendor` directory, or run `composer install` on the server.

### Web server
* **Apache / cPanel:** point the document root to `public/` (see `deploy/apache-vhost.conf`).
  If you cannot change the document root, upload the whole project; the root `.htaccess` sends requests into `public/` and blocks sensitive paths — including `bootstrap/`, which holds the compiled config with your `APP_KEY` and database password.
* **Nginx:** see `deploy/nginx.conf`.
* **No Composer on the server?** The packages are already committed in `build/vendor`, so there is nothing to install: upload the `build/` folder with the project. (Alternatively run `composer install` on your PC and upload the result.)

### Cron (backups)
Add one cron entry (`deploy/crontab.txt`):
```
* * * * * cd /path/to/nowills-pms && php artisan schedule:run >> /dev/null 2>&1
```
This runs `pms:backup` every night at 02:30 (keeping 14 days), `pms:ical-sync` every 15 minutes, `pms:pre-arrival` at 10:00 and a `pms:gl-sync` safety net at 03:15 (the night audit already posts each closed day) and a `pms:rm-snapshot` safety net at 03:30, plus `pms:foreign-run` hourly (Form C due / overdue alerts) and `pms:frontdesk-run` every 5 minutes (late / missed wake-up calls). Backups are written in pure PHP, so `mysqldump` is not needed. You can also run it by hand:
```bash
php artisan pms:feedback-run        # v3.1: send due surveys / reminders, expire links, escalate overdue guest cases
php artisan pms:tables-run          # v3.2: table-booking reminders, automatic no-shows, close yesterday's waitlist (every 5 min)
php artisan pms:transport-run       # v3.3: driver reminders, close unconfirmed transport requests (every 5 min)
php artisan pms:foreign-run         # v3.4: Form C (FRRO) due / overdue e-mails, forex surrender check (hourly)
php artisan pms:frontdesk-run       # v3.5: escalate wake-up calls not made on time, close missed ones (every 5 min)
php artisan pms:interfaces          # v3.7: expire key cards, re-send failed PABX commands, re-run the call review queue (every 5 min)
php artisan pms:engineering         # v3.9: licence & inspection reminders – compliance digest e-mail + log book (daily 07:00)
php artisan pms:webhooks            # v4.0: deliver due webhook events with retry back-off (every minute); --prune: log retention (daily 03:45)
php artisan pms:upsell              # v4.1: e-mail pre-arrival offers to arrivals inside the window (once each) and decline stale early/late requests (hourly at :20)
php artisan pms:security            # v4.2: overstay alerts, visitor no-shows, overdue gate passes and visitor-data retention (every 15 minutes)
php artisan pms:vouchers            # v3.8: expire gift vouchers (breakage) and e-mail expiry reminders (daily 06:30)
php artisan pms:backup              # full database (schema + data)
php artisan pms:backup --hotel=1    # one property's data only
php artisan pms:ical-sync           # pull all OTA calendars now (--hotel=NOWILLS for one)
php artisan pms:gl-sync --full      # post / re-check every closed day in the general ledger
php artisan pms:rm-snapshot --backfill=14   # revenue management: record / rebuild on-the-books history
```

### Starting fresh without demo data
Run `migrate` without `db:seed`, then create your first property with `php artisan tinker`:
```php
$h = App\Models\Hotel::create(['code'=>'MYHOTEL','name'=>'My Hotel','business_date'=>now()->toDateString()]);
App\Services\HotelProvisioner::provision($h);   // roles, taxes, payment modes, sources, account heads
$u = new App\Models\User(['name'=>'Admin','username'=>'admin','password'=>'ChangeMe123']);
$u->hotel_id = $h->id; $u->is_super_admin = true; $u->save();
```

## Demo logins (after seeding)

| Hotel ID | Username | Password | Notes |
|---|---|---|---|
| `NOWILLS` | `admin` | `Admin@123` | Hotel administrator |
| `NOWILLS` | `frontdesk`, `fom`, `housekeeping`, `cashier`, `accounts`, `store`, `chef`, `attendant`, `spa`, `hr`, `laundry`, `engineer`, `banquet`, `marketing`, `restaurant`, `concierge`, `driver` | `Pass@123` | Role-restricted staff (`chef` = Kitchen Display only; `attendant` = Ramesh Gowda's *My rooms* phone screen + self-service; `spa` = spa desk; `hr` = Anjali Menon, HR Manager; `laundry` = Kwame Boateng, Laundry Attendant; `engineer` = Joseph Thomas, Chief Engineer; `banquet` = Farah Khan, Banquet Manager; `marketing` = Efua Asante, Sales & Marketing – drafts rate contracts; `fom` approves them; `accounts` runs credit control) |
| `NWRESORT` | `admin` | `Admin@123` | Second property |
| `NOWILLS` (at `/partner/login`) | `travel.desk@infosys.com`, `rahul.iyer@infosys.com`, `accounts.payable@infosys.com`, `karan.shah@thomascook.in`, `bookings@yatra.com` | `Partner2026` | Corporate / travel-agent partner portal (v7.4) |
| `NOWILLS` (at `/organiser/login`) | `ama.serwaa@saharaevents.com`, `yaw.osei@saharaevents.com`, `efua.adu@saharaevents.com`, `akosua.mensah@example.com` | `Organiser2026` | Event organiser portal (v7.5) — Sahara Events and the Mensah–Owusu wedding |
| `NWGROUP` (tick **Group Login**) | `groupadmin` | `Admin@123` | Can switch between both properties |

Public pages: `/stay/{token}` (guest mobile portal; open **Front Office › In-House Guests**, click the QR icon, then *Open as guest*), `/book/NOWILLS` (booking engine), `/dine/NOWILLS` (restaurant table booking), `/feedback/NOWILLS` (guest feedback), `/survey/{token}` (post-stay survey), `/menu/{token}` (QR table menu; open **POS › Table QR Codes** and click *Open* on any table), `/ical/{token}.ics` (OTA feed; copy it from **Reservations › Channel Manager**), `/checkin/{token}` (online check-in; copy it from any upcoming confirmed reservation).

v1.7 demo data: the portal is enabled, with Wi-Fi, hotel information and the *In-Room Dining* outlet set up, and every current stay has a link. The request queue holds new (one overdue), acknowledged and in-progress requests, including an AC fault with a linked maintenance ticket and a *clean my room* request with a linked HK task. There are also completed requests (one rated ★★★★★), a pending portal room-service order on the POS and a portal feedback entry. Log in as `frontdesk` and open **Front Office › Guest Requests**.

v1.6 demo data: a fully posted ledger for both properties up to yesterday: opening balances (bank, cash, furniture & equipment against owner's capital), this month's depreciation journal, and every folio, POS, voucher and stores transaction. The trial balance balances, the guest and city ledger controls reconcile, and the balance sheet difference is zero. Log in as `accounts` to see the accountant's view.

v1.5 demo data: today's housekeeping tasks already credit-balanced across three attendants. Some rooms are done and waiting for inspection, one has passed, one failed inspection and is back with the attendant, one is in progress and one is DND. There is a minibar posting on an occupied room; 10 spa/salon/fitness/tour services, 4 treatment rooms and 3 therapists; and today's spa calendar with completed (room-posted and paid), in-progress, booked, cancelled and no-show appointments, plus bookings for the next two days. Messaging is on with the *log* provider, and the message log has a sent/failed history.

v1.4 demo data: two running tables with KOTs across the Kitchen, Bar and Pastry stations (one 14 minutes old), 10 %/7 % commission on the two travel agents with 5 accrued stays (one agent already paid), a draft kitchen stock take with some counts entered, one pre-checked-in arrival, and a first-night late-cancellation/no-show policy.

v1.2 demo data: `cashier` has an open shift (float 3,000) and `frontdesk` has a closed shift that is 50 short. There are recipes on 9 dishes, all outlets draw from the Kitchen store, two iCal-imported Airbnb bookings, and one logged confirmation e-mail.

## Tests
Feature tests use a separate MySQL database (`nowills_pms_test`, configured in `phpunit.xml`). Run PHPUnit
from the committed vendor directory — Laravel 13 has no `php artisan test` command, and this project has no
`vendor/` directory:
```bash
php build/vendor/bin/phpunit                        # whole suite
php build/vendor/bin/phpunit --filter Phase2Test    # one class
php build/vendor/bin/phpunit --testsuite Unit
```
`.github/workflows/tests.yml` runs the whole suite against a MySQL 8 service on every push and pull request.
**Verified full-suite run for the current application code:** all 651 tests passed with 0 failures, errors, or skips in the complete six-shard MySQL run [37752732099](https://github.com/nowillsgroup/NowillsPMS-ERP/actions/runs/37752732099) on commit `d857feb`. The earlier 14-failure baseline and a follow-up reference-clock cursor regression have been resolved; see [`docs/TEST-STATUS.md`](docs/TEST-STATUS.md) for shard totals and the fix inventory.
The tests open every page as an administrator, including all 259 reports and all master screens. They also run full workflows: reservation → check-in → charges → check-out/invoice; walk-in → POS room-post → night audit; tenant isolation; permissions; login lockout and group login; inventory guards and goods receipt; master CRUD with delete protection; booking engine; CSV formula-injection protection; backups. `Phase2Test` (v1.1) covers rate resolution, the calendar, booking-engine stop-sell and min-stay, the QR pending → accept/reject flow with token regeneration and tenant isolation, tape-chart assign/move/conflict/permission, loyalty earn/redeem/void-refund with no double-earning, and group dashboard access. `Phase3Test` (v1.2) covers folio split/routing (void, closed-target, cross-tenant and permission guards), cashier-shift totals/variance/snapshot/enforcement/visibility, recipe consumption on settlement (idempotent, counted on the shift), e-mail send/fail logging and auto-confirmation, iCal export of sold-out and stop-sell ranges (stateless, no loops), and iCal import create/idempotent/update/cancel/conflict/SSRF/garbage handling. `Phase4Test` (v1.3) covers fixed-charge posting (idempotent, void = waived, once vs daily, stop, one-off at checkout), room-block holds, overbooking refusal, all-or-nothing pickup, cut-off release, status and tenant/permission guards, e-GRC signature validation, private-document upload (PHP-upload rejection, tenant isolation, permissions, deletion), FIFO AR ageing, flash-report figures against source data plus the post-audit e-mail, and TOTP (RFC vector, enrolment, login challenge, replay protection, recovery codes, lockout, admin enforcement and reset). `Phase5Test` (v1.4) covers the KDS feed, station filter, bump/undo/served and role permissions; split (part quantities, totals conserved), move (busy-table refusal) and merge (bill reopens, merged orders excluded from the audit); online check-in (token guard, PHP-upload rejection, guest/ID/signature saved, link e-mail, link closed after cancellation); cancellation-fee free window, first-night fee netted against the advance, waiver with reason, and the night-audit no-show fee (tentative exempt); commission accrual (room revenue only, net of voids, once per stay), voucher payment, double-pay refusal and reversal on voucher delete; and stock take (frozen snapshot, one draft per store, blind sheet, variance posting, lock after posting) plus re-order → one draft PO per supplier. `Phase6Test` (v1.5) covers credit-balanced assignment (one task per room, balanced loads, idempotent), the attendant cycle (own rooms only, checklist enforcement, clean → fail with reason → re-clean → pass → inspected, DND, inspection switched off), screen access rules, the hotel-scoped and unique employee↔login link, minibar folio posting with stock deduction and vacant-room refusal, issue/found reports, the check-out hook (departure clean + thank-you SMS), spa clash rules (therapist, room, back-to-back, cancelled frees the slot, reschedule), room-post / walk-in payment (closed zero-balance folio) / complimentary settlement and permissions, number normalisation, Twilio/Hubtel requests (faked HTTP), failures that never throw (gateway error, private URL, switched off), encrypted secrets (blank keeps, clear removes, never echoed), auto-confirmation, custom placeholders and pre-arrival sent only once. `Phase7Test` (v1.6) covers the chart of accounts (complete, idempotent, legacy head merged, payment modes mapped, per property), system-account protection, posting rules for charges/tax, payments, city-ledger transfers, outlet and room-posted POS bills (service charge and round-off split), the rule that the open day is never posted and the night audit posts the closed day, void and amendment → reversal + re-post on the latest closed day, deleted vouchers, period lock (corrections move forward, manual JVs refused, moving back needs a reason), goods receipt and stores postings, mapping change + full re-check, manual journal validation (unbalanced, both sides, future date, other hotel's account), reversal (once only; automatic journals refused), balanced ledger and reconciled controls after every scenario, all screens and statements incl. CSV, and permissions plus tenant isolation. `Phase8Test` (v1.7) covers the link created at check-in and the welcome SMS, the access window (upcoming / in-house / read-only after check-out / expired), privacy headers, the on/off switch, tenant separation of tokens, the request lifecycle between guest and staff (validation, duplicates, open-request cap, cancel/rate only on your own stay, decline needs a reason, phone requests, assignment), SLA overdue, maintenance-ticket and HK-task links that complete the request, in-room dining arriving pending on the POS and being accepted, spa auto-assignment with no double-booking and guest cancellation, the live bill without voided lines, express check-out, one feedback entry per stay, check-out closing open requests, link send/regenerate, the role matrix and cross-tenant guards, and settings validation. `Phase9Test` (v1.8) covers the seeded AP states reconciling to the control account, bill-at-receipt, one bill per receipt, tolerance vs hold, no posting or payment while held, segregation of duties, approval posting straight away for back-dated bills, expense bills with claimable and non-claimable tax, refused control accounts, duplicate references and future dates, payment over-allocation and wrong-mode refusal, credit apply, un-apply and cancel (bills re-open, journals reversed), debit-note auto-apply, cancelling a bill releasing credits, ageing = statement = sub-ledger, reports and CSV, the role matrix and tenant isolation. `Phase10Test` (v1.9) covers the rule engine: stacking order, inclusive occupancy bands, hotel vs room-type basis, lead time, weekdays, date ranges, amounts, minimum stay, rounding and floor/ceiling, and no adjustment for past dates. It covers recommend mode (recommendations leave live rates alone; publish, republish, revert to base, past and fixed dates skipped, accept all, reset by room type and by range, manual overrides untouched) and auto-pilot (published RM rates ignored, the price rising as bookings arrive, minimum stay raised, the same price on the rate API, booking engine and grid, fixed prices kept, publishing refused). It also covers pickup and forecast bounds, reconstructing past positions from booking and cancellation times, the snapshot command, the night-audit snapshot, all screens and reports with CSV, rule and settings validation, tenant isolation, the role matrix, and `pms:upgrade` grants. `Phase11Test` (v2.0) checks that the seeded runs follow the pay rules (pro-rated pay, loss of pay, overtime, the PF cap, ESI eligibility, PT slabs, annual tax projection and rebate) and reconcile to the ledger. It covers attendance summaries (joining mid-month, half days, leave, unmarked-day policy) and locking; leave balances, carry-forward, overlap, half-day, year-boundary and joining-date rules, no self-approval, rejection reasons and withdrawal; and the run lifecycle (one run per month, adjustments, recalculation, segregation of duties, negative-net guard, approve → reopen → reverse, pay-date limits, paid runs being final, deleting a draft, and the bank advice). It also covers advances (limit, automatic recovery, cancellation only before the first recovery), component and settings validation including system components, per-employee overrides, landing pages by role, the role matrix, self-service privacy (own payslips only, approved runs only), tenant isolation, reports, and provisioning with `pms:upgrade`. `Phase12Test` (v2.1) covers provisioning and the seeded states reconciling to the clearing account. It runs the staff link → public page → test checkout flow: a forged return is ignored, the genuine return posts once, a repeat return does nothing, and paid lines can't be cancelled or voided. It covers Paystack with faked HTTP (checkout, verify in minor units, fee capture, bad signature rejected and redacted, valid webhook, replay → duplicate, short payment → review → accept posts what was received), Stripe (amount in minor units, signature and timestamp tolerance, unknown hotel or provider → 404), bounded partial and full refunds, and booking-engine deposits (tentative → paid → confirmed, unpaid → released by the expiry job, a late payment held for review, switched off → confirm directly). It also covers portal bill payment with link reuse, declines, the disabled states, payouts (permission, cap, fee validation, bank-only, ledger lines, control at zero, delete reverses), settings with encrypted secrets that are never echoed (blank keeps, clear removes), the role matrix, tenant isolation, pages and the report. `Phase13Test` (v2.2) checks the seeded orders, linen stock and batches against their movements and the ledger (laundry revenue on 4060, the batch bill on 6130). It covers price-list-only pricing (injected prices ignored, services an item doesn't offer or switched-off items refused), the express surcharge and turnaround, re-itemising, ready and deliver guards, exactly-once folio posting, frozen delivered orders, walk-in payment rules and the closed invoice, the guest-request link (same room only; start → done / decline), the portal price list, the check-out warning, and refusing delivery after check-out. It also covers linen movement and write-off rules (location limits, reason, valuation), counts, types and par, and batches: auto-collect from floors, future dates, over-receiving, damage, close short → lost at cost, charges, one bill per batch, and in-house batches with no bill. Plus settings, the role matrix, tenant isolation, reports and `pms:upgrade`. `Phase14Test` (v2.3) checks the seeded register, runs and disposals against the ledger (1510 / 1590 equal the register). It covers the depreciation maths: SLM and WDV, first-month pro-rata, the residual floor, no charge in the disposal month, and validation. It covers run order (strictly sequential, closed months only, the period lock), latest-only reversal and re-run numbering. It covers the three acquisition routes (the direct journal, refused modes, bill capitalisation without double posting and the capital-line cap), locking after depreciation, and delete rules. Disposal is covered for sold / scrapped: depreciation must be up to date, the gain/loss goes to 4980, schedules are switched off, and it blocks reversing the run. PM covers lead time, one open ticket, rolling forward and skipping missed cycles, paused schedules and disposed assets, the command and the night-audit hook. Also covered: maintenance ↔ asset status, the role matrix, tenant isolation, reports, tags and `pms:upgrade`. `Phase15Test` (v2.4) checks the seeded requisitions, issue notes and budgets (issued + committed) against the stock movements and the ledger (direct issues on the department's account, e.g. 6140 / 6040; transfers post nothing). It covers drafting and validation (same store both ends, past dates, de-activated and foreign items, duplicate lines merged), auto-approval (below the limit and within budget only, switched off at 0), edit and submit rights, and approval: HOD only, never your own, anyone when there's no HOD, cuts but no increases, nothing-approved refusal, and over budget in warn mode (tick + reason) and block mode (setup rights only). Issue is covered for direct and transfer: part issue, over-issue, stock and date guards, the closed-day ledger re-post, and the commitment moving to issued rather than doubling. It also covers acknowledgement (right department, once), close short releasing the budget, cancel rules and repeat, purchase orders for shortages (last supplier, net of stock and open POs, no history → told), par suggestions and the top-up form, the sidebar badge, the role matrix, tenant isolation, reports and `pms:upgrade`. `Phase16Test` (v2.5) checks the seeded pipeline (every status, deposits, the Rotary bill on guarantee-vs-actual, the offsite's fee and credit) against the folios and the ledger (banquet revenue on 4030). It covers creation and validation (capacity by set-up, package minimums, past dates, times, option dates), venue clashes with the turnaround (incl. late-evening functions near midnight, two functions of one event, enquiries that can't be held over a booking), the pipeline (option before the event, deposit before confirm, refused modes, lost blocked while money is held, re-open), auto-release (deposits protected), guarantee cut-off (up not down) and frozen events, attendance and billing (one charge per line with its own tax, no double billing), the cancellation scale, waiver rights and refunds that close the folio, documents, the diary, the role matrix, tenant isolation, reports, settings and `pms:upgrade`. `Phase17Test` (v2.6) covers segment rules (malformed rules dropped, all / any, "is not" including blanks, tags, birthday month, the live preview), promo validation (unknown, inactive, expired, stay window, minimum nights, room type, fully redeemed, per guest), the per-night discount never going below zero, the 90 % cap and per-hotel unique codes, booking-engine redemption with consent capture and a cancellation freeing the use, campaign sends with every skip reason (no consent, blacklisted, invalid e-mail, frequency cap), personalisation and tracked links, no second send, subject and inactive-segment guards, scheduling (past times refused, not before due, the command, back to draft when the segment is switched off, unschedule and cancel rules), automations (once per occasion, skips not retried, next year = new occasion, cap exempt, one pre-arrival per booking with all tokens resolved, inactive ones not run, toggle rights), attribution (click beats promo, promo to the carrying campaign, influenced within the window and nothing after it, campaign stats), the preferences page (channels can only be switched off, logged with source, no-op not logged, one-click without CSRF, unknown token 404, preview for staff only, skipped next time) and staff consent rights, guest stats with imported stays and RFM, and every CRM page and report, CSV export, the role matrix, settings and tenant isolation. `Phase18Test` (v2.7) covers contract pricing (net / double / extra adult, a season beating the open line, blackout and out-of-period nights at the public rate, % off following the daily rate, LRA overriding stop-sell and a non-LRA contract not), contract validation (net without rates, overlapping seasons, allotments outside validity or beyond inventory, duplicate corporate codes), maker-checker activation (no permission, never the drafter), overlap refusal, locking, suspension, re-approval, renewal with uplift and deleting drafts only. It covers allotments (held from general sale, released inside the window, pickup leaving general stock unchanged, the contract booking its last room when general stock is gone), the rate API and contract linking, bill-to-company guards, contract cancellation terms and commission, FIFO ageing / exposure / available credit, the charge guard (limit, hold, override permission and reason, not approved, inactive), folio transfer and check-out with and without an override, the automatic review (auto hold / release, manual holds kept, grace days, switched off), terms and statement logging, the booking-engine corporate code, every page and report, the role matrix, `pms:upgrade` and tenant isolation. `Phase19Test` (v2.8) covers the seeded channel state, ARI (availability capped by max-sell and never negative, channel stop-sell / min-stay / closed-to-arrival merged with hotel restrictions, markup and rounding, BB/HB supplements, unmapped rates left out), the change queue (bookings, rates and restrictions mark dates dirty; delta pushes send only dirty dates, signed, with a watermark so a concurrent change is never lost; full refresh in 31-day chunks), failures (back-off schedule, error kept for the log, retry, SSRF guard on endpoints, simulator never touching the network), the webhook (unknown token 404, bad / stale signature 401, paused 423, bad JSON 400, validation 422 incl. past dates and wrong currency, idempotent duplicates, card data stripped), modifications and cancellations (cancelled and unknown bookings, in-house stays flagged rather than changed), overbooking (accepted, flagged, resolved from the dashboard or the reservation), unmapped messages reprocessed after mapping, inbox actions, channel-collect billing and commission, connection admin (validation, secret rotation, deletion refused with bookings), every page and report, the role matrix, scheduling and tenant isolation. Phase20Test (privacy) covers: request deadlines, auto-linking, one-off extension and the event timeline; identity rules (e-mail link refused for erasure); access exports (all sections, counted and logged); full erasure (profile, scans on disk, SMS / e-mail logs, loyalty forfeit, audit-trail names, consent proof kept); legal holds (police and tax: which fields are kept, restricted profile, completed by the retention job when the hold ends); blockers; retention dry run = actual run, settings validation; the public form (honeypot, confirmation tick, 3-request flood guard, hashed 48 h token, deadline from confirmation, expiry); masking and logged reveals by role; the breach clock and closing rules; consent withdrawal and marketing exclusion; screens, reports, the dashboard, permissions, tenant isolation, the console command and the schedule. Phase21Test (GST) covers: GSTIN check characters and the validation rule (India only); buyer snapshot and invoice date at close; the sandbox IRN (deterministic hash, QR claims, payload totals, no second call); B2C without an IRN; the printed tax invoice; data errors that are not retried and are fixed by refreshing the buyer; the 30-day rule; the GSP driver with faked HTTP (headers, 503 → back-off → scheduled retry, duplicate IRN fetched, cancellation); settings with the SSRF guard, SAC validation and kept secrets; the re-open rules; cancellation (number recorded, new number on re-issue, 24-hour limit); credit notes to the company account (balance and outstanding, CRN payload, remaining creditable amount, cancel blocked, credit note cancelled) and refunds (full-line tax, wrong modes, the section 34 limit, roles); GSTR-1 sections, checks, JSON and the filing lock; screens, reports, the dashboard, permissions, `pms:upgrade` back-fill and the schedule. Phase22Test (feedback & reputation) covers: survey scheduling at check-out (delay, idempotency, no link before sending), the runner (send with a hashed 40-hex token, one reminder, expiry, the command and its 15-minute schedule), skip reasons (cooldown, invalid e-mail, restricted data, feedback given in the stay, the auto switch, inactive survey) and manual sends; the public survey (privacy headers, sent → opened, unknown / malformed tokens, validation of required answers and ranges, answered once, answers stored per question, promoter review links and click tracking, the apology note for detractors); text analysis (negation, clause splitting, safety words, NPS maths); automatic cases (department owner, SLA, safety words → high) and the case lifecycle over HTTP (assign to case staff only, start, e-mail contact logged, goodwill points with the manager limit, close only after resolving, re-open, escalation once, dashboard alert, in-house desk case with the short SLA); review CSV import (duplicates, bad rows reported, 10-point scale, name matching to a stay, high-severity case, URL scheme guard, re-link / unlink); four-eyes approval (placeholders and length checked, draft → pending → returned with a note → approved by someone else → posted, positive reviews ready to post, ignore / restore); the survey builder rules; settings validation (SLA order, https links, owners must handle cases, safety words normalised), templates, the role matrix and tenant isolation; privacy export / erasure; every screen, the five reports with CSV, the guest card and an idempotent `pms:upgrade`. Phase23Test (table reservations) covers: the seeded floor, history and live state (joined tables sharing one order, to clear, blocked, late); slots from service periods (weekday rules, last seating), full slots and overlap through the dining time, staff allowed a bigger table while online guests are not; best-fit and joined-table allocation, clashes refused and the reset time respected, past times refused; the staff form (guest matched by phone, requests, confirmation message, contact required, phone look-up with history, edit); phone normalisation and the repeat no-show flag; the public page (privacy headers, honeypot, service periods, largest party, notice, 3-booking flood guard, manage link, guest cancellation, unknown tokens) and online requests waiting for confirmation; seating that opens or adopts the POS order, refusal of occupied tables, the bill closing the party and flagging the table, clearing; POS move and merge carrying the booking; walk-ins, the waitlist (quote, "ready" SMS, seat on chosen tables, walk-away, reinstate, closed next day); no-shows (not before time, manual, reinstate, automatic after the hold, switched off) and reminders sent once; cancellations and blocked tables; settings validation, dining times, weekend-only periods and the layout API; the role matrix and tenant isolation; the day sheet, floor, POS tiles, dashboard, the four reports with CSV and the guest card; privacy export / erasure / retention; the schedule, the command and `pms:upgrade`. Phase24Test (guest transport) covers: the seeded fleet, roles, alerts and every transport page; booking from a reservation (prefill, pick-up lead window, tariff fare, flight normalised) and its guards (room billing needs a current stay, past times, validation); clash detection with the turnaround buffer, the desk's clash hand-back and the logged override, cancelled trips freeing the car, and the assign-dialog JSON with suggestions; blockers (workshop, expired PUC, too few seats, expired licence, retired car) that can't be forced; the room-billed lifecycle with odometer checks, automatic extra-km billing, the folio charge and guest / driver messages; direct billing (payment required, a closed zero-balance invoice, a guest profile) and complimentary trips; cancellation voiding a posted fare, posting only once, no-show rules and the no-show fee; the check-out guard, the check-out warning and *Add to this bill*, and extras only after an early posting; reservation cancellation cancelling planned trips; guest-request linking and completion; the guest portal (tile, honeypot, notice, stay window, request → desk confirm → guest cancel, the 2-hour rule, other stays' trips unreachable); the driver phone screen (own trips only, 403 elsewhere, start / on board / complete, fuel log with odometer, unlinked logins, desk view-as); the role matrix and tenant isolation; reminders once and request expiry, the command and schedule; rescheduling with clash re-check and workshop refusal, un-assign; expenses, settings and masters; the four reports, the dashboard, and privacy export / erasure. Phase25Test (foreign guests) covers: the seeded Form C and forex state and every page; check-in opening the Form C with the 24-hour deadline, profile data and online check-in pre-fill (idempotent; none for Indian guests or when switched off); particulars validation, profile sync, visa-expiry warnings and the scan requirement; filing rules (incomplete, reference format, future / pre-check-in times, duplicate references, late reason) and re-opening with an audit note; the check-out warning and block, departure stamping and reporting, no re-open after the departure is reported, forced check-out; Nepal / Bhutan without visa fields, exemptions, companions and the nationality fix from the desk and the guest profile; hourly alerts sent once, the command, the schedule and the night-audit list; rates (margin checks, copy, night-audit carry-forward); the GST slabs, the cash limit with 30-day aggregation, the passport copy from ₹50,000, per-100 yen, residents and the switch; cash exchanges with the shift hand-over and balanced journals, the shift requirement; bill credits and same-day voids; FIFO position, surrender checks, gain and proportional cost; the role matrix, statutory setting ceilings and tenant isolation; privacy export / erasure; the four reports, dashboard, reservation card, online check-in and the guest portal page. Phase26Test (front desk operations) covers: the seeded desk state, counters and every page; log-book entries (title and future follow-up checks, note vs open by category, business date and shift stamped), read receipts and acknowledgement, comments, resolve with a note, re-open, pins restricted to managers, system entries and the hand-over buckets; shift boundaries (including the night shift after midnight) and settings validation; messages and parcels (numbering, SMS notification without the private text, slips, validation, stays that aren't in-house, parcel collector required, notify by call, return with a reason, portal read → delivered / parcel notified, check-out cancelling messages but keeping parcels, departed-guest parcels, arriving-guest messages notified at check-in); wake-up calls (past / after-departure / ±10-minute clash refused, not attempted before due, busy → retry, answered → daily series rolls on, three no-answers → escalated urgent log entry, the runner escalating once and closing missed calls, the command and its schedule, portal booking and cancel, a portal clash leaving no request behind, room move and check-out hooks); traces (no past dates, reservation link and page, done / cancel rules, night-audit pre-check and carry-forward); lost & found (numbering, category and value-based valuables, safe storage, retention periods, probable owner from the last stay, edit, desk vs supervisor release, photo ID and courier details, receipt, retention before disposal, police early, documents only to police, no cash to the finder, witness must be another user with the right password, housekeeping finds landing in the register) and loss reports (contact required, candidate matching, match once, return closes the report, close with a note); the role matrix and tenant isolation; privacy export / erasure; the five reports with CSV, the night-audit and dashboard panels. Phase27Test (budgeting) covers: the fiscal calendar (April and calendar-year starts, month index, go-live month), the seeded versions (four-eyes, statistics driving room revenue) and settings validation; actuals that reconcile with the GL P&L per account and month, nothing after the cut-off, and actual rooms statistics; blank / copy with growth / last-year / run-rate versions (the go-live window) and creation guards; the grid (only changed values, notes, drafts only), even / by-days / seasonal spreads adding up exactly, statistics recalculating room revenue, % adjustments and the HTTP forms; CSV export, BOM and ₹ handling, and the all-or-nothing import with row errors; submit (not empty), return with a note, resubmit, the four-eyes refusal, a single active version, activate, re-open with a reason, drafts-only delete; re-forecasts (closed months only, locked actual months, pre-go-live months kept, statistics from actuals, spreads keeping locked months, the active forecast column); budget vs actual (pro-rating factor, month / YTD / full-year totals, GOP and net arithmetic, favourable logic, flag thresholds, pre-go-live and future months, YTD without go-live, KPIs); commentary (validation, author / approver deletion); spend control (within budget, unbudgeted accounts, income ignored, before go-live, warn with the flash and bill note, month / annual basis, block without the permission, override needing a reason, off); permissions, nav badge and dashboard card; tenant isolation; and the five reports with CSV. Phase28Test (hotel interfaces) covers: defaults, tariffs, extensions and the seeded calls, cards and master keys; rating (longest prefix, `+` numbers, pulses, markup, minimum charge, emergency / toll-free / internal / unanswered calls, free seconds, stripping the access digit, the configurable free list, a missing default tariff, tariff validation, the JSON calculator); folio posting (telephone category, tax, masked number, source link, duplicate lines ignored, unmasked option, free calls logged against the stay, future times refused, manual entry with m:ss); attribution (vacant room, unknown extension added and re-run to department costing, a record arriving after check-out, calls before and after a room move, the PBX move command and cards following the guest); the review queue (post to another stay, class-of-service holds not auto-posted, waive with a reason, reversal of a posted call, auto-posting off then re-run); SMDR parsing (duration and date formats, header / short / bad lines, idempotent re-import, a custom column order, format validation, file upload); the push API (JSON and raw text, duplicates, bad and rotated tokens, switched off, per-property tokens); class of service at check-in, holds, changes by role, check-out barring and card expiry, the offline driver with manual and scheduled retry; key cards (new / duplicate / limits, supersede, areas, validity and the late-check-out key, encoder offline or switched off, HTTP roles); lost cards (fee, replacements, no-reissue, return, read, extension re-validation, the expiry sweep and `pms:interfaces`); the master-key register (sign out / in, grand-master purpose, contractors, overdue and late return, lost → urgent security log entry and lock cancel, key master data); and permissions, pages, nav badges, the dashboard card, the folio sidebar, the five reports with CSV and tenant isolation. Phase29Test (gift vouchers) covers: defaults, the Gift Voucher payment mode and its 2120 mapping, the system accounts, role grants and the seeded states; code generation and normalisation; sale limits, validation, e-mailing and the sale posting; folio redemption (wrong / missing code, over-balance, partial, refund back onto the voucher capped at what the folio took, void restores the balance, the desk's apply-to-folio action); where-usable rules, single-use breakage, suspend / re-activate and expiry; POS settlement (roll-back on a missing code, short balance or a folio-only voucher; redemption linked to the bill; the POS GL debit to 2120); the mode kept out of AP and spa; stored-value reloads (type check, max balance, validity restart, reload after full use); promotional campaigns (recipient lists, bulk, promo-expense posting) and cancellations (promo withdrawn, sold refunded by mode, front desk forbidden); `pms:vouchers` expiry (breakage vs promo release) and once-only reminders; GL 2120 reconciling to the voucher ledger at the cut-off; every page, the print card and public page with QR, masked codes for cashiers, settings and disabling, voucher types; the five reports, permissions and tenant isolation. Phase31Test (API & webhooks) covers: key issue (hashed, shown once, scopes, presets, IP allow-list, expiry), rotate / pause / revoke and the owner check; every error code (missing, malformed, unknown, revoked, expired, paused, wrong IP, missing scope, inactive owner, API switched off, unknown path, wrong method) with the envelope and request id; rate limiting and the bad-token brake; availability and rates matching the desk; booking with the guest, business source, external reference and activity log, availability and stop-sell refusals, idempotent replay, key reuse with a different body, cancellation with the policy; folio, guest lookup, rooms and housekeeping updates with the in-house stay, daily statistics; tenant isolation; webhook URL policy with DNS, signatures and secret rotation, events fired only after commit, retries and back-off, auto-disable and re-enable, test pings, the console pages, reports, permissions and log retention. Phase32Test (upselling) covers: permissions, roles, menu and the seeded catalogue, history and live orders with their charges; quotes (rate difference with the minimum, per person per night, per stay, in-house nights from tonight) and every eligibility rule (channel, room type, already in that type, minimum nights, online lead time vs the desk, validity, inactive, switched off, keep-free inventory, before check-in only, early check-in after check-in time); upgrades switching the room type and releasing the old room, the nightly charge, one upgrade per stay, cancel reverting the type and stopping the charge, a desk upgrade with its incentive → check-in → first night posted once → cancel refused; early / late requests (awaiting, one per kind, decline needs a reason, re-request, confirm stamping the time and a once charge, cancel clearing it), late check-out posted at check-out, expiry and the check-out decline; desk sales, incentives by % and fixed, duplicate add-ons, cancel zeroing the incentive, daily limits by date and the role matrix; the guest page (stable token, opened / converted funnel, order and request with a note, someone else's stay or order, bad token, the cancellation window, portal channel in house, closed after cancellation with 410 on order, disabled → 404); catalogue validation (upgrade / time / channel / code / icon / other hotel's room type), edit, toggle, delete refused once sold, settings (partial updates keep the rest); the pre-arrival e-mail and presentation, the hourly run once per guest, auto-e-mail off, the command, the no-show and check-out hooks; and every page, the reservation card, the check-in panel and its presentation, the portal tile, the three reports and tenant isolation. Phase33Test (security) covers: permissions, roles, menu, version and the seeded register; registration to guests, staff, functions and others with validation (checked-out or other hotel's stay, missing host, ID required or not); guest confirmation (awaiting → approve / deny with a reason, the pass capped at visiting hours, extension), the policy mode, after-hours refusal and the manager-only override, the no-visitor and call-first privacy flags; watch-list matching by phone, ID hash and name (block refused, alert logged, override, lifted and expired entries, no raw ID stored or shown); portal pre-registration (time window, stay dates, honeypot), the public pass without the room number, arrival with ID, cancel, the guest's own privacy setting and the check-out hook; gate passes (validation, fixed assets and the one-open-pass rule, no self-approval, gate-out only after approval, over-receipt refused, partial → returned, asset status, non-returnable closing at the gate, cancel, extend and close with a note, own-pass visibility and the role matrix); `pms:security` (overstay and overdue alerts once, no-shows, retention anonymisation); incidents (escalation by severity with log entry and SMS, future time, follow-up, update, close needs a resolution, closed is locked, reopen); and every page, the dashboard card, nav badges, the four reports, privacy erasure / export and tenant isolation. `phase7.py`, `phase8.py`, `phase9.py`, `phase10.py`, `phase11.py`, `phase12.py`, `phase13.py`, `phase14.py`, `phase15.py`, `phase16.py`, `phase17.py`, `phase18.py`, `phase19.py`, `phase20.py`, `phase21.py`, `phase22.py`, `phase23.py`, `phase24.py`, `phase25.py`, `phase26.py`, `phase27.py`, `phase28.py`, `phase29.py`, `phase30.py`, `phase31.py` `phase32.py` and `phase33.py` (Playwright, dev server running). **350 tests, ~10,900 assertions** (the count varies slightly with the randomised demo history; every run passes). The suite runs on a fixed reference clock, Sat 26 Sep 2026 16:00 GMT (Africa/Accra) (`Tests\TestCase::REFERENCE_NOW`). The clock keeps ticking in real time, so scenario tests that check the demo's calendar dates don't break as the real date moves on. Set `PMS_TEST_REAL_CLOCK=1` to run against the real date.

## Production checklist
`APP_ENV=production`, `APP_DEBUG=false`, HTTPS (HSTS is sent automatically on HTTPS), `PMS_FRAME_ANCESTORS="'self'"`, a strong DB password, change every demo password (or don't seed demo data), set up the cron entry, and store backups off-site.
