<?php

/*
|--------------------------------------------------------------------------
| Nowills PMS – module, permission and navigation registry
|--------------------------------------------------------------------------
*/

return [
    'version' => '7.52.0',   // asset cache-buster + the sidebar stamp; docs/MODULES.md quotes it and modules_doc_check.py diffs the two

    'banquet_service_categories' => ['av' => 'Audio-visual', 'decor' => 'Décor & flowers', 'entertainment' => 'Entertainment', 'equipment' => 'Equipment & furniture', 'staffing' => 'Staffing', 'other' => 'Other'],

    // CSP frame-ancestors: "'self'" in production; "*" only when embedding in a preview iframe.
    'frame_ancestors' => env('PMS_FRAME_ANCESTORS', "'self'"),

    // v7.37 – allow channel/test endpoints on private addresses (dev sandboxes only!)
    'channel_allow_private' => (bool) env('PMS_CHANNEL_ALLOW_PRIVATE', false),

    'permissions' => [
        'Dashboard' => ['dashboard.view' => 'View dashboard'],
        'Reservations' => [
            'reservations.view' => 'View reservations', 'reservations.create' => 'Create reservations',
            'reservations.edit' => 'Edit reservations', 'reservations.cancel' => 'Cancel / No-show reservations',
            'reservations.rates' => 'Manage seasonal rates & stop-sell',
        ],
        'Front Desk' => [
            'frontdesk.view' => 'View room view & tape chart', 'frontdesk.checkin' => 'Check-in / Walk-in',
            'frontdesk.checkout' => 'Check-out', 'frontdesk.roommove' => 'Room move / amend stay',
        ],
        'Guest Requests' => ['requests.view' => 'View the guest request queue', 'requests.manage' => 'Handle guest requests & manage portal links'],
        'Billing & Folio' => [
            'folio.view' => 'View folios', 'folio.post' => 'Post charges', 'folio.payment' => 'Receive payments / refunds',
            'folio.void' => 'Void transactions', 'folio.discount' => 'Give discounts / allowances', 'folio.loyalty' => 'Redeem loyalty points', 'folio.split' => 'Split / route folio lines',
            'folio.credit_note' => 'Issue VAT credit notes (refunds) against invoices',
        ],
        'Cashiering' => [
            'folio.shift' => 'Open / close own cashier shift', 'folio.shift_all' => 'Review & close all cashier shifts',
        ],
        'GRA VAT & E-VAT' => [
            'gst.view' => 'View VAT documents, E-VAT status & the VAT / Tourism Levy return',
            'gst.manage' => 'Submit documents to E-VAT, cancel unstamped invoices, file the VAT return & VAT settings',
        ],
        'Online Payments' => [
            'payments.view' => 'View online payments & payment links', 'payments.request' => 'Create, send & cancel payment links',
            'payments.refund' => 'Refund online payments & accept payments under review', 'payments.settle' => 'Record gateway payouts (settlements)',
            'payments.settings' => 'Payment gateway settings & keys',
        ],
        'Guests & CRM' => ['guests.view' => 'View guests', 'guests.manage' => 'Manage guests & companies', 'messages.send' => 'Send SMS / WhatsApp to guests',
            'pii.reveal' => 'Reveal masked ID / passport numbers & dates of birth (every reveal is logged)'],
        'Foreign Guests & Forex' => ['formc.view' => 'View the Form C (FRRO) desk, foreign guests & visa alerts', 'formc.manage' => 'Complete, submit, exempt & re-open Form C; report departures; Form C settings',
            'forex.view' => 'View exchange rates, currency exchanges & the forex position', 'forex.exchange' => 'Buy foreign currency from in-house guests (cash or credit to the bill) & print encashment certificates',
            'forex.manage' => 'Set daily exchange rates, void exchanges, surrender currency to the bank, currencies & forex settings'],
        'Feedback & Reputation' => ['feedback.view' => 'View feedback, online reviews & the reputation dashboard', 'feedback.cases' => 'Handle service-recovery cases (unhappy guests)',
            'feedback.respond' => 'Draft responses to online reviews', 'feedback.approve' => 'Approve responses to critical reviews', 'feedback.manage' => 'Surveys, review import, templates & feedback settings'],
        'Privacy & Compliance' => ['privacy.view' => 'View privacy requests, breach register, retention & the PII access log',
            'privacy.handle' => 'Log, verify & process privacy requests (access exports, corrections, consent withdrawal, grievances)',
            'privacy.erase' => 'Execute erasure (anonymisation), retention settings & runs', 'privacy.breach' => 'Record & manage personal-data breaches'],
        'Contracts & Credit' => ['contracts.view' => 'View rate contracts, allotments & contract production', 'contracts.manage' => 'Draft / edit / renew rate contracts',
            'contracts.approve' => 'Activate (approve) & suspend rate contracts', 'credit.view' => 'View credit control & company exposure',
            'credit.manage' => 'Set credit limits & terms, hold / release companies, send statements', 'credit.override' => 'Bill a company on hold or over its limit (with a reason)'],
        'CRM & Marketing' => ['crm.view' => 'View marketing dashboard, campaigns, segments, automations & promo codes', 'crm.manage' => 'Create & edit segments, campaign drafts, automations & promo codes',
            'crm.send' => 'Send / schedule campaigns, switch automations on & change marketing settings'],
        'Housekeeping' => ['housekeeping.view' => 'View housekeeping', 'housekeeping.manage' => 'Update room status & tasks, inspect rooms', 'housekeeping.attendant' => 'My rooms – attendant mobile screen', 'maintenance.manage' => 'Maintenance tickets', 'lostfound.manage' => 'Lost & found – log, match loss reports, return & dispose of ordinary items', 'lostfound.release' => 'Release or dispose of valuable lost & found items (ID check / witness)'],
        'Front Desk Operations' => ['logbook.view' => 'Read the shift log book & acknowledge the hand-over', 'logbook.write' => 'Write log book entries, comment and resolve follow-ups',
            'logbook.manage' => 'Pin / resolve any log entry; front desk operations settings', 'desk.messages' => 'Take, notify & deliver guest messages and parcels',
            'desk.wakeup' => 'Book, make & cancel wake-up calls', 'desk.traces' => 'Create & complete traces'],
        'Laundry & Linen' => [
            'laundry.view' => 'View laundry orders, price list & linen stock', 'laundry.orders' => 'Collect, itemise, deliver & cancel guest laundry',
            'laundry.linen' => 'Linen movements, counts & laundry batches', 'laundry.manage' => 'Laundry price list, linen types, settings & billing batches to AP',
        ],
        'Requisitions' => [
            'requisitions.view' => 'View store requisitions, issue notes, budgets & par levels', 'requisitions.raise' => 'Raise, submit & cancel requisitions; acknowledge receipt',
            'requisitions.approve' => 'Approve / reject requisitions (head of department)', 'requisitions.issue' => 'Issue stock against approved requisitions; close short',
            'requisitions.manage' => 'Department budgets, HODs, cost accounts & store par levels; approve for any department',
        ],
        'Engineering & Compliance' => [
            'utilities.view' => 'View meters, readings, utility bills, energy KPIs & carbon footprint', 'utilities.record' => 'Enter daily meter readings; acknowledge usage anomalies',
            'utilities.manage' => 'Meters & tariffs, correct / delete readings, utility bills & AP booking, emission factors',
            'compliance.view' => 'View the licences & statutory compliance register and documents',
            'compliance.manage' => 'Add / edit licences, permits, insurance & inspections; record renewals, inspections & documents',
        ],
        'Fixed Assets' => [
            'assets.view' => 'View the asset register, depreciation & asset tags', 'assets.manage' => 'Add, edit, move & dispose of assets; asset categories',
            'assets.depreciation' => 'Run & reverse monthly depreciation',
        ],
        'POS' => ['pos.view' => 'View POS', 'pos.order' => 'Take orders / KOT', 'pos.settle' => 'Bill & settle', 'pos.refund' => 'Refund settled POS payments', 'pos.void' => 'Void orders / items', 'pos.kitchen' => 'Kitchen display (KDS) – bump tickets', 'pos.printers' => 'Set up KOT printers, station routing and reprints'],
        'Restaurant Reservations' => ['tables.view' => 'View table reservations, the floor plan & waitlist', 'tables.book' => 'Take / change / cancel table bookings, seat parties, manage the waitlist',
            'tables.manage' => 'Reservation settings, service periods, floor-plan layout & blocking tables'],
        'Transport Desk' => ['transport.view' => 'View the dispatch board, trips, fleet, papers & tariffs', 'transport.book' => 'Book, change, assign, complete & cancel guest transfers',
            'transport.manage' => 'Vehicles, drivers, tariff card, vehicle expenses & transport settings', 'transport.drive' => 'Driver phone screen – my trips (start, on board, complete)'],
        'Spa & Activities' => ['spa.view' => 'View spa / activity bookings', 'spa.manage' => 'Book, settle & cancel spa / activity appointments'],
        'Income Audit' => ['income_audit.view' => 'View audit days, exceptions, room counts, authorisations & audit reports', 'income_audit.audit' => 'Run the checks, resolve exceptions, sign the day as income auditor',
            'income_audit.close' => 'Financial controller / GM: close & reopen audit days, accept exceptions, approve above the authority limit, audit settings',
            'income_audit.request' => 'Request comps, house use, rate and discount authorisations', 'income_audit.authorise' => 'Approve / decline authorisations within the authority limit',
            'income_audit.count' => 'Housekeeping room count sheet'],
        'Apartments & Leases' => ['leases.view' => 'View apartments, leases, tenant accounts, meters & reports', 'leases.manage' => 'Units, draft & sign leases, move-in / move-out, notice, renewals, inspections & meter readings',
            'leases.billing' => 'Tenant billing: receipts & deposits, extra charges, billing run, arrears', 'leases.approve' => 'Lease settings, credit notes, cancel charges, void receipts, refunds, deposit settlement, short notice'],
        'Health Club' => ['club.view' => 'View the club dashboard, members, visits, classes & day passes', 'club.desk' => 'Check-in desk: member / resident / guest entry, sell day passes, book classes & take attendance',
            'club.members' => 'Enrol & edit memberships, card holders & photos, take member payments, renew, freeze', 'club.manage' => 'Plans & club settings, cancel memberships & charges, credit notes, refunds, override entry refusals'], // v5.1
        'Banquet' => ['banquet.view' => 'View events, diary, function sheets (BEO) & proposals', 'banquet.manage' => 'Create & edit events, holds, confirm, lost, cancel, attendance',
            'banquet.billing' => 'Take / refund deposits, bill events, waive cancellation fees', 'banquet.setup' => 'Venues, menu packages, services & banquet settings'],
        'Car Rental' => ['rentals.view' => 'View the rental desk, fleet and bookings', 'rentals.manage' => 'Book, pick up and return vehicles, edit the fleet, cancel rentals'], // v7.40
        'Inventory' => ['inventory.view' => 'View stock', 'inventory.manage' => 'Purchase / issue / adjust stock'],
        'Food Safety' => ['foodsafety.view' => 'View HACCP records, the allergen matrix and food-safety dashboard', 'foodsafety.record' => 'Record temperatures, complete checklists, log food waste, print prep labels',
            'foodsafety.manage' => 'Equipment & critical limits, checklist templates, allergen declarations, review breaches, sign off checklists, cancel waste', 'foodsafety.certs' => 'Maintain food handler medical / hygiene certificates'], // v5.0
        'Purchasing' => ['purchasing.rfq' => 'Raise requests for quotation, invite suppliers, record quotations and award (raises draft POs)', 'purchasing.prices' => 'Maintain supplier price lists / rate contracts',
            'purchasing.approve' => 'Approve purchase orders up to the level-2 limit, and POs priced above the agreed rate', 'purchasing.approve_high' => 'Approve purchase orders above the level-2 limit (senior management)'], // v4.8
        'Workforce' => ['roster.view' => 'View duty rosters and the time-clock day board', 'roster.manage' => 'Build & publish rosters, shift templates, decide shift swaps',
            'timeclock.approve' => 'Correct time punches, set kiosk PINs and approve daily time & attendance', 'timeclock.kiosk' => 'Run the shared time-clock kiosk (staff clock in with code + PIN)',
            'servicecharge.manage' => 'Record staff tips and prepare service charge distributions', 'servicecharge.approve' => 'Approve, post to payroll and cancel service charge distributions'], // v4.9
        'Accounts' => ['accounts.view' => 'View accounts', 'accounts.manage' => 'Manage vouchers & city ledger'],
        'Bank Reconciliation' => ['bankrec.view' => 'View bank / Mobile Money statements, matches and reconciliation statements', 'bankrec.manage' => 'Import statements, match, unmatch, ignore lines, book charges / interest from a statement',
            'bankrec.approve' => 'Finalise and reopen reconciliations; reconciliation settings'], // v4.6
        'Petty Cash' => ['petty.view' => 'View petty cash floats, cash books, vouchers, IOUs and counts', 'petty.manage' => 'Operate a float as its custodian: vouchers, IOUs & settlements, cash counts, top-up requests',
            'petty.approve' => 'Approve over-limit vouchers, top-ups and count differences; pay top-ups; open / change floats; operate any float'], // v4.7
        'Expense Claims' => ['claims.submit' => 'Claim back my own out-of-pocket expenses', 'claims.approve' => 'Approve / send back staff expense claims; raise claims for staff',
            'claims.pay' => 'Pay approved expense claims (bank, Mobile Money, cash or petty cash)'], // v4.7
        'Accounts Payable' => ['ap.view' => 'View supplier bills, payments & ageing', 'ap.manage' => 'Enter & cancel supplier bills / debit notes', 'ap.pay' => 'Record supplier payments & allocate credits', 'ap.approve' => 'Approve bills held for price variance'],
        'Budgeting & Planning' => ['budget.view' => 'View budgets, forecasts & budget vs actual; comment on variances', 'budget.manage' => 'Prepare budgets & re-forecasts (edit, import, submit)', 'budget.approve' => 'Approve / return / re-open budgets & budget settings', 'budget.override' => 'Enter supplier expense bills over budget when spend control blocks'],
        'Gift Vouchers' => ['vouchers.view' => 'View gift vouchers, balances, transactions & voucher reports', 'vouchers.sell' => 'Sell gift vouchers, reload stored-value cards & e-mail vouchers',
            'vouchers.redeem' => 'Look up a voucher by code and redeem it against a bill', 'vouchers.manage' => 'Voucher types, promotional campaigns, suspend / cancel / refund & voucher settings'],
        'Hotel Interfaces' => ['calls.view' => 'View call accounting: call log, review queue, tariffs & extensions', 'calls.manage' => 'Import / post / waive calls; tariffs, extensions & phone class of service',
            'keys.view' => 'View key cards & the master-key register', 'keys.issue' => 'Encode guest key cards (new key, duplicate, lost, return, read)', 'keys.master' => 'Sign master keys out / in & manage master keys',
            'interfaces.manage' => 'Interface hub: PABX / lock settings, PBX push token, command log & retry'],
        'General Ledger' => ['gl.view' => 'View general ledger, trial balance & financial statements', 'gl.journal' => 'Post & reverse manual journal entries', 'gl.manage' => 'Chart of accounts, GL mapping, sync & period lock'],
        'Sales & Accounts' => ['sales.view' => 'View sales accounts, contacts, the opportunity pipeline, the sales diary, targets and account production', // v6.1
            'sales.edit' => 'Add prospects, contacts, opportunities and sales activities; work my own accounts and deals',
            'sales.marketing' => 'Plan marketing events (trade shows, roadshows, fam trips…), the marketing budget and the collateral library',
            'sales.manage' => 'Work every account and deal, reassign account managers, set sales targets and sales settings'],
        'Central Reservations' => ['cro.view' => 'View the central reservations desk (group availability), enquiry log, lost business, cross-property guest lookup and CRO performance', // v6.4
            'cro.book' => 'Log enquiries, book into any property of your scope, refer turnaways to sister hotels, close enquiries as denials / regrets',
            'cro.manage' => 'Override rates on central bookings, book blacklisted guests and central reservations settings'],
        'Talent Management' => ['talent.view' => 'View the talent dashboard, vacancies, applicant pipeline, new starters, appraisal progress and the establishment', // v6.9
            'talent.recruit' => 'Request vacancies, add and move applicants, schedule interviews, make offers and hire; run onboarding and new-starter paperwork',
            'talent.approve' => 'Approve vacancies and above-range offers (never your own) and decide dismissals',
            'talent.appraise' => 'Run appraisal cycles, assign reviewers, sign off appraisals and hold probation reviews',
            'talent.discipline' => 'Disciplinary cases, grievances and exits: queries, hearings, sanctions, notice, clearance and exit interviews',
            'talent.manage' => 'Talent settings, the departmental establishment and line managers'],
        'F&B Cost Control' => ['fbc.view' => 'View plate costs, recipe cards, menu engineering, the food & beverage cost statement, usage variance and cost creep', // v6.8
            'fbc.cost' => 'Write and cost recipe cards, record yield tests and apply ingredient yields, log staff meals',
            'fbc.price' => 'Change menu prices from the pricing screen, approve recipe cards and F&B cost settings'],
        'IPTV & Guest Welcome' => ['iptv.view' => 'View the in-room TV dashboard, TVs, channel line-up, movie rentals, welcome screens, TV messages and usage analytics',
            'iptv.message' => 'Send messages to guest-room TVs (one room, a floor, a group, VIP rooms, all rooms, lobby screens), withdraw them, show the welcome again',
            'iptv.emergency' => 'Put an EMERGENCY alert on every TV in the hotel and send the all clear',
            'iptv.manage' => 'Pair and configure TVs, send remote commands, channels (M3U / XMLTV guide), movies and refunds, welcome screens, information pages and IPTV settings'],
        'Partner Portal' => ['partner.view' => 'See the corporate & travel-agent portal dashboard, partner accounts and users, the request queue, the portal activity log and partner reports', // v7.4
            'partner.approve' => 'Approve or decline partner bookings held for credit / value, and complete partner change and group-quote requests',
            'partner.manage' => 'Switch the portal on for a company, set its portal terms, invite / disable / unlock partner users and change partner portal settings'],
        'Organiser Portal' => ['organiser.view' => 'See the event organiser portal dashboard, portal events, organiser logins, the request queue, the portal activity log and organiser reports', // v7.5
            'organiser.approve' => 'Approve organiser cancellations (releases the event) or decline them, and complete organiser change and quote requests',
            'organiser.manage' => 'Switch the portal on for an event, publish the banquet event order, invite / disable / unlock organiser users and change organiser portal settings'],
        'Guest Messaging' => ['inbox.view' => 'See the guest messaging inbox, conversations, the messaging dashboard, broadcasts and messaging reports', // v7.3
            'inbox.reply' => 'Answer guests (portal chat, WhatsApp, SMS, e-mail), add internal notes, assign, snooze and close conversations, raise guest requests from a message',
            'inbox.broadcast' => 'Send a broadcast to in-house guests, today\'s arrivals / departures or one floor',
            'inbox.manage' => 'Guest messaging settings: desk hours, response-time target, automatic replies, quick replies, keyword routing rules and the inbound webhook secret'],
        'Self-Service Kiosk' => ['kiosk.view' => 'View the kiosk dashboard, kiosks, guest visits (step-by-step trail) and kiosk reports', // v7.2
            'kiosk.assist' => 'Answer the kiosk assistance queue – mark guests the kiosk handed to the desk as dealt with',
            'kiosk.manage' => 'Pair, configure and switch off lobby kiosks, issue new start addresses and change kiosk settings (deposits, ID, walk-ins)'],
        'Sustainability & ESG' => ['esg.view' => 'View the ESG dashboard, carbon footprint (HCMI / HWMI), waste and refrigerant logs, targets, initiatives, people indicators, certification, policies, carbon statements and the annual ESG report', // v7.0
            'esg.record' => 'Record waste consignments, refrigerant top-ups, community contributions and propose green initiatives',
            'esg.green' => 'Enrol in-house guests in the green-stay choice (skip stay-over cleaning for loyalty points) and end it',
            'esg.manage' => 'Targets, initiative approval, certification self-assessment, policy register, outsourced laundry tonnage, building areas, emission factors and ESG settings'],
        'Health & Safety' => ['hse.view' => 'View the health & safety dashboard, fire equipment, drills, wardens, risk assessments, permits to work and the accident register', // v6.7
            'hse.check' => 'Record fire-equipment checks and the monthly round, report accidents / near misses / dangerous occurrences, request permits to work',
            'hse.permit' => 'Issue and close out permits to work (authorised issuer)',
            'hse.manage' => 'Equipment register and contractor service, drills and wardens, risk assessments and acceptance, accident investigation, Factories Inspectorate notices, settings'],
        'Parking & Valet' => ['parking.view' => 'View the parking desk, valet board, tickets, vehicles, permits and car-park occupancy', // v6.6
            'parking.operate' => 'Park and release vehicles, take valet keys and condition checks, bring cars round, move vehicles, take parking payments at the exit',
            'parking.validate' => 'Validate visitor parking tickets against an outlet bill (restaurant, spa, banquet)',
            'parking.manage' => 'Zones and capacity, tariff and valet settings, permits, banned vehicles, waive charges, cancel tickets, post tonight\'s guest parking'],
        'Quality Assurance' => ['qa.view' => 'View the QA dashboard, audits, scores, corrective actions and the standards library; update and complete corrective actions you own', // v6.5
            'qa.audit' => 'Schedule and conduct audits and walk-throughs, enter mystery-guest reports, raise corrective actions',
            'qa.manage' => 'Maintain standards and checklists, assign and verify corrective actions (never your own), cancel audits and QA settings'],
        'Business Development' => ['bd.view' => 'View the business development dashboard, competitive set, rate shops, benchmarks, market mix, partnerships and initiatives', // v6.3
            'bd.edit' => 'Enter rate shops and comp-set benchmarks; create and run partnerships and initiatives (own initiatives), milestones and results',
            'bd.approve' => 'Decide initiative business cases (never your own), shelve / revive initiatives and sign partnerships',
            'bd.manage' => 'Competitive set, all initiatives and partnerships, and business development settings'],
        'Digital Marketing' => ['digital.view' => 'View the digital dashboard, social content calendar, paid campaigns, tracked links, listings and influencer collaborations', // v6.2
            'digital.content' => 'Draft social posts, mark them published and record results; create tracked links; record channel figures and listing audits',
            'digital.approve' => 'Approve or return social posts written by others (never your own)',
            'digital.manage' => 'Channels, paid campaigns & ad spend (manual / CSV import), influencer collaborations and digital marketing settings'],
        'Learning & Development' => ['learning.view' => 'View the training matrix, courses, sessions, staff training records and the SOP library', // v6.0
            'learning.train' => 'Schedule training sessions, enrol staff, record attendance, scores and external training; record SOP sign-off sheets',
            'learning.sop' => 'Write, publish, review and retire standard operating procedures',
            'learning.manage' => 'Course catalogue, training matrix requirements, exemptions, record deletion and L&D settings'],
        'Concierge' => ['concierge.view' => 'View the concierge board, activity bookings, vendors, local guide & settlements', 'concierge.book' => 'Book, change, confirm, complete and cancel guest activities; departures & manifests', 'concierge.settle' => 'Settle with activity vendors (pay what the hotel collected, collect commission)', 'concierge.manage' => 'Activities, vendors & commission rates, local guide, concierge settings; waive fees & override cut-offs'], // v5.9
        'Owner Reporting' => ['owner.view' => 'View USALI statements, KPIs, management fees, FF&E reserve, capex and owner packs', 'owner.operate' => 'Operator: accrue & pay management fees, prepare / issue owner packs, answer owner queries, capex requests, reserve entries', 'owner.approve' => "Owner's representative: acknowledge / query owner packs, approve or reject capex", 'owner.manage' => 'Management agreement terms & USALI account mapping'], // v5.8
        'Treasury' => ['treasury.view' => 'View cash position, transfers, cheque registers, forecast & cash-flow statement', 'treasury.operate' => 'Bank cash & cheques, sweep settlements, record cheques received / written, forecast lines', 'treasury.approve' => 'Approve transfers above the threshold (four eyes)', 'treasury.manage' => 'Bank accounts, cheque books, treasury settings & cash-flow classification'], // v5.7
        'Financial Close' => ['close.view' => 'View period close, schedules, recurring journals & year end', 'close.prepare' => 'Run close checks, complete tasks, post period-end journals, enter schedules & submit a period', 'close.approve' => 'Approve / send back a period close, reopen requests, schedules & recurring journals (four eyes)'], // v5.6
        'HR & Payroll' => [
            'hr.manage' => 'Employees, attendance, leave types & employee pay setup', 'hr.leave' => 'Approve / reject staff leave requests',
            'hr.self' => 'Self-service: my payslips, leave balance & leave requests',
            'payroll.view' => 'View payroll runs, payslips & advances', 'payroll.manage' => 'Generate payroll, adjust payslips, pay components & salary advances',
            'payroll.approve' => 'Approve, reopen & pay payroll runs',
        ],
        'Night Audit' => ['nightaudit.run' => 'Run night audit'],
        'Revenue Management' => ['revenue.view' => 'View demand forecast, pickup & rate recommendations', 'revenue.manage' => 'Publish dynamic rates, pricing rules, floors & auto-pilot'],
        'Channel Manager' => ['channels.view' => 'View channel dashboard, OTA inbox, sync log & rate parity', 'channels.resolve' => 'Resolve OTA overbookings & inbox items',
            'channels.manage' => 'Manage OTA connections, mappings, restrictions, pushes & iCal feeds'],
        'Reports' => ['reports.view' => 'View reports, save personal report views & pin them to the dashboard',
            'reports.schedule' => 'Schedule e-mailed reports, share saved views, send reports to external addresses', // v5.4
            'reports.alerts' => 'Manage KPI threshold alerts and acknowledge alert events'], // v5.4
        'Packages & Meal Plans' => ['packages.view' => 'View package dashboard, meal cover forecast, entitlements & package catalogue', // v5.5
            'packages.redeem' => 'Record breakfast / meal covers and allowances used at the restaurant door, charge extras to the room',
            'packages.sell' => 'Add packages (dinner, airport drop, spa credit …) to a stay',
            'packages.manage' => 'Manage the package catalogue, rate-plan inclusions, package settings and void redemptions'],
        'Administration' => ['masters.manage' => 'Manage masters', 'users.manage' => 'Manage users & roles', 'settings.manage' => 'Hotel settings', 'logs.view' => 'View activity logs'],
        'Upselling' => ['upsell.view' => 'View upsell offers, orders, requests and conversion', 'upsell.sell' => 'Sell upsells at the desk, confirm / decline guest requests, cancel orders, e-mail offers',
            'upsell.manage' => 'Manage the offer catalogue, prices, staff incentives and upselling settings'],
        'Security' => ['security.view' => 'View visitor register, gate passes, incidents and the security dashboard',
            'security.visitors' => 'Register visitors, approve / turn away, check in / out, print badges, set guest visitor preferences',
            'security.gate' => 'Gate duty: let approved gate-pass goods out and receive returns',
            'security.gatepass' => 'Raise material gate passes for your department',
            'security.approve' => 'Approve / reject gate passes, move return dates, short-close passes',
            'security.incidents' => 'Report security incidents and add follow-up notes',
            'security.manage' => 'Watch list, overrides (barred persons, no-visitor rooms, after-hours), close / reopen incidents, security settings'],
        'API & Integrations' => ['api.view' => 'View API clients, webhooks, deliveries and the API request log',
            'api.manage' => 'Issue / rotate / revoke API keys, manage webhook endpoints, retry deliveries, API settings'],
    ],

    // Sidebar navigation. 'perm' is required permission; 'route' is the named route.
    'menu' => [
        // DASHBOARD: Dashboard & Operations
        ['label' => 'Dashboard & Operations', 'icon' => 'grid', 'section' => 'DASHBOARD', 'children' => [
            ['label' => 'Dashboard', 'icon' => 'grid', 'route' => 'dashboard', 'perm' => 'dashboard.view'],
            ['label' => 'Group Dashboard', 'icon' => 'layers', 'route' => 'group.dashboard', 'perm' => 'group.dashboard'],
            ['label' => 'Night Audit', 'icon' => 'moon', 'route' => 'nightaudit.index', 'perm' => 'nightaudit.run'],
            ['label' => 'Reports', 'icon' => 'chart', 'children' => [ // v5.4
                ['label' => 'All Reports', 'route' => 'reports.index', 'perm' => 'reports.view'],
                ['label' => 'My Reports & Saved Views', 'route' => 'reports.library', 'perm' => 'reports.view'],
                ['label' => 'Scheduled Reports', 'route' => 'reports.schedules', 'perm' => 'reports.schedule'],
                ['label' => 'KPI Alerts', 'route' => 'reports.alerts', 'perm' => 'reports.alerts', 'badge' => 'kpi_alerts'],
            ]],
        ]],
        // OPERATIONS: Front Office & Guest Services
        ['label' => 'Front Office & Guest Services', 'icon' => 'bell', 'section' => 'OPERATIONS', 'children' => [
            ['label' => 'Front Office', 'icon' => 'bell', 'children' => [
                ['label' => 'Room View', 'route' => 'frontdesk.rooms', 'perm' => 'frontdesk.view'],
                ['label' => 'Tape Chart', 'route' => 'frontdesk.tapechart', 'perm' => 'frontdesk.view'],
                ['label' => 'Walk-in Check-in', 'route' => 'frontdesk.walkin', 'perm' => 'frontdesk.checkin'],
                ['label' => 'Arrivals', 'route' => 'frontdesk.arrivals', 'perm' => 'frontdesk.view'],
                ['label' => 'In-House Guests', 'route' => 'frontdesk.inhouse', 'perm' => 'frontdesk.view'],
                ['label' => 'Departures', 'route' => 'frontdesk.departures', 'perm' => 'frontdesk.view'],
                ['label' => 'Guest Requests', 'route' => 'requests.index', 'perm' => 'requests.view', 'badge' => 'guest_requests'],
                ['label' => 'Offline Request Capture', 'route' => 'offline.guest_requests', 'perm' => 'requests.view'],
            ]],
            ['label' => 'Desk Operations', 'icon' => 'book', 'children' => [
                ['label' => 'Shift Log Book', 'route' => 'desk.logbook', 'perm' => 'logbook.view', 'badge' => 'desk_log'],
                ['label' => 'Shift Hand-over', 'route' => 'desk.handover', 'perm' => 'logbook.view'],
                ['label' => 'Wake-up Calls', 'route' => 'desk.wakeups', 'perm' => 'desk.wakeup', 'badge' => 'desk_wakeups'],
                ['label' => 'Messages & Parcels', 'route' => 'desk.messages', 'perm' => 'desk.messages', 'badge' => 'desk_messages'],
                ['label' => 'Traces', 'route' => 'desk.traces', 'perm' => 'desk.traces', 'badge' => 'desk_traces'],
                ['label' => 'Lost & Found', 'route' => 'lostfound.index', 'perm' => 'lostfound.manage', 'badge' => 'desk_lf'],
                ['label' => 'Desk Settings', 'route' => 'desk.settings', 'perm' => 'logbook.manage'],
            ]],
            ['label' => 'Reservations', 'icon' => 'calendar', 'children' => [
                ['label' => 'Reservation Workspace', 'route' => 'reservations.workspace', 'perm' => 'reservations.view', 'badge' => 'ws_arrivals'],
                ['label' => 'New Reservation', 'route' => 'reservations.create', 'perm' => 'reservations.create'],
                ['label' => 'Reservation List', 'route' => 'reservations.index', 'perm' => 'reservations.view'],
                ['label' => 'Availability', 'route' => 'reservations.availability', 'perm' => 'reservations.view'],
                ['label' => 'Group Blocks', 'route' => 'blocks.index', 'perm' => 'reservations.view'],
                ['label' => 'Rate Calendar', 'route' => 'rates.index', 'perm' => 'reservations.rates'],
                ['label' => 'Channel Manager', 'route' => 'cm.index', 'perm' => 'channels.view', 'badge' => 'channel_attention'],
                ['label' => 'iCal Feeds', 'route' => 'channels.index', 'perm' => 'channels.manage'],
            ]],
            ['label' => 'Central Reservations', 'icon' => 'phone', 'children' => [ // v6.4 central reservations & lost business
                ['label' => 'CRO Desk', 'route' => 'cro.index', 'perm' => 'cro.view'],
                ['label' => 'Enquiries', 'route' => 'cro.enquiries', 'perm' => 'cro.view', 'badge' => 'cro_attention'],
                ['label' => 'Guest Lookup', 'route' => 'cro.guests', 'perm' => 'cro.view'],
                ['label' => 'Lost Business', 'route' => 'cro.lost', 'perm' => 'cro.view'],
                ['label' => 'CRO Performance', 'route' => 'cro.performance', 'perm' => 'cro.view'],
                ['label' => 'CRO Settings', 'route' => 'cro.settings', 'perm' => 'cro.manage'],
            ]],
            ['label' => 'Concierge', 'icon' => 'globe', 'children' => [ // v5.9 activities & excursions desk
                ['label' => 'Concierge Desk', 'route' => 'concierge.index', 'perm' => 'concierge.view', 'badge' => 'concierge_attention'],
                ['label' => 'Activity Bookings', 'route' => 'concierge.bookings', 'perm' => 'concierge.view'],
                ['label' => 'Activities & Vendors', 'route' => 'concierge.catalogue', 'perm' => 'concierge.view'],
                ['label' => 'Local Guide', 'route' => 'concierge.guide', 'perm' => 'concierge.view'],
                ['label' => 'Vendor Settlements', 'route' => 'concierge.settlements', 'perm' => 'concierge.view'],
                ['label' => 'Concierge Settings', 'route' => 'concierge.settings', 'perm' => 'concierge.manage'],
            ]],
            ['label' => 'Guest Messaging', 'icon' => 'message', 'children' => [ // v7.3 shared inbox
                ['label' => 'Inbox', 'route' => 'inbox.index', 'perm' => 'inbox.view', 'badge' => 'inbox_attention'],
                ['label' => 'Messaging Dashboard', 'route' => 'inbox.dashboard', 'perm' => 'inbox.view'],
                ['label' => 'Broadcasts', 'route' => 'inbox.broadcasts', 'perm' => 'inbox.view'],
                ['label' => 'Quick Replies', 'route' => 'inbox.replies', 'perm' => 'inbox.manage'],
                ['label' => 'Routing Rules', 'route' => 'inbox.rules', 'perm' => 'inbox.manage'],
                ['label' => 'Messaging Settings', 'route' => 'inbox.settings', 'perm' => 'inbox.manage'],
            ]],
            ['label' => 'Self-Service Kiosk', 'icon' => 'key', 'children' => [ // v7.2 lobby kiosk
                ['label' => 'Kiosk Dashboard', 'route' => 'kiosks.index', 'perm' => 'kiosk.view', 'badge' => 'kiosk_attention'],
                ['label' => 'Kiosks', 'route' => 'kiosks.devices', 'perm' => 'kiosk.view'],
                ['label' => 'Guest Visits', 'route' => 'kiosks.sessions', 'perm' => 'kiosk.view'],
                ['label' => 'Kiosk Settings', 'route' => 'kiosks.settings', 'perm' => 'kiosk.manage'],
            ]],
            ['label' => 'IPTV & Guest Welcome', 'icon' => 'grid', 'children' => [ // v7.1 in-room TV
                ['label' => 'TV Dashboard', 'route' => 'iptv.index', 'perm' => 'iptv.view', 'badge' => 'iptv_attention'],
                ['label' => 'TVs & Screens', 'route' => 'iptv.devices', 'perm' => 'iptv.view'],
                ['label' => 'Channels & Guide', 'route' => 'iptv.channels', 'perm' => 'iptv.view'],
                ['label' => 'Movies & Rentals', 'route' => 'iptv.movies', 'perm' => 'iptv.view'],
                ['label' => 'Welcome Screens', 'route' => 'iptv.welcome', 'perm' => 'iptv.view'],
                ['label' => 'Information Pages', 'route' => 'iptv.pages', 'perm' => 'iptv.view'],
                ['label' => 'TV Messages', 'route' => 'iptv.messages', 'perm' => 'iptv.view'],
                ['label' => 'Usage Analytics', 'route' => 'iptv.analytics', 'perm' => 'iptv.view'],
                ['label' => 'IPTV Settings', 'route' => 'iptv.settings', 'perm' => 'iptv.manage'],
            ]],
            ['label' => 'Foreign Guests', 'icon' => 'globe', 'children' => [
                ['label' => 'Form C (FRRO)', 'route' => 'fg.formc', 'perm' => 'formc.view', 'badge' => 'formc_attention'],
                ['label' => 'Currency Exchange', 'route' => 'fg.fx', 'perm' => 'forex.view'],
                ['label' => 'Exchange Rates', 'route' => 'fg.rates', 'perm' => 'forex.view', 'badge' => 'fx_rates'],
                ['label' => 'Currency Position', 'route' => 'fg.position', 'perm' => 'forex.view', 'badge' => 'fx_surrender'],
                ['label' => 'Currencies', 'route' => 'masters.index', 'params' => ['currencies'], 'perm' => 'forex.manage'],
                ['label' => 'Foreign Guest Settings', 'route' => 'fg.settings', 'perm' => 'formc.manage'],
            ]],
            ['label' => 'Interfaces', 'icon' => 'phone', 'children' => [
                ['label' => 'Call Accounting', 'route' => 'calls.index', 'perm' => 'calls.view', 'badge' => 'calls_pending'],
                ['label' => 'Key Cards', 'route' => 'keys.index', 'perm' => 'keys.view', 'badge' => 'keys_overdue'],
                ['label' => 'Interface Hub', 'route' => 'interfaces.index', 'perm' => 'interfaces.manage'],
            ]],
        ]],
        // OPERATIONS: Guest Relations & Commercial
        ['label' => 'Guest Relations & Commercial', 'icon' => 'users', 'section' => 'OPERATIONS', 'children' => [
            ['label' => 'Guests & CRM', 'icon' => 'users', 'children' => [
                ['label' => 'Guest Profiles', 'route' => 'guests.index', 'perm' => 'guests.view'],
                ['label' => 'Companies / Agents', 'route' => 'masters.index', 'params' => ['companies'], 'perm' => 'guests.manage'],
                ['label' => 'Rate Contracts', 'route' => 'contracts.index', 'perm' => 'contracts.view', 'badge' => 'contracts_alert'],
                ['label' => 'Feedback & Reputation', 'route' => 'rep.index', 'perm' => 'feedback.view', 'badge' => 'rep_attention'],
                ['label' => 'Guest Cases', 'route' => 'rep.cases.index', 'perm' => 'feedback.cases', 'badge' => 'cases_mine'],
                ['label' => 'Loyalty Program', 'route' => 'loyalty.index', 'perm' => 'guests.view'],
                ['label' => 'Privacy & Data', 'route' => 'privacy.index', 'perm' => 'privacy.view', 'badge' => 'privacy_attention'],
                ['label' => 'Marketing Dashboard', 'route' => 'crm.index', 'perm' => 'crm.view'],
                ['label' => 'Campaigns', 'route' => 'crm.campaigns.index', 'perm' => 'crm.view', 'badge' => 'crm_scheduled'],
                ['label' => 'Guest Segments', 'route' => 'crm.segments.index', 'perm' => 'crm.view'],
                ['label' => 'Automations', 'route' => 'crm.journeys.index', 'perm' => 'crm.view'],
                ['label' => 'Promo Codes', 'route' => 'crm.promos.index', 'perm' => 'crm.view'],
            ]],
            ['label' => 'Sales & Marketing', 'icon' => 'trend', 'children' => [ // v6.1 sales & marketing
                ['label' => 'Sales Dashboard', 'route' => 'sales.index', 'perm' => 'sales.view', 'badge' => 'sales_attention'],
                ['label' => 'Accounts', 'route' => 'sales.accounts', 'perm' => 'sales.view'],
                ['label' => 'Opportunities', 'route' => 'sales.opportunities', 'perm' => 'sales.view'],
                ['label' => 'Sales Diary', 'route' => 'sales.activities', 'perm' => 'sales.view'],
                ['label' => 'Sales Targets', 'route' => 'sales.targets', 'perm' => 'sales.view'],
                ['label' => 'Marketing Calendar', 'route' => 'sales.marketing', 'perm' => 'sales.view'],
                ['label' => 'Marketing Budget', 'route' => 'sales.budget', 'perm' => 'sales.view'],
                ['label' => 'Collateral Library', 'route' => 'sales.collateral', 'perm' => 'sales.view'],
                ['label' => 'Sales Settings', 'route' => 'sales.settings', 'perm' => 'sales.manage'],
            ]],
            ['label' => 'Digital Marketing', 'icon' => 'globe', 'children' => [ // v6.2 digital marketing
                ['label' => 'Digital Dashboard', 'route' => 'digital.index', 'perm' => 'digital.view', 'badge' => 'digital_attention'],
                ['label' => 'Content Calendar', 'route' => 'digital.calendar', 'perm' => 'digital.view'],
                ['label' => 'Paid Campaigns', 'route' => 'digital.campaigns', 'perm' => 'digital.view'],
                ['label' => 'Tracked Links', 'route' => 'digital.links', 'perm' => 'digital.view'],
                ['label' => 'Channels & Listings', 'route' => 'digital.channels', 'perm' => 'digital.view'],
                ['label' => 'Influencers', 'route' => 'digital.influencers', 'perm' => 'digital.view'],
                ['label' => 'Digital Settings', 'route' => 'digital.settings', 'perm' => 'digital.manage'],
            ]],
            ['label' => 'Business Development', 'icon' => 'trend', 'children' => [ // v6.3 business development
                ['label' => 'BD Dashboard', 'route' => 'bd.index', 'perm' => 'bd.view', 'badge' => 'bd_attention'],
                ['label' => 'Competitive Set', 'route' => 'bd.compset', 'perm' => 'bd.view'],
                ['label' => 'Market Mix', 'route' => 'bd.market', 'perm' => 'bd.view'],
                ['label' => 'Partnerships', 'route' => 'bd.partners', 'perm' => 'bd.view'],
                ['label' => 'Initiatives', 'route' => 'bd.initiatives', 'perm' => 'bd.view'],
                ['label' => 'BD Settings', 'route' => 'bd.settings', 'perm' => 'bd.manage'],
            ]],
            ['label' => 'Gift Vouchers', 'icon' => 'star', 'children' => [
                ['label' => 'Vouchers', 'route' => 'vouchers.index', 'perm' => 'vouchers.view', 'badge' => 'gv_expiring'],
                ['label' => 'Sell Voucher', 'route' => 'vouchers.index', 'params' => ['tab' => 'sell'], 'perm' => 'vouchers.sell'],
                ['label' => 'Check / Redeem', 'route' => 'vouchers.index', 'params' => ['tab' => 'lookup'], 'perm' => 'vouchers.redeem'],
                ['label' => 'Voucher Types & Campaigns', 'route' => 'vouchers.index', 'params' => ['tab' => 'types'], 'perm' => 'vouchers.manage'],
            ]],
            ['label' => 'Partner Portal', 'icon' => 'building', 'children' => [ // v7.4 corporate & travel-agent extranet
                ['label' => 'Partner Dashboard', 'route' => 'partner-admin.dashboard', 'perm' => 'partner.view'],
                ['label' => 'Requests & Approvals', 'route' => 'partner-admin.requests', 'perm' => 'partner.view', 'badge' => 'partner_pending'],
                ['label' => 'Partner Accounts', 'route' => 'partner-admin.accounts', 'perm' => 'partner.view'],
                ['label' => 'Portal Activity', 'route' => 'partner-admin.activity', 'perm' => 'partner.view'],
                ['label' => 'Portal Settings', 'route' => 'partner-admin.settings', 'perm' => 'partner.manage'],
            ]],
            ['label' => 'Organiser Portal', 'icon' => 'calendar', 'children' => [ // v7.5 event organiser extranet
                ['label' => 'Organiser Dashboard', 'route' => 'organiser-admin.dashboard', 'perm' => 'organiser.view'],
                ['label' => 'Requests & Approvals', 'route' => 'organiser-admin.requests', 'perm' => 'organiser.view', 'badge' => 'organiser_pending'],
                ['label' => 'Portal Events', 'route' => 'organiser-admin.events', 'perm' => 'organiser.view'],
                ['label' => 'Organiser Logins', 'route' => 'organiser-admin.organisers', 'perm' => 'organiser.view'],
                ['label' => 'Portal Activity', 'route' => 'organiser-admin.activity', 'perm' => 'organiser.view'],
                ['label' => 'Portal Settings', 'route' => 'organiser-admin.settings', 'perm' => 'organiser.manage'],
            ]],
        ]],
        // FINANCE: Revenue & Financial Management
        ['label' => 'Revenue & Financial Management', 'icon' => 'trend', 'section' => 'FINANCE', 'children' => [
            ['label' => 'Revenue', 'icon' => 'trend', 'children' => [
                ['label' => 'Revenue Dashboard', 'route' => 'revenue.index', 'perm' => 'revenue.view'],
                ['label' => 'Pricing Grid', 'route' => 'revenue.pricing', 'perm' => 'revenue.view'],
                ['label' => 'Pricing Rules', 'route' => 'revenue.rules', 'perm' => 'revenue.view'],
                ['label' => 'Upselling', 'route' => 'upsell.index', 'perm' => 'upsell.view', 'badge' => 'upsell_pending'],
            ]],
            ['label' => 'Billing', 'icon' => 'receipt', 'children' => [
                ['label' => 'Folios', 'route' => 'folios.index', 'perm' => 'folio.view'],
                ['label' => 'Non-Resident Bill', 'route' => 'folios.walkin', 'perm' => 'folio.post'],
                ['label' => 'Invoices', 'route' => 'folios.invoices', 'perm' => 'folio.view'],
                ['label' => 'GRA VAT & E-VAT', 'route' => 'gst.index', 'perm' => 'gst.view', 'badge' => 'gst_attention'],
                ['label' => 'Online Payments', 'route' => 'payments.index', 'perm' => 'payments.view', 'badge' => 'payments_review'],
                ['label' => 'Gateway Payouts', 'route' => 'payments.payouts', 'perm' => 'payments.settle'],
            ]],
            ['label' => 'Cashiering', 'icon' => 'money', 'children' => [
                ['label' => 'Cashier Dashboard', 'route' => 'shifts.index', 'perm' => 'folio.shift'],
                ['label' => 'Shift Register', 'route' => 'shifts.index', 'params' => ['tab' => 'history'], 'perm' => 'folio.shift'],
            ]],
            ['label' => 'Income Audit', 'icon' => 'shield', 'children' => [ // v5.3 income audit & revenue integrity
                ['label' => 'Audit Days', 'route' => 'audit.index', 'perm' => 'income_audit.view', 'badge' => 'ia_attention'],
                ['label' => 'Authorisations', 'route' => 'audit.auth', 'perm' => 'income_audit.request', 'badge' => 'ia_auth'],
                ['label' => 'Room Count', 'route' => 'audit.count', 'perm' => 'income_audit.count'],
                ['label' => 'Room Discrepancies', 'route' => 'audit.discrepancies', 'perm' => 'income_audit.authorise'],
            ]],
            ['label' => 'Accounts', 'icon' => 'wallet', 'children' => [
                ['label' => 'Vouchers', 'route' => 'accounts.vouchers', 'perm' => 'accounts.view'],
                ['label' => 'City Ledger', 'route' => 'accounts.cityledger', 'perm' => 'accounts.view'],
                ['label' => 'Credit Control', 'route' => 'credit.index', 'perm' => 'credit.view', 'badge' => 'credit_hold'],
                ['label' => 'Agent Commissions', 'route' => 'accounts.commissions', 'perm' => 'accounts.view'],
                ['label' => 'Supplier Bills (AP)', 'route' => 'ap.bills', 'perm' => 'ap.view'],
                ['label' => 'Supplier Payments', 'route' => 'ap.payments', 'perm' => 'ap.view'],
                ['label' => 'Withholding Tax (GRA)', 'route' => 'ap.wht', 'perm' => 'ap.view'], // v4.4
                ['label' => 'AP Ageing', 'route' => 'ap.ageing', 'perm' => 'ap.view'],
                ['label' => 'Cash Book', 'route' => 'accounts.cashbook', 'perm' => 'accounts.view'],
                ['label' => 'Bank Reconciliation', 'route' => 'bankrec.index', 'perm' => 'bankrec.view', 'badge' => 'bankrec_attention'], // v4.6
                ['label' => 'Petty Cash', 'route' => 'petty.index', 'perm' => 'petty.view', 'badge' => 'petty_attention'], // v4.7
                ['label' => 'Expense Claims', 'route' => 'claims.index', 'perm' => 'claims.approve,claims.pay', 'badge' => 'claims_attention'],
                ['label' => 'Profit & Loss (operational)', 'route' => 'accounts.pnl', 'perm' => 'accounts.view'],
                ['label' => 'General Ledger', 'route' => 'gl.index', 'perm' => 'gl.view'],
                ['label' => 'Budgets', 'route' => 'budgets.index', 'perm' => 'budget.view', 'badge' => 'budget_pending'],
                ['label' => 'Budget vs Actual', 'route' => 'budgets.bva', 'perm' => 'budget.view'],
                ['label' => 'Journal Entries', 'route' => 'gl.journals', 'perm' => 'gl.view'],
                ['label' => 'Period Close', 'route' => 'close.index', 'perm' => 'close.view', 'badge' => 'close_attention'], // v5.6
                ['label' => 'Accruals & Prepayments', 'route' => 'close.accruals', 'perm' => 'close.view'],
                ['label' => 'Recurring Journals', 'route' => 'close.recurring', 'perm' => 'close.view'],
                ['label' => 'Year-end Close', 'route' => 'close.yearend', 'perm' => 'close.view'],
                ['label' => 'Balance Sheet', 'route' => 'reports.show', 'params' => ['balance_sheet'], 'perm' => 'gl.view'],
                ['label' => 'Chart of Accounts', 'route' => 'masters.index', 'params' => ['account-heads'], 'perm' => 'gl.manage'],
                ['label' => 'GL Mapping', 'route' => 'gl.mapping', 'perm' => 'gl.manage'],
            ]],
            ['label' => 'Treasury', 'icon' => 'money', 'children' => [ // v5.7
                ['label' => 'Cash Position', 'route' => 'treasury.index', 'perm' => 'treasury.view', 'badge' => 'treasury_attention'],
                ['label' => 'Banking & Transfers', 'route' => 'treasury.transfers', 'perm' => 'treasury.view'],
                ['label' => 'Cheque Registers', 'route' => 'treasury.cheques', 'perm' => 'treasury.view'],
                ['label' => '13-week Cash Forecast', 'route' => 'treasury.forecast', 'perm' => 'treasury.view'],
                ['label' => 'Cash-flow Statement', 'route' => 'treasury.cashflow', 'perm' => 'treasury.view'],
                ['label' => 'Bank Accounts', 'route' => 'treasury.accounts', 'perm' => 'treasury.view'],
            ]],
            ['label' => 'Owner Reporting', 'icon' => 'building', 'children' => [ // v5.8
                ['label' => 'Owner Dashboard', 'route' => 'owner.index', 'perm' => 'owner.view', 'badge' => 'owner_attention'],
                ['label' => 'USALI Statement', 'route' => 'owner.usali', 'perm' => 'owner.view'],
                ['label' => 'Monthly Owner Packs', 'route' => 'owner.statements', 'perm' => 'owner.view'],
                ['label' => 'Management Fees', 'route' => 'owner.fees', 'perm' => 'owner.view'],
                ['label' => 'FF&E Reserve & Capex', 'route' => 'owner.reserve', 'perm' => 'owner.view'],
                ['label' => 'Agreement & Mapping', 'route' => 'owner.setup', 'perm' => 'owner.manage'],
            ]],
        ]],
        // OPERATIONS: Housekeeping & Laundry
        ['label' => 'Housekeeping & Laundry', 'icon' => 'broom', 'section' => 'OPERATIONS', 'children' => [
            ['label' => 'Housekeeping', 'icon' => 'broom', 'children' => [
                ['label' => 'Room Status', 'route' => 'housekeeping.index', 'perm' => 'housekeeping.view'],
                ['label' => 'HK Tasks & Inspection', 'route' => 'housekeeping.tasks', 'perm' => 'housekeeping.view'],
                ['label' => 'My Rooms (mobile)', 'route' => 'hk.my', 'perm' => 'housekeeping.attendant'],
                ['label' => 'Minibar Items', 'route' => 'masters.index', 'params' => ['minibar-items'], 'perm' => 'masters.manage'],
                ['label' => 'Maintenance', 'route' => 'maintenance.index', 'perm' => 'maintenance.manage', 'badge' => 'pm_overdue'],
            ]],
            ['label' => 'Laundry & Linen', 'icon' => 'shirt', 'children' => [
                ['label' => 'Laundry Orders', 'route' => 'laundry.index', 'perm' => 'laundry.view', 'badge' => 'laundry_due'],
                ['label' => 'Laundry Price List', 'route' => 'laundry.prices', 'perm' => 'laundry.view'],
                ['label' => 'Linen Stock & Par', 'route' => 'linen.index', 'perm' => 'laundry.view'],
                ['label' => 'Linen Batches', 'route' => 'linen.batches', 'perm' => 'laundry.view'],
            ]],
        ]],
        // OPERATIONS: Food & Beverage
        ['label' => 'Food & Beverage', 'icon' => 'cup', 'section' => 'OPERATIONS', 'children' => [
            ['label' => 'POS / Restaurant', 'icon' => 'cup', 'children' => [
                ['label' => 'POS Terminal', 'route' => 'pos.index', 'perm' => 'pos.view'],
                ['label' => 'Orders & Bills', 'route' => 'pos.orders', 'perm' => 'pos.view'],
                ['label' => 'Floor Plan & Waitlist', 'route' => 'tb.floor', 'perm' => 'tables.view', 'badge' => 'tables_attention'],
                ['label' => 'Table Reservations', 'route' => 'tb.index', 'perm' => 'tables.view'],
                ['label' => 'Kitchen Display', 'route' => 'kitchen.index', 'perm' => 'pos.kitchen'],
                ['label' => 'KOT Printers', 'route' => 'pos.printers', 'perm' => 'pos.printers'],
                ['label' => 'Print Station', 'route' => 'kitchen.station', 'perm' => 'pos.kitchen'],
                ['label' => 'Menu Items', 'route' => 'masters.index', 'params' => ['menu-items'], 'perm' => 'masters.manage'],
                ['label' => 'Menu Categories', 'route' => 'masters.index', 'params' => ['menu-categories'], 'perm' => 'masters.manage'],
                ['label' => 'Outlets', 'route' => 'masters.index', 'params' => ['outlets'], 'perm' => 'masters.manage'],
                ['label' => 'Tables', 'route' => 'masters.index', 'params' => ['pos-tables'], 'perm' => 'masters.manage'],
                ['label' => 'Table QR Codes', 'route' => 'pos.qr', 'perm' => 'pos.view'],
                ['label' => 'Modifiers & Add-ons', 'route' => 'pos.modifiers', 'perm' => 'masters.manage'],
                ['label' => 'Reservation Settings', 'route' => 'tb.settings', 'perm' => 'tables.manage'],
            ]],
            ['label' => 'Packages & Meals', 'icon' => 'coffee', 'children' => [ // v5.5 rate packages & meal plans
                ['label' => 'Package Dashboard', 'route' => 'packages.index', 'perm' => 'packages.view'],
                ['label' => 'Meal Desk', 'route' => 'packages.desk', 'perm' => 'packages.redeem', 'badge' => 'pkg_desk'],
                ['label' => 'Entitlements', 'route' => 'packages.register', 'perm' => 'packages.view'],
                ['label' => 'Catalogue & Inclusions', 'route' => 'packages.catalogue', 'perm' => 'packages.view'],
            ]],
            ['label' => 'Banquet', 'icon' => 'star', 'children' => [
                ['label' => 'Events & Enquiries', 'route' => 'banquet.index', 'perm' => 'banquet.view', 'badge' => 'banquet_action'],
                ['label' => 'Function Diary', 'route' => 'banquet.diary', 'perm' => 'banquet.view'],
                ['label' => 'Function Sheet (BEO)', 'route' => 'banquet.sheet', 'perm' => 'banquet.view'],
                ['label' => 'Venues', 'route' => 'masters.index', 'params' => ['banquet-halls'], 'perm' => 'banquet.setup'],
                ['label' => 'Menu Packages', 'route' => 'masters.index', 'params' => ['banquet-packages'], 'perm' => 'banquet.setup'],
                ['label' => 'Services & Equipment', 'route' => 'masters.index', 'params' => ['banquet-services'], 'perm' => 'banquet.setup'],
                ['label' => 'Banquet Settings', 'route' => 'banquet.settings', 'perm' => 'banquet.setup'],
            ]],
            ['label' => 'Food Safety', 'icon' => 'thermo', 'children' => [ // v5.0 HACCP
                ['label' => 'Food Safety Dashboard', 'route' => 'fs.index', 'perm' => 'foodsafety.view', 'badge' => 'fs_attention'],
                ['label' => 'Temperature Logs', 'route' => 'fs.temps', 'perm' => 'foodsafety.view'],
                ['label' => 'HACCP Checklists', 'route' => 'fs.checklists', 'perm' => 'foodsafety.view'],
                ['label' => 'Allergen Matrix', 'route' => 'fs.allergens', 'perm' => 'foodsafety.view'],
                ['label' => 'Food Waste Log', 'route' => 'fs.waste', 'perm' => 'foodsafety.view'],
                ['label' => 'Prep Labels', 'route' => 'fs.labels', 'perm' => 'foodsafety.record'],
                ['label' => 'Food Handler Certificates', 'route' => 'fs.certs', 'perm' => 'foodsafety.view'],
            ]],
            ['label' => 'F&B Cost Control', 'icon' => 'trend', 'children' => [ // v6.8 F&B cost control & menu engineering
                ['label' => 'Cost Dashboard', 'route' => 'fbc.index', 'perm' => 'fbc.view', 'badge' => 'fbc_attention'],
                ['label' => 'Recipe Cards & Plate Cost', 'route' => 'fbc.recipes', 'perm' => 'fbc.view'],
                ['label' => 'Menu Engineering', 'route' => 'fbc.menu', 'perm' => 'fbc.view'],
                ['label' => 'Food & Beverage Cost', 'route' => 'fbc.statement', 'perm' => 'fbc.view'],
                ['label' => 'Usage Variance', 'route' => 'fbc.variance', 'perm' => 'fbc.view'],
                ['label' => 'Menu Pricing', 'route' => 'fbc.pricing', 'perm' => 'fbc.view'],
                ['label' => 'Yield Tests', 'route' => 'fbc.yields', 'perm' => 'fbc.view'],
                ['label' => 'Staff Meals', 'route' => 'fbc.meals', 'perm' => 'fbc.view'],
                ['label' => 'Cost Settings', 'route' => 'fbc.settings', 'perm' => 'fbc.price'],
            ]],
            ['label' => 'Inventory', 'icon' => 'box', 'children' => [
                ['label' => 'Stock Summary', 'route' => 'inventory.index', 'perm' => 'inventory.view'],
                ['label' => 'Requisitions', 'route' => 'req.index', 'perm' => 'requisitions.view', 'badge' => 'req_action'],
                ['label' => 'Budgets & Par Levels', 'route' => 'req.setup', 'perm' => 'requisitions.view'],
                ['label' => 'Purchase Orders', 'route' => 'purchases.index', 'perm' => 'inventory.view', 'badge' => 'proc_attention'],
                ['label' => 'RFQs & Quotations', 'route' => 'rfq.index', 'perm' => 'inventory.view'], // v4.8
                ['label' => 'Goods Receipts (GRN)', 'route' => 'grn.index', 'perm' => 'inventory.view'],
                ['label' => 'Supplier Price Lists', 'route' => 'prices.index', 'perm' => 'inventory.view'],
                ['label' => 'Supplier Scorecard', 'route' => 'proc.scorecard', 'perm' => 'inventory.view'],
                ['label' => 'Stock Issue / Adjust', 'route' => 'inventory.movement', 'perm' => 'inventory.manage'],
                ['label' => 'Stock Ledger', 'route' => 'inventory.ledger', 'perm' => 'inventory.view'],
                ['label' => 'Stock Take (Physical Count)', 'route' => 'stocktakes.index', 'perm' => 'inventory.view'],
                ['label' => 'Re-order Suggestions', 'route' => 'inventory.reorder', 'perm' => 'inventory.view'],
                ['label' => 'Recipes (F&B costing)', 'route' => 'recipes.index', 'perm' => 'inventory.manage'],
                ['label' => 'Items', 'route' => 'masters.index', 'params' => ['inventory-items'], 'perm' => 'masters.manage'],
                ['label' => 'Suppliers', 'route' => 'masters.index', 'params' => ['suppliers'], 'perm' => 'masters.manage'],
                ['label' => 'Stores', 'route' => 'masters.index', 'params' => ['stores'], 'perm' => 'masters.manage'],
            ]],
        ]],
        // OPERATIONS: Accommodation & Property
        ['label' => 'Accommodation & Property', 'icon' => 'building', 'section' => 'OPERATIONS', 'children' => [
            ['label' => 'Apartments & Leases', 'icon' => 'building', 'children' => [ // v5.2 serviced apartments & long-stay leases
                ['label' => 'Leasing Dashboard', 'route' => 'leases.index', 'perm' => 'leases.view', 'badge' => 'lease_attention'],
                ['label' => 'Leases', 'route' => 'leases.list', 'perm' => 'leases.view'],
                ['label' => 'Units', 'route' => 'leases.units', 'perm' => 'leases.view'],
                ['label' => 'Billing & Arrears', 'route' => 'leases.billing', 'perm' => 'leases.view'],
                ['label' => 'Meter Readings', 'route' => 'leases.meters', 'perm' => 'leases.view'],
            ]],
            ['label' => 'Fixed Assets', 'icon' => 'layers', 'children' => [
                ['label' => 'Asset Register', 'route' => 'assets.index', 'perm' => 'assets.view'],
                ['label' => 'Depreciation', 'route' => 'assets.depreciation', 'perm' => 'assets.view'],
                ['label' => 'Asset Categories', 'route' => 'assets.categories', 'perm' => 'assets.view'],
                ['label' => 'PM Schedules', 'route' => 'pm.index', 'perm' => 'maintenance.manage'],
            ]],
            ['label' => 'Parking & Valet', 'icon' => 'key', 'children' => [ // v6.6 parking & valet
                ['label' => 'Parking Desk', 'route' => 'parking.index', 'perm' => 'parking.view', 'badge' => 'parking_attention'],
                ['label' => 'Valet Board', 'route' => 'parking.valet', 'perm' => 'parking.view'],
                ['label' => 'Tickets', 'route' => 'parking.sessions', 'perm' => 'parking.view'],
                ['label' => 'Vehicles', 'route' => 'parking.vehicles', 'perm' => 'parking.view'],
                ['label' => 'Permits', 'route' => 'parking.permits', 'perm' => 'parking.view'],
                ['label' => 'Zones', 'route' => 'parking.zones', 'perm' => 'parking.view'],
                ['label' => 'Tariff & Settings', 'route' => 'parking.settings', 'perm' => 'parking.manage'],
            ]],
        ]],
        // OPERATIONS: Facilities & Security
        ['label' => 'Facilities & Security', 'icon' => 'tool', 'section' => 'OPERATIONS', 'children' => [
            ['label' => 'Engineering', 'icon' => 'tool', 'children' => [
                ['label' => 'Utilities & Energy', 'route' => 'utilities.index', 'perm' => 'utilities.view', 'badge' => 'util_attention'],
                ['label' => 'Daily Meter Readings', 'route' => 'utilities.index', 'params' => ['tab' => 'readings'], 'perm' => 'utilities.record'],
                ['label' => 'Licences & Compliance', 'route' => 'compliance.index', 'perm' => 'compliance.view', 'badge' => 'comp_attention'],
            ]],
            ['label' => 'Security', 'icon' => 'shield', 'children' => [
                ['label' => 'Visitors', 'route' => 'security.visitors', 'perm' => 'security.view', 'badge' => 'sec_visitors'],
                ['label' => 'Gate Passes', 'route' => 'security.gp.index', 'perm' => 'security.view,security.gatepass', 'badge' => 'sec_gatepass'],
                ['label' => 'Incident Register', 'route' => 'security.incidents', 'perm' => 'security.view,security.incidents', 'badge' => 'sec_incidents'],
                ['label' => 'Watch List', 'route' => 'security.visitors', 'params' => ['tab' => 'watchlist'], 'perm' => 'security.manage'],
            ]],
            ['label' => 'Transport', 'icon' => 'car', 'children' => [
                ['label' => 'Dispatch Board', 'route' => 'tr.index', 'perm' => 'transport.view', 'badge' => 'transport_attention'],
                ['label' => 'Trips', 'route' => 'tr.trips', 'perm' => 'transport.view'],
                ['label' => 'My Trips (driver)', 'route' => 'tr.my', 'perm' => 'transport.drive'],
                ['label' => 'Fleet & Papers', 'route' => 'tr.fleet', 'perm' => 'transport.view'],
                ['label' => 'Vehicle Expenses', 'route' => 'tr.expenses', 'perm' => 'transport.view'],
                ['label' => 'Vehicles', 'route' => 'masters.index', 'params' => ['vehicles'], 'perm' => 'transport.manage'],
                ['label' => 'Drivers', 'route' => 'masters.index', 'params' => ['drivers'], 'perm' => 'transport.manage'],
                ['label' => 'Tariff Card', 'route' => 'masters.index', 'params' => ['transport-tariffs'], 'perm' => 'transport.manage'],
                ['label' => 'Transport Settings', 'route' => 'tr.settings', 'perm' => 'transport.manage'],
            ]],
        ]],
        // OPERATIONS: Wellness & Recreation
        ['label' => 'Wellness & Recreation', 'icon' => 'spa', 'section' => 'OPERATIONS', 'children' => [
            ['label' => 'Health Club', 'icon' => 'dumbbell', 'children' => [ // v5.1 memberships
                ['label' => 'Club Dashboard', 'route' => 'club.index', 'perm' => 'club.view', 'badge' => 'club_attention'],
                ['label' => 'Check-in Desk', 'route' => 'club.desk', 'perm' => 'club.desk'],
                ['label' => 'Members', 'route' => 'club.members', 'perm' => 'club.view'],
                ['label' => 'Billing & Renewals', 'route' => 'club.billing', 'perm' => 'club.view'],
                ['label' => 'Class Timetable', 'route' => 'club.classes', 'perm' => 'club.view'],
                ['label' => 'Day Passes', 'route' => 'club.passes', 'perm' => 'club.view'],
                ['label' => 'Plans & Settings', 'route' => 'club.plans', 'perm' => 'club.view'],
            ]],
            ['label' => 'Recreation Outlet', 'icon' => 'spa', 'children' => [ // v7.38 POS ticketing for walk-ins
                ['label' => 'Ticket Outlet', 'route' => 'recreation.outlet', 'perm' => 'club.desk'],
                ['label' => 'Ticket Sales', 'route' => 'recreation.sales', 'perm' => 'club.view'],
                ['label' => 'Ticket Types', 'route' => 'recreation.types', 'perm' => 'club.manage'],
            ]],
            ['label' => 'Spa & Activities', 'icon' => 'spa', 'children' => [
                ['label' => 'Appointments', 'route' => 'spa.index', 'perm' => 'spa.view'],
                ['label' => 'Services & Activities', 'route' => 'masters.index', 'params' => ['spa-services'], 'perm' => 'masters.manage'],
                ['label' => 'Treatment Rooms', 'route' => 'masters.index', 'params' => ['spa-rooms'], 'perm' => 'masters.manage'],
            ]],
        ]],
        // PEOPLE: HR & Workforce
        ['label' => 'HR & Workforce', 'icon' => 'users', 'section' => 'PEOPLE', 'children' => [
            ['label' => 'Talent Management', 'icon' => 'users', 'children' => [ // v6.9 recruitment, onboarding, appraisals, discipline, exits
                ['label' => 'Talent Dashboard', 'route' => 'talent.index', 'perm' => 'talent.view', 'badge' => 'talent_attention'],
                ['label' => 'Vacancies', 'route' => 'talent.vacancies', 'perm' => 'talent.view'],
                ['label' => 'Applicants', 'route' => 'talent.applicants', 'perm' => 'talent.recruit'],
                ['label' => 'New Starters', 'route' => 'talent.starters', 'perm' => 'talent.recruit,talent.appraise'],
                ['label' => 'Appraisals', 'route' => 'talent.appraisals', 'perm' => 'talent.appraise'],
                ['label' => 'Discipline & Grievances', 'route' => 'talent.cases', 'perm' => 'talent.discipline'],
                ['label' => 'Exits & Turnover', 'route' => 'talent.exits', 'perm' => 'talent.discipline'],
                ['label' => 'Establishment', 'route' => 'talent.establishment', 'perm' => 'talent.view'],
                ['label' => 'Talent Settings', 'route' => 'talent.settings', 'perm' => 'talent.manage'],
            ]],
            ['label' => 'HR & Payroll', 'icon' => 'id', 'children' => [
                ['label' => 'Employees', 'route' => 'masters.index', 'params' => ['employees'], 'perm' => 'hr.manage'],
                ['label' => 'Attendance', 'route' => 'hr.attendance', 'perm' => 'hr.manage'],
                ['label' => 'Monthly Register', 'route' => 'hr.register', 'perm' => 'hr.manage'],
                ['label' => 'Public Holidays', 'route' => 'hr.holidays', 'perm' => 'hr.manage,roster.view,payroll.view'], // v7.43 Act 601 / Act 1142
                ['label' => 'Leave Requests', 'route' => 'leave.index', 'perm' => 'hr.leave', 'badge' => 'leave_pending'],
                ['label' => 'Duty Roster', 'route' => 'roster.index', 'perm' => 'roster.view', 'badge' => 'swap_pending'], // v4.9
                ['label' => 'Time Clock', 'route' => 'clock.index', 'perm' => 'roster.view', 'badge' => 'timeclock_attention'],
                ['label' => 'Time Clock Kiosk', 'route' => 'clock.kiosk', 'perm' => 'timeclock.kiosk'],
                ['label' => 'Service Charge & Tips', 'route' => 'sc.index', 'perm' => 'servicecharge.manage,servicecharge.approve', 'badge' => 'sc_attention'],
                ['label' => 'Payroll Runs', 'route' => 'payroll.index', 'perm' => 'payroll.view'],
                ['label' => 'Salary Advances', 'route' => 'payroll.advances', 'perm' => 'payroll.view'],
                ['label' => 'Statutory Returns', 'route' => 'payroll.statutory', 'perm' => 'payroll.view', 'badge' => 'stat_overdue'],
                ['label' => 'Pay Components', 'route' => 'payroll.components', 'perm' => 'payroll.manage'],
                ['label' => 'Leave Types', 'route' => 'masters.index', 'params' => ['leave-types'], 'perm' => 'hr.manage'],
                ['label' => 'Departments', 'route' => 'masters.index', 'params' => ['departments'], 'perm' => 'hr.manage'],
                ['label' => 'My Payslips & Leave', 'route' => 'me.hr', 'perm' => 'hr.self', 'employee' => true],
                ['label' => 'My Roster & Time Clock', 'route' => 'me.roster', 'perm' => 'hr.self', 'employee' => true], // v4.9
                ['label' => 'My Training & SOPs', 'route' => 'me.learning', 'perm' => 'hr.self', 'employee' => true], // v6.0
                ['label' => 'My Appraisal', 'route' => 'talent.me', 'perm' => 'hr.self', 'employee' => true], // v6.9
                ['label' => 'My Expense Claims', 'route' => 'claims.mine', 'perm' => 'claims.submit', 'employee' => true], // v4.7
            ]],
            ['label' => 'Learning & SOPs', 'icon' => 'book', 'children' => [ // v6.0 learning & development
                ['label' => 'L&D Dashboard', 'route' => 'learning.index', 'perm' => 'learning.view', 'badge' => 'learning_attention'],
                ['label' => 'Training Matrix', 'route' => 'learning.matrix', 'perm' => 'learning.view'],
                ['label' => 'Training Sessions', 'route' => 'learning.sessions', 'perm' => 'learning.view'],
                ['label' => 'Training Records', 'route' => 'learning.records', 'perm' => 'learning.view'],
                ['label' => 'Courses & Requirements', 'route' => 'learning.courses', 'perm' => 'learning.view'],
                ['label' => 'SOP Library', 'route' => 'learning.sops', 'perm' => 'learning.view'],
                ['label' => 'L&D Settings', 'route' => 'learning.settings', 'perm' => 'learning.manage'],
            ]],
        ]],
        // GOVERNANCE: Quality, Safety & Sustainability
        ['label' => 'Quality, Safety & Sustainability', 'icon' => 'shield', 'section' => 'GOVERNANCE', 'children' => [
            ['label' => 'Quality Assurance', 'icon' => 'check', 'children' => [ // v6.5 quality assurance & brand standards
                ['label' => 'QA Dashboard', 'route' => 'qa.index', 'perm' => 'qa.view', 'badge' => 'qa_attention'],
                ['label' => 'Audits', 'route' => 'qa.audits', 'perm' => 'qa.view'],
                ['label' => 'Corrective Actions', 'route' => 'qa.actions', 'perm' => 'qa.view'],
                ['label' => 'Standards', 'route' => 'qa.standards', 'perm' => 'qa.view'],
                ['label' => 'Checklists', 'route' => 'qa.templates', 'perm' => 'qa.view'],
                ['label' => 'QA Settings', 'route' => 'qa.settings', 'perm' => 'qa.manage'],
            ]],
            ['label' => 'Health & Safety', 'icon' => 'shield', 'children' => [ // v6.7 health, safety & fire
                ['label' => 'HSE Dashboard', 'route' => 'hse.index', 'perm' => 'hse.view', 'badge' => 'hse_attention'],
                ['label' => 'Fire Equipment', 'route' => 'hse.equipment', 'perm' => 'hse.view'],
                ['label' => 'Inspection Round', 'route' => 'hse.round', 'perm' => 'hse.check'],
                ['label' => 'Permits to Work', 'route' => 'hse.permits', 'perm' => 'hse.view'],
                ['label' => 'Accidents & Near Misses', 'route' => 'hse.accidents', 'perm' => 'hse.view'],
                ['label' => 'Risk Assessments', 'route' => 'hse.risks', 'perm' => 'hse.view'],
                ['label' => 'Drills & Wardens', 'route' => 'hse.drills', 'perm' => 'hse.view'],
                ['label' => 'HSE Settings', 'route' => 'hse.settings', 'perm' => 'hse.manage'],
            ]],
            ['label' => 'Sustainability & ESG', 'icon' => 'tree', 'children' => [ // v7.0 sustainability & ESG
                ['label' => 'ESG Dashboard', 'route' => 'esg.index', 'perm' => 'esg.view', 'badge' => 'esg_attention'],
                ['label' => 'Carbon & HCMI', 'route' => 'esg.carbon', 'perm' => 'esg.view'],
                ['label' => 'Waste', 'route' => 'esg.waste', 'perm' => 'esg.view'],
                ['label' => 'Refrigerants (F-gas)', 'route' => 'esg.refrigerants', 'perm' => 'esg.view'],
                ['label' => 'Green Stay', 'route' => 'esg.green', 'perm' => 'esg.view'],
                ['label' => 'Targets', 'route' => 'esg.targets', 'perm' => 'esg.view'],
                ['label' => 'Initiatives', 'route' => 'esg.initiatives', 'perm' => 'esg.view'],
                ['label' => 'People & Community', 'route' => 'esg.social', 'perm' => 'esg.view'],
                ['label' => 'Certification', 'route' => 'esg.certification', 'perm' => 'esg.view'],
                ['label' => 'Policies', 'route' => 'esg.policies', 'perm' => 'esg.view'],
                ['label' => 'Carbon Statements', 'route' => 'esg.statements', 'perm' => 'esg.view'],
                ['label' => 'Annual ESG Report', 'route' => 'esg.report', 'perm' => 'esg.view'],
                ['label' => 'ESG Settings', 'route' => 'esg.settings', 'perm' => 'esg.manage'],
            ]],
        ]],
        // GOVERNANCE: Administration & Configuration
        ['label' => 'Administration & Configuration', 'icon' => 'shield', 'section' => 'GOVERNANCE', 'children' => [
            ['label' => 'Masters', 'icon' => 'layers', 'children' => [
                ['label' => 'Room Types', 'route' => 'masters.index', 'params' => ['room-types'], 'perm' => 'masters.manage'],
                ['label' => 'Rooms', 'route' => 'masters.index', 'params' => ['rooms'], 'perm' => 'masters.manage'],
                ['label' => 'Floors', 'route' => 'masters.index', 'params' => ['floors'], 'perm' => 'masters.manage'],
                ['label' => 'Rate Plans', 'route' => 'masters.index', 'params' => ['rate-plans'], 'perm' => 'masters.manage'],
                ['label' => 'Taxes', 'route' => 'masters.index', 'params' => ['taxes'], 'perm' => 'masters.manage'],
                ['label' => 'Business Sources', 'route' => 'masters.index', 'params' => ['business-sources'], 'perm' => 'masters.manage'],
                ['label' => 'Payment Modes', 'route' => 'masters.index', 'params' => ['payment-modes'], 'perm' => 'masters.manage'],
                ['label' => 'Item Categories', 'route' => 'masters.index', 'params' => ['item-categories'], 'perm' => 'masters.manage'],
            ]],
            ['label' => 'Administration', 'icon' => 'shield', 'children' => [
                ['label' => 'Users', 'route' => 'users.index', 'perm' => 'users.manage'],
                ['label' => 'Roles & Permissions', 'route' => 'roles.index', 'perm' => 'users.manage'],
                ['label' => 'Hotel Settings', 'route' => 'settings.index', 'perm' => 'settings.manage'],
                ['label' => 'Activity Log', 'route' => 'logs.index', 'perm' => 'logs.view'],
                ['label' => 'E-mail Log', 'route' => 'emails.index', 'perm' => 'logs.view'],
                ['label' => 'SMS / WhatsApp Settings', 'route' => 'messages.settings', 'perm' => 'settings.manage'],
                ['label' => 'Online Payment Settings', 'route' => 'payments.settings', 'perm' => 'payments.settings'],
                ['label' => 'SMS / WhatsApp Log', 'route' => 'messages.index', 'perm' => 'logs.view'],
                ['label' => 'API & Webhooks', 'route' => 'developers.index', 'perm' => 'api.view', 'badge' => 'api_attention'],
                ['label' => 'Database Backup', 'route' => 'settings.backup', 'perm' => 'settings.manage'],
                ['label' => 'Data Migration', 'route' => 'migration.index', 'perm' => 'settings.manage'],
            ]],
        ]],
    ],

    'charge_categories' => [
        'room' => 'Room Tariff', 'extra_bed' => 'Extra Bed / Person', 'fnb' => 'Food & Beverage', 'laundry' => 'Laundry',
        'telephone' => 'Telephone', 'minibar' => 'Minibar', 'transport' => 'Transport / Pickup', 'spa' => 'Spa',
        'banquet' => 'Banquet', 'parking' => 'Parking & Valet', 'pay_tv' => 'Pay TV / Movies', 'damage' => 'Damages', 'cancellation' => 'Cancellation / No-show Fee', 'misc' => 'Miscellaneous',
        'excursion' => 'Excursions & Tickets (agency)', // v5.9: sold for activity vendors – not hotel revenue
    ],

    'id_types' => ['ghana_card' => 'Ghana Card', 'passport' => 'Passport', 'driving_licence' => 'Driving Licence', 'voter_id' => 'Voter ID', 'ecowas_card' => 'ECOWAS ID card', 'national_id' => 'National ID (other country)', 'aadhaar' => 'Aadhaar (India)', 'pan' => 'PAN card (India)', 'other' => 'Other'], // v4.4: Ghana Card first; Indian keys kept for existing records
    'meal_plans' => ['EP' => 'EP – Room Only', 'CP' => 'CP – With Breakfast', 'MAP' => 'MAP – Breakfast + 1 Meal', 'AP' => 'AP – All Meals'],
];
