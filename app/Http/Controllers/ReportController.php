<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\BanquetEvent;
use App\Models\Folio;
use App\Models\FolioTransaction;
use App\Models\Guest;
use App\Models\MaintenanceRequest;
use App\Models\NightAudit;
use App\Models\PosOrder;
use App\Models\PosOrderItem;
use App\Models\PosPayment;
use App\Models\PurchaseOrder;
use App\Models\Reservation;
use App\Models\ReservationRoom;
use App\Models\Room;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Voucher;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Config-driven report engine. Every report returns columns + rows and is
 * rendered by one view with print and CSV export.
 */
class ReportController extends Controller
{
    /** slug => [group, title, description, filter mode] */
    public const REPORTS = [
        'arrivals' => ['Front Office', 'Arrival List', 'Expected arrivals for the period', 'range'],
        'departures' => ['Front Office', 'Departure List', 'Expected departures for the period', 'range'],
        'inhouse' => ['Front Office', 'In-House Guest List', 'All guests currently checked in', 'none'],
        'reservations' => ['Front Office', 'Reservation Register', 'Reservations created in the period', 'range'],
        'cancellations' => ['Front Office', 'Cancellation & No-Show', 'Cancelled and no-show reservations', 'range'],
        'occupancy' => ['Front Office', 'Occupancy Statistics', 'Day-wise occupancy, ADR and RevPAR', 'range'],
        'source' => ['Front Office', 'Business Source Analysis', 'Room nights & revenue by source', 'range'],
        'company' => ['Front Office', 'Company-wise Business', 'Room nights & revenue by company/agent', 'range'],
        'nationality' => ['Front Office', 'Nationality Report', 'Guest arrivals by nationality', 'range'],
        'police' => ['Front Office', 'Police / Form-C Report', 'Foreign national check-ins', 'range'],
        'room_status' => ['Housekeeping', 'Room Status Report', 'Current occupancy and housekeeping status', 'none'],
        'maintenance' => ['Housekeeping', 'Maintenance Report', 'Tickets raised in the period', 'range'],
        'guest_requests' => ['Front Office', 'Guest Requests & Response Times', 'Portal / phone requests by type: volumes, response & completion times, SLA breaches, ratings', 'range'],
        'hk_productivity' => ['Housekeeping', 'Attendant Productivity', 'Rooms, credits, minutes per room, DND and re-cleans per attendant', 'range'],
        'minibar' => ['Housekeeping', 'Minibar Sales', 'Minibar consumption posted to guest folios', 'range'],
        'spa_revenue' => ['Spa & Activities', 'Spa & Activity Revenue', 'Appointments and revenue by service and therapist', 'range'],
        'revenue' => ['Billing', 'Daily Revenue Report', 'Revenue by charge category', 'range'],
        'collection' => ['Billing', 'Collection Report', 'Payments by mode and cashier', 'range'],
        'tax' => ['Billing', 'Tax (VAT & Levies) Report', 'Taxable value with VAT, NHIL, GETFund and Tourism Levy by category and rate', 'range'],
        'invoices' => ['Billing', 'Invoice Register', 'Closed folios / invoices', 'range'],
        'online_payments' => ['Billing', 'Online Payments', 'Payment links paid, refunded, pending or expired in the period, with gateway transaction, fees and payouts', 'range', 'payments.view'],
        'laundry_register' => ['Laundry & Linen', 'Laundry Register', 'Guest & walk-in laundry orders collected in the period: pieces, express surcharge, amount, status and where it was charged', 'range', 'laundry.view'],
        'linen_par' => ['Laundry & Linen', 'Linen Stock & Par', 'Every linen type by location (clean, floors, soiled, at laundry) against par, with shortfall and stock value', 'none', 'laundry.view'],
        'linen_losses' => ['Laundry & Linen', 'Linen Losses & Write-offs', 'Linen discarded as damaged or lost (on the floors or by the laundry) in the period, at cost', 'range', 'laundry.view'],
        'asset_register' => ['Fixed Assets', 'Fixed Asset Register', 'Every asset held at a date: category, location, cost, accumulated depreciation and net book value', 'date', 'assets.view'],
        'asset_schedule' => ['Fixed Assets', 'Fixed Asset Schedule (Roll-forward)', 'Per asset for the period: opening cost, additions, disposals, depreciation charge and closing net book value – ties to the balance sheet', 'range', 'assets.view'],
        'pm_compliance' => ['Fixed Assets', 'Preventive Maintenance Compliance', 'PM tickets that fell due in the period: done on time, late or still open, with days late and technician', 'range', 'maintenance.manage'],
        'req_register' => ['Inventory', 'Requisition Register', 'Store requisitions raised in the period: department, status, requested / approved / issued value, fill rate and turnaround', 'range', 'requisitions.view'],
        'dept_consumption' => ['Inventory', 'Department Consumption vs Budget', 'Stock issued to each department in the period (expensed, transferred to its store, ad-hoc) against its pro-rated budget, with cost per occupied room', 'range', 'requisitions.view'],
        'req_pending' => ['Inventory', 'Outstanding Requisition Lines', 'Approved quantities not yet issued: balance, stock in the issuing store and age – the store\'s to-do list', 'none', 'requisitions.view'],
        'voids' => ['Billing', 'Void & Discount Report', 'Voided transactions and discounts (audit)', 'range'],
        'outstanding' => ['Billing', 'Guest Ledger / Outstanding', 'Open folios with balances', 'none'],
        'pos_sales' => ['POS', 'Outlet Sales Summary', 'Bills, covers and sales by outlet', 'range'],
        'pos_items' => ['POS', 'Item-wise Sales', 'Quantity and value by menu item', 'range'],
        'pos_bills' => ['POS', 'Bill Register', 'All settled / void bills', 'range'],
        'pos_payments' => ['POS', 'POS Tender Register', 'Every POS payment and refund by tender, reference, cashier and bill', 'range', 'pos.view'],
        'pos_comps' => ['POS', 'Complimentary / Non-Chargeable Items', 'Every complimentary POS item in the period, including item-level comps and whole-bill non-chargeable settlements, with value, reason, authorizer and bill context', 'range', 'pos.view'],
        // v3.2 restaurant table reservations
        'table_bookings' => ['POS', 'Table Booking Register', 'Every restaurant booking, walk-in and waitlist party in the period: time, party, table, source, occasion, status, minutes late, time at the table and the bill', 'range', 'tables.view'],
        'table_covers' => ['POS', 'Covers & No-Shows by Day', 'Day by day: bookings, booked / walk-in / waitlist covers seated, no-shows, cancellations, no-show rate and online share', 'range', 'tables.view'],
        'table_turns' => ['POS', 'Table Turns & Utilisation', 'Per table: parties, covers, average party, average time at the table, turns per day, seat utilisation and linked sales', 'range', 'tables.view'],
        'table_waitlist' => ['POS', 'Waitlist Performance', 'Day by day: parties waitlisted, seated and walked away, average quoted and actual wait and the share seated within the quote', 'range', 'tables.view'],
        // v3.3 guest transport & fleet
        'transport_trips' => ['Transport', 'Transfer Register', 'Every pick-up, drop and local transfer in the period: time, guest, route, flight, car, driver, km, minutes late, fare, billing and status', 'range', 'transport.view'],
        'transport_vehicles' => ['Transport', 'Vehicle Utilisation & Running Cost', 'Per vehicle: trips, km driven, hours on the road, revenue, fuel & other running costs, cost per km, km per litre and margin', 'range', 'transport.view'],
        'transport_drivers' => ['Transport', 'Driver Performance', 'Per driver: trips, km, hours on duty, on-time departures, no-shows, revenue and last trip', 'range', 'transport.view'],
        'transport_revenue' => ['Transport', 'Transport Revenue by Day', 'Day by day: pick-ups, drops and transfers, fares to the room bill, direct and complimentary, extras, no-shows and cancellations', 'range', 'transport.view'],
        // v3.4 foreign guests: Form C & forex desk
        'formc_register' => ['Foreign Guests', 'Form C (FRRO) Register', 'Every foreign national who checked in: nationality, passport, visa, room, deadline, when the Form C was filed, FRRO reference, hours taken and the departure report', 'range', 'formc.view'],
        'foreign_arrivals' => ['Foreign Guests', 'Foreign Arrivals by Nationality', 'Per nationality: guests, stays, room nights, average stay, share of foreign arrivals and Form C filed on time', 'range', 'formc.view'],
        'forex_register' => ['Foreign Guests', 'Currency Exchange Register', 'Every foreign currency purchase: guest, passport, currency, amount, rate, cedi value, amount paid, cash or bill credit and voids (RBI money-changing register)', 'range', 'forex.view'],
        'forex_position' => ['Foreign Guests', 'Currency Position & Surrenders', 'Per currency: opening balance, bought, surrendered to the bank, closing balance, cost, realised value and exchange gain / loss', 'range', 'forex.view'],
        'logbook_register' => ['Desk Operations', 'Shift Log Book Register', 'Every log book entry in the period by date and shift: category, priority, department, author, open / resolved, time to resolve and how many staff read it', 'range', 'logbook.view'],
        'wakeup_calls' => ['Desk Operations', 'Wake-up Call Performance', 'Every wake-up call due in the period: room, guest, source, attempts, answered / no answer / missed / cancelled and minutes late for the first attempt', 'range', 'desk.wakeup'],
        'guest_messages' => ['Desk Operations', 'Guest Messages & Parcels', 'Messages and parcels taken for guests: from, channel, how and when the guest was told, delivery time and outcome', 'range', 'desk.messages'],
        'traces_register' => ['Desk Operations', 'Traces Register', 'Traces due in the period by department: text, booking, completed on time, carried forward by the night audit or cancelled', 'range', 'desk.traces'],
        'lost_found_register' => ['Desk Operations', 'Lost & Found Register', 'Items found in the period: item no., category, where found, finder, storage, probable owner and the outcome (returned with ID / courier, disposed with witness)', 'range', 'lostfound.manage'],
        'banquet' => ['Banquet', 'Banquet Event Report', 'Events in the period: functions, venues, peak pax, value, deposits and status', 'range', 'banquet.view'],
        'banquet_pipeline' => ['Banquet', 'Banquet Sales Pipeline', 'Enquiries, tentative holds, definite and lost business by event month – count, value and conversion', 'range', 'banquet.view'],
        'venue_utilisation' => ['Banquet', 'Venue Utilisation', 'Per venue: functions, days and hours booked, occupancy of a 16-hour day, covers and revenue', 'range', 'banquet.view'],
        'function_forecast' => ['Banquet', 'Function Forecast (Kitchen & Ops)', 'Upcoming tentative and definite functions with covers, menu and set-up – for purchasing and staffing', 'range', 'banquet.view'],
        'crm_campaigns' => ['CRM & Marketing', 'Campaign & Automation Performance', 'Per campaign / automation: recipients, delivered, skipped, clicks, CTR, unsubscribes, attributed bookings, room nights and revenue', 'range', 'crm.view'],
        'crm_promos' => ['CRM & Marketing', 'Promo Code Redemptions', 'Bookings made with each promo code in the period: room nights, revenue after discount, discount given and cancellations', 'range', 'crm.view'],
        'crm_guest_value' => ['CRM & Marketing', 'Guest Value (RFM)', 'Every guest with a stay: recency, frequency and monetary scores, value segment, consent and last stay – who to win back, who to reward', 'none', 'crm.view'],
        'contract_production' => ['Contracts & Credit', 'Contract Production', 'Per rate contract: bookings, room nights and revenue for stays in the period, ADR vs public rate, cancellations and progress against the room-night target', 'range', 'contracts.view'],
        'allotment_utilisation' => ['Contracts & Credit', 'Allotment Utilisation', 'Per allotment line: room nights allotted in the period, picked up, released unsold and still held – is the account using what it asked for?', 'range', 'contracts.view'],
        'sales_pipeline' => ['Sales & Marketing', 'Sales Pipeline', 'Every open opportunity: account, owner, stage, expected close, room nights, value, probability and weighted value – with stage totals', 'none', 'sales.view'], // v6.1
        'sales_win_loss' => ['Sales & Marketing', 'Win / Loss Analysis', 'Opportunities won and lost in the period by type, source and salesperson; win rate, value won and the reasons business was lost', 'range', 'sales.view'],
        'sales_activity' => ['Sales & Marketing', 'Sales Activity Report', 'Completed sales calls, visits, site inspections, meetings and entertainment in the period by salesperson, with spend', 'range', 'sales.view'],
        'account_production' => ['Sales & Marketing', 'Account Production', 'Room nights, stays and room revenue per corporate / agency account for the period vs the same period last year, with on-the-books business and account manager', 'range', 'sales.view'],
        'sales_targets' => ['Sales & Marketing', 'Sales Targets vs Actual', 'Each salesperson per month in the period: room-night, revenue and activity targets against production of their accounts', 'range', 'sales.view'],
        'marketing_roi' => ['Sales & Marketing', 'Marketing Event ROI', 'Marketing events starting in the period: budget, actual cost, leads, cost per lead, opportunities and revenue won, ROI', 'range', 'sales.view'],
        'marketing_budget' => ['Sales & Marketing', 'Marketing Budget vs Actual', 'Marketing budget by line for the months in the period against actual spend and committed (planned) events', 'range', 'sales.view'],
        'digital_social' => ['Digital Marketing', 'Social Media Performance', 'Followers at start / end, growth, reach, impressions, engagements, engagement rate and posts per social channel', 'range', 'digital.view'], // v6.2
        'digital_paid' => ['Digital Marketing', 'Paid Media Performance', 'Paid campaigns: spend vs budget, impressions, clicks, CTR, CPC, platform conversions & ROAS, and bookings / revenue attributed in the PMS', 'range', 'digital.view'],
        'digital_links' => ['Digital Marketing', 'Tracked Link Attribution', 'Clicks, unique visitors, device mix and credited direct bookings, nights and revenue per tracked link and UTM source / medium', 'range', 'digital.view'],
        'digital_content' => ['Digital Marketing', 'Content Calendar & Approvals', 'Posts scheduled in the period with channels, status, approver, approval turnaround, reach and engagement; missed slots highlighted', 'range', 'digital.view'],
        'digital_influencers' => ['Digital Marketing', 'Influencer Collaborations', 'Creator collaborations: fee, complimentary value, reach, engagements, cost per engagement, bookings via link and rating', 'range', 'digital.view'],
        'cro_enquiries' => ['Central Reservations', 'Reservations Enquiry Register', 'Every enquiry logged in the period with the stay asked, channel, agent, value and outcome – booked, referred to a sister hotel, or lost (denial / regret, reason, competitor)', 'range', 'cro.view'],
        'cro_lost_business' => ['Central Reservations', 'Lost Business (Denials & Regrets)', 'Lost enquiries by reason: denials (could not offer) and regrets (guest declined) with count, room nights, value, share, and turnaways kept in the group by referral', 'range', 'cro.view'],
        'cro_demand' => ['Central Reservations', 'Unconstrained Demand', 'By stay date: capacity, rooms on the books, rooms denied and regretted, and occupancy with and without denials – the demand revenue management should price for', 'range', 'cro.view'],
        'cro_agents' => ['Central Reservations', 'CRO Agent Performance', 'Per reservations agent: enquiries, bookings, referrals, denials, regrets, conversion vs target, booked and lost revenue, average call length', 'range', 'cro.view'],
        'cro_referrals' => ['Central Reservations', 'Sister-Hotel Referrals', 'Turnaways referred between the hotels of the group: origin and receiving hotel, guest, stay, reason and room revenue kept in the group', 'range', 'cro.view'],
        'qa_audits' => ['Quality Assurance', 'QA Audit Register', 'Every audit completed in the period with checklist, department, auditor, score against the pass mark, findings (critical / repeat) and whether the department acknowledged it', 'range', 'qa.view'],
        'qa_department_scores' => ['Quality Assurance', 'QA Scores by Department', 'Average audit score per department and checklist type against the pass mark and target, pass rate, findings and open / overdue corrective actions', 'range', 'qa.view'],
        'qa_standard_failures' => ['Quality Assurance', 'Standards Failure Analysis', 'Every standard failed in the period: times checked, times failed, fail rate, repeat findings and critical flag – where to focus training', 'range', 'qa.view'],
        'qa_actions' => ['Quality Assurance', 'Corrective Action Log', 'Corrective actions raised in the period with owner, priority, due date, status, days to close and whether they closed on time or were reopened', 'range', 'qa.view'],
        'qa_template_compliance' => ['Quality Assurance', 'Audit Schedule Compliance', 'Each recurring checklist: audits required by its frequency in the period, audits done, compliance %, average score, last audit and next due date', 'range', 'qa.view'],
        'fbc_recipe_costs' => ['F&B Cost Control', 'Recipe Costing Register', 'Every menu item with price, plate cost at the current costing basis and trim yields, gross profit, cost % against its target, suggested price and recipe-card status', 'none', 'fbc.view'], // v6.8
        'fbc_menu_engineering' => ['F&B Cost Control', 'Menu Engineering Analysis', 'Kasavana & Smith worksheet for the period: items sold, menu mix %, average price, plate cost, contribution margin and class (Star / Plowhorse / Puzzle / Dog) with the action', 'range', 'fbc.view'],
        'fbc_food_cost' => ['F&B Cost Control', 'Daily Food & Beverage Cost Flash', 'Per day: food and beverage revenue, theoretical cost, cost consumed, employee meals and officers\' checks credited, cost of sales and cost % against target', 'range', 'fbc.view'],
        'fbc_variance' => ['F&B Cost Control', 'Ideal vs Actual Usage Variance', 'Per ingredient: ideal usage from portions sold × recipe (grossed up for yield) against consumption, issues, waste and count adjustments from the kitchen, bar and outlet stores, with the value', 'range', 'fbc.view'],
        'fbc_price_changes' => ['F&B Cost Control', 'Menu Price Change Log', 'Menu price changes in the period with old and new price, plate cost at the time, cost % before and after, reason and user', 'range', 'fbc.view'],
        'talent_vacancies' => ['Talent Management', 'Vacancy Register & Time to Fill', 'Vacancies raised in the period: department, posts, salary range, reason, requester and approver, applicants, hires and days from approval to filled', 'range', 'talent.view'], // v6.9
        'talent_pipeline' => ['Talent Management', 'Recruitment Funnel & Sources', 'Applicants received in the period by vacancy: screened, interviewed, offered, hired and rejected, conversion, average interview score, and applications / hires by source', 'range', 'talent.recruit'],
        'talent_appraisals' => ['Talent Management', 'Appraisal Results', 'Appraisals in cycles ending in the period: reviewer, goals and competency scores, overall rating and band, performance improvement plans, sign-off and acknowledgement', 'range', 'talent.appraise'],
        'talent_discipline' => ['Talent Management', 'Discipline & Grievance Register', 'Disciplinary cases and grievances in the period: category, severity, query and hearing dates, finding, sanction and warning expiry (confidential grievances masked unless you may see them)', 'range', 'talent.discipline'],
        'talent_exits' => ['Talent Management', 'Leavers & Turnover', 'Leavers with a last day in the period: type, length of service, statutory notice, pay in lieu, final-pay date, exit-interview reason, regretted and re-hire flags, with turnover rates', 'range', 'talent.discipline'],
        'talent_headcount' => ['Talent Management', 'Headcount vs Establishment', 'Per department on a date: approved establishment, staff in post, gap, open posts, starters and leavers in the last 12 months, turnover and staff on probation', 'date', 'talent.view'],
        'hse_equipment' => ['Health & Safety', 'Fire Equipment Register', 'Every fire and life-safety item with GNFS tag, location, last and next visual check, contractor service due, expiry and state (in order / due / overdue / defective / expired)', 'none', 'hse.view'],
        'hse_checks' => ['Health & Safety', 'Fire Equipment Inspection Log', 'Visual checks and contractor services in the period: item, result, faults found, certificate, engineering ticket and who checked', 'range', 'hse.view'],
        'hse_accidents' => ['Health & Safety', 'Accident & Near-Miss Register', 'Accidents, injuries, near misses and dangerous occurrences with the person, injury, days lost, Act 328 notice and investigation status; LTIFR for the period', 'range', 'hse.view'],
        'esg_carbon' => ['Sustainability & ESG', 'Carbon Inventory by Scope (GHG Protocol / HCMI)', 'Scope 1 fuels and refrigerant leaks, Scope 2 grid electricity, Scope 3 water, waste by route and outsourced laundry: activity, factor and kg CO₂e, with the HCMI footprint per occupied room and per m² of meeting space per hour', 'range', 'esg.view'], // v7.0
        'esg_waste' => ['Sustainability & ESG', 'Waste Register & Diversion', 'Waste consignments in the period: stream, disposal route, weight, department, contractor, waste transfer note, cost and income, kg CO₂e, with the diversion rate and kg per occupied room', 'range', 'esg.view'],
        'esg_refrigerants' => ['Sustainability & ESG', 'F-gas (Refrigerant) Log', 'Refrigerant added and recovered in the period by system: gas, GWP, work done, technician and certificate, kg CO₂e, with the annual leak rate per system', 'range', 'esg.view'],
        'esg_green' => ['Sustainability & ESG', 'Green-Stay Nights', 'Nights on which a green-stay room was not serviced: room, guest, loyalty points and the estimated energy, water and carbon saved, with uptake against occupied room-nights', 'range', 'esg.view'],
        'esg_initiatives' => ['Sustainability & ESG', 'Green Initiative Register', 'Every initiative: category, owner, status, capital cost, annual saving, simple payback, energy / water / waste / carbon savings, approver and completion', 'none', 'esg.view'],
        'esg_local_spend' => ['Sustainability & ESG', 'Local Sourcing Spend', 'Supplier bills in the period by supplier (net of debit notes, before tax) with the local flag and the share of spend with local suppliers', 'range', 'esg.view'],
        'iptv_devices' => ['IPTV & Guest Welcome', 'TV & Screen Register', 'Every in-room TV and lobby screen: room / location, platform, model, MAC, serial, status, last heartbeat, online now, paired date and the guest in the room', 'none', 'iptv.view'], // v7.1
        'iptv_vod' => ['IPTV & Guest Welcome', 'Movie Rentals (Pay TV)', 'Movie rentals in the period: room, guest, title, amount before tax, VAT & levies, total charged to the folio, refunds with reason and approver', 'range', 'iptv.view'],
        'iptv_messages' => ['IPTV & Guest Welcome', 'TV Message & Emergency Log', 'Room messages, broadcasts and emergency alerts sent to TVs in the period: audience, timing, sender, reads, withdrawal / all-clear note', 'range', 'iptv.view'],
        'iptv_engagement' => ['IPTV & Guest Welcome', 'TV Engagement by Day', 'Per day: TVs used, screen actions, welcome screens seen, channel views, movie rentals, dining orders and service requests from the TV, with the share of stays using the TV portal', 'range', 'iptv.view'],
        'iptv_orders' => ['IPTV & Guest Welcome', 'Orders & Requests from the TV', 'In-room dining orders, service requests, express check-outs and wake-up calls guests placed on the room TV, with status and value', 'range', 'iptv.view'],
        'iptv_channels' => ['IPTV & Guest Welcome', 'Channel Line-up & Viewing', 'Every channel: number, category, language, stream, guide id, programmes still ahead in the guide and views in the last 30 days', 'none', 'iptv.view'],
        'kiosk_sessions' => ['Self-Service Kiosk', 'Kiosk Guest Visits', 'Every kiosk visit in the period: kiosk, service (check-in / check-out / walk-in), guest, booking, room, how far it got, time taken, amount paid and outcome', 'range', 'kiosk.view'], // v7.2
        'kiosk_adoption' => ['Self-Service Kiosk', 'Kiosk Adoption by Day', 'Per day: check-ins, how many were done at the kiosk and the share, check-outs at the kiosk, walk-in bookings, visits handed to the desk and average check-in time', 'range', 'kiosk.view'],
        'kiosk_payments' => ['Self-Service Kiosk', 'Kiosk Payments', 'Deposits, walk-in stays and bill balances guests paid by QR at the kiosk: payment link, booking, guest, purpose, amount and gateway status', 'range', 'kiosk.view'],
        'kiosk_assist' => ['Self-Service Kiosk', 'Kiosk Assistance Requests', 'Visits the kiosk handed to the front desk: reason, note, when, how long until someone dealt with it and who – with a summary by reason', 'range', 'kiosk.view'],
        'inbox_conversations' => ['Guest Messaging', 'Guest Conversations', 'Every guest conversation started in the period: channel (portal chat, WhatsApp, SMS, e-mail), guest, room, team, assigned staff, messages each way, first response time, guest rating and status', 'range', 'inbox.view'], // v7.3
        'inbox_response' => ['Guest Messaging', 'Messaging Response Times by Day', 'Per day: conversations, guest messages, staff, automatic and broadcast replies, average and slowest response and the share answered within the response-time target', 'range', 'inbox.view'],
        'inbox_agents' => ['Guest Messaging', 'Messaging Team Performance', 'Per staff member: conversations, replies, internal notes, average first response, share within target, conversations closed and average guest rating', 'range', 'inbox.view'],
        'inbox_topics' => ['Guest Messaging', 'Messaging Topics & Automatic Answers', 'What guests write about: conversations per team with channel mix and rating, and how often each keyword rule answered automatically', 'range', 'inbox.view'],
        'partner_bookings' => ['Partner Portal', 'Partner Portal Bookings', 'Every booking corporate clients and travel agents made in the partner portal during the period: partner, booker, traveller, dates, rooms, room nights, stay value, bill-to, reference and status (held for approval / confirmed / cancelled)', 'range', 'partner.view'], // v7.4
        'partner_production' => ['Partner Portal', 'Partner Portal Production by Partner', 'Per corporate client / travel agent: active logins, sign-ins, portal bookings, room nights, stay value, cancellations and cancellation rate, held for approval and share of the partner\'s bookings made online', 'range', 'partner.view'],
        'partner_requests' => ['Partner Portal', 'Partner Requests & Response Times', 'Booking approvals, change requests and group quotes received in the period with who answered, the answer and the hours the partner waited; open requests past 24 hours highlighted', 'range', 'partner.view'],
        'partner_access' => ['Partner Portal', 'Partner Portal Access Audit', 'Security trail of partner logins: sign-ins, failed passwords, lock-outs, sign-outs, password changes / resets and user administration, with IP address; failures and lock-outs highlighted', 'range', 'partner.view'],
        'organiser_events' => ['Organiser Portal', 'Organiser Portal Events', 'Every event the hotel shares online right now: organiser, functions, status, active portal logins, BEO state (draft / published / signed), deposits paid, portal online payments and the balance still owed', 'none', 'organiser.view'], // v7.5
        'organiser_payments' => ['Organiser Portal', 'Organiser Portal Online Payments', 'Every online deposit and balance payment event organisers made in the portal during the period: event, payer, channel, amount, gateway reference and status', 'range', 'organiser.view'],
        'organiser_requests' => ['Organiser Portal', 'Organiser Requests & Response Times', 'Change requests, cancellations and quote requests received in the period with who answered, the answer and the hours the organiser waited; open requests past 24 hours highlighted', 'range', 'organiser.view'],
        'organiser_access' => ['Organiser Portal', 'Organiser Portal Access Audit', 'Security trail of organiser logins: sign-ins, failed passwords, lock-outs, sign-outs, password changes / resets and user administration, with IP address; failures and lock-outs highlighted', 'range', 'organiser.view'],
        'hse_permits' => ['Health & Safety', 'Permit-to-Work Register', 'Permits requested in the period by type: location, contractor, period, requester and issuer, fire watch / gas test / isolation, close-out time and late close-outs', 'range', 'hse.view'],
        'hse_drills' => ['Health & Safety', 'Fire Drill Log', 'Drills in the period by shift: evacuation time against the target, roll call, guests evacuated, result and improvements', 'range', 'hse.view'],
        'hse_risks' => ['Health & Safety', 'Risk Register', 'Live risk assessments: hazard, who is at risk, initial and residual rating on the 5 × 5 matrix, owner, acceptance and review date', 'none', 'hse.view'],
        'parking_sessions' => ['Parking & Valet', 'Parking Ticket Register', 'Every parking ticket issued in the period: plate, guest / visitor / permit, valet, zone, time in and out, duration, validation, lost tickets and what was charged or waived at the exit', 'range', 'parking.view'],
        'parking_revenue' => ['Parking & Valet', 'Parking Revenue by Day', 'Parking revenue per business date: guest nights posted to room folios, visitor exit fees and valet, lost-ticket fees and amounts waived', 'range', 'parking.view'],
        'parking_occupancy' => ['Parking & Valet', 'Car Park Occupancy by Zone', 'Each zone: bays, tickets by guest / visitor / permit, average stay, peak vehicles parked at once, bay utilisation and vehicles parked now', 'range', 'parking.view'],
        'parking_valet' => ['Parking & Valet', 'Valet Retrieval Performance', 'Valet cars brought round per runner: average and longest minutes from request to the door, share within the target and how guests asked (QR, phone, desk)', 'range', 'parking.view'],
        'parking_permits' => ['Parking & Valet', 'Parking Permit Register', 'Staff, tenant and contractor permits with vehicle, zone, validity, days left, fee, state and how often each was used', 'none', 'parking.view'],
        'bd_benchmark' => ['Business Development', 'Comp-Set Benchmark (MPI / ARI / RGI)', 'Monthly occupancy, ADR and RevPAR against the competitive set with the penetration, rate and revenue generation indices', 'range', 'bd.view'],
        'bd_rate_shop' => ['Business Development', 'Rate Shop', 'Our public rate vs the competitive set by stay date and channel: comp average, gap, price rank, lowest / highest competitor and sold-outs', 'range', 'bd.view'],
        'bd_initiatives' => ['Business Development', 'Initiative Portfolio', 'Business development initiatives by stage: investment, annual revenue & net, payback, NPV, actual results vs plan and milestones', 'none', 'bd.view'],
        'bd_partners' => ['Business Development', 'Partnership Production', 'Bookings, room nights, revenue and commission credited to each strategic partnership, with renewal and review flags', 'range', 'bd.view'],
        'bd_market_mix' => ['Business Development', 'Market Mix', 'Room nights and revenue by source market (guest nationality) and segment with share and growth vs the same period last year', 'range', 'bd.view'],
        'credit_exposure' => ['Contracts & Credit', 'Credit Exposure', 'Every direct-billing account: limit, city ledger, overdue, in-house balances, total exposure, available credit and hold status', 'none', 'credit.view'],
        'channel_production' => ['Channel Manager', 'Channel Production', 'Per OTA connection: bookings received in the period, room nights, gross revenue, commission, net revenue, ADR, lead time, length of stay, cancellations and prepaid share', 'range', 'channels.view'],
        'channel_sync_log' => ['Channel Manager', 'Channel Sync Log', 'ARI pushes per channel and day: deltas and full refreshes, room-nights sent, failures, average duration and the last error', 'range', 'channels.view'],
        'channel_parity' => ['Channel Manager', 'Rate Parity Exceptions', 'Every room-night where what a channel last acknowledged (availability, restrictions, prices) differs from what the PMS publishes now', 'range', 'channels.view'],
        'pos_evat_register' => ['GRA VAT & E-VAT', 'Restaurant Bills E-VAT Register', 'Bills settled at the outlets in the period: customer TIN, taxable value, VAT and levies, E-VAT receipt number and status', 'range', 'gst.view'],
        'einvoice_register' => ['GRA VAT & E-VAT', 'E-VAT Register', 'Invoices and credit notes dated in the period: buyer TIN, value, E-VAT SDC ID, receipt number and status – stamped, failed or not stamped', 'range', 'gst.view'],
        'credit_notes' => ['GRA VAT & E-VAT', 'Credit Note Register', 'Credit notes issued in the period against earlier invoices: reason, taxable value, VAT / NHIL / GETFund / Tourism Levy, settlement (refund or company account) and E-VAT stamp', 'range', 'gst.view'],
        'hsn_summary' => ['GRA VAT & E-VAT', 'VAT & Levies by Category', 'Outward supplies by charge category and rate for the period: taxable value, VAT, NHIL, GETFund, Tourism Levy, B2B and B2C', 'range', 'gst.view'],
        'privacy_requests' => ['Privacy & Compliance', 'Privacy Request Register', 'Data-subject requests received in the period: type, channel, identity check, deadline, days taken, on time / late / open and the outcome', 'range', 'privacy.view'],
        'pii_access' => ['Privacy & Compliance', 'PII Access Log', 'Every reveal of masked identifiers, ID-document and e-signature view and personal-data export in the period, by user', 'range', 'privacy.view'],
        'privacy_retention' => ['Privacy & Compliance', 'Retention & Erasure Log', 'Per day: ID scans and signatures deleted, message contents purged, profiles anonymised or restricted (on request / by policy), legal holds completed', 'range', 'privacy.view'],
        'nps_trend' => ['Feedback & Reputation', 'NPS & Satisfaction Trend', 'Month by month: surveys sent, response rate, promoters / passives / detractors, NPS, average overall rating, negative comments, online rating and review response rate', 'range', 'feedback.view'],
        'feedback_register' => ['Feedback & Reputation', 'Guest Feedback Register', 'Every survey, portal and web feedback response in the period with recommend score, overall rating, sentiment, complaint areas, comments and the recovery case', 'range', 'feedback.view'],
        'feedback_cases' => ['Feedback & Reputation', 'Service Recovery Cases', 'Cases opened in the period: department, severity, owner, hours to first contact and to resolve, SLA, recovery action, goodwill points and outcome', 'range', 'feedback.view'],
        'online_reviews' => ['Feedback & Reputation', 'Online Review Register', 'Reviews from TripAdvisor, Google, Booking.com and others by review date: rating, sentiment, matched guest, response status, who posted it and hours to respond', 'range', 'feedback.view'],
        'dept_scores' => ['Feedback & Reputation', 'Department Scorecard', 'Per department: average survey rating, positive and negative mentions in comments and reviews, net sentiment, cases, high-severity and past-SLA cases', 'range', 'feedback.view'],
        'purchases' => ['Inventory', 'Purchase Register', 'Purchase orders in the period', 'range'],
        'goods_received' => ['Inventory', 'Goods Received Register', 'Every delivery in the period by GRN and item: delivered, rejected (with reason) and accepted quantities, PO rate and accepted value – single-step receipts included', 'range'], // v4.8
        'open_purchase_orders' => ['Inventory', 'Open Purchase Orders & Overdue Deliveries', 'Approved and part-received PO lines still to be delivered on the date: ordered, received, outstanding quantity and value, expected date and days overdue', 'date'],
        'supplier_scorecard' => ['Inventory', 'Supplier Scorecard', 'Supplier performance in the period: on-time delivery, fill rate, quality (rejections), price compliance against the agreed price list, RFQ response rate, lead time and overall grade', 'range'],
        'consumption' => ['Inventory', 'Consumption / Issue Report', 'Issues and wastage by item', 'range'],
        'expenses' => ['Accounts', 'Expense Report', 'Payment vouchers by head', 'range'],
        'flash' => ['Management', 'Flash Report (Day / MTD / YTD)', "Manager's daily flash: occupancy, ADR, RevPAR, revenue, collections", 'date'],
        'ar_aging' => ['Accounts', 'AR Ageing (City Ledger)', 'Company outstanding by age bucket, FIFO against receipts', 'date'],
        'ap_ageing' => ['Accounts', 'AP Ageing (Suppliers)', 'Supplier balances by days past due, with unbilled goods receipts and unapplied credits', 'date', 'ap.view'],
        'ap_register' => ['Accounts', 'Supplier Bill Register', 'Bills & debit notes in the period: match variance, paid and outstanding', 'range', 'ap.view'],
        'wht_register' => ['Accounts', 'Withholding Tax Register (GRA)', 'Supplier payments in the period with the tax withheld: supplier TIN, nature of payment, taxable amount, rate, WHT, withholding VAT, net paid and certificate', 'range', 'ap.view'],
        'trial_balance' => ['General Ledger', 'Trial Balance', 'Opening, period movement and closing balance of every account', 'range', 'gl.view'],
        'gl_pnl' => ['General Ledger', 'Profit & Loss (GL)', 'Income statement from the double-entry ledger, by account group', 'range', 'gl.view'],
        'balance_sheet' => ['General Ledger', 'Balance Sheet', 'Assets, liabilities and equity as at a date', 'date', 'gl.view'],
        'journal_register' => ['General Ledger', 'Journal Register (Day Book)', 'Every journal line posted in the period', 'range', 'gl.view'],
        'close_status' => ['General Ledger', 'Period Close Status', 'Month-end close by period: checklist cleared, waivers, preparer and approver, days taken to close', 'range', 'close.view'], // v5.6
        'accrual_register' => ['General Ledger', 'Accruals & Prepayments Register', 'Accrued expenses, prepaid expenses and deferred income: amount, posted, balance remaining and next month to post', 'date', 'close.view'],
        'period_end_postings' => ['General Ledger', 'Period-end Postings', 'Journals made from schedules and recurring templates in the period, with auto-reversals', 'range', 'close.view'],
        'cash_flow' => ['Treasury', 'Cash-flow Statement (IAS 7)', 'Indirect method: profit, non-cash add-backs and working capital, investing and financing flows; opening + net = closing cash', 'range', 'treasury.view'], // v5.7
        'cash_position' => ['Treasury', 'Cash Position', 'Book balance per bank, MoMo, cash box, float and clearing account as at a date, with minimums, unpresented cheques and last reconciliation', 'date', 'treasury.view'],
        'cheque_register' => ['Treasury', 'Cheque Register', 'Cheques received (post-dated, in hand, banked, cleared, bounced) and cheques written in the period', 'range', 'treasury.view'],
        'transfer_register' => ['Treasury', 'Banking & Transfer Register', 'Cash / cheque bankings, settlement sweeps and bank transfers: requested, approved, sent, credited, fees and short / over banking', 'range', 'treasury.view'],
        'usali_summary' => ['Owner Reporting', 'USALI Summary Operating Statement', 'USALI 11th edition statement for any period: revenue by department, departmental and undistributed expenses, GOP, management fees, non-operating items, EBITDA, replacement reserve and net income with % of revenue', 'range', 'owner.view'], // v5.8
        'usali_departments' => ['Owner Reporting', 'USALI Departmental Schedules', 'Every ledger account under its USALI department and category (revenue, cost of sales, labour, other) with departmental profit and % of the department\'s revenue', 'range', 'owner.view'],
        'hotel_kpis' => ['Owner Reporting', 'Hotel KPIs by Month', 'Per calendar month: occupancy, ADR, RevPAR, TRevPAR, GOPPAR, cost per occupied room, GOP and EBITDA margins, labour cost % and F&B cost %', 'range', 'owner.view'],
        'management_fees' => ['Owner Reporting', 'Management Fee Calculation', 'Monthly base fee, FF&E contribution, adjusted GOP, fee-year AGOP against the owner\'s priority, incentive fee, status and payment', 'range', 'owner.view'],
        'ffe_reserve' => ['Owner Reporting', 'FF&E Reserve Ledger & Capex', 'Reserve opening balance, contributions, interest, capex drawdowns and adjustments with a running balance, and each capital project\'s budget and spend', 'range', 'owner.view'],
        'concierge_bookings' => ['Concierge', 'Activity Bookings Register', 'Every activity / excursion booking in the period: operator, guest, party, source, paid to hotel or operator, sold value, commission, status and settlement', 'range', 'concierge.view'], // v5.9
        'concierge_sales' => ['Concierge', 'Activity Sales & Commission', 'Per activity: bookings, guests, sold value, average booking, commission earned, cancellations, no-shows, portal share and take-up', 'range', 'concierge.view'],
        'concierge_vendors' => ['Concierge', 'Operator Statement & Balances', 'Per tour operator: finished bookings, commission, settlements paid / received and unsettled balances owed each way, with licence expiry', 'range', 'concierge.view'],
        'concierge_staff' => ['Concierge', 'Concierge Sales by Staff', 'Bookings, guests, sold value and commission by the staff member (or guest portal) that made them – the base for concierge incentives', 'range', 'concierge.view'],
        'training_compliance' => ['Learning & Development', 'Training Matrix Compliance', 'Every employee against the training their department / job title requires: current, expiring, expired, overdue, new-starter due and compliance %', 'none', 'learning.view'],
        'training_expiry' => ['Learning & Development', 'Certificates Expiring', 'Staff certificates (fire, first aid, food hygiene…) expiring in the date range – whether a renewal session is booked – so refreshers can be planned', 'range', 'learning.view'],
        'training_records' => ['Learning & Development', 'Training Records Register', 'Every training outcome in the period: employee, course, in-house session or external, result, score, hours, cost and certificate number', 'range', 'learning.view'],
        'training_hours' => ['Learning & Development', 'Training Hours & Spend by Department', 'Training hours delivered, staff trained, hours per head, cost and cost per head by department, with the courses taken', 'range', 'learning.view'],
        'sop_acknowledgement' => ['Learning & Development', 'SOP Sign-off Status', 'Every published SOP that staff must sign: version, effective date, review due, staff in scope, signed, outstanding and who has not signed', 'none', 'learning.view'],
        'budget_vs_actual' => ['Budget & Planning', 'Budget vs Actual (P&L)', 'Month and year-to-date actuals from the general ledger against the active budget (current month pro-rated), variances, % and full-year budget / forecast', 'date', 'budget.view'],
        'budget_annual' => ['Budget & Planning', 'Annual Budget – Monthly Phasing', 'The active budget for the fiscal year: every income and expense account by month with section totals, GOP and net profit', 'date', 'budget.view'],
        'forecast_vs_budget' => ['Budget & Planning', 'Forecast vs Budget', 'Full-year active re-forecast against the approved budget per account, with actuals to date and the remaining forecast', 'date', 'budget.view'],
        'budget_kpis' => ['Budget & Planning', 'Rooms KPIs vs Budget', 'Per fiscal month: occupancy, ADR, RevPAR, room-nights and room revenue – budget against actual', 'date', 'budget.view'],
        'variance_commentary' => ['Budget & Planning', 'Variance Commentary Log', 'Explanations recorded for budget variances in the period: month, account, actual, budget, variance, comment and author', 'range', 'budget.view'],
        'call_log' => ['Hotel Interfaces', 'Call Accounting Log', 'Every call from the PABX in the period: time, extension, room / guest, number (masked), class, duration, carrier cost, guest charge and status', 'range', 'calls.view'],
        'call_revenue' => ['Hotel Interfaces', 'Telephone Revenue & Margin', 'Guest calls by class: calls, minutes, carrier cost, amount charged and margin – plus unbilled (pending / waived) calls', 'range', 'calls.view'],
        'admin_call_usage' => ['Hotel Interfaces', 'Administrative Call Usage', 'Calls from administrative and public extensions by department and extension: calls, minutes and carrier cost', 'range', 'calls.view'],
        'key_card_audit' => ['Hotel Interfaces', 'Key Card Audit', 'Guest key cards encoded in the period: room, holder, type, valid until, status, who issued and lost-card fees', 'range', 'keys.view'],
        'master_key_register' => ['Hotel Interfaces', 'Master Key Register', 'Master-key sign-outs in the period: key, holder, purpose, out, due, returned, late / lost', 'range', 'keys.view'],
        'gift_voucher_register' => ['Gift Vouchers', 'Gift Voucher Register', 'Every voucher issued in the period: type, sold / promotional, purchaser & recipient, value, redeemed, balance and status', 'range', 'vouchers.view'],
        'voucher_liability' => ['Gift Vouchers', 'Voucher Liability (Outstanding)', 'Unspent voucher balances as at a date by expiry bucket – reconciled to GL 2120 Gift Vouchers Outstanding', 'date', 'vouchers.view'],
        'voucher_redemptions' => ['Gift Vouchers', 'Voucher Redemptions', 'Every redemption and reversal: where it was spent (front office folio / outlet bill), amount and cashier', 'range', 'vouchers.view'],
        'voucher_breakage' => ['Gift Vouchers', 'Voucher Breakage & Cancellations', 'Balances that lapsed at expiry (breakage income / promotional cost released) and cancelled / refunded vouchers', 'range', 'vouchers.view'],
        'voucher_sales' => ['Gift Vouchers', 'Voucher Sales Summary', 'Per voucher type: vouchers sold and given away, value sold, reloads, redeemed and lapsed – plus sales by payment mode', 'range', 'vouchers.view'],
        // v3.9 engineering
        'utility_consumption' => ['Engineering', 'Utility Consumption by Meter', 'Per meter: readings, days covered, consumption, daily average, cost, estimated readings and anomalies – with sub-meter coverage of each main meter', 'range', 'utilities.view'],
        'energy_kpis' => ['Engineering', 'Energy & Water KPIs', 'Per day: occupied rooms, guests, kWh, kWh per occupied room, water per guest night, utility cost per room and as % of revenue, CO₂ per room', 'range', 'utilities.view'],
        'utility_bill_reconciliation' => ['Engineering', 'Utility Bill Reconciliation', 'Bills dated in the period: units billed vs metered, variance, charges, effective rate vs tariff and the AP document', 'range', 'utilities.view'],
        'carbon_footprint' => ['Engineering', 'Carbon Footprint (Scope 1 & 2)', 'Consumption × emission factor per energy source: t CO₂e, share, per occupied room and emissions avoided by solar', 'range', 'utilities.view'],
        'compliance_register' => ['Engineering', 'Licences & Compliance Register', 'Every licence, permit, certificate, insurance, inspection and AMC with expiry / next due, days left and status as at a date', 'date', 'compliance.view'],
        // v4.0 API platform
        'upsell_revenue' => ['Upselling', 'Upsell Revenue by Offer', 'Per offer: confirmed orders and revenue split by channel (pre-arrival, desk, in-house portal), average sale, share of upsell revenue, plus declined and cancelled orders', 'range', 'upsell.view'],
        'upsell_conversion' => ['Upselling', 'Upsell Conversion by Channel', 'Guests shown offers per channel, how many opened them and how many bought – conversion rate, orders and revenue per guest offered', 'range', 'upsell.view'],
        'security_visitors' => ['Security', 'Visitor Register', 'Every visitor in the period: pass, name, masked ID, whom they visited, purpose, time in / out, who let them in, overrides and watch-list matches', 'range', 'security.view'],
        'security_gatepasses' => ['Security', 'Gate Pass Register', 'Gate passes raised in the period with party, purpose, items, value, approval, gate-out time and return status', 'range', 'security.view'],
        'security_outstanding' => ['Security', 'Goods Outside the Property', 'Returnable gate-pass items still out at a date: party, quantity outstanding, estimated value, due date and days overdue', 'date', 'security.view'],
        'security_incidents' => ['Security', 'Security Incident Register', 'Incidents in the period by type and severity with location, guest, injuries, loss, police reference, status and resolution', 'range', 'security.view'],
        'upsell_incentives' => ['Upselling', 'Staff Upsell Incentives', 'Per staff member: desk upsells confirmed, sales value, incentive earned and the offers sold – for the payroll incentive run', 'range', 'upsell.view'],
        'api_usage' => ['API & Integrations', 'API Usage by Client', 'Per API client and call: requests, successes, client (4xx) and server (5xx) errors, error rate, average response time and the last call – plus calls refused for want of a valid key', 'range', 'api.view'],
        'webhook_deliveries' => ['API & Integrations', 'Webhook Delivery Performance', 'Per endpoint and event: events queued, delivered, still retrying and given up, first-attempt success, average attempts and response time', 'range', 'api.view'],
        'rm_forecast' => ['Revenue Management', 'Forecast & On the Books', 'Per night: rooms booked & held, ADR, revenue, 7-day pickup and forecast occupancy / revenue', 'range', 'revenue.view'],
        'rm_pickup' => ['Revenue Management', 'Booking Pace (Pickup)', 'Per booking date: new bookings, room nights, revenue, average lead time & rate, cancellations', 'range', 'revenue.view'],
        'payroll_register' => ['HR & Payroll', 'Payroll Register', 'Per employee and month: paid days, LOP, overtime, earnings, each statutory deduction, net pay and employer cost', 'range', 'payroll.view'],
        'payroll_statutory' => ['HR & Payroll', 'Statutory Dues Summary', 'Per month and liability: employee deductions + employer contributions owed (SSNIT Tier 1, Tier 2 pension, PAYE)', 'range', 'payroll.view'],
        'ssnit_schedule' => ['HR & Payroll', 'SSNIT Contribution Schedule', 'Per approved payroll month and employee: SSNIT number, Ghana Card, basic salary, insurable earnings, Tier 1 (employee 5.5% + employer 8%) and Tier 2 (5%)', 'range', 'payroll.view'],
        'paye_schedule' => ['HR & Payroll', 'PAYE Monthly Schedule (GRA)', 'Per approved payroll month and employee: TIN / Ghana Card, basic, allowances, gross, SSNIT relief, chargeable income and PAYE withheld', 'range', 'payroll.view'],
        'bank_reconciliation' => ['Accounts', 'Bank Reconciliation Summary', 'Per bank / Mobile Money / clearing account at a date: book balance, latest statement balance, deposits in transit, unpresented payments, statement items not in the books and the unexplained difference', 'date', 'bankrec.view'],
        'uncleared_items' => ['Accounts', 'Uncleared Items', 'Book entries not yet on a bank statement and statement lines not in the books, at a date, with days outstanding', 'date', 'bankrec.view'],
        'bank_statement_lines' => ['Accounts', 'Bank Statement Lines', 'Imported statement lines in the period: statement, date, details, money in / out, status, how matched and the book entries they cleared', 'range', 'bankrec.view'],
        'petty_cash_book' => ['Accounts', 'Petty Cash Book', 'Every posted petty cash movement in the period per float: top-ups, vouchers, IOUs and settlements, count differences and claims paid in cash, with opening, running and closing balances', 'range', 'petty.view'], // v4.7
        'petty_expenses' => ['Accounts', 'Petty Cash Spending by Account', 'Vouchers and settled IOUs in the period by expense account and float, with input VAT, NHIL & GETFund recovered on VAT invoices', 'range', 'petty.view'],
        'expense_claims' => ['Accounts', 'Staff Expense Claims Register', 'Claims submitted in the period: claimant, department, title, amount, input VAT, status, approver, payment date and method', 'range', 'claims.approve'],
        'statutory_remittances' => ['HR & Payroll', 'Statutory Remittance Register', 'SSNIT, Tier 2 and PAYE payments made in the period: month, payee, amount, due date, days late, reference and payment mode', 'range', 'payroll.view'],
        'roster_vs_actual' => ['HR & Payroll', 'Roster vs Actual', 'Per employee: rostered shifts and planned hours against time-clock hours worked, late arrivals, early departures, no-shows, unrostered work and manager corrections', 'range', 'roster.view'], // v4.9
        'timesheet' => ['HR & Payroll', 'Timesheet (Time Clock)', 'Every time punch in the period: rostered shift, clock-in / out, source (self-service, kiosk, manager), hours worked, late / early minutes, corrections and approval', 'range', 'roster.view'],
        'service_charge_distribution' => ['HR & Payroll', 'Service Charge Distribution', 'Service charge & tips shared with staff for distributions in the period: points, days worked, amount, status and the payroll run that paid it', 'range', 'servicecharge.manage'],
        'haccp_temperatures' => ['Food Safety', 'HACCP Temperature Log', 'Every temperature reading in the period – equipment checks, receiving, cooking, reheating and cooling – with critical limit, result, corrective action and reviewer', 'range', 'foodsafety.view'], // v5.0
        'haccp_checklists' => ['Food Safety', 'HACCP Checklist Compliance', 'Checklist periods due in the range: completed on time or missed, failed items and sign-off', 'range', 'foodsafety.view'],
        'club_members' => ['Health Club', 'Membership Register', 'Memberships current in the period by plan, with term, card holders, balance and visits; joins, renewals and leavers', 'range', 'club.view'], // v5.1
        'club_attendance' => ['Health Club', 'Club Attendance & Utilisation', 'Visits by day and facility (members, hotel guests, day passes, members\' guests), refusals, overrides and class attendance', 'range', 'club.view'],
        'club_revenue' => ['Health Club', 'Club Revenue & Deferred Fees', 'Membership fees earned day by day, joining fees, guest & day passes, credit notes, receipts and the fees still held in advance', 'range', 'club.view'],
        'lease_rent_roll' => ['Apartments & Leases', 'Rent Roll', 'Every unit with its tenant, term, cycle, monthly rent & service charge, deposit held, balance and rent invoiced / collected in the period', 'range', 'leases.view'], // v5.2
        'lease_arrears' => ['Apartments & Leases', 'Tenant Arrears Ageing', 'Overdue tenant invoices after the grace period by age (0–30, 31–60, 61–90, 90+ days) with the deposit held against each', 'range', 'leases.view'],
        'lease_expiry' => ['Apartments & Leases', 'Lease Expiry & Renewals', 'Leases ending, on notice or due for a rent review in the period (from the start date to the end date), with the action needed', 'range', 'leases.view'],
        'ia_findings' => ['Income Audit', 'Audit Exceptions Register', 'Every income-audit exception raised for business dates in the period: check, severity, room / document, value and how it was resolved (explained, corrected, escalated, accepted)', 'range', 'income_audit.view'], // v5.3
        'ia_rate_variance' => ['Income Audit', 'Room Rate Variance', 'Each occupied room night: tariff charged against the expected rate (rate plan, contract, group block or rack less booking discount), variance and the authorisation covering it (max 31 days)', 'range', 'income_audit.view'],
        'ia_reconciliation' => ['Income Audit', 'Daily Revenue Reconciliation', 'Per business date: night-audit report vs the baseline at close vs now for room / other revenue, tax and collections, discounts, GL income, open exceptions and sign-off', 'range', 'income_audit.view'],
        'ia_authorisations' => ['Income Audit', 'Comps, Discounts & Rate Authorisations', 'Complimentary and house-use rooms, below-expected rates and folio discounts requested in the period, the revenue given away, who asked and who approved', 'range', 'income_audit.view'],
        'food_waste' => ['Food Safety', 'Food Waste Analysis', 'Food waste by item and reason with quantity and cost; raw & unserved dishes (posted to 5042 Food Waste) and plate waste (analysis only)', 'range', 'foodsafety.view'],
        'leave_register' => ['HR & Payroll', 'Leave Register', 'Leave requests overlapping the period: employee, type, dates, days, status and approver', 'range', 'hr.leave'],
        'mis' => ['Management', 'Night Audit MIS', 'Night audit statistics', 'range'],
        'guest_history' => ['Management', 'Top Guests', 'Repeat guests by stays and revenue', 'range'],
        'user_activity' => ['Management', 'User Activity Summary', 'Actions per user and module', 'range'],
        'rpt_deliveries' => ['Management', 'Scheduled Report Deliveries', 'Every scheduled / sent-now report e-mail: period, recipients, rows, failures', 'range', 'reports.schedule'], // v5.4
        'kpi_alert_log' => ['Management', 'KPI Alert Log', 'KPI threshold breaches by business date, actual vs threshold, acknowledgement', 'range', 'reports.alerts'], // v5.4
        'package_forecast' => ['Packages & Meal Plans', 'Meal Cover Forecast', 'Breakfast / lunch / dinner covers per service date: actual entitlements for past days, on-the-books forecast from today (in-house + confirmed reservations)', 'range', 'packages.view'], // v5.5
        'package_allocation' => ['Packages & Meal Plans', 'Package Allocation & Breakage', 'Per package: value allocated out of room revenue and add-ons, used at the outlets, expired as breakage, still open, extras charged; take-up rate', 'range', 'packages.view'],
        'package_consumption' => ['Packages & Meal Plans', 'Package Consumption Register', 'Every cover / allowance recorded at the Meal Desk: room, guest, package, covers, value, extras and how they were settled, voids', 'range', 'packages.view'],
    ];

    public function index()
    {
        $visible = collect(self::REPORTS)->filter(fn ($r) => can_do($r[4] ?? 'reports.view'));

        return view('reports.index', ['groups' => $visible->groupBy(fn ($r) => $r[0], true)]);
    }

    public function show(Request $request, string $report)
    {
        abort_unless(isset(self::REPORTS[$report]), 404);
        [$group, $title, $desc, $mode] = self::REPORTS[$report];
        abort_unless(can_do(self::REPORTS[$report][4] ?? 'reports.view'), 403);
        $defaultFrom = match ($report) { 'rm_forecast', 'channel_parity', 'lease_expiry', 'training_expiry', 'bd_rate_shop', 'cro_demand' => bdate(), 'bd_benchmark' => bdate()->copy()->subMonthsNoOverflow(12)->startOfMonth(), 'bd_partners', 'bd_market_mix' => bdate()->copy()->startOfYear(), 'hse_checks', 'hse_accidents', 'hse_permits', 'hse_drills' => bdate()->copy()->subMonthsNoOverflow(6)->startOfMonth(), 'esg_carbon' => Carbon::parse(\App\Services\EsgService::window()[0]), 'esg_waste', 'esg_green', 'esg_local_spend' => bdate()->copy()->startOfYear(), 'esg_refrigerants' => bdate()->copy()->subYear()->addDay(), 'iptv_engagement', 'iptv_orders', 'kiosk_sessions', 'kiosk_adoption', 'kiosk_payments', 'kiosk_assist', 'inbox_conversations', 'inbox_response', 'inbox_agents', 'inbox_topics', 'partner_bookings', 'partner_requests', 'partner_access', 'organiser_payments', 'organiser_requests', 'organiser_access' => bdate()->copy()->subDays(13), 'partner_production' => bdate()->copy()->subDays(29), 'talent_vacancies', 'talent_pipeline', 'talent_appraisals', 'talent_discipline' => bdate()->copy()->startOfYear(), 'talent_exits' => bdate()->copy()->subYear()->addDay(), 'talent_headcount' => bdate(), 'qa_audits', 'qa_department_scores', 'qa_standard_failures', 'qa_actions', 'qa_template_compliance' => bdate()->copy()->subDays(179), 'parking_sessions', 'parking_revenue', 'parking_occupancy', 'parking_valet' => bdate()->copy()->subDays(13), 'rm_pickup' => bdate()->copy()->subDays(13), 'payroll_register', 'payroll_statutory', 'ssnit_schedule', 'paye_schedule' => bdate()->copy()->subMonths(2)->startOfMonth(), default => bdate()->copy()->startOfMonth() };
        // v5.4 saved view: relative range resolved against today's business date (explicit from/to still win)
        $activeView = null;
        if ($request->filled('view')) {
            $activeView = \App\Models\ReportView::where('report_key', $report)->find($request->integer('view'));
            abort_unless($activeView && \App\Services\ReportScheduleService::canOpen($activeView, $request->user()), 404);
            [$vf, $vt] = \App\Services\ReportScheduleService::resolve($activeView->range, $activeView->date_from, $activeView->date_to);
            $defaultFrom = $vf;
            $viewTo = $vt;
            $activeView->forceFill(['last_opened_at' => now(), 'opens' => $activeView->opens + 1])->saveQuietly();
        }
        $from = Carbon::parse($request->input('from', $defaultFrom->toDateString()))->startOfDay();
        $defaultTo = in_array($report, ['lease_expiry', 'training_expiry'], true) ? bdate()->copy()->addDays(90) : ($report === 'rm_forecast' ? bdate()->copy()->addDays(29) : (in_array($report, ['channel_parity', 'bd_rate_shop', 'cro_demand'], true) ? bdate()->copy()->addDays(29) : (in_array($report, ['flash', 'trial_balance', 'gl_pnl', 'balance_sheet', 'journal_register', 'budget_vs_actual', 'budget_annual', 'forecast_vs_budget', 'budget_kpis']) ? bdate()->copy()->subDay() : bdate()))); // GL: last closed day
        $to = Carbon::parse($request->input('to', ($viewTo ?? $defaultTo)->toDateString()))->startOfDay();
        if ((self::REPORTS[$report][3] ?? '') === 'date') {
            $from = $to->copy(); // "as at" reports only use the date
        }
        if ($to->lt($from)) {
            [$from, $to] = [$to, $from];
        }
        if ($from->diffInDays($to) > 366) {
            $from = $to->copy()->subDays(366);
        }
        $data = $this->{'r_'.$report}($from, $to) + ['money' => [], 'sum' => []];
        $data['rows'] = collect($data['rows'])->values();
        $allCols = $data['cols'];
        if ($activeView) {
            $data = \App\Services\ReportScheduleService::shape($data, $activeView->columns, $activeView->filter);
        }

        if ($request->input('export') === 'csv') {
            return response()->streamDownload(function () use ($data) {
                $out = fopen('php://output', 'w');
                fputcsv($out, array_values($data['cols']));
                foreach ($data['rows'] as $row) {
                    fputcsv($out, array_map(fn ($k) => $this->csvSafe($row[$k] ?? ''), array_keys($data['cols'])));
                }
                fclose($out);
            }, $report.'_'.$from->format('Ymd').'_'.$to->format('Ymd').'.csv', ['Content-Type' => 'text/csv']);
        }

        $views = \App\Services\ReportScheduleService::viewsFor($request->user(), $report);
        $range = $activeView && ! $request->hasAny(['from', 'to']) ? $activeView->range : \App\Services\ReportScheduleService::guess($report, $from, $to);

        return view('reports.show', compact('report', 'title', 'desc', 'mode', 'from', 'to', 'data', 'activeView', 'views', 'allCols', 'range'));
    }

    protected function r_rpt_deliveries($from, $to): array
    {
        $rows = \App\Models\ReportDelivery::with('schedule.user', 'user')->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])->orderBy('id')->get()
            ->map(fn ($d) => ['at' => $d->created_at->format('d-m-Y H:i'), 'schedule' => $d->title, 'report' => self::REPORTS[$d->report_key][1] ?? $d->report_key,
                'period' => $d->period_from ? $d->period_from->format('d-m-Y').($d->period_from->equalTo($d->period_to) ? '' : ' – '.$d->period_to->format('d-m-Y')) : '',
                'trigger' => ['schedule' => 'Scheduled', 'after_audit' => 'After audit', 'manual' => 'Sent now'][$d->trigger] ?? $d->trigger,
                'owner' => $d->schedule?->user?->name, 'status' => $d->status, 'rows' => $d->rows, 'recipients' => $d->recipients, 'delivered' => $d->delivered,
                'error' => $d->error, '_class' => $d->status === 'failed' ? 'row-red' : '']);

        return ['cols' => ['at' => 'Run at', 'schedule' => 'Schedule', 'report' => 'Report', 'period' => 'Period', 'trigger' => 'Trigger', 'owner' => 'Owner', 'status' => 'Status',
            'rows' => 'Rows', 'recipients' => 'Recipients', 'delivered' => 'Delivered', 'error' => 'Detail'],
            'rows' => $rows, 'sum' => ['rows', 'recipients', 'delivered'],
            'summary' => ['Runs' => (string) $rows->count(), 'Delivered' => (string) $rows->whereIn('status', ['sent', 'partial'])->count(), 'Failed' => (string) $rows->where('status', 'failed')->count(),
                'E-mails sent' => (string) $rows->sum('delivered')],
            'note' => 'Deliveries do not keep a copy of the report – download re-generates the same period from the Scheduled Reports page.'];
    }

    protected function r_kpi_alert_log($from, $to): array
    {
        $rows = \App\Models\KpiAlertEvent::with('alert', 'acknowledger')->whereDate('business_date', '>=', $from->toDateString())->whereDate('business_date', '<=', $to->toDateString())
            ->orderBy('business_date')->orderBy('id')->get()
            ->map(function ($e) {
                $fmt = \App\Services\KpiService::METRICS[$e->alert?->metric][1] ?? 'int';

                return ['date' => $e->business_date->format('d-m-Y'), 'alert' => $e->alert?->name, 'metric' => \App\Services\KpiService::metricLabel((string) $e->alert?->metric),
                    'severity' => $e->severity, 'actual' => \App\Services\KpiService::format((float) $e->value, $fmt),
                    'threshold' => ($e->alert?->operator === 'below' ? '< ' : '> ').\App\Services\KpiService::format((float) $e->threshold, $fmt),
                    'notified' => $e->notified, 'ack' => $e->acknowledger?->name ?? '', 'ack_at' => $e->acknowledged_at?->format('d-m-Y H:i') ?? '', 'note' => $e->ack_note,
                    '_class' => $e->acknowledged_at ? '' : ($e->severity === 'critical' ? 'row-red' : 'row-amber')];
            });

        return ['cols' => ['date' => 'Business date', 'alert' => 'Alert', 'metric' => 'Metric', 'severity' => 'Severity', 'actual' => 'Actual', 'threshold' => 'Threshold',
            'notified' => 'Messages sent', 'ack' => 'Acknowledged by', 'ack_at' => 'Acknowledged at', 'note' => 'Note'],
            'rows' => $rows, 'sum' => ['notified'],
            'summary' => ['Breaches' => (string) $rows->count(), 'Critical' => (string) $rows->where('severity', 'critical')->count(),
                'Unacknowledged' => (string) $rows->where('ack', '')->count()]];
    }

    protected function r_package_forecast($from, $to): array
    {
        $pkgs = \App\Models\Package::where('is_allowance', true)->orderBy('sort')->orderBy('name')->get()->keyBy('id');
        $bd = bdate()->toDateString();
        $rows = collect();
        $actual = \App\Models\PackageEntitlement::whereDate('service_date', '>=', $from->toDateString())->whereDate('service_date', '<=', min($to->toDateString(), bdate()->copy()->subDay()->toDateString()))
            ->where('status', '!=', 'cancelled')->where('is_allowance', true)
            ->selectRaw('service_date, package_id, COUNT(DISTINCT reservation_room_id) rooms, SUM(adults) adults, SUM(children) children, SUM(qty) covers, SUM(consumed_qty) used, SUM(amount) value')
            ->groupBy('service_date', 'package_id')->get();
        foreach ($actual as $a) {
            $rows->push(['date' => Carbon::parse($a->service_date)->format('d-m-Y'), '_d' => Carbon::parse($a->service_date)->toDateString(), 'package' => $pkgs[$a->package_id]->name ?? '#'.$a->package_id,
                'basis' => 'Actual', 'rooms' => (int) $a->rooms, 'adults' => (int) $a->adults, 'children' => (int) $a->children, 'covers' => (int) $a->covers, 'used' => (int) $a->used, 'value' => round((float) $a->value, 2)]);
        }
        if ($to->toDateString() >= $bd) {
            foreach (\App\Services\PackageService::forecast($from->copy()->max(bdate()), $to->copy()) as $d => $cells) {
                foreach ($cells as $pid => $c) {
                    $rows->push(['date' => Carbon::parse($d)->format('d-m-Y'), '_d' => $d, 'package' => $pkgs[$pid]->name ?? '#'.$pid, 'basis' => $d === $bd ? 'Today (on the books)' : 'Forecast',
                        'rooms' => $c['rooms'], 'adults' => $c['adults'], 'children' => $c['children'], 'covers' => $c['covers'], 'used' => $d === $bd ? (int) \App\Models\PackageEntitlement::whereDate('service_date', $d)->where('package_id', $pid)->sum('consumed_qty') : '',
                        'value' => round($c['value'], 2), '_class' => $d === $bd ? 'row-amber' : '']);
                }
            }
        }
        $rows = $rows->sortBy(fn ($r) => $r['_d'].$r['package'])->values()->map(function ($r) {
            unset($r['_d']);

            return $r;
        });

        return ['cols' => ['date' => 'Service date', 'package' => 'Package', 'basis' => 'Basis', 'rooms' => 'Rooms', 'adults' => 'Adults', 'children' => 'Children', 'covers' => 'Covers', 'used' => 'Served', 'value' => 'Value'],
            'rows' => $rows, 'money' => ['value'], 'sum' => ['rooms', 'covers', 'value'],
            'summary' => ['Covers' => (string) $rows->sum('covers'), 'Forecast days' => (string) $rows->where('basis', 'Forecast')->pluck('date')->unique()->count()],
            'note' => 'Breakfast is counted on the morning after the night that includes it. Forecast rows come from confirmed reservations and in-house stays with the package included in their rate or added on.'];
    }

    protected function r_package_allocation($from, $to): array
    {
        $range = [$from->toDateString(), $to->toDateString()];
        $pkgs = \App\Models\Package::orderBy('sort')->orderBy('name')->get();
        $alloc = \App\Models\PackageEntitlement::whereDate('night_date', '>=', $range[0])->whereDate('night_date', '<=', $range[1])->where('status', '!=', 'cancelled')
            ->selectRaw("package_id, SUM(CASE WHEN source='included' THEN amount ELSE 0 END) inc, SUM(CASE WHEN source='addon' THEN amount ELSE 0 END) addon, SUM(qty) covers, COUNT(*) n")
            ->groupBy('package_id')->get()->keyBy('package_id');
        $used = \App\Models\PackageRedemption::whereDate('business_date', '>=', $range[0])->whereDate('business_date', '<=', $range[1])->whereNull('voided_at')
            ->selectRaw("package_id, SUM(amount) used, SUM(covers) cov, SUM(CASE WHEN overage_mode='folio' THEN extra_amount ELSE 0 END) extras, SUM(extra_covers) xcov")
            ->groupBy('package_id')->get()->keyBy('package_id');
        $fixedUsed = \App\Models\PackageEntitlement::whereDate('night_date', '>=', $range[0])->whereDate('night_date', '<=', $range[1])->where('status', 'consumed')->where('is_allowance', false)
            ->selectRaw('package_id, SUM(amount) v, SUM(qty) q')->groupBy('package_id')->get()->keyBy('package_id');
        $brk = \App\Models\PackageEntitlement::whereDate('expired_date', '>=', $range[0])->whereDate('expired_date', '<=', $range[1])->selectRaw('package_id, SUM(breakage_amount) b, SUM(qty - consumed_qty) q')->groupBy('package_id')->get()->keyBy('package_id');
        $open = \App\Models\PackageEntitlement::whereIn('status', ['open', 'partial'])->where('is_allowance', true)->whereDate('night_date', '<=', $range[1])
            ->selectRaw('package_id, SUM(amount - consumed_amount) o')->groupBy('package_id')->get()->keyBy('package_id');
        $rows = $pkgs->map(function ($p) use ($alloc, $used, $brk, $open, $fixedUsed) {
            $a = $alloc[$p->id] ?? null;
            $u = $used[$p->id] ?? null;
            $usedV = (float) ($u->used ?? 0) + (float) ($fixedUsed[$p->id]->v ?? 0);
            $covers = (int) ($a->covers ?? 0);
            $usedQ = (int) ($u->cov ?? 0) + (int) ($fixedUsed[$p->id]->q ?? 0);

            return ['package' => $p->name, 'code' => $p->code, 'type' => $p->is_allowance ? ($p->redeem_mode === 'amount' ? 'Allowance' : 'Covers') : 'Fixed',
                'nights' => (int) ($a->n ?? 0), 'included' => round((float) ($a->inc ?? 0), 2), 'addon' => round((float) ($a->addon ?? 0), 2), 'covers' => $covers,
                'used' => round($usedV, 2), 'breakage' => round((float) ($brk[$p->id]->b ?? 0), 2), 'open' => round((float) ($open[$p->id]->o ?? 0), 2),
                'extras' => round((float) ($u->extras ?? 0), 2), 'takeup' => $covers > 0 ? round(min($usedQ, $covers) * 100 / $covers, 1).'%' : ''];
        })->filter(fn ($r) => $r['nights'] || $r['used'] || $r['breakage'] || $r['extras'] || $r['open'])->values();

        return ['cols' => ['package' => 'Package', 'code' => 'Code', 'type' => 'Type', 'nights' => 'Entitlements', 'included' => 'Allocated from room', 'addon' => 'Add-on sales',
            'covers' => 'Covers', 'used' => 'Used', 'breakage' => 'Breakage', 'open' => 'Open (liability)', 'extras' => 'Extras charged', 'takeup' => 'Take-up'],
            'rows' => $rows, 'money' => ['included', 'addon', 'used', 'breakage', 'open', 'extras'], 'sum' => ['nights', 'included', 'addon', 'covers', 'used', 'breakage', 'open', 'extras'],
            'summary' => ['Allocated from room revenue' => money($rows->sum('included')), 'Breakage' => money($rows->sum('breakage')), 'Open liability' => money(\App\Services\PackageService::openLiability())],
            'note' => 'Allocations by stay night, usage by redemption date, breakage by expiry date. Fixed inclusions (Wi-Fi, transfers) count as used when allocated. The GL posts the same figures daily (source “Package allocation”).'];
    }

    protected function r_package_consumption($from, $to): array
    {
        $rows = \App\Models\PackageRedemption::with('package', 'stay.room', 'stay.guest', 'outlet', 'recorder', 'voider')
            ->whereDate('business_date', '>=', $from->toDateString())->whereDate('business_date', '<=', $to->toDateString())->orderBy('business_date')->orderBy('id')->get()
            ->map(fn ($r) => ['no' => $r->redemption_no, 'date' => $r->business_date->format('d-m-Y'), 'time' => $r->created_at?->format('H:i'), 'room' => $r->stay?->room?->room_no,
                'guest' => $r->stay?->guest?->full_name, 'package' => $r->package?->name, 'outlet' => $r->outlet?->name, 'covers' => $r->voided_at ? 0 : $r->covers,
                'amount' => $r->voided_at ? 0 : (float) $r->amount, 'extra_covers' => $r->voided_at ? 0 : $r->extra_covers, 'extra' => $r->voided_at ? 0 : (float) $r->extra_amount,
                'settle' => (float) $r->extra_amount > 0 ? \App\Models\PackageRedemption::OVERAGE[$r->overage_mode] : '', 'by' => $r->recorder?->name,
                'status' => $r->voided_at ? 'Voided by '.$r->voider?->name.': '.$r->void_reason : 'OK', '_class' => $r->voided_at ? 'row-red' : ''])->values();

        return ['cols' => ['no' => 'No.', 'date' => 'Date', 'time' => 'Time', 'room' => 'Room', 'guest' => 'Guest', 'package' => 'Package', 'outlet' => 'Outlet', 'covers' => 'Covers',
            'amount' => 'Allowance used', 'extra_covers' => 'Extra covers', 'extra' => 'Extra value', 'settle' => 'Extras settled', 'by' => 'Recorded by', 'status' => 'Status'],
            'rows' => $rows, 'money' => ['amount', 'extra'], 'sum' => ['covers', 'amount', 'extra_covers', 'extra'],
            'summary' => ['Redemptions' => (string) $rows->where('status', 'OK')->count(), 'Covers' => (string) ($rows->sum('covers') + $rows->sum('extra_covers')),
                'Voided' => (string) $rows->where('status', '!=', 'OK')->count()]];
    }

    /** Neutralise spreadsheet formula injection. */
    protected function csvSafe($v)
    {
        $v = (string) $v;

        return preg_match('/^[=+\-@\t\r]/', $v) && ! is_numeric($v) ? "'".$v : $v;
    }

    protected function stayRow(ReservationRoom $s): array
    {
        $g = $s->guest ?? $s->reservation->guest;

        return ['res' => $s->reservation->reservation_no, 'guest' => $g?->full_name, 'phone' => $g?->phone, 'room' => $s->room?->room_no ?? '—', 'type' => $s->roomType?->name,
            'arr' => $s->arrival_date->format('d-m-Y'), 'dep' => $s->departure_date->format('d-m-Y'), 'nights' => $s->nights, 'pax' => $s->adults.'/'.$s->children,
            'rate' => (float) $s->rate, 'company' => $s->reservation->company?->name, 'status' => str_replace('_', ' ', $s->status)];
    }

    protected function stayCols(): array
    {
        return ['res' => 'Res. No', 'guest' => 'Guest', 'phone' => 'Phone', 'room' => 'Room', 'type' => 'Room type', 'arr' => 'Arrival', 'dep' => 'Departure', 'nights' => 'Nights', 'pax' => 'A/C', 'rate' => 'Rate', 'company' => 'Company', 'status' => 'Status'];
    }

    protected function r_ap_ageing($from, $to): array
    {
        $rows = \App\Services\ApService::ageing($to->toDateString())->map(fn ($r) => $r + ['overdue' => round($r['d30'] + $r['d60'] + $r['d90'] + $r['d90p'], 2)]);

        return ['cols' => ['supplier' => 'Supplier', 'terms' => 'Terms (days)', 'current' => 'Not due', 'd30' => '1–30', 'd60' => '31–60', 'd90' => '61–90', 'd90p' => '> 90',
            'overdue' => 'Total overdue', 'credit' => 'Unapplied credit', 'balance' => 'Balance', 'unbilled' => 'Of which unbilled', 'oldest' => 'Oldest (days past due)'],
            'rows' => $rows, 'money' => ['current', 'd30', 'd60', 'd90', 'd90p', 'overdue', 'credit', 'balance', 'unbilled'], 'sum' => ['current', 'd30', 'd60', 'd90', 'd90p', 'overdue', 'credit', 'balance', 'unbilled']];
    }

    protected function r_ap_register($from, $to): array
    {
        $rows = \App\Models\SupplierInvoice::with('supplier', 'purchaseOrder')->whereDate('doc_date', '>=', $from)->whereDate('doc_date', '<=', $to)->orderBy('doc_date')->orderBy('id')->get()
            ->map(fn ($b) => ['doc' => $b->doc_no, 'date' => $b->doc_date->format('d-m-Y'), 'type' => $b->isDebitNote() ? 'Debit note' : ($b->kind === 'goods' ? 'Goods' : 'Expense'),
                'supplier' => $b->supplier?->name, 'ref' => $b->supplier_ref, 'po' => $b->purchaseOrder?->po_no, 'due' => $b->isDebitNote() ? '' : $b->due_date?->format('d-m-Y'),
                'subtotal' => ($b->isDebitNote() ? -1 : 1) * (float) $b->subtotal, 'tax' => ($b->isDebitNote() ? -1 : 1) * (float) $b->tax, 'total' => ($b->isDebitNote() ? -1 : 1) * (float) $b->total,
                'variance' => $b->variance(), 'allocated' => (float) $b->allocated, 'outstanding' => $b->isDebitNote() ? 0 : $b->outstanding(),
                'status' => \App\Models\SupplierInvoice::STATUSES[$b->status] ?? $b->status]);

        return ['cols' => ['doc' => 'Document', 'date' => 'Date', 'type' => 'Type', 'supplier' => 'Supplier', 'ref' => 'Supplier inv.', 'po' => 'PO', 'due' => 'Due',
            'subtotal' => 'Subtotal', 'tax' => 'Tax', 'total' => 'Total', 'variance' => 'Variance', 'allocated' => 'Paid / applied', 'outstanding' => 'Outstanding', 'status' => 'Status'],
            'rows' => $rows, 'money' => ['subtotal', 'tax', 'total', 'variance', 'allocated', 'outstanding'], 'sum' => ['subtotal', 'tax', 'total', 'variance', 'outstanding']];
    }

    protected function r_payroll_register($from, $to): array
    {
        $std = ['BASIC', 'OT', 'SSNIT', 'PAYE', 'ADV'];
        $rows = \App\Models\Payslip::with('employee.department', 'run', 'lines')
            ->whereHas('run', fn ($q) => $q->whereBetween('period_start', [$from->copy()->startOfMonth()->toDateString(), $to->toDateString()]))
            ->get()->sortBy(fn ($p) => $p->run->period_start->format('Ymd').$p->employee->emp_code)->values()
            ->map(function ($p) use ($std) {
                $by = $p->lines->groupBy('code')->map(fn ($g) => (float) $g->sum('amount'));
                $earn = $p->lines->where('kind', 'earning');
                $ded = $p->lines->where('kind', 'deduction');

                return ['month' => $p->run->period_start->format('M Y'), 'status' => ucfirst($p->run->status), 'code' => $p->employee->emp_code, 'employee' => $p->employee->name,
                    'dept' => $p->employee->department?->name, 'paid_days' => (float) $p->paid_days, 'lop' => (float) $p->lop_days, 'ot_hours' => (float) $p->ot_hours,
                    'basic' => $by['BASIC'] ?? 0, 'allow' => round((float) $earn->whereNotIn('code', $std)->sum('amount'), 2), 'ot' => $by['OT'] ?? 0, 'gross' => (float) $p->gross,
                    'ssnit' => $by['SSNIT'] ?? 0, 'paye' => $by['PAYE'] ?? 0, 'adv' => $by['ADV'] ?? 0,
                    'other_ded' => round((float) $ded->whereNotIn('code', $std)->sum('amount'), 2), 'net' => (float) $p->net, 'employer' => (float) $p->employer_cost];
            });
        $m = ['basic', 'allow', 'ot', 'gross', 'ssnit', 'paye', 'adv', 'other_ded', 'net', 'employer'];

        return ['cols' => ['month' => 'Month', 'status' => 'Run', 'code' => 'Code', 'employee' => 'Employee', 'dept' => 'Department', 'paid_days' => 'Paid days', 'lop' => 'LOP', 'ot_hours' => 'OT h',
            'basic' => 'Basic', 'allow' => 'Allowances & other', 'ot' => 'Overtime', 'gross' => 'Gross', 'ssnit' => 'SSNIT 5.5%', 'paye' => 'PAYE',
            'adv' => 'Advance recovery', 'other_ded' => 'Other deductions', 'net' => 'Net pay', 'employer' => 'Employer contrib.'],
            'rows' => $rows, 'money' => $m, 'sum' => array_merge(['lop', 'ot_hours'], $m)];
    }

    /** Approved / paid payroll months whose period starts in the range (YYYY-MM list). */
    protected function statMonths($from, $to): array
    {
        return \App\Models\PayrollRun::whereBetween('period_start', [Carbon::parse($from)->startOfMonth()->toDateString(), Carbon::parse($to)->toDateString()])
            ->whereIn('status', ['approved', 'paid'])->orderBy('period_start')->get()->map(fn ($r) => $r->period_start->format('Y-m'))->all();
    }

    protected function r_ssnit_schedule($from, $to): array
    {
        $rows = collect();
        foreach ($this->statMonths($from, $to) as $p) {
            $t1 = \App\Services\StatutoryService::build('ssnit', $p)['rows']->keyBy('employee_id');
            $t2 = \App\Services\StatutoryService::build('tier2', $p)['rows']->keyBy('employee_id');
            foreach ($t1->keys()->merge($t2->keys())->unique() as $id) {
                $a = $t1[$id] ?? null;
                $b = $t2[$id] ?? null;
                $x = $a ?? $b;
                $rows->push(['month' => Carbon::parse($p.'-01')->format('M Y'), 'code' => $x['emp_code'], 'name' => $x['name'], 'ssnit' => (string) $x['ssnit_no'], 'card' => (string) $x['ghana_card'],
                    'basic' => $x['basic'], 'insurable' => $x['insurable'], 't1_ee' => $a['ee'] ?? 0, 't1_er' => $a['er'] ?? 0, 't1' => $a['total'] ?? 0, 't2' => $b['total'] ?? 0,
                    'total' => round(($a['total'] ?? 0) + ($b['total'] ?? 0), 2)]);
            }
        }

        return ['cols' => ['month' => 'Month', 'code' => 'Emp. code', 'name' => 'Employee', 'ssnit' => 'SSNIT no.', 'card' => 'Ghana Card', 'basic' => 'Basic salary', 'insurable' => 'Insurable earnings',
            't1_ee' => 'Employee 5.5%', 't1_er' => 'Employer 8%', 't1' => 'Tier 1 (SSNIT)', 't2' => 'Tier 2 (5%)', 'total' => 'Total'],
            'rows' => $rows, 'money' => ['basic', 'insurable', 't1_ee', 't1_er', 't1', 't2', 'total'], 'sum' => ['basic', 'insurable', 't1_ee', 't1_er', 't1', 't2', 'total'],
            'note' => $rows->count().' contribution line(s). Tier 1 is paid to SSNIT and Tier 2 to the corporate trustee by the 14th of the following month (HR & Payroll › Statutory Returns).'];
    }

    protected function r_paye_schedule($from, $to): array
    {
        $rows = collect();
        foreach ($this->statMonths($from, $to) as $p) {
            foreach (\App\Services\StatutoryService::build('paye', $p)['rows'] as $x) {
                $rows->push(['month' => Carbon::parse($p.'-01')->format('M Y'), 'code' => $x['emp_code'], 'name' => $x['name'], 'tin' => (string) $x['ghana_card'], 'position' => (string) $x['position'],
                    'basic' => $x['basic'], 'allowances' => $x['allowances'], 'overtime' => $x['overtime'], 'gross' => $x['gross'], 'relief' => $x['relief'], 'chargeable' => $x['chargeable'], 'paye' => $x['total']]);
            }
        }

        return ['cols' => ['month' => 'Month', 'code' => 'Emp. code', 'name' => 'Employee', 'tin' => 'TIN / Ghana Card', 'position' => 'Position', 'basic' => 'Basic', 'allowances' => 'Allowances',
            'overtime' => 'Overtime', 'gross' => 'Gross', 'relief' => 'SSNIT relief', 'chargeable' => 'Chargeable income', 'paye' => 'PAYE'],
            'rows' => $rows, 'money' => ['basic', 'allowances', 'overtime', 'gross', 'relief', 'chargeable', 'paye'], 'sum' => ['basic', 'allowances', 'overtime', 'gross', 'relief', 'chargeable', 'paye'],
            'note' => $rows->count().' line(s) · '.money($rows->sum('paye')).' PAYE withheld. PAYE is due to GRA by the 15th of the following month.'];
    }

    protected function r_statutory_remittances($from, $to): array
    {
        $rows = \App\Models\StatutoryRemittance::with('paymentMode', 'creator')->whereBetween('paid_on', [Carbon::parse($from)->toDateString(), Carbon::parse($to)->toDateString()])
            ->orderBy('paid_on')->orderBy('id')->get()->map(function ($r) {
                $due = \App\Services\StatutoryService::due($r->kind, $r->period);
                $late = $r->paid_on->gt($due) ? (int) $due->diffInDays($r->paid_on) : 0;

                return ['paid' => $r->paid_on->format('d M Y'), 'no' => $r->remit_no, 'return' => \App\Services\StatutoryService::label($r->kind), 'month' => Carbon::parse($r->period.'-01')->format('M Y'),
                    'payee' => $r->payee, 'employees' => $r->employees, 'ee' => (float) $r->employee_share, 'er' => (float) $r->employer_share, 'amount' => $r->isCancelled() ? 0 : (float) $r->amount,
                    'due' => $due->format('d M Y'), 'late' => $late ?: '', 'ref' => $r->reference, 'mode' => $r->paymentMode?->name, 'status' => $r->isCancelled() ? 'Cancelled: '.$r->cancel_reason : ($late ? 'Late' : 'On time')];
            });

        return ['cols' => ['paid' => 'Paid on', 'no' => 'No.', 'return' => 'Return', 'month' => 'Payroll month', 'payee' => 'Paid to', 'employees' => 'Employees', 'ee' => 'Employee share', 'er' => 'Employer share',
            'amount' => 'Amount', 'due' => 'Due', 'late' => 'Days late', 'ref' => 'Reference', 'mode' => 'Paid from', 'status' => 'Status'],
            'rows' => $rows, 'money' => ['ee', 'er', 'amount'], 'sum' => ['amount'],
            'note' => $rows->count().' payment(s) · '.money($rows->sum('amount')).' remitted'.(($l = $rows->where('status', 'Late')->count()) ? " · {$l} paid late" : '').'.'];
    }

    /** v4.6: latest statement of each account ending on or before the date */
    protected function recStatements($to)
    {
        return \App\Services\BankRecService::accounts()->map(fn ($a) => [$a, \App\Models\BankStatement::where('account_head_id', $a->id)->where('period_to', '<=', $to->toDateString())
            ->orderByDesc('period_to')->orderByDesc('id')->first()]);
    }

    protected function r_bank_reconciliation($from, $to): array
    {
        $rows = $this->recStatements($to)->map(function ($p) use ($to) {
            [$a, $s] = $p;
            $book = \App\Services\BankRecService::glBalance($a->id, $to->toDateString());
            if (! $s) {
                return ['account' => $a->code.' '.$a->name, 'book' => $book, 'statement' => 'None yet', 'stmt_bal' => null, 'in' => null, 'out' => null, 'bank_only' => null, 'diff' => null, 'status' => 'Not started'];
            }
            $x = \App\Services\BankRecService::summary($s);

            return ['account' => $a->code.' '.$a->name, 'book' => $book, 'statement' => $s->statement_no.' to '.$s->period_to->format('d M Y'), 'stmt_bal' => $x['closing'],
                'in' => $x['uncleared_in'], 'out' => -$x['uncleared_out'], 'bank_only' => $x['bank_only'], 'diff' => $x['difference'],
                'status' => ($s->isDraft() ? 'In progress' : 'Reconciled').($s->needs_review ? ' – review' : '').($s->period_to->lt($to) ? ' (book moved on '.$s->period_to->diffInDays($to).' day(s))' : '')];
        })->values();

        return ['cols' => ['account' => 'Account', 'book' => 'Book balance', 'statement' => 'Latest statement', 'stmt_bal' => 'Statement balance', 'in' => 'Deposits in transit', 'out' => 'Unpresented payments',
            'bank_only' => 'Statement items not in books', 'diff' => 'Difference', 'status' => 'Status'],
            'rows' => $rows, 'money' => ['book', 'stmt_bal', 'in', 'out', 'bank_only', 'diff'], 'sum' => ['book'],
            'note' => 'Deposits in transit, unpresented payments and statement-only items are as at each statement’s end date; the book balance is at '.$to->format('d M Y').'.'];
    }

    protected function r_uncleared_items($from, $to): array
    {
        $rows = collect();
        foreach ($this->recStatements($to) as [$a, $s]) {
            if (! $s) {
                continue;
            }
            $x = \App\Services\BankRecService::summary($s);
            $end = $s->period_to;
            foreach ($x['U'] as $g) {
                $v = round((float) $g->debit - (float) $g->credit, 2);
                $rows->push(['account' => $a->code.' '.$a->name, 'kind' => $v > 0 ? 'Deposit in transit' : 'Unpresented payment', 'date' => $g->date->format('d M Y'), 'sort' => $g->date->toDateString(),
                    'ref' => $g->journal->journal_no, 'details' => \Illuminate\Support\Str::limit($g->journal->narration, 80), 'amount' => $v, 'days' => (int) $g->date->diffInDays($end), 'statement' => $s->statement_no]);
            }
            foreach ($x['S'] as $l) {
                $rows->push(['account' => $a->code.' '.$a->name, 'kind' => $l->status === 'ignored' ? 'Statement only (ignored)' : 'Statement only', 'date' => $l->date->format('d M Y'), 'sort' => $l->date->toDateString(),
                    'ref' => $l->reference, 'details' => trim($l->description.($l->ignore_reason ? ' – '.$l->ignore_reason : '')), 'amount' => (float) $l->amount, 'days' => (int) $l->date->diffInDays($end), 'statement' => $s->statement_no]);
            }
        }
        $rows = $rows->sortBy([['account', 'asc'], ['kind', 'asc'], ['sort', 'asc']])->values()->map(fn ($r) => \Illuminate\Support\Arr::except($r, 'sort'));

        return ['cols' => ['account' => 'Account', 'kind' => 'Item', 'date' => 'Date', 'ref' => 'Reference', 'details' => 'Details', 'amount' => 'Amount', 'days' => 'Days at statement end', 'statement' => 'Statement'],
            'rows' => $rows, 'money' => ['amount'], 'sum' => ['amount'],
            'note' => $rows->count().' item(s); '.$rows->where('days', '>', 30)->count().' more than 30 days old.'];
    }

    protected function r_bank_statement_lines($from, $to): array
    {
        $types = ['reference' => 'Auto – reference', 'amount' => 'Auto – amount', 'group' => 'Auto – batch', 'manual' => 'By hand', 'entry' => 'Booked from statement'];
        $rows = \App\Models\BankStatementLine::with('statement.account', 'glLines.journal')->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('date')->orderBy('id')->get()->map(fn ($l) => ['date' => $l->date->format('d M Y'), 'account' => $l->statement->account->code, 'statement' => $l->statement->statement_no,
                'details' => $l->description, 'ref' => $l->reference, 'in' => $l->amount > 0 ? (float) $l->amount : null, 'out' => $l->amount < 0 ? -(float) $l->amount : null,
                'status' => ucfirst($l->status), 'how' => $types[$l->match_type] ?? ($l->status === 'ignored' ? $l->ignore_reason : ''), 'journals' => $l->glLines->map(fn ($g) => $g->journal->journal_no)->unique()->implode(', ')]);

        return ['cols' => ['date' => 'Date', 'account' => 'Account', 'statement' => 'Statement', 'details' => 'Details', 'ref' => 'Reference', 'in' => 'In', 'out' => 'Out', 'status' => 'Status', 'how' => 'Matched how', 'journals' => 'Book entries'],
            'rows' => $rows, 'money' => ['in', 'out'], 'sum' => ['in', 'out'],
            'note' => $rows->count().' line(s): '.$rows->where('status', 'Matched')->count().' matched, '.$rows->where('status', 'Unmatched')->count().' open, '.$rows->where('status', 'Ignored')->count().' ignored.'];
    }

    protected function r_petty_cash_book($from, $to): array
    {
        $rows = collect();
        $sumIn = $sumOut = 0;
        foreach (\App\Models\PettyFloat::orderBy('code')->get() as $f) {
            [$open, $txns, $close] = \App\Services\PettyCashService::book($f, $from->toDateString(), $to->toDateString());
            if ($txns->isEmpty() && abs($open) < 0.005) {
                continue;
            }
            $rows->push(['date' => $from->format('d M Y'), 'float' => $f->code, 'no' => '', 'type' => 'Opening balance', 'party' => '', 'account' => '', 'in' => null, 'out' => null, 'vat' => null, 'balance' => $open]);
            foreach ($txns as $t) {
                $e = (float) $t->effect;
                $e > 0 ? $sumIn += $e : $sumOut -= $e;
                $rows->push(['date' => $t->date->format('d M Y'), 'float' => $f->code, 'no' => $t->txn_no, 'type' => $t->typeLabel(), 'party' => trim(($t->party() ?? '').($t->description ? ' – '.\Illuminate\Support\Str::limit($t->description, 50) : ''), ' –'),
                    'account' => $t->head?->label, 'in' => $e > 0 ? $e : null, 'out' => $e < 0 ? -$e : null, 'vat' => (float) $t->vat_amount ?: null, 'balance' => $t->running]);
            }
            $rows->push(['date' => $to->format('d M Y'), 'float' => $f->code, 'no' => '', 'type' => 'Closing balance', 'party' => $f->name, 'account' => '', 'in' => null, 'out' => null, 'vat' => null, 'balance' => $close]);
        }

        return ['cols' => ['date' => 'Date', 'float' => 'Float', 'no' => 'No.', 'type' => 'Type', 'party' => 'Paid to / details', 'account' => 'Account', 'in' => 'In', 'out' => 'Out', 'vat' => 'Input VAT', 'balance' => 'Balance'],
            'rows' => $rows, 'money' => ['in', 'out', 'vat', 'balance'], 'sum' => ['in', 'out', 'vat'],
            'note' => 'Cash in '.money($sumIn).', out '.money($sumOut).'. Pending (unapproved), rejected and cancelled items are left out.'];
    }

    protected function r_petty_expenses($from, $to): array
    {
        $floats = \App\Models\PettyFloat::orderBy('code')->get();
        $txns = \App\Models\PettyTxn::with('head')->where('status', 'posted')->whereIn('type', ['expense', 'settle'])->whereBetween('date', [$from->toDateString(), $to->toDateString()])->get()
            ->filter(fn ($t) => $t->type === 'expense' || (float) $t->spent > 0);
        $rows = $txns->groupBy('expense_head_id')->map(function ($g) use ($floats) {
            $r = ['account' => $g->first()->head?->label ?? '—'];
            foreach ($floats as $f) {
                $r['f'.$f->id] = round((float) $g->where('petty_float_id', $f->id)->sum(fn ($t) => $t->type === 'settle' ? (float) $t->spent : (float) $t->amount), 2) ?: null;
            }
            $r['count'] = $g->count();
            $r['vat'] = round((float) $g->sum('vat_amount'), 2) ?: null;
            $r['total'] = round((float) $g->sum(fn ($t) => $t->type === 'settle' ? (float) $t->spent : (float) $t->amount), 2);

            return $r;
        })->sortByDesc('total')->values();
        $cols = ['account' => 'Expense account'] + $floats->mapWithKeys(fn ($f) => ['f'.$f->id => $f->name])->all() + ['count' => 'Items', 'vat' => 'Input VAT', 'total' => 'Total'];
        $money = array_merge($floats->map(fn ($f) => 'f'.$f->id)->all(), ['vat', 'total']);

        return ['cols' => $cols, 'rows' => $rows, 'money' => $money, 'sum' => array_merge($money, ['count']),
            'note' => 'Gross amounts paid (VAT-inclusive). Expense claims paid from petty cash are in the claims register, not here.'];
    }

    protected function r_expense_claims($from, $to): array
    {
        $rows = \App\Models\ExpenseClaim::with('employee.department', 'approver', 'paymentMode', 'pettyTxn.float')->whereNotNull('submitted_at')
            ->whereBetween('submitted_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])->orderBy('submitted_at')->get()
            ->map(fn ($c) => ['claim' => $c->claim_no, 'submitted' => $c->submitted_at->format('d M Y'), 'staff' => $c->employee?->name, 'dept' => $c->employee?->department?->name, 'title' => $c->title,
                'total' => (float) $c->total, 'vat' => (float) $c->vat_total ?: null, 'status' => ucfirst($c->status).($c->status === 'rejected' && $c->reject_reason ? ' – '.$c->reject_reason : ''),
                'approver' => in_array($c->status, ['approved', 'paid']) ? $c->approver?->name : '', 'paid' => $c->paid_on?->format('d M Y'),
                'method' => $c->status === 'paid' ? ($c->pay_method === 'petty' ? 'Petty cash – '.$c->pettyTxn?->float?->code : $c->paymentMode?->name) : '']);

        return ['cols' => ['claim' => 'Claim', 'submitted' => 'Submitted', 'staff' => 'Staff', 'dept' => 'Department', 'title' => 'Title', 'total' => 'Amount', 'vat' => 'Input VAT', 'status' => 'Status', 'approver' => 'Approved by', 'paid' => 'Paid', 'method' => 'Paid by'],
            'rows' => $rows, 'money' => ['total', 'vat'], 'sum' => ['total', 'vat'],
            'note' => $rows->count().' claim(s): '.money($rows->where('paid', '!=', null)->sum('total')).' paid, '.money($rows->filter(fn ($r) => str_starts_with($r['status'], 'Approved'))->sum('total')).' approved and unpaid (2260 Staff Expense Claims Payable).'];
    }

    protected function r_payroll_statutory($from, $to): array
    {
        $lines = \App\Models\PayslipLine::with('payslip.run')->whereIn('kind', ['deduction', 'employer'])->whereNull('staff_advance_id')->where('is_manual', false)
            ->whereHas('payslip.run', fn ($q) => $q->whereIn('status', ['approved', 'paid'])->whereBetween('period_start', [$from->copy()->startOfMonth()->toDateString(), $to->toDateString()]))->get();
        $heads = \App\Models\AccountHead::pluck('name', 'id');
        $rows = $lines->groupBy(fn ($l) => $l->payslip->run->period_start->format('Y-m').'|'.$l->liability_head_id)->map(function ($g) use ($heads) {
            $run = $g->first()->payslip->run;
            $emp = (float) $g->where('kind', 'deduction')->sum('amount');
            $er = (float) $g->where('kind', 'employer')->sum('amount');

            return ['month' => $run->period_start->format('M Y'), 'sort' => $run->period_start->format('Ym'), 'liability' => $heads[$g->first()->liability_head_id] ?? '—',
                'components' => $g->pluck('code')->unique()->implode(', '), 'employees' => $g->pluck('payslip.employee_id')->unique()->count(),
                'employee_share' => round($emp, 2), 'employer_share' => round($er, 2), 'total' => round($emp + $er, 2), 'status' => ucfirst($run->status)];
        })->sortBy(fn ($r) => $r['sort'].$r['liability'])->values()->map(fn ($r) => \Illuminate\Support\Arr::except($r, 'sort'));

        return ['cols' => ['month' => 'Month', 'liability' => 'Payable to (account)', 'components' => 'Components', 'employees' => 'Employees', 'employee_share' => 'Deducted from staff',
            'employer_share' => 'Employer contribution', 'total' => 'Total due', 'status' => 'Run'],
            'rows' => $rows, 'money' => ['employee_share', 'employer_share', 'total'], 'sum' => ['employee_share', 'employer_share', 'total']];
    }

    protected function r_online_payments($from, $to): array
    {
        $f = $from->copy()->startOfDay();
        $t = $to->copy()->endOfDay();
        $rows = \App\Models\OnlinePayment::with('reservation', 'folioTransaction.folio')
            ->where(fn ($q) => $q->whereBetween('created_at', [$f, $t])->orWhereBetween('paid_at', [$f, $t]))->orderBy('id')->get()
            ->map(fn ($p) => ['no' => $p->pay_no, 'created' => $p->created_at->format('d-m-Y H:i'), 'purpose' => \App\Models\OnlinePayment::PURPOSES[$p->purpose] ?? $p->purpose,
                'guest' => $p->payer_name, 'res' => $p->reservation?->reservation_no, 'folio' => $p->folioTransaction?->folio?->folio_no, 'gateway' => ucfirst($p->provider),
                'txn' => $p->provider_payment_id, 'channel' => $p->channel ? str_replace('_', ' ', $p->channel) : '', 'status' => $p->statusLabel(),
                'paid_at' => $p->paid_at?->format('d-m-Y H:i'), 'amount' => (float) $p->amount,
                'received' => in_array($p->status, ['paid', 'review']) ? $p->received() : 0.0, 'refunded' => (float) $p->refunded_amount,
                'net' => in_array($p->status, ['paid', 'review']) ? round($p->received() - (float) $p->refunded_amount, 2) : 0.0, 'fee' => (float) $p->fee]);

        return ['cols' => ['no' => 'Link', 'created' => 'Created', 'purpose' => 'Purpose', 'guest' => 'Guest', 'res' => 'Reservation', 'folio' => 'Folio', 'gateway' => 'Gateway',
            'txn' => 'Transaction', 'channel' => 'Channel', 'status' => 'Status', 'paid_at' => 'Paid at', 'amount' => 'Requested', 'received' => 'Received', 'refunded' => 'Refunded',
            'net' => 'Net', 'fee' => 'Gateway fee'], 'rows' => $rows, 'money' => ['amount', 'received', 'refunded', 'net', 'fee'], 'sum' => ['received', 'refunded', 'net', 'fee']];
    }

    protected function r_laundry_register($from, $to): array
    {
        $rows = \App\Models\LaundryOrder::with('room', 'folio', 'paymentMode')
            ->whereBetween('collected_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])->orderBy('collected_at')->orderBy('id')->get()
            ->map(fn ($o) => ['no' => $o->order_no, 'collected' => $o->collected_at?->format('d-m-Y H:i'), 'type' => $o->type === 'walk_in' ? 'Walk-in' : 'Guest',
                'room' => $o->room?->room_no, 'guest' => $o->guest_name, 'speed' => \App\Services\LaundryService::SPEEDS[$o->speed] ?? $o->speed, 'pieces' => (int) $o->pieces,
                'subtotal' => (float) $o->subtotal, 'express' => (float) $o->express_charge, 'total' => $o->status === 'cancelled' ? 0.0 : (float) $o->total,
                'status' => $o->statusLabel().($o->isOverdue() ? ' (overdue)' : ''), 'delivered' => $o->delivered_at?->format('d-m-Y H:i'),
                'charged' => $o->folio ? ($o->type === 'walk_in' ? 'Invoice '.$o->folio->invoice_no.($o->paymentMode ? ' – '.$o->paymentMode->name : '') : 'Folio '.$o->folio->folio_no) : ''])->values();

        return ['cols' => ['no' => 'Order', 'collected' => 'Collected', 'type' => 'Type', 'room' => 'Room', 'guest' => 'Guest / customer', 'speed' => 'Service', 'pieces' => 'Pieces',
            'subtotal' => 'Items', 'express' => 'Express', 'total' => 'Amount', 'status' => 'Status', 'delivered' => 'Delivered', 'charged' => 'Charged to'],
            'rows' => $rows, 'money' => ['subtotal', 'express', 'total'], 'sum' => ['pieces', 'subtotal', 'express', 'total']];
    }

    protected function r_linen_par($from, $to): array
    {
        $rows = \App\Services\LinenService::parReport()->map(fn ($t) => ['code' => $t->code, 'name' => $t->name, 'per_room' => (float) $t->per_room, 'par' => $t->par_qty,
            'store' => (int) $t->qty_store, 'floor' => (int) $t->qty_floor, 'soiled' => (int) $t->qty_soiled, 'laundry' => (int) $t->qty_laundry, 'total' => $t->total_qty,
            'short' => $t->short, 'cover' => $t->cover === null ? '' : $t->cover.'%', 'cost' => (float) $t->unit_cost, 'value' => round($t->total_qty * (float) $t->unit_cost, 2)])->values();

        return ['cols' => ['code' => 'Code', 'name' => 'Linen', 'per_room' => 'Per room', 'par' => 'Par', 'store' => 'Clean (store)', 'floor' => 'Floors', 'soiled' => 'Soiled',
            'laundry' => 'At laundry', 'total' => 'Total', 'short' => 'Short of par', 'cover' => 'Cover', 'cost' => 'Unit cost', 'value' => 'Stock value'],
            'rows' => $rows, 'money' => ['cost', 'value'], 'sum' => ['par', 'store', 'floor', 'soiled', 'laundry', 'total', 'short', 'value']];
    }

    protected function r_linen_losses($from, $to): array
    {
        $rows = \App\Models\LinenMovement::with('linen', 'batch.supplier', 'user')->whereIn('type', ['discard', 'lost'])
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])->orderBy('date')->orderBy('id')->get()
            ->map(fn ($m) => ['date' => $m->date->format('d-m-Y'), 'code' => $m->linen?->code, 'name' => $m->linen?->name, 'type' => \App\Models\LinenMovement::TYPES[$m->type],
                'where' => \App\Models\LinenType::LOCATIONS[$m->from_loc] ?? $m->from_loc, 'batch' => $m->batch ? $m->batch->batch_no.' – '.($m->batch->supplier?->name ?? 'in-house') : '',
                'qty' => (int) $m->qty, 'value' => (float) $m->value, 'reason' => $m->reason, 'by' => $m->user?->name])->values();

        return ['cols' => ['date' => 'Date', 'code' => 'Code', 'name' => 'Linen', 'type' => 'Write-off', 'where' => 'From', 'batch' => 'Laundry batch', 'qty' => 'Pieces',
            'value' => 'Value at cost', 'reason' => 'Reason', 'by' => 'Recorded by'], 'rows' => $rows, 'money' => ['value'], 'sum' => ['qty', 'value']];
    }

    /** Accumulated depreciation per asset id up to (and including) a date: brought in + posted runs. */
    protected function assetAcc(string $asOf, ?string $after = null): \Illuminate\Support\Collection
    {
        return \App\Models\AssetDepLine::query()->join('asset_dep_runs as r', 'r.id', '=', 'asset_dep_lines.asset_dep_run_id')
            ->where('r.status', 'posted')->where('r.period_end', '<=', $asOf)->when($after, fn ($q) => $q->where('r.period_end', '>', $after))
            ->groupBy('asset_dep_lines.asset_id')->selectRaw('asset_dep_lines.asset_id, SUM(asset_dep_lines.amount) amt')->pluck('amt', 'asset_id');
    }

    protected function r_req_register($from, $to): array
    {
        $qf = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.');
        $reqs = \App\Models\Requisition::with('department', 'fromStore', 'toStore', 'raiser', 'approver', 'lines.item', 'issues')
            ->whereDate('req_date', '>=', $from->toDateString())->whereDate('req_date', '<=', $to->toDateString())->orderBy('req_date')->orderBy('id')->get();
        $rows = $reqs->map(function ($r) {
            $approvedQty = $r->lines->sum(fn ($l) => (float) ($l->qty_approved ?? 0));
            $issuedQty = $r->lines->sum(fn ($l) => (float) $l->qty_issued);

            return ['no' => $r->req_no, 'date' => $r->req_date->format('d-m-Y'), 'dept' => $r->department?->name, 'from' => $r->fromStore?->name, 'to' => $r->destination(),
                'status' => $r->statusLabel(), 'items' => $r->lines->count(), 'requested' => \App\Services\RequisitionService::valueOf($r->lines, 'qty_requested'),
                'approved' => $r->approved_at && $r->status !== 'rejected' ? (float) $r->est_value : 0.0, 'issued' => round((float) $r->issues->sum('value'), 2),
                'fill' => $approvedQty > 0 ? round($issuedQty / $approvedQty * 100, 1).' %' : '', 'raised' => $r->raiser?->name,
                'approver' => $r->status === 'rejected' ? 'Rejected – '.$r->approver?->name : ($r->approver?->name ?? ($r->approved_at ? 'Auto' : '')),
                'tat' => $r->turnaroundHours(), 'over' => $r->over_budget ? 'Yes' : ''];
        })->values();
        $tats = $rows->pluck('tat')->filter(fn ($v) => $v !== null);
        $appr = $reqs->filter(fn ($r) => $r->approved_at && $r->status !== 'rejected');

        return ['cols' => ['no' => 'Requisition', 'date' => 'Date', 'dept' => 'Department', 'from' => 'From', 'to' => 'To', 'status' => 'Status', 'items' => 'Items',
            'requested' => 'Requested value', 'approved' => 'Approved value', 'issued' => 'Issued value', 'fill' => 'Fill rate', 'raised' => 'Raised by', 'approver' => 'Approved by',
            'tat' => 'Turnaround (h)', 'over' => 'Over budget'],
            'rows' => $rows, 'money' => ['requested', 'approved', 'issued'], 'sum' => ['requested', 'approved', 'issued'],
            'summary' => ['Requisitions' => (string) $rows->count(), 'Approved' => (string) $appr->count(), 'Rejected' => (string) $reqs->where('status', 'rejected')->count(),
                'Issued value' => money($rows->sum('issued')), 'Fill rate (approved qty issued)' => ($q = $appr->sum(fn ($r) => $r->lines->sum(fn ($l) => (float) $l->qty_approved))) > 0 ? round($appr->sum(fn ($r) => $r->lines->sum('qty_issued')) / $q * 100, 1).' %' : '—',
                'Average turnaround' => $tats->count() ? round($tats->avg(), 1).' h' : '—'],
            'note' => 'Turnaround = hours from submission to the first issue note. Fill rate = quantity issued ÷ quantity approved.'];
    }

    protected function r_dept_consumption($from, $to): array
    {
        $f = $from->toDateString();
        $t = $to->toDateString();
        $issues = \App\Models\StoreIssue::whereBetween('date', [$f, $t])->get()->groupBy('department_id');
        $adhoc = \App\Models\StockMovement::with('item')->where('type', 'issue')->whereNull('store_issue_id')->whereNotNull('department_id')->whereBetween('date', [$f, $t])->get()
            ->groupBy('department_id')->map(fn ($g) => round($g->sum(fn ($m) => -(float) $m->qty * ((float) $m->rate ?: (float) $m->item?->last_cost)), 2));
        $reqCount = \App\Models\Requisition::whereDate('req_date', '>=', $f)->whereDate('req_date', '<=', $t)->whereNotIn('status', ['draft', 'cancelled'])->selectRaw('department_id, COUNT(*) n')->groupBy('department_id')->pluck('n', 'department_id');
        $occupied = (int) \App\Models\NightAudit::whereDate('business_date', '>=', $f)->whereDate('business_date', '<=', $t)->sum('rooms_occupied');
        // budget pro-rated by the days of each month the period covers
        $factor = 0.0;
        for ($m = $from->copy()->startOfMonth(); $m->lte($to); $m->addMonth()) {
            $a = $m->copy()->max($from);
            $b = $m->copy()->endOfMonth()->min($to);
            $factor += ($a->diffInDays($b) + 1) / $m->daysInMonth;
        }
        $rows = \App\Models\Department::with('expenseHead')->orderBy('name')->get()->map(function ($d) use ($issues, $adhoc, $reqCount, $occupied, $factor) {
            $g = $issues[$d->id] ?? collect();
            $direct = round((float) $g->whereNull('to_store_id')->sum('value'), 2);
            $transfer = round((float) $g->whereNotNull('to_store_id')->sum('value'), 2);
            $other = (float) ($adhoc[$d->id] ?? 0);
            $total = round($direct + $transfer + $other, 2);
            $budget = $d->monthly_budget ? round((float) $d->monthly_budget * $factor, 2) : null;

            return ['dept' => $d->name, 'account' => $d->expenseHead ? $d->expenseHead->code.' '.$d->expenseHead->name : 'by store type', 'reqs' => (int) ($reqCount[$d->id] ?? 0),
                'direct' => $direct, 'transfer' => $transfer, 'other' => $other, 'total' => $total, 'budget' => $budget,
                'variance' => $budget === null ? null : round($budget - $total, 2), 'used' => $budget ? round($total / $budget * 100, 1).' %' : '',
                'per_room' => $occupied ? round($total / $occupied, 2) : null];
        })->filter(fn ($r) => $r['total'] > 0 || $r['budget'] !== null || $r['reqs'])->values();

        return ['cols' => ['dept' => 'Department', 'account' => 'Cost account', 'reqs' => 'Requisitions', 'direct' => 'Issued (expensed)', 'transfer' => 'To own store',
            'other' => 'Ad-hoc issues', 'total' => 'Total', 'budget' => 'Budget (pro-rated)', 'variance' => 'Under / (over)', 'used' => 'Used', 'per_room' => 'Per occupied room'],
            'rows' => $rows, 'money' => ['direct', 'transfer', 'other', 'total', 'budget', 'variance', 'per_room'], 'sum' => ['direct', 'transfer', 'other', 'total', 'budget', 'variance'],
            'summary' => ['Issued to departments' => money($rows->sum('total')), 'Budget for the period' => money($rows->sum('budget')), 'Occupied room nights' => (string) $occupied,
                'Cost per occupied room' => $occupied ? money($rows->sum('total') / $occupied) : '—'],
            'note' => 'At cost. "To own store" is stock transferred into the department\'s store (kitchen, bar…) – it is expensed there when used. Budgets are monthly, pro-rated by the days of each month in the period. Occupied room nights come from the night audits.'];
    }

    protected function r_req_pending($from, $to): array
    {
        $qf = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.');
        $reqs = \App\Models\Requisition::with('department', 'fromStore', 'lines.item')->whereIn('status', \App\Models\Requisition::OPEN_FOR_ISSUE)->orderBy('req_date')->get();
        $stock = \App\Models\StockMovement::groupBy('inventory_item_id', 'store_id')->selectRaw('inventory_item_id, store_id, SUM(qty) q')->get()->keyBy(fn ($m) => $m->inventory_item_id.':'.$m->store_id);
        $rows = collect();
        foreach ($reqs as $r) {
            foreach ($r->lines as $l) {
                if (($out = $l->outstanding()) <= 0) {
                    continue;
                }
                $have = round((float) ($stock[$l->inventory_item_id.':'.$r->from_store_id]->q ?? 0), 2);
                $rows->push(['no' => $r->req_no, 'date' => $r->req_date->format('d-m-Y'), 'dept' => $r->department?->name, 'store' => $r->fromStore?->name,
                    'item' => $l->item?->name, 'unit' => $l->item?->unit, 'approved' => $qf($l->qty_approved), 'issued' => $qf($l->qty_issued), 'balance' => $qf($out),
                    'stock' => $qf($have), 'state' => $have + 0.0001 >= $out ? 'Can issue now' : ($have > 0 ? 'Part in stock' : 'Out of stock'),
                    'value' => round($out * (float) $l->item?->last_cost, 2), 'age' => (int) $r->req_date->diffInDays(bdate()), 'needed' => $r->required_by?->format('d-m-Y')]);
            }
        }

        return ['cols' => ['no' => 'Requisition', 'date' => 'Date', 'dept' => 'Department', 'store' => 'Issuing store', 'item' => 'Item', 'unit' => 'Unit', 'approved' => 'Approved',
            'issued' => 'Issued', 'balance' => 'Balance', 'stock' => 'In store', 'state' => 'Status', 'value' => 'Balance value', 'age' => 'Age (days)', 'needed' => 'Needed by'],
            'rows' => $rows, 'money' => ['value'], 'sum' => ['value'],
            'summary' => ['Open lines' => (string) $rows->count(), 'Can issue now' => (string) $rows->where('state', 'Can issue now')->count(), 'Out of stock' => (string) $rows->where('state', 'Out of stock')->count(), 'Balance value' => money($rows->sum('value'))],
            'note' => 'Every approved or part-issued requisition line with a balance, as at now.'];
    }

    protected function r_asset_register($from, $to): array
    {
        $d = $to->toDateString();
        $runs = $this->assetAcc($d);
        $rows = \App\Models\Asset::with('category', 'room', 'custodian')->whereDate('purchase_date', '<=', $d)
            ->where(fn ($q) => $q->whereNull('disposed_date')->whereDate('disposed_date', '>', $d))->orderBy('asset_no')->get()
            ->map(function ($a) use ($runs) {
                $acc = round((float) $a->opening_acc_dep + (float) ($runs[$a->id] ?? 0), 2);

                return ['no' => $a->asset_no, 'name' => $a->name, 'category' => $a->category?->name, 'location' => $a->placeLabel(), 'dept' => $a->department, 'serial' => $a->serial_no,
                    'purchased' => $a->purchase_date->format('d-m-Y'), 'method' => $a->method === 'wdv' ? 'WDV '.(float) $a->wdv_rate.'%' : 'SLM '.$a->life_months.'m',
                    'cost' => (float) $a->cost, 'acc' => $acc, 'nbv' => round((float) $a->cost - $acc, 2), 'warranty' => $a->warranty_until?->format('d-m-Y'), 'custodian' => $a->custodian?->name];
            })->values();

        return ['cols' => ['no' => 'Asset no', 'name' => 'Asset', 'category' => 'Category', 'location' => 'Location', 'dept' => 'Department', 'serial' => 'Serial', 'purchased' => 'Purchased',
            'method' => 'Method', 'cost' => 'Cost', 'acc' => 'Acc. depreciation', 'nbv' => 'Net book value', 'warranty' => 'Warranty to', 'custodian' => 'Custodian'],
            'rows' => $rows, 'money' => ['cost', 'acc', 'nbv'], 'sum' => ['cost', 'acc', 'nbv'],
            'summary' => ['Assets' => (string) $rows->count(), 'Cost' => money($rows->sum('cost')), 'Accumulated depreciation' => money($rows->sum('acc')), 'Net book value' => money($rows->sum('nbv'))],
            'note' => 'As at '.$to->format('d M Y').'. Accumulated depreciation = amount brought in at go-live + depreciation runs posted for months ending on or before this date.'];
    }

    protected function r_asset_schedule($from, $to): array
    {
        $f = $from->toDateString();
        $t = $to->toDateString();
        $before = $from->copy()->subDay()->toDateString();
        $accOpen = $this->assetAcc($before);
        $charge = $this->assetAcc($t, $before);
        $rows = \App\Models\Asset::with('category')->whereDate('purchase_date', '<=', $t)
            ->where(fn ($q) => $q->whereNull('disposed_date')->whereDate('disposed_date', '>=', $f))->orderBy('asset_no')->get()
            ->map(function ($a) use ($f, $t, $accOpen, $charge) {
                $isNew = $a->purchase_date->toDateString() >= $f;
                $gone = $a->disposed_date && $a->disposed_date->toDateString() <= $t;
                $openCost = $isNew ? 0.0 : (float) $a->cost;
                $add = $isNew ? (float) $a->cost : 0.0;
                $dispCost = $gone ? (float) $a->cost : 0.0;
                $openAcc = $isNew ? 0.0 : round((float) $a->opening_acc_dep + (float) ($accOpen[$a->id] ?? 0), 2);
                $chg = round((float) ($charge[$a->id] ?? 0) + ($isNew ? (float) $a->opening_acc_dep : 0), 2);
                $dispAcc = $gone ? round($openAcc + $chg, 2) : 0.0;
                $closeCost = round($openCost + $add - $dispCost, 2);
                $closeAcc = round($openAcc + $chg - $dispAcc, 2);

                return ['no' => $a->asset_no, 'name' => $a->name, 'category' => $a->category?->name, 'open_cost' => $openCost, 'additions' => $add, 'disposals' => $dispCost,
                    'close_cost' => $closeCost, 'open_acc' => $openAcc, 'charge' => $chg, 'disp_acc' => $dispAcc, 'close_acc' => $closeAcc, 'nbv' => round($closeCost - $closeAcc, 2),
                    'gain' => $gone ? round((float) $a->disposal_proceeds - (float) $a->disposal_nbv, 2) : 0.0];
            })->values();
        $m = ['open_cost', 'additions', 'disposals', 'close_cost', 'open_acc', 'charge', 'disp_acc', 'close_acc', 'nbv', 'gain'];

        return ['cols' => ['no' => 'Asset no', 'name' => 'Asset', 'category' => 'Category', 'open_cost' => 'Opening cost', 'additions' => 'Additions', 'disposals' => 'Disposals',
            'close_cost' => 'Closing cost', 'open_acc' => 'Opening acc. dep.', 'charge' => 'Charge for period', 'disp_acc' => 'On disposals', 'close_acc' => 'Closing acc. dep.',
            'nbv' => 'Closing NBV', 'gain' => 'Gain / (loss)'], 'rows' => $rows, 'money' => $m, 'sum' => $m,
            'note' => 'Charge for the period = depreciation runs posted for months ending in the range (for assets added in the period, it also includes any depreciation brought in). Closing cost and closing accumulated depreciation tie to accounts 1510 and 1590 on the balance sheet.'];
    }

    protected function r_pm_compliance($from, $to): array
    {
        $c = \App\Services\PmService::compliance($from->toDateString(), $to->toDateString());
        $rows = $c['rows']->sortBy('due_date')->map(function ($tk) {
            $done = $tk->resolved_at ? Carbon::parse($tk->resolved_at)->startOfDay() : null;
            $late = $done ? max(0, (int) $tk->due_date->diffInDays($done, false)) : max(0, (int) $tk->due_date->diffInDays(bdate(), false));

            return ['ticket' => $tk->ticket_no, 'task' => $tk->schedule?->title ?? $tk->title, 'asset' => $tk->asset ? $tk->asset->asset_no.' '.$tk->asset->name : ($tk->location ?? ''),
                'freq' => $tk->schedule?->frequency(), 'due' => $tk->due_date->format('d-m-Y'), 'done' => $done?->format('d-m-Y') ?? '',
                'result' => ! $done ? 'Open' : ($late ? 'Late' : 'On time'), 'late' => $late, 'tech' => $tk->employee?->name ?? $tk->schedule?->vendor, 'cost' => (float) $tk->cost];
        })->values();

        return ['cols' => ['ticket' => 'Ticket', 'task' => 'Task', 'asset' => 'Asset / location', 'freq' => 'Frequency', 'due' => 'Due', 'done' => 'Done', 'result' => 'Result',
            'late' => 'Days late', 'tech' => 'Technician / vendor', 'cost' => 'Cost'], 'rows' => $rows, 'money' => ['cost'], 'sum' => ['cost'],
            'summary' => ['PM tasks due' => (string) $c['total'], 'On time' => (string) $c['on_time'], 'Late' => (string) $c['late'], 'Still open' => (string) $c['open'], 'On-time %' => $c['pct'] === null ? '—' : $c['pct'].' %']];
    }

    protected function r_leave_register($from, $to): array
    {
        $rows = \App\Models\LeaveRequest::with('employee.department', 'leaveType', 'decider')
            ->where('date_from', '<=', $to->toDateString())->where('date_to', '>=', $from->toDateString())->orderBy('date_from')->get()
            ->map(fn ($r) => ['employee' => $r->employee->name, 'code' => $r->employee->emp_code, 'dept' => $r->employee->department?->name, 'type' => $r->leaveType->code.' – '.$r->leaveType->name,
                'paid' => $r->leaveType->is_paid ? 'Paid' : 'Unpaid', 'from' => $r->date_from->format('d-m-Y'), 'to' => $r->date_to->format('d-m-Y'), 'days' => (float) $r->days,
                'status' => ucfirst($r->status), 'reason' => $r->reason, 'decided' => $r->decider ? $r->decider->name.' '.$r->decided_at?->format('d-m-Y') : '', 'note' => $r->decision_note]);

        return ['cols' => ['employee' => 'Employee', 'code' => 'Code', 'dept' => 'Department', 'type' => 'Leave type', 'paid' => 'Paid?', 'from' => 'From', 'to' => 'To', 'days' => 'Days',
            'status' => 'Status', 'reason' => 'Reason', 'decided' => 'Decided by', 'note' => 'Note'], 'rows' => $rows, 'sum' => ['days']];
    }

    protected function r_rm_forecast($from, $to): array
    {
        $rows = collect(\App\Services\RevenueService::forecast($from, $from->diffInDays($to) + 1))->map(fn ($r) => [
            'date' => Carbon::parse($r['date'])->format('d-m-Y'), 'dow' => $r['dow'], 'lead' => $r['lead'], 'rooms' => $r['rooms'], 'held' => $r['held'], 'occ' => $r['occ'],
            'adr' => $r['adr'], 'revenue' => $r['revenue'], 'pickup7' => $r['pickup7'] ?? '', 'fc_rooms' => $r['fc_rooms'], 'fc_occ' => $r['fc_occ'], 'fc_revenue' => $r['fc_revenue'],
        ])->values();

        return ['cols' => ['date' => 'Date', 'dow' => 'Day', 'lead' => 'Lead (d)', 'rooms' => 'Booked', 'held' => 'Held', 'occ' => 'OTB occ %', 'adr' => 'ADR', 'revenue' => 'Revenue',
            'pickup7' => 'Pickup 7d', 'fc_rooms' => 'Forecast rooms', 'fc_occ' => 'Forecast occ %', 'fc_revenue' => 'Forecast revenue'],
            'rows' => $rows, 'money' => ['adr', 'revenue', 'fc_revenue'], 'sum' => ['rooms', 'held', 'revenue', 'fc_rooms', 'fc_revenue'],
            'chart' => ['label' => 'Forecast occupancy %', 'key' => 'fc_occ', 'x' => 'date']];
    }

    protected function r_rm_pickup($from, $to): array
    {
        $res = Reservation::with('rooms')->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])->get()->groupBy(fn ($r) => $r->created_at->toDateString());
        $cx = Reservation::with('rooms')->whereBetween('cancelled_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])->get()->groupBy(fn ($r) => $r->cancelled_at->toDateString());
        $nights = fn ($g) => $g->sum(fn ($r) => $r->rooms->sum(fn ($s) => $s->nights));
        $value = fn ($g) => $g->sum(fn ($r) => $r->rooms->sum(fn ($s) => $s->nights * $s->nightlyTotal()));
        $rows = [];
        for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
            $g = $res[$d->toDateString()] ?? collect();
            $c = $cx[$d->toDateString()] ?? collect();
            $n = $nights($g);
            $rows[] = ['date' => $d->format('d-m-Y'), 'dow' => $d->format('D'), 'bookings' => $g->count(), 'nights' => $n, 'revenue' => round($value($g), 2),
                'rate' => $n ? round($value($g) / $n, 2) : 0, 'lead' => $g->count() ? round($g->avg(fn ($r) => max(0, $r->created_at->copy()->startOfDay()->diffInDays($r->arrival_date, false))), 1) : '',
                'cx' => $c->count(), 'cx_nights' => $nights($c), 'cx_revenue' => round($value($c), 2), 'net' => $n - $nights($c)];
        }

        return ['cols' => ['date' => 'Booked on', 'dow' => 'Day', 'bookings' => 'Bookings', 'nights' => 'Room nights', 'revenue' => 'Room revenue', 'rate' => 'Avg rate', 'lead' => 'Avg lead (d)',
            'cx' => 'Cancellations', 'cx_nights' => 'Nights lost', 'cx_revenue' => 'Revenue lost', 'net' => 'Net nights'],
            'rows' => collect($rows), 'money' => ['revenue', 'rate', 'cx_revenue'], 'sum' => ['bookings', 'nights', 'revenue', 'cx', 'cx_nights', 'cx_revenue', 'net'],
            'chart' => ['label' => 'Room nights booked', 'key' => 'nights', 'x' => 'date']];
    }

    protected function r_arrivals($from, $to): array
    {
        $rows = ReservationRoom::with('reservation.guest', 'reservation.company', 'guest', 'room', 'roomType')->whereDate('arrival_date', '>=', $from)->whereDate('arrival_date', '<=', $to)
            ->whereIn('status', ['reserved', 'checked_in', 'checked_out'])->orderBy('arrival_date')->get()->map(fn ($s) => $this->stayRow($s));

        return ['cols' => $this->stayCols(), 'rows' => $rows, 'money' => ['rate'], 'sum' => ['nights']];
    }

    protected function r_departures($from, $to): array
    {
        $rows = ReservationRoom::with('reservation.guest', 'reservation.company', 'guest', 'room', 'roomType')->whereDate('departure_date', '>=', $from)->whereDate('departure_date', '<=', $to)
            ->whereIn('status', ['checked_in', 'checked_out'])->orderBy('departure_date')->get()->map(fn ($s) => $this->stayRow($s));

        return ['cols' => $this->stayCols(), 'rows' => $rows, 'money' => ['rate'], 'sum' => ['nights']];
    }

    protected function r_inhouse($from, $to): array
    {
        $stays = ReservationRoom::with('reservation.guest', 'reservation.company', 'guest', 'room', 'roomType', 'folio')->where('status', 'checked_in')->get()->sortBy('room.room_no');
        $rows = $stays->map(fn ($s) => $this->stayRow($s) + ['balance' => $s->folio ? $s->folio->balance() : 0]);

        return ['cols' => $this->stayCols() + ['balance' => 'Balance'], 'rows' => $rows->values(), 'money' => ['rate', 'balance'], 'sum' => ['balance']];
    }

    protected function r_reservations($from, $to): array
    {
        $rows = Reservation::with('guest', 'company', 'source', 'rooms', 'creator')->whereBetween('created_at', [$from, $to->copy()->endOfDay()])->latest()->get()
            ->map(fn ($r) => ['no' => $r->reservation_no, 'date' => $r->created_at->format('d-m-Y'), 'guest' => $r->guest?->full_name, 'arr' => $r->arrival_date->format('d-m-Y'), 'dep' => $r->departure_date->format('d-m-Y'),
                'rooms' => $r->rooms->count(), 'nights' => $r->nights, 'value' => $r->rooms->sum(fn ($s) => $s->rate * $s->nights), 'source' => $r->source?->name, 'company' => $r->company?->name, 'status' => $r->status, 'by' => $r->creator?->username]);

        return ['cols' => ['no' => 'Res. No', 'date' => 'Booked on', 'guest' => 'Guest', 'arr' => 'Arrival', 'dep' => 'Departure', 'rooms' => 'Rooms', 'nights' => 'Nights', 'value' => 'Room value', 'source' => 'Source', 'company' => 'Company', 'status' => 'Status', 'by' => 'By'],
            'rows' => $rows, 'money' => ['value'], 'sum' => ['rooms', 'value']];
    }

    protected function r_cancellations($from, $to): array
    {
        $rows = ReservationRoom::with('reservation.guest', 'reservation.company', 'guest', 'room', 'roomType')->whereIn('status', ['cancelled', 'no_show'])
            ->whereDate('arrival_date', '>=', $from)->whereDate('arrival_date', '<=', $to)->get()->map(fn ($s) => $this->stayRow($s) + ['reason' => $s->reservation->cancel_reason]);

        return ['cols' => $this->stayCols() + ['reason' => 'Reason'], 'rows' => $rows, 'money' => ['rate'], 'sum' => ['nights']];
    }

    /** Room nights and revenue per day computed from stays + posted tariff. */
    protected function r_occupancy($from, $to): array
    {
        $total = Room::where('is_active', true)->count();
        $stays = ReservationRoom::whereIn('status', ['checked_in', 'checked_out'])->whereDate('arrival_date', '<=', $to)->whereDate('departure_date', '>', $from)->get(['arrival_date', 'departure_date', 'adults', 'children']);
        $rev = FolioTransaction::where('category', 'room')->where('type', 'charge')->where('is_void', false)->whereDate('business_date', '>=', $from)->whereDate('business_date', '<=', $to)
            ->get(['business_date', 'amount'])->groupBy(fn ($t) => $t->business_date->toDateString())->map->sum('amount');
        $rows = collect();
        for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
            $occ = $stays->filter(fn ($s) => $s->arrival_date->lte($d) && $s->departure_date->gt($d));
            $sold = $occ->count(); $r = (float) ($rev[$d->toDateString()] ?? 0);
            $rows->push(['date' => $d->format('d-m-Y D'), 'total' => $total, 'sold' => $sold, 'vacant' => $total - $sold, 'pax' => $occ->sum('adults') + $occ->sum('children'),
                'occ' => $total ? round($sold * 100 / $total, 1) : 0, 'revenue' => $r, 'adr' => $sold ? round($r / $sold, 2) : 0, 'revpar' => $total ? round($r / $total, 2) : 0]);
        }

        return ['cols' => ['date' => 'Date', 'total' => 'Rooms', 'sold' => 'Sold', 'vacant' => 'Vacant', 'pax' => 'Pax', 'occ' => 'Occ %', 'revenue' => 'Room revenue', 'adr' => 'ADR', 'revpar' => 'RevPAR'],
            'rows' => $rows, 'money' => ['revenue', 'adr', 'revpar'], 'sum' => ['sold', 'pax', 'revenue'], 'chart' => ['label' => 'Occupancy %', 'key' => 'occ', 'x' => 'date']];
    }

    protected function r_flash($from, $to): array
    {
        [, $rows] = \App\Services\ManagementReportService::flash($to);

        return ['cols' => ['metric' => 'Metric', 'day' => 'Day · '.$to->format('d M Y'), 'mtd' => 'Month to date', 'ytd' => 'Year to date'], 'rows' => $rows,
            'note' => 'Computed from posted transactions. Day = the business date selected (by default the last audited day). Collections exclude city-ledger transfers.'];
    }

    protected function r_ar_aging($from, $to): array
    {
        $rows = \App\Services\ManagementReportService::arAgeing($to);
        $m = ['billed', 'received', 'b0', 'b30', 'b60', 'b90', 'unapplied', 'outstanding', 'limit'];

        return ['cols' => ['company' => 'Company', 'type' => 'Type', 'billed' => 'Billed', 'received' => 'Received', 'b0' => '0–30 days', 'b30' => '31–60 days', 'b60' => '61–90 days', 'b90' => 'Over 90 days', 'unapplied' => 'Unapplied credit', 'outstanding' => 'Outstanding', 'limit' => 'Credit limit', 'oldest' => 'Oldest', 'flag' => 'Flag'],
            'rows' => $rows, 'money' => $m, 'sum' => $m,
            'note' => 'Company outstanding aged FIFO against receipts up to the date. '.$this->glNote()];
    }

    /** How far the general ledger has been posted – appended to every ledger report. */
    protected function glNote(): string
    {
        $cut = setting('gl_cutoff');

        return 'Ledger '.($cut ? 'posted through '.Carbon::parse($cut)->format('d M Y') : 'not yet synced').'. Closed business days are posted automatically by the night audit.'
            .(setting('gl_lock_date') ? ' Period locked up to '.Carbon::parse(setting('gl_lock_date'))->format('d M Y').'.' : '');
    }
    protected function r_trial_balance($from, $to): array
    {
        $rows = \App\Services\GlReportService::trialBalance($from->toDateString(), $to->toDateString());
        $m = ['open_dr', 'open_cr', 'dr', 'cr', 'close_dr', 'close_cr'];

        return ['cols' => ['code' => 'Code', 'account' => 'Account', 'type' => 'Type', 'open_dr' => 'Opening Dr', 'open_cr' => 'Opening Cr', 'dr' => 'Debit', 'cr' => 'Credit', 'close_dr' => 'Closing Dr', 'close_cr' => 'Closing Cr'],
            'rows' => $rows, 'money' => $m, 'sum' => $m,
            'summary' => ['Period debits' => $rows->sum('dr'), 'Period credits' => $rows->sum('cr'), 'Closing Dr' => $rows->sum('close_dr'), 'Closing Cr' => $rows->sum('close_cr')],
            'note' => $this->glNote().' Pre-closing trial balance: year-end closing entries dated inside the range are left out of the movement (earlier years\' closings are in the opening balances).'];
    }

    protected function r_gl_pnl($from, $to): array
    {
        $p = \App\Services\GlReportService::profitLoss($from->toDateString(), $to->toDateString());

        return ['cols' => ['section' => 'Section', 'group' => 'Group', 'code' => 'Code', 'account' => 'Account', 'amount' => 'Amount'], 'rows' => $p['rows'], 'money' => ['amount'],
            'summary' => ['Income' => $p['income'], 'Expenses' => $p['expense'], 'Net profit / (loss)' => $p['net']], 'note' => $this->glNote().' Discounts & allowances reduce income. Year-end closing entries are excluded.'];
    }

    protected function r_balance_sheet($from, $to): array
    {
        $b = \App\Services\GlReportService::balanceSheet($to->toDateString());

        return ['cols' => ['section' => 'Section', 'group' => 'Group', 'code' => 'Code', 'account' => 'Account', 'amount' => 'Amount'], 'rows' => $b['rows'], 'money' => ['amount'],
            'summary' => ['Total assets' => $b['assets'], 'Liabilities' => $b['liabilities'], 'Equity incl. earnings' => $b['equity'], 'Difference' => $b['difference']],
            'note' => $this->glNote().' As at '.$to->format('d M Y').'. Difference must be zero.'];
    }

    // ---- v5.7 treasury

    protected function r_cash_flow($from, $to): array
    {
        $cf = \App\Services\TreasuryService::cashFlow($from->toDateString(), $to->toDateString());
        $rows = collect();
        $h = fn ($t) => $rows->push(['section' => $t, 'line' => '', 'amount' => null, '_class' => 'bold-row']);
        $h('Operating activities');
        $rows->push(['section' => '', 'line' => 'Net profit for the period', 'amount' => $cf['profit']]);
        foreach (array_merge($cf['noncash'], $cf['working']) as $l) {
            $rows->push(['section' => '', 'line' => trim(($l['code'] ? $l['code'].' ' : '').$l['label']), 'amount' => $l['amount']]);
        }
        $rows->push(['section' => '', 'line' => 'Net cash from operating activities', 'amount' => $cf['operating'], '_class' => 'bold-row']);
        foreach (['investing' => 'Investing activities', 'financing' => 'Financing activities'] as $k => $t) {
            $h($t);
            foreach ($cf[$k] as $l) {
                $rows->push(['section' => '', 'line' => trim(($l['code'] ? $l['code'].' ' : '').$l['label']), 'amount' => $l['amount']]);
            }
            $rows->push(['section' => '', 'line' => 'Net cash from '.strtolower($t), 'amount' => $cf[$k.'_total'], '_class' => 'bold-row']);
        }
        $rows->push(['section' => 'Net change in cash', 'line' => '', 'amount' => $cf['net'], '_class' => 'bold-row']);
        $rows->push(['section' => 'Cash at start', 'line' => '', 'amount' => $cf['opening']]);
        $rows->push(['section' => 'Cash at end', 'line' => '', 'amount' => $cf['closing'], '_class' => 'bold-row']);

        return ['cols' => ['section' => 'Section', 'line' => 'Line', 'amount' => 'Amount'], 'rows' => $rows, 'money' => ['amount'],
            'summary' => ['Operating' => $cf['operating'], 'Investing' => $cf['investing_total'], 'Financing' => $cf['financing_total'], 'Net change' => $cf['net'], 'Difference' => $cf['difference']],
            'note' => $this->glNote().' Indirect method (IAS 7). Cash = accounts classed as cash & cash equivalents under Treasury › Bank accounts. Difference must be zero.'];
    }

    protected function r_cash_position($from, $to): array
    {
        $p = \App\Services\TreasuryService::position($to->toDateString());
        $TA = \App\Models\TreasuryAccount::class;
        $rows = $p['rows']->map(fn ($r) => ['code' => $r['account']->head?->code, 'account' => $r['account']->name, 'kind' => $TA::KINDS[$r['account']->kind], 'no' => $r['account']->maskedNo(),
            'balance' => $r['balance'], 'min' => (float) $r['account']->min_balance ?: null, 'unpresented' => $r['unpresented'] ?: null, 'rec' => $r['reconciled_to'] ? $r['reconciled_to']->format('d M Y') : '',
            'in' => $r['account']->in_position ? 'Yes' : 'No', 'flag' => $r['overdrawn'] ? 'Overdrawn' : ($r['below_min'] ? 'Below minimum' : ''), '_class' => $r['overdrawn'] || $r['below_min'] ? 'bold-row' : '']);

        return ['cols' => ['code' => 'Code', 'account' => 'Account', 'kind' => 'Type', 'no' => 'Account no.', 'balance' => 'Book balance', 'min' => 'Minimum', 'unpresented' => 'Cheques not presented', 'rec' => 'Reconciled to', 'in' => 'In cash position', 'flag' => 'Flag'],
            'rows' => $rows, 'money' => ['balance', 'min', 'unpresented'],
            'summary' => ['Cash position' => $p['cash'], 'Banks & MoMo' => $p['banks'], 'On hand' => $p['on_hand'], 'In transit' => $p['transit'], 'Clearing (due from acquirers)' => $p['clearing']],
            'note' => 'Book balances from the general ledger as at '.$to->format('d M Y').'.'];
    }

    protected function r_cheque_register($from, $to): array
    {
        $F = $from->toDateString();
        $T = $to->toDateString();
        $CR = \App\Models\ChequeReceived::class;
        $CI = \App\Models\ChequeIssued::class;
        $in = $CR::with('transfer')->whereBetween('received_on', [$F, $T])->orderBy('received_on')->get()->map(fn ($c) => ['dir' => 'Received', 'date' => $c->received_on->format('d M Y'), 'no' => $c->cheque_no,
            'party' => $c->party(), 'bank' => $c->bank_name, 'cheque_date' => $c->cheque_date->format('d M Y'), 'amount' => (float) $c->amount, 'status' => $CR::STATUSES[$c->status],
            'ref' => trim($c->number.' '.($c->transfer?->number ?? '')), 'note' => $c->bounce_reason, '_class' => $c->status === 'bounced' ? 'bold-row' : '']);
        $out = $CI::with('book.account')->whereDate('cheque_date', '>=', $F)->whereDate('cheque_date', '<=', $T)->orderBy('cheque_date')->get()->map(fn ($c) => ['dir' => 'Written', 'date' => $c->cheque_date->format('d M Y'), 'no' => $c->cheque_no,
            'party' => $c->payee ?: '(spoilt)', 'bank' => $c->book?->account?->name, 'cheque_date' => $c->cheque_date->format('d M Y'), 'amount' => -(float) $c->amount, 'status' => $CI::STATUSES[$c->status],
            'ref' => $c->payment?->payment_no, 'note' => $c->void_reason ?: $c->memo, '_class' => '']);
        $rows = $in->concat($out)->values();

        return ['cols' => ['dir' => 'Direction', 'date' => 'Date', 'no' => 'Cheque no.', 'party' => 'Drawer / payee', 'bank' => 'Bank', 'cheque_date' => 'Cheque date', 'amount' => 'Amount', 'status' => 'Status', 'ref' => 'Reference', 'note' => 'Note'],
            'rows' => $rows, 'money' => ['amount'],
            'summary' => ['Received' => round($in->sum('amount'), 2), 'Bounced' => round($in->where('status', $CR::STATUSES['bounced'])->sum('amount'), 2), 'Written' => round(-$out->sum('amount'), 2), 'Not presented' => round((float) $CI::where('status', 'issued')->sum('amount'), 2)],
            'note' => 'Received by date received; written by cheque date. Written cheques shown negative.'];
    }

    protected function r_transfer_register($from, $to): array
    {
        $TT = \App\Models\TreasuryTransfer::class;
        $rows = $TT::with('from', 'to', 'requester', 'approver')->whereBetween('date', [$from->toDateString(), $to->toDateString()])->orderBy('date')->orderBy('id')->get()
            ->map(fn ($t) => ['no' => $t->number, 'date' => $t->date->format('d M Y'), 'kind' => $TT::KINDS[$t->kind], 'from' => $t->from?->name, 'to' => $t->to?->name, 'amount' => (float) $t->amount,
                'fee' => (float) $t->fee ?: null, 'variance' => abs((float) $t->variance) >= 0.005 ? (float) $t->variance : null, 'status' => $TT::STATUSES[$t->status],
                'sent' => $t->dispatched_on?->format('d M'), 'credited' => $t->completed_on && $t->status === 'completed' ? $t->completed_on->format('d M') : '',
                'people' => trim(($t->requester?->name ?? '').($t->approver ? ' / '.$t->approver->name : '')), 'slip' => $t->slip_no, '_class' => abs((float) $t->variance) >= 0.005 ? 'bold-row' : '']);
        $done = $rows->where('status', $TT::STATUSES['completed']);

        return ['cols' => ['no' => 'No.', 'date' => 'Date', 'kind' => 'Type', 'from' => 'From', 'to' => 'To', 'amount' => 'Amount', 'fee' => 'Fee', 'variance' => 'Short / over', 'status' => 'Status', 'sent' => 'Sent', 'credited' => 'Credited', 'people' => 'Requested / approved', 'slip' => 'Slip'],
            'rows' => $rows, 'money' => ['amount', 'fee', 'variance'],
            'summary' => ['Transfers' => $rows->count(), 'Completed' => round($done->sum('amount'), 2), 'Fees' => round($rows->sum('fee'), 2), 'Short / over' => round($rows->sum('variance'), 2)],
            'note' => 'Transfers from '.money((float) \App\Services\TreasuryService::cfg('treasury_approval_threshold')).' need a second person\'s approval.'];
    }

    // ---- v5.8 owner reporting

    protected function r_usali_summary($from, $to): array
    {
        $U = \App\Services\UsaliService::class;
        $s = $U::range($from->toDateString(), $to->toDateString())['summary'];
        $layout = $U::layout('operator');
        foreach ($U::layout('owner') as $r) {
            if (in_array($r[0], ['h_ida', 'b_interest', 'b_depreciation', 'b_amortization', 'tida', 'ibit', 'b_income_tax', 'net'], true)) {
                $layout[] = $r;
            }
        }
        $rows = collect($layout)->map(function ($r) use ($s) {
            [$k, $label, $kind] = $r;
            if ($kind === 'head') {
                return ['line' => $label, 'amount' => null, 'pct' => null, '_class' => 'bold-row'];
            }
            $base = $s[$r[3] ?? 'tor'] ?? 0;

            return ['line' => ($kind === 'line' ? '   ' : '').$label, 'amount' => $s[$k] ?? 0, 'pct' => $base != 0 ? round(($s[$k] ?? 0) * 100 / $base, 1) : null, '_class' => in_array($kind, ['total', 'strong'], true) ? 'bold-row' : ''];
        });

        return ['cols' => ['line' => 'Line', 'amount' => 'Amount', 'pct' => '% of revenue'], 'rows' => $rows, 'money' => ['amount'],
            'summary' => ['Total operating revenue' => $s['tor'], 'GOP' => $s['gop'], 'EBITDA' => $s['ebitda'], 'Net income' => $s['net'], 'Ledger profit' => $s['gl_net']],
            'note' => $this->glNote().' Departmental expenses are a % of their department\'s revenue; other lines a % of total operating revenue. Replacement reserve = FF&E contributions accrued with the management fees. Payroll is split across departments by each month\'s payroll.'];
    }

    protected function r_usali_departments($from, $to): array
    {
        $U = \App\Services\UsaliService::class;
        $acc = $U::range($from->toDateString(), $to->toDateString())['acc'];
        $rows = collect();
        foreach (array_merge(array_keys($U::OPERATED), ['ood', 'misc'], array_keys($U::UNDISTRIBUTED), ['mgmt', 'nonop', 'below']) as $code) {
            foreach ($U::schedule($code, $acc) as $sec) {
                if (! $sec['cats']) {
                    continue;
                }
                $rows->push(['dept' => $sec['label'], 'cat' => '', 'account' => '', 'amount' => null, 'pct' => null, '_class' => 'bold-row']);
                foreach ($sec['cats'] as $c) {
                    foreach ($c['lines'] as $l) {
                        $rows->push(['dept' => '', 'cat' => $c['label'], 'account' => trim($l['code'].' '.$l['name']).($l['split'] ? ' (payroll split)' : ''), 'amount' => $l['amount'],
                            'pct' => $sec['revenue'] > 0 ? round($l['amount'] * 100 / $sec['revenue'], 1) : null, '_class' => '']);
                    }
                    $rows->push(['dept' => '', 'cat' => 'Total '.strtolower($c['label']), 'account' => '', 'amount' => $c['total'], 'pct' => null, '_class' => 'bold-row']);
                }
                if ($sec['revenue'] != 0 && $sec['expense'] != 0) {
                    $rows->push(['dept' => '', 'cat' => 'Departmental profit', 'account' => '', 'amount' => $sec['profit'], 'pct' => $sec['revenue'] > 0 ? round($sec['profit'] * 100 / $sec['revenue'], 1) : null, '_class' => 'bold-row']);
                }
            }
        }
        $sum = $U::summarise($acc);

        return ['cols' => ['dept' => 'Department', 'cat' => 'Category', 'account' => 'Account', 'amount' => 'Amount', 'pct' => '% of dept. revenue'], 'rows' => $rows, 'money' => ['amount'],
            'summary' => ['Departmental profit' => $sum['tdp'], 'Undistributed' => $sum['tue'], 'GOP' => $sum['gop'], 'Labour' => $sum['labor']],
            'note' => $this->glNote().' Mapping under Owner Reporting › Agreement & mapping.'];
    }

    protected function r_hotel_kpis($from, $to): array
    {
        $U = \App\Services\UsaliService::class;
        $rows = collect();
        $tot = ['available' => 0, 'sold' => 0];
        $yms = [];
        for ($c = $from->copy()->startOfMonth(); $c->lte($to); $c->addMonthNoOverflow()) {
            $ym = $c->format('Y-m');
            $yms[] = $ym;
            $st = $U::roomStats([$ym]);
            $k = $U::kpis($U::month($ym), $st);
            $tot['available'] += $st['available'];
            $tot['sold'] += $st['sold'];
            $rows->push(['month' => $c->format('M Y')] + array_intersect_key($k, array_flip(['available', 'sold', 'occupancy', 'adr', 'revpar', 'trevpar', 'goppar', 'cpor', 'gop_margin', 'ebitda_margin', 'labor_pct', 'fnb_cost_pct'])));
        }
        $all = $U::kpis($U::range($yms[0].'-01', \Illuminate\Support\Carbon::parse(end($yms).'-01')->endOfMonth()->toDateString())['summary'], $tot);
        $rows->push(['month' => 'Period', '_class' => 'bold-row'] + array_intersect_key($all, array_flip(['available', 'sold', 'occupancy', 'adr', 'revpar', 'trevpar', 'goppar', 'cpor', 'gop_margin', 'ebitda_margin', 'labor_pct', 'fnb_cost_pct'])));

        return ['cols' => ['month' => 'Month', 'available' => 'Rooms avail.', 'sold' => 'Sold', 'occupancy' => 'Occ. %', 'adr' => 'ADR', 'revpar' => 'RevPAR', 'trevpar' => 'TRevPAR', 'goppar' => 'GOPPAR', 'cpor' => 'CPOR',
            'gop_margin' => 'GOP %', 'ebitda_margin' => 'EBITDA %', 'labor_pct' => 'Labour %', 'fnb_cost_pct' => 'F&B cost %'], 'rows' => $rows, 'money' => ['adr', 'revpar', 'trevpar', 'goppar', 'cpor'],
            'summary' => ['Occupancy %' => $all['occupancy'], 'ADR' => $all['adr'], 'RevPAR' => $all['revpar'], 'GOPPAR' => $all['goppar']],
            'note' => 'Whole calendar months touched by the range. Rooms available / sold from the booking data; revenue and costs from the general ledger (USALI). CPOR = rooms departmental expenses per occupied room.'];
    }

    protected function r_management_fees($from, $to): array
    {
        $F = \App\Models\OwnerFee::class;
        $fees = $F::with('paidAccount')->whereBetween('period', [$from->format('Y-m'), $to->format('Y-m')])->orderBy('period')->get();
        $rows = $fees->map(fn ($f) => ['month' => $f->label(), 'revenue' => (float) $f->total_revenue, 'base' => (float) $f->base_fee, 'ffe' => (float) $f->ffe_amount, 'gop' => (float) $f->gop, 'agop' => (float) $f->agop,
            'agop_ytd' => (float) $f->agop_ytd, 'priority' => (float) $f->priority_ytd, 'incentive' => (float) $f->incentive_fee, 'total' => (float) $f->total_fee, 'status' => $F::STATUSES[$f->status],
            'paid' => $f->status === 'paid' ? $f->paid_on?->format('d M Y').' '.$f->paidAccount?->name : ($f->status === 'accrued' ? 'due '.$f->dueOn()->format('d M Y') : $f->reversal_note), '_class' => $f->status === 'reversed' ? 'muted' : '']);
        $live = $fees->where('status', '!=', 'reversed');

        return ['cols' => ['month' => 'Month', 'revenue' => 'Revenue', 'base' => 'Base fee', 'ffe' => 'FF&E', 'gop' => 'GOP', 'agop' => 'AGOP', 'agop_ytd' => 'AGOP fee YTD', 'priority' => 'Owner priority YTD', 'incentive' => 'Incentive', 'total' => 'Total fee', 'status' => 'Status', 'paid' => 'Paid / due'],
            'rows' => $rows, 'money' => ['revenue', 'base', 'ffe', 'gop', 'agop', 'agop_ytd', 'priority', 'incentive', 'total'],
            'summary' => ['Base fees' => round($live->sum('base_fee'), 2), 'Incentive fees' => round($live->sum('incentive_fee'), 2), 'Total fees' => round($live->sum('total_fee'), 2), 'Unpaid' => round($live->where('status', 'accrued')->sum('total_fee'), 2), 'FF&E contributions' => round($live->sum('ffe_amount'), 2)],
            'note' => ($a = \App\Services\OwnerService::agreement()) ? $a->operator_name.' · base '.(float) $a->base_fee_pct.'% of revenue, incentive '.(float) $a->incentive_pct.'% of '.strtoupper($a->incentive_basis).' above the owner\'s priority, FF&E '.$a->scheduleLabel().'.' : 'No management agreement set up.'];
    }

    protected function r_ffe_reserve($from, $to): array
    {
        $FE = \App\Models\FfeEntry::class;
        $CP = \App\Models\CapexProject::class;
        $l = \App\Services\OwnerService::ledger($from->toDateString(), $to->toDateString());
        $rows = collect([['date' => $from->format('d M Y'), 'type' => 'Opening balance', 'detail' => '', 'in' => null, 'out' => null, 'balance' => $l['opening'], '_class' => 'bold-row']]);
        foreach ($l['rows'] as $r) {
            $e = $r['entry'];
            $rows->push(['date' => $e->date->format('d M Y'), 'type' => $FE::TYPES[$e->type], 'detail' => trim(($e->project?->number ?? '').' '.$e->memo), 'in' => $e->amount > 0 ? (float) $e->amount : null, 'out' => $e->amount < 0 ? -(float) $e->amount : null, 'balance' => $r['balance'], '_class' => '']);
        }
        $rows->push(['date' => $to->format('d M Y'), 'type' => 'Closing balance', 'detail' => '', 'in' => null, 'out' => null, 'balance' => $l['closing'], '_class' => 'bold-row']);
        $rows->push(['date' => '', 'type' => 'Capital projects', 'detail' => '', 'in' => null, 'out' => null, 'balance' => null, '_class' => 'bold-row']);
        foreach ($CP::with('assets', 'entries')->orderBy('number')->get() as $p) {
            $rows->push(['date' => $p->number, 'type' => $CP::STATUSES[$p->status], 'detail' => $p->title.' · '.$CP::FUNDING[$p->funding].' · budget '.money((float) $p->budget), 'in' => null, 'out' => $p->spent() ?: null, 'balance' => null, '_class' => '']);
        }
        $all = collect($l['rows'])->pluck('entry');
        $funded = \App\Services\OwnerService::reserveFunded(null, $to->toDateString());

        return ['cols' => ['date' => 'Date / project', 'type' => 'Type', 'detail' => 'Detail', 'in' => 'In', 'out' => 'Out / spent', 'balance' => 'Balance'], 'rows' => $rows, 'money' => ['in', 'out', 'balance'],
            'summary' => ['Opening' => $l['opening'], 'Contributions' => round($all->where('type', 'contribution')->sum('amount'), 2), 'Drawdowns' => round(-$all->where('type', 'drawdown')->sum('amount'), 2), 'Closing' => $l['closing']] + ($funded !== null ? ['Cash in reserve account' => $funded] : []),
            'note' => 'The reserve is a memorandum fund kept with the management fees; the cash is held in the designated reserve bank account.'];
    }

    // ---- v5.6 financial close

    protected function r_close_status($from, $to): array
    {
        $CP = \App\Models\ClosePeriod::class;
        $rows = $CP::with('preparer', 'closer')->withCount(['tasks', 'tasks as cleared_count' => fn ($q) => $q->whereIn('status', \App\Models\CloseTask::CLEARED), 'tasks as waived_count' => fn ($q) => $q->where('status', 'waived')])
            ->whereBetween('period', [$from->format('Y-m'), $to->format('Y-m')])->orderBy('period')->get()
            ->map(fn ($p) => ['period' => $p->label(), 'fy' => \App\Services\BudgetService::fyLabel($p->fiscal_year).' · M'.$p->fiscal_month, 'status' => $CP::STATUSES[$p->status],
                'tasks' => $p->cleared_count.' / '.$p->tasks_count, 'waived' => $p->waived_count, 'prepared' => $p->preparer ? $p->preparer->name.' · '.$p->prepared_at?->format('d M Y') : '',
                'closed' => $p->closer ? $p->closer->name.' · '.$p->closed_at?->format('d M Y') : '', 'days' => $p->closed_at ? max(0, (int) $p->end()->diffInDays($p->closed_at->copy()->startOfDay())) : null,
                'reopened' => $p->reopen_count ?: '', '_class' => $p->status === 'closed' ? '' : 'bold-row']);
        $closed = $rows->whereNotNull('days');

        return ['cols' => ['period' => 'Period', 'fy' => 'Fiscal', 'status' => 'Status', 'tasks' => 'Checklist cleared', 'waived' => 'Waived', 'prepared' => 'Prepared', 'closed' => 'Approved & closed', 'days' => 'Days to close', 'reopened' => 'Reopened'],
            'rows' => $rows, 'summary' => ['Periods' => $rows->count(), 'Closed' => $closed->count(), 'Average days to close' => $closed->count() ? round($closed->avg('days'), 1) : '—', 'Waivers' => $rows->sum('waived')],
            'note' => 'Books locked up to '.(\App\Services\LedgerService::lockDate() ? Carbon::parse(\App\Services\LedgerService::lockDate())->format('d M Y') : '—').'. Days to close = approval date − month end.'];
    }

    protected function r_accrual_register($from, $to): array
    {
        $A = \App\Models\Accrual::class;
        $asOf = $to->format('Y-m');
        $rows = $A::with('plAccount', 'balanceAccount', 'supplier')->whereIn('status', ['active', 'completed', 'cancelled'])->where('start_period', '<=', $asOf)->orderBy('type')->orderBy('number')->get()
            ->map(function ($a) use ($asOf, $A) {
                $posted = (float) \App\Models\PeriodPosting::where('source', 'accrual')->where('source_id', $a->id)->where('kind', 'post')->where('period', '<=', $asOf)->sum('amount');
                $next = $a->status === 'active' ? collect($a->schedule())->keys()->first(fn ($m) => $m > ($a->last_period ?? '0000-00')) : null;

                return ['no' => $a->number, 'type' => $A::TYPES[$a->type], 'description' => $a->description, 'party' => $a->supplier?->name, 'pl' => $a->plAccount?->code.' '.$a->plAccount?->name,
                    'bs' => $a->balanceAccount?->code, 'months' => Carbon::parse($a->start_period.'-01')->format('M y').($a->months > 1 ? ' – '.Carbon::parse($a->endPeriod().'-01')->format('M y') : ''),
                    'amount' => (float) $a->amount, 'posted' => round($posted, 2), 'remaining' => $a->status === 'cancelled' ? 0.0 : round((float) $a->amount - $posted, 2),
                    'next' => $next ? Carbon::parse($next.'-01')->format('M Y') : '', 'status' => $A::STATUSES[$a->status]];
            });

        return ['cols' => ['no' => 'No.', 'type' => 'Type', 'description' => 'Description', 'party' => 'Supplier / customer', 'pl' => 'Expense / income account', 'bs' => 'B/S account', 'months' => 'Months', 'amount' => 'Amount', 'posted' => 'Posted to date', 'remaining' => 'Remaining', 'next' => 'Next posting', 'status' => 'Status'],
            'rows' => $rows, 'money' => ['amount', 'posted', 'remaining'], 'sum' => ['amount', 'posted', 'remaining'],
            'summary' => collect($A::TYPES)->mapWithKeys(fn ($l, $k) => [$l.' remaining' => $rows->where('type', $l)->sum('remaining')])->all(),
            'note' => 'Posted to date counts postings for months up to '.$to->format('M Y').'. Accrued expenses reverse on the 1st of the following month.'];
    }

    protected function r_period_end_postings($from, $to): array
    {
        $rows = \App\Models\PeriodPosting::with('journal', 'accrual', 'recurring')->whereBetween('date', [$from->toDateString(), $to->toDateString()])->orderBy('date')->orderBy('id')->get()
            ->map(fn ($p) => ['date' => $p->date->format('d-m-Y'), 'source' => $p->source === 'accrual' ? \App\Models\Accrual::TYPES[$p->accrual?->type ?? 'accrual'] : 'Recurring journal',
                'item' => $p->sourceLabel(), 'period' => Carbon::parse($p->period.'-01')->format('M Y'), 'kind' => $p->kind === 'reverse' ? 'Auto-reversal' : 'Posting', 'amount' => (float) $p->amount, 'journal' => $p->journal?->journal_no]);

        return ['cols' => ['date' => 'Date', 'source' => 'Source', 'item' => 'Schedule / template', 'period' => 'For month', 'kind' => 'Kind', 'amount' => 'Amount', 'journal' => 'Journal'],
            'rows' => $rows, 'money' => ['amount'], 'sum' => ['amount'],
            'summary' => ['Postings' => $rows->where('kind', 'Posting')->sum('amount'), 'Auto-reversals' => $rows->where('kind', 'Auto-reversal')->sum('amount')],
            'note' => 'Made by Period close › Run period-end postings, or automatically when the night audit closes the last day of a month.'];
    }

    // ---- v3.6 budget & planning

    /** the budget a report is about: ?budget= (approved, this hotel) or the active budget of the date's fiscal year */
    protected function reportBudget(Carbon $to, string $kind = 'budget'): ?\App\Models\Budget
    {
        $id = (int) request('budget');
        if ($id && ($b = \App\Models\Budget::where('status', 'approved')->find($id))) {
            return $b;
        }

        return \App\Services\BudgetService::active(\App\Services\BudgetService::fyFor($to), $kind);
    }

    protected function noBudget(array $cols, string $what = 'approved, active budget'): array
    {
        return ['cols' => $cols, 'rows' => collect(), 'note' => "There is no {$what} for this fiscal year – see Accounts › Budgets."];
    }

    protected function r_budget_vs_actual($from, $to): array
    {
        $cols = ['section' => 'Section', 'code' => 'Code', 'account' => 'Account', 'actual' => 'Actual (month)', 'budget' => 'Budget (month)', 'var' => 'Variance', 'var_pct' => 'Var %',
            'ytd_actual' => 'Actual YTD', 'ytd_budget' => 'Budget YTD', 'ytd_var' => 'Variance YTD', 'ytd_var_pct' => 'YTD var %', 'year_budget' => 'Full-year budget', 'forecast' => 'Forecast', 'flag' => 'Explain?'];
        $b = $this->reportBudget($to);
        if (! $b) {
            return $this->noBudget($cols);
        }
        $B = \App\Services\BudgetService::class;
        $month = $B::fyFor($to) === $b->fiscal_year ? $B::monthIndex($to) : 12;
        if (request('month')) {
            $month = min(12, max(1, (int) request('month')));
        }
        $d = $B::bva($b, $month, $B::active($b->fiscal_year, 'forecast'));
        $fmt = fn ($r, $sec, $code = '') => ['section' => $sec, 'code' => $code, 'account' => $r['name'], 'actual' => $r['actual'], 'budget' => $r['budget'], 'var' => $r['var'],
            'var_pct' => $r['var_pct'] === null ? '—' : number_format($r['var_pct'], 1), 'ytd_actual' => $r['ytd_actual'], 'ytd_budget' => $r['ytd_budget'], 'ytd_var' => $r['ytd_var'],
            'ytd_var_pct' => $r['ytd_var_pct'] === null ? '—' : number_format($r['ytd_var_pct'], 1), 'year_budget' => $r['year_budget'], 'forecast' => $r['forecast'] ?? null, 'flag' => ! empty($r['flag']) ? 'yes' : '', '_class' => $code === '' ? 'bold-row' : ''];
        $rows = collect();
        $byS = $d['rows']->groupBy('section');
        foreach ($B::SECTIONS as $k => $label) {
            if (! isset($byS[$k])) {
                continue;
            }
            foreach ($byS[$k] as $r) {
                $rows->push($fmt($r, $label, $r['code']));
            }
            $rows->push($fmt($d['sections'][$k], $label));
            if ($k === 'opex') {
                $rows->push($fmt($d['totals']['gop'], 'Result'));
            }
        }
        $rows->push($fmt($d['totals']['net'], 'Result'));
        $m = $d['meta'];

        return ['cols' => $cols, 'rows' => $rows, 'money' => ['actual', 'budget', 'var', 'ytd_actual', 'ytd_budget', 'ytd_var', 'year_budget', 'forecast'],
            'summary' => ['Budget' => $b->budget_no.' · '.$b->fyLabel(), 'Revenue – month' => $d['totals']['revenue']['actual'], 'Revenue budget' => $d['totals']['revenue']['budget'],
                'GOP – month' => $d['totals']['gop']['actual'], 'GOP budget' => $d['totals']['gop']['budget'], 'Net profit YTD' => $d['totals']['net']['ytd_actual']],
            'note' => $m['start']->format('F Y').': actuals from the general ledger to '.$m['cutoff']->format('d M Y').'.'.($m['partial'] ? ' Month in progress – budget pro-rated to '.round($m['factor'] * 100).' %.' : '')
                .($m['pre_live'] ? ' Month is before go-live – not compared.' : '').' Variance = actual − budget; for expenses a positive variance is adverse.'];
    }

    protected function r_budget_annual($from, $to): array
    {
        $B = \App\Services\BudgetService::class;
        $b = $this->reportBudget($to);
        $labels = $B::monthLabels($b?->fiscal_year ?? $B::fyFor($to));
        $cols = ['section' => 'Section', 'code' => 'Code', 'account' => 'Account'];
        foreach ($labels as $i => $l) {
            $cols['m'.$i] = $l;
        }
        $cols['total'] = 'Total';
        if (! $b) {
            return $this->noBudget($cols);
        }
        $acc = $B::accounts()->keyBy('id');
        $lines = $b->lines()->get()->filter(fn ($l) => $acc->has($l->account_head_id));
        $rows = collect();
        $secSum = [];
        foreach ($B::SECTIONS as $k => $label) {
            $ls = $lines->filter(fn ($l) => $acc[$l->account_head_id]->section === $k)->sortBy(fn ($l) => $acc[$l->account_head_id]->code);
            $sum = array_fill(1, 12, 0.0);
            foreach ($ls as $l) {
                if (abs($l->total()) < 0.005) {
                    continue;
                }
                $r = ['section' => $label, 'code' => $acc[$l->account_head_id]->code, 'account' => $acc[$l->account_head_id]->name];
                foreach (range(1, 12) as $m) {
                    $r['m'.$m] = $l->month($m);
                    $sum[$m] += $l->month($m);
                }
                $rows->push($r + ['total' => $l->total()]);
            }
            $secSum[$k] = $sum;
            $t = ['section' => $label, 'code' => '', 'account' => 'Total '.strtolower($label), '_class' => 'bold-row'];
            foreach (range(1, 12) as $m) {
                $t['m'.$m] = round($sum[$m], 2);
            }
            $rows->push($t + ['total' => round(array_sum($sum), 2)]);
        }
        foreach (['Gross operating profit (GOP)' => [['revenue'], ['direct', 'opex']], 'Net profit' => [['revenue', 'other_income'], ['direct', 'opex', 'nonop']]] as $name => [$plus, $minus]) {
            $t = ['section' => 'Result', 'code' => '', 'account' => $name, '_class' => 'bold-row'];
            $tot = 0;
            foreach (range(1, 12) as $m) {
                $v = array_sum(array_map(fn ($k) => $secSum[$k][$m], $plus)) - array_sum(array_map(fn ($k) => $secSum[$k][$m], $minus));
                $t['m'.$m] = round($v, 2);
                $tot += $v;
            }
            $rows->push($t + ['total' => round($tot, 2)]);
        }
        $tt = $B::totals($b);

        return ['cols' => $cols, 'rows' => $rows, 'money' => array_merge(array_map(fn ($m) => 'm'.$m, range(1, 12)), ['total']),
            'summary' => ['Budget' => $b->budget_no.' · '.$b->fyLabel(), 'Revenue' => $tt['income'], 'Expenses' => $tt['expense'], 'Net profit' => $tt['net']],
            'note' => "{$b->name} – {$b->statusLabel()}".($b->approver ? ' by '.$b->approver->name : '').'.'];
    }

    protected function r_forecast_vs_budget($from, $to): array
    {
        $B = \App\Services\BudgetService::class;
        $cols = ['section' => 'Section', 'code' => 'Code', 'account' => 'Account', 'budget' => 'Budget (year)', 'forecast' => 'Forecast (year)', 'var' => 'Forecast − budget', 'var_pct' => 'Var %',
            'actual' => 'Actual to date', 'remaining' => 'Remaining forecast'];
        $b = $this->reportBudget($to);
        $f = $b ? $B::active($b->fiscal_year, 'forecast') : null;
        if (! $b || ! $f) {
            return $this->noBudget($cols, $b ? 'approved, active re-forecast' : 'approved, active budget');
        }
        $acc = $B::accounts()->keyBy('id');
        $bl = $b->lines()->get()->keyBy('account_head_id');
        $fl = $f->lines()->get()->keyBy('account_head_id');
        $act = $B::actuals($b->fiscal_year);
        $rows = collect();
        $tot = ['income' => [0, 0], 'expense' => [0, 0]];
        foreach ($acc->sortBy(fn ($a) => array_search($a->section, array_keys($B::SECTIONS)).'-'.$a->code) as $a) {
            $bv = isset($bl[$a->id]) ? $bl[$a->id]->total() : 0.0;
            $fv = isset($fl[$a->id]) ? $fl[$a->id]->total() : 0.0;
            $av = round(array_sum($act[$a->id] ?? []), 2);
            if (abs($bv) + abs($fv) + abs($av) < 0.005) {
                continue;
            }
            $tot[$a->type][0] += $bv;
            $tot[$a->type][1] += $fv;
            $rows->push(['section' => $B::SECTIONS[$a->section], 'code' => $a->code, 'account' => $a->name, 'budget' => $bv, 'forecast' => $fv, 'var' => round($fv - $bv, 2),
                'var_pct' => abs($bv) >= 0.01 ? number_format(($fv - $bv) * 100 / abs($bv), 1) : '—', 'actual' => $av, 'remaining' => round($fv - $av, 2)]);
        }
        $netB = $tot['income'][0] - $tot['expense'][0];
        $netF = $tot['income'][1] - $tot['expense'][1];

        return ['cols' => $cols, 'rows' => $rows, 'money' => ['budget', 'forecast', 'var', 'actual', 'remaining'],
            'summary' => ['Forecast' => $f->budget_no.' (actuals to '.$f->actuals_through?->format('M Y').')', 'Revenue: forecast − budget' => round($tot['income'][1] - $tot['income'][0], 2),
                'Expenses: forecast − budget' => round($tot['expense'][1] - $tot['expense'][0], 2), 'Net profit: forecast' => round($netF, 2), 'Net profit: budget' => round($netB, 2)],
            'note' => "Budget {$b->budget_no} vs re-forecast {$f->budget_no}. For expense accounts a positive difference means more cost than budgeted."];
    }

    protected function r_budget_kpis($from, $to): array
    {
        $B = \App\Services\BudgetService::class;
        $cols = ['month' => 'Month', 'b_occ' => 'Budget occ. %', 'a_occ' => 'Actual occ. %', 'b_adr' => 'Budget ADR', 'a_adr' => 'Actual ADR', 'b_revpar' => 'Budget RevPAR', 'a_revpar' => 'Actual RevPAR',
            'b_nights' => 'Budget room-nights', 'a_nights' => 'Actual room-nights', 'b_rev' => 'Budget room revenue', 'a_rev' => 'Actual room revenue'];
        $b = $this->reportBudget($to);
        if (! $b) {
            return $this->noBudget($cols);
        }
        $as = $B::actualStats($b->fiscal_year);
        $st = $b->stats()->get()->keyBy('month');
        $rows = collect();
        foreach ($B::monthLabels($b->fiscal_year) as $m => $l) {
            $s = $st[$m] ?? null;
            $a = $as[$m];
            $rows->push(['month' => $l, 'b_occ' => number_format($s?->occupancy ?? 0, 1), 'a_occ' => $a['available'] ? number_format($a['occupancy'], 1) : '—',
                'b_adr' => $s?->adr ?? 0, 'a_adr' => $a['adr'], 'b_revpar' => $s?->revpar() ?? 0, 'a_revpar' => $a['revpar'],
                'b_nights' => $s?->roomsSold() ?? 0, 'a_nights' => $a['sold'], 'b_rev' => $s?->roomRevenue() ?? 0, 'a_rev' => $a['revenue']]);
        }

        return ['cols' => $cols, 'rows' => $rows, 'money' => ['b_adr', 'a_adr', 'b_revpar', 'a_revpar', 'b_rev', 'a_rev'], 'sum' => ['b_nights', 'a_nights', 'b_rev', 'a_rev'],
            'summary' => ['Budget' => $b->budget_no.' · '.$b->fyLabel(), 'Budget room revenue' => $rows->sum('b_rev'), 'Actual room revenue to date' => $rows->sum('a_rev')],
            'note' => 'Actuals: room-nights of checked-in / checked-out stays on closed days; available = active rooms × days; room revenue from the general ledger.'];
    }

    protected function r_variance_commentary($from, $to): array
    {
        $rows = \App\Models\BudgetComment::with('account', 'user', 'budgetVersion')->whereBetween('period', [$from->format('Y-m'), $to->format('Y-m')])->orderBy('period')->orderBy('id')->get()
            ->map(fn ($c) => ['period' => \Illuminate\Support\Carbon::parse($c->period.'-01')->format('M Y'), 'budget_no' => $c->budgetVersion?->budget_no, 'account' => $c->account ? $c->account->code.' '.$c->account->name : 'General (whole month)',
                'actual' => $c->actual, 'budget' => $c->budget, 'var' => $c->actual !== null && $c->budget !== null ? round($c->actual - $c->budget, 2) : null, 'comment' => $c->body,
                'by' => $c->user?->name, 'at' => $c->created_at->format('d-m-Y H:i')]);

        return ['cols' => ['period' => 'Month', 'budget_no' => 'Budget', 'account' => 'Account', 'actual' => 'Actual', 'budget' => 'Budget', 'var' => 'Variance', 'comment' => 'Explanation', 'by' => 'By', 'at' => 'Recorded'],
            'rows' => $rows, 'money' => ['actual', 'budget', 'var'], 'summary' => ['Comments' => (string) $rows->count(), 'Accounts explained' => (string) $rows->pluck('account')->unique()->count()]];
    }

    // ---- v3.7 hotel interfaces

    protected function r_call_log($from, $to): array
    {
        $rows = \App\Models\CallRecord::with('room', 'stay.guest', 'stay.reservation.guest')->whereBetween('started_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])->orderBy('started_at')->get()
            ->map(fn ($c) => ['at' => $c->started_at->format('d-m-Y H:i'), 'ext' => $c->extension, 'room' => $c->room?->room_no, 'guest' => ($c->stay?->guest ?? $c->stay?->reservation?->guest)?->full_name,
                'number' => $c->maskedNumber(), 'class' => \App\Models\CallTariff::CLASSES[$c->call_class] ?? ucfirst((string) $c->call_class), 'duration' => $c->durationLabel(), 'secs' => $c->duration_secs,
                'cost' => (float) $c->cost, 'charge' => (float) $c->charge, 'status' => $c->statusLabel(), 'note' => $c->note]);

        return ['cols' => ['at' => 'Time', 'ext' => 'Ext', 'room' => 'Room', 'guest' => 'Guest', 'number' => 'Number', 'class' => 'Class', 'duration' => 'Duration', 'cost' => 'Carrier cost', 'charge' => 'Charge', 'status' => 'Status', 'note' => 'Note'],
            'rows' => $rows, 'money' => ['cost', 'charge'], 'sum' => ['cost', 'charge'],
            'summary' => ['Calls' => (string) $rows->count(), 'Minutes' => (string) round($rows->sum('secs') / 60), 'Posted to folios' => money($rows->where('status', 'Posted')->sum('charge')), 'Awaiting review' => (string) $rows->where('status', 'Review')->count()],
            'note' => 'Numbers are masked (last digits hidden) as on the guest bill. Charge is shown for every rated call; only “Posted” calls are billed.'];
    }

    protected function r_call_revenue($from, $to): array
    {
        $calls = \App\Models\CallRecord::whereBetween('started_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])->whereNotIn('status', ['admin'])->get();
        $rows = $calls->groupBy('call_class')->map(function ($g, $cls) {
            $posted = $g->where('status', 'posted');

            return ['class' => \App\Models\CallTariff::CLASSES[$cls] ?? ucfirst((string) ($cls ?: 'internal')), 'calls' => $g->count(), 'posted' => $posted->count(), 'minutes' => (int) round($g->sum('duration_secs') / 60),
                'cost' => round((float) $posted->sum('cost'), 2), 'charged' => round((float) $posted->sum('charge'), 2), 'margin' => round((float) $posted->sum('charge') - (float) $posted->sum('cost'), 2),
                'unbilled' => round((float) $g->whereIn('status', ['pending', 'waived'])->sum('charge'), 2)];
        })->sortByDesc('charged')->values();

        return ['cols' => ['class' => 'Class', 'calls' => 'Calls', 'posted' => 'Billed', 'minutes' => 'Minutes', 'cost' => 'Carrier cost (billed)', 'charged' => 'Charged', 'margin' => 'Margin', 'unbilled' => 'Unbilled (review / waived)'],
            'rows' => $rows, 'money' => ['cost', 'charged', 'margin', 'unbilled'], 'sum' => ['calls', 'posted', 'minutes', 'cost', 'charged', 'margin', 'unbilled'],
            'summary' => ['Telephone revenue' => money($rows->sum('charged')), 'Margin' => money($rows->sum('margin')), 'Unbilled' => money($rows->sum('unbilled'))],
            'chart' => ['label' => 'Charged', 'key' => 'charged', 'x' => 'class']];
    }

    protected function r_admin_call_usage($from, $to): array
    {
        $rows = \App\Models\CallRecord::with('phoneExtension')->where('status', 'admin')->whereBetween('started_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])->get()
            ->groupBy('extension')->map(fn ($g, $ext) => ['dept' => $g->first()->phoneExtension?->department ?: (\App\Models\PhoneExtension::TYPES[$g->first()->phoneExtension?->type ?? ''] ?? '—'),
                'ext' => (string) $ext, 'label' => $g->first()->phoneExtension?->label, 'calls' => $g->count(), 'minutes' => (int) round($g->sum('duration_secs') / 60),
                'isd' => $g->where('call_class', 'isd')->count(), 'cost' => round((float) $g->sum('cost'), 2)])
            ->sortBy(fn ($r) => $r['dept'].'-'.str_pad($r['ext'], 10, '0', STR_PAD_LEFT))->values();

        return ['cols' => ['dept' => 'Department', 'ext' => 'Extension', 'label' => 'Label', 'calls' => 'Calls', 'minutes' => 'Minutes', 'isd' => 'International', 'cost' => 'Carrier cost'],
            'rows' => $rows, 'money' => ['cost'], 'sum' => ['calls', 'minutes', 'isd', 'cost'],
            'summary' => ['Administrative call cost' => money($rows->sum('cost')), 'Departments' => (string) $rows->pluck('dept')->unique()->count()]];
    }

    protected function r_key_card_audit($from, $to): array
    {
        $rows = \App\Models\KeyCard::with('room', 'issuer', 'fee')->whereBetween('valid_from', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])->orderBy('valid_from')->get()
            ->map(fn ($k) => ['card' => $k->card_no, 'room' => $k->room?->room_no, 'holder' => $k->holder, 'type' => \App\Models\KeyCard::TYPES[$k->type] ?? $k->type, 'serial' => $k->serial,
                'issued' => $k->valid_from->format('d-m-Y H:i'), 'until' => $k->valid_until->format('d-m-Y H:i'), 'status' => $k->statusLabel(), 'note' => $k->status_note,
                'by' => $k->issuer?->name, 'fee' => $k->fee && ! $k->fee->is_void ? (float) $k->fee->amount : null]);

        return ['cols' => ['card' => 'Card', 'room' => 'Room', 'holder' => 'Holder', 'type' => 'Type', 'serial' => 'Serial', 'issued' => 'Encoded', 'until' => 'Valid until', 'status' => 'Status', 'note' => 'Note', 'by' => 'Issued by', 'fee' => 'Lost fee'],
            'rows' => $rows, 'money' => ['fee'], 'sum' => ['fee'],
            'summary' => ['Cards encoded' => (string) $rows->count(), 'Duplicates' => (string) $rows->where('type', 'Duplicate')->count(), 'Lost' => (string) $rows->where('status', 'Lost')->count(), 'Lost-card fees' => money($rows->sum('fee'))]];
    }

    protected function r_master_key_register($from, $to): array
    {
        $rows = \App\Models\KeyLoan::with('key', 'issuer', 'receiver')->whereBetween('out_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])->orderBy('out_at')->get()
            ->map(fn ($l) => ['key' => $l->key?->key_no, 'name' => $l->key?->name, 'level' => \App\Models\MasterKey::LEVELS[$l->key?->level ?? ''] ?? '', 'holder' => $l->holder, 'purpose' => $l->purpose,
                'out' => $l->out_at->format('d-m-Y H:i'), 'due' => $l->due_at->format('d-m-Y H:i'), 'in' => $l->in_at?->format('d-m-Y H:i'),
                'status' => $l->status === 'out' ? ($l->isOverdue() ? 'OVERDUE' : 'Out') : ($l->status === 'lost' ? 'LOST' : ($l->in_at && $l->in_at->gt($l->due_at) ? 'Returned late' : 'Returned')),
                'by' => $l->issuer?->name, 'received' => $l->receiver?->name, 'note' => $l->note]);

        return ['cols' => ['key' => 'Key', 'name' => 'Name', 'level' => 'Level', 'holder' => 'Holder', 'purpose' => 'Purpose', 'out' => 'Out', 'due' => 'Due', 'in' => 'Returned', 'status' => 'Status', 'by' => 'Issued by', 'received' => 'Received by', 'note' => 'Note'],
            'rows' => $rows, 'summary' => ['Sign-outs' => (string) $rows->count(), 'Still out' => (string) $rows->whereIn('status', ['Out', 'OVERDUE'])->count(), 'Overdue' => (string) $rows->where('status', 'OVERDUE')->count(), 'Lost' => (string) $rows->where('status', 'LOST')->count()]];
    }

    // ───────────────────────── v3.8 gift vouchers ─────────────────────────

    protected function r_gift_voucher_register($from, $to): array
    {
        $rows = \App\Models\GiftVoucher::with('type', 'paymentMode', 'issuer', 'transactions')->whereBetween('issued_on', [$from->toDateString(), $to->toDateString()])->orderBy('issued_on')->orderBy('id')->get()
            ->map(fn ($v) => ['code' => $v->code, 'type' => $v->type?->name, 'source' => $v->source === 'promo' ? 'Promo'.($v->campaign ? ': '.$v->campaign : '') : 'Sold', 'purchaser' => $v->purchaser_name, 'recipient' => $v->recipient_name,
                'issued' => $v->issued_on->format('d-m-Y'), 'expires' => $v->expires_on->format('d-m-Y'), 'mode' => $v->paymentMode?->name, 'value' => $v->face_value,
                'reloads' => round((float) $v->transactions->where('type', 'reload')->sum('amount'), 2), 'redeemed' => round((float) $v->transactions->where('type', 'redeem')->sum('amount') - (float) $v->transactions->where('type', 'reversal')->sum('amount'), 2),
                'balance' => $v->balance, 'status' => $v->statusLabel(), 'by' => $v->issuer?->name]);
        $m = ['value', 'reloads', 'redeemed', 'balance'];

        return ['cols' => ['code' => 'Code', 'type' => 'Type', 'source' => 'Source', 'purchaser' => 'Purchaser', 'recipient' => 'Recipient', 'issued' => 'Issued', 'expires' => 'Expires', 'mode' => 'Paid by', 'value' => 'Value', 'reloads' => 'Reloads', 'redeemed' => 'Redeemed', 'balance' => 'Balance', 'status' => 'Status', 'by' => 'Issued by'],
            'rows' => $rows, 'money' => $m, 'sum' => $m,
            'summary' => ['Vouchers' => (string) $rows->count(), 'Sold' => money($rows->where('source', 'Sold')->sum('value')), 'Promotional' => money($rows->where('source', '!=', 'Sold')->sum('value')), 'Still outstanding' => money($rows->sum('balance'))]];
    }

    protected function r_voucher_liability($from, $to): array
    {
        $d = $to->toDateString();
        $bal = \App\Models\GiftVoucherTransaction::whereDate('business_date', '<=', $d)->selectRaw("gift_voucher_id, SUM(CASE WHEN type IN ('issue','reload','reversal') THEN amount ELSE -amount END) b")
            ->groupBy('gift_voucher_id')->havingRaw('ABS(b) > 0.004')->pluck('b', 'gift_voucher_id');
        $rows = \App\Models\GiftVoucher::with('type')->whereIn('id', $bal->keys())->orderBy('expires_on')->get()->map(function ($v) use ($bal, $to) {
            $days = (int) $to->diffInDays($v->expires_on, false);
            $bucket = $days < 0 ? 'Past expiry' : ($days <= 30 ? '0–30 days' : ($days <= 90 ? '31–90 days' : ($days <= 180 ? '91–180 days' : 'Over 180 days')));

            return ['code' => $v->code, 'type' => $v->type?->name, 'source' => $v->source === 'promo' ? 'Promo' : 'Sold', 'holder' => $v->recipient_name ?: $v->purchaser_name, 'issued' => $v->issued_on->format('d-m-Y'),
                'expires' => $v->expires_on->format('d-m-Y'), 'days' => $days, 'bucket' => $bucket, 'status' => $v->statusLabel(), 'balance' => round((float) $bal[$v->id], 2)];
        });
        $gl = 0.0;
        try {
            $gl = -(float) \App\Models\GlLine::where('account_head_id', \App\Models\AccountHead::sys('voucher_liability'))->where('date', '<=', $d)->sum(\DB::raw('debit - credit'));
        } catch (\Throwable) {
        }
        $total = round($rows->sum('balance'), 2);
        $summary = ['Outstanding' => money($total), 'Vouchers' => (string) $rows->count(), 'Sold' => money($rows->where('source', 'Sold')->sum('balance')), 'Promotional' => money($rows->where('source', 'Promo')->sum('balance'))];
        foreach (['0–30 days', '31–90 days', '91–180 days', 'Over 180 days', 'Past expiry'] as $b) {
            if ($x = $rows->where('bucket', $b)->sum('balance')) {
                $summary['Expiring '.$b] = money($x);
            }
        }
        $summary['GL 2120 balance'] = money(round($gl, 2));

        return ['cols' => ['code' => 'Code', 'type' => 'Type', 'source' => 'Source', 'holder' => 'Holder', 'issued' => 'Issued', 'expires' => 'Expires', 'days' => 'Days left', 'bucket' => 'Bucket', 'status' => 'Status', 'balance' => 'Balance'],
            'rows' => $rows, 'money' => ['balance'], 'sum' => ['balance'], 'summary' => $summary,
            'note' => 'Balances rebuilt from voucher transactions up to the date. GL 2120 includes only days already posted to the ledger – '.$this->glNote().' Promotional vouchers are carried at face value against 6150 Promotional Vouchers.'];
    }

    protected function r_voucher_redemptions($from, $to): array
    {
        $rows = \App\Models\GiftVoucherTransaction::with('voucher.type', 'user', 'folioTransaction.folio', 'posOrder')->whereIn('type', ['redeem', 'reversal'])
            ->whereDate('business_date', '>=', $from->toDateString())->whereDate('business_date', '<=', $to->toDateString())->orderBy('business_date')->orderBy('id')->get()
            ->map(fn ($t) => ['date' => $t->business_date->format('d-m-Y'), 'code' => $t->voucher?->code, 'type' => $t->voucher?->type?->name, 'movement' => $t->type === 'redeem' ? 'Redeemed' : 'Reversed',
                'where' => $t->outlet ?: ($t->folio_transaction_id ? 'Front office' : '—'), 'doc' => $t->folioTransaction?->folio?->folio_no ?? $t->posOrder?->bill_no ?? $t->reference,
                'amount' => $t->type === 'redeem' ? $t->amount : -$t->amount, 'balance' => $t->balance_after, 'by' => $t->user?->name, 'note' => $t->note]);
        $summary = ['Net redeemed' => money($rows->sum('amount')), 'Redemptions' => (string) $rows->where('movement', 'Redeemed')->count(), 'Reversals' => (string) $rows->where('movement', 'Reversed')->count()];
        foreach ($rows->groupBy('where') as $w => $g) {
            $summary[$w] = money($g->sum('amount'));
        }

        return ['cols' => ['date' => 'Date', 'code' => 'Voucher', 'type' => 'Type', 'movement' => 'Movement', 'where' => 'Where', 'doc' => 'Folio / bill', 'amount' => 'Amount', 'balance' => 'Balance after', 'by' => 'By', 'note' => 'Note'],
            'rows' => $rows, 'money' => ['amount', 'balance'], 'sum' => ['amount'], 'summary' => $summary];
    }

    protected function r_voucher_breakage($from, $to): array
    {
        $rows = \App\Models\GiftVoucherTransaction::with('voucher.type', 'paymentMode', 'user')->whereIn('type', ['expire', 'cancel'])
            ->whereDate('business_date', '>=', $from->toDateString())->whereDate('business_date', '<=', $to->toDateString())->orderBy('business_date')->orderBy('id')->get()
            ->map(function ($t) {
                $promo = $t->voucher?->source === 'promo';
                $kind = $t->type === 'expire' ? ($promo ? 'Promo lapsed' : 'Breakage') : ($t->payment_mode_id ? 'Refunded' : 'Withdrawn');

                return ['date' => $t->business_date->format('d-m-Y'), 'code' => $t->voucher?->code, 'type' => $t->voucher?->type?->name, 'source' => $promo ? 'Promo' : 'Sold', 'kind' => $kind,
                    'reason' => $t->note, 'mode' => $t->paymentMode?->name, 'amount' => $t->amount, 'by' => $t->user?->name ?? 'night audit'];
            });

        return ['cols' => ['date' => 'Date', 'code' => 'Voucher', 'type' => 'Type', 'source' => 'Source', 'kind' => 'Outcome', 'reason' => 'Reason', 'mode' => 'Refunded by', 'amount' => 'Amount', 'by' => 'By'],
            'rows' => $rows, 'money' => ['amount'], 'sum' => ['amount'],
            'summary' => ['Breakage income (4087)' => money($rows->where('kind', 'Breakage')->sum('amount')), 'Promotional cost released (6150)' => money($rows->whereIn('kind', ['Promo lapsed', 'Withdrawn'])->sum('amount')),
                'Refunded to purchasers' => money($rows->where('kind', 'Refunded')->sum('amount')), 'Vouchers' => (string) $rows->pluck('code')->unique()->count()],
            'note' => 'Breakage on unredeemed vouchers is not a supply of goods or services – no VAT is charged on it.'];
    }

    protected function r_voucher_sales($from, $to): array
    {
        $tx = \App\Models\GiftVoucherTransaction::with('voucher', 'paymentMode')->whereDate('business_date', '>=', $from->toDateString())->whereDate('business_date', '<=', $to->toDateString())->get();
        $rows = \App\Models\GiftVoucherType::orderBy('name')->get()->map(function ($type) use ($tx) {
            $t = $tx->filter(fn ($x) => $x->voucher?->gift_voucher_type_id === $type->id);
            $issue = $t->where('type', 'issue');

            return ['type' => $type->name, 'kind' => \App\Models\GiftVoucherType::KINDS[$type->kind] ?? $type->kind,
                'sold_n' => $issue->filter(fn ($x) => $x->voucher->source === 'sold')->count(), 'sold' => round($issue->filter(fn ($x) => $x->voucher->source === 'sold')->sum('amount'), 2),
                'promo_n' => $issue->filter(fn ($x) => $x->voucher->source === 'promo')->count(), 'promo' => round($issue->filter(fn ($x) => $x->voucher->source === 'promo')->sum('amount'), 2),
                'reloads' => round($t->where('type', 'reload')->sum('amount'), 2), 'redeemed' => round($t->where('type', 'redeem')->sum('amount') - $t->where('type', 'reversal')->sum('amount'), 2),
                'lapsed' => round($t->where('type', 'expire')->sum('amount'), 2), 'cancelled' => round($t->where('type', 'cancel')->sum('amount'), 2)];
        })->filter(fn ($r) => $r['sold_n'] + $r['promo_n'] || $r['reloads'] || $r['redeemed'] || $r['lapsed'] || $r['cancelled'])->values();
        $m = ['sold', 'promo', 'reloads', 'redeemed', 'lapsed', 'cancelled'];
        $summary = ['Cash in (sales + reloads)' => money($rows->sum('sold') + $rows->sum('reloads')), 'Given away' => money($rows->sum('promo')), 'Redeemed' => money($rows->sum('redeemed'))];
        foreach ($tx->whereIn('type', ['issue', 'reload'])->whereNotNull('payment_mode_id')->groupBy(fn ($x) => $x->paymentMode?->name ?? '—') as $mode => $g) {
            $summary['By '.$mode] = money($g->sum('amount'));
        }

        return ['cols' => ['type' => 'Voucher type', 'kind' => 'Kind', 'sold_n' => 'Sold', 'sold' => 'Value sold', 'promo_n' => 'Promo', 'promo' => 'Promo value', 'reloads' => 'Reloads', 'redeemed' => 'Redeemed', 'lapsed' => 'Lapsed', 'cancelled' => 'Cancelled'],
            'rows' => $rows, 'money' => $m, 'sum' => array_merge($m, ['sold_n', 'promo_n']), 'summary' => $summary];
    }

    // ───── v3.9 engineering ─────
    protected function r_utility_consumption($from, $to): array
    {
        $rows = \App\Services\UtilityService::consumption($from->toDateString(), $to->toDateString());
        $byId = $rows->keyBy(fn ($r) => $r['meter']->id);
        $out = $rows->map(function ($r) use ($byId) {
            $m = $r['meter'];
            $kids = $byId->filter(fn ($k) => $k['meter']->parent_id === $m->id);

            return ['code' => $m->code, 'meter' => $m->name, 'utility' => $m->utilityLabel(), 'area' => $m->area, 'type' => $m->is_main ? 'Main' : 'Sub-meter of '.($m->parent?->code ?? '—'),
                'n' => $r['n'], 'days' => $r['days'], 'cons' => number_format($r['cons'], 1).' '.$m->unit, 'per_day' => $r['days'] ? number_format($r['cons'] / $r['days'], 1) : '—',
                'sub' => $kids->count() && $r['cons'] > 0 ? round($kids->sum('cons') / $r['cons'] * 100, 1).'%' : '', 'est' => $r['est'], 'flags' => $r['flags'], 'cost' => $r['cost']];
        })->values();
        $summary = [];
        foreach (\App\Services\UtilityService::byUtility($from->toDateString(), $to->toDateString()) as $u => $v) {
            if ($v['cons'] > 0) {
                $summary[\App\Models\UtilityMeter::UTILITIES[$u][0]] = number_format($v['cons'], 1).' '.\App\Models\UtilityMeter::UTILITIES[$u][1].' · '.money($v['cost']);
            }
        }
        $summary['Total cost (main meters)'] = money($rows->filter(fn ($r) => $r['meter']->is_main)->sum('cost'));

        return ['cols' => ['code' => 'Meter', 'meter' => 'Name', 'utility' => 'Utility', 'area' => 'Area', 'type' => 'Type', 'n' => 'Readings', 'days' => 'Days', 'cons' => 'Consumption', 'per_day' => 'Per day',
            'sub' => 'Sub-metered', 'est' => 'Estimated', 'flags' => 'Anomalies', 'cost' => 'Cost'], 'rows' => $out, 'money' => ['cost'], 'sum' => ['n', 'est', 'flags'], 'summary' => $summary,
            'note' => 'Consumption = (reading − previous reading) × multiplier, with register roll-over and meter replacement handled. Sub-meter cost is informational – the hotel total counts main meters only. "Sub-metered" = share of the main meter measured by its sub-meters.'];
    }

    protected function r_energy_kpis($from, $to): array
    {
        $k = \App\Services\UtilityService::kpis($from->toDateString(), $to->toDateString());
        $f = fn ($v, $d = 1) => $v === null ? '—' : number_format($v, $d);
        $rows = collect($k['rows'])->map(fn ($r, $d) => ['date' => Carbon::parse($d)->format('d-m-Y D'), 'rooms' => $r['rooms'], 'guests' => $r['guests'], 'kwh' => round($r['kwh'], 1),
            'solar' => round($r['solar'], 1), 'kwh_por' => $f($r['kwh_por']), 'water' => round($r['water'], 2), 'water_lgn' => $f($r['water_lgn'], 0), 'cost' => $r['cost'],
            'cost_por' => $r['cost_por'] === null ? '—' : money($r['cost_por']), 'cost_pct' => $r['cost_pct'] === null ? '—' : $r['cost_pct'].'%', 'co2' => round($r['co2'], 1), 'co2_por' => $f($r['co2_por'])])->values();
        $t = $k['total'];

        return ['cols' => ['date' => 'Date', 'rooms' => 'Occ. rooms', 'guests' => 'Guests', 'kwh' => 'kWh', 'solar' => 'of which solar', 'kwh_por' => 'kWh / room', 'water' => 'Water m³', 'water_lgn' => 'L / guest',
            'cost' => 'Cost', 'cost_por' => 'Cost / room', 'cost_pct' => '% revenue', 'co2' => 'kg CO₂e', 'co2_por' => 'kg CO₂ / room'],
            'rows' => $rows, 'money' => ['cost'], 'sum' => ['rooms', 'guests', 'kwh', 'solar', 'water', 'cost', 'co2'],
            'summary' => ['kWh per occupied room' => $f($t['kwh_por']), 'Water per guest night' => $f($t['water_lgn'], 0).' L', 'Utility cost per occupied room' => $t['cost_por'] === null ? '—' : money($t['cost_por']),
                'Utility cost % of revenue' => $t['cost_pct'] === null ? '—' : $t['cost_pct'].'%', 'CO₂ per occupied room' => $f($t['co2_por']).' kg', 'Solar share' => $t['solar_share'].'%'],
            'note' => 'Main meters only. Occupied rooms and revenue come from the night audit (in-house stays for days not yet closed); guest-nights count adults + children in house.'];
    }

    protected function r_utility_bill_reconciliation($from, $to): array
    {
        $rows = \App\Models\UtilityBill::with('meter', 'supplierInvoice')->whereDate('bill_date', '>=', $from->toDateString())->whereDate('bill_date', '<=', $to->toDateString())->orderBy('bill_date')->get()
            ->map(fn ($b) => ['bill' => $b->bill_no, 'date' => $b->bill_date->format('d-m-Y'), 'meter' => $b->meter->code, 'period' => $b->period_from->format('d-m').' – '.$b->period_to->format('d-m-Y'),
                'billed' => round($b->units_billed, 1), 'metered' => round($b->metered_units, 1), 'var' => round($b->varianceUnits(), 1), 'var_pct' => $b->variancePct() === null ? '—' : $b->variancePct().'%',
                'energy' => $b->energy_charges, 'fixed' => $b->fixed_charges, 'taxes' => $b->taxes, 'total' => $b->total, 'rate' => number_format($b->effectiveRate(), 2), 'tariff' => number_format($b->meter->rate, 2),
                'ap' => $b->supplierInvoice?->doc_no ?? 'not booked']);
        $m = ['energy', 'fixed', 'taxes', 'total'];

        return ['cols' => ['bill' => 'Bill', 'date' => 'Date', 'meter' => 'Meter', 'period' => 'Period', 'billed' => 'Billed', 'metered' => 'Metered', 'var' => 'Variance', 'var_pct' => 'Var %',
            'energy' => 'Energy', 'fixed' => 'Fixed', 'taxes' => 'Taxes', 'total' => 'Total', 'rate' => 'Eff. rate', 'tariff' => 'Tariff', 'ap' => 'AP bill'],
            'rows' => $rows, 'money' => $m, 'sum' => $m,
            'summary' => ['Bills' => (string) $rows->count(), 'Total billed' => money($rows->sum('total')), 'Booked to AP' => money($rows->where('ap', '!=', 'not booked')->sum('total')),
                'Over ±3% variance' => (string) $rows->filter(fn ($r) => $r['var_pct'] !== '—' && abs((float) $r['var_pct']) > 3)->count()],
            'note' => 'Metered = consumption of readings after the period start up to the period end. A variance above ±3% usually means a missed reading, a wrong multiplier or a billing error.'];
    }

    protected function r_carbon_footprint($from, $to): array
    {
        $c = \App\Services\UtilityService::carbon($from->toDateString(), $to->toDateString());
        $tot = max(0.001, $c['total_kg']);
        $rows = collect($c['rows'])->map(fn ($r) => ['source' => $r['label'], 'scope' => $r['scope'] ? 'Scope '.$r['scope'] : '—', 'cons' => round($r['cons'], 1), 'unit' => $r['unit'],
            'factor' => $r['factor'], 'kg' => round($r['kg'], 1), 't' => round($r['kg'] / 1000, 3), 'share' => round($r['kg'] / $tot * 100, 1).'%'])->values();
        $s1 = collect($c['rows'])->where('scope', 1)->sum('kg');
        $s2 = collect($c['rows'])->where('scope', 2)->sum('kg');

        return ['cols' => ['source' => 'Source', 'scope' => 'Scope', 'cons' => 'Consumption', 'unit' => 'Unit', 'factor' => 'kg CO₂e / unit', 'kg' => 'kg CO₂e', 't' => 't CO₂e', 'share' => 'Share'],
            'rows' => $rows, 'sum' => ['kg', 't'],
            'summary' => ['Total' => number_format($c['total_kg'] / 1000, 2).' t CO₂e', 'Scope 1 (fuels on site)' => number_format($s1 / 1000, 2).' t', 'Scope 2 (grid electricity)' => number_format($s2 / 1000, 2).' t',
                'Per occupied room' => $c['per_room'] === null ? '—' : number_format($c['per_room'], 1).' kg', 'Occupied room-nights' => (string) $c['rooms'], 'Avoided by solar' => number_format($c['avoided_kg'] / 1000, 2).' t'],
            'note' => 'Emission factors are set under Utilities › Settings (grid: CEA CO₂ Baseline Database; fuels: IPCC defaults). Main meters only; DG kWh are zero when the diesel they burn is metered.'];
    }

    protected function r_compliance_register($from, $to): array
    {
        $rows = \App\Services\ComplianceService::register($to->toDateString())->map(fn ($r) => ['item' => $r['item']->name, 'category' => $r['item']->categoryLabel(), 'department' => $r['item']->department,
            'authority' => $r['item']->authority, 'ref' => $r['item']->reference_no, 'issued' => $r['item']->issued_on?->format('d-m-Y'), 'expires' => $r['item']->expires_on?->format('d-m-Y') ?? 'No expiry',
            'days' => $r['days'], 'status' => \App\Models\ComplianceItem::STATES[$r['state']][0], 'critical' => $r['item']->is_critical ? 'Yes' : '', 'owner' => $r['item']->owner?->name, 'fee' => $r['item']->fee]);
        $by = $rows->groupBy('status');

        return ['cols' => ['item' => 'Item', 'category' => 'Category', 'department' => 'Department', 'authority' => 'Authority', 'ref' => 'Reference', 'issued' => 'Issued / done', 'expires' => 'Valid until / due',
            'days' => 'Days left', 'status' => 'Status', 'critical' => 'Critical', 'owner' => 'Responsible', 'fee' => 'Fee'],
            'rows' => $rows, 'money' => ['fee'], 'sum' => ['fee'],
            'summary' => collect(\App\Models\ComplianceItem::STATES)->mapWithKeys(fn ($s) => [$s[0] => (string) ($by[$s[0]] ?? collect())->count()])->filter(fn ($v) => $v !== '0')->all()
                + ['Critical expired' => (string) $rows->where('critical', 'Yes')->where('status', 'Expired / overdue')->count()],
            'note' => 'Status as at the report date: due soon = inside the item\'s renewal lead time; renewal applied = application submitted, awaiting the authority. Archived items are excluded.'];
    }

    protected function r_api_usage($from, $to): array
    {
        $q = \App\Models\ApiRequestLog::whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()]);
        $names = \App\Models\ApiClient::pluck('name', 'id');
        $rows = (clone $q)->whereNotNull('api_client_id')->groupBy('api_client_id', 'route')
            ->selectRaw('api_client_id, route, count(*) n, sum(status < 400) ok, sum(status between 400 and 499) c4, sum(status >= 500) c5, round(avg(duration_ms)) ms, max(created_at) last_at')
            ->get()->map(fn ($r) => ['client' => $names[$r->api_client_id] ?? '#'.$r->api_client_id, 'route' => $r->route ?? '(unmatched path)', 'calls' => (int) $r->n, 'ok' => (int) $r->ok,
                'c4' => (int) $r->c4, 'c5' => (int) $r->c5, 'rate' => $r->n ? round(($r->c4 + $r->c5) * 100 / $r->n, 1) : 0, 'ms' => (int) $r->ms,
                'last' => \Illuminate\Support\Carbon::parse($r->last_at)->format('d-m-Y H:i')])
            ->sortBy([['client', 'asc'], ['calls', 'desc']])->values();
        $refused = \App\Models\ApiRequestLog::withoutGlobalScope('hotel')->whereNull('hotel_id')->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])->count();
        $top = $rows->groupBy('client')->map->sum('calls')->sortDesc();

        return ['cols' => ['client' => 'Client', 'route' => 'Call', 'calls' => 'Requests', 'ok' => 'Successful', 'c4' => '4xx', 'c5' => '5xx', 'rate' => 'Error %', 'ms' => 'Avg ms', 'last' => 'Last call'],
            'rows' => $rows, 'sum' => ['calls', 'ok', 'c4', 'c5'],
            'summary' => ['Requests' => number_format($rows->sum('calls')), 'Errors' => number_format($rows->sum('c4') + $rows->sum('c5')), 'Clients calling' => (string) $top->count(),
                'Busiest client' => $top->keys()->first() ?? '–', 'Refused (no valid key, all hotels)' => (string) $refused],
            'chart' => ['label' => 'Requests', 'key' => 'calls', 'x' => 'route'],
            'note' => 'From the API request log (kept for '.\App\Services\ApiService::cfg()['log_days'].' days – see Administration › API & Webhooks › Settings). 4xx are caller mistakes (validation, missing scope, rate limit); 5xx need investigating – quote the request id.'];
    }

    // ───────── v4.2 security ─────────

    protected function r_security_visitors($from, $to): array
    {
        $rows = \App\Models\Visitor::with('room', 'inBy', 'outBy', 'watch')->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])->orderBy('id')->get();

        return ['cols' => ['pass' => 'Pass', 'name' => 'Visitor', 'phone' => 'Mobile', 'id' => 'ID shown', 'host' => 'Visiting', 'purpose' => 'Purpose', 'persons' => 'Persons',
            'in' => 'In', 'out' => 'Out', 'mins' => 'Minutes', 'by' => 'Let in by', 'status' => 'Status', 'flags' => 'Flags'],
            'rows' => $rows->map(fn ($v) => ['pass' => $v->pass_no, 'name' => $v->name, 'phone' => $v->phone, 'id' => $v->maskedId(), 'host' => $v->hostLabel(),
                'purpose' => \App\Models\Visitor::PURPOSES[$v->purpose] ?? $v->purpose, 'persons' => $v->persons, 'in' => $v->checked_in_at?->format('d M H:i'),
                'out' => $v->checked_out_at?->format('d M H:i'), 'mins' => $v->checked_in_at && $v->checked_out_at ? (int) $v->checked_in_at->diffInMinutes($v->checked_out_at) : null,
                'by' => $v->inBy?->name, 'status' => $v->statusLabel(),
                'flags' => collect([$v->watchlist_id ? 'watch list' : null, $v->override_reason ? 'override: '.$v->override_reason : null,
                    $v->checked_out_at && $v->valid_until && $v->checked_out_at->gt($v->valid_until) ? 'overstayed' : null, $v->source === 'portal' ? 'pre-registered' : null])->filter()->join('; ')]),
            'sum' => ['persons'],
            'summary' => ['Visitors' => (string) $rows->count(), 'People' => (string) $rows->sum('persons'), 'To guest rooms' => (string) $rows->where('host_type', 'guest')->count(),
                'Turned away' => (string) $rows->where('status', 'denied')->count(), 'Watch-list matches' => (string) $rows->whereNotNull('watchlist_id')->count(),
                'Overrides' => (string) $rows->whereNotNull('override_reason')->count()],
            'note' => 'Registered in the period. ID numbers are never stored – only the type and last 4 characters. Records older than the retention setting show as anonymised.'];
    }

    protected function r_security_gatepasses($from, $to): array
    {
        $rows = \App\Models\GatePass::with('items', 'department', 'requester', 'approver')->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])->orderBy('id')->get();

        return ['cols' => ['pass' => 'Pass', 'date' => 'Raised', 'type' => 'Type', 'purpose' => 'Purpose', 'dept' => 'Department', 'party' => 'Party', 'items' => 'Items',
            'value' => 'Est. value', 'by' => 'Raised by', 'approved' => 'Approved by', 'out' => 'Out', 'due' => 'Due back', 'status' => 'Status'],
            'rows' => $rows->map(fn ($p) => ['pass' => $p->pass_no, 'date' => $p->created_at->format('d M Y'), 'type' => \App\Models\GatePass::TYPES[$p->type],
                'purpose' => \App\Models\GatePass::PURPOSES[$p->purpose] ?? $p->purpose, 'dept' => $p->department?->name, 'party' => $p->party_name,
                'items' => $p->items->map(fn ($i) => ((float) $i->quantity + 0).' '.$i->description)->join(', '), 'value' => (float) $p->items->sum('est_value'),
                'by' => $p->requester?->name, 'approved' => $p->approver?->name, 'out' => $p->out_at?->format('d M H:i'), 'due' => $p->expected_return_date?->format('d M Y'),
                'status' => $p->statusLabel().($p->isOverdue() ? ' – '.$p->daysOverdue().' d overdue' : '')]),
            'money' => ['value'], 'sum' => ['value'],
            'summary' => ['Gate passes' => (string) $rows->count(), 'Returnable' => (string) $rows->where('type', 'returnable')->count(),
                'Non-returnable' => (string) $rows->where('type', 'non_returnable')->count(), 'Rejected' => (string) $rows->where('status', 'rejected')->count(),
                'Overdue now' => (string) $rows->filter->isOverdue()->count()]];
    }

    protected function r_security_outstanding($from, $to): array
    {
        $asAt = $to->copy()->endOfDay();
        $passes = \App\Models\GatePass::with('items', 'department', 'returns')->where('type', 'returnable')->whereNotNull('out_at')->where('out_at', '<=', $asAt)
            ->where(fn ($q) => $q->whereNull('closed_at')->orWhere('closed_at', '>', $asAt))->orderBy('expected_return_date')->get();
        $rows = collect();
        foreach ($passes as $p) {
            foreach ($p->items as $it) {
                $back = $p->returns->filter(fn ($r) => $r->returned_at->lte($asAt))->flatMap(fn ($r) => $r->lines)->where('item_id', $it->id)->sum('qty');
                $left = round((float) $it->quantity - (float) $back, 2);
                if ($left <= 0) {
                    continue;
                }
                $late = $p->expected_return_date && $p->expected_return_date->lt($to->copy()->startOfDay()) ? (int) $p->expected_return_date->diffInDays($to->copy()->startOfDay()) : 0;
                $rows->push(['pass' => $p->pass_no, 'party' => $p->party_name, 'dept' => $p->department?->name, 'item' => $it->description, 'out' => $p->out_at->format('d M Y'),
                    'qty' => $left.' '.$it->unit, 'value' => $it->est_value ? round((float) $it->est_value * $left / max(0.01, (float) $it->quantity), 2) : 0,
                    'due' => $p->expected_return_date?->format('d M Y'), 'late' => $late]);
            }
        }

        return ['cols' => ['pass' => 'Pass', 'party' => 'With', 'dept' => 'Department', 'item' => 'Item', 'out' => 'Went out', 'qty' => 'Still out', 'value' => 'Est. value', 'due' => 'Due back', 'late' => 'Days overdue'],
            'rows' => $rows->sortByDesc('late')->values(), 'money' => ['value'], 'sum' => ['value'],
            'summary' => ['Items out' => (string) $rows->count(), 'Estimated value' => money($rows->sum('value')), 'Overdue items' => (string) $rows->where('late', '>', 0)->count()],
            'note' => 'Returnable goods that had left through the gate and were not back (or written off) at the end of the chosen date.'];
    }

    protected function r_security_incidents($from, $to): array
    {
        $rows = \App\Models\SecurityIncident::with('room', 'guest', 'assignee')->whereBetween('occurred_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])->orderBy('occurred_at')->get();

        return ['cols' => ['no' => 'Incident', 'when' => 'When', 'type' => 'Type', 'severity' => 'Severity', 'title' => 'Summary', 'where' => 'Location', 'guest' => 'Guest',
            'injury' => 'Injury', 'loss' => 'Loss', 'police' => 'Police ref', 'officer' => 'Officer', 'status' => 'Status', 'resolution' => 'Resolution'],
            'rows' => $rows->map(fn ($i) => ['no' => $i->incident_no, 'when' => $i->occurred_at->format('d M Y H:i'), 'type' => $i->typeLabel(), 'severity' => $i->severityLabel(),
                'title' => $i->title, 'where' => $i->location.($i->room ? ' · '.$i->room->room_no : ''), 'guest' => $i->guest?->full_name, 'injury' => $i->injuries ? 'Yes' : '',
                'loss' => (float) $i->loss_amount, 'police' => $i->police_informed ? ($i->police_ref ?: 'informed') : '', 'officer' => $i->assignee?->name,
                'status' => $i->statusLabel(), 'resolution' => $i->resolution]),
            'money' => ['loss'], 'sum' => ['loss'],
            'summary' => ['Incidents' => (string) $rows->count(), 'High / critical' => (string) $rows->whereIn('severity', ['high', 'critical'])->count(),
                'Still open' => (string) $rows->where('status', '!=', 'closed')->count(), 'With injuries' => (string) $rows->where('injuries', true)->count(),
                'Police informed' => (string) $rows->where('police_informed', true)->count(), 'Loss / damage' => money($rows->sum('loss_amount'))]];
    }

    protected function upsellOrdersIn($from, $to)
    {
        return \App\Models\UpsellOrder::with('offer')->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])->get();
    }

    protected function r_upsell_revenue($from, $to): array
    {
        $all = $this->upsellOrdersIn($from, $to);
        $total = (float) $all->where('status', 'confirmed')->sum('amount');
        $rows = $all->groupBy('upsell_offer_id')->map(function ($g) use ($total) {
            $c = $g->where('status', 'confirmed');
            $amt = (float) $c->sum('amount');

            return ['offer' => $g->first()->offer?->name ?? '(deleted)', 'kind' => $g->first()->offer?->kindLabel() ?? $g->first()->kind, 'orders' => $c->count(),
                'pre' => (float) $c->where('channel', 'pre_arrival')->sum('amount'), 'desk' => (float) $c->where('channel', 'desk')->sum('amount'),
                'portal' => (float) $c->where('channel', 'portal')->sum('amount'), 'amount' => $amt, 'avg' => $c->count() ? round($amt / $c->count(), 2) : 0,
                'share' => $total ? round($amt * 100 / $total, 1) : 0, 'declined' => $g->where('status', 'declined')->count(),
                'cancelled' => $g->whereIn('status', ['cancelled', 'expired'])->count()];
        })->sortByDesc('amount')->values();

        return ['cols' => ['offer' => 'Offer', 'kind' => 'Type', 'orders' => 'Confirmed', 'pre' => 'Pre-arrival', 'desk' => 'Front desk', 'portal' => 'In-house',
            'amount' => 'Revenue', 'avg' => 'Avg sale', 'share' => 'Share %', 'declined' => 'Declined', 'cancelled' => 'Cancelled / expired'],
            'rows' => $rows, 'money' => ['pre', 'desk', 'portal', 'amount', 'avg'], 'sum' => ['orders', 'pre', 'desk', 'portal', 'amount', 'declined', 'cancelled'],
            'summary' => ['Upsell revenue' => money($total), 'Confirmed orders' => (string) $rows->sum('orders'),
                'Room upgrades' => money($all->where('status', 'confirmed')->where('kind', 'upgrade')->sum('amount')),
                'Add-ons' => money($all->where('status', 'confirmed')->where('kind', 'addon')->sum('amount')),
                'Early / late' => money($all->where('status', 'confirmed')->whereIn('kind', ['early_checkin', 'late_checkout'])->sum('amount'))],
            'chart' => ['label' => 'Revenue', 'key' => 'amount', 'x' => 'offer'],
            'note' => 'Orders placed in the period, before tax. Revenue is the value booked on confirmation; it reaches the folio as fixed charges (nightly items with the night audit, one-off items at check-in / check-out). Cancelled orders stop their charge.'];
    }

    protected function r_upsell_conversion($from, $to): array
    {
        $range = [$from->copy()->startOfDay(), $to->copy()->endOfDay()];
        $pres = \App\Models\UpsellPresentation::whereBetween('created_at', $range)->groupBy('channel')
            ->selectRaw('channel, count(*) n, sum(opened_at is not null) opened, sum(converted_at is not null) conv')->get()->keyBy('channel');
        $orders = $this->upsellOrdersIn($from, $to);
        $rows = collect(\App\Models\UpsellOrder::CHANNELS)->map(function ($label, $ch) use ($pres, $orders) {
            $p = $pres[$ch] ?? null;
            $c = $orders->where('channel', $ch)->where('status', 'confirmed');
            $n = (int) ($p->n ?? 0);

            return ['channel' => $label, 'offered' => $n, 'opened' => (int) ($p->opened ?? 0), 'converted' => (int) ($p->conv ?? 0),
                'rate' => $n ? round(($p->conv ?? 0) * 100 / $n, 1) : 0, 'orders' => $c->count(), 'amount' => (float) $c->sum('amount'),
                'per' => $n ? round($c->sum('amount') / $n, 2) : 0];
        })->values();
        $n = $rows->sum('offered');

        return ['cols' => ['channel' => 'Channel', 'offered' => 'Guests offered', 'opened' => 'Opened', 'converted' => 'Bought', 'rate' => 'Conversion %',
            'orders' => 'Orders', 'amount' => 'Revenue', 'per' => 'Revenue / guest offered'],
            'rows' => $rows, 'money' => ['amount', 'per'], 'sum' => ['offered', 'opened', 'converted', 'orders', 'amount'],
            'summary' => ['Guests offered' => (string) $n, 'Overall conversion' => $n ? round($rows->sum('converted') * 100 / $n, 1).'%' : '–',
                'Revenue per guest offered' => $n ? money($rows->sum('amount') / $n) : '–'],
            'chart' => ['label' => 'Conversion %', 'key' => 'rate', 'x' => 'channel'],
            'note' => 'A guest counts once per channel: pre-arrival = offers e-mailed or the offers link opened before arrival; front desk = offers shown on the check-in screen; in-house = offers page opened from the guest portal. “Bought” = at least one order placed through that channel.'];
    }

    protected function r_upsell_incentives($from, $to): array
    {
        $users = \App\Models\User::pluck('name', 'id');
        $rows = $this->upsellOrdersIn($from, $to)->whereNotNull('sold_by')->where('status', 'confirmed')->groupBy('sold_by')
            ->map(fn ($g, $uid) => ['staff' => $users[$uid] ?? '#'.$uid, 'orders' => $g->count(), 'amount' => (float) $g->sum('amount'),
                'incentive' => (float) $g->sum('incentive_amount'),
                'offers' => $g->groupBy(fn ($o) => $o->offer?->name)->map->count()->sortDesc()->map(fn ($n, $k) => "{$k} ×{$n}")->implode(', ')])
            ->sortByDesc('incentive')->values();

        return ['cols' => ['staff' => 'Staff', 'orders' => 'Upsells', 'amount' => 'Sales', 'incentive' => 'Incentive earned', 'offers' => 'Offers sold'],
            'rows' => $rows, 'money' => ['amount', 'incentive'], 'sum' => ['orders', 'amount', 'incentive'],
            'summary' => ['Incentives earned' => money($rows->sum('incentive')), 'Desk upsell sales' => money($rows->sum('amount')), 'Staff selling' => (string) $rows->count()],
            'chart' => ['label' => 'Incentive', 'key' => 'incentive', 'x' => 'staff'],
            'note' => 'Desk sales confirmed in the period (orders placed on the check-in screen or the reservation). Incentive rates are set per offer; a cancelled upsell earns nothing. Add the totals as a one-off earning in the payroll run.'];
    }

    protected function r_webhook_deliveries($from, $to): array
    {
        $names = \App\Models\WebhookEndpoint::pluck('name', 'id');
        $rows = \App\Models\WebhookDelivery::whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])->get()
            ->groupBy(fn ($d) => $d->webhook_endpoint_id.'|'.$d->event)->map(function ($g) use ($names) {
                $done = $g->where('status', 'success');

                return ['endpoint' => $names[$g->first()->webhook_endpoint_id] ?? '(deleted)', 'event' => $g->first()->event, 'queued' => $g->count(), 'delivered' => $done->count(),
                    'pending' => $g->where('status', 'pending')->count(), 'failed' => $g->where('status', 'failed')->count(),
                    'first' => $g->count() ? round($done->where('attempts', 1)->count() * 100 / $g->count(), 1) : 0,
                    'attempts' => round($g->avg('attempts'), 2), 'ms' => (int) round($done->avg('duration_ms') ?? 0)];
            })->sortBy([['endpoint', 'asc'], ['queued', 'desc']])->values();
        $q = $rows->sum('queued');

        return ['cols' => ['endpoint' => 'Endpoint', 'event' => 'Event', 'queued' => 'Queued', 'delivered' => 'Delivered', 'pending' => 'Retrying', 'failed' => 'Gave up',
            'first' => '1st-try %', 'attempts' => 'Avg attempts', 'ms' => 'Avg ms'],
            'rows' => $rows, 'sum' => ['queued', 'delivered', 'pending', 'failed'],
            'summary' => ['Events queued' => (string) $q, 'Delivered' => $q ? round($rows->sum('delivered') * 100 / $q, 1).'%' : '–', 'Still retrying' => (string) $rows->sum('pending'),
                'Gave up' => (string) $rows->sum('failed')],
            'note' => 'Deliveries created in the period. A delivery is retried up to '.\App\Services\WebhookService::MAX_ATTEMPTS.' times with growing back-off (1 min → 24 h) before it gives up; an endpoint is switched off after '.\App\Services\ApiService::cfg()['disable_after'].' failures in a row.'];
    }

    protected function r_journal_register($from, $to): array
    {
        $rows = \App\Services\GlReportService::journalRegister($from->toDateString(), $to->toDateString());

        return ['cols' => ['date' => 'Date', 'jv' => 'Journal', 'source' => 'Source', 'ref' => 'Reference', 'narration' => 'Narration', 'account' => 'Account', 'memo' => 'Memo', 'debit' => 'Debit', 'credit' => 'Credit'],
            'rows' => $rows, 'money' => ['debit', 'credit'], 'sum' => ['debit', 'credit'], 'note' => $this->glNote()];
    }

    protected function roomNightsBy($from, $to, string $key): Collection
    {
        return ReservationRoom::with('reservation.source', 'reservation.company', 'folio.transactions')->whereIn('status', ['checked_in', 'checked_out'])
            ->whereDate('arrival_date', '<=', $to)->whereDate('departure_date', '>', $from)->get()
            ->groupBy(fn ($s) => ($key === 'source' ? $s->reservation->source?->name : $s->reservation->company?->name) ?? ($key === 'source' ? 'Direct / Walk-in' : 'Individual (FIT)'))
            ->map(function ($g, $name) use ($from, $to) {
                $end = $to->copy()->addDay();
                $nights = $g->sum(fn ($s) => max(0, (int) ($s->arrival_date->gt($from) ? $s->arrival_date : $from)->diffInDays($s->departure_date->lt($end) ? $s->departure_date : $end)));
                $rev = $g->sum(fn ($s) => $s->folio ? $s->folio->transactions->where('is_void', false)->where('type', 'charge')->where('category', 'room')->whereDate('business_date', '>=', $from)->whereDate('business_date', '<=', $to)->sum('amount') : 0);

                return ['name' => $name, 'stays' => $g->count(), 'nights' => (int) $nights, 'revenue' => $rev, 'adr' => $nights ? round($rev / $nights, 2) : 0];
            })->sortByDesc('revenue')->values();
    }

    protected function r_source($from, $to): array
    {
        return ['cols' => ['name' => 'Source', 'stays' => 'Stays', 'nights' => 'Room nights', 'revenue' => 'Room revenue', 'adr' => 'ADR'], 'rows' => $this->roomNightsBy($from, $to, 'source'), 'money' => ['revenue', 'adr'], 'sum' => ['stays', 'nights', 'revenue'], 'chart' => ['label' => 'Revenue', 'key' => 'revenue', 'x' => 'name']];
    }

    protected function r_company($from, $to): array
    {
        return ['cols' => ['name' => 'Company / Agent', 'stays' => 'Stays', 'nights' => 'Room nights', 'revenue' => 'Room revenue', 'adr' => 'ADR'], 'rows' => $this->roomNightsBy($from, $to, 'company'), 'money' => ['revenue', 'adr'], 'sum' => ['stays', 'nights', 'revenue']];
    }

    protected function r_nationality($from, $to): array
    {
        $rows = ReservationRoom::with('reservation.guest', 'guest')->whereIn('status', ['checked_in', 'checked_out'])->whereDate('arrival_date', '>=', $from)->whereDate('arrival_date', '<=', $to)->get()
            ->groupBy(fn ($s) => ($s->guest ?? $s->reservation->guest)?->nationality ?: 'Unknown')
            ->map(fn ($g, $n) => ['nat' => $n, 'arrivals' => $g->count(), 'pax' => $g->sum('adults') + $g->sum('children'), 'nights' => $g->sum(fn ($s) => $s->nights)])->sortByDesc('arrivals')->values();

        return ['cols' => ['nat' => 'Nationality', 'arrivals' => 'Arrivals', 'pax' => 'Pax', 'nights' => 'Room nights'], 'rows' => $rows, 'sum' => ['arrivals', 'pax', 'nights'], 'chart' => ['label' => 'Arrivals', 'key' => 'arrivals', 'x' => 'nat']];
    }

    protected function r_police($from, $to): array
    {
        $home = strtolower(hotel()->country ?? 'india');
        $rows = ReservationRoom::with('reservation.guest', 'guest', 'room')->whereIn('status', ['checked_in', 'checked_out'])->whereBetween('checked_in_at', [$from, $to->copy()->endOfDay()])->get()
            ->filter(fn ($s) => ($n = strtolower((string) ($s->guest ?? $s->reservation->guest)?->nationality)) && ! in_array($n, [$home, 'indian', 'india']))
            ->map(function ($s) {
                $g = $s->guest ?? $s->reservation->guest;

                return ['name' => $g->full_name, 'nat' => $g->nationality, 'passport' => $g->passport_no ?: $g->id_number, 'visa' => optional($g->visa_expiry)->format('d-m-Y'), 'dob' => optional($g->dob)->format('d-m-Y'),
                    'address' => trim($g->address.' '.$g->city.' '.$g->country), 'room' => $s->room?->room_no, 'in' => $s->checked_in_at?->format('d-m-Y H:i'), 'dep' => $s->departure_date->format('d-m-Y'), 'purpose' => $s->reservation->purpose];
            })->values();

        return ['cols' => ['name' => 'Guest', 'nat' => 'Nationality', 'passport' => 'Passport / ID', 'visa' => 'Visa expiry', 'dob' => 'DOB', 'address' => 'Address', 'room' => 'Room', 'in' => 'Checked in', 'dep' => 'Departure', 'purpose' => 'Purpose'], 'rows' => $rows];
    }

    protected function r_room_status($from, $to): array
    {
        $rows = Room::with('roomType', 'floor', 'currentStay.reservation.guest')->where('is_active', true)->orderBy('room_no')->get()
            ->map(fn ($r) => ['room' => $r->room_no, 'type' => $r->roomType->name, 'floor' => $r->floor?->name, 'status' => str_replace('_', ' ', $r->status), 'hk' => str_replace('_', ' ', $r->hk_status),
                'guest' => $r->currentStay?->reservation?->guest?->full_name, 'dep' => $r->currentStay?->departure_date?->format('d-m-Y'), 'notes' => $r->notes]);

        return ['cols' => ['room' => 'Room', 'type' => 'Type', 'floor' => 'Floor', 'status' => 'Occupancy', 'hk' => 'HK status', 'guest' => 'Guest', 'dep' => 'Departure', 'notes' => 'Notes'], 'rows' => $rows];
    }

    protected function r_maintenance($from, $to): array
    {
        $rows = MaintenanceRequest::with('room', 'employee', 'reporter')->whereBetween('created_at', [$from, $to->copy()->endOfDay()])->latest()->get()
            ->map(fn ($m) => ['no' => $m->ticket_no, 'date' => $m->created_at->format('d-m-Y H:i'), 'loc' => $m->room?->room_no ?? $m->location, 'cat' => $m->category, 'title' => $m->title, 'priority' => $m->priority,
                'status' => str_replace('_', ' ', $m->status), 'tech' => $m->employee?->name, 'resolved' => $m->resolved_at?->format('d-m-Y H:i'), 'cost' => (float) $m->cost]);

        return ['cols' => ['no' => 'Ticket', 'date' => 'Raised', 'loc' => 'Room / Location', 'cat' => 'Category', 'title' => 'Issue', 'priority' => 'Priority', 'status' => 'Status', 'tech' => 'Assigned', 'resolved' => 'Resolved', 'cost' => 'Cost'], 'rows' => $rows, 'money' => ['cost'], 'sum' => ['cost']];
    }

    protected function r_guest_requests($from, $to): array
    {
        $sla = max(1, (int) setting('portal_sla_minutes', 15));
        $all = \App\Models\GuestRequest::whereBetween('created_at', [$from, $to->copy()->endOfDay()])->get();
        $rows = $all->groupBy('category')->map(function ($g, $cat) use ($sla) {
            $resp = $g->filter(fn ($r) => $r->acknowledged_at)->map(fn ($r) => $r->created_at->diffInMinutes($r->acknowledged_at));
            $comp = $g->where('status', 'done')->filter(fn ($r) => $r->completed_at)->map(fn ($r) => $r->created_at->diffInMinutes($r->completed_at));

            return ['cat' => \App\Models\GuestRequest::CATEGORIES[$cat][0] ?? $cat, 'total' => $g->count(), 'portal' => $g->where('source', 'portal')->count(),
                'done' => $g->where('status', 'done')->count(), 'declined' => $g->where('status', 'declined')->count(), 'open' => $g->whereIn('status', \App\Models\GuestRequest::OPEN)->count(),
                'resp' => $resp->count() ? round($resp->avg(), 1) : null, 'comp' => $comp->count() ? round($comp->avg(), 1) : null,
                'breach' => $g->filter(fn ($r) => $r->created_at->diffInMinutes($r->acknowledged_at ?? ($r->isOpen() ? now() : $r->created_at)) >= $sla)->count(),
                'rating' => $g->whereNotNull('rating')->count() ? round($g->whereNotNull('rating')->avg('rating'), 1) : null];
        })->sortByDesc('total')->values();

        return ['cols' => ['cat' => 'Request type', 'total' => 'Requests', 'portal' => 'Via portal', 'done' => 'Done', 'declined' => 'Declined', 'open' => 'Still open',
            'resp' => 'Avg response (min)', 'comp' => 'Avg completion (min)', 'breach' => "SLA breaches (> {$sla} min)", 'rating' => 'Avg rating ★'],
            'rows' => $rows, 'sum' => ['total', 'portal', 'done', 'declined', 'open', 'breach'], 'chart' => ['label' => 'Requests', 'key' => 'total', 'x' => 'cat'],
            'note' => 'Response = raised → acknowledged. Completion = raised → done. Breaches count requests left unanswered longer than the SLA set in Settings.'];
    }

    protected function r_hk_productivity($from, $to): array
    {
        $tasks = \App\Models\HousekeepingTask::with('employee')->whereDate('task_date', '>=', $from->toDateString())->whereDate('task_date', '<=', $to->toDateString())->get();
        $rows = $tasks->groupBy(fn ($t) => $t->employee?->name ?? 'Unassigned')->map(function ($g, $name) {
            $done = $g->where('status', 'completed');
            $mins = $done->map->minutes()->filter(fn ($m) => $m !== null);
            $insp = $g->whereNotNull('inspection_status');

            return ['name' => $name, 'assigned' => $g->count(), 'done' => $done->count(), 'credits' => (int) $done->sum('credits'),
                'avg' => $mins->count() ? round($mins->avg(), 1) : null, 'dnd' => $g->whereNotNull('dnd_at')->count(), 'reclean' => (int) $g->sum('reclean_count'),
                'pass' => $insp->count() ? round($insp->count() / max(1, $insp->count() + $g->sum('reclean_count')) * 100).'%' : '—'];
        })->sortByDesc('credits')->values();

        return ['cols' => ['name' => 'Attendant', 'assigned' => 'Tasks', 'done' => 'Completed', 'credits' => 'Credits done', 'avg' => 'Avg min / room', 'dnd' => 'DND', 'reclean' => 'Re-cleans', 'pass' => 'First-time pass'],
            'rows' => $rows, 'sum' => ['assigned', 'done', 'credits', 'dnd', 'reclean'], 'chart' => ['label' => 'Credits completed', 'key' => 'credits', 'x' => 'name']];
    }

    protected function r_minibar($from, $to): array
    {
        $rows = FolioTransaction::with('folio.guest', 'folio.stay.room', 'poster')->where('category', 'minibar')->where('type', 'charge')->where('is_void', false)
            ->whereDate('business_date', '>=', $from->toDateString())->whereDate('business_date', '<=', $to->toDateString())->orderBy('business_date')->get()
            ->map(fn ($t) => ['date' => $t->business_date->format('d-m-Y'), 'room' => $t->folio?->stay?->room?->room_no, 'guest' => $t->folio?->guest?->full_name, 'desc' => $t->description,
                'amount' => (float) $t->amount, 'tax' => (float) $t->tax_amount, 'by' => $t->poster?->name]);

        return ['cols' => ['date' => 'Date', 'room' => 'Room', 'guest' => 'Guest', 'desc' => 'Item', 'amount' => 'Amount', 'tax' => 'Tax', 'by' => 'Posted by'], 'rows' => $rows, 'money' => ['amount', 'tax'], 'sum' => ['amount', 'tax']];
    }

    protected function r_spa_revenue($from, $to): array
    {
        $appts = \App\Models\SpaAppointment::with('service', 'therapist')->whereBetween('date', [$from->toDateString(), $to->toDateString()])->get();
        $rows = $appts->groupBy(fn ($a) => $a->service->name.'|'.($a->therapist?->name ?? '—'))->map(function ($g) {
            $done = $g->where('status', 'completed');
            $paid = $done->where('settlement', '!=', 'complimentary');

            return ['service' => $g->first()->service->name, 'cat' => \App\Models\SpaService::CATEGORIES[$g->first()->service->category] ?? '', 'therapist' => $g->first()->therapist?->name ?? '—',
                'booked' => $g->count(), 'done' => $done->count(), 'pax' => (int) $done->sum('pax'), 'lost' => $g->whereIn('status', ['cancelled', 'no_show'])->count(),
                'room' => round($paid->where('settlement', 'room_post')->sum(fn ($a) => $a->net()), 2), 'direct' => round($paid->where('settlement', 'payment')->sum(fn ($a) => $a->net()), 2),
                'comp' => round($done->where('settlement', 'complimentary')->sum(fn ($a) => $a->net()), 2), 'revenue' => round($paid->sum(fn ($a) => $a->net()), 2)];
        })->sortByDesc('revenue')->values();

        return ['cols' => ['service' => 'Service', 'cat' => 'Category', 'therapist' => 'Therapist', 'booked' => 'Bookings', 'done' => 'Completed', 'pax' => 'Guests', 'lost' => 'Cancelled / no-show',
            'room' => 'Posted to room', 'direct' => 'Direct paid', 'comp' => 'Complimentary (value)', 'revenue' => 'Revenue'],
            'rows' => $rows, 'money' => ['room', 'direct', 'comp', 'revenue'], 'sum' => ['booked', 'done', 'pax', 'lost', 'room', 'direct', 'comp', 'revenue'],
            'chart' => ['label' => 'Revenue', 'key' => 'revenue', 'x' => 'service'], 'note' => 'Revenue is before tax; tax is shown on the folio / invoice.'];
    }

    protected function r_revenue($from, $to): array
    {
        $tx = FolioTransaction::where('is_void', false)->whereDate('business_date', '>=', $from)->whereDate('business_date', '<=', $to)->whereIn('type', ['charge', 'discount', 'allowance'])->get();
        $cats = config('pms.charge_categories');
        $rows = $tx->groupBy(fn ($t) => $t->business_date->toDateString())->sortKeys()->map(function ($g, $d) use ($cats) {
            $row = ['date' => Carbon::parse($d)->format('d-m-Y')];
            foreach ($cats as $k => $l) {
                $row[$k] = $g->where('type', 'charge')->where('category', $k)->sum('amount');
            }
            $row['discount'] = -$g->whereIn('type', ['discount', 'allowance'])->sum('amount');
            $row['tax'] = $g->sum('tax_amount');
            $row['total'] = $g->where('type', 'charge')->sum('amount') + $row['discount'] + $row['tax'];

            return $row;
        })->values();
        $used = collect($cats)->filter(fn ($l, $k) => $rows->sum($k) != 0)->all();
        $keys = array_merge(array_keys($used), ['discount', 'tax', 'total']);

        return ['cols' => ['date' => 'Date'] + $used + ['discount' => 'Discount', 'tax' => 'Tax', 'total' => 'Total'], 'rows' => $rows, 'money' => $keys, 'sum' => $keys];
    }

    protected function r_collection($from, $to): array
    {
        $rows = FolioTransaction::with('folio.guest', 'paymentMode', 'poster')->where('is_void', false)->whereIn('type', ['payment', 'refund'])->whereDate('business_date', '>=', $from)->whereDate('business_date', '<=', $to)->orderBy('business_date')->get()
            ->map(fn ($t) => ['date' => $t->business_date->format('d-m-Y'), 'folio' => $t->folio->folio_no, 'guest' => $t->folio->guest?->full_name, 'desc' => $t->description,
                'mode' => $t->category === 'city_ledger' ? 'City Ledger' : $t->paymentMode?->name, 'ref' => $t->reference, 'amount' => $t->type === 'refund' ? -(float) $t->amount : (float) $t->amount, 'user' => $t->poster?->username]);

        return ['cols' => ['date' => 'Date', 'folio' => 'Folio', 'guest' => 'Guest', 'desc' => 'Description', 'mode' => 'Mode', 'ref' => 'Reference', 'amount' => 'Amount', 'user' => 'Cashier'], 'rows' => $rows, 'money' => ['amount'], 'sum' => ['amount'],
            'summary' => $rows->groupBy('mode')->map->sum('amount')];
    }

    protected function r_tax($from, $to): array
    {
        $split = function (string $src, string $cat, $rate, float $taxable, float $tax, ?string $group) {
            $b = \App\Services\TaxService::breakdown($tax, $group, (float) $rate);

            return ['src' => $src, 'cat' => $cat, 'rate' => $rate, 'taxable' => round($taxable, 2), 'vat' => $b['VAT'], 'nhil' => $b['NHIL'], 'getfund' => $b['GETFUND'], 'tl' => $b['TL'], 'tax' => round($tax, 2)];
        };
        $rows = FolioTransaction::where('is_void', false)->where('type', 'charge')->whereDate('business_date', '>=', $from)->whereDate('business_date', '<=', $to)->get()
            ->groupBy(fn ($t) => $t->category.'|'.(float) $t->tax_rate)
            ->map(function ($g, $k) use ($split) {
                [$cat, $rate] = explode('|', $k);

                return $split('Front Office', config('pms.charge_categories')[$cat] ?? $cat, $rate, (float) $g->sum('amount'), (float) $g->sum('tax_amount'), $cat);
            })->values();
        $pos = PosOrderItem::whereHas('order', fn ($q) => $q->where('status', 'settled')->where('settlement', '!=', 'room_post')->whereDate('business_date', '>=', $from)->whereDate('business_date', '<=', $to))->where('is_cancelled', false)->get()
            ->groupBy(fn ($i) => (float) $i->tax_rate)
            ->map(fn ($g, $rate) => $split('POS (direct)', 'Food & Beverage', $rate, (float) $g->sum('amount'), round($g->sum(fn ($i) => $i->amount * $i->tax_rate / 100), 2), 'fnb'))->values();
        $m = ['taxable', 'vat', 'nhil', 'getfund', 'tl', 'tax'];

        return ['cols' => ['src' => 'Source', 'cat' => 'Category', 'rate' => 'Rate %', 'taxable' => 'Taxable value', 'vat' => 'VAT', 'nhil' => 'NHIL', 'getfund' => 'GETFund', 'tl' => 'Tourism levy', 'tax' => 'Total tax'],
            'rows' => $rows->concat($pos)->values(), 'money' => $m, 'sum' => $m,
            'note' => 'VAT, NHIL and GETFund are payable to GRA (VAT return); the Tourism Levy to the Ghana Tourism Authority. POS bills posted to room are included under Front Office (F&B). Taxable value for POS is before bill-level discount.'];
    }

    protected function r_invoices($from, $to): array
    {
        $rows = Folio::with('guest', 'company', 'activeTransactions')->where('status', 'closed')->whereBetween('closed_at', [$from, $to->copy()->endOfDay()])->orderBy('closed_at')->get()
            ->map(function ($f) {
                $t = $f->totals();

                return ['inv' => $f->invoice_no, 'date' => $f->closed_at->format('d-m-Y'), 'folio' => $f->folio_no, 'guest' => $f->guest?->full_name, 'gstin' => $f->buyer_gstin ?? ($f->company?->tax_number ?? $f->guest?->tax_number),
                    'taxable' => $t['charges'] - $t['tax'] - $t['discount'], 'tax' => $t['tax'], 'total' => $t['charges'] - $t['discount'], 'paid' => $t['payments'] - $t['refunds']];
            });

        return ['cols' => ['inv' => 'Invoice', 'date' => 'Date', 'folio' => 'Folio', 'guest' => 'Guest', 'gstin' => 'TIN', 'taxable' => 'Taxable', 'tax' => 'Tax', 'total' => 'Invoice total', 'paid' => 'Settled'],
            'rows' => $rows, 'money' => ['taxable', 'tax', 'total', 'paid'], 'sum' => ['taxable', 'tax', 'total', 'paid']];
    }

    protected function r_voids($from, $to): array
    {
        $rows = FolioTransaction::with('folio.guest', 'poster')->whereDate('business_date', '>=', $from)->whereDate('business_date', '<=', $to)->where(fn ($q) => $q->where('is_void', true)->orWhereIn('type', ['discount', 'allowance']))->orderBy('business_date')->get()
            ->map(fn ($t) => ['date' => $t->business_date->format('d-m-Y'), 'folio' => $t->folio->folio_no, 'guest' => $t->folio->guest?->full_name, 'kind' => $t->is_void ? 'VOID '.$t->type : strtoupper($t->type),
                'desc' => $t->description, 'amount' => (float) $t->amount + (float) $t->tax_amount, 'reason' => $t->void_reason, 'user' => $t->poster?->username]);
        $pos = PosOrder::with('outlet', 'user')->where('status', 'void')->whereDate('business_date', '>=', $from)->whereDate('business_date', '<=', $to)->get()
            ->map(fn ($o) => ['date' => $o->business_date->format('d-m-Y'), 'folio' => $o->order_no, 'guest' => $o->outlet->name, 'kind' => 'VOID POS', 'desc' => $o->customer_name, 'amount' => (float) $o->total, 'reason' => $o->void_reason, 'user' => $o->user?->username]);

        return ['cols' => ['date' => 'Date', 'folio' => 'Folio / Order', 'guest' => 'Guest / Outlet', 'kind' => 'Type', 'desc' => 'Description', 'amount' => 'Amount', 'reason' => 'Reason', 'user' => 'User'], 'rows' => $rows->concat($pos)->values(), 'money' => ['amount'], 'sum' => ['amount']];
    }

    protected function r_outstanding($from, $to): array
    {
        $rows = Folio::with('guest', 'stay.room', 'company')->where('status', 'open')->get()
            ->map(function ($f) {
                $t = $f->totals();

                return ['folio' => $f->folio_no, 'type' => $f->type, 'room' => $f->stay?->room?->room_no, 'guest' => $f->guest?->full_name, 'company' => $f->company?->name, 'opened' => $f->created_at->format('d-m-Y'),
                    'charges' => $t['charges'] - $t['discount'], 'paid' => $t['payments'] - $t['refunds'], 'balance' => $t['balance']];
            })->filter(fn ($r) => abs($r['balance']) > 0.009)->sortByDesc('balance')->values();

        return ['cols' => ['folio' => 'Folio', 'type' => 'Type', 'room' => 'Room', 'guest' => 'Guest', 'company' => 'Company', 'opened' => 'Opened', 'charges' => 'Charges', 'paid' => 'Paid', 'balance' => 'Balance'], 'rows' => $rows, 'money' => ['charges', 'paid', 'balance'], 'sum' => ['charges', 'paid', 'balance']];
    }

    protected function r_pos_sales($from, $to): array
    {
        $rows = PosOrder::with('outlet')->where('status', 'settled')->whereDate('business_date', '>=', $from)->whereDate('business_date', '<=', $to)->get()->groupBy('outlet.name')
            ->map(fn ($g, $o) => ['outlet' => $o, 'bills' => $g->count(), 'covers' => $g->sum('covers'), 'gross' => $g->sum('subtotal'), 'discount' => $g->sum('discount'), 'sc' => $g->sum('service_charge'), 'tax' => $g->sum('tax'), 'total' => $g->sum('total'),
                'cash' => $g->where('settlement', 'payment')->sum('total'), 'room' => $g->where('settlement', 'room_post')->sum('total'), 'comp' => $g->where('settlement', 'complimentary')->sum('subtotal'), 'apc' => $g->sum('covers') ? round($g->sum('total') / $g->sum('covers'), 2) : 0])->values();
        $m = ['gross', 'discount', 'sc', 'tax', 'total', 'cash', 'room', 'comp', 'apc'];

        return ['cols' => ['outlet' => 'Outlet', 'bills' => 'Bills', 'covers' => 'Covers', 'gross' => 'Gross', 'discount' => 'Discount', 'sc' => 'Service chg', 'tax' => 'Tax', 'total' => 'Net sales', 'cash' => 'Direct settled', 'room' => 'Posted to room', 'comp' => 'Complimentary', 'apc' => 'Avg / cover'],
            'rows' => $rows, 'money' => $m, 'sum' => ['bills', 'covers', 'gross', 'discount', 'sc', 'tax', 'total', 'cash', 'room', 'comp'], 'chart' => ['label' => 'Net sales', 'key' => 'total', 'x' => 'outlet']];
    }

    protected function r_pos_items($from, $to): array
    {
        $rows = PosOrderItem::with('order.outlet', 'menuItem.category')->where('is_cancelled', false)->whereHas('order', fn ($q) => $q->where('status', 'settled')->whereDate('business_date', '>=', $from)->whereDate('business_date', '<=', $to))->get()
            ->groupBy(fn ($i) => $i->order->outlet->name.'|'.$i->item_name)
            ->map(function ($g, $k) {
                [$o, $n] = explode('|', $k, 2);

                return ['outlet' => $o, 'item' => $n, 'cat' => $g->first()->menuItem?->category?->name, 'qty' => $g->sum('qty'), 'amount' => $g->sum('amount'), 'avg' => $g->sum('qty') ? round($g->sum('amount') / $g->sum('qty'), 2) : 0];
            })->sortByDesc('amount')->values();

        return ['cols' => ['outlet' => 'Outlet', 'item' => 'Item', 'cat' => 'Category', 'qty' => 'Qty sold', 'amount' => 'Amount', 'avg' => 'Avg rate'], 'rows' => $rows, 'money' => ['amount', 'avg'], 'sum' => ['qty', 'amount']];
    }

    protected function r_pos_bills($from, $to): array
    {
        $rows = PosOrder::with('outlet', 'table', 'paymentMode', 'payments.paymentMode', 'stay.room', 'user')->whereIn('status', ['settled', 'void'])->whereDate('business_date', '>=', $from)->whereDate('business_date', '<=', $to)->orderBy('business_date')->orderBy('id')->get()
            ->map(function ($o) {
                $tenders = $o->payments->where('kind', 'payment')->map(fn ($p) => $p->paymentMode?->name)->filter()->unique()->join(', ');

                return ['date' => $o->business_date->format('d-m-Y'), 'bill' => $o->bill_no ?? $o->order_no, 'outlet' => $o->outlet->name, 'table' => $o->table?->table_no ?? ($o->stay?->room ? 'Room '.$o->stay->room->room_no : $o->order_type),
                    'covers' => $o->covers, 'sub' => (float) $o->subtotal, 'disc' => (float) $o->discount, 'tax' => (float) $o->tax, 'total' => $o->status === 'void' ? 0 : (float) $o->total,
                    'settle' => $o->status === 'void' ? 'VOID' : ($o->settlement === 'payment' ? ($tenders ?: ($o->paymentMode?->name ?? 'Payment')) : str_replace('_', ' ', $o->settlement)), 'user' => $o->user?->username];
            });

        return ['cols' => ['date' => 'Date', 'bill' => 'Bill', 'outlet' => 'Outlet', 'table' => 'Table / Room', 'covers' => 'Covers', 'sub' => 'Subtotal', 'disc' => 'Discount', 'tax' => 'Tax', 'total' => 'Total', 'settle' => 'Settlement', 'user' => 'User'],
            'rows' => $rows, 'money' => ['sub', 'disc', 'tax', 'total'], 'sum' => ['covers', 'sub', 'disc', 'tax', 'total']];
    }

    protected function r_pos_payments($from, $to): array
    {
        $rows = PosPayment::with('order.outlet', 'paymentMode', 'receiver')->whereDate('business_date', '>=', $from)->whereDate('business_date', '<=', $to)->orderBy('business_date')->orderBy('id')->get()
            ->map(fn ($p) => ['date' => $p->business_date->format('d-m-Y'), 'time' => $p->received_at?->format('H:i'), 'kind' => $p->kind === 'refund' ? 'REFUND' : 'PAYMENT',
                'bill' => $p->order?->bill_no ?? $p->order?->order_no, 'outlet' => $p->order?->outlet?->name, 'mode' => $p->paymentMode?->name,
                'amount' => $p->kind === 'refund' ? -(float) $p->amount : (float) $p->amount, 'tendered' => (float) ($p->tendered_amount ?? 0),
                'change' => (float) $p->change_amount, 'reference' => $p->reference, 'cheque' => trim(implode(' · ', array_filter([$p->cheque_bank_name, $p->cheque_number, $p->cheque_bank_branch]))) ?: null,
                'authorisation' => $p->card_authorisation, 'mobile' => trim(implode(' · ', array_filter([$p->mobile_network, $p->mobile_phone]))) ?: null,
                'cashier' => $p->receiver?->username ?? $p->receiver?->name, 'notes' => $p->notes]);

        return ['cols' => ['date' => 'Date', 'time' => 'Time', 'kind' => 'Type', 'bill' => 'Bill', 'outlet' => 'Outlet', 'mode' => 'Tender', 'amount' => 'Net amount', 'tendered' => 'Tendered',
            'change' => 'Change', 'reference' => 'Reference', 'cheque' => 'Cheque details', 'authorisation' => 'Card authorisation', 'mobile' => 'Mobile details', 'cashier' => 'Cashier', 'notes' => 'Notes'],
            'rows' => $rows, 'money' => ['amount', 'tendered', 'change'], 'sum' => ['amount', 'tendered', 'change'],
            'note' => 'Payments are positive and refunds are negative. Cash tendered and change are retained for drawer reconciliation.'];
    }

    protected function r_pos_comps($from, $to): array
    {
        // Keep whole-bill complimentary settlements and item-level complimentary
        // dishes in one audit. A line is represented once in the value totals;
        // when both mechanisms were used, its item-level event wins and the
        // remaining lines carry the whole-bill settlement context.
        $orders = PosOrder::with([
            'outlet', 'table', 'stay.room', 'user', 'settledBy',
            'items.menuItem.category', 'items.compBy',
        ])->whereDate('business_date', '>=', $from->toDateString())->whereDate('business_date', '<=', $to->toDateString())
            ->whereNotIn('status', ['merged'])
            ->where(function ($q) {
                $q->where('settlement', 'complimentary')
                    ->orWhereHas('items', fn ($items) => $items->where('is_complimentary', true));
            })->orderBy('business_date')->orderBy('id')->get();

        $rows = collect();
        foreach ($orders as $order) {
            $liveItems = $order->items->where('is_cancelled', false)->where('is_pending', false)->values();
            $wholeBill = $order->settlement === 'complimentary';
            if ($wholeBill) {
                $itemComps = $liveItems->where('is_complimentary', true)->values();
                $billItems = $liveItems->where('is_complimentary', false)->values();
                // If both mechanisms were used, retain the item-level audit
                // event and let the bill-level rows cover only the other lines.
                // This preserves both sources without counting a line twice.
                $included = $itemComps->map(fn ($item) => [$item, 'Item'])
                    ->concat($billItems->map(fn ($item) => [$item, 'Whole bill']))->values();
                // Keep the bill-level settlement visible even when every live
                // line was already marked complimentary (or lines were removed).
                if ($billItems->isEmpty()) {
                    $included->push([null, 'Whole bill']);
                }
            } else {
                $included = $liveItems->where('is_complimentary', true)->values()->map(fn ($item) => [$item, 'Item']);
            }

            foreach ($included as [$item, $kind]) {
                $quantity = (float) ($item?->qty ?? 0);
                $rate = (float) ($item?->rate ?? 0);
                $value = round($quantity * $rate, 2);
                $tax = round($value * (float) ($item?->tax_rate ?? 0) / 100, 2);
                $isItemComp = $kind === 'Item';
                $authorizer = $isItemComp ? ($item?->compBy ?? $order->user) : ($order->settledBy ?? $order->user);
                $actionAt = $isItemComp ? ($item?->comp_at ?? $item?->updated_at) : ($order->settled_at ?? $order->updated_at);
                $location = $order->table?->table_no
                    ? 'Table '.$order->table->table_no
                    : ($order->stay?->room ? 'Room '.$order->stay->room->room_no : ucwords(str_replace('_', ' ', (string) $order->order_type)));

                $rows->push([
                    'date' => $order->business_date->format('d-m-Y'),
                    'time' => $actionAt?->format('H:i'),
                    'kind' => $kind,
                    'bill' => $order->bill_no ?? $order->order_no,
                    'order' => $order->order_no,
                    'outlet' => $order->outlet?->name,
                    'location' => $location,
                    'guest' => $order->customer_name,
                    'item' => $item?->item_name ?? ($liveItems->isNotEmpty() ? '(Whole-bill settlement; item lines above)' : '(No live items recorded)'),
                    'cat' => $item?->menuItem?->category?->name,
                    'qty' => $quantity,
                    'rate' => $rate,
                    'value' => $value,
                    'tax' => $tax,
                    'gross' => round($value + $tax, 2),
                    'reason' => $isItemComp ? $item?->comp_reason : $order->comp_reason,
                    'authorized' => $authorizer?->name ?? $authorizer?->username,
                    'cashier' => $order->user?->name ?? $order->user?->username,
                    'action_at' => $actionAt?->format('d-m-Y H:i'),
                    'settlement' => $order->settlement ? ucwords(str_replace('_', ' ', $order->settlement)) : 'Not settled',
                    'status' => ucwords(str_replace('_', ' ', (string) $order->status)),
                ]);
            }
        }

        // The order query is already chronological; preserve that order so dates
        // continue to sort correctly across month boundaries.
        $rows = $rows->values();
        $money = ['rate', 'value', 'tax', 'gross'];

        return [
            'cols' => [
                'date' => 'Business date', 'time' => 'Time', 'kind' => 'Comp type', 'bill' => 'Bill', 'order' => 'Order',
                'outlet' => 'Outlet', 'location' => 'Table / Room', 'guest' => 'Guest', 'item' => 'Item', 'cat' => 'Category',
                'qty' => 'Qty', 'rate' => 'Original rate', 'value' => 'Original value', 'tax' => 'Tax reference', 'gross' => 'Value incl. tax',
                'reason' => 'Reason', 'authorized' => 'Authorised by', 'cashier' => 'Order user', 'action_at' => 'Comp / settlement at',
                'settlement' => 'Settlement', 'status' => 'Order status',
            ],
            'rows' => $rows,
            'money' => $money,
            'sum' => ['qty', 'value', 'tax', 'gross'],
            'summary' => [
                'Rows' => (string) $rows->count(),
                'Item-level rows' => (string) $rows->where('kind', 'Item')->count(),
                'Whole-bill rows' => (string) $rows->where('kind', 'Whole bill')->count(),
                'Units given away' => (string) $rows->sum('qty'),
                'Original value' => money($rows->sum('value')),
            ],
            'note' => 'Original value is quantity × the menu rate before the complimentary adjustment. Whole-bill settlements show each live POS item once. If a bill also contains item-level comp flags, those lines remain Item rows and the other lines are Whole bill rows; no line is counted twice. A zero-value Whole bill row preserves the settlement audit record when every line is item-level or no live lines remain. Tax reference is calculated at each item tax rate and is provided for audit context, not as a tax posting.',
        ];
    }

    protected function r_banquet($from, $to): array
    {
        $rows = BanquetEvent::with('functions.hall', 'functions.items', 'company', 'salesUser')->whereDate('event_date', '>=', $from)->whereDate('event_date', '<=', $to)->orderBy('event_date')->get()
            ->map(fn ($e) => ['no' => $e->event_no, 'date' => $e->event_date->format('d-m-Y'), 'title' => $e->title, 'type' => BanquetEvent::TYPES[$e->event_type] ?? $e->event_type,
                'client' => $e->company?->name ?? $e->contact_name, 'venues' => $e->functions->pluck('hall.name')->unique()->join(', '), 'fns' => $e->functions->count(),
                'pax' => $e->pax(), 'net' => $e->net(), 'tax' => $e->tax(), 'deposit' => $e->depositsReceived(), 'sales' => $e->salesUser?->name,
                'status' => (BanquetEvent::STATUSES[$e->status] ?? $e->status).($e->status === 'cancelled' && $e->cancellation_fee > 0 ? ' (fee '.number_format((float) $e->cancellation_fee, 2).')' : '')]);

        return ['cols' => ['no' => 'Event', 'date' => 'Date', 'title' => 'Title', 'type' => 'Type', 'client' => 'Client', 'venues' => 'Venue(s)', 'fns' => 'Functions', 'pax' => 'Pax',
            'net' => 'Value', 'tax' => 'Tax', 'deposit' => 'Deposits', 'sales' => 'Sales', 'status' => 'Status'], 'rows' => $rows, 'money' => ['net', 'tax', 'deposit'], 'sum' => ['fns', 'pax', 'net', 'tax', 'deposit']];
    }

    protected function r_banquet_pipeline($from, $to): array
    {
        $rows = BanquetEvent::with('functions.items')->whereDate('event_date', '>=', $from)->whereDate('event_date', '<=', $to)->orderBy('event_date')->get()
            ->groupBy(fn ($e) => $e->event_date->format('Y-m'))
            ->map(function ($g, $m) {
                $by = fn ($st) => $g->whereIn('status', (array) $st);
                $won = $by(['definite', 'completed', 'cancelled'])->count();
                $decided = $won + $by('lost')->count();

                return ['month' => Carbon::parse($m.'-01')->format('M Y'), 'enq_n' => $by('enquiry')->count(), 'enq_v' => $by('enquiry')->sum(fn ($e) => $e->net()),
                    'ten_n' => $by('tentative')->count(), 'ten_v' => $by('tentative')->sum(fn ($e) => $e->net()),
                    'def_n' => $by(['definite', 'completed'])->count(), 'def_v' => $by(['definite', 'completed'])->sum(fn ($e) => $e->net()),
                    'lost_n' => $by('lost')->count(), 'lost_v' => $by('lost')->sum(fn ($e) => $e->net()),
                    'can_n' => $by('cancelled')->count(), 'fees' => (float) $by('cancelled')->sum('cancellation_fee'),
                    'conv' => $decided ? round($won / $decided * 100, 1) : null];
            })->values();

        return ['cols' => ['month' => 'Event month', 'enq_n' => 'Enquiries', 'enq_v' => 'Enquiry value', 'ten_n' => 'Tentative', 'ten_v' => 'Tentative value', 'def_n' => 'Definite',
            'def_v' => 'Definite value', 'lost_n' => 'Lost', 'lost_v' => 'Lost value', 'can_n' => 'Cancelled', 'fees' => 'Cancel fees', 'conv' => 'Conversion %'],
            'rows' => $rows, 'money' => ['enq_v', 'ten_v', 'def_v', 'lost_v', 'fees'], 'sum' => ['enq_n', 'enq_v', 'ten_n', 'ten_v', 'def_n', 'def_v', 'lost_n', 'lost_v', 'can_n', 'fees']];
    }

    protected function r_venue_utilisation($from, $to): array
    {
        $days = Carbon::parse($from)->diffInDays(Carbon::parse($to)) + 1;
        $fns = \App\Models\BanquetFunction::with('items', 'event')->whereBetween('date', [$from, $to])
            ->whereHas('event', fn ($q) => $q->whereIn('status', ['tentative', 'definite', 'completed']))->get()->groupBy('banquet_hall_id');
        $rows = \App\Models\BanquetHall::orderBy('sort')->orderBy('name')->get()->map(function ($h) use ($fns, $days) {
            $all = $fns->get($h->id, collect());
            $firm = $all->filter(fn ($f) => $f->event->status !== 'tentative');
            $hours = $firm->sum(fn ($f) => $f->hours());

            return ['hall' => $h->name, 'cap' => $h->capacity, 'fns' => $firm->count(), 'tent' => $all->count() - $firm->count(), 'days' => $firm->pluck('date')->map->toDateString()->unique()->count(),
                'hours' => round($hours, 1), 'occ' => round($hours / ($days * 16) * 100, 1), 'covers' => $firm->sum(fn ($f) => $f->billablePax()),
                'rent' => round($firm->sum(fn ($f) => (float) $f->hall_rate), 2), 'fnb' => round($firm->sum(fn ($f) => $f->fnbAmount()), 2), 'total' => round($firm->sum(fn ($f) => $f->net()), 2)];
        });

        return ['cols' => ['hall' => 'Venue', 'cap' => 'Capacity', 'fns' => 'Functions', 'tent' => '+ Tentative', 'days' => 'Days used', 'hours' => 'Hours', 'occ' => 'Occupancy %',
            'covers' => 'Covers', 'rent' => 'Venue rent', 'fnb' => 'F&B', 'total' => 'Total revenue'],
            'rows' => $rows, 'money' => ['rent', 'fnb', 'total'], 'sum' => ['fns', 'tent', 'days', 'hours', 'covers', 'rent', 'fnb', 'total'],
            'note' => "Definite and completed functions; occupancy = hours booked ÷ ({$days} days × 16 h). Tentative holds are counted separately."];
    }

    protected function r_function_forecast($from, $to): array
    {
        $rows = \App\Models\BanquetFunction::with('event.company', 'hall', 'package', 'items')->whereBetween('date', [$from, $to])
            ->whereHas('event', fn ($q) => $q->whereIn('status', ['tentative', 'definite']))->orderBy('date')->orderBy('start_time')->get()
            ->map(fn ($f) => ['date' => $f->date->format('d-m-Y D'), 'time' => $f->timeRange(), 'venue' => $f->hall->name, 'event' => $f->event->event_no.' '.$f->event->title,
                'fn' => $f->name, 'setup' => \App\Models\BanquetFunction::SETUPS[$f->setup_style] ?? $f->setup_style, 'pax' => $f->guaranteed_pax ?? $f->expected_pax,
                'guar' => $f->guaranteed_pax !== null ? 'Yes' : 'due '.$f->guaranteeCutoff()->format('d M'), 'menu' => $f->package?->name ?? ($f->rate_per_pax > 0 ? 'Custom' : '—'),
                'extras' => $f->items->pluck('description')->join(', '), 'fnb' => $f->fnbAmount(), 'status' => BanquetEvent::STATUSES[$f->event->status] ?? $f->event->status]);

        return ['cols' => ['date' => 'Date', 'time' => 'Time', 'venue' => 'Venue', 'event' => 'Event', 'fn' => 'Function', 'setup' => 'Set-up', 'pax' => 'Covers', 'guar' => 'Guaranteed',
            'menu' => 'Menu', 'extras' => 'Services / equipment', 'fnb' => 'F&B value', 'status' => 'Status'], 'rows' => $rows, 'money' => ['fnb'], 'sum' => ['pax', 'fnb']];
    }

    protected function r_contract_production($from, $to): array
    {
        $f = Carbon::parse($from)->startOfDay();
        $t = Carbon::parse($to)->startOfDay()->addDay();
        $pub = \App\Models\RoomType::pluck('base_rate', 'id');
        $stays = \App\Models\ReservationRoom::with('reservation:id,rate_contract_id,status')->whereHas('reservation', fn ($q) => $q->whereNotNull('rate_contract_id'))
            ->whereDate('arrival_date', '<', $t)->whereDate('departure_date', '>', $f)->get()->groupBy(fn ($s) => $s->reservation->rate_contract_id);
        $rows = \App\Models\RateContract::with('company')->orderBy('code')->get()->map(function ($c) use ($stays, $f, $t, $pub) {
            $all = $stays->get($c->id, collect());
            $live = $all->where('status', '!=', 'cancelled')->filter(fn ($s) => $s->reservation->status !== 'cancelled');
            $n = fn ($s) => max(0, (int) max($s->arrival_date, $f)->diffInDays(min($s->departure_date, $t)));
            $nights = $live->sum($n);
            $rev = round($live->sum(fn ($s) => (float) $s->rate * $n($s)), 2);
            $public = round($live->sum(fn ($s) => (float) ($pub[$s->room_type_id] ?? 0) * $n($s)), 2);
            $p = \App\Services\ContractService::production($c);

            return ['code' => $c->code, 'company' => $c->company->name, 'basis' => $c->basisLabel(), 'status' => ucfirst($c->state()),
                'book' => $live->pluck('reservation_id')->unique()->count(), 'nights' => $nights, 'rev' => $rev, 'adr' => $nights ? round($rev / $nights, 2) : 0,
                'pub' => $nights ? round($public / $nights, 2) : 0, 'disc' => $public > 0 ? round((1 - $rev / $public) * 100, 1) : null,
                'cxl' => $all->filter(fn ($s) => $s->status === 'cancelled' || $s->reservation->status === 'cancelled')->pluck('reservation_id')->unique()->count(),
                'target' => $p['target'] ?: null, 'tpct' => $p['target_pct'], 'pace' => $p['pace_pct']];
        })->filter(fn ($r) => $r['nights'] > 0 || $r['cxl'] > 0 || $r['status'] === 'Active')->values();

        return ['cols' => ['code' => 'Contract', 'company' => 'Company', 'basis' => 'Pricing', 'status' => 'Status', 'book' => 'Bookings', 'nights' => 'Room nights', 'rev' => 'Room revenue',
            'adr' => 'ADR', 'pub' => 'Public ADR', 'disc' => 'Discount %', 'cxl' => 'Cancelled', 'target' => 'RN target', 'tpct' => 'Target % (to date)', 'pace' => 'Period elapsed %'],
            'rows' => $rows, 'money' => ['rev', 'adr', 'pub'], 'sum' => ['book', 'nights', 'rev', 'cxl'],
            'note' => 'Room nights and revenue count the nights of each stay that fall in the period (rate × nights, before tax). Public ADR = rack rate of the same room types. Target % uses all bookings on the contract to date.'];
    }

    protected function r_allotment_utilisation($from, $to): array
    {
        $f = Carbon::parse($from)->startOfDay();
        $t = Carbon::parse($to)->startOfDay();
        $today = bdate()->copy()->startOfDay();
        $rows = \App\Models\ContractAllotment::with('contract.company', 'roomType')->whereDate('date_from', '<=', $t)->whereDate('date_to', '>=', $f)
            ->whereHas('contract', fn ($q) => $q->whereIn('status', ['active', 'suspended']))->get()
            ->map(function ($a) use ($f, $t, $today) {
                $stays = \App\Models\ReservationRoom::where('room_type_id', $a->room_type_id)->whereIn('status', ['reserved', 'checked_in', 'checked_out'])
                    ->whereHas('reservation', fn ($q) => $q->where('rate_contract_id', $a->rate_contract_id)->where('status', '!=', 'cancelled'))
                    ->whereDate('arrival_date', '<=', min($t, $a->date_to))->whereDate('departure_date', '>', max($f, $a->date_from))->get(['arrival_date', 'departure_date']);
                $allotted = $picked = $released = $held = 0;
                for ($d = max($f, $a->date_from)->copy(); $d->lte(min($t, $a->date_to)); $d->addDay()) {
                    $p = min($a->rooms, $stays->filter(fn ($s) => $s->arrival_date->lte($d) && $s->departure_date->gt($d))->count());
                    $allotted += $a->rooms;
                    $picked += $p;
                    if ($today->diffInDays($d, false) < $a->release_days) {
                        $released += $a->rooms - $p;
                    } else {
                        $held += $a->rooms - $p;
                    }
                }

                return ['contract' => $a->contract->code, 'company' => $a->contract->company->name, 'type' => $a->roomType?->name, 'rooms' => $a->rooms, 'rel' => $a->release_days.' d',
                    'period' => $a->date_from->format('d M').' – '.$a->date_to->format('d M Y'), 'allot' => $allotted, 'picked' => $picked, 'released' => $released, 'held' => $held,
                    'pct' => $allotted ? round($picked / $allotted * 100, 1) : 0];
            })->sortBy('contract')->values();

        return ['cols' => ['contract' => 'Contract', 'company' => 'Company', 'type' => 'Room type', 'rooms' => 'Rooms / night', 'rel' => 'Release', 'period' => 'Allotment period',
            'allot' => 'Allotted RN', 'picked' => 'Picked up', 'released' => 'Released unsold', 'held' => 'Still held', 'pct' => 'Utilisation %'],
            'rows' => $rows, 'sum' => ['allot', 'picked', 'released', 'held'],
            'note' => 'Nights in the selected period only. Released = nights inside the release window (or past) that were not picked up – those rooms went back to general sale. Still held = future nights not yet released.'];
    }

    protected function r_channel_production($from, $to): array
    {
        $f = Carbon::parse($from)->startOfDay();
        $t = Carbon::parse($to)->endOfDay();
        $res = \App\Models\Reservation::whereNotNull('channel_connection_id')->whereBetween('created_at', [$f, $t])
            ->get(['id', 'channel_connection_id', 'status', 'arrival_date', 'departure_date', 'created_at', 'channel_amount', 'channel_commission', 'channel_payment'])->groupBy('channel_connection_id');
        $rows = \App\Models\ChannelConnection::orderBy('name')->get()->map(function ($c) use ($res) {
            $all = $res->get($c->id, collect());
            $live = $all->where('status', '!=', 'cancelled');
            $nights = $live->sum(fn ($r) => $r->arrival_date->diffInDays($r->departure_date));
            $gross = round((float) $live->sum('channel_amount'), 2);
            $comm = round((float) $live->sum('channel_commission'), 2);

            return ['ch' => $c->name, 'code' => $c->code, 'book' => $live->count(), 'nights' => $nights, 'gross' => $gross, 'comm' => $comm, 'net' => round($gross - $comm, 2),
                'adr' => $nights ? round($gross / $nights, 2) : 0, 'lead' => $live->count() ? round($live->avg(fn ($r) => max(0, $r->created_at->copy()->startOfDay()->diffInDays($r->arrival_date, false))), 1) : null,
                'los' => $live->count() ? round($nights / $live->count(), 1) : null, 'cxl' => $all->where('status', 'cancelled')->count(),
                'cxlp' => $all->count() ? round($all->where('status', 'cancelled')->count() / $all->count() * 100, 1) : null,
                'pre' => $live->count() ? round($live->where('channel_payment', 'channel_collect')->count() / $live->count() * 100) : null];
        })->filter(fn ($r) => $r['book'] || $r['cxl'])->values();

        return ['cols' => ['ch' => 'Channel', 'code' => 'Code', 'book' => 'Bookings', 'nights' => 'Room nights', 'gross' => 'Gross revenue', 'comm' => 'Commission', 'net' => 'Net revenue',
            'adr' => 'ADR (gross)', 'lead' => 'Avg lead (days)', 'los' => 'Avg LOS', 'cxl' => 'Cancelled', 'cxlp' => 'Cancel %', 'pre' => 'Prepaid %'],
            'rows' => $rows, 'money' => ['gross', 'comm', 'net', 'adr'], 'sum' => ['book', 'nights', 'gross', 'comm', 'net', 'cxl'],
            'chart' => ['label' => 'Net revenue', 'key' => 'net', 'x' => 'ch'],
            'note' => 'Bookings received (created) in the period through two-way connections. Gross = amount sent by the channel; commission as sent or at the contract rate; net = gross − commission. Lead time = arrival − booking date.'];
    }

    protected function r_einvoice_register($from, $to): array
    {
        $rows = Folio::whereNotNull('invoice_no')->whereIn('status', ['closed', 'cancelled'])
            ->whereDate('invoice_date', '>=', Carbon::parse($from)->toDateString())->whereDate('invoice_date', '<=', Carbon::parse($to)->toDateString())->orderBy('invoice_date')->orderBy('id')->get()
            ->map(function ($f) {
                $t = $f->gstTotals();

                return ['no' => $f->invoice_no, 'typ' => $f->isCreditNote() ? 'REFUND' : 'INVOICE', 'date' => $f->invoice_date->format('d-m-Y'), 'sup' => $f->supply_type ?: 'B2C', 'gstin' => $f->buyer_gstin, 'buyer' => $f->buyer_name,
                    'taxable' => abs($t['taxable']), 'tax' => abs($t['tax']), 'value' => abs($t['value']),
                    'status' => $f->status === 'cancelled' ? 'document cancelled' : (['generated' => 'stamped'][$f->irn_status] ?? ($f->irn_status ?? 'not stamped')),
                    'ack' => $f->irn_ack_no ? $f->irn_ack_no.' · '.$f->irn_ack_at?->format('d-m-Y H:i') : '',
                    'irn' => $f->irn ?: \Illuminate\Support\Str::limit((string) $f->irn_error, 80)];
            });
        $gen = $rows->where('status', 'stamped')->count();

        return ['cols' => ['no' => 'Document', 'typ' => 'Flag', 'date' => 'Date', 'sup' => 'Supply', 'gstin' => 'Buyer TIN', 'buyer' => 'Buyer', 'taxable' => 'Taxable', 'tax' => 'Taxes', 'value' => 'Value', 'status' => 'E-VAT status', 'ack' => 'Receipt no. / time', 'irn' => 'SDC ID / error'],
            'rows' => $rows->values(), 'money' => ['taxable', 'tax', 'value'], 'sum' => ['taxable', 'tax', 'value'],
            'note' => $rows->count().' document(s) · '.$gen.' stamped by E-VAT · '.$rows->whereIn('status', ['failed', 'pending', 'not stamped'])->count().' not stamped.'.(\App\Services\EInvoiceService::enabled() ? '' : ' E-VAT is switched off.')];
    }

    /** v4.4 */
    protected function r_pos_evat_register($from, $to): array
    {
        $rows = \App\Models\PosOrder::with('outlet:id,name')->where('status', 'settled')->where('settlement', 'payment')->where('total', '>', 0)
            ->whereDate('business_date', '>=', Carbon::parse($from)->toDateString())->whereDate('business_date', '<=', Carbon::parse($to)->toDateString())->orderBy('business_date')->orderBy('id')->get()
            ->map(function ($o) {
                $l = \App\Services\PosEvatService::lines($o);

                return ['no' => $o->bill_no, 'date' => $o->business_date->format('d-m-Y'), 'outlet' => $o->outlet?->name, 'tin' => $o->buyer_tin, 'buyer' => $o->buyer_name ?: 'Cash customer',
                    'taxable' => round($l->sum('taxable') + (float) $o->service_charge, 2), 'vat' => round($l->sum('vat'), 2), 'levies' => round($l->sum(fn ($x) => $x['nhil'] + $x['getfund'] + $x['tl']), 2),
                    'total' => (float) $o->total, 'status' => ['generated' => 'stamped'][$o->evat_status] ?? ($o->evat_status ?? 'not stamped'),
                    'rec' => $o->evat_rec_num ? $o->evat_rec_num.' · '.$o->evat_at?->format('d-m-Y H:i') : \Illuminate\Support\Str::limit((string) $o->evat_error, 80)];
            });

        return ['cols' => ['no' => 'Bill', 'date' => 'Date', 'outlet' => 'Outlet', 'tin' => 'Customer TIN', 'buyer' => 'Customer', 'taxable' => 'Taxable', 'vat' => 'VAT', 'levies' => 'NHIL + GETFund + Tourism', 'total' => 'Bill total', 'status' => 'E-VAT status', 'rec' => 'Receipt no. / error'],
            'rows' => $rows->values(), 'money' => ['taxable', 'vat', 'levies', 'total'], 'sum' => ['taxable', 'vat', 'levies', 'total'],
            'note' => $rows->count().' bill(s) paid at the outlets · '.$rows->where('status', 'stamped')->count().' stamped by E-VAT. Bills posted to rooms are on the guest invoices.'.(\App\Services\PosEvatService::enabled() ? '' : ' E-VAT for restaurant bills is switched off.')];
    }

    /** v4.4 */
    protected function r_wht_register($from, $to): array
    {
        $rows = \App\Models\SupplierPayment::with('supplier:id,name,tax_number')->whereNull('cancelled_at')
            ->whereBetween('date', [Carbon::parse($from)->toDateString(), Carbon::parse($to)->toDateString()])->orderBy('date')->orderBy('id')->get()
            ->map(fn ($p) => ['date' => $p->date->format('d-m-Y'), 'no' => $p->payment_no, 'supplier' => $p->supplier?->name, 'tin' => $p->supplier?->tax_number,
                'nature' => \App\Services\WhtService::label($p->wht_category), 'gross' => (float) $p->amount, 'base' => (float) $p->wht_base,
                'rate' => (float) $p->wht_amount > 0 ? rtrim(rtrim(number_format($p->wht_rate, 2), '0'), '.').'%' : '', 'wht' => (float) $p->wht_amount, 'wvat' => (float) $p->wvat_amount,
                'net' => $p->netPaid(), 'cert' => $p->wht_cert_no ?: (string) $p->wht_note]);

        return ['cols' => ['date' => 'Date', 'no' => 'Payment', 'supplier' => 'Supplier', 'tin' => 'Supplier TIN', 'nature' => 'Nature of payment', 'gross' => 'Gross settled', 'base' => 'Taxable (excl. VAT)', 'rate' => 'Rate', 'wht' => 'WHT', 'wvat' => 'Withholding VAT', 'net' => 'Net paid', 'cert' => 'Certificate / note'],
            'rows' => $rows->values(), 'money' => ['gross', 'base', 'wht', 'wvat', 'net'], 'sum' => ['gross', 'base', 'wht', 'wvat', 'net'],
            'note' => $rows->count().' payment(s) · '.money($rows->sum('wht')).' withheld. WHT is due to GRA by the 15th of the following month (Accounts › Withholding Tax).'];
    }

    protected function r_credit_notes($from, $to): array
    {
        $rows = Folio::with('original:id,invoice_no,invoice_date', 'guest')->where('type', 'credit_note')->whereNotNull('invoice_no')
            ->whereDate('invoice_date', '>=', Carbon::parse($from)->toDateString())->whereDate('invoice_date', '<=', Carbon::parse($to)->toDateString())->orderBy('invoice_date')->orderBy('id')->get()
            ->map(function ($n) {
                $x = $n->status === 'cancelled';
                $c = \App\Services\GstService::componentTotals(\App\Services\GstService::lines($n));
                $t = $n->gstTotals();
                $set = $n->transactions()->whereIn('type', ['payment', 'refund'])->first();

                return ['no' => $n->invoice_no, 'date' => $n->invoice_date->format('d-m-Y'), 'inv' => $n->original?->invoice_no, 'invdt' => $n->original?->invoice_date?->format('d-m-Y'),
                    'buyer' => $n->buyer_name ?? $n->guest?->full_name, 'gstin' => $n->buyer_gstin, 'reason' => Folio::NOTE_REASONS[$n->note_reason] ?? $n->note_reason,
                    'taxable' => $x ? 0 : abs($t['taxable']), 'vat' => $x ? 0 : abs($c['vat']), 'levies' => $x ? 0 : abs($c['nhil'] + $c['getfund']), 'tl' => $x ? 0 : abs($c['tl']),
                    'value' => $x ? 0 : abs($t['value']), 'settle' => $set ? ($set->type === 'refund' ? 'Refund' : 'Company account') : '',
                    'status' => $x ? 'CANCELLED' : ($n->irn_status === 'generated' ? 'issued · E-VAT stamped' : 'issued')];
            });
        $m = ['taxable', 'vat', 'levies', 'tl', 'value'];

        return ['cols' => ['no' => 'Credit note', 'date' => 'Date', 'inv' => 'Invoice', 'invdt' => 'Invoice date', 'buyer' => 'Buyer', 'gstin' => 'TIN', 'reason' => 'Reason', 'taxable' => 'Taxable', 'vat' => 'VAT', 'levies' => 'NHIL + GETFund', 'tl' => 'Tourism levy', 'value' => 'Value', 'settle' => 'Settled by', 'status' => 'Status'],
            'rows' => $rows->values(), 'money' => $m, 'sum' => $m, 'note' => 'Cancelled credit notes are listed with zero value.'];
    }

    protected function r_hsn_summary($from, $to): array
    {
        $f = Carbon::parse($from)->toDateString();
        $t = Carbon::parse($to)->toDateString();
        $acc = [];
        $keys = ['taxable', 'vat', 'nhil', 'getfund', 'tl', 'total'];
        $add = function (string $tab, array $l) use (&$acc, $keys) {
            $k = $l['desc'].'|'.$l['rate'].'|'.$tab;
            $acc[$k] ??= ['desc' => $l['desc'], 'tab' => $tab, 'rate' => $l['rate'], 'n' => 0] + array_fill_keys($keys, 0.0);
            $acc[$k]['n'] += $l['count'] ?? 1;
            foreach ($keys as $x) {
                $acc[$k][$x] = round($acc[$k][$x] + $l[$x], 2);
            }
        };
        foreach (Folio::where('status', 'closed')->whereNotNull('invoice_no')->whereDate('invoice_date', '>=', $f)->whereDate('invoice_date', '<=', $t)->get() as $d) {
            foreach (\App\Services\GstService::lines($d) as $l) {
                $add($d->supply_type === 'B2B' ? 'B2B' : 'B2C', $l);
            }
        }
        foreach (\App\Services\GstService::posLinesBetween($f, $t) as $p) {
            $add('B2C', ['desc' => 'Restaurant bills', 'total' => round($p['taxable'] + $p['tax'], 2)] + $p);
        }
        $rows = collect($acc)->sortBy(fn ($r) => $r['tab'].$r['desc'].str_pad((string) $r['rate'], 5, '0', STR_PAD_LEFT))->values();
        $m = ['taxable', 'vat', 'nhil', 'getfund', 'tl', 'total'];

        return ['cols' => ['tab' => 'Supply', 'desc' => 'Category', 'rate' => 'Rate %', 'n' => 'Lines', 'taxable' => 'Taxable value', 'vat' => 'VAT', 'nhil' => 'NHIL', 'getfund' => 'GETFund', 'tl' => 'Tourism levy', 'total' => 'Total value'],
            'rows' => $rows, 'money' => $m, 'sum' => $m,
            'note' => 'Invoices and credit notes (negative) by document date, plus restaurant bills settled at the outlet. Tax rates are set in Masters › Taxes.'];
    }

    protected function r_privacy_requests($from, $to): array
    {
        $rows = \App\Models\PrivacyRequest::with('guest:id', 'completer:id,name')->whereBetween('received_at', [Carbon::parse($from)->startOfDay(), Carbon::parse($to)->endOfDay()])
            ->orderBy('received_at')->get()->map(function ($r) {
                $days = $r->completed_at ? $r->received_at->copy()->startOfDay()->diffInDays($r->completed_at->copy()->startOfDay()) : null;
                $sla = match (true) {
                    $r->status === 'unverified' => 'awaiting e-mail',
                    $r->status === 'open' => $r->isOverdue() ? 'OVERDUE' : 'open',
                    default => $r->completed_at && $r->completed_at->toDateString() > $r->due_on->toDateString() ? 'LATE' : 'on time',
                };

                return ['no' => $r->request_no, 'type' => \App\Models\PrivacyRequest::SHORT[$r->type], 'ch' => \App\Models\PrivacyRequest::CHANNELS[$r->channel] ?? $r->channel,
                    'recv' => $r->received_at->format('d M Y'), 'due' => $r->due_on->format('d M Y').($r->extended ? ' (ext.)' : ''),
                    'id' => $r->verified_at ? (\App\Models\PrivacyRequest::METHODS[$r->verification_method] ?? 'yes') : ($r->needsIdentity() ? 'no' : 'n/a'),
                    'status' => $r->status, 'days' => $days, 'sla' => $sla, 'out' => $r->status === 'rejected' ? 'Rejected: '.$r->rejection_reason : \Illuminate\Support\Str::limit((string) $r->outcome, 90)];
            });
        $closed = $rows->whereIn('sla', ['on time', 'LATE']);

        return ['cols' => ['no' => 'Request', 'type' => 'Type', 'ch' => 'Channel', 'recv' => 'Received', 'due' => 'Due', 'id' => 'Identity check', 'status' => 'Status', 'days' => 'Days taken', 'sla' => 'Deadline', 'out' => 'Outcome'],
            'rows' => $rows->values(), 'sum' => [],
            'note' => $rows->count().' request(s) · '.$closed->where('sla', 'on time')->count().' answered on time, '.$closed->where('sla', 'LATE')->count().' late, '.$rows->where('sla', 'OVERDUE')->count().' overdue and open. Deadline = '
                .\App\Services\PrivacyService::settings()['privacy_response_days'].' days from receipt (or from e-mail confirmation for web requests), plus any one-off extension.'];
    }

    protected function r_pii_access($from, $to): array
    {
        $rows = \App\Models\PiiAccessLog::with('user:id,name', 'guest:id,first_name,last_name,title,privacy_status')->whereBetween('created_at', [Carbon::parse($from)->startOfDay(), Carbon::parse($to)->endOfDay()])
            ->orderBy('id')->get()->map(fn ($l) => ['at' => $l->created_at->format('d M Y H:i'), 'user' => $l->user?->name ?? 'system', 'action' => \App\Models\PiiAccessLog::ACTIONS[$l->action] ?? $l->action,
                'guest' => $l->guest ? $l->guest->full_name.' (G-'.$l->guest_id.')' : '—', 'detail' => $l->detail, 'reason' => $l->reason, 'ip' => $l->ip]);

        return ['cols' => ['at' => 'When', 'user' => 'Staff', 'action' => 'Action', 'guest' => 'Guest', 'detail' => 'Detail', 'reason' => 'Reason', 'ip' => 'IP'], 'rows' => $rows->values(), 'sum' => [],
            'chart' => null, 'note' => $rows->count().' access event(s) by '.$rows->pluck('user')->unique()->count().' user(s) on '.$rows->pluck('guest')->unique()->count().' guest profile(s).'];
    }

    protected function r_privacy_retention($from, $to): array
    {
        $f = Carbon::parse($from)->startOfDay();
        $t = Carbon::parse($to)->endOfDay();
        $days = [];
        foreach (\App\Models\PrivacyRetentionRun::whereBetween('created_at', [$f, $t])->get() as $r) {
            $d = $r->created_at->toDateString();
            foreach ($r->counts as $k => $n) {
                $days[$d][$k] = ($days[$d][$k] ?? 0) + (int) $n;
            }
            $days[$d]['runs'] = ($days[$d]['runs'] ?? 0) + 1;
        }
        foreach (\App\Models\PrivacyRequest::where('type', 'erasure')->where('status', 'completed')->whereBetween('completed_at', [$f, $t])->get() as $r) {
            $d = $r->completed_at->toDateString();
            $days[$d]['requests'] = ($days[$d]['requests'] ?? 0) + 1;
        }
        ksort($days);
        $rows = collect($days)->map(fn ($v, $d) => ['date' => Carbon::parse($d)->format('d M Y'), 'runs' => $v['runs'] ?? 0, 'requests' => $v['requests'] ?? 0, 'documents' => $v['documents'] ?? 0,
            'signatures' => $v['signatures'] ?? 0, 'messages' => ($v['messages'] ?? 0) + ($v['emails'] ?? 0), 'inactive' => $v['inactive'] ?? 0, 'holds' => $v['holds_ended'] ?? 0,
            'unverified' => $v['unverified'] ?? 0])->values();

        return ['cols' => ['date' => 'Date', 'runs' => 'Retention runs', 'requests' => 'Erasures on request', 'documents' => 'ID scans deleted', 'signatures' => 'Signatures deleted',
            'messages' => 'Message contents purged', 'inactive' => 'Inactive profiles anonymised', 'holds' => 'Legal holds completed', 'unverified' => 'Web requests closed'],
            'rows' => $rows, 'sum' => ['runs', 'requests', 'documents', 'signatures', 'messages', 'inactive', 'holds', 'unverified'],
            'note' => 'Profiles: '.\App\Models\Guest::where('privacy_status', 'erased')->count().' erased and '.\App\Models\Guest::where('privacy_status', 'restricted')->count().' restricted under a legal hold (all time).'];
    }

    protected function r_channel_sync_log($from, $to): array
    {
        $rows = \App\Models\ChannelPush::with('connection:id,name')->whereBetween('created_at', [Carbon::parse($from)->startOfDay(), Carbon::parse($to)->endOfDay()])->orderBy('id')->get()
            ->groupBy(fn ($p) => $p->created_at->toDateString().'|'.$p->channel_connection_id)
            ->map(function ($g) {
                $failed = $g->where('status', 'failed');

                return ['date' => $g->first()->created_at->format('d M Y'), 'ch' => $g->first()->connection?->name, 'pushes' => $g->count(), 'full' => $g->where('kind', 'full')->count(),
                    'ok' => $g->where('status', 'ok')->count(), 'failed' => $failed->count(), 'rn' => (int) $g->where('status', 'ok')->sum('room_days'),
                    'ms' => (int) round($g->avg('duration_ms')), 'err' => $failed->last()?->error];
            })->sortByDesc(fn ($r, $k) => $k)->values();

        return ['cols' => ['date' => 'Date', 'ch' => 'Channel', 'pushes' => 'Pushes', 'full' => 'Full refreshes', 'ok' => 'OK', 'failed' => 'Failed', 'rn' => 'Room-nights sent', 'ms' => 'Avg ms', 'err' => 'Last error'],
            'rows' => $rows, 'sum' => ['pushes', 'full', 'ok', 'failed', 'rn'],
            'note' => 'Failed pushes retry automatically (1, 5, 15, 30, then every 60 minutes) and the next successful push carries every pending change. Push history is kept for 90 days.'];
    }

    protected function r_channel_parity($from, $to): array
    {
        $f = Carbon::parse($from)->startOfDay()->max(bdate()->copy()->startOfDay());
        $days = min(60, max(1, (int) $f->diffInDays(Carbon::parse($to)->startOfDay()) + 1));
        $types = \App\Models\RoomType::pluck('name', 'id');
        $rows = collect();
        foreach (\App\Models\ChannelConnection::where('is_active', true)->orderBy('name')->get() as $c) {
            foreach (\App\Services\ChannelService::parity($c, $f, $days) as $typeId => $byDate) {
                foreach ($byDate as $date => $cell) {
                    if (! $cell['out_of_sync']) {
                        continue;
                    }
                    $e = $cell['expected'];
                    $h = $cell['has'];
                    $rates = collect($e['rates'])->filter(fn ($p, $code) => ! isset($h['rates'][$code]) || abs((float) $h['rates'][$code] - $p) > 0.004)
                        ->map(fn ($p, $code) => $code.' '.number_format($p).' vs '.(isset($h['rates'][$code]) ? number_format($h['rates'][$code]) : '–'))->implode('; ');
                    $rows->push(['date' => Carbon::parse($date)->format('D d M'), 'ch' => $c->name, 'room' => $types[$typeId] ?? '?', 'pms' => $e['stop_sell'] ? 'closed' : $e['available'],
                        'has' => $h ? ($h['stop_sell'] ? 'closed' : $h['available']) : 'never pushed', 'rates' => $rates ?: '—',
                        'minstay' => $h && $h['min_stay'] !== $e['min_stay'] ? $e['min_stay'].' vs '.$h['min_stay'] : '', 'pushed' => $cell['pushed_at']?->format('d M H:i') ?? '—']);
                }
            }
        }

        return ['cols' => ['date' => 'Night', 'ch' => 'Channel', 'room' => 'Room type', 'pms' => 'PMS availability', 'has' => 'Channel has', 'rates' => 'Prices (PMS vs channel)', 'minstay' => 'Min stay (PMS vs channel)', 'pushed' => 'Last pushed'],
            'rows' => $rows->values(), 'note' => "Active connections, {$days} night(s) from ".$f->format('d M Y').' (max 60). Differences are normal for a minute after a change (the next push fixes them); persistent ones point to a failing connection.'];
    }

    protected function r_credit_exposure($from, $to): array
    {
        $rows = \App\Services\CreditService::positions()->filter(fn ($p) => $p['company']->is_active || $p['outstanding'] != 0)->sortByDesc('exposure')
            ->map(fn ($p) => ['co' => $p['company']->name, 'type' => ucwords(str_replace('_', ' ', $p['company']->type)),
                'terms' => $p['company']->direct_billing ? $p['company']->credit_days.' days' : 'Pay direct', 'limit' => $p['limit'], 'out' => $p['outstanding'], 'od' => $p['overdue'],
                'odd' => $p['overdue_days'] ?: null, 'pend' => $p['pending'], 'exp' => $p['exposure'], 'avail' => $p['available'], 'used' => $p['used_pct'],
                'status' => $p['company']->onHold() ? 'HOLD'.($p['company']->credit_hold_auto ? ' (auto)' : '') : ($p['over_limit'] ? 'Over limit' : ($p['company']->direct_billing ? 'Open' : 'No credit'))])->values();

        return ['cols' => ['co' => 'Account', 'type' => 'Type', 'terms' => 'Terms', 'limit' => 'Credit limit', 'out' => 'City ledger', 'od' => 'Overdue', 'odd' => 'Oldest overdue (days)',
            'pend' => 'In-house', 'exp' => 'Exposure', 'avail' => 'Available', 'used' => 'Limit used %', 'status' => 'Status'],
            'rows' => $rows, 'money' => ['limit', 'out', 'od', 'pend', 'exp', 'avail'], 'sum' => ['out', 'od', 'pend', 'exp'],
            'note' => 'As on business date '.bdate()->format('d M Y').'. Receipts are applied to the oldest bills first; a bill is overdue once its credit days have passed. Exposure = city ledger + balances of open folios billed to the account.'];
    }

    protected function r_crm_campaigns($from, $to): array
    {
        $f = Carbon::parse($from);
        $t = Carbon::parse($to);
        $row = fn ($name, $kind, $ch, $st) => ['name' => $name, 'kind' => $kind, 'ch' => \App\Services\CrmService::CHANNELS[$ch] ?? $ch, 'rcpt' => $st['recipients'], 'sent' => $st['sent'],
            'skip' => $st['skipped'], 'fail' => $st['failed'], 'clicks' => $st['clicks'], 'ctr' => $st['ctr'], 'unsub' => $st['unsubscribes'],
            'book' => $st['bookings'], 'nights' => $st['nights'], 'rev' => $st['revenue'], 'disc' => $st['discount']];
        $rows = \App\Models\MarketingCampaign::whereIn('status', ['sent', 'sending'])->whereBetween('sent_at', [$f->copy()->startOfDay(), $t->copy()->endOfDay()])->orderBy('sent_at')->get()
            ->map(fn ($c) => $row($c->name, 'Campaign · '.$c->sent_at?->format('d M Y'), $c->channel, \App\Services\CrmService::stats($c)))
            ->concat(\App\Models\MarketingJourney::orderBy('name')->get()->map(fn ($j) => $row($j->name, 'Automation · '.$j->triggerLabel(), $j->channel, \App\Services\CrmService::stats($j, $f, $t)))
                ->filter(fn ($r) => $r['rcpt'] > 0))->values();

        return ['cols' => ['name' => 'Campaign / automation', 'kind' => 'Type', 'ch' => 'Channel', 'rcpt' => 'Recipients', 'sent' => 'Delivered', 'skip' => 'Skipped', 'fail' => 'Failed',
            'clicks' => 'Clicks', 'ctr' => 'CTR %', 'unsub' => 'Unsubs', 'book' => 'Bookings', 'nights' => 'Room nights', 'rev' => 'Room revenue', 'disc' => 'Promo discount'],
            'rows' => $rows, 'money' => ['rev', 'disc'], 'sum' => ['rcpt', 'sent', 'skip', 'fail', 'clicks', 'unsub', 'book', 'nights', 'rev', 'disc'],
            'note' => 'Campaigns sent in the period; automations count messages sent in the period. Bookings are credited when made within '.setting('crm_attribution_days', '30').' days of a message, through its link, or with its promo code; cancelled bookings are excluded. Revenue = room rate × nights before tax.'];
    }

    protected function r_crm_promos($from, $to): array
    {
        $res = Reservation::with('rooms')->whereNotNull('promo_code_id')->whereBetween('created_at', [Carbon::parse($from)->startOfDay(), Carbon::parse($to)->endOfDay()])->get()->groupBy('promo_code_id');
        $rows = \App\Models\PromoCode::orderBy('code')->get()->map(function ($p) use ($res) {
            $all = $res->get($p->id, collect());
            $live = $all->where('status', '!=', 'cancelled');
            $nights = $live->sum(fn ($r) => $r->rooms->sum(fn ($x) => $x->arrival_date->diffInDays($x->departure_date)));

            return ['code' => $p->code, 'name' => $p->name, 'offer' => $p->offerLabel(), 'status' => $p->is_active ? 'Active' : 'Inactive', 'book' => $live->count(), 'cxl' => $all->count() - $live->count(),
                'nights' => $nights, 'rev' => round($live->sum(fn ($r) => $r->rooms->sum(fn ($x) => (float) $x->rate * $x->arrival_date->diffInDays($x->departure_date))), 2),
                'disc' => round((float) $live->sum('promo_discount'), 2), 'uses' => $p->usesCount().($p->max_uses ? ' / '.$p->max_uses : '')];
        })->filter(fn ($r) => $r['book'] + $r['cxl'] > 0 || $r['status'] === 'Active')->values();

        return ['cols' => ['code' => 'Code', 'name' => 'Offer name', 'offer' => 'Discount', 'status' => 'Status', 'book' => 'Bookings', 'cxl' => 'Cancelled', 'nights' => 'Room nights',
            'rev' => 'Revenue (after discount)', 'disc' => 'Discount given', 'uses' => 'Uses (all time)'], 'rows' => $rows, 'money' => ['rev', 'disc'], 'sum' => ['book', 'cxl', 'nights', 'rev', 'disc']];
    }

    protected function r_crm_guest_value($from, $to): array
    {
        $guests = Guest::where('stays_count', '>', 0)->orderByDesc('total_revenue')->get();
        $rows = $guests->map(function ($g) {
            $s = \App\Services\CrmService::rfm($g);

            return ['guest' => $g->full_name, 'country' => $g->country, 'stays' => $g->stays_count, 'nights' => $g->nights_count, 'rev' => (float) $g->total_revenue,
                'last' => $g->last_stay_on?->format('d-m-Y'), 'days' => $g->last_stay_on ? (int) $g->last_stay_on->diffInDays(bdate()) : null,
                'rfm' => $s['r'].'-'.$s['f'].'-'.$s['m'], 'seg' => $s['label'],
                'consent' => collect(['email' => 'E', 'sms' => 'S', 'whatsapp' => 'W'])->filter(fn ($l, $ch) => $g->{$ch.'_opt_in'})->implode('') ?: '—',
                'tags' => $g->tags];
        });

        return ['cols' => ['guest' => 'Guest', 'country' => 'Country', 'stays' => 'Stays', 'nights' => 'Nights', 'rev' => 'Revenue', 'last' => 'Last stay', 'days' => 'Days since',
            'rfm' => 'R-F-M', 'seg' => 'Segment', 'consent' => 'Consent', 'tags' => 'Tags'], 'rows' => $rows, 'money' => ['rev'], 'sum' => ['stays', 'nights', 'rev'],
            'note' => 'Scores 1–5. Recency: ≤90 / 180 / 365 / 730 days. Frequency: 1, 2, 3–4, 5–9, 10+ stays. Monetary: net revenue ≥ 25k / 75k / 150k / 400k. Consent: E = e-mail, S = SMS, W = WhatsApp. Statistics refresh at the night audit.'];
    }

    protected function r_purchases($from, $to): array
    {
        $rows = PurchaseOrder::with('supplier', 'store')->whereDate('po_date', '>=', $from)->whereDate('po_date', '<=', $to)->orderBy('po_date')->get()
            ->map(fn ($p) => ['po' => $p->po_no, 'date' => $p->po_date->format('d-m-Y'), 'supplier' => $p->supplier->name, 'store' => $p->store->name, 'inv' => $p->invoice_no, 'sub' => (float) $p->subtotal, 'tax' => (float) $p->tax, 'total' => (float) $p->total, 'status' => $p->status]);

        return ['cols' => ['po' => 'PO No', 'date' => 'Date', 'supplier' => 'Supplier', 'store' => 'Store', 'inv' => 'Supplier inv.', 'sub' => 'Subtotal', 'tax' => 'Tax', 'total' => 'Total', 'status' => 'Status'], 'rows' => $rows, 'money' => ['sub', 'tax', 'total'], 'sum' => ['sub', 'tax', 'total']];
    }

    /** v4.8 */
    protected function r_goods_received($from, $to): array
    {
        $rows = collect();
        foreach (\App\Models\GoodsReceipt::with('lines.item', 'order', 'supplier')->whereBetween('date', [$from->toDateString(), $to->toDateString()])->orderBy('date')->orderBy('id')->get() as $g) {
            foreach ($g->lines as $l) {
                $rows->push(['date' => $g->date->format('d-m-Y'), 'grn' => $g->grn_no, 'po' => $g->order?->po_no, 'supplier' => $g->supplier->name, 'item' => $l->item->name, 'unit' => $l->item->unit,
                    'del' => (float) $l->qty_delivered, 'rej' => (float) $l->qty_rejected ?: null, 'reason' => $l->reject_reason, 'acc' => (float) $l->qty_accepted, 'rate' => (float) $l->rate,
                    'value' => (float) $l->amount, 'late' => $g->onTime() ? '' : 'late']);
            }
        }
        foreach (PurchaseOrder::with('items.item', 'supplier')->where('status', 'received')->whereDoesntHave('receipts')
            ->whereBetween('received_at', [$from->toDateString().' 00:00:00', $to->toDateString().' 23:59:59'])->get() as $po) {
            foreach ($po->items as $l) {
                $rows->push(['date' => $po->received_at->format('d-m-Y'), 'grn' => '(single-step)', 'po' => $po->po_no, 'supplier' => $po->supplier->name, 'item' => $l->item->name, 'unit' => $l->item->unit,
                    'del' => (float) $l->qty, 'rej' => null, 'reason' => null, 'acc' => (float) $l->qty, 'rate' => (float) $l->rate, 'value' => (float) $l->amount,
                    'late' => $po->expected_date && $po->received_at->toDateString() > $po->expected_date->toDateString() ? 'late' : '']);
            }
        }
        $rows = $rows->sortBy(fn ($r) => \Carbon\Carbon::createFromFormat('d-m-Y', $r['date'])->format('Ymd').$r['grn'])->values();
        $rejQ = $rows->sum('rej');

        return ['cols' => ['date' => 'Date', 'grn' => 'GRN', 'po' => 'PO', 'supplier' => 'Supplier', 'item' => 'Item', 'unit' => 'Unit', 'del' => 'Delivered', 'rej' => 'Rejected', 'reason' => 'Reject reason',
            'acc' => 'Accepted', 'rate' => 'Rate', 'value' => 'Accepted value', 'late' => 'Late'], 'rows' => $rows, 'money' => ['rate', 'value'], 'sum' => ['value'],
            'note' => $rows->pluck('grn')->unique()->count().' receipt(s)'.($rejQ ? ', '.$rows->where('rej', '>', 0)->count().' line(s) with rejections' : ', no rejections').'. Values before tax at the PO rate.'];
    }

    protected function r_open_purchase_orders($d): array
    {
        $on = $d->toDateString();
        $rows = collect();
        foreach (PurchaseOrder::with('items.item', 'supplier')->whereIn('status', ['approved', 'partial'])->whereDate('po_date', '<=', $on)->orderBy('expected_date')->get() as $po) {
            foreach ($po->items as $l) {
                if ($l->outstanding() <= 0) {
                    continue;
                }
                $late = $po->expected_date && $po->expected_date->toDateString() < $on ? (int) $po->expected_date->diffInDays($d) : null;
                $rows->push(['po' => $po->po_no, 'date' => $po->po_date->format('d-m-Y'), 'supplier' => $po->supplier->name, 'item' => $l->item->name, 'ordered' => (float) $l->qty,
                    'received' => (float) $l->received_qty ?: null, 'out' => $l->outstanding(), 'value' => round($l->outstanding() * (float) $l->rate, 2),
                    'expected' => $po->expected_date?->format('d-m-Y') ?? '—', 'late' => $late, 'status' => $po->status]);
            }
        }

        return ['cols' => ['po' => 'PO', 'date' => 'PO date', 'supplier' => 'Supplier', 'item' => 'Item', 'ordered' => 'Ordered', 'received' => 'Received', 'out' => 'Outstanding', 'value' => 'Outstanding value',
            'expected' => 'Expected', 'late' => 'Days overdue', 'status' => 'Status'], 'rows' => $rows, 'money' => ['value'], 'sum' => ['value'],
            'note' => $rows->pluck('po')->unique()->count().' open PO(s); '.$rows->whereNotNull('late')->pluck('po')->unique()->count().' overdue. Current position – receipts after the date are not backed out.'];
    }

    protected function r_supplier_scorecard($from, $to): array
    {
        $rows = \App\Services\ProcurementService::scorecard($from->toDateString(), $to->toDateString())->map(fn ($r) => ['supplier' => $r['supplier'], 'orders' => $r['orders'], 'value' => $r['value'],
            'deliveries' => $r['deliveries'], 'on_time' => $r['on_time'], 'fill' => $r['fill'], 'quality' => $r['quality'], 'price' => $r['price'], 'response' => $r['response'],
            'lead' => $r['lead_days'], 'score' => $r['score'], 'grade' => $r['grade']]);

        return ['cols' => ['supplier' => 'Supplier', 'orders' => 'POs', 'value' => 'PO value', 'deliveries' => 'Deliveries', 'on_time' => 'On time %', 'fill' => 'Fill rate %', 'quality' => 'Quality %',
            'price' => 'Price compliance %', 'response' => 'RFQ response %', 'lead' => 'Avg lead (days)', 'score' => 'Score', 'grade' => 'Grade'], 'rows' => $rows, 'money' => ['value'], 'sum' => ['orders', 'value', 'deliveries'],
            'note' => 'Score weights: on-time 35, fill rate 25, quality 25, price compliance 15 – averaged over the measures available. Grades A ≥ 90, B ≥ 75, C ≥ 60, D below.'];
    }

    protected function r_consumption($from, $to): array
    {
        $rows = StockMovement::with('item')->whereIn('type', ['issue', 'wastage', 'consumption'])->whereBetween('date', [$from, $to])->get()
            ->groupBy(fn ($m) => $m->item->name.'|'.($m->department ?: '—'))
            ->map(function ($g, $k) {
                [$i, $d] = explode('|', $k, 2);

                return ['item' => $i, 'unit' => $g->first()->item->unit, 'dept' => $d, 'issued' => abs($g->whereIn('type', ['issue', 'consumption'])->sum('qty')), 'wastage' => abs($g->where('type', 'wastage')->sum('qty')), 'value' => abs($g->sum(fn ($m) => $m->qty * $m->rate))];
            })->sortByDesc('value')->values();

        return ['cols' => ['item' => 'Item', 'unit' => 'Unit', 'dept' => 'Department', 'issued' => 'Issued', 'wastage' => 'Wastage', 'value' => 'Value'], 'rows' => $rows, 'money' => ['value'], 'sum' => ['value']];
    }

    protected function r_expenses($from, $to): array
    {
        $rows = Voucher::with('head', 'paymentMode', 'supplier')->where('type', 'payment')->whereBetween('date', [$from, $to])->orderBy('date')->get()
            ->map(fn ($v) => ['no' => $v->voucher_no, 'date' => $v->date->format('d-m-Y'), 'head' => $v->head->name, 'party' => $v->supplier?->name ?? $v->party_name, 'mode' => $v->paymentMode?->name, 'narr' => $v->narration, 'amount' => (float) $v->amount]);

        return ['cols' => ['no' => 'Voucher', 'date' => 'Date', 'head' => 'Head', 'party' => 'Party', 'mode' => 'Mode', 'narr' => 'Narration', 'amount' => 'Amount'], 'rows' => $rows, 'money' => ['amount'], 'sum' => ['amount'], 'summary' => $rows->groupBy('head')->map->sum('amount')];
    }

    protected function r_mis($from, $to): array
    {
        $rows = NightAudit::whereDate('business_date', '>=', $from)->whereDate('business_date', '<=', $to)->orderBy('business_date')->get()
            ->map(fn ($a) => ['date' => $a->business_date->format('d-m-Y'), 'sold' => $a->rooms_occupied.'/'.$a->rooms_total, 'occ' => (float) $a->occupancy_pct, 'arr' => $a->arrivals, 'dep' => $a->departures, 'ns' => $a->no_shows,
                'room' => (float) $a->room_revenue, 'other' => (float) $a->other_revenue, 'tax' => (float) $a->tax_collected, 'coll' => (float) $a->collections, 'adr' => (float) $a->adr, 'revpar' => (float) $a->revpar]);
        $m = ['room', 'other', 'tax', 'coll', 'adr', 'revpar'];

        return ['cols' => ['date' => 'Date', 'sold' => 'Sold/Total', 'occ' => 'Occ %', 'arr' => 'Arr', 'dep' => 'Dep', 'ns' => 'No-show', 'room' => 'Room rev.', 'other' => 'Other rev.', 'tax' => 'Tax', 'coll' => 'Collections', 'adr' => 'ADR', 'revpar' => 'RevPAR'],
            'rows' => $rows, 'money' => $m, 'sum' => ['arr', 'dep', 'ns', 'room', 'other', 'tax', 'coll'], 'chart' => ['label' => 'Occupancy %', 'key' => 'occ', 'x' => 'date']];
    }

    protected function r_guest_history($from, $to): array
    {
        $rows = Guest::withCount(['reservations as stays' => fn ($q) => $q->whereIn('status', ['checked_in', 'checked_out', 'partially_checked_in'])->whereDate('arrival_date', '>=', $from)->whereDate('arrival_date', '<=', $to)])
            ->with(['folios' => fn ($q) => $q->where('status', 'closed')->whereBetween('closed_at', [$from, $to->copy()->endOfDay()])])->get()
            ->filter(fn ($g) => $g->stays > 0)
            ->map(fn ($g) => ['guest' => $g->full_name, 'phone' => $g->phone, 'city' => $g->city, 'vip' => $g->is_vip ? 'VIP' : '', 'stays' => $g->stays, 'revenue' => $g->folios->sum(fn ($f) => $f->totals()['charges'] - $f->totals()['discount']), 'points' => $g->loyalty_points])
            ->sortByDesc('revenue')->take(100)->values();

        return ['cols' => ['guest' => 'Guest', 'phone' => 'Phone', 'city' => 'City', 'vip' => 'VIP', 'stays' => 'Stays', 'revenue' => 'Billed', 'points' => 'Loyalty pts'], 'rows' => $rows, 'money' => ['revenue'], 'sum' => ['stays', 'revenue']];
    }

    protected function r_user_activity($from, $to): array
    {
        $users = User::pluck('name', 'id');
        $rows = ActivityLog::whereBetween('created_at', [$from, $to->copy()->endOfDay()])->get(['user_id', 'module'])->groupBy(fn ($l) => $l->user_id.'|'.$l->module)
            ->map(function ($g, $k) use ($users) {
                [$u, $m] = explode('|', $k);

                return ['user' => $users[$u] ?? 'System', 'module' => $m, 'actions' => $g->count()];
            })->sortByDesc('actions')->values();

        return ['cols' => ['user' => 'User', 'module' => 'Module', 'actions' => 'Actions'], 'rows' => $rows, 'sum' => ['actions']];
    }

    // ------------------------------------------------------------------ v3.1 feedback & reputation

    protected function r_nps_trend($from, $to): array
    {
        $f = Carbon::parse($from)->startOfMonth();
        $t = Carbon::parse($to);
        $rows = collect();
        for ($m = $f->copy(); $m->lte($t); $m->addMonth()) {
            $a = $m->copy()->max(Carbon::parse($from));
            $b = $m->copy()->endOfMonth()->min($t);
            $s = \App\Services\FeedbackService::stats($a, $b);
            $rows->push(['m' => $m->format('M Y'), 'inv' => $s['invited'], 'resp' => $s['responses'], 'rate' => $s['response_rate'] !== null ? $s['response_rate'].'%' : '',
                'pro' => $s['nps']['promoters'], 'pas' => $s['nps']['passives'], 'det' => $s['nps']['detractors'], 'nps' => $s['nps']['nps'] ?? '', 'npsv' => (int) ($s['nps']['nps'] ?? 0),
                'csat' => $s['csat'] ?? '', 'neg' => $s['sentiment']['negative'], 'rev' => $s['reviews'], 'ravg' => $s['review_avg'] ?? '',
                'rrr' => $s['review_response_rate'] !== null ? $s['review_response_rate'].'%' : '', 'rh' => $s['review_response_hours'] ?? '']);
        }
        $all = \App\Services\FeedbackService::stats(Carbon::parse($from), $t);

        return ['cols' => ['m' => 'Month', 'inv' => 'Surveys sent', 'resp' => 'Responses', 'rate' => 'Response rate', 'pro' => 'Promoters', 'pas' => 'Passives', 'det' => 'Detractors',
            'nps' => 'NPS', 'csat' => 'Avg overall (1-5)', 'neg' => 'Negative comments', 'rev' => 'Online reviews', 'ravg' => 'Online rating (/5)', 'rrr' => 'Reviews answered', 'rh' => 'Avg hours to answer'],
            'rows' => $rows, 'sum' => ['inv', 'resp', 'pro', 'pas', 'det', 'neg', 'rev'],
            'summary' => ['NPS' => $all['nps']['nps'] === null ? '–' : (string) $all['nps']['nps'], 'Responses' => (string) $all['responses'],
                'Avg overall' => $all['csat'] ? $all['csat'].' / 5' : '–', 'Online rating' => $all['review_avg'] ? $all['review_avg'].' / 5' : '–'],
            'chart' => $rows->count() > 1 ? ['key' => 'resp', 'x' => 'm', 'label' => 'Responses per month'] : null,
            'note' => 'NPS = % promoters (9-10) − % detractors (0-6) among responses with a recommend score. Responses include post-stay surveys, the guest portal and the public feedback form. Online reviews by review date, ratings normalised to 5; "answered" excludes reviews marked "no response".'];
    }

    protected function r_feedback_register($from, $to): array
    {
        $rows = \App\Models\GuestFeedback::with('recoveryCase', 'stay.room', 'reservation')->whereBetween('created_at', [Carbon::parse($from)->startOfDay(), Carbon::parse($to)->endOfDay()])->orderBy('created_at')->get()
            ->map(fn ($f) => ['date' => $f->created_at->format('d M Y H:i'), 'guest' => $f->guest_name ?: 'Anonymous', 'res' => $f->reservation?->reservation_no, 'room' => $f->stay?->room?->room_no,
                'src' => \App\Models\GuestFeedback::SOURCES[$f->source] ?? $f->source, 'nps' => $f->nps ?? '', 'grp' => $f->nps_group ? ucfirst($f->nps_group) : '', 'overall' => $f->rating_overall,
                'sent' => ucfirst((string) $f->sentiment), 'neg' => collect((array) $f->aspects)->filter(fn ($s) => in_array($s, ['negative', 'mixed']))->keys()->map(fn ($d) => \App\Services\FeedbackService::DEPTS[$d] ?? $d)->implode(', '),
                'comments' => \Illuminate\Support\Str::limit((string) $f->comments, 200), 'case' => $f->recoveryCase?->case_no, 'status' => $f->status === 'new' ? 'Unread' : 'Read',
                '_class' => $f->nps_group === 'detractor' || $f->sentiment === 'negative' ? 'row-alert' : ''])->values();

        return ['cols' => ['date' => 'Received', 'guest' => 'Guest', 'res' => 'Reservation', 'room' => 'Room', 'src' => 'Source', 'nps' => 'Recommend (0-10)', 'grp' => 'NPS group', 'overall' => 'Overall (1-5)',
            'sent' => 'Sentiment', 'neg' => 'Complaints about', 'comments' => 'Comments', 'case' => 'Case', 'status' => 'Status'], 'rows' => $rows, 'sum' => [],
            'note' => 'Sentiment and complaint areas come from the ratings and a keyword analysis of the comments. Detractors and negative responses are highlighted.'];
    }

    protected function r_feedback_cases($from, $to): array
    {
        $h = fn ($a, $b) => $a && $b ? round($a->diffInMinutes($b) / 60, 1) : '';
        $rows = \App\Models\FeedbackCase::with('assignee', 'guest')->whereBetween('created_at', [Carbon::parse($from)->startOfDay(), Carbon::parse($to)->endOfDay()])->orderBy('created_at')->get()
            ->map(function ($c) use ($h) {
                $done = $c->resolved_at ?? $c->closed_at;
                $onTime = $done ? ($done->lte($c->due_at) ? 'Yes' : 'Late') : ($c->due_at->isPast() ? 'Overdue' : 'Open');

                return ['no' => $c->case_no, 'opened' => $c->created_at->format('d M Y H:i'), 'src' => ucfirst($c->source), 'dept' => \App\Services\FeedbackService::DEPTS[$c->department] ?? $c->department,
                    'sev' => ucfirst($c->severity), 'guest' => $c->guest?->full_name, 'title' => \Illuminate\Support\Str::limit($c->title, 90), 'owner' => $c->assignee?->name ?? 'Unassigned',
                    'status' => \App\Models\FeedbackCase::STATUSES[$c->status], 'first' => $h($c->created_at, $c->first_response_at), 'res' => $h($c->created_at, $done), 'sla' => $onTime,
                    'action' => \App\Models\FeedbackCase::ACTIONS[$c->recovery_action] ?? '', 'pts' => (int) $c->goodwill_points, 'outcome' => \App\Models\FeedbackCase::OUTCOMES[$c->outcome] ?? '',
                    '_class' => in_array($onTime, ['Late', 'Overdue']) ? 'row-alert' : ''];
            })->values();
        $closed = $rows->whereNotIn('outcome', ['']);
        $rec = $closed->where('outcome', \App\Models\FeedbackCase::OUTCOMES['recovered'])->count();
        $done = $rows->whereIn('sla', ['Yes', 'Late']);

        return ['cols' => ['no' => 'Case', 'opened' => 'Opened', 'src' => 'From', 'dept' => 'Department', 'sev' => 'Severity', 'guest' => 'Guest', 'title' => 'Subject', 'owner' => 'Owner',
            'status' => 'Status', 'first' => 'Hours to first contact', 'res' => 'Hours to resolve', 'sla' => 'Within SLA', 'action' => 'Recovery action', 'pts' => 'Goodwill points', 'outcome' => 'Outcome'],
            'rows' => $rows, 'sum' => ['pts'],
            'summary' => ['Cases' => (string) $rows->count(), 'Resolved within SLA' => $done->count() ? round($done->where('sla', 'Yes')->count() / $done->count() * 100).'%' : '–',
                'Recovery rate' => $closed->count() ? round($rec / $closed->count() * 100).'%' : '–', 'Still open' => (string) $rows->whereIn('sla', ['Open', 'Overdue'])->count()],
            'note' => 'Cases opened in the period. SLA hours by severity (and a shorter target while the guest is in the hotel) are set in Feedback settings. Recovery rate = closed cases where the guest was satisfied.'];
    }

    protected function r_online_reviews($from, $to): array
    {
        $rows = \App\Models\OnlineReview::with('guest', 'poster', 'recoveryCase')->whereDate('review_date', '>=', Carbon::parse($from)->toDateString())->whereDate('review_date', '<=', Carbon::parse($to)->toDateString())->orderBy('review_date')->get()
            ->map(fn ($r) => ['date' => $r->review_date->format('d M Y'), 'site' => $r->sourceName(), 'rev' => $r->reviewer_name, 'rating' => rtrim(rtrim(number_format((float) $r->rating, 1), '0'), '.').' / '.$r->rating_scale,
                'r5' => (float) $r->rating5, 'title' => \Illuminate\Support\Str::limit((string) ($r->title ?: $r->body), 80), 'sent' => ucfirst((string) $r->sentiment), 'guest' => $r->guest?->full_name,
                'status' => \App\Models\OnlineReview::STATUSES[$r->status], 'by' => $r->poster?->name, 'hrs' => $r->responseHours() ?? '', 'case' => $r->recoveryCase?->case_no,
                '_class' => (float) $r->rating5 <= 2.5 ? 'row-alert' : ''])->values();
        $answerable = $rows->where('status', '!=', \App\Models\OnlineReview::STATUSES['ignored']);

        return ['cols' => ['date' => 'Review date', 'site' => 'Site', 'rev' => 'Reviewer', 'rating' => 'Rating', 'r5' => 'Out of 5', 'title' => 'Review', 'sent' => 'Sentiment', 'guest' => 'Matched guest',
            'status' => 'Response', 'by' => 'Posted by', 'hrs' => 'Hours to respond', 'case' => 'Case'], 'rows' => $rows, 'sum' => [],
            'summary' => ['Reviews' => (string) $rows->count(), 'Average' => $rows->count() ? round($rows->avg('r5'), 2).' / 5' : '–',
                'Responded' => $answerable->count() ? round($answerable->where('status', \App\Models\OnlineReview::STATUSES['posted'])->count() / $answerable->count() * 100).'%' : '–',
                'Critical (≤ 2.5)' => (string) $rows->where('r5', '<=', 2.5)->count()],
            'note' => 'Reviews imported or entered for the period (by review date). Booking.com-style ratings out of 10 are halved for the 5-point average. Hours to respond run from when the review reached the inbox to when the response was marked as posted.'];
    }

    protected function r_dept_scores($from, $to): array
    {
        $a = Carbon::parse($from)->startOfDay();
        $b = Carbon::parse($to)->endOfDay();
        $s = \App\Services\FeedbackService::stats($a, $b);
        $cases = \App\Models\FeedbackCase::whereBetween('created_at', [$a, $b])->get();
        $rows = collect($s['departments'])->map(function ($d, $k) use ($cases) {
            $c = $cases->where('department', $k);
            $res = $c->filter(fn ($x) => $x->resolved_at)->map(fn ($x) => $x->created_at->diffInMinutes($x->resolved_at) / 60);

            return ['dept' => $d['label'], 'avg' => $d['avg'] ?? '', 'n' => $d['n'], 'pos' => $d['pos'], 'neg' => $d['neg'],
                'net' => $d['pos'] + $d['neg'] ? round(($d['pos'] - $d['neg']) / ($d['pos'] + $d['neg']) * 100) : '', 'cases' => $c->count(),
                'high' => $c->where('severity', 'high')->count(), 'late' => $c->filter(fn ($x) => ($x->resolved_at ?? now())->gt($x->due_at))->count(),
                'hrs' => $res->count() ? round($res->avg(), 1) : '', '_class' => $d['avg'] !== null && $d['avg'] < 3.5 || $d['neg'] > $d['pos'] ? 'row-alert' : ''];
        })->filter(fn ($r) => $r['n'] || $r['pos'] || $r['neg'] || $r['cases'])->sortBy(fn ($r) => $r['avg'] === '' ? 9 : $r['avg'])->values();

        return ['cols' => ['dept' => 'Department', 'avg' => 'Avg rating (1-5)', 'n' => 'Ratings', 'pos' => 'Positive mentions', 'neg' => 'Negative mentions', 'net' => 'Net sentiment %',
            'cases' => 'Cases', 'high' => 'High severity', 'late' => 'Past SLA', 'hrs' => 'Avg hours to resolve'], 'rows' => $rows, 'sum' => ['n', 'pos', 'neg', 'cases', 'high', 'late'],
            'note' => 'Ratings come from survey questions linked to a department (and the legacy feedback form); mentions from the keyword analysis of survey comments and online reviews. Weakest departments first; rows under 3.5★ or with more negative than positive mentions are highlighted.'];
    }

    // ------------------------------------------------------------------ v3.2 table reservations

    protected function tbRows($from, $to)
    {
        return \App\Models\TableReservation::with('outlet', 'tables', 'order')->whereBetween('date', [Carbon::parse($from)->toDateString(), Carbon::parse($to)->toDateString()])
            ->orderBy('start_at')->get();
    }

    protected function r_table_bookings($from, $to): array
    {
        $T = \App\Models\TableReservation::class;
        $rows = $this->tbRows($from, $to)->map(function ($r) use ($T) {
            $late = $r->type === 'booking' && $r->seated_at ? (int) round($r->start_at->diffInMinutes($r->seated_at, false)) : null;

            return ['when' => $r->start_at->format('d M Y H:i'), 'ref' => $r->booking_no, 'outlet' => $r->outlet?->name, 'guest' => $r->name, 'pax' => (int) $r->party_size,
                'table' => $r->tableLabel() === '—' ? '' : $r->tableLabel(), 'type' => ['booking' => 'Booking', 'walkin' => 'Walk-in', 'waitlist' => 'Waitlist'][$r->type] ?? $r->type,
                'src' => $T::SOURCES[$r->source] ?? $r->source, 'occ' => $T::OCCASIONS[$r->occasion] ?? '', 'status' => ucwords(str_replace('_', ' ', $r->status)),
                'late' => $late === null ? '' : max(0, $late), 'dined' => $r->dinedMinutes() ?? '', 'bill' => $r->order && $r->order->status !== 'void' ? (float) $r->order->total : 0,
                '_class' => $r->status === 'no_show' ? 'row-alert' : ''];
        })->values();
        $bk = $rows->where('type', 'Booking');
        $due = $bk->whereIn('status', ['Completed', 'Seated', 'No Show']);

        return ['cols' => ['when' => 'Date & time', 'ref' => 'Ref', 'outlet' => 'Outlet', 'guest' => 'Guest', 'pax' => 'Pax', 'table' => 'Table', 'type' => 'Type', 'src' => 'Source',
            'occ' => 'Occasion', 'status' => 'Status', 'late' => 'Min late', 'dined' => 'Min at table', 'bill' => 'Bill'],
            'rows' => $rows, 'money' => ['bill'], 'sum' => ['pax', 'bill'],
            'summary' => ['Parties' => (string) $rows->count(), 'Covers seated' => (string) $rows->whereIn('status', ['Completed', 'Seated'])->sum('pax'),
                'No-show rate' => $due->count() ? round($due->where('status', 'No Show')->count() / $due->count() * 100, 1).'%' : '–',
                'Avg time at table' => $rows->where('dined', '!==', '')->count() ? round($rows->where('dined', '!==', '')->avg('dined')).' min' : '–'],
            'note' => 'By booking date. "Min late" = minutes between the booked time and seating; "Min at table" = seating to bill settlement. No-show rate = no-shows ÷ bookings that were due (cancellations excluded). Bill = the POS order opened when the party was seated.'];
    }

    protected function r_table_covers($from, $to): array
    {
        $all = $this->tbRows($from, $to);
        $seated = fn ($c) => $c->whereIn('status', ['seated', 'completed']);
        $rows = $all->groupBy(fn ($r) => $r->date->toDateString())->map(function ($d, $day) use ($seated) {
            $bk = $d->where('type', 'booking');
            $due = $bk->whereIn('status', ['seated', 'completed', 'no_show']);
            $ns = $bk->where('status', 'no_show');
            $cv = $seated($d)->sum('party_size');

            return ['day' => Carbon::parse($day)->format('D d M Y'), 'bk' => $bk->count(), 'bkc' => (int) $seated($bk)->sum('party_size'), 'wic' => (int) $seated($d->where('type', 'walkin'))->sum('party_size'),
                'wlc' => (int) $seated($d->where('type', 'waitlist'))->sum('party_size'), 'cov' => (int) $cv, 'ns' => $ns->count(), 'nsc' => (int) $ns->sum('party_size'),
                'cx' => $bk->where('status', 'cancelled')->count(), 'nsr' => $due->count() ? round($ns->count() / $due->count() * 100, 1) : '',
                'onl' => $bk->count() ? round($bk->where('source', 'online')->count() / $bk->count() * 100) : '', '_class' => $due->count() && $ns->count() / $due->count() > .15 ? 'row-alert' : ''];
        })->values();
        $bk = $all->where('type', 'booking');
        $due = $bk->whereIn('status', ['seated', 'completed', 'no_show']);

        return ['cols' => ['day' => 'Day', 'bk' => 'Bookings', 'bkc' => 'Booked covers seated', 'wic' => 'Walk-in covers', 'wlc' => 'Waitlist covers', 'cov' => 'Total covers',
            'ns' => 'No-shows', 'nsc' => 'No-show covers', 'cx' => 'Cancelled', 'nsr' => 'No-show %', 'onl' => 'Online bookings %'],
            'rows' => $rows, 'sum' => ['bk', 'bkc', 'wic', 'wlc', 'cov', 'ns', 'nsc', 'cx'],
            'summary' => ['Covers' => (string) $rows->sum('cov'), 'Avg covers / day' => $rows->count() ? (string) round($rows->avg('cov'), 1) : '–',
                'No-show rate' => $due->count() ? round($due->where('status', 'no_show')->count() / $due->count() * 100, 1).'%' : '–',
                'Covers lost to no-shows' => (string) $rows->sum('nsc')],
            'note' => 'Covers are counted when the party is seated. No-show % = no-shows ÷ bookings that were due; days above 15% are highlighted. Online % = share of bookings made on the public booking page.'];
    }

    protected function r_table_turns($from, $to): array
    {
        $all = $this->tbRows($from, $to)->whereIn('status', ['seated', 'completed']);
        $days = max(1, Carbon::parse($from)->diffInDays(Carbon::parse($to)) + 1);
        $by = [];
        foreach ($all as $r) {
            $share = max(1, $r->tables->count());
            foreach ($r->tables as $t) {
                $by[$t->id]['t'] = $t;
                $by[$t->id]['outlet'] = $r->outlet?->name;
                $by[$t->id]['p'][] = $r;
                $by[$t->id]['cov'] = ($by[$t->id]['cov'] ?? 0) + $r->party_size / $share;
                $by[$t->id]['sales'] = ($by[$t->id]['sales'] ?? 0) + ($r->order && $r->order->status !== 'void' ? (float) $r->order->total / $share : 0);
            }
        }
        $rows = collect($by)->map(function ($x) use ($days) {
            $p = collect($x['p']);
            $dined = $p->map->dinedMinutes()->filter();
            $cap = $x['t']->capacity * $p->count();

            return ['outlet' => $x['outlet'], 'table' => $x['t']->table_no, 'sec' => $x['t']->section, 'seats' => (int) $x['t']->capacity, 'parties' => $p->count(), 'cov' => (int) round($x['cov']),
                'avg' => round($x['cov'] / max(1, $p->count()), 1), 'dined' => $dined->count() ? (int) round($dined->avg()) : '', 'turns' => round($p->count() / $days, 2),
                'util' => $cap ? round($x['cov'] / $cap * 100) : '', 'sales' => round($x['sales'], 2), 'per' => $x['cov'] ? round($x['sales'] / $x['cov'], 2) : 0,
                '_class' => $cap && $x['cov'] / $cap < .5 ? 'row-alert' : ''];
        })->sortBy(fn ($r) => $r['outlet'].str_pad(preg_replace('/\D/', '', $r['table']), 4, '0', STR_PAD_LEFT))->values();

        return ['cols' => ['outlet' => 'Outlet', 'table' => 'Table', 'sec' => 'Section', 'seats' => 'Seats', 'parties' => 'Parties', 'cov' => 'Covers', 'avg' => 'Avg party',
            'dined' => 'Avg min at table', 'turns' => 'Turns / day', 'util' => 'Seat utilisation %', 'sales' => 'Sales', 'per' => 'Sales / cover'],
            'rows' => $rows, 'money' => ['sales', 'per'], 'sum' => ['parties', 'cov', 'sales'],
            'summary' => ['Tables used' => (string) $rows->count(), 'Avg turns / day' => $rows->count() ? (string) round($rows->avg('turns'), 2) : '–',
                'Seat utilisation' => $rows->sum('seats') ? round($rows->sum('cov') / max(1, $rows->sum(fn ($r) => $r['seats'] * $r['parties'])) * 100).'%' : '–'],
            'note' => "Seated and completed parties over {$days} day(s). A party on joined tables counts once per table, with covers and sales shared between them. Seat utilisation = covers ÷ seats offered to the parties seated there; under 50% (a 2-top on a 6-seat table) is highlighted."];
    }

    protected function r_table_waitlist($from, $to): array
    {
        $all = $this->tbRows($from, $to)->where('type', 'waitlist');
        $rows = $all->groupBy(fn ($r) => $r->date->toDateString())->map(function ($d, $day) {
            $s = $d->whereIn('status', ['seated', 'completed']);
            $w = $s->map->waitedMinutes()->filter(fn ($v) => $v !== null);
            $within = $s->filter(fn ($r) => $r->quoted_minutes && $r->waitedMinutes() !== null && $r->waitedMinutes() <= $r->quoted_minutes)->count();
            $quoted = $s->filter(fn ($r) => $r->quoted_minutes)->count();

            return ['day' => Carbon::parse($day)->format('D d M Y'), 'n' => $d->count(), 'cov' => (int) $d->sum('party_size'), 'seated' => $s->count(), 'left' => $d->where('status', 'left')->count(),
                'waiting' => $d->whereIn('status', ['waiting', 'notified'])->count(), 'q' => $d->whereNotNull('quoted_minutes')->count() ? (int) round($d->whereNotNull('quoted_minutes')->avg('quoted_minutes')) : '',
                'w' => $w->count() ? (int) round($w->avg()) : '', 'within' => $quoted ? round($within / $quoted * 100) : '', '_class' => $d->where('status', 'left')->count() > $s->count() ? 'row-alert' : ''];
        })->values();
        $s = $all->whereIn('status', ['seated', 'completed']);
        $w = $s->map->waitedMinutes()->filter(fn ($v) => $v !== null);

        return ['cols' => ['day' => 'Day', 'n' => 'Parties', 'cov' => 'Covers', 'seated' => 'Seated', 'left' => 'Walked away', 'waiting' => 'Still waiting', 'q' => 'Avg quoted (min)',
            'w' => 'Avg actual wait (min)', 'within' => 'Seated within quote %'],
            'rows' => $rows, 'sum' => ['n', 'cov', 'seated', 'left'],
            'summary' => ['Parties' => (string) $all->count(), 'Conversion' => $all->count() ? round($s->count() / $all->count() * 100).'%' : '–',
                'Avg wait' => $w->count() ? round($w->avg()).' min' : '–', 'Walked away' => (string) $all->where('status', 'left')->count()],
            'note' => 'Waitlist parties by day. Actual wait = time added to the list until seated. Conversion = parties seated ÷ parties added. Days where more parties left than were seated are highlighted.'];
    }

    // ───────── v3.3 transport ─────────

    protected function trRows($from, $to)
    {
        return \App\Models\TransportTrip::with('vehicle', 'driver', 'stay.room')->whereBetween('scheduled_at', [Carbon::parse($from)->startOfDay(), Carbon::parse($to)->endOfDay()])
            ->orderBy('scheduled_at')->get();
    }

    // ─────────────── v3.4 foreign guests ───────────────

    // ───────────────────────── v3.5 desk operations ─────────────────────────

    protected function r_logbook_register($from, $to): array
    {
        $E = \App\Models\LogEntry::class;
        $list = $E::with('author', 'resolver', 'stay.room', 'room')->withCount('reads')->whereDate('business_date', '>=', $from)->whereDate('business_date', '<=', $to)->orderBy('business_date')->orderBy('id')->get();
        $rows = $list->map(fn ($e) => ['date' => $e->business_date->format('d M Y'), 'time' => $e->created_at->format('H:i'), 'shift' => $E::SHIFTS[$e->shift] ?? $e->shift,
            'dept' => $E::DEPARTMENTS[$e->department] ?? $e->department, 'cat' => $e->categoryLabel(), 'prio' => $E::PRIORITIES[$e->priority] ?? $e->priority,
            'title' => $e->title, 'room' => (string) ($e->stay?->room?->room_no ?? $e->room?->room_no), 'by' => $e->source === 'system' ? 'System' : (string) $e->author?->name,
            'status' => ['note' => 'Note', 'open' => 'Open', 'resolved' => 'Resolved'][$e->status] ?? $e->status,
            'hours' => $e->resolved_at ? round($e->created_at->diffInMinutes($e->resolved_at) / 60, 1) : '', 'reads' => (int) $e->reads_count,
            '_class' => $e->isOpen() && $e->priority === 'urgent' ? 'row-alert' : ''])->values();
        $res = $list->whereNotNull('resolved_at');

        return ['cols' => ['date' => 'Date', 'time' => 'Time', 'shift' => 'Shift', 'dept' => 'Department', 'cat' => 'Category', 'prio' => 'Priority', 'title' => 'Entry', 'room' => 'Room',
            'by' => 'By', 'status' => 'Status', 'hours' => 'Hours to resolve', 'reads' => 'Read by'],
            'rows' => $rows, 'money' => [], 'sum' => [],
            'summary' => ['Entries' => (string) $list->count(), 'Needing action' => (string) $list->whereIn('status', ['open', 'resolved'])->count(), 'Resolved' => (string) $res->count(),
                'Still open' => (string) $list->where('status', 'open')->count(), 'Urgent' => (string) $list->where('priority', 'urgent')->count(),
                'Avg hours to resolve' => $res->count() ? (string) round($res->avg(fn ($e) => $e->created_at->diffInMinutes($e->resolved_at)) / 60, 1) : '—',
                'Complaints / incidents' => (string) $list->whereIn('category', ['complaint', 'incident'])->count()],
            'note' => 'Complaints, incidents, maintenance and urgent entries are always open until someone records how they were resolved.'];
    }

    protected function r_wakeup_calls($from, $to): array
    {
        $W = \App\Models\WakeupCall::class;
        $list = $W::with('stay.room', 'stay.guest', 'stay.reservation.guest', 'room', 'attemptsLog', 'completer')
            ->whereBetween('call_at', [Carbon::parse($from)->startOfDay(), Carbon::parse($to)->endOfDay()])->orderBy('call_at')->get();
        $rows = $list->map(function ($w) use ($W) {
            $first = $w->attemptsLog->first();
            $late = $first ? (int) max(0, round($w->call_at->diffInMinutes($first->attempted_at, false))) : null;

            return ['time' => $w->call_at->format('d M Y H:i'), 'room' => (string) ($w->stay?->room?->room_no ?? $w->room?->room_no),
                'guest' => (string) \App\Services\GuestMessageService::stayGuest($w->stay)?->full_name, 'source' => $W::SOURCES[$w->source] ?? $w->source,
                'daily' => $w->daily ? 'Yes' : '', 'attempts' => (int) $w->attempts, 'late' => $late ?? '', 'result' => $w->statusLabel(), 'by' => (string) $w->completer?->name,
                '_class' => in_array($w->status, ['no_answer', 'missed']) || ($late ?? 0) > 5 ? 'row-alert' : ''];
        })->values();
        $made = $list->whereIn('status', ['done', 'no_answer']);
        $firsts = $list->map(fn ($w) => $w->attemptsLog->first() ? max(0, $w->call_at->diffInMinutes($w->attemptsLog->first()->attempted_at, false)) : null)->filter(fn ($x) => $x !== null);

        return ['cols' => ['time' => 'Call time', 'room' => 'Room', 'guest' => 'Guest', 'source' => 'Booked via', 'daily' => 'Daily', 'attempts' => 'Attempts', 'late' => 'Min. late', 'result' => 'Result', 'by' => 'Logged by'],
            'rows' => $rows, 'money' => [], 'sum' => ['attempts'],
            'summary' => ['Calls' => (string) $list->count(), 'Answered' => (string) $list->where('status', 'done')->count(), 'No answer' => (string) $list->where('status', 'no_answer')->count(),
                'Missed (not called)' => (string) $list->where('status', 'missed')->count(), 'Cancelled' => (string) $list->where('status', 'cancelled')->count(),
                'Made on time (≤ 5 min)' => $firsts->count() ? round($firsts->filter(fn ($m) => $m <= 5)->count() / $firsts->count() * 100).'%' : '—',
                'Via guest portal' => (string) $list->where('source', 'portal')->count()],
            'note' => 'A missed call is one nobody logged within two hours – it was escalated to the duty manager through the log book when it was '.\App\Services\FrontDeskOpsService::cfg()['wakeup_grace'].' minutes late.'];
    }

    protected function r_guest_messages($from, $to): array
    {
        $M = \App\Models\GuestMessage::class;
        $list = $M::with('stay.room', 'guest', 'taker', 'deliverer')->whereBetween('created_at', [Carbon::parse($from)->startOfDay(), Carbon::parse($to)->endOfDay()])->orderBy('id')->get();
        $rows = $list->map(fn ($m) => ['no' => $m->message_no, 'at' => $m->created_at->format('d M H:i'), 'type' => $m->isParcel() ? 'Parcel' : 'Message',
            'room' => (string) $m->stay?->room?->room_no, 'guest' => (string) $m->guest?->full_name, 'from' => $m->from_name.($m->from_company ? ', '.$m->from_company : ''),
            'channel' => $M::CHANNELS[$m->channel] ?? $m->channel, 'told' => $m->notified_at ? strtoupper((string) $m->notified_via).' '.$m->notified_at->format('H:i') : '',
            'mins' => $m->delivered_at && $m->status === 'delivered' ? (int) $m->created_at->diffInMinutes($m->delivered_at) : '', 'status' => $m->statusLabel(),
            'note' => (string) ($m->collected_by ? 'by '.$m->collected_by : ($m->delivery_note ?? $m->close_reason)), 'taken' => (string) $m->taker?->name,
            '_class' => $m->isOpen() || $m->status === 'cancelled' && $m->close_reason === 'Guest checked out before delivery' ? 'row-alert' : ''])->values();
        $del = $list->where('status', 'delivered');

        return ['cols' => ['no' => 'No.', 'at' => 'Received', 'type' => 'Type', 'room' => 'Room', 'guest' => 'Guest', 'from' => 'From', 'channel' => 'Via', 'told' => 'Guest told',
            'mins' => 'Minutes to deliver', 'status' => 'Status', 'note' => 'Delivery', 'taken' => 'Taken by'],
            'rows' => $rows, 'money' => [], 'sum' => [],
            'summary' => ['Messages' => (string) $list->where('kind', 'message')->count(), 'Parcels' => (string) $list->where('kind', 'parcel')->count(), 'Delivered' => (string) $del->count(),
                'Median minutes to deliver' => $del->count() ? (string) $del->map(fn ($m) => $m->created_at->diffInMinutes($m->delivered_at))->median() : '—',
                'Lapsed at check-out' => (string) $list->where('close_reason', 'Guest checked out before delivery')->count(), 'Still waiting' => (string) $list->filter->isOpen()->count(),
                'Read on guest portal' => (string) $list->whereNotNull('portal_read_at')->count()],
            'note' => 'Rows in red are still waiting or lapsed because the guest left before the message reached them.'];
    }

    protected function r_traces_register($from, $to): array
    {
        $D = \App\Services\TraceService::DEPARTMENTS;
        $list = \App\Models\Trace::with('reservation', 'stay.room', 'guest', 'creator', 'doer')
            ->where(fn ($q) => $q->whereDate('trace_date', '>=', $from)->whereDate('trace_date', '<=', $to)->orWhereBetween('carried_from', [$from, $to]))->orderBy('trace_date')->orderBy('due_time')->get();
        $rows = $list->map(fn ($t) => ['date' => ($t->carried_from ?? $t->trace_date)->format('d M Y'), 'time' => $t->due_time ? substr($t->due_time, 0, 5) : '',
            'dept' => $D[$t->department] ?? $t->department, 'text' => $t->text, 'res' => (string) $t->reservation?->reservation_no, 'room' => (string) $t->stay?->room?->room_no,
            'carried' => $t->carried_from ? 'to '.$t->trace_date->format('d M') : '', 'status' => ucfirst($t->status), 'done' => $t->done_at?->format('d M H:i') ?? '',
            'by' => (string) ($t->doer?->name ?? ''), 'set' => (string) $t->creator?->name, '_class' => $t->isOverdue() ? 'row-alert' : ''])->values();
        $done = $list->where('status', 'done');

        return ['cols' => ['date' => 'Due', 'time' => 'By', 'dept' => 'Department', 'text' => 'Trace', 'res' => 'Booking', 'room' => 'Room', 'carried' => 'Carried forward',
            'status' => 'Status', 'done' => 'Completed', 'by' => 'Done by', 'set' => 'Set by'],
            'rows' => $rows, 'money' => [], 'sum' => [],
            'summary' => ['Traces' => (string) $list->count(), 'Done' => (string) $done->count(),
                'Done on the day' => (string) $done->filter(fn ($t) => $t->done_at && $t->done_at->toDateString() <= ($t->carried_from ?? $t->trace_date)->toDateString())->count(),
                'Carried forward' => (string) $list->whereNotNull('carried_from')->count(), 'Open' => (string) $list->where('status', 'open')->count(), 'Cancelled' => (string) $list->where('status', 'cancelled')->count()],
            'note' => 'Open traces are moved to the next business day by the night audit; the original date stays in "Due".'];
    }

    protected function r_lost_found_register($from, $to): array
    {
        $I = \App\Models\LostFoundItem::class;
        $list = $I::with('room', 'guest', 'releaser', 'disposer', 'witness', 'report')->whereDate('found_date', '>=', $from)->whereDate('found_date', '<=', $to)->orderBy('found_date')->orderBy('id')->get();
        $rows = $list->map(fn ($i) => ['no' => (string) $i->item_no, 'found' => $i->found_date->format('d M Y'), 'item' => $i->item_name, 'cat' => $i->categoryLabel(),
            'val' => $i->valuable ? 'Yes' : '', 'where' => (string) $i->location, 'finder' => (string) $i->found_by, 'store' => (string) $i->storage_location,
            'owner' => (string) ($i->guest?->full_name ?? ''), 'report' => (string) $i->report?->report_no, 'status' => $i->statusLabel(),
            'outcome' => match ($i->status) {
                'returned' => ($I::RETURN_METHODS[$i->return_method] ?? 'Returned').' – '.$i->returned_to.' '.$i->returned_date?->format('d M').($i->claimant_id_type ? ' (ID checked)' : ''),
                'disposed' => ($I::DISPOSAL[$i->disposal_method] ?? 'Disposed').' '.$i->disposed_date?->format('d M').($i->witness ? ' · witness '.$i->witness->name : ''),
                default => 'keep until '.$i->retain_until?->format('d M Y'),
            }, '_class' => $i->isDue() ? 'row-alert' : ''])->values();
        $ret = $list->where('status', 'returned');

        return ['cols' => ['no' => 'Item no.', 'found' => 'Found', 'item' => 'Item', 'cat' => 'Category', 'val' => 'Valuable', 'where' => 'Where', 'finder' => 'Found by', 'store' => 'Stored',
            'owner' => 'Probable owner', 'report' => 'Loss report', 'status' => 'Status', 'outcome' => 'Outcome'],
            'rows' => $rows, 'money' => [], 'sum' => [],
            'summary' => ['Items found' => (string) $list->count(), 'Valuables' => (string) $list->where('valuable', true)->count(), 'Returned' => (string) $ret->count(),
                'Return rate' => $list->count() ? round($ret->count() / $list->count() * 100).'%' : '—', 'Disposed' => (string) $list->where('status', 'disposed')->count(),
                'Still in store' => (string) $list->where('status', 'stored')->count(), 'Past retention' => (string) $list->filter->isDue()->count(),
                'Loss reports in period' => (string) \App\Models\LostReport::whereBetween('created_at', [Carbon::parse($from)->startOfDay(), Carbon::parse($to)->endOfDay()])->count()],
            'note' => 'Valuables are released only against photo ID by a supervisor and disposed of only with a second member of staff as witness.'];
    }

    protected function r_formc_register($from, $to): array
    {
        $recs = \App\Models\FormCRecord::with('stay.room')->whereBetween('checked_in_at', [Carbon::parse($from)->startOfDay(), Carbon::parse($to)->endOfDay()])
            ->orderBy('checked_in_at')->orderBy('id')->get();
        $V = \App\Models\FormCRecord::VISA_TYPES;
        $rows = $recs->map(fn ($f) => ['in' => $f->checked_in_at?->format('d M Y H:i'), 'form' => $f->form_no, 'guest' => $f->name().($f->is_companion ? ' (companion)' : ''),
            'nat' => (string) $f->nationality, 'passport' => \App\Models\Guest::mask($f->passport_no), 'visa' => (string) ($V[$f->visa_type] ?? ''), 'room' => (string) $f->stay?->room?->room_no,
            'deadline' => $f->deadline_at?->format('d M H:i'), 'filed' => $f->submitted_at?->format('d M H:i') ?? '', 'ref' => (string) $f->frro_ref,
            'hours' => $f->submitted_at && $f->checked_in_at ? round($f->checked_in_at->diffInMinutes($f->submitted_at) / 60, 1) : '',
            'out' => $f->departed_at?->format('d M H:i') ?? '', 'dep' => $f->departure_submitted_at?->format('d M H:i') ?? ($f->departurePending() ? 'pending' : ''),
            'status' => match ($f->state()) { 'overdue' => 'Overdue', 'due' => 'To submit', 'departure' => 'Departure to report', 'done' => 'Closed', 'exempt' => 'Exempt', default => 'Submitted' }
                .($f->isLate() ? ' (late)' : ''),
            '_class' => $f->isOverdue() || $f->isLate() ? 'row-alert' : ''])->values();
        $filed = $recs->whereNotNull('submitted_at');
        $onTime = $filed->filter(fn ($f) => ! $f->isLate())->count();

        return ['cols' => ['in' => 'Checked in', 'form' => 'Form C', 'guest' => 'Name', 'nat' => 'Nationality', 'passport' => 'Passport', 'visa' => 'Visa', 'room' => 'Room',
            'deadline' => 'Deadline', 'filed' => 'Filed', 'ref' => 'FRRO ref', 'hours' => 'Hours taken', 'out' => 'Checked out', 'dep' => 'Departure reported', 'status' => 'Status'],
            'rows' => $rows, 'money' => [], 'sum' => [],
            'summary' => ['Foreign nationals' => (string) $recs->count(), 'Filed' => (string) $filed->count(), 'On time' => $filed->count() ? round($onTime / $filed->count() * 100).'%' : '—',
                'Late' => (string) ($filed->count() - $onTime), 'Still to file' => (string) $recs->where('status', 'pending')->count(),
                'Departures to report' => (string) $recs->filter(fn ($f) => $f->departurePending())->count(), 'Exempt' => (string) $recs->where('status', 'exempt')->count()],
            'note' => 'Rule 14 of the Registration of Foreigners Rules 1992: the arrival of every foreign national is reported on indianfrro.gov.in within 24 hours; departures after check-out. Passport numbers are masked – the full register is in Foreign Guests › Form C › Export (logged).'];
    }

    protected function r_foreign_arrivals($from, $to): array
    {
        $F = \App\Services\FormCService::class;
        $f0 = Carbon::parse($from)->startOfDay();
        $t0 = Carbon::parse($to)->endOfDay();
        $stays = \App\Models\ReservationRoom::with('reservation.guest', 'guest')->whereIn('status', ['checked_in', 'checked_out'])->whereBetween('checked_in_at', [$f0, $t0])->get()
            ->map(fn ($s) => ['s' => $s, 'g' => $F::stayGuest($s)])->filter(fn ($r) => $F::isForeign($r['g']));
        $forms = \App\Models\FormCRecord::whereBetween('checked_in_at', [$f0, $t0])->where('is_companion', false)->get()->keyBy('reservation_room_id');
        $total = $stays->count();
        $rows = $stays->groupBy(fn ($r) => ucwords(mb_strtolower(trim($r['g']->nationality))))->map(function ($grp, $nat) use ($forms, $total) {
            $nights = $grp->sum(fn ($r) => max(1, $r['s']->checked_in_at->copy()->startOfDay()->diffInDays(($r['s']->checked_out_at ?? $r['s']->departure_date)->copy()->startOfDay())));
            $fc = $grp->map(fn ($r) => $forms[$r['s']->id] ?? null)->filter();
            $filed = $fc->whereNotNull('submitted_at');

            return ['nat' => $nat, 'guests' => $grp->pluck('g.id')->unique()->count(), 'stays' => $grp->count(), 'nights' => (int) $nights,
                'avg' => round($nights / max(1, $grp->count()), 1), 'share' => $total ? round($grp->count() / $total * 100, 1).'%' : '',
                'ontime' => $filed->count() ? round($filed->filter(fn ($f) => ! $f->isLate())->count() / $filed->count() * 100).'%' : '—'];
        })->sortByDesc('stays')->values();

        return ['cols' => ['nat' => 'Nationality', 'guests' => 'Guests', 'stays' => 'Stays', 'nights' => 'Room nights', 'avg' => 'Avg nights', 'share' => 'Share', 'ontime' => 'Form C on time'],
            'rows' => $rows, 'money' => [], 'sum' => ['guests', 'stays', 'nights'],
            'summary' => ['Foreign stays' => (string) $total, 'Nationalities' => (string) $rows->count(), 'Top market' => (string) ($rows->first()['nat'] ?? '—'),
                'Room nights' => (string) $rows->sum('nights')],
            'note' => 'Stays checked in during the period whose guest is a foreign national (nationality other than the property\'s country). Room nights run to the check-out, or the planned departure for guests still in house.'];
    }

    protected function r_forex_register($from, $to): array
    {
        $xs = \App\Models\ForexExchange::with('currency', 'stay.room', 'creator')->whereDate('business_date', '>=', Carbon::parse($from)->toDateString())->whereDate('business_date', '<=', Carbon::parse($to)->toDateString())
            ->orderBy('business_date')->orderBy('id')->get();
        $rows = $xs->map(function ($x) {
            $v = (bool) $x->voided_at;

            return ['date' => $x->business_date->format('d M Y'), 'ref' => $x->exchange_no, 'guest' => $x->guest_name.($x->stay?->room ? ' · '.$x->stay->room->room_no : ''),
                'nat' => (string) $x->nationality, 'passport' => \App\Models\Guest::mask($x->passport_no), 'cur' => $x->currency?->code.($x->instrument === 'tc' ? ' (TC)' : ''),
                'fc' => number_format((float) $x->fc_amount, 2), 'rate' => (float) $x->rate.($x->unit > 1 ? ' / '.$x->unit : ''),
                'inr' => $v ? 0.0 : (float) $x->inr_amount, 'gst' => $v ? 0.0 : (float) $x->gst, 'net' => $v ? 0.0 : (float) $x->net_amount,
                'usd' => $v ? 0.0 : (float) $x->usd_equiv, 'how' => $x->payout === 'cash' ? 'Cash' : 'Room bill', 'by' => (string) $x->creator?->name,
                'status' => $v ? 'Void – '.$x->void_reason : 'OK', '_class' => $v ? 'row-alert' : ''];
        })->values();
        $ok = $xs->whereNull('voided_at');

        return ['cols' => ['date' => 'Date', 'ref' => 'Ref', 'guest' => 'Guest · room', 'nat' => 'Nationality', 'passport' => 'Passport', 'cur' => 'Currency', 'fc' => 'Amount',
            'rate' => 'Rate '.hotel()->currency_symbol, 'inr' => 'Cedi value', 'net' => 'Paid / credited', 'usd' => 'USD equiv.', 'how' => 'How', 'by' => 'Cashier', 'status' => 'Status'],
            'rows' => $rows, 'money' => ['inr', 'net'], 'sum' => ['inr', 'net', 'usd'],
            'summary' => ['Purchases' => (string) $ok->count(), 'Cedi value' => money($ok->sum('inr_amount')), 'Paid in cash' => money($ok->where('payout', 'cash')->sum('net_amount')),
                'Credited to bills' => money($ok->where('payout', 'folio')->sum('net_amount')), 'Voided' => (string) $xs->whereNotNull('voided_at')->count()],
            'note' => 'Bank of Ghana rules for licensed buying: the desk only buys foreign currency, pays cash up to the house limit per foreign visitor (counted in USD), records the passport for every purchase and keeps a copy from the configured cedi value. Money changing is VAT-exempt. Voided purchases show zero.'];
    }

    protected function r_forex_position($from, $to): array
    {
        $f = Carbon::parse($from)->toDateString();
        $t = Carbon::parse($to)->toDateString();
        $buys = \App\Models\ForexExchange::whereNull('voided_at')->whereDate('business_date', '<=', $t)->get(['currency_id', 'business_date', 'fc_amount', 'inr_amount'])->groupBy('currency_id');
        $outs = \App\Models\ForexSurrenderLine::with('surrender')->whereHas('surrender', fn ($q) => $q->where('hotel_id', \App\Support\Tenant::id())->where('date', '<=', $t))->get()->groupBy('currency_id');
        $rows = \App\Models\Currency::orderBy('sort_order')->get()->map(function ($c) use ($buys, $outs, $f) {
            $b = $buys[$c->id] ?? collect();
            $o = $outs[$c->id] ?? collect();
            if ($b->isEmpty() && $o->isEmpty()) {
                return null;
            }
            $before = fn ($x, $col) => (float) $x->filter(fn ($r) => ($r->business_date ?? $r->surrender->date)->toDateString() < $f)->sum($col);
            $in = fn ($x, $col) => (float) $x->filter(fn ($r) => ($r->business_date ?? $r->surrender->date)->toDateString() >= $f)->sum($col);
            $open = $before($b, 'fc_amount') - $before($o, 'fc_amount');
            $bought = $in($b, 'fc_amount');
            $sold = $in($o, 'fc_amount');
            $cost = $in($o, 'cost');
            $real = $in($o, 'realised');

            return ['cur' => $c->code, 'open' => round($open, 2), 'bought' => round($bought, 2), 'bought_inr' => round($in($b, 'inr_amount'), 2), 'sold' => round($sold, 2),
                'close' => round($open + $bought - $sold, 2), 'cost' => round($cost, 2), 'real' => round($real, 2), 'gain' => round($real - $cost, 2),
                '_class' => $real - $cost < 0 ? 'row-alert' : ''];
        })->filter()->values();

        return ['cols' => ['cur' => 'Currency', 'open' => 'Opening', 'bought' => 'Bought', 'bought_inr' => 'Bought '.hotel()->currency_symbol, 'sold' => 'Surrendered', 'close' => 'Closing',
            'cost' => 'Cost surrendered '.hotel()->currency_symbol, 'real' => 'Realised '.hotel()->currency_symbol, 'gain' => 'Gain / loss'],
            'rows' => $rows, 'money' => ['bought_inr', 'cost', 'real', 'gain'], 'sum' => ['bought_inr', 'cost', 'real', 'gain'],
            'summary' => ['Currencies' => (string) $rows->count(), 'Bought' => money($rows->sum('bought_inr')), 'Realised' => money($rows->sum('real')), 'Exchange gain' => money($rows->sum('gain'))],
            'note' => 'Foreign-currency amounts per currency; cost = average purchase cost of what was surrendered. Authorised money changers surrender purchases to their bank / authorised dealer promptly (default reminder: 7 days). Gains and losses post to GL 4986.'];
    }

    protected function r_transport_trips($from, $to): array
    {
        $T = \App\Models\TransportTrip::class;
        $rows = $this->trRows($from, $to)->map(fn ($t) => ['when' => $t->scheduled_at->format('d M Y H:i'), 'ref' => $t->trip_no, 'type' => $t->typeLabel(),
            'guest' => $t->passenger_name.($t->stay?->room ? ' · '.$t->stay->room->room_no : ''), 'pax' => (int) $t->pax, 'route' => $t->route(), 'flight' => (string) $t->flight_no,
            'car' => (string) $t->vehicle?->code, 'driver' => (string) $t->driver?->name, 'km' => $t->status === 'completed' ? ($t->km() ?? '') : '', 'late' => $t->status === 'completed' ? ($t->lateMinutes() ?? '') : '',
            'fare' => in_array($t->status, ['cancelled'], true) ? 0 : (float) $t->total, 'billing' => $T::BILLING[$t->billing] ?? $t->billing, 'status' => $t->statusLabel(),
            '_class' => in_array($t->status, ['no_show', 'cancelled'], true) ? 'row-alert' : ''])->values();
        $done = $rows->where('status', 'Completed');

        return ['cols' => ['when' => 'Date & time', 'ref' => 'Trip', 'type' => 'Type', 'guest' => 'Guest · room', 'pax' => 'Pax', 'route' => 'Route', 'flight' => 'Flight / train',
            'car' => 'Vehicle', 'driver' => 'Driver', 'km' => 'km', 'late' => 'Min late', 'fare' => 'Fare', 'billing' => 'Billing', 'status' => 'Status'],
            'rows' => $rows, 'money' => ['fare'], 'sum' => ['pax', 'fare'],
            'summary' => ['Trips' => (string) $rows->count(), 'Completed' => (string) $done->count(), 'Passengers carried' => (string) $done->sum('pax'),
                'km driven' => number_format((int) $done->sum(fn ($r) => (int) $r['km'])), 'No-shows / cancelled' => $rows->where('status', 'No-show')->count().' / '.$rows->where('status', 'Cancelled')->count()],
            'note' => 'By the scheduled time (landing time for pick-ups, leaving time for drops). Fare = tariff + extras before tax (complimentary trips show the value given away). "Min late" = minutes the car left after the planned departure.'];
    }

    protected function r_transport_vehicles($from, $to): array
    {
        $trips = $this->trRows($from, $to)->where('status', 'completed')->whereNotNull('vehicle_id')->groupBy('vehicle_id');
        $exp = \App\Models\VehicleExpense::whereBetween('date', [Carbon::parse($from)->toDateString(), Carbon::parse($to)->toDateString()])->get()->groupBy('vehicle_id');
        $rows = \App\Models\Vehicle::orderBy('code')->get()->map(function ($v) use ($trips, $exp) {
            $t = $trips[$v->id] ?? collect();
            $e = $exp[$v->id] ?? collect();
            $km = (int) $t->sum(fn ($x) => $x->km() ?? 0);
            $hours = round($t->sum(fn ($x) => $x->started_at && $x->completed_at ? $x->started_at->diffInMinutes($x->completed_at) : $x->duration_min) / 60, 1);
            $rev = (float) $t->where('billing', '!=', 'complimentary')->sum('total');
            $fuel = (float) $e->where('kind', 'fuel')->sum('amount');
            $cost = (float) $e->sum('amount');
            $lit = (float) $e->where('kind', 'fuel')->sum('litres');
            if (! $t->count() && ! $e->count() && ! $v->is_active) {
                return null;
            }

            return ['car' => $v->code.' · '.$v->reg_no, 'type' => \App\Models\Vehicle::TYPES[$v->type] ?? $v->type, 'trips' => $t->count(), 'km' => $km, 'hours' => $hours,
                'rev' => $rev, 'comp' => (float) $t->where('billing', 'complimentary')->sum('total'), 'fuel' => $fuel, 'other' => round($cost - $fuel, 2),
                'cpk' => $km ? round($cost / $km, 2) : '', 'kpl' => $lit > 0 && $km ? round($km / $lit, 1) : '', 'margin' => round($rev - $cost, 2),
                '_class' => $rev - $cost < 0 ? 'row-alert' : ''];
        })->filter()->values();

        return ['cols' => ['car' => 'Vehicle', 'type' => 'Type', 'trips' => 'Trips', 'km' => 'km', 'hours' => 'Hours on road', 'rev' => 'Revenue', 'comp' => 'Complimentary value',
            'fuel' => 'Fuel', 'other' => 'Service, tolls & other', 'cpk' => 'Cost / km', 'kpl' => 'km / litre', 'margin' => 'Margin'],
            'rows' => $rows, 'money' => ['rev', 'comp', 'fuel', 'other', 'cpk', 'margin'], 'sum' => ['trips', 'km', 'hours', 'rev', 'comp', 'fuel', 'other', 'margin'],
            'summary' => ['km driven' => number_format($rows->sum('km')), 'Running cost' => money($rows->sum('fuel') + $rows->sum('other')),
                'Cost per km' => $rows->sum('km') ? money(($rows->sum('fuel') + $rows->sum('other')) / $rows->sum('km')) : '–', 'Margin' => money($rows->sum('margin'))],
            'note' => 'Completed trips by scheduled date; km from the odometer readings entered at start and completion. Running cost = vehicle expenses dated in the period (fuel, service, repairs, tolls, parking). Driver salaries are in payroll and not included. Vehicles running at a loss are highlighted.'];
    }

    protected function r_transport_drivers($from, $to): array
    {
        $all = $this->trRows($from, $to)->whereNotNull('driver_id')->groupBy('driver_id');
        $rows = \App\Models\Driver::orderBy('name')->get()->map(function ($d) use ($all) {
            $t = $all[$d->id] ?? collect();
            if (! $t->count()) {
                return null;
            }
            $done = $t->where('status', 'completed');
            $started = $done->filter(fn ($x) => $x->started_at);
            $onTime = $started->filter(fn ($x) => $x->started_at->lte($x->busy_from->copy()->addMinutes(10)))->count();

            return ['driver' => $d->name, 'trips' => $done->count(), 'pax' => (int) $done->sum('pax'), 'km' => (int) $done->sum(fn ($x) => $x->km() ?? 0),
                'hours' => round($done->sum(fn ($x) => $x->started_at && $x->completed_at ? $x->started_at->diffInMinutes($x->completed_at) : $x->duration_min) / 60, 1),
                'ontime' => $started->count() ? round($onTime / $started->count() * 100) : '', 'avglate' => $started->count() ? round($started->avg(fn ($x) => $x->lateMinutes())) : '',
                'ns' => $t->where('status', 'no_show')->count(), 'rev' => (float) $done->where('billing', '!=', 'complimentary')->sum('total'),
                'last' => $done->last()?->scheduled_at->format('d M H:i') ?? '', '_class' => $started->count() && $onTime / $started->count() < .8 ? 'row-alert' : ''];
        })->filter()->values();
        $st = $rows->where('ontime', '!==', '');

        return ['cols' => ['driver' => 'Driver', 'trips' => 'Trips', 'pax' => 'Passengers', 'km' => 'km', 'hours' => 'Hours driving', 'ontime' => 'On time %', 'avglate' => 'Avg min late',
            'ns' => 'No-shows', 'rev' => 'Revenue', 'last' => 'Last trip'],
            'rows' => $rows, 'money' => ['rev'], 'sum' => ['trips', 'pax', 'km', 'hours', 'ns', 'rev'],
            'summary' => ['Trips' => (string) $rows->sum('trips'), 'Drivers' => (string) $rows->count(), 'On time (fleet)' => $st->count() ? round($st->avg('ontime')).'%' : '–'],
            'note' => 'On time = the car left no more than 10 minutes after the planned departure (pick-ups leave before the landing time by the lead set in Transport Settings). Drivers below 80% on time are highlighted. No-shows = trips where the guest was not found.'];
    }

    protected function r_transport_revenue($from, $to): array
    {
        $rows = $this->trRows($from, $to)->groupBy(fn ($t) => $t->scheduled_at->toDateString())->map(function ($d, $day) {
            $done = $d->where('status', 'completed');
            $sum = fn ($b) => (float) $done->where('billing', $b)->sum('total');

            return ['day' => Carbon::parse($day)->format('D d M Y'), 'pu' => $done->where('type', 'pickup')->count(), 'dr' => $done->where('type', 'drop')->count(), 'tf' => $done->where('type', 'transfer')->count(),
                'room' => $sum('room'), 'direct' => $sum('direct'), 'comp' => $sum('complimentary'), 'extra' => (float) $done->where('billing', '!=', 'complimentary')->sum('extra_amount'),
                'nsfee' => (float) $d->where('status', 'no_show')->whereNotNull('folio_transaction_id')->sum('total'),
                'ns' => $d->where('status', 'no_show')->count(), 'cx' => $d->where('status', 'cancelled')->count(), 'total' => $sum('room') + $sum('direct')
                    + (float) $d->where('status', 'no_show')->whereNotNull('folio_transaction_id')->sum('total')];
        })->values();

        return ['cols' => ['day' => 'Day', 'pu' => 'Pick-ups', 'dr' => 'Drops', 'tf' => 'Transfers', 'room' => 'To room bill', 'direct' => 'Paid direct', 'extra' => 'of which extras',
            'nsfee' => 'No-show fees', 'comp' => 'Complimentary value', 'ns' => 'No-shows', 'cx' => 'Cancelled', 'total' => 'Revenue'],
            'rows' => $rows, 'money' => ['room', 'direct', 'extra', 'nsfee', 'comp', 'total'], 'sum' => ['pu', 'dr', 'tf', 'room', 'direct', 'extra', 'nsfee', 'comp', 'ns', 'cx', 'total'],
            'summary' => ['Revenue' => money($rows->sum('total')), 'Trips' => (string) ($rows->sum('pu') + $rows->sum('dr') + $rows->sum('tf')),
                'Given free' => money($rows->sum('comp')), 'Avg fare' => ($n = $rows->sum('pu') + $rows->sum('dr') + $rows->sum('tf')) ? money(($rows->sum('room') + $rows->sum('direct')) / $n) : '–'],
            'note' => 'Completed trips by scheduled date, fares before tax. Room-billed fares post to the guest folio (GL 4070 Transport Revenue); direct fares are invoiced and paid at completion. Complimentary value is not revenue – it shows what was given away.'];
    }

    // ───────────────────────── v4.9 workforce ─────────────────────────

    protected function r_roster_vs_actual($from, $to): array
    {
        $f = $from->toDateString();
        $t = min($to->toDateString(), now()->toDateString());
        $shifts = \App\Models\RosterShift::with('template')->where('kind', 'shift')->whereBetween('date', [$f, $t])->get()->groupBy('employee_id');
        $punches = \App\Models\TimePunch::whereDate('work_date', '>=', $f)->whereDate('work_date', '<=', $t)->get()->groupBy('employee_id');
        $ids = $shifts->keys()->merge($punches->keys())->unique();
        $leave = \App\Services\RosterService::leaveMap($ids, $f, $t);
        $rows = \App\Models\Employee::with('department')->whereIn('id', $ids)->orderBy('department_id')->orderBy('name')->get()->map(function ($e) use ($shifts, $punches, $leave) {
            $ss = $shifts[$e->id] ?? collect();
            $ps = $punches[$e->id] ?? collect();
            $punchDays = $ps->pluck('work_date')->map(fn ($d) => $d->toDateString())->unique()->flip();
            $noShow = $ss->filter(fn ($s) => ! isset($punchDays[$s->date->toDateString()]) && ! isset($leave[$e->id][$s->date->toDateString()]) && $s->startsAt()->lt(now()))->count();
            $unrost = $ps->filter(fn ($p) => ! $p->roster_shift_id)->count();
            $planned = round($ss->sum(fn ($s) => $s->paidMinutes()) / 60, 2);
            $worked = round($ps->sum('worked_minutes') / 60, 2);

            return ['emp' => $e->name.' ('.$e->emp_code.')', 'dept' => $e->department?->name, 'shifts' => $ss->count(), 'planned' => $planned, 'days' => $punchDays->count(), 'worked' => $worked,
                'variance' => round($worked - $planned, 2), 'late' => $ps->where('late_minutes', '>', 0)->count() ?: null, 'late_min' => (int) $ps->sum('late_minutes') ?: null,
                'early' => $ps->where('early_minutes', '>', 0)->count() ?: null, 'no_show' => $noShow ?: null, 'unrostered' => $unrost ?: null,
                'edited' => $ps->whereNotNull('edited_by')->count() ?: null, 'open' => $ps->where('status', 'open')->count() ?: null,
                'punctual' => $ps->whereNotNull('roster_shift_id')->count() ? round($ps->whereNotNull('roster_shift_id')->where('late_minutes', 0)->count() / $ps->whereNotNull('roster_shift_id')->count() * 100, 1) : null];
        });

        return ['cols' => ['emp' => 'Employee', 'dept' => 'Department', 'shifts' => 'Shifts', 'planned' => 'Planned h', 'days' => 'Days worked', 'worked' => 'Worked h', 'variance' => 'Variance h',
            'late' => 'Late', 'late_min' => 'Late min', 'early' => 'Left early', 'no_show' => 'No-shows', 'unrostered' => 'Unrostered', 'edited' => 'Corrected', 'open' => 'Open', 'punctual' => 'On time %'],
            'rows' => $rows, 'sum' => ['shifts', 'planned', 'days', 'worked', 'variance', 'late', 'late_min', 'early', 'no_show', 'unrostered', 'edited', 'open'],
            'note' => 'Planned hours are rostered shift hours less the unpaid break; worked hours come from closed time punches (break deducted). A no-show is a rostered shift that has started with no punch and no approved leave. Future days are ignored.'];
    }

    protected function r_timesheet($from, $to): array
    {
        $rows = \App\Models\TimePunch::with('employee.department', 'shift.template', 'editor', 'approver')->whereDate('work_date', '>=', $from->toDateString())->whereDate('work_date', '<=', $to->toDateString())
            ->orderBy('work_date')->orderBy('clock_in')->get()->map(fn ($p) => ['date' => $p->work_date->format('d M Y'), 'emp' => $p->employee?->name.' ('.$p->employee?->emp_code.')', 'dept' => $p->employee?->department?->name,
                'shift' => $p->shift ? $p->shift->code().' '.$p->shift->label() : 'not rostered', 'in' => $p->clock_in->format('H:i'), 'out' => $p->clock_out ? $p->clock_out->format($p->clock_out->isSameDay($p->clock_in) ? 'H:i' : 'd M H:i') : 'open',
                'hours' => $p->clock_out ? round($p->worked_minutes / 60, 2) : null, 'late' => $p->late_minutes ?: null, 'early' => $p->early_minutes ?: null, 'source' => $p->in_source.($p->out_source && $p->out_source !== $p->in_source ? ' / '.$p->out_source : ''),
                'edited' => $p->edit_reason ? ($p->editor?->name.': '.$p->edit_reason) : '', 'status' => $p->status.($p->approver ? ' ('.$p->approver->name.')' : '')]);

        return ['cols' => ['date' => 'Date', 'emp' => 'Employee', 'dept' => 'Department', 'shift' => 'Rostered', 'in' => 'In', 'out' => 'Out', 'hours' => 'Hours', 'late' => 'Late min', 'early' => 'Early min', 'source' => 'Source', 'edited' => 'Correction', 'status' => 'Status'],
            'rows' => $rows, 'sum' => ['hours', 'late', 'early'], 'note' => 'Sources: self = self-service, kiosk = shared terminal with PIN, manager = entered or corrected by a supervisor.'];
    }

    protected function r_service_charge_distribution($from, $to): array
    {
        $rows = collect();
        foreach (\App\Models\ScDistribution::with('lines.employee', 'lines.department', 'run')->where('status', '!=', 'cancelled')->where('period_start', '<=', $to->toDateString())->where('period_end', '>=', $from->toDateString())->orderBy('period_start')->get() as $d) {
            foreach ($d->lines->sortBy(fn ($l) => [$l->department?->name, $l->employee?->name]) as $l) {
                $rows->push(['dist' => $d->dist_no, 'period' => $d->label(), 'emp' => $l->employee?->name.' ('.$l->employee?->emp_code.')', 'dept' => $l->department?->name,
                    'points' => (float) $l->points, 'days' => (float) $l->days, 'amount' => (float) $l->amount, 'note' => $l->excluded ? 'excluded: '.$l->exclude_reason : '',
                    'status' => $d->status, 'run' => $d->run?->run_no]);
            }
        }

        return ['cols' => ['dist' => 'Distribution', 'period' => 'Period', 'emp' => 'Employee', 'dept' => 'Department', 'points' => 'Points', 'days' => 'Days', 'amount' => 'Amount', 'note' => 'Note', 'status' => 'Status', 'run' => 'Payroll run'],
            'rows' => $rows, 'money' => ['amount'], 'sum' => ['amount'], 'note' => 'Shares are paid through payroll as a taxable earning. The house retention is posted to 4095 Service Charge Retained.'];
    }

    // ---------------------------------------------------------------- v5.0 food safety

    protected function r_haccp_temperatures($from, $to): array
    {
        $rows = \App\Models\FsTempLog::with('equipment', 'user', 'reviewer', 'supplier')->whereBetween('recorded_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])->orderBy('recorded_at')->get()
            ->map(fn ($l) => ['at' => $l->recorded_at->format('d/m/Y H:i'), 'kind' => \App\Models\FsTempLog::KINDS[$l->kind], 'what' => $l->subject().($l->supplier ? ' – '.$l->supplier->name : ''),
                'reading' => rtrim(rtrim(number_format((float) $l->reading_c, 1), '0'), '.').($l->reading2_c !== null ? ' → '.rtrim(rtrim(number_format((float) $l->reading2_c, 1), '0'), '.') : ''),
                'limit' => \App\Services\FoodSafetyService::limitText($l->min_c, $l->max_c), 'result' => $l->in_range ? ($l->coolingOpen() ? 'cooling' : 'OK') : 'OUT OF RANGE',
                'action' => $l->corrective_action, 'by' => $l->user?->name, 'reviewed' => $l->reviewer ? $l->reviewer->name.' '.$l->reviewed_at->format('d/m') : ($l->in_range ? '' : 'pending')]);
        $bad = $rows->where('result', 'OUT OF RANGE')->count();

        return ['cols' => ['at' => 'Date / time', 'kind' => 'Type', 'what' => 'Equipment / food', 'reading' => '°C', 'limit' => 'Critical limit', 'result' => 'Result', 'action' => 'Corrective action', 'by' => 'Recorded by', 'reviewed' => 'Reviewed'],
            'rows' => $rows, 'money' => [], 'sum' => [], 'note' => $rows->count().' readings, '.$bad.' outside the critical limit. Keep HACCP records for at least 2 years (Ghana FDA inspections).'];
    }

    protected function r_haccp_checklists($from, $to): array
    {
        $FS = \App\Services\FoodSafetyService::class;
        $runs = \App\Models\FsChecklistRun::with('completer', 'signer')->whereBetween('period', [$from->copy()->subMonth()->toDateString(), $to->toDateString()])->get()->keyBy(fn ($r) => $r->fs_checklist_id.'|'.$r->period->toDateString());
        $rows = collect();
        foreach (\App\Models\FsChecklist::orderBy('name')->get() as $c) {
            $seen = [];
            for ($d = $from->copy(); $d->lte($to) && $d->lte(now()); $d->addDay()) {
                $p = $FS::periodFor($c, $d);
                if (isset($seen[$p->toDateString()]) || ($c->created_at && $c->created_at->toDateString() > $p->toDateString()) || (! $c->is_active && ! isset($runs[$c->id.'|'.$p->toDateString()]))) {
                    continue;
                }
                $seen[$p->toDateString()] = true;
                $r = $runs[$c->id.'|'.$p->toDateString()] ?? null;
                $current = $p->toDateString() === $FS::periodFor($c)->toDateString();
                $rows->push(['period' => $FS::periodLabel($c, $p), 'list' => $c->name, 'area' => $c->area, 'status' => $r ? ($r->failed ? 'completed – '.$r->failed.' failed' : 'completed') : ($current ? 'due' : 'MISSED'),
                    'by' => $r ? $r->completer?->name.' '.$r->completed_at->format('d/m H:i') : '', 'failed' => $r ? (int) $r->failed : 0,
                    'issues' => $r ? collect($r->answers)->where('ok', false)->map(fn ($a) => $a['item'].': '.$a['note'])->implode('; ') : '', 'signed' => $r?->signer?->name ?? ($r && $r->failed ? 'awaiting' : '')]);
            }
        }
        $missed = $rows->where('status', 'MISSED')->count();

        return ['cols' => ['period' => 'Period', 'list' => 'Checklist', 'area' => 'Area', 'status' => 'Status', 'by' => 'Completed by', 'failed' => 'Failed items', 'issues' => 'Issues', 'signed' => 'Signed off by'],
            'rows' => $rows, 'money' => [], 'sum' => ['failed'], 'note' => $rows->count().' checklist periods, '.$missed.' missed.'];
    }

    protected function r_food_waste($from, $to): array
    {
        $rows = \App\Models\FsWaste::with('item', 'menuItem', 'store', 'outlet', 'user')->whereNull('cancelled_at')->whereBetween('date', [$from->toDateString(), $to->toDateString()])->orderBy('date')->get()
            ->map(fn ($w) => ['no' => $w->waste_no, 'date' => dmy($w->date), 'item' => $w->name(), 'type' => \App\Models\FsWaste::SOURCES[$w->source], 'qty' => rtrim(rtrim(number_format((float) $w->qty, 3), '0'), '.').' '.$w->unit,
                'reason' => \App\Models\FsWaste::REASONS[$w->reason], 'where' => trim(($w->store?->name ?? '').($w->outlet ? ' / '.$w->outlet->name : ''), ' /'), 'cost' => (float) $w->cost, 'by' => $w->user?->name]);
        $s = \App\Services\FoodWasteService::summary($from->toDateString(), $to->toDateString());

        return ['cols' => ['no' => 'No.', 'date' => 'Date', 'item' => 'Item', 'type' => 'Type', 'qty' => 'Quantity', 'reason' => 'Reason', 'where' => 'Store / outlet', 'cost' => 'Cost', 'by' => 'By'],
            'rows' => $rows, 'money' => ['cost'], 'sum' => ['cost'], 'note' => 'Taken out of stock '.money($s['stock']).' (5042 Food Waste)'.($s['pct'] !== null ? ' = '.$s['pct'].'% of food & beverage cost' : '').'; plate waste '.money($s['plate']).' (analysis only).'];
    }

    // ───────────────────────── v5.1 health club ─────────────────────────

    protected function r_club_members($from, $to): array
    {
        $f = $from->toDateString();
        $t = $to->toDateString();
        $ms = \App\Models\ClubMembership::with('plan', 'company')->withCount(['members' => fn ($q) => $q->where('is_active', true)])
            ->whereDate('start_date', '<=', $t)->where(fn ($q) => $q->whereDate('end_date', '>=', $f)->orWhereIn('status', ['active', 'frozen']))->orderBy('membership_no')->get();
        $visits = \App\Models\ClubVisit::join('club_members as cm', 'cm.id', '=', 'club_visits.club_member_id')->where('club_visits.denied', false)
            ->whereBetween('club_visits.checked_in_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])->selectRaw('cm.club_membership_id m, COUNT(*) n')->groupBy('cm.club_membership_id')->pluck('n', 'm');
        $rows = $ms->map(fn ($m) => ['no' => $m->membership_no, 'holder' => $m->holder_name.($m->company ? ' ('.$m->company->name.')' : ''), 'plan' => $m->plan->name, 'cards' => $m->members_count,
            'start' => dmy($m->start_date), 'end' => dmy($m->end_date), 'status' => \App\Models\ClubMembership::STATUSES[$m->status].($m->cancel_mode === 'term_end' && $m->status !== 'cancelled' ? ' (notice)' : ''),
            'visits' => (int) ($visits[$m->id] ?? 0), 'balance' => \App\Services\ClubBillingService::balance($m)]);
        $joined = $ms->filter(fn ($m) => $m->start_date->between($from, $to) && ! \App\Models\ClubCharge::where('club_membership_id', $m->id)->where('type', 'subscription')->where('period_start', '<', $m->start_date)->exists())->count();
        $renewed = \App\Models\ClubCharge::where('type', 'subscription')->whereNull('cancelled_at')->whereBetween('date', [$f, $t])->where('description', 'like', '%renewal%')->count();
        $left = $ms->filter(fn ($m) => ($m->status === 'cancelled' && $m->cancelled_on && $m->cancelled_on->between($from, $to)) || ($m->status === 'expired' && $m->end_date->between($from, $to)))->count();

        return ['cols' => ['no' => 'Membership', 'holder' => 'Holder', 'plan' => 'Plan', 'cards' => 'Cards', 'start' => 'Since', 'end' => 'Term ends', 'status' => 'Status', 'visits' => 'Visits', 'balance' => 'Balance'],
            'rows' => $rows, 'money' => ['balance'], 'sum' => ['cards', 'visits', 'balance'], 'note' => "{$joined} joined, {$renewed} renewal invoice(s), {$left} left or lapsed in the period. Balance: positive = owed by the member."];
    }

    protected function r_club_attendance($from, $to): array
    {
        $v = \App\Models\ClubVisit::whereBetween('checked_in_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])->get();
        $rows = collect();
        for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
            $day = $v->filter(fn ($x) => $x->checked_in_at->isSameDay($d));
            $ok = $day->where('denied', false);
            $row = ['date' => $d->format('D d M')];
            foreach (\App\Models\ClubPlan::FACILITIES as $k => $l) {
                $row[$k] = (int) $ok->where('facility', $k)->sum('pax');
            }
            foreach (['member', 'resident', 'day_pass', 'guest'] as $ty) {
                $row['t_'.$ty] = (int) $ok->where('visitor_type', $ty)->sum('pax');
            }
            $row['total'] = (int) $ok->sum('pax');
            $row['denied'] = $day->where('denied', true)->count();
            $row['override'] = $ok->whereNotNull('override_reason')->count();
            $row['classes'] = \App\Models\ClubBooking::where('date', $d->toDateString())->where('status', 'attended')->count();
            $rows->push($row);
        }
        $cols = ['date' => 'Date'] + collect(\App\Models\ClubPlan::FACILITIES)->except('classes')->all()
            + ['t_member' => 'Members', 't_resident' => 'Hotel guests', 't_day_pass' => 'Day passes', 't_guest' => "Members' guests", 'total' => 'Total visits', 'denied' => 'Refused', 'override' => 'Overrides', 'classes' => 'Class attendances'];
        $rows = $rows->map(fn ($r) => array_diff_key($r, ['classes_fac' => 1]));
        $mins = $v->where('denied', false)->filter(fn ($x) => $x->checked_out_at)->map(fn ($x) => $x->checked_in_at->diffInMinutes($x->checked_out_at));

        return ['cols' => $cols, 'rows' => $rows, 'sum' => array_values(array_diff(array_keys($cols), ['date'])),
            'note' => 'Average stay '.($mins->count() ? round($mins->avg()).' min' : '—').'. Visits count people (a family check-in of 3 counts 3).'];
    }

    protected function r_club_revenue($from, $to): array
    {
        $f = $from->toDateString();
        $t = $to->toDateString();
        $ch = \App\Models\ClubCharge::with('creditOf')->whereNull('cancelled_at')->whereBetween('date', [$f, $t])->get();
        $dp = \App\Models\ClubDayPass::whereNull('cancelled_at')->whereBetween('date', [$f, $t])->get();
        $pay = \App\Models\ClubPayment::whereNull('voided_at')->whereBetween('date', [$f, $t])->get();
        $subCredits = $ch->where('type', 'credit')->filter(fn ($c) => $c->creditOf?->type === 'subscription');
        $otherCredits = $ch->where('type', 'credit')->reject(fn ($c) => $c->creditOf?->type === 'subscription');
        $rows = collect([
            ['line' => 'Subscriptions invoiced (deferred to 2130)', 'net' => (float) $ch->where('type', 'subscription')->sum('amount'), 'tax' => (float) $ch->where('type', 'subscription')->sum('tax'), 'count' => $ch->where('type', 'subscription')->count()],
            ['line' => 'Credit notes on subscriptions', 'net' => (float) $subCredits->sum('amount'), 'tax' => (float) $subCredits->sum('tax'), 'count' => $subCredits->count()],
            ['line' => 'Membership revenue earned (4042)', 'net' => \App\Services\ClubBillingService::recognised($f, $t), 'tax' => 0, 'count' => null],
            ['line' => 'Joining fees (4043)', 'net' => (float) $ch->where('type', 'joining')->sum('amount'), 'tax' => (float) $ch->where('type', 'joining')->sum('tax'), 'count' => $ch->where('type', 'joining')->count()],
            ['line' => 'Guest passes & other charges (4043)', 'net' => (float) $ch->whereIn('type', ['guest_pass', 'other'])->sum('amount') + (float) $otherCredits->sum('amount'), 'tax' => (float) $ch->whereIn('type', ['guest_pass', 'other'])->sum('tax') + (float) $otherCredits->sum('tax'), 'count' => $ch->whereIn('type', ['guest_pass', 'other'])->count()],
            ['line' => 'Day passes paid at the desk (4043)', 'net' => (float) $dp->whereNull('reservation_room_id')->sum('amount'), 'tax' => (float) $dp->whereNull('reservation_room_id')->sum('tax'), 'count' => $dp->whereNull('reservation_room_id')->count()],
            ['line' => 'Day passes charged to rooms (via folio)', 'net' => (float) $dp->whereNotNull('reservation_room_id')->sum('amount'), 'tax' => (float) $dp->whereNotNull('reservation_room_id')->sum('tax'), 'count' => $dp->whereNotNull('reservation_room_id')->count()],
            ['line' => 'Member receipts', 'net' => (float) $pay->where('kind', 'payment')->sum('amount'), 'tax' => null, 'count' => $pay->where('kind', 'payment')->count()],
            ['line' => 'Refunds to members', 'net' => -(float) $pay->where('kind', 'refund')->sum('amount'), 'tax' => null, 'count' => $pay->where('kind', 'refund')->count()],
        ])->map(fn ($r) => $r + ['total' => $r['tax'] === null ? null : round($r['net'] + $r['tax'], 2)]);
        $earned = \App\Services\ClubBillingService::recognised($f, $t) + $rows[3]['net'] + $rows[4]['net'] + $rows[5]['net'] + $rows[6]['net'];

        return ['cols' => ['line' => 'Line', 'count' => 'Documents', 'net' => 'Net', 'tax' => 'Tax', 'total' => 'Total'], 'rows' => $rows, 'money' => ['net', 'tax', 'total'],
            'note' => 'Club revenue earned in the period '.money($earned).' (net of tax). Membership fees received in advance at '.dmy($t).': '.money(\App\Services\ClubBillingService::unearned($t)).'. Members receivable: '.money(\App\Services\ClubBillingService::controlBalance($t)).'.'];
    }

    protected function r_lease_rent_roll($from, $to): array
    {
        $f = $from->toDateString();
        $t = $to->toDateString();
        $LB = \App\Services\LeaseBillingService::class;
        $leases = \App\Models\Lease::whereIn('status', ['active', 'notice', 'ended', 'terminated'])->whereDate('start_date', '<=', $t)->get()
            ->filter(fn ($l) => $l->lastDay()->toDateString() >= $f)->groupBy('lease_unit_id');
        $rows = \App\Models\LeaseUnit::where('is_active', true)->orderBy('code')->get()->map(function ($u) use ($leases, $f, $t, $LB) {
            $l = ($leases[$u->id] ?? collect())->sortByDesc('start_date')->first();
            $inv = $l ? (float) \App\Models\LeaseCharge::where('lease_id', $l->id)->whereNull('cancelled_at')->whereIn('type', ['rent', 'service_charge'])->whereBetween('date', [$f, $t])->sum('amount')
                + (float) \App\Models\LeaseCharge::where('lease_id', $l->id)->whereNull('cancelled_at')->where('type', 'credit')->whereBetween('date', [$f, $t])->whereHas('creditOf', fn ($q) => $q->whereIn('type', ['rent', 'service_charge']))->sum('amount') : 0;
            $col = $l ? (float) \App\Models\LeasePayment::where('lease_id', $l->id)->whereNull('voided_at')->where('kind', 'payment')->whereBetween('date', [$f, $t])->selectRaw('SUM(amount + wht_amount) s')->value('s') : 0;

            return ['unit' => $u->code, 'type' => \App\Models\LeaseUnit::TYPES[$u->type], 'tenant' => $l ? $l->tenant_name.($l->company_id ? ' ('.$l->company?->name.')' : '') : '— '.strtolower(\App\Models\LeaseUnit::STATUSES[$u->status]).' —',
                'class' => $l ? ucfirst($l->tax_class) : ucfirst($u->tax_class), 'term' => $l ? dmy($l->start_date).' – '.dmy($l->lastDay()) : '', 'cycle' => $l ? \App\Models\Lease::CYCLES[$l->cycle_months] : '',
                'rent' => $l ? (float) $l->rent : 0, 'sc' => $l ? (float) $l->service_charge : 0, 'deposit' => $l ? $LB::depositHeld($l, $t) : 0,
                'invoiced' => round($inv, 2), 'collected' => round($col, 2), 'balance' => $l ? $LB::balance($l, $t) : 0,
                'status' => $l ? \App\Models\Lease::STATUSES[$l->status] : \App\Models\LeaseUnit::STATUSES[$u->status]];
        });
        $let = $rows->filter(fn ($r) => in_array($r['status'], ['Active', 'Notice given'], true))->count();
        $vac = $rows->where('status', 'Vacant')->count();

        return ['cols' => ['unit' => 'Unit', 'type' => 'Type', 'tenant' => 'Tenant', 'class' => 'Tax class', 'term' => 'Term', 'cycle' => 'Cycle', 'rent' => 'Rent / mo', 'sc' => 'Service / mo', 'deposit' => 'Deposit held',
            'invoiced' => 'Invoiced (net)', 'collected' => 'Collected', 'balance' => 'Balance', 'status' => 'Status'],
            'rows' => $rows, 'money' => ['rent', 'sc', 'deposit', 'invoiced', 'collected', 'balance'], 'sum' => ['rent', 'sc', 'deposit', 'invoiced', 'collected', 'balance'],
            'note' => "{$let} of {$rows->count()} units let (".($rows->count() ? round($let * 100 / $rows->count(), 1) : 0)."%), {$vac} vacant. Invoiced = rent & service charge net of tax and credit notes; collected includes tax withheld by company tenants."];
    }

    protected function r_lease_arrears($from, $to): array
    {
        $t = $to->toDateString();
        $LB = \App\Services\LeaseBillingService::class;
        $rows = $LB::arrears($t)->map(fn ($a) => ['lease' => $a['lease']->lease_no, 'unit' => $a['lease']->unit->code, 'tenant' => $a['lease']->tenant_name, 'payer' => $a['lease']->company?->name ?? 'Individual',
            'phone' => $a['lease']->phone, 'since' => dmy($a['since']), 'b0' => $a['buckets'][0], 'b1' => $a['buckets'][1], 'b2' => $a['buckets'][2], 'b3' => $a['buckets'][3], 'overdue' => $a['amount'],
            'balance' => $a['balance'], 'deposit' => $LB::depositHeld($a['lease'], $t), 'status' => \App\Models\Lease::STATUSES[$a['lease']->status]]);

        return ['cols' => ['lease' => 'Lease', 'unit' => 'Unit', 'tenant' => 'Tenant', 'payer' => 'Payer', 'phone' => 'Phone', 'since' => 'Oldest due', 'b0' => '0–30 days', 'b1' => '31–60', 'b2' => '61–90', 'b3' => '90+',
            'overdue' => 'Overdue', 'balance' => 'Account balance', 'deposit' => 'Deposit held', 'status' => 'Lease'],
            'rows' => $rows, 'money' => ['b0', 'b1', 'b2', 'b3', 'overdue', 'balance', 'deposit'], 'sum' => ['b0', 'b1', 'b2', 'b3', 'overdue', 'balance', 'deposit'],
            'note' => 'As at '.dmy($t).', after '.(int) \App\Services\LeaseService::cfg('lease_grace_days').' days\' grace. Tenant receivables in total: '.money($LB::controlBalance($t)).'. The deposit is security, not a payment – apply it only at move-out or with the tenant\'s agreement.'];
    }

    protected function r_lease_expiry($from, $to): array
    {
        $f = $from->toDateString();
        $t = $to->toDateString();
        $rows = collect();
        foreach (\App\Models\Lease::with('unit', 'company')->whereIn('status', ['active', 'notice'])->get() as $l) {
            $end = $l->lastDay()->toDateString();
            if ($end >= $f && $end <= $t) {
                $rows->push(['date' => $end, 'event' => $l->status === 'notice' ? 'Move-out (notice by '.$l->notice_by.')' : ($l->auto_renew ? 'Term ends – auto-renews' : 'Term ends'), 'l' => $l,
                    'action' => $l->status === 'notice' ? 'Book the move-out inspection & meter readings; market the unit' : ($l->auto_renew ? 'Renews 30 days before the end' : 'Agree renewal or take notice')]);
            }
            if ($l->next_review_on && $l->next_review_on->toDateString() >= $f && $l->next_review_on->toDateString() <= $t && $l->next_review_on->lte($l->end_date)) {
                $rows->push(['date' => $l->next_review_on->toDateString(), 'event' => 'Rent review +'.rtrim(rtrim(number_format($l->escalation_pct, 2), '0'), '.').'%', 'l' => $l,
                    'action' => 'New rent '.money(round($l->rent * (1 + $l->escalation_pct / 100), 2)).' applied automatically – notify the tenant']);
            }
        }
        foreach (\App\Models\Lease::with('unit', 'company')->where('status', 'draft')->whereDate('start_date', '>=', $f)->whereDate('start_date', '<=', $t)->get() as $l) {
            $rows->push(['date' => $l->start_date->toDateString(), 'event' => 'Move-in', 'l' => $l, 'action' => $l->signed_at ? 'Prepare the unit, keys & opening meter readings' : 'Get the agreement signed first']);
        }
        $rows = $rows->sortBy('date')->values()->map(fn ($r) => ['date' => dmy($r['date']), 'event' => $r['event'], 'lease' => $r['l']->lease_no, 'unit' => $r['l']->unit->code, 'tenant' => $r['l']->tenant_name.($r['l']->company ? ' ('.$r['l']->company->name.')' : ''),
            'rent' => (float) $r['l']->rent, 'action' => $r['action']]);

        return ['cols' => ['date' => 'Date', 'event' => 'Event', 'lease' => 'Lease', 'unit' => 'Unit', 'tenant' => 'Tenant', 'rent' => 'Rent / mo', 'action' => 'Action'], 'rows' => $rows, 'money' => ['rent'],
            'note' => 'Pick a forward date range (e.g. the next 90 days) to plan renewals and move-outs.'];
    }

    // ───────────── v5.3 income audit ─────────────

    protected function r_ia_findings($from, $to): array
    {
        $checks = \App\Services\IncomeAuditService::CHECKS;
        $rows = \App\Models\AuditFinding::with('day', 'resolver')->where('status', '!=', 'cleared')
            ->whereHas('day', fn ($q) => $q->whereDate('business_date', '>=', $from->toDateString())->whereDate('business_date', '<=', $to->toDateString()))->get()
            ->sortBy(fn ($f) => [$f->day->business_date->toDateString(), array_search($f->check, array_keys($checks))])->values()
            ->map(fn ($f) => ['date' => dmy($f->day->business_date), 'check' => $checks[$f->check][0] ?? $f->check, 'sev' => $f->severity, 'room' => $f->room_no, 'ref' => $f->ref_label,
                'detail' => $f->detail, 'amount' => (float) $f->amount, 'status' => \App\Models\AuditFinding::STATUSES[$f->status], 'resolution' => $f->resolution, 'by' => $f->resolver?->name]);

        return ['cols' => ['date' => 'Date', 'check' => 'Check', 'sev' => 'Severity', 'room' => 'Room', 'ref' => 'Reference', 'detail' => 'Found', 'amount' => 'Value', 'status' => 'Status', 'resolution' => 'Resolution', 'by' => 'By'],
            'rows' => $rows, 'money' => ['amount'], 'sum' => ['amount'],
            'note' => $rows->count().' exception(s); '.$rows->whereIn('status', ['Open', 'Escalated'])->count().' still open or escalated. Exceptions put right before sign-off (cleared by a re-check) are not listed.'];
    }

    protected function r_ia_rate_variance($from, $to): array
    {
        $to = $to->copy()->min($from->copy()->addDays(30))->min(bdate()->copy()->subDay());
        $tol = (float) \App\Services\IncomeAuditService::cfg('ia_rate_tolerance_pct');
        $rows = collect();
        for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
            $date = $d->toDateString();
            $stays = \App\Services\IncomeAuditService::stayNights($date);
            $folioStay = \App\Models\Folio::whereIn('reservation_room_id', $stays->pluck('id'))->pluck('reservation_room_id', 'id');
            $posted = \App\Models\FolioTransaction::where('source_type', 'night')->where('reference', $date)->where('is_void', false)->where('category', 'room')
                ->whereIn('folio_id', $folioStay->keys())->get()->groupBy(fn ($t) => $folioStay[$t->folio_id]);
            foreach ($stays as $s) {
                $exp = \App\Services\IncomeAuditService::expectedRate($s, $date);
                $charged = isset($posted[$s->id]) ? (float) $posted[$s->id]->first()->amount : null;
                $var = $exp && $charged !== null ? round($charged - $exp['rate'], 2) : null;
                $auth = $var !== null && $var < 0 ? \App\Services\RevenueAuthService::match(['comp', 'house_use', 'rate'], $s->id, null, $date, (float) $charged) : null;
                $rows->push(['date' => dmy($date), 'room' => $s->room?->room_no, 'guest' => $s->guest?->full_name, 'res' => $s->reservation?->reservation_no, 'basis' => $exp['basis'] ?? 'channel rate – not checked',
                    'expected' => $exp['rate'] ?? null, 'charged' => $charged, 'variance' => $var, 'pct' => $var !== null && $exp['rate'] > 0 ? round($var / $exp['rate'] * 100, 1).'%' : '',
                    'flag' => $charged === null ? 'no tariff' : ($var !== null && $exp['rate'] > 0 && abs($var) / $exp['rate'] * 100 > $tol ? ($auth ? 'authorised '.$auth->auth_no : 'outside tolerance') : ''),
                ]);
            }
        }

        return ['cols' => ['date' => 'Night', 'room' => 'Room', 'guest' => 'Guest', 'res' => 'Reservation', 'basis' => 'Expected basis', 'expected' => 'Expected', 'charged' => 'Charged', 'variance' => 'Variance', 'pct' => '%', 'flag' => 'Flag'],
            'rows' => $rows, 'money' => ['expected', 'charged', 'variance'], 'sum' => ['expected', 'charged', 'variance'],
            'note' => 'Tolerance ±'.$tol.'% (Income Audit settings). Up to 31 nights, ending on the last closed business date. Expected rate: rate plan → group block → corporate contract → rack rate less the booking discount.'];
    }

    protected function r_ia_reconciliation($from, $to): array
    {
        $to = $to->copy()->min(bdate()->copy()->subDay());
        $days = \App\Models\AuditDay::with('signer', 'closer')->whereDate('business_date', '>=', $from->toDateString())->whereDate('business_date', '<=', $to->toDateString())->get()->keyBy(fn ($d) => $d->business_date->toDateString());
        $rows = collect();
        for ($d = $from->copy(); $d->lte($to) && $rows->count() < 92; $d->addDay()) {
            $date = $d->toDateString();
            $day = $days[$date] ?? null;
            $f = \App\Services\IncomeAuditService::figures($date);
            $base = $day?->figures['baseline'] ?? null;
            $rows->push(['date' => dmy($date), 'status' => $day ? \App\Models\AuditDay::STATUSES[$day->status] : 'not started',
                'na_total' => $f['reported'] ? round(array_sum($f['reported']) - $f['reported']['collections'], 2) : null,
                'room' => $f['now']['room'], 'other' => $f['now']['other'], 'tax' => $f['now']['tax'], 'coll' => $f['now']['collections'], 'disc' => $f['discounts'],
                'change' => $base ? round(($f['now']['room'] + $f['now']['other'] + $f['now']['tax']) - (($base['room'] ?? 0) + ($base['other'] ?? 0) + ($base['tax'] ?? 0)), 2) : null,
                'gl' => $f['gl_income'], 'open' => $day?->findings_open, 'signed' => $day?->signer?->name, 'closed' => $day?->closer?->name]);
        }

        return ['cols' => ['date' => 'Date', 'status' => 'Audit', 'na_total' => 'NA report (rev + tax)', 'room' => 'Room rev', 'other' => 'Other rev', 'tax' => 'Tax', 'coll' => 'Collections', 'disc' => 'Discounts',
            'change' => 'Changed since close', 'gl' => 'GL income', 'open' => 'Open exc.', 'signed' => 'Signed', 'closed' => 'Closed'],
            'rows' => $rows, 'money' => ['na_total', 'room', 'other', 'tax', 'coll', 'disc', 'change', 'gl'], 'sum' => ['room', 'other', 'tax', 'coll', 'disc', 'gl'],
            'note' => 'Revenue and collections recomputed now from folio postings and settled POS bills. "Changed since close" compares with the baseline taken when the day entered income audit – any figure there means back-dated postings or voids.'];
    }

    protected function r_ia_authorisations($from, $to): array
    {
        $rows = \App\Models\RevenueAuthorisation::with('stay.room', 'stay.guest', 'stay.reservation', 'folio', 'requester', 'decider')
            ->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])->orderBy('id')->get()
            ->map(fn ($a) => ['no' => $a->auth_no, 'date' => $a->created_at->format('d M Y'), 'kind' => \App\Models\RevenueAuthorisation::KINDS[$a->kind], 'for' => $a->subject(),
                'nights' => $a->date_from ? dmy($a->date_from, 'd M').' – '.dmy($a->date_to, 'd M').' ('.$a->nights().')' : '', 'value' => \App\Services\RevenueAuthService::value($a),
                'reason' => $a->reason, 'req' => $a->requester?->name, 'status' => $a->status === 'cancelled' ? 'withdrawn' : $a->status, 'dec' => $a->decider?->name, 'note' => $a->decision_note]);

        return ['cols' => ['no' => 'No.', 'date' => 'Requested', 'kind' => 'Type', 'for' => 'For', 'nights' => 'Nights', 'value' => 'Revenue given away', 'reason' => 'Reason', 'req' => 'Requested by', 'status' => 'Status', 'dec' => 'Decided by', 'note' => 'Note'],
            'rows' => $rows, 'money' => ['value'], 'sum' => ['value'],
            'note' => 'Approved: '.money($rows->where('status', 'approved')->sum('value')).' given away · '.$rows->where('status', 'pending')->count().' pending · '.$rows->where('status', 'declined')->count().' declined.'];
    }

    // ───────── v6.0 learning & development ─────────

    protected function r_training_compliance($from, $to): array
    {
        $m = \App\Services\LearningService::matrix();
        $rows = $m['employees']->map(function ($e) use ($m) {
            $r = $m['rows'][$e->id];
            $gaps = collect($m['cells'][$e->id] ?? [])->filter(fn ($c) => in_array($c['state'], ['overdue', 'expired'], true))->keys()
                ->map(fn ($cid) => $m['courses']->firstWhere('id', $cid)?->code)->implode(', ');

            return ['emp' => $e->name, 'code' => $e->emp_code, 'dept' => $e->department?->name ?? '—', 'job' => (string) $e->designation, 'required' => $r['required'], 'current' => $r['current'],
                'expiring' => $r['expiring'], 'expired' => $r['expired'], 'overdue' => $r['overdue'], 'due' => $r['due'], 'pct' => $r['pct'] === null ? '—' : $r['pct'].'%', 'gaps' => $gaps,
                '_class' => $r['expired'] + $r['overdue'] ? 'row-alert' : ''];
        })->values();
        $t = $m['totals'];

        return ['cols' => ['emp' => 'Employee', 'code' => 'Code', 'dept' => 'Department', 'job' => 'Job title', 'required' => 'Required', 'current' => 'Current', 'expiring' => 'Expiring',
            'expired' => 'Expired', 'overdue' => 'Overdue', 'due' => 'New-starter due', 'pct' => 'Compliance', 'gaps' => 'Missing / lapsed'],
            'rows' => $rows, 'sum' => ['required', 'current', 'expiring', 'expired', 'overdue', 'due'],
            'summary' => ['Staff' => (string) $rows->count(), 'Compliance' => $t['pct'] === null ? '—' : $t['pct'].'%', 'Overdue' => (string) $t['overdue'], 'Expired' => (string) $t['expired'],
                'Expiring' => (string) $t['expiring']],
            'note' => 'As at the business date '.bdate()->format('d M Y').'. Compliance = current + expiring ÷ required (new starters still inside their grace period are left out). Expiring = within '.\App\Services\LearningService::cfg()['expiry_warn_days'].' days.'];
    }

    protected function r_training_expiry($from, $to): array
    {
        $f = Carbon::parse($from)->toDateString();
        $t = Carbon::parse($to)->toDateString();
        $recs = \App\Models\TrainingRecord::with('course', 'employee.department')->whereIn('status', ['passed', 'exempt'])->whereNotNull('expires_on')->whereBetween('expires_on', [$f, $t])
            ->whereHas('employee', fn ($q) => $q->where('is_active', true))->orderBy('expires_on')->get();
        $booked = \App\Models\TrainingRecord::with('session')->where('status', 'enrolled')->whereHas('session', fn ($q) => $q->where('status', 'scheduled'))->get()
            ->mapWithKeys(fn ($r) => [$r->employee_id.'-'.$r->training_course_id => $r->session]);
        $rows = $recs->filter(function ($r) {   // only the latest certificate per employee & course (a renewal supersedes it)
            return ! \App\Models\TrainingRecord::where('employee_id', $r->employee_id)->where('training_course_id', $r->training_course_id)->whereIn('status', ['passed', 'exempt'])
                ->where(fn ($q) => $q->whereNull('expires_on')->orWhere('expires_on', '>', $r->expires_on->toDateString()))->exists();
        })->map(function ($r) use ($booked) {
            $s = $booked[$r->employee_id.'-'.$r->training_course_id] ?? null;
            $days = (int) bdate()->diffInDays($r->expires_on, false);

            return ['expires' => $r->expires_on->format('d M Y'), 'days' => $days, 'emp' => $r->employee->name, 'dept' => $r->employee->department?->name ?? '—', 'course' => $r->course->name,
                'cert' => (string) $r->certificate_no, 'completed' => $r->completed_on?->format('d M Y') ?? '', 'renewal' => $s ? $s->number.' '.$s->date->format('d M') : '',
                '_class' => $days < 0 ? 'row-alert' : ($s ? '' : 'row-warn')];
        })->values();

        return ['cols' => ['expires' => 'Expires', 'days' => 'Days left', 'emp' => 'Employee', 'dept' => 'Department', 'course' => 'Course', 'cert' => 'Certificate', 'completed' => 'Completed', 'renewal' => 'Renewal booked'],
            'rows' => $rows, 'summary' => ['Expiring' => (string) $rows->count(), 'Already expired' => (string) $rows->where('days', '<', 0)->count(), 'Renewal booked' => (string) $rows->where('renewal', '!=', '')->count(),
                'Not booked' => (string) $rows->where('renewal', '')->count()],
            'note' => 'Certificates of current staff with an expiry date in the range – the latest certificate per person and course only. Book a refresher before the expiry date to stay compliant.'];
    }

    // ───────── v6.1 Sales & marketing ─────────

    protected function r_sales_pipeline($from, $to): array
    {
        $O = \App\Models\SalesOpportunity::class;
        $opps = $O::with('company', 'owner')->whereIn('stage', $O::OPEN)->orderByRaw("FIELD(stage, 'negotiation', 'proposal', 'qualified', 'prospect')")->orderBy('expected_close')->get();
        $today = bdate()->copy()->startOfDay();
        $rows = collect();
        foreach ($O::OPEN as $s) {
            $g = $opps->where('stage', $s)->sortByDesc('stage')->values();
            foreach ($opps->where('stage', $s) as $o) {
                $rows->push(['no' => $o->number, 'name' => $o->name, 'account' => $o->company->name, 'type' => $o->typeLabel(), 'owner' => (string) $o->owner?->name, 'stage' => $o->stageLabel(),
                    'close' => $o->expected_close->format('d M Y'), 'rn' => (int) $o->room_nights, 'value' => $o->total(), 'prob' => $o->probability, 'weighted' => $o->weighted(),
                    '_class' => $o->expected_close->lt($today) ? 'row-warn' : '']);
            }
            if ($g->count()) {
                $rows->push(['no' => '', 'name' => $O::STAGES[$s][0].' total', 'account' => '', 'type' => '', 'owner' => '', 'stage' => '', 'close' => '', 'rn' => (int) $g->sum('room_nights'),
                    'value' => round($g->sum(fn ($o) => $o->total()), 2), 'prob' => '', 'weighted' => round($g->sum(fn ($o) => $o->weighted()), 2), '_class' => 'bold-row']);
            }
        }

        return ['cols' => ['no' => 'No.', 'name' => 'Opportunity', 'account' => 'Account', 'type' => 'Type', 'owner' => 'Owner', 'stage' => 'Stage', 'close' => 'Expected close', 'rn' => 'Room nights',
            'value' => 'Value', 'prob' => 'Prob. %', 'weighted' => 'Weighted'],
            'rows' => $rows, 'money' => ['value', 'weighted'],
            'summary' => ['Open deals' => (string) $opps->count(), 'Pipeline' => money($opps->sum(fn ($o) => $o->total())), 'Weighted' => money($opps->sum(fn ($o) => $o->weighted())),
                'Room nights' => number_format($opps->sum('room_nights')), 'Past close date' => (string) $opps->filter(fn ($o) => $o->expected_close->lt($today))->count()],
            'note' => 'Open opportunities as of today. Weighted = value × win probability. Highlighted rows are past their expected close date.'];
    }

    protected function r_sales_win_loss($from, $to): array
    {
        $O = \App\Models\SalesOpportunity::class;
        $opps = $O::with('owner')->whereIn('stage', ['won', 'lost'])->whereBetween('closed_on', [Carbon::parse($from)->toDateString(), Carbon::parse($to)->toDateString()])->get();
        $rows = collect();
        $line = function ($label, $g, $cls = '') {
            $w = $g->where('stage', 'won');
            $l = $g->where('stage', 'lost');

            return ['dim' => $label, 'won' => $w->count(), 'lost' => $l->count(), 'rate' => $g->count() ? round($w->count() * 100 / $g->count(), 1) : 0.0,
                'won_value' => round($w->sum(fn ($o) => $o->total()), 2), 'lost_value' => round($l->sum(fn ($o) => $o->total()), 2), 'rn' => (int) $w->sum('room_nights'), '_class' => $cls];
        };
        foreach (['type' => ['By type', $O::TYPES], 'source' => ['By source', $O::SOURCES]] as $k => [$title, $labels]) {
            $rows->push(['dim' => $title, 'won' => '', 'lost' => '', 'rate' => '', 'won_value' => '', 'lost_value' => '', 'rn' => '', '_class' => 'bold-row']);
            foreach ($opps->groupBy($k) as $v => $g) {
                $rows->push($line('   '.($labels[$v] ?? $v), $g));
            }
        }
        $rows->push(['dim' => 'By salesperson', 'won' => '', 'lost' => '', 'rate' => '', 'won_value' => '', 'lost_value' => '', 'rn' => '', '_class' => 'bold-row']);
        foreach ($opps->groupBy(fn ($o) => $o->owner?->name ?? '—') as $v => $g) {
            $rows->push($line('   '.$v, $g));
        }
        $rows->push(['dim' => 'Lost – reasons', 'won' => '', 'lost' => '', 'rate' => '', 'won_value' => '', 'lost_value' => '', 'rn' => '', '_class' => 'bold-row']);
        foreach ($opps->where('stage', 'lost')->groupBy('lost_reason') as $v => $g) {
            $rows->push(['dim' => '   '.($O::LOST_REASONS[$v] ?? $v), 'won' => '', 'lost' => $g->count(), 'rate' => '', 'won_value' => '', 'lost_value' => round($g->sum(fn ($o) => $o->total()), 2), 'rn' => '', '_class' => '']);
        }
        $rows->push($line('Total', $opps, 'bold-row'));
        $won = $opps->where('stage', 'won');

        return ['cols' => ['dim' => 'Breakdown', 'won' => 'Won', 'lost' => 'Lost', 'rate' => 'Win rate %', 'won_value' => 'Value won', 'lost_value' => 'Value lost', 'rn' => 'Room nights won'],
            'rows' => $rows, 'money' => ['won_value', 'lost_value'],
            'summary' => ['Decided' => (string) $opps->count(), 'Won' => (string) $won->count(), 'Win rate' => ($opps->count() ? round($won->count() * 100 / $opps->count(), 1) : 0).'%',
                'Value won' => money($won->sum(fn ($o) => $o->total())), 'Value lost' => money($opps->where('stage', 'lost')->sum(fn ($o) => $o->total()))],
            'note' => 'Opportunities closed (won or lost) in the period, by close date.'];
    }

    protected function r_sales_activity($from, $to): array
    {
        $A = \App\Models\SalesActivity::class;
        $acts = $A::with('owner', 'company')->where('status', 'done')->whereBetween('done_at', [Carbon::parse($from)->startOfDay(), Carbon::parse($to)->endOfDay()])->get();
        $rows = $acts->groupBy(fn ($a) => $a->owner?->name ?? '—')->sortKeys()->map(function ($g, $who) use ($A) {
            $r = ['who' => $who];
            foreach ($A::TYPES as $k => $x) {
                $r[$k] = $g->where('type', $k)->count();
            }

            return $r + ['total' => $g->count(), 'accounts' => $g->pluck('company_id')->filter()->unique()->count(), 'cost' => round((float) $g->sum('cost'), 2)];
        })->values();
        $cols = ['who' => 'Salesperson'];
        foreach ($A::TYPES as $k => [$l]) {
            $cols[$k] = $l;
        }

        return ['cols' => $cols + ['total' => 'Total', 'accounts' => 'Accounts touched', 'cost' => 'Spend'],
            'rows' => $rows, 'money' => ['cost'], 'sum' => array_merge(array_keys($A::TYPES), ['total', 'cost']),
            'summary' => ['Activities' => (string) $acts->count(), 'Sales visits' => (string) $acts->where('type', 'visit')->count(), 'Site inspections' => (string) $acts->where('type', 'site_inspection')->count(),
                'Accounts touched' => (string) $acts->pluck('company_id')->filter()->unique()->count(), 'Spend' => money($acts->sum('cost'))],
            'note' => 'Completed activities in the period, by completion date. Spend is the entertainment / travel cost recorded on each activity.'];
    }

    protected function r_account_production($from, $to): array
    {
        $F = Carbon::parse($from)->startOfDay();
        $T = Carbon::parse($to)->startOfDay();
        $now = \App\Services\SalesService::production($F->copy(), $T->copy());
        $ly = \App\Services\SalesService::production($F->copy()->subYear(), $T->copy()->subYear());
        $cos = \App\Models\Company::with('accountManager')->whereIn('id', $now->keys()->merge($ly->keys())->unique()->all() ?: [0])->get();
        $rows = $cos->map(function ($c) use ($now, $ly) {
            $n = $now[$c->id] ?? ['stays' => 0, 'nights' => 0, 'revenue' => 0, 'otb_nights' => 0, 'otb_revenue' => 0];
            $l = $ly[$c->id] ?? ['nights' => 0, 'revenue' => 0];

            return ['account' => $c->name, 'type' => $c->typeLabel(), 'tier' => $c->tierLabel(), 'mgr' => (string) $c->accountManager?->name, 'stays' => $n['stays'], 'rn' => $n['nights'],
                'rev' => (float) $n['revenue'], 'adr' => $n['nights'] ? round($n['revenue'] / $n['nights'], 2) : 0.0, 'otb' => $n['otb_nights'], 'otb_rev' => (float) $n['otb_revenue'],
                'ly_rn' => $l['nights'], 'ly_rev' => (float) $l['revenue'], 'var' => $l['revenue'] > 0 ? round(($n['revenue'] - $l['revenue']) * 100 / $l['revenue'], 1) : '',
                '_class' => $l['revenue'] > 0 && $n['revenue'] < $l['revenue'] * 0.8 ? 'row-warn' : ''];
        })->sortByDesc('rev')->values();

        return ['cols' => ['account' => 'Account', 'type' => 'Type', 'tier' => 'Tier', 'mgr' => 'Account manager', 'stays' => 'Stays', 'rn' => 'Room nights', 'rev' => 'Room revenue', 'adr' => 'ADR',
            'otb' => 'OTB nights', 'otb_rev' => 'OTB revenue', 'ly_rn' => 'LY nights', 'ly_rev' => 'LY revenue', 'var' => 'Revenue vs LY %'],
            'rows' => $rows, 'money' => ['rev', 'adr', 'otb_rev', 'ly_rev'], 'sum' => ['stays', 'rn', 'rev', 'otb', 'otb_rev', 'ly_rn', 'ly_rev'],
            'summary' => ['Accounts' => (string) $rows->count(), 'Room nights' => number_format($rows->sum('rn')), 'Room revenue' => money($rows->sum('rev')), 'Last year' => money($rows->sum('ly_rev')),
                'On the books' => number_format($rows->sum('otb')).' RN'],
            'note' => 'Stays linked to a company, overlapping the period (checked in / out). Room revenue = room charges posted to their folios in the period. OTB = reserved stays from the business date on at the booked rate. Highlighted = more than 20% below last year.'];
    }

    protected function r_sales_targets($from, $to): array
    {
        $rows = collect();
        for ($m = Carbon::parse($from)->startOfMonth(); $m->lte(Carbon::parse($to)); $m->addMonthNoOverflow()) {
            foreach (\App\Services\SalesService::targets($m->format('Y-m')) as $r) {
                $t = $r['target'];
                $rows->push(['month' => $m->format('M Y'), 'who' => $r['user']->name, 'accounts' => $r['accounts'], 'rn_t' => (int) ($t?->room_nights), 'rn' => $r['nights'] + $r['otb_nights'],
                    'rn_pct' => $r['pct_nights'] ?? '', 'rev_t' => (float) ($t?->revenue), 'rev' => round($r['revenue'] + $r['otb_revenue'], 2), 'rev_pct' => $r['pct_revenue'] ?? '',
                    'act_t' => (int) ($t?->activities), 'act' => $r['activities'], 'won' => $r['won'], '_class' => $r['pct_revenue'] !== null && $r['pct_revenue'] < 75 ? 'row-warn' : '']);
            }
        }

        return ['cols' => ['month' => 'Month', 'who' => 'Salesperson', 'accounts' => 'Accounts', 'rn_t' => 'RN target', 'rn' => 'RN actual + OTB', 'rn_pct' => 'RN %', 'rev_t' => 'Revenue target',
            'rev' => 'Revenue actual + OTB', 'rev_pct' => 'Revenue %', 'act_t' => 'Activity target', 'act' => 'Activities', 'won' => 'Business won'],
            'rows' => $rows, 'money' => ['rev_t', 'rev', 'won'], 'sum' => ['rn_t', 'rn', 'rev_t', 'rev', 'act_t', 'act', 'won'],
            'summary' => ['Revenue target' => money($rows->sum('rev_t')), 'Actual + OTB' => money($rows->sum('rev')), 'Attainment' => $rows->sum('rev_t') > 0 ? round($rows->sum('rev') * 100 / $rows->sum('rev_t'), 1).'%' : '—'],
            'note' => 'Production is credited to the account manager of each account. Current and future months include reserved business on the books. Highlighted = below 75% of the revenue target.'];
    }

    protected function r_marketing_roi($from, $to): array
    {
        $M = \App\Models\MarketingEvent::class;
        $events = $M::with('owner')->whereDate('start_date', '>=', Carbon::parse($from)->toDateString())->whereDate('start_date', '<=', Carbon::parse($to)->toDateString())->orderBy('start_date')->get();
        $roi = \App\Services\MarketingPlanService::roi($events);
        $rows = $events->map(fn ($e) => ['no' => $e->number, 'name' => $e->name, 'type' => $e->typeLabel(), 'dates' => $e->start_date->format('d M Y'), 'segment' => $M::SEGMENTS[$e->segment] ?? '',
            'market' => $M::MARKETS[$e->market] ?? '', 'status' => $e->statusLabel(), 'budget' => (float) $e->budget, 'cost' => in_array($e->status, ['done', 'cancelled']) ? (float) $e->actual_cost : 0.0,
            'leads' => $e->leads, 'cpl' => $roi[$e->id]['cpl'] ?? '', 'opps' => $roi[$e->id]['opps'], 'won' => $roi[$e->id]['won_value'], 'roi' => $roi[$e->id]['roi'] ?? '',
            '_class' => $e->status === 'cancelled' ? 'row-alert' : ''])->values();
        $cost = $rows->sum('cost');

        return ['cols' => ['no' => 'No.', 'name' => 'Event', 'type' => 'Type', 'dates' => 'Starts', 'segment' => 'Segment', 'market' => 'Market', 'status' => 'Status', 'budget' => 'Budget', 'cost' => 'Actual cost',
            'leads' => 'Leads', 'cpl' => 'Cost / lead', 'opps' => 'Opportunities', 'won' => 'Revenue won', 'roi' => 'ROI %'],
            'rows' => $rows, 'money' => ['budget', 'cost', 'cpl', 'won'], 'sum' => ['budget', 'cost', 'leads', 'opps', 'won'],
            'summary' => ['Events' => (string) $rows->count(), 'Spent' => money($cost), 'Leads' => (string) $rows->sum('leads'), 'Revenue won' => money($rows->sum('won')),
                'Overall ROI' => $cost > 0 ? round(($rows->sum('won') - $cost) * 100 / $cost, 1).'%' : '—'],
            'note' => 'Revenue won = opportunities whose source marketing event is this event and that are won. ROI = (revenue won − actual cost) ÷ actual cost, for closed events.'];
    }

    protected function r_marketing_budget($from, $to): array
    {
        $F = Carbon::parse($from)->startOfMonth();
        $T = Carbon::parse($to);
        $acc = [];
        for ($m = $F->copy(); $m->lte($T); $m->addMonthNoOverflow()) {
            $bva = $acc[$m->year] ??= \App\Services\MarketingPlanService::budgetVsActual($m->year);
        }
        $rows = collect(\App\Models\MarketingEvent::budgetLines())->map(function ($label, $k) use ($F, $T, $acc) {
            $b = $a = $c = 0.0;
            for ($m = $F->copy(); $m->lte($T); $m->addMonthNoOverflow()) {
                $r = $acc[$m->year][$k];
                $b += $r['budget'][$m->month];
                $a += $r['actual'][$m->month];
                $c += $r['committed'][$m->month];
            }

            return ['line' => $label, 'budget' => round($b, 2), 'actual' => round($a, 2), 'committed' => round($c, 2), 'var' => round($b - $a, 2), 'pct' => $b > 0 ? round($a * 100 / $b, 1) : '',
                '_class' => $b > 0 && $a > $b ? 'row-alert' : ''];
        })->filter(fn ($r) => $r['budget'] || $r['actual'] || $r['committed'])->values();

        return ['cols' => ['line' => 'Budget line', 'budget' => 'Budget', 'actual' => 'Actual', 'committed' => 'Committed', 'var' => 'Variance', 'pct' => '% used'],
            'rows' => $rows, 'money' => ['budget', 'actual', 'committed', 'var'], 'sum' => ['budget', 'actual', 'committed', 'var'],
            'summary' => ['Budget' => money($rows->sum('budget')), 'Actual' => money($rows->sum('actual')), 'Committed' => money($rows->sum('committed')), 'Remaining' => money($rows->sum('budget') - $rows->sum('actual') - $rows->sum('committed'))],
            'note' => 'Whole months in the period. Actual = closed / cancelled marketing events (by start month), client entertainment on completed sales activities, digital spend. Committed = planned / confirmed events not yet closed.'];
    }

    // ---------------------------------------------------------------- v6.2 digital marketing

    protected function r_digital_social($from, $to): array
    {
        $F = Carbon::parse($from)->startOfMonth();
        $T = Carbon::parse($to)->startOfMonth();
        $rows = \App\Models\DmChannel::orderBy('name')->get()->filter(fn ($c) => $c->kind() === 'social')->map(function ($c) use ($F, $T) {
            $st = \App\Models\DmChannelStat::where('channel_id', $c->id)->whereBetween('month', [$F->toDateString(), $T->toDateString()])->orderBy('month')->get();
            $before = \App\Models\DmChannelStat::where('channel_id', $c->id)->where('month', '<', $F->toDateString())->orderByDesc('month')->value('followers');
            $start = $before ?? $st->first()?->followers;
            $end = $st->last()?->followers;
            $posts = \App\Models\DmPost::whereJsonContains('channels', $c->id)->where('status', 'published')->whereBetween('published_at', [$F, $T->copy()->endOfMonth()])->count();

            return ['channel' => $c->name, 'platform' => $c->platformLabel(), 'start' => $start ?? '', 'end' => $end ?? '', 'growth' => $start && $end !== null ? $end - $start : null,
                'growth_pct' => $start && $end !== null ? round(100 * ($end - $start) / $start, 1) : '', 'reach' => (int) $st->sum('reach'), 'impressions' => (int) $st->sum('impressions'),
                'engagements' => (int) $st->sum('engagements'), 'er' => $st->sum('reach') ? round(100 * $st->sum('engagements') / $st->sum('reach'), 2) : '', 'clicks' => (int) $st->sum('clicks'), 'posts' => $posts,
                '_class' => $st->isEmpty() ? 'row-alert' : ''];
        })->values();

        return ['cols' => ['channel' => 'Channel', 'platform' => 'Platform', 'start' => 'Followers at start', 'end' => 'Followers at end', 'growth' => 'Growth', 'growth_pct' => 'Growth %',
            'reach' => 'Reach', 'impressions' => 'Impressions', 'engagements' => 'Engagements', 'er' => 'Engagement %', 'clicks' => 'Clicks', 'posts' => 'Posts published'],
            'rows' => $rows, 'sum' => ['growth', 'reach', 'impressions', 'engagements', 'clicks', 'posts'],
            'summary' => ['Channels' => (string) $rows->count(), 'Followers now' => number_format((int) $rows->sum(fn ($r) => (int) $r['end'])), 'Reach' => number_format($rows->sum('reach')),
                'Engagement rate' => $rows->sum('reach') ? round(100 * $rows->sum('engagements') / $rows->sum('reach'), 2).'%' : '—'],
            'note' => 'Monthly figures as recorded from each platform\'s insights for the months in the period. Highlighted channels have no figures for the period.'];
    }

    protected function r_digital_paid($from, $to): array
    {
        $F = Carbon::parse($from);
        $T = Carbon::parse($to);
        $C = \App\Models\DmCampaign::class;
        $rows = $C::with('channel')->whereDate('start_date', '<=', $T->toDateString())->where(fn ($q) => $q->whereNull('end_date')->whereDate('end_date', '>=', $F->toDateString()))
            ->where('status', '!=', 'draft')->orderBy('start_date')->get()->map(function ($c) use ($F, $T, $C) {
                $s = \App\Services\DigitalMarketingService::campaignStats($c, $F, $T);

                return ['no' => $c->number, 'name' => $c->name, 'platform' => $c->channel?->platformLabel() ?? '', 'objective' => $C::OBJECTIVES[$c->objective], 'status' => $c->statusLabel(),
                    'budget' => (float) $c->budget, 'spend' => $s['spend'], 'impressions' => $s['impressions'], 'clicks' => $s['clicks'], 'ctr' => $s['ctr'] ?? '', 'cpc' => $s['cpc'] ?? '',
                    'conv' => $s['conversions'], 'value' => $s['conversion_value'], 'roas' => $s['roas'] ?? '', 'bookings' => $s['bookings'], 'revenue' => $s['revenue'], 'pms_roas' => $s['pms_roas'] ?? '',
                    '_class' => $c->target_roas && $s['pms_roas'] !== null && $s['pms_roas'] < (float) $c->target_roas ? 'row-alert' : ''];
            })->values();
        $spend = $rows->sum('spend');

        return ['cols' => ['no' => 'No.', 'name' => 'Campaign', 'platform' => 'Platform', 'objective' => 'Objective', 'status' => 'Status', 'budget' => 'Budget', 'spend' => 'Spend', 'impressions' => 'Impressions',
            'clicks' => 'Clicks', 'ctr' => 'CTR %', 'cpc' => 'CPC', 'conv' => 'Platform conv.', 'value' => 'Platform value', 'roas' => 'Platform ROAS', 'bookings' => 'PMS bookings', 'revenue' => 'PMS revenue', 'pms_roas' => 'PMS ROAS'],
            'rows' => $rows, 'money' => ['budget', 'spend', 'cpc', 'value', 'revenue'], 'sum' => ['budget', 'spend', 'impressions', 'clicks', 'conv', 'value', 'bookings', 'revenue'],
            'summary' => ['Spend' => money($spend), 'Clicks' => number_format($rows->sum('clicks')), 'PMS bookings' => (string) $rows->sum('bookings'), 'PMS revenue' => money($rows->sum('revenue')),
                'Blended ROAS' => $spend > 0 ? round($rows->sum('revenue') / $spend, 2).'×' : '—'],
            'note' => 'Spend and delivery for the days in the period. PMS bookings / revenue = booking-engine bookings made in the period through the campaign\'s tracked links (room rate × nights, cancelled excluded). Highlighted: PMS ROAS below the campaign\'s target.'];
    }

    protected function r_digital_links($from, $to): array
    {
        $F = Carbon::parse($from);
        $T = Carbon::parse($to);
        $rows = \App\Models\DmLink::with('campaign')->orderBy('name')->get()->map(function ($l) use ($F, $T) {
            $s = \App\Services\DigitalMarketingService::linkStats($l, $F, $T);

            return ['code' => '/go/'.$l->code, 'name' => $l->name, 'source' => $l->utm_source, 'medium' => $l->utm_medium, 'campaign' => $l->utm_campaign, 'clicks' => $s['clicks'], 'unique' => $s['unique'],
                'mobile' => $s['clicks'] ? round(100 * ($s['devices']['mobile'] ?? 0) / $s['clicks']) : '', 'bookings' => $s['bookings'], 'nights' => $s['nights'], 'revenue' => $s['revenue'], 'conv' => $s['conversion'] ?? ''];
        })->filter(fn ($r) => $r['clicks'] || $r['bookings'])->sortByDesc('revenue')->values();
        $bySource = $rows->groupBy(fn ($r) => $r['source'].' / '.$r['medium'])->map(fn ($g) => money($g->sum('revenue')))->sortDesc()->take(3)->all();

        return ['cols' => ['code' => 'Link', 'name' => 'Name', 'source' => 'utm_source', 'medium' => 'utm_medium', 'campaign' => 'utm_campaign', 'clicks' => 'Clicks', 'unique' => 'Unique',
            'mobile' => 'Mobile %', 'bookings' => 'Bookings', 'nights' => 'Nights', 'revenue' => 'Room revenue', 'conv' => 'Conversion %'],
            'rows' => $rows, 'money' => ['revenue'], 'sum' => ['clicks', 'unique', 'bookings', 'nights', 'revenue'],
            'summary' => ['Clicks' => number_format($rows->sum('clicks')), 'Bookings' => (string) $rows->sum('bookings'), 'Room revenue' => money($rows->sum('revenue'))] + array_combine(array_map(fn ($k) => 'Top: '.$k, array_keys($bySource)), array_values($bySource)),
            'note' => 'Clicks on tracked links in the period (bots and repeat clicks within 30 s excluded) and booking-engine bookings made in the period that were credited to each link.'];
    }

    protected function r_digital_content($from, $to): array
    {
        $P = \App\Models\DmPost::class;
        $F = Carbon::parse($from)->startOfDay();
        $T = Carbon::parse($to)->endOfDay();
        $names = \App\Models\DmChannel::pluck('name', 'id');
        $rows = $P::with('author', 'reviewer')->whereBetween('scheduled_at', [$F, $T])->where('status', '!=', 'cancelled')->orderBy('scheduled_at')->get()->map(fn ($p) => [
            'slot' => $p->scheduled_at->format('d M Y H:i'), 'title' => $p->title, 'channels' => collect((array) $p->channels)->map(fn ($id) => $names[$id] ?? '')->implode(', '), 'pillar' => $P::PILLARS[$p->pillar] ?? '',
            'status' => $p->isMissed() ? 'Missed slot' : $p->statusLabel(), 'author' => $p->author?->name, 'approver' => $p->reviewer?->name ?? '',
            'turnaround' => $p->submitted_at && $p->reviewed_at ? round($p->submitted_at->diffInMinutes($p->reviewed_at) / 60, 1) : '',
            'reach' => $p->status === 'published' ? $p->reach : null, 'eng' => $p->status === 'published' ? $p->engagements : null, 'er' => $p->status === 'published' ? ($p->engagementRate() ?? '') : '',
            'clicks' => $p->status === 'published' ? $p->clicks : null, '_class' => $p->isMissed() ? 'row-alert' : ''])->values();
        $pub = $rows->where('status', 'Published');

        return ['cols' => ['slot' => 'Slot', 'title' => 'Post', 'channels' => 'Channels', 'pillar' => 'Pillar', 'status' => 'Status', 'author' => 'Author', 'approver' => 'Approved by', 'turnaround' => 'Approval (h)',
            'reach' => 'Reach', 'eng' => 'Engagements', 'er' => 'Eng. %', 'clicks' => 'Clicks'],
            'rows' => $rows, 'sum' => ['reach', 'eng', 'clicks'],
            'summary' => ['Posts' => (string) $rows->count(), 'Published' => (string) $pub->count(), 'Missed slots' => (string) $rows->where('status', 'Missed slot')->count(),
                'Avg approval' => $rows->where('turnaround', '!==', '')->count() ? round($rows->where('turnaround', '!==', '')->avg('turnaround'), 1).' h' : '—',
                'Engagement rate' => $pub->sum('reach') ? round(100 * $pub->sum('eng') / $pub->sum('reach'), 2).'%' : '—'],
            'note' => 'Posts scheduled in the period (cancelled excluded). Approval time = sent for approval → decision. Missed = approved but not marked published an hour after the slot.'];
    }

    protected function r_digital_influencers($from, $to): array
    {
        $I = \App\Models\DmInfluencer::class;
        $F = Carbon::parse($from)->toDateString();
        $T = Carbon::parse($to)->toDateString();
        $rows = $I::where(fn ($q) => $q->whereBetween('stay_from', [$F, $T])->orWhere(fn ($w) => $w->whereNull('stay_from')->whereBetween('created_at', [$F.' 00:00:00', $T.' 23:59:59'])))
            ->orderBy('stay_from')->get()->map(function ($i) use ($I) {
                $s = \App\Services\DigitalMarketingService::influencerStats($i);

                return ['no' => $i->number, 'name' => $i->name, 'handle' => $i->handle, 'platform' => \App\Models\DmChannel::PLATFORMS[$i->platform][0] ?? $i->platform, 'followers' => $i->followers,
                    'stay' => $i->stay_from?->format('d M Y') ?? '', 'status' => $i->isOverdue() ? 'Content overdue' : $i->statusLabel(), 'fee' => (float) $i->fee, 'comp' => (float) $i->comp_value, 'cost' => $s['cost'],
                    'reach' => $i->reach, 'eng' => $i->engagements, 'cpe' => $s['cpe'] ?? '', 'bookings' => $s['bookings'], 'revenue' => $s['revenue'], 'rating' => $i->rating ?? '',
                    '_class' => $i->isOverdue() ? 'row-alert' : ''];
            })->values();

        return ['cols' => ['no' => 'No.', 'name' => 'Creator', 'handle' => 'Handle', 'platform' => 'Platform', 'followers' => 'Followers', 'stay' => 'Stay', 'status' => 'Status', 'fee' => 'Fee', 'comp' => 'Comp value',
            'cost' => 'Total cost', 'reach' => 'Reach', 'eng' => 'Engagements', 'cpe' => 'Cost / eng.', 'bookings' => 'Bookings', 'revenue' => 'Revenue', 'rating' => 'Rating'],
            'rows' => $rows, 'money' => ['fee', 'comp', 'cost', 'cpe', 'revenue'], 'sum' => ['fee', 'comp', 'cost', 'reach', 'eng', 'bookings', 'revenue'],
            'summary' => ['Collaborations' => (string) $rows->count(), 'Total cost' => money($rows->sum('cost')), 'Reach' => number_format($rows->sum('reach')), 'Revenue via links' => money($rows->sum('revenue'))],
            'note' => 'Collaborations with a stay in the period (or proposed in the period without dates). Cost = cash fee + complimentary value. Revenue = bookings through the creator\'s tracked link.'];
    }

    protected function r_bd_benchmark($from, $to): array
    {
        $BD = \App\Services\BusinessDevService::class;
        $m = Carbon::parse($from)->startOfMonth();
        $end = Carbon::parse($to)->startOfMonth()->min(bdate()->copy()->startOfMonth()->subMonthNoOverflow());
        $rows = collect();
        for (; $m->lte($end); $m->addMonthNoOverflow()) {
            $x = $BD::indices($m->copy());
            $rows->push(['month' => $m->format('M Y'), 'occ' => round($x['own']['occupancy'], 1), 'adr' => round($x['own']['adr'], 2), 'revpar' => round($x['own']['revpar'], 2),
                'c_occ' => $x['comp'] ? round($x['comp']['occupancy'], 1) : null, 'c_adr' => $x['comp']['adr'] ?? null, 'c_revpar' => $x['comp']['revpar'] ?? null,
                'mpi' => $x['mpi'] ?? '', 'ari' => $x['ari'] ?? '', 'rgi' => $x['rgi'] ?? '',
                'src' => $x['benchmark'] ? (\App\Models\BdBenchmark::SOURCES[$x['benchmark']->source] ?? '').($x['own']['source'] === 'report' ? ' (own figures from report)' : '') : 'No benchmark',
                '_class' => $x['rgi'] !== null && $x['rgi'] < 95 ? 'row-amber' : '']);
        }
        $with = $rows->filter(fn ($r) => $r['rgi'] !== '');
        $avg = fn ($k) => $with->count() ? round($with->avg($k), 1) : '—';

        return ['cols' => ['month' => 'Month', 'occ' => 'Occ % (us)', 'adr' => 'ADR (us)', 'revpar' => 'RevPAR (us)', 'c_occ' => 'Occ % (comp)', 'c_adr' => 'ADR (comp)', 'c_revpar' => 'RevPAR (comp)',
            'mpi' => 'MPI', 'ari' => 'ARI', 'rgi' => 'RGI', 'src' => 'Source'],
            'rows' => $rows, 'money' => ['adr', 'revpar', 'c_adr', 'c_revpar'],
            'summary' => ['Months benchmarked' => $with->count().' / '.$rows->count(), 'Average MPI' => (string) $avg('mpi'), 'Average ARI' => (string) $avg('ari'), 'Average RGI' => (string) $avg('rgi')],
            'note' => 'Completed months only. MPI = our occupancy ÷ comp-set occupancy × 100, ARI = ADR index, RGI = RevPAR index (100 = fair share). Months with an RGI below 95 are highlighted. Our figures come from the report’s “My Property” line when entered, otherwise from the PMS closed days.'];
    }

    protected function r_bd_rate_shop($from, $to): array
    {
        $CH = \App\Models\BdRateShop::CHANNELS;
        $shops = \App\Models\BdRateShop::with('lines.competitor')->whereDate('stay_date', '>=', Carbon::parse($from)->toDateString())->whereDate('stay_date', '<=', Carbon::parse($to)->toDateString())->orderBy('stay_date')->orderBy('channel')->get();
        $rows = $shops->map(function ($s) use ($CH) {
            $rated = $s->lines->where('sold_out', false)->whereNotNull('rate');
            $avg = $s->compAverage();
            $diff = $avg && $s->own_rate !== null ? round(100 * ((float) $s->own_rate - $avg) / $avg, 1) : '';
            $cheap = $rated->sortBy('rate')->first();

            return ['date' => $s->stay_date->format('D d M Y'), 'channel' => $CH[$s->channel] ?? $s->channel, 'own' => $s->own_sold_out ? null : $s->own_rate, 'avg' => $avg !== null ? round($avg, 2) : null,
                'diff' => $diff, 'rank' => ($r = $s->ownRank()) ? $r.' / '.($rated->count() + 1) : '', 'min' => $cheap ? (float) $cheap->rate : null, 'min_by' => $cheap?->competitor?->name ?? '',
                'max' => $rated->count() ? (float) $rated->max('rate') : null, 'sold' => $s->lines->where('sold_out', true)->count() ?: '', 'shopped' => $s->shopped_on->format('d M'),
                '_class' => $diff !== '' && abs($diff) > 25 ? 'row-amber' : ''];
        });
        $d = $rows->filter(fn ($r) => $r['diff'] !== '');

        return ['cols' => ['date' => 'Stay date', 'channel' => 'Channel', 'own' => 'Our rate', 'avg' => 'Comp average', 'diff' => 'Us vs avg %', 'rank' => 'Price rank', 'min' => 'Lowest comp',
            'min_by' => 'Lowest by', 'max' => 'Highest comp', 'sold' => 'Comps sold out', 'shopped' => 'Shopped'],
            'rows' => $rows, 'money' => ['own', 'avg', 'min', 'max'],
            'summary' => ['Shops' => (string) $rows->count(), 'Average position' => $d->count() ? (($p = round($d->avg('diff'), 1)) > 0 ? '+' : '').$p.'% vs comp set' : '—',
                'Days we are cheaper' => (string) $d->filter(fn ($r) => $r['diff'] < 0)->count(), 'Days we are dearer' => (string) $d->filter(fn ($r) => $r['diff'] > 0)->count()],
            'note' => 'Public rates of the competitive set by stay date (lowest bookable rate, 1 night, 2 adults). Price rank 1 = the dearest hotel of the set. Gaps of more than 25% to the comp-set average are highlighted.'];
    }

    protected function r_bd_initiatives($from, $to): array
    {
        $I = \App\Models\BdInitiative::class;
        $rows = $I::with('owner', 'milestones', 'results')->orderByRaw("FIELD(stage, 'live', 'implementation', 'approved', 'business_case', 'assessment', 'idea', 'closed', 'rejected')")->orderByDesc('annual_revenue')->get()
            ->map(function ($i) use ($I) {
                $s = \App\Services\BusinessDevService::initiativeStats($i);

                return ['no' => $i->number, 'name' => $i->name, 'category' => $I::CATEGORIES[$i->category] ?? '', 'stage' => $i->stageLabel(), 'owner' => $i->owner?->name, 'investment' => (float) $i->investment,
                    'revenue' => (float) $i->annual_revenue, 'net' => $s['net'], 'payback' => $s['payback_months'] ?? '', 'npv' => $s['npv'], 'actual' => $s['months'] ? $s['actual_net'] : null,
                    'vs' => $s['achievement'] ?? '', 'ms' => $s['milestones'] ? $s['milestones_done'].'/'.$s['milestones'].($s['milestones_overdue'] ? ' ('.$s['milestones_overdue'].' late)' : '') : '',
                    '_class' => $s['milestones_overdue'] ? 'row-amber' : ''];
            });
        $committed = $rows->filter(fn ($r) => in_array($r['stage'], ['Approved', 'Implementation', 'Live'], true));

        return ['cols' => ['no' => 'No.', 'name' => 'Initiative', 'category' => 'Category', 'stage' => 'Stage', 'owner' => 'Owner', 'investment' => 'Investment', 'revenue' => 'Annual revenue',
            'net' => 'Annual net', 'payback' => 'Payback (months)', 'npv' => 'NPV', 'actual' => 'Actual net to date', 'vs' => 'vs plan %', 'ms' => 'Milestones'],
            'rows' => $rows, 'money' => ['investment', 'revenue', 'net', 'npv', 'actual'], 'sum' => ['investment', 'revenue', 'net', 'npv', 'actual'],
            'summary' => ['Initiatives' => (string) $rows->count(), 'Committed annual revenue' => money($committed->sum('revenue')), 'Committed investment' => money($committed->sum('investment')),
                'Awaiting approval' => (string) $rows->where('stage', 'Awaiting approval')->count()],
            'note' => 'NPV at the '.\App\Services\BusinessDevService::cfg()['discount_rate'].'% hurdle rate over each initiative’s horizon. Actual net = results recorded since going live; vs plan = against the business case pro rata. Rows with overdue milestones are highlighted.'];
    }

    protected function r_bd_partners($from, $to): array
    {
        $BD = \App\Services\BusinessDevService::class;
        $P = \App\Models\BdPartner::class;
        $F = Carbon::parse($from)->startOfDay();
        $T = Carbon::parse($to)->startOfDay();
        $rows = $P::with('owner', 'source', 'promo', 'company')->where('status', '!=', 'prospect')->orderBy('name')->get()->map(function ($p) use ($BD, $F, $T, $P) {
            $v = $BD::partnerProduction($p, $F, $T);
            $flags = collect($BD::partnerFlags($p))->pluck(1)->implode('; ');

            return ['no' => $p->number, 'name' => $p->name, 'type' => $p->typeLabel(), 'agreement' => $P::AGREEMENTS[$p->agreement] ?? '', 'status' => $p->statusLabel(),
                'end' => $p->end_date?->format('d M Y') ?? '', 'bookings' => $v['bookings'], 'nights' => $v['nights'], 'revenue' => $v['revenue'], 'commission' => $v['commission'], 'net' => $v['net'],
                'flags' => $flags, '_class' => str_contains($flags, 'Past its end') ? 'row-red' : ($flags !== '' ? 'row-amber' : '')];
        })->sortByDesc('revenue')->values();

        return ['cols' => ['no' => 'No.', 'name' => 'Partner', 'type' => 'Type', 'agreement' => 'Agreement', 'status' => 'Status', 'end' => 'Ends', 'bookings' => 'Bookings', 'nights' => 'Nights',
            'revenue' => 'Room revenue', 'commission' => 'Commission', 'net' => 'Net revenue', 'flags' => 'Attention'],
            'rows' => $rows, 'money' => ['revenue', 'commission', 'net'], 'sum' => ['bookings', 'nights', 'revenue', 'commission', 'net'],
            'summary' => ['Active partnerships' => (string) $rows->where('status', 'Active')->count(), 'Room revenue' => money($rows->sum('revenue')), 'Commission' => money($rows->sum('commission')),
                'Needing attention' => (string) $rows->filter(fn ($r) => $r['flags'] !== '')->count()],
            'note' => 'Stays arriving in the period (cancelled excluded) credited to each partnership through its business source, promo code or company; room revenue = booked rate × nights; commission at the agreed %. Prospects are left out.'];
    }

    protected function r_bd_market_mix($from, $to): array
    {
        $BD = \App\Services\BusinessDevService::class;
        $F = Carbon::parse($from)->startOfDay();
        $T = Carbon::parse($to)->startOfDay();
        $mk = fn ($by, $label) => $BD::marketMix($F, $T, $by)->map(fn ($x) => ['dim' => $label, 'key' => $x['label'], 'bookings' => $x['bookings'], 'nights' => $x['nights'], 'adr' => $x['adr'],
            'revenue' => $x['revenue'], 'share' => $x['share'], 'ly_nights' => $x['ly_nights'], 'ly_revenue' => $x['ly_revenue'], 'growth' => $x['growth'] ?? ($x['revenue'] > 0 ? 'new' : 'lost')]);
        $nat = $mk('nationality', 'Source market');
        $seg = $mk('segment', 'Segment');
        $tot = $nat->sum('revenue');
        $ly = $nat->sum('ly_revenue');

        return ['cols' => ['dim' => 'View', 'key' => 'Market / segment', 'bookings' => 'Bookings', 'nights' => 'Nights', 'adr' => 'ADR', 'revenue' => 'Room revenue', 'share' => 'Share %',
            'ly_nights' => 'LY nights', 'ly_revenue' => 'LY revenue', 'growth' => 'Growth %'],
            'rows' => $nat->concat($seg)->values(), 'money' => ['adr', 'revenue', 'ly_revenue'],
            'summary' => ['Room revenue' => money($tot), 'Last year' => money($ly), 'Growth' => $ly > 0 ? round(100 * ($tot - $ly) / $ly, 1).'%' : '—', 'Top market' => (string) ($nat->first()['key'] ?? '—')],
            'note' => 'Stays arriving in the period by guest nationality and by segment (each view totals 100%), cancelled excluded; room revenue = booked rate × nights; last year = the same dates a year earlier.'];
    }

    // ------------------------------------------------------------------ v6.4 central reservations

    protected function r_cro_enquiries($from, $to): array
    {
        $E = \App\Models\CroEnquiry::class;
        $rows = $E::with('agent', 'roomType', 'reservation', 'referredHotel', 'referredFrom', 'competitor')
            ->whereBetween('created_at', [Carbon::parse($from)->startOfDay(), Carbon::parse($to)->endOfDay()])->orderBy('created_at')->get()
            ->map(fn ($e) => ['no' => $e->number, 'at' => $e->created_at->format('d M Y H:i'), 'channel' => $e->channelLabel(), 'caller' => $e->caller_name,
                'stay' => $e->arrival_date->format('d M').' – '.$e->departure_date->format('d M Y'), 'rn' => $e->roomNights(), 'type' => $e->roomType?->name ?? 'Any',
                'value' => (float) ($e->booked_value ?? $e->quoted_total ?? 0) ?: null, 'outcome' => $e->statusLabel().($e->loss_type ? ' – '.$E::LOSS_TYPES[$e->loss_type][0] : ''),
                'detail' => match ($e->status) { 'booked' => ($e->reservation?->reservation_no ?? '').($e->referredFrom ? ' (from '.$e->referredFrom->name.')' : ''),
                    'referred' => '→ '.$e->referredHotel?->name.' '.$e->referred_reservation_no.' · '.$e->reasonLabel(), 'lost' => $e->reasonLabel().($e->competitor ? ' – '.$e->competitor->name : ''),
                    default => $e->follow_up_at ? 'follow up '.$e->follow_up_at->format('d M H:i') : '' },
                'agent' => $e->agent?->name, '_class' => $e->status === 'lost' ? 'row-red' : ($e->status === 'open' ? 'row-amber' : '')]);
        $k = \App\Services\CroService::kpis(Carbon::parse($from), Carbon::parse($to));

        return ['cols' => ['no' => 'No.', 'at' => 'Logged', 'channel' => 'Channel', 'caller' => 'Caller', 'stay' => 'Stay', 'rn' => 'Room nights', 'type' => 'Room type', 'value' => 'Value',
            'outcome' => 'Outcome', 'detail' => 'Detail', 'agent' => 'Agent'], 'rows' => $rows, 'money' => ['value'], 'sum' => ['rn', 'value'],
            'summary' => ['Enquiries' => (string) $k['total'], 'Booked' => $k['booked'].' ('.money($k['booked_revenue']).')', 'Referred' => $k['referred'].' ('.money($k['referred_revenue']).')',
                'Lost' => $k['lost'].' ('.money($k['lost_revenue']).')', 'Conversion' => $k['conversion'] !== null ? $k['conversion'].'%' : '—', 'Group retention' => $k['retention'] !== null ? $k['retention'].'%' : '—'],
            'note' => 'Value = room revenue booked (booked / referred) or the quote at stake (open / lost). Conversion = booked ÷ closed enquiries; group retention adds referrals to sister hotels.'];
    }

    protected function r_cro_lost_business($from, $to): array
    {
        $F = Carbon::parse($from);
        $T = Carbon::parse($to);
        $E = \App\Models\CroEnquiry::class;
        $rows = \App\Services\CroService::lostReasons($F, $T)->sortBy(fn ($x) => ($x['type'] === 'denial' ? 0 : 1).str_pad((string) (99999 - $x['count']), 5, '0', STR_PAD_LEFT))->values()
            ->map(fn ($x) => ['type' => $E::LOSS_TYPES[$x['type']][0] ?? '', 'reason' => $x['label'], 'count' => $x['count'], 'share' => $x['count'] ? $x['share'] : null, 'nights' => $x['nights'],
                'value' => $x['value'], 'referred' => $x['referred'] ?: null, 'kept' => $x['kept'] ?: null]);
        $k = \App\Services\CroService::kpis($F, $T);

        return ['cols' => ['type' => 'Type', 'reason' => 'Reason', 'count' => 'Lost', 'share' => 'Share of lost %', 'nights' => 'Room nights', 'value' => 'Value lost', 'referred' => 'Referred instead', 'kept' => 'Kept in group'],
            'rows' => $rows, 'money' => ['value', 'kept'], 'sum' => ['count', 'nights', 'value', 'referred', 'kept'],
            'summary' => ['Denials' => $k['denials'].' ('.number_format($k['denied_nights']).' room nights)', 'Regrets' => $k['regrets'].' ('.number_format($k['regret_nights']).' room nights)',
                'Value lost' => money($k['lost_revenue']), 'Kept in the group' => money($k['referred_revenue'])],
            'note' => 'Denial = the hotel could not offer (sold out, room type full, restrictions, group / party size) – true unconstrained demand. Regret = we offered and the guest declined. Denials that were booked at a sister hotel appear under “Referred instead”.'];
    }

    protected function r_cro_demand($from, $to): array
    {
        $F = Carbon::parse($from)->startOfDay();
        $days = max(1, min(92, (int) $F->diffInDays(Carbon::parse($to)->startOfDay()) + 1));
        $rows = \App\Services\CroService::demand($F, $days)->map(fn ($d) => ['date' => $d['date']->format('D d M Y'), 'capacity' => $d['capacity'], 'sold' => $d['sold'], 'denied' => $d['denied'] ?: null,
            'regret' => $d['regret'] ?: null, 'unconstrained' => $d['unconstrained'], 'occ' => $d['occ'], 'uocc' => $d['uocc'], '_class' => $d['uocc'] > 100 ? 'row-red' : ($d['denied'] ? 'row-amber' : '')]);

        return ['cols' => ['date' => 'Night', 'capacity' => 'Capacity', 'sold' => 'On the books', 'denied' => 'Denied', 'regret' => 'Regretted', 'unconstrained' => 'Unconstrained demand', 'occ' => 'Occupancy %', 'uocc' => 'Unconstrained occ. %'],
            'rows' => $rows, 'money' => [], 'sum' => ['sold', 'denied', 'regret', 'unconstrained'],
            'summary' => ['Nights' => (string) $rows->count(), 'Room nights denied' => (string) $rows->sum('denied'), 'Nights with denials' => (string) $rows->filter(fn ($r) => $r['denied'])->count(),
                'Nights over capacity' => (string) $rows->filter(fn ($r) => $r['uocc'] > 100)->count()],
            'note' => 'Unconstrained demand = rooms on the books + rooms denied for that night (lost or referred for lack of availability / restrictions). Up to 92 nights. Regrets are shown but not added – the guest was offered a room.'];
    }

    protected function r_cro_agents($from, $to): array
    {
        $rows = \App\Services\CroService::breakdown(Carbon::parse($from), Carbon::parse($to), 'agent')->map(fn ($a) => ['agent' => $a['label'], 'total' => $a['total'], 'booked' => $a['booked'],
            'referred' => $a['referred'] ?: null, 'denials' => $a['denials'] ?: null, 'regrets' => $a['regrets'] ?: null, 'open' => $a['open'] ?: null, 'conv' => $a['conversion'] ?? '',
            'revenue' => $a['revenue'], 'kept' => $a['referred_revenue'] ?: null, 'lost' => $a['lost_revenue'] ?: null, 'call' => $a['avg_handle'] !== null ? gmdate('i:s', $a['avg_handle']) : '',
            '_class' => $a['conversion'] !== null && ! $a['on_target'] ? 'row-amber' : '']);

        return ['cols' => ['agent' => 'Agent', 'total' => 'Enquiries', 'booked' => 'Booked', 'referred' => 'Referred', 'denials' => 'Denials', 'regrets' => 'Regrets', 'open' => 'Open', 'conv' => 'Conversion %',
            'revenue' => 'Booked revenue', 'kept' => 'Referred revenue', 'lost' => 'Lost value', 'call' => 'Avg call (m:s)'], 'rows' => $rows, 'money' => ['revenue', 'kept', 'lost'],
            'sum' => ['total', 'booked', 'referred', 'denials', 'regrets', 'open', 'revenue', 'kept', 'lost'],
            'summary' => ['Agents' => (string) $rows->count(), 'Conversion target' => \App\Services\CroService::cfg()['conversion_target'].'%'],
            'note' => 'Conversion = booked ÷ closed (booked + referred + lost). Agents below the target are highlighted.'];
    }

    protected function r_cro_referrals($from, $to): array
    {
        $hotels = \App\Services\CroService::groupHotels(auth()->user())->keyBy('id');
        $E = \App\Models\CroEnquiry::class;
        $rows = $E::withoutGlobalScope('hotel')->whereIn('hotel_id', $hotels->keys())->where('status', 'referred')->whereIn('referred_hotel_id', $hotels->keys())
            ->whereBetween('created_at', [Carbon::parse($from)->startOfDay(), Carbon::parse($to)->endOfDay()])->orderBy('created_at')->get()
            ->map(fn ($e) => ['at' => $e->created_at->format('d M Y'), 'no' => $e->number, 'from' => $hotels[$e->hotel_id]->name, 'to' => $hotels[$e->referred_hotel_id]->name, 'res' => $e->referred_reservation_no,
                'guest' => $e->caller_name, 'stay' => $e->arrival_date->format('d M').' – '.$e->departure_date->format('d M Y'), 'rn' => $e->roomNights(), 'why' => $e->reasonLabel(), 'value' => (float) $e->booked_value]);

        return ['cols' => ['at' => 'Date', 'no' => 'Enquiry', 'from' => 'From hotel', 'to' => 'Booked at', 'res' => 'Reservation', 'guest' => 'Guest', 'stay' => 'Stay', 'rn' => 'Room nights', 'why' => 'Why not kept', 'value' => 'Room revenue kept'],
            'rows' => $rows, 'money' => ['value'], 'sum' => ['rn', 'value'], 'summary' => ['Referrals' => (string) $rows->count(), 'Revenue kept in the group' => money($rows->sum('value'))],
            'note' => $hotels->count() > 1 ? 'All hotels of the group in your central reservations scope.' : 'Only this hotel is in your scope – group reservations agents see referrals between every hotel of the group.'];
    }

    // ------------------------------------------------------------------ v6.5 quality assurance

    protected function r_qa_audits($from, $to): array
    {
        $rows = \App\Models\QaAudit::with(['template', 'department', 'auditor', 'acknowledger'])->where('status', 'completed')
            ->whereBetween('completed_at', [Carbon::parse($from)->startOfDay(), Carbon::parse($to)->endOfDay()])->orderBy('completed_at')->get()
            ->map(fn ($a) => ['date' => $a->completed_at->format('d M Y'), 'no' => $a->number, 'checklist' => $a->template?->name ?? $a->title, 'type' => $a->typeLabel(),
                'dept' => $a->department?->name ?? 'Whole hotel', 'auditor' => $a->auditorName(), 'score' => (float) $a->score_pct, 'pass' => (float) $a->pass_mark,
                'result' => $a->passed ? 'Pass' : ($a->critical_fails && $a->score_pct >= $a->pass_mark ? 'Fail (critical)' : 'Fail'), 'fails' => $a->fails, 'crit' => $a->critical_fails ?: null,
                'rep' => $a->repeat_fails ?: null, 'ack' => $a->acknowledged_at ? $a->acknowledger?->name.' '.$a->acknowledged_at->format('d M') : '', '_class' => $a->passed ? '' : 'row-red'])->values();

        return ['cols' => ['date' => 'Completed', 'no' => 'Audit', 'checklist' => 'Checklist', 'type' => 'Type', 'dept' => 'Department', 'auditor' => 'Auditor', 'score' => 'Score %', 'pass' => 'Pass mark %',
            'result' => 'Result', 'fails' => 'Findings', 'crit' => 'Critical', 'rep' => 'Repeat', 'ack' => 'Acknowledged'], 'rows' => $rows, 'money' => [], 'sum' => ['fails', 'crit', 'rep'],
            'summary' => ['Audits' => (string) $rows->count(), 'Average score' => $rows->count() ? number_format($rows->avg('score'), 1).'%' : '—',
                'Passed' => $rows->where('result', 'Pass')->count().' of '.$rows->count()],
            'note' => 'Score = points of passed standards ÷ points of standards checked (n/a excluded).'.(\App\Services\QaService::cfg()['critical_autofail'] ? ' A failed critical standard fails the audit whatever the score.' : '')];
    }

    protected function r_qa_department_scores($from, $to): array
    {
        $cfg = \App\Services\QaService::cfg();
        $audits = \App\Models\QaAudit::with('department')->where('status', 'completed')->whereBetween('completed_at', [Carbon::parse($from)->startOfDay(), Carbon::parse($to)->endOfDay()])->get();
        $open = \App\Models\QaAction::whereIn('status', \App\Models\QaAction::OPEN)->get(['department_id', 'due_date'])->groupBy(fn ($x) => (int) $x->department_id);
        $rows = $audits->groupBy(fn ($a) => (int) $a->department_id)->map(function ($g, $id) use ($cfg, $open) {
            $avg = round((float) $g->avg('score_pct'), 1);
            $int = $g->whereIn('type', ['internal', 'walkthrough']);
            $ext = $g->whereIn('type', ['mystery', 'brand']);
            $o = $open[$id] ?? collect();

            return ['dept' => $g->first()->department?->name ?? 'Whole hotel', 'audits' => $g->count(), 'avg' => $avg, 'internal' => $int->count() ? round((float) $int->avg('score_pct'), 1) : '',
                'external' => $ext->count() ? round((float) $ext->avg('score_pct'), 1) : '', 'best' => (float) $g->max('score_pct'), 'worst' => (float) $g->min('score_pct'),
                'rate' => round($g->where('passed', true)->count() / $g->count() * 100, 1), 'fails' => (int) $g->sum('fails'), 'crit' => (int) $g->sum('critical_fails') ?: null,
                'open' => $o->count() ?: null, 'overdue' => $o->filter(fn ($x) => $x->due_date->lt(bdate()->copy()->startOfDay()))->count() ?: null,
                '_class' => $avg < $cfg['pass_mark'] ? 'row-red' : ($avg < $cfg['target_score'] ? 'row-amber' : '')];
        })->sortBy('avg')->values();

        return ['cols' => ['dept' => 'Department', 'audits' => 'Audits', 'avg' => 'Average %', 'internal' => 'Internal %', 'external' => 'Mystery / brand %', 'best' => 'Best %', 'worst' => 'Worst %',
            'rate' => 'Pass rate %', 'fails' => 'Findings', 'crit' => 'Critical', 'open' => 'Open actions', 'overdue' => 'Overdue actions'], 'rows' => $rows, 'money' => [], 'sum' => ['audits', 'fails', 'crit', 'open', 'overdue'],
            'summary' => ['Hotel average' => $audits->count() ? number_format($audits->avg('score_pct'), 1).'%' : '—', 'Target' => $cfg['target_score'].'%', 'Default pass mark' => $cfg['pass_mark'].'%'],
            'note' => 'Red: average below the pass mark; amber: below the target. Open / overdue actions are as of today.'];
    }

    protected function r_qa_standard_failures($from, $to): array
    {
        $rows = \App\Services\QaService::topFailures(Carbon::parse($from), Carbon::parse($to), 1000)->map(fn ($f) => ['code' => $f['standard']->code, 'std' => $f['standard']->title,
            'area' => $f['standard']->area, 'dept' => $f['standard']->department?->name ?? '—', 'weight' => $f['standard']->weight, 'crit' => $f['standard']->is_critical ? 'Yes' : '',
            'checks' => $f['checks'], 'fails' => $f['fails'], 'rate' => $f['rate'], 'rep' => $f['repeats'] ?: null, 'open' => $f['open'] ?: null,
            '_class' => $f['standard']->is_critical ? 'row-red' : ($f['repeats'] ? 'row-amber' : '')]);

        return ['cols' => ['code' => 'Code', 'std' => 'Standard', 'area' => 'Area', 'dept' => 'Department', 'weight' => 'Weight', 'crit' => 'Critical', 'checks' => 'Checked', 'fails' => 'Failed',
            'rate' => 'Fail rate %', 'rep' => 'Repeat', 'open' => 'Open actions'], 'rows' => $rows, 'money' => [], 'sum' => ['fails', 'rep', 'open'],
            'summary' => ['Standards failed' => (string) $rows->count(), 'Critical standards failed' => (string) $rows->where('crit', 'Yes')->count()],
            'note' => 'Checked counts pass + fail only (n/a excluded). Repeat = failed again after failing on the previous audit of the same checklist.'];
    }

    protected function r_qa_actions($from, $to): array
    {
        $X = \App\Models\QaAction::class;
        $rows = $X::with(['department', 'owner', 'audit'])->whereBetween('created_at', [Carbon::parse($from)->startOfDay(), Carbon::parse($to)->endOfDay()])->orderBy('created_at')->get()
            ->map(function ($x) {
                $closed = in_array($x->status, ['done', 'verified'], true) && $x->completed_at;

                return ['raised' => $x->created_at->format('d M Y'), 'no' => $x->number, 'audit' => $x->audit?->number ?? 'walk-round', 'title' => $x->title, 'dept' => $x->department?->name ?? '—',
                    'owner' => $x->owner?->name ?? 'unassigned', 'pri' => $x->priorityLabel(), 'due' => $x->due_date->format('d M Y'), 'status' => $x->statusLabel(),
                    'days' => $closed ? (int) $x->created_at->copy()->startOfDay()->diffInDays($x->completed_at->copy()->startOfDay()) : '',
                    'ontime' => $closed ? ($x->completed_at->toDateString() <= $x->due_date->toDateString() ? 'Yes' : 'Late') : ($x->isOverdue() ? 'Overdue' : ''),
                    'reopened' => $x->reopened ?: null, '_class' => $x->isOverdue() ? 'row-red' : ($closed && $x->completed_at->toDateString() > $x->due_date->toDateString() ? 'row-amber' : '')];
            });
        $closed = $rows->whereIn('ontime', ['Yes', 'Late']);

        return ['cols' => ['raised' => 'Raised', 'no' => 'Action', 'audit' => 'Source', 'title' => 'Finding', 'dept' => 'Department', 'owner' => 'Owner', 'pri' => 'Priority', 'due' => 'Due',
            'status' => 'Status', 'days' => 'Days to fix', 'ontime' => 'On time', 'reopened' => 'Reopened'], 'rows' => $rows, 'money' => [], 'sum' => ['reopened'],
            'summary' => ['Raised' => (string) $rows->count(), 'Closed' => (string) $closed->count(), 'On time' => $closed->count() ? round($closed->where('ontime', 'Yes')->count() / $closed->count() * 100, 1).'%' : '—',
                'Overdue now' => (string) $rows->where('ontime', 'Overdue')->count()],
            'note' => 'Days to fix = raised to marked done. Done actions still need verification by a quality manager other than the owner.'];
    }

    protected function r_qa_template_compliance($from, $to): array
    {
        $f = Carbon::parse($from)->startOfDay();
        $t = Carbon::parse($to)->endOfDay();
        $days = (int) $f->diffInDays($t->copy()->startOfDay()) + 1;
        $due = \App\Services\QaService::due()->keyBy(fn ($d) => $d['template']->id);
        $rows = \App\Models\QaTemplate::with('department')->where('is_active', true)->orderBy('name')->get()->map(function ($tp) use ($f, $t, $days, $due) {
            $done = \App\Models\QaAudit::where('qa_template_id', $tp->id)->where('status', 'completed')->whereBetween('completed_at', [$f, $t])->get(['score_pct', 'passed', 'completed_at']);
            $req = $tp->frequency_days ? max(1, (int) floor($days / $tp->frequency_days)) : null;
            $pct = $req ? min(100, round($done->count() / $req * 100, 1)) : '';
            $d = $due[$tp->id] ?? null;

            return ['name' => $tp->name, 'type' => $tp->typeLabel(), 'dept' => $tp->department?->name ?? 'Whole hotel', 'freq' => $tp->frequency_days ? 'every '.$tp->frequency_days.' d' : 'on demand',
                'req' => $req, 'done' => $done->count(), 'pct' => $pct, 'avg' => $done->count() ? round((float) $done->avg('score_pct'), 1) : '', 'passed' => $done->where('passed', true)->count() ?: null,
                'last' => $done->max('completed_at')?->format('d M Y') ?? '', 'next' => $d ? ($d['pending'] ? $d['pending']->scheduled_for->format('d M Y').' (booked)' : $d['next']->format('d M Y')) : '',
                '_class' => $req && $pct < 80 ? 'row-red' : ($d && in_array($d['state'], ['due', 'late'], true) ? 'row-amber' : '')];
        });

        return ['cols' => ['name' => 'Checklist', 'type' => 'Type', 'dept' => 'Department', 'freq' => 'Frequency', 'req' => 'Required', 'done' => 'Done', 'pct' => 'Compliance %', 'avg' => 'Avg score %',
            'passed' => 'Passed', 'last' => 'Last audit', 'next' => 'Next due'], 'rows' => $rows, 'money' => [], 'sum' => ['req', 'done', 'passed'],
            'summary' => ['Checklists' => (string) $rows->count(), 'Period' => $days.' days'],
            'note' => 'Required = whole frequency cycles in the period. Red: under 80 % of the required audits done; amber: due or late now.'];
    }

    protected function r_training_records($from, $to): array
    {
        $R = \App\Models\TrainingRecord::class;
        $rows = \App\Models\TrainingRecord::with('course', 'employee.department', 'session')->whereIn('status', ['passed', 'failed', 'no_show'])
            ->whereBetween('completed_on', [Carbon::parse($from)->toDateString(), Carbon::parse($to)->toDateString()])->orderBy('completed_on')->orderBy('id')->get()
            ->map(fn ($r) => ['date' => $r->completed_on->format('d M Y'), 'emp' => $r->employee->name, 'dept' => $r->employee->department?->name ?? '—', 'course' => $r->course->name,
                'source' => $r->session ? $r->session->number : ($R::SOURCES[$r->source] ?? $r->source), 'provider' => (string) ($r->provider ?: ($r->session?->trainerLabel() ?? '')),
                'result' => $r->statusLabel(), 'score' => $r->score !== null ? (float) $r->score : '', 'hours' => $r->status === 'passed' ? (float) $r->course->duration_hours : 0.0,
                'cost' => (float) $r->cost, 'cert' => (string) $r->certificate_no, '_class' => $r->status === 'passed' ? '' : 'row-alert'])->values();

        return ['cols' => ['date' => 'Date', 'emp' => 'Employee', 'dept' => 'Department', 'course' => 'Course', 'source' => 'Session / source', 'provider' => 'Trainer / provider', 'result' => 'Result',
            'score' => 'Score %', 'hours' => 'Hours', 'cost' => 'Cost', 'cert' => 'Certificate'],
            'rows' => $rows, 'money' => ['cost'], 'sum' => ['hours', 'cost'],
            'summary' => ['Outcomes' => (string) $rows->count(), 'Passed' => (string) $rows->where('result', 'Passed')->count(), 'Failed' => (string) $rows->where('result', 'Failed')->count(),
                'No-shows' => (string) $rows->where('result', 'No-show')->count(), 'Hours' => number_format($rows->sum('hours'), 1), 'Cost' => money($rows->sum('cost'))],
            'note' => 'By completion date. Hours count passed courses only; cost is the session cost shared across attendees (or the per-head rate) plus external fees.'];
    }

    protected function r_training_hours($from, $to): array
    {
        $recs = \App\Models\TrainingRecord::with('course', 'employee.department')->whereIn('status', ['passed', 'failed'])
            ->whereBetween('completed_on', [Carbon::parse($from)->toDateString(), Carbon::parse($to)->toDateString()])->get();
        $staff = \App\Services\LearningService::employees()->groupBy(fn ($e) => $e->department?->name ?? '—');
        $rows = $staff->keys()->merge($recs->map(fn ($r) => $r->employee->department?->name ?? '—'))->unique()->sort()->map(function ($d) use ($recs, $staff) {
            $g = $recs->filter(fn ($r) => ($r->employee->department?->name ?? '—') === $d);
            $n = $staff->get($d, collect())->count();
            $hours = round($g->where('status', 'passed')->sum(fn ($r) => (float) $r->course->duration_hours), 1);
            $cost = round($g->sum(fn ($r) => (float) $r->cost), 2);

            return ['dept' => $d, 'staff' => $n, 'trained' => $g->pluck('employee_id')->unique()->count(), 'outcomes' => $g->count(), 'hours' => $hours,
                'per_head' => $n ? round($hours / $n, 1) : 0.0, 'cost' => $cost, 'cost_head' => $n ? round($cost / $n, 2) : 0.0,
                'courses' => $g->groupBy(fn ($r) => $r->course->code)->map(fn ($x, $k) => $k.' ×'.$x->count())->implode(', ')];
        })->values();
        $n = $rows->sum('staff');

        return ['cols' => ['dept' => 'Department', 'staff' => 'Staff', 'trained' => 'Staff trained', 'outcomes' => 'Course outcomes', 'hours' => 'Hours', 'per_head' => 'Hours / head',
            'cost' => 'Cost', 'cost_head' => 'Cost / head', 'courses' => 'Courses'],
            'rows' => $rows, 'money' => ['cost', 'cost_head'], 'sum' => ['staff', 'trained', 'outcomes', 'hours', 'cost'],
            'summary' => ['Hours' => number_format($rows->sum('hours'), 1), 'Hours per head' => $n ? number_format($rows->sum('hours') / $n, 1) : '0', 'Spend' => money($rows->sum('cost')),
                'Spend per head' => money($n ? $rows->sum('cost') / $n : 0)],
            'note' => 'Completed training (passed or failed) in the period. Hours count passed courses at the course duration; "per head" divides by current staff in the department.'];
    }

    protected function r_sop_acknowledgement($from, $to): array
    {
        $rows = \App\Services\LearningService::sopOverview()->map(function ($r) {
            $d = $r['doc'];
            $out = $r['rows']->filter(fn ($x) => ! $x['ack'])->map(fn ($x) => $x['employee']->name);

            return ['code' => $d->code, 'title' => $d->title, 'dept' => $d->department?->name ?? 'Hotel-wide', 'version' => 'v'.$d->version.($r['version'] < $d->version ? ' (sign v'.$r['version'].'+)' : ''),
                'effective' => $d->effective_date?->format('d M Y') ?? '', 'review' => $d->review_due?->format('d M Y') ?? '', 'required' => $r['required'], 'signed' => $r['signed'],
                'outstanding' => $r['required'] - $r['signed'], 'pct' => $r['pct'] === null ? '—' : $r['pct'].'%', 'who' => $out->take(8)->implode(', ').($out->count() > 8 ? ' +'.($out->count() - 8) : ''),
                '_class' => $d->reviewOverdue() ? 'row-alert' : ($r['required'] > $r['signed'] ? 'row-warn' : '')];
        })->values();
        $req = $rows->sum('required');

        return ['cols' => ['code' => 'SOP', 'title' => 'Title', 'dept' => 'Department', 'version' => 'Version', 'effective' => 'Effective', 'review' => 'Review due', 'required' => 'Staff in scope',
            'signed' => 'Signed', 'outstanding' => 'Outstanding', 'pct' => 'Signed %', 'who' => 'Not signed yet'],
            'rows' => $rows, 'sum' => ['required', 'signed', 'outstanding'],
            'summary' => ['SOPs' => (string) $rows->count(), 'Signatures needed' => (string) $req, 'Signed' => $req ? round($rows->sum('signed') * 100 / $req, 1).'%' : '—',
                'Fully signed SOPs' => (string) $rows->where('outstanding', 0)->count()],
            'note' => 'Published SOPs that staff must sign, as at today. Signatures on earlier versions still count when later versions were minor changes; a version published with "staff must sign again" resets them.'];
    }

    // ───────── v5.9 concierge ─────────

    protected function cgRows($from, $to)
    {
        return \App\Models\ActivityBooking::with('activity', 'vendor', 'creator')->whereBetween('date', [Carbon::parse($from)->toDateString(), Carbon::parse($to)->toDateString()])
            ->orderBy('date')->orderBy('time')->orderBy('id')->get();
    }

    protected function r_concierge_bookings($from, $to): array
    {
        $B = \App\Models\ActivityBooking::class;
        $rows = $this->cgRows($from, $to)->map(fn ($b) => ['date' => $b->date->format('d M Y').' '.$b->time, 'no' => $b->number, 'activity' => $b->activity->name, 'vendor' => $b->vendor->name,
            'guest' => $b->guest_name.($b->room_no ? ' · '.$b->room_no : ''), 'pax' => $b->pax(), 'source' => $B::SOURCES[$b->source] ?? $b->source, 'paid' => $b->collection === 'folio' ? 'Room bill' : 'Vendor',
            'gross' => $b->status === 'cancelled' ? (float) $b->cancel_fee : (in_array($b->status, ['completed', 'no_show', 'confirmed'], true) ? (float) $b->gross : 0.0),
            'commission' => in_array($b->status, ['requested', 'waitlisted'], true) ? 0.0 : (float) $b->commission, 'status' => $b->statusLabel(), 'by' => (string) ($b->creator?->name ?? 'Guest portal'),
            'settled' => $b->activity_settlement_id ? 'Yes' : '', '_class' => in_array($b->status, ['no_show', 'cancelled'], true) ? 'row-alert' : ''])->values();

        return ['cols' => ['date' => 'Date & time', 'no' => 'Booking', 'activity' => 'Activity', 'vendor' => 'Operator', 'guest' => 'Guest · room', 'pax' => 'Pax', 'source' => 'Source', 'paid' => 'Paid to',
            'gross' => 'Sold', 'commission' => 'Commission', 'status' => 'Status', 'by' => 'Booked by', 'settled' => 'Settled'],
            'rows' => $rows, 'money' => ['gross', 'commission'], 'sum' => ['pax', 'gross', 'commission'],
            'summary' => ['Bookings' => (string) $rows->count(), 'Completed' => (string) $rows->where('status', 'Completed')->count(), 'Guests' => (string) $rows->whereIn('status', ['Completed', 'Confirmed', 'No-show'])->sum('pax'),
                'Sold' => money($rows->sum('gross')), 'Commission' => money($rows->sum('commission'))],
            'note' => 'By activity date. Sold = price of confirmed / completed / no-show bookings plus late-cancellation fees (free cancellations and unconfirmed requests show 0). Commission on confirmed bookings is not earned until the activity takes place.'];
    }

    protected function r_concierge_sales($from, $to): array
    {
        $A = \App\Models\Activity::class;
        $rows = $this->cgRows($from, $to)->groupBy('activity_id')->map(function ($g) use ($A) {
            $a = $g->first()->activity;
            $sold = $g->whereIn('status', ['completed', 'no_show', 'confirmed']);
            $done = $g->whereIn('status', ['completed', 'no_show']);
            $earned = $g->whereNotNull('recognised_on');

            return ['activity' => $a->name, 'cat' => $A::CATEGORIES[$a->category] ?? $a->category, 'vendor' => $g->first()->vendor->name, 'bookings' => $g->count(), 'sold' => $sold->count(),
                'pax' => $sold->sum(fn ($b) => $b->pax()), 'gross' => round($sold->sum(fn ($b) => (float) $b->gross) + $g->where('status', 'cancelled')->sum(fn ($b) => (float) $b->cancel_fee), 2),
                'avg' => $sold->count() ? round($sold->sum(fn ($b) => (float) $b->gross) / $sold->count(), 2) : 0.0, 'commission' => round($earned->sum(fn ($b) => (float) $b->commission), 2),
                'cancel' => $g->where('status', 'cancelled')->count(), 'noshow' => $g->where('status', 'no_show')->count(), 'portal' => $g->where('source', 'portal')->count(),
                'conv' => $g->count() ? round($done->count() * 100 / $g->count(), 1) : 0];
        })->sortByDesc('gross')->values();

        return ['cols' => ['activity' => 'Activity', 'cat' => 'Category', 'vendor' => 'Operator', 'bookings' => 'Bookings', 'sold' => 'Confirmed / done', 'pax' => 'Guests', 'gross' => 'Sold',
            'avg' => 'Avg booking', 'commission' => 'Commission earned', 'cancel' => 'Cancelled', 'noshow' => 'No-shows', 'portal' => 'Via portal', 'conv' => 'Took place %'],
            'rows' => $rows, 'money' => ['gross', 'avg', 'commission'], 'sum' => ['bookings', 'sold', 'pax', 'gross', 'commission', 'cancel', 'noshow', 'portal'],
            'summary' => ['Activities sold' => (string) $rows->count(), 'Sold' => money($rows->sum('gross')), 'Commission earned' => money($rows->sum('commission')),
                'Top seller' => (string) ($rows->first()['activity'] ?? '–')],
            'note' => 'Bookings with an activity date in the period. Commission earned = recognised in the ledger (completed, no-show and late-cancellation fees); "Took place %" = completed + no-show ÷ all bookings.'];
    }

    protected function r_concierge_vendors($from, $to): array
    {
        $to = Carbon::parse($to)->toDateString();
        $V = \App\Models\ActivityVendor::class;
        $done = $this->cgRows($from, $to)->whereIn('status', ['completed', 'no_show', 'cancelled'])->groupBy('activity_vendor_id');
        $open = \App\Services\ConciergeService::unsettled(null, $to)->groupBy('activity_vendor_id');
        $paid = \App\Models\ActivitySettlement::where('status', 'posted')->whereBetween('date', [Carbon::parse($from)->toDateString(), $to])->get()->groupBy('activity_vendor_id');
        $rows = \App\Models\ActivityVendor::orderBy('name')->get()->map(function ($v) use ($done, $open, $paid, $V) {
            $d = $done[$v->id] ?? collect();
            $o = $open[$v->id] ?? collect();
            $p = $paid[$v->id] ?? collect();
            if ($d->isEmpty() && $o->isEmpty() && $p->isEmpty()) {
                return null;
            }
            $owe = round($o->where('collection', 'folio')->sum(fn ($b) => (float) $b->vendor_net), 2);
            $due = round($o->where('collection', 'vendor')->sum(fn ($b) => (float) $b->commission), 2);

            return ['vendor' => $v->name, 'kind' => $V::KINDS[$v->kind] ?? $v->kind, 'pct' => (float) $v->commission_pct, 'bookings' => $d->count(),
                'gross' => round($d->sum(fn ($b) => (float) ($b->status === 'cancelled' ? $b->cancel_fee : $b->gross)), 2), 'commission' => round($d->sum(fn ($b) => (float) $b->commission), 2),
                'settled' => $p->count(), 'paid' => round($p->sum(fn ($s) => max(0, (float) $s->net)), 2), 'received' => round($p->sum(fn ($s) => max(0, -(float) $s->net)), 2),
                'owe' => $owe, 'due' => $due, 'net' => round($owe - $due, 2), 'oldest' => $o->isEmpty() ? '' : $o->min(fn ($b) => $b->date)->format('d M Y'),
                'licence' => $v->licence_expires ? ($v->licenceExpired() ? 'EXPIRED ' : '').$v->licence_expires->format('d M Y') : '',
                '_class' => $v->licenceExpired() ? 'row-alert' : ''];
        })->filter()->values();

        return ['cols' => ['vendor' => 'Operator', 'kind' => 'Type', 'pct' => 'Comm. %', 'bookings' => 'Finished bookings', 'gross' => 'Sold', 'commission' => 'Commission', 'settled' => 'Settlements',
            'paid' => 'Paid out', 'received' => 'Received', 'owe' => 'Hotel owes (unsettled)', 'due' => 'Operator owes (unsettled)', 'net' => 'Net payable', 'oldest' => 'Oldest unsettled', 'licence' => 'Licence expires'],
            'rows' => $rows, 'money' => ['gross', 'commission', 'paid', 'received', 'owe', 'due', 'net'], 'sum' => ['bookings', 'gross', 'commission', 'settled', 'paid', 'received', 'owe', 'due', 'net'],
            'summary' => ['Owed to operators' => money($rows->sum('owe')), 'Commission due' => money($rows->sum('due')), 'Net' => money($rows->sum('net')), 'Paid out in period' => money($rows->sum('paid'))],
            'note' => 'Finished = completed, no-show and cancelled bookings with an activity date in the period; settlements dated in the period. Unsettled balances are every finished booking up to the end date not yet in a settlement.'];
    }

    protected function r_concierge_staff($from, $to): array
    {
        $rows = $this->cgRows($from, $to)->groupBy(fn ($b) => $b->source === 'portal' ? 'portal' : 'u'.$b->created_by)->map(function ($g) {
            $sold = $g->whereIn('status', ['completed', 'no_show', 'confirmed']);

            return ['who' => $g->first()->source === 'portal' ? 'Guest portal (self-service)' : (string) ($g->first()->creator?->name ?? '—'), 'bookings' => $g->count(), 'sold' => $sold->count(),
                'pax' => $sold->sum(fn ($b) => $b->pax()), 'gross' => round($sold->sum(fn ($b) => (float) $b->gross), 2), 'commission' => round($sold->sum(fn ($b) => (float) $b->commission), 2),
                'inhouse' => $g->whereNotNull('reservation_room_id')->count(), 'walkin' => $g->whereNull('reservation_room_id')->count(), 'cancel' => $g->where('status', 'cancelled')->count()];
        })->sortByDesc('commission')->values();

        return ['cols' => ['who' => 'Sold by', 'bookings' => 'Bookings', 'sold' => 'Confirmed / done', 'pax' => 'Guests', 'gross' => 'Sold', 'commission' => 'Commission', 'inhouse' => 'Staying guests', 'walkin' => 'Non-residents', 'cancel' => 'Cancelled'],
            'rows' => $rows, 'money' => ['gross', 'commission'], 'sum' => ['bookings', 'sold', 'pax', 'gross', 'commission', 'inhouse', 'walkin', 'cancel'],
            'note' => 'Bookings with an activity date in the period by the user who made them. Commission includes confirmed bookings not yet taken place (the incentive base for concierge staff).'];
    }

    // ------------------------------------------------------------------ v6.6 parking & valet

    protected function r_parking_sessions($from, $to): array
    {
        $rows = \App\Models\ParkingSession::with(['zone', 'stay.room'])->whereBetween('entered_at', [Carbon::parse($from)->startOfDay(), Carbon::parse($to)->endOfDay()])->orderBy('entered_at')->orderBy('id')->get()
            ->map(fn ($s) => ['in' => $s->entered_at->format('d M Y H:i'), 'no' => $s->number, 'plate' => $s->plate, 'type' => $s->kindLabel(), 'zone' => $s->zone?->code,
                'who' => trim(($s->stay?->room ? 'Rm '.$s->stay->room->room_no.' · ' : '').($s->guest_name ?? '')), 'out' => $s->exited_at?->format('d M Y H:i') ?? '', 'dur' => $s->durationLabel(),
                'valid' => $s->validation_hours ?: null, 'lost' => $s->lost_ticket ? 'Yes' : '', 'fee' => (float) $s->fee ?: null, 'waived' => (float) $s->fee_waived ?: null, 'status' => $s->statusLabel(),
                '_class' => $s->lost_ticket || (float) $s->fee_waived > 0 ? 'row-amber' : ($s->status === 'cancelled' ? 'row-red' : '')]);

        return ['cols' => ['in' => 'In', 'no' => 'Ticket', 'plate' => 'Plate', 'type' => 'Type', 'zone' => 'Zone', 'who' => 'Driver / room', 'out' => 'Out', 'dur' => 'Duration', 'valid' => 'Validated h',
            'lost' => 'Lost ticket', 'fee' => 'Charged at exit', 'waived' => 'Waived', 'status' => 'Status'], 'rows' => $rows, 'money' => ['fee', 'waived'], 'sum' => ['fee', 'waived'],
            'summary' => ['Tickets' => (string) $rows->count(), 'Valet' => (string) $rows->filter(fn ($r) => str_contains($r['type'], 'valet'))->count(), 'Lost tickets' => (string) $rows->where('lost', 'Yes')->count(),
                'Charged at exit' => money($rows->sum('fee'))],
            'note' => 'Guest nights posted to room folios by the night audit are not in "charged at exit" – see Parking Revenue by Day. Amounts are net of VAT / levies.'];
    }

    protected function r_parking_revenue($from, $to): array
    {
        $ch = \App\Models\ParkingCharge::with('session')->whereDate('business_date', '>=', Carbon::parse($from)->toDateString())->whereDate('business_date', '<=', Carbon::parse($to)->toDateString())->get();
        $waived = \App\Models\ParkingSession::where('fee_waived', '>', 0)->whereBetween('exited_at', [Carbon::parse($from)->startOfDay(), Carbon::parse($to)->endOfDay()])->get();
        $rows = $ch->groupBy(fn ($c) => $c->business_date->toDateString())->sortKeys()->map(function ($g, $d) use ($waived) {
            $n = $g->where('kind', 'night');
            $x = $g->where('kind', 'exit');
            $vis = $x->filter(fn ($c) => $c->session?->kind === 'visitor');
            $w = $waived->filter(fn ($s) => $s->exited_at->toDateString() === $d);

            return ['date' => Carbon::parse($d)->format('D d M Y'), 'nights' => $n->count() ?: null, 'night_amt' => (float) $n->sum('amount') ?: null,
                'exits' => $vis->pluck('parking_session_id')->unique()->count() ?: null, 'exit_amt' => (float) $x->sum('amount') ?: null,
                'lost' => (float) $g->where('kind', 'lost')->sum('amount') ?: null, 'total' => (float) $g->sum('amount'), 'waived' => (float) $w->sum('fee_waived') ?: null];
        })->values();

        return ['cols' => ['date' => 'Business date', 'nights' => 'Guest nights', 'night_amt' => 'Room-folio parking', 'exits' => 'Paid exits', 'exit_amt' => 'Exit fees & valet', 'lost' => 'Lost tickets',
            'total' => 'Total revenue', 'waived' => 'Waived'], 'rows' => $rows, 'money' => ['night_amt', 'exit_amt', 'lost', 'total', 'waived'], 'sum' => ['nights', 'night_amt', 'exits', 'exit_amt', 'lost', 'total', 'waived'],
            'summary' => ['Revenue' => money($rows->sum('total')), 'Room folios' => money($rows->sum('night_amt')), 'Paid at exit' => money($rows->sum('exit_amt') + $rows->sum('lost')), 'Waived' => money($rows->sum('waived'))],
            'note' => 'Net of VAT, NHIL and GETFund; posted under the Parking & Valet charge category (GL 4075). Waived amounts were never charged.'];
    }

    protected function r_parking_occupancy($from, $to): array
    {
        $f = Carbon::parse($from)->startOfDay();
        $t = Carbon::parse($to)->endOfDay();
        $days = (int) $f->diffInDays($t->copy()->startOfDay()) + 1;
        $rows = \App\Models\ParkingZone::orderBy('sort')->orderBy('code')->get()->map(function ($z) use ($f, $t, $days) {
            $ss = \App\Models\ParkingSession::where('parking_zone_id', $z->id)->where('status', '!=', 'cancelled')->where('entered_at', '<=', $t)
                ->where(fn ($q) => $q->whereNull('exited_at')->orWhere('exited_at', '>=', $f))->get(['id', 'entered_at', 'exited_at', 'kind', 'is_valet', 'fee']);
            $ev = [];
            $bayMinutes = 0;
            foreach ($ss as $s) {
                $a = $s->entered_at->max($f);
                $b = ($s->exited_at ?? now())->min($t);
                $ev[] = [$a->timestamp, 1];
                $ev[] = [$b->timestamp, -1];
                $bayMinutes += max(0, $a->diffInSeconds($b, true) / 60);
            }
            usort($ev, fn ($x, $y) => [$x[0], $x[1]] <=> [$y[0], $y[1]]);
            $cur = $peak = 0;
            foreach ($ev as [, $d]) {
                $cur += $d;
                $peak = max($peak, $cur);
            }
            $inPeriod = $ss->filter(fn ($s) => $s->entered_at->between($f, $t));
            $closed = $inPeriod->whereNotNull('exited_at');
            $avg = $closed->count() ? $closed->avg(fn ($s) => $s->entered_at->diffInSeconds($s->exited_at, true) / 60) : null;
            $util = $z->capacity ? round($bayMinutes / ($z->capacity * $days * 1440) * 100, 1) : null;

            return ['zone' => $z->code.' · '.$z->name, 'type' => $z->typeLabel(), 'bays' => $z->capacity ?: null, 'tickets' => $inPeriod->count(), 'guests' => $inPeriod->where('kind', 'guest')->count() ?: null,
                'visitors' => $inPeriod->where('kind', 'visitor')->count() ?: null, 'staff' => $inPeriod->where('kind', 'staff')->count() ?: null,
                'avg' => $avg !== null ? (floor($avg / 60) ? floor($avg / 60).'h ' : '').round(fmod($avg, 60)).'m' : '', 'peak' => $peak, 'peak_pct' => $z->capacity ? round($peak / $z->capacity * 100).'%' : '',
                'util' => $util !== null ? $util.'%' : '', 'now' => \App\Models\ParkingSession::where('parking_zone_id', $z->id)->whereIn('status', \App\Models\ParkingSession::ACTIVE)->count(),
                '_class' => $z->capacity && $peak >= $z->capacity ? 'row-red' : ''];
        });

        return ['cols' => ['zone' => 'Zone', 'type' => 'Type', 'bays' => 'Bays', 'tickets' => 'Tickets', 'guests' => 'Guests', 'visitors' => 'Visitors', 'staff' => 'Permit', 'avg' => 'Avg stay',
            'peak' => 'Peak parked', 'peak_pct' => 'Peak %', 'util' => 'Bay utilisation', 'now' => 'Parked now'], 'rows' => $rows, 'money' => [], 'sum' => ['bays', 'tickets', 'guests', 'visitors', 'staff', 'now'],
            'summary' => ['Tickets' => (string) $rows->sum('tickets'), 'Bays' => (string) $rows->sum('bays'), 'Zones at capacity' => (string) $rows->where('_class', 'row-red')->count()],
            'note' => 'Peak = most vehicles parked at the same time in the period. Bay utilisation = vehicle-minutes ÷ (bays × minutes in the period).'];
    }

    protected function r_parking_valet($from, $to): array
    {
        $sla = \App\Services\ParkingService::cfg()['sla_minutes'];
        $ss = \App\Models\ParkingSession::with('runner')->where('is_valet', true)->whereNotNull('requested_at')->whereNotNull('ready_at')
            ->whereBetween('requested_at', [Carbon::parse($from)->startOfDay(), Carbon::parse($to)->endOfDay()])->get();
        $mk = function ($g, string $name) use ($sla) {
            $w = $g->map(fn ($s) => $s->requested_at->diffInSeconds($s->ready_at, true) / 60);

            return ['runner' => $name, 'n' => $g->count(), 'avg' => $g->count() ? round($w->avg(), 1) : null, 'max' => $g->count() ? round($w->max(), 1) : null,
                'sla' => $g->count() ? round($w->filter(fn ($x) => $x <= $sla)->count() / $g->count() * 100, 1).'%' : '', 'late' => $w->filter(fn ($x) => $x > $sla)->count() ?: null,
                'qr' => $g->where('requested_via', 'qr')->count() ?: null, 'phone' => $g->where('requested_via', 'phone')->count() ?: null, 'desk' => $g->where('requested_via', 'desk')->count() ?: null];
        };
        $rows = $ss->groupBy(fn ($s) => $s->runner?->name ?? '—')->sortKeys()->map(fn ($g, $n) => $mk($g, $n))->values();
        if ($ss->count()) {
            $rows->push($mk($ss, 'All runners') + ['_class' => 'bold-row']);
        }

        return ['cols' => ['runner' => 'Runner', 'n' => 'Retrievals', 'avg' => 'Avg min to door', 'max' => 'Longest', 'sla' => "Within {$sla} min", 'late' => 'Over target', 'qr' => 'Via QR', 'phone' => 'Via phone', 'desk' => 'At the desk'],
            'rows' => $rows, 'money' => [], 'sum' => [],
            'summary' => ['Retrievals' => (string) $ss->count(), 'Average' => $ss->count() ? round($ss->avg(fn ($s) => $s->requested_at->diffInSeconds($s->ready_at, true) / 60), 1).' min' : '—', 'Target' => $sla.' min'],
            'note' => 'Minutes from the request (desk, phone or the guest scanning the QR on the ticket) until the car is at the door.'];
    }

    protected function r_parking_permits($from, $to): array
    {
        $rows = \App\Models\ParkingPermit::with(['vehicle', 'zone', 'employee'])->orderByRaw("status = 'revoked'")->orderBy('valid_to')->get()->map(function ($p) {
            [$state] = $p->stateBadge();

            return ['no' => $p->number, 'holder' => $p->holder_name, 'type' => \App\Models\ParkingPermit::HOLDERS[$p->holder_type] ?? $p->holder_type, 'emp' => $p->employee?->emp_code ?? '',
                'plate' => $p->vehicle->plate_display, 'vehicle' => $p->vehicle->description(), 'zone' => $p->zone?->code ?? 'any', 'from' => $p->valid_from->format('d M Y'), 'to' => $p->valid_to->format('d M Y'),
                'days' => $p->state() === 'active' ? (int) bdate()->diffInDays($p->valid_to) : null, 'fee' => (float) $p->fee ?: null, 'state' => $state.($p->revoke_reason ? ' – '.$p->revoke_reason : ''),
                'uses' => \App\Models\ParkingSession::where('parking_permit_id', $p->id)->count() ?: null,
                '_class' => $p->state() === 'active' && $p->valid_to->lte(bdate()->copy()->addDays(7)) ? 'row-amber' : ($p->state() === 'revoked' ? 'row-red' : '')];
        });

        return ['cols' => ['no' => 'Permit', 'holder' => 'Holder', 'type' => 'Type', 'emp' => 'Emp code', 'plate' => 'Plate', 'vehicle' => 'Vehicle', 'zone' => 'Zone', 'from' => 'From', 'to' => 'To',
            'days' => 'Days left', 'fee' => 'Fee', 'state' => 'State', 'uses' => 'Times parked'], 'rows' => $rows, 'money' => ['fee'], 'sum' => ['fee', 'uses'],
            'summary' => ['Permits' => (string) $rows->count(), 'Valid today' => (string) $rows->filter(fn ($r) => str_starts_with($r['state'], 'Active'))->count(),
                'Expiring in 7 days' => (string) $rows->where('_class', 'row-amber')->count()],
            'note' => 'Permit fees are recorded here only – bill them through the tenant lease, payroll deduction or a non-resident folio.'];
    }

    // ------------------------------------------------------------------ v6.7 health, safety & fire

    protected function r_hse_equipment($from, $to): array
    {
        $lab = ['ok' => 'In order', 'due' => 'Due soon', 'overdue' => 'Overdue', 'defective' => 'Defective', 'expired' => 'Expired', 'retired' => 'Retired'];
        $rows = \App\Models\HseEquipment::with('ticket')->where('status', '!=', 'retired')->orderBy('code')->get()->map(function ($e) use ($lab) {
            $st = $e->state();

            return ['code' => $e->code, 'type' => $e->typeLabel(), 'size' => $e->capacity ?? '', 'location' => $e->location, 'floor' => $e->floor ?? '', 'tag' => $e->gnfs_tag ?? '',
                'last' => $e->last_checked_on?->format('d M Y') ?? '', 'next' => $e->next_check_on?->format('d M Y') ?? 'now', 'service' => $e->next_service_on?->format('d M Y') ?? '',
                'expires' => $e->expires_on?->format('d M Y') ?? '', 'state' => $lab[$st].($e->ticket && $e->ticket->isOpen() ? ' · '.$e->ticket->ticket_no : ''),
                '_class' => in_array($st, ['overdue', 'defective', 'expired'], true) ? 'row-red' : ($st === 'due' ? 'row-amber' : '')];
        });
        $eq = \App\Services\HseService::equipmentStats();

        return ['cols' => ['code' => 'Code', 'type' => 'Type', 'size' => 'Size', 'location' => 'Location', 'floor' => 'Floor', 'tag' => 'GNFS tag', 'last' => 'Last check', 'next' => 'Next check',
            'service' => 'Service due', 'expires' => 'Expires', 'state' => 'State'], 'rows' => $rows, 'money' => [], 'sum' => [],
            'summary' => ['Items' => (string) $eq['total'], 'In order' => ($eq['pct'] ?? 0).'%', 'Overdue' => (string) $eq['overdue'], 'Defective' => (string) $eq['defective'], 'Expired' => (string) $eq['expired']],
            'note' => 'Fire Precaution (Premises) Regulations 2003 (L.I. 1724): keep fire-fighting equipment serviceable and ready for the GNFS inspection. Tag numbers are the GNFS equipment tags (AFCAS).'];
    }

    protected function r_hse_checks($from, $to): array
    {
        $rows = \App\Models\HseCheck::with(['equipment', 'user', 'ticket'])->whereBetween('checked_on', [$from->toDateString(), $to->toDateString()])->orderByDesc('checked_on')->orderByDesc('id')->get()
            ->map(fn ($c) => ['date' => $c->checked_on->format('d M Y'), 'code' => $c->equipment?->code, 'type' => $c->equipment?->typeLabel(), 'location' => $c->equipment?->location,
                'kind' => $c->kind === 'service' ? 'Service' : 'Visual', 'result' => $c->result === 'pass' ? 'Pass' : 'FAIL', 'faults' => $c->faults ? implode('; ', $c->faults) : '',
                'cert' => $c->certificate_no ?? '', 'ticket' => $c->ticket?->ticket_no ?? '', 'by' => $c->contractor ?? $c->user?->name, '_class' => $c->result === 'fail' ? 'row-red' : '']);

        return ['cols' => ['date' => 'Date', 'code' => 'Item', 'type' => 'Type', 'location' => 'Location', 'kind' => 'Kind', 'result' => 'Result', 'faults' => 'Faults', 'cert' => 'Certificate',
            'ticket' => 'Ticket', 'by' => 'Checked by'], 'rows' => $rows, 'money' => [], 'sum' => [],
            'summary' => ['Checks' => (string) $rows->count(), 'Failed' => (string) $rows->where('result', 'FAIL')->count(), 'Services' => (string) $rows->where('kind', 'Service')->count()]];
    }

    protected function r_hse_accidents($from, $to): array
    {
        $list = \App\Models\HseAccident::with(['employee', 'guest'])->whereBetween('occurred_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])->orderByDesc('occurred_at')->get();
        $rows = $list->map(fn ($a) => ['no' => $a->number, 'when' => $a->occurred_at->format('d M Y H:i'), 'kind' => $a->kindLabel().($a->occurrence ? ' – '.\App\Models\HseAccident::OCCURRENCES[$a->occurrence] : ''),
            'where' => $a->location, 'person' => $a->personLabel(), 'ptype' => $a->person_type ? \App\Models\HseAccident::PERSONS[$a->person_type] : '',
            'injury' => trim(($a->injury_type ? \App\Models\HseAccident::INJURIES[$a->injury_type] : '').($a->body_part ? ' · '.\App\Models\HseAccident::BODY[$a->body_part] : '')),
            'outcome' => $a->severityLabel(), 'lost' => $a->lost_days ?: null, 'notice' => $a->reportable ? ($a->notified_on ? 'sent '.$a->notified_on->format('d M') : 'DUE') : '',
            'status' => \App\Models\HseAccident::STATUSES[$a->status], '_class' => $a->reportable && ! $a->notified_on ? 'row-red' : ($a->isLostTime() ? 'row-amber' : '')]);
        $ltis = $list->filter(fn ($a) => $a->kind === 'injury' && in_array($a->person_type, ['staff', 'contractor'], true) && $a->isLostTime())->count();
        $months = max(1, $from->copy()->startOfDay()->diffInDays($to->copy()->endOfDay(), true) / 30.44);
        $hours = \App\Models\Employee::where('is_active', true)->count() * \App\Services\HseService::cfg()['hours_month'] * $months;

        return ['cols' => ['no' => 'Ref', 'when' => 'When', 'kind' => 'Kind', 'where' => 'Where', 'person' => 'Person', 'ptype' => 'Type', 'injury' => 'Injury', 'outcome' => 'Outcome',
            'lost' => 'Days lost', 'notice' => 'Act 328 notice', 'status' => 'Status'], 'rows' => $rows, 'money' => [], 'sum' => ['lost'],
            'summary' => ['Injuries' => (string) $list->where('kind', 'injury')->count(), 'Near misses' => (string) $list->where('kind', 'near_miss')->count(),
                'Dangerous occurrences' => (string) $list->where('kind', 'dangerous')->count(), 'Lost-time injuries' => (string) $ltis,
                'LTIFR' => $hours ? (string) round($ltis * 1000000 / $hours, 2) : '—'],
            'note' => 'LTIFR = lost-time injuries to staff and contractors × 1,000,000 ÷ hours worked (active employees × the hours per month in HSE settings). Act 328 s. 10: deaths and injuries disabling a person employed for more than 3 days; s. 11: every dangerous occurrence – notify the Factories Inspectorate forthwith.'];
    }

    // ───────────── v6.9 talent management ─────────────

    protected function r_talent_vacancies($from, $to): array
    {
        $list = \App\Models\TalentVacancy::with(['department', 'requester', 'approver'])->withCount(['applicants', 'applicants as hires_count' => fn ($q) => $q->where('stage', 'hired')])
            ->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])->orderByDesc('id')->get();
        $rows = $list->map(function ($v) {
            $days = $v->approved_at && $v->filled_on ? (int) $v->approved_at->copy()->startOfDay()->diffInDays($v->filled_on) : null;

            return ['no' => $v->number, 'title' => $v->title, 'dept' => $v->department->name, 'type' => $v->typeLabel(), 'posts' => $v->filled.'/'.$v->headcount,
                'min' => (float) $v->salary_min, 'max' => (float) $v->salary_max, 'reason' => \App\Models\TalentVacancy::REASONS[$v->reason], 'req' => $v->requester?->name ?? '',
                'appr' => $v->approver ? $v->approver->name.' · '.$v->approved_at->format('d M') : '', 'apps' => $v->applicants_count, 'hires' => $v->hires_count,
                'days' => $days, 'status' => $v->statusLabel(), '_class' => $v->status === 'pending' ? 'row-amber' : ''];
        });
        $filled = $rows->whereNotNull('days');

        return ['cols' => ['no' => 'Ref', 'title' => 'Vacancy', 'dept' => 'Department', 'type' => 'Type', 'posts' => 'Filled', 'min' => 'Salary from', 'max' => 'Salary to', 'reason' => 'Reason',
            'req' => 'Requested by', 'appr' => 'Approved', 'apps' => 'Applicants', 'hires' => 'Hired', 'days' => 'Days to fill', 'status' => 'Status'], 'rows' => $rows,
            'money' => ['min', 'max'], 'sum' => ['apps', 'hires'],
            'summary' => ['Vacancies' => (string) $list->count(), 'Posts' => (string) $list->sum('headcount'), 'Filled' => (string) $list->sum('filled'),
                'Open' => (string) $list->where('status', 'open')->count(), 'Average days to fill' => $filled->isNotEmpty() ? number_format($filled->avg('days'), 0) : '—']];
    }

    protected function r_talent_pipeline($from, $to): array
    {
        $apps = \App\Models\TalentApplicant::with(['vacancy.department', 'interviews.scorecards', 'offers'])->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])->get();
        $rows = $apps->groupBy('vacancy_id')->map(function ($g) {
            $v = $g->first()->vacancy;
            $iv = $g->filter(fn ($a) => $a->interviews->isNotEmpty());
            $sc = $g->flatMap(fn ($a) => $a->interviews->flatMap->scorecards);
            $hired = $g->where('stage', 'hired')->count();

            return ['vac' => $v->number.' '.$v->title, 'dept' => $v->department->name, 'apps' => $g->count(),
                'screened' => $g->filter(fn ($a) => $a->screen_rating || $a->interviews->isNotEmpty() || in_array($a->stage, ['screening', 'interview', 'offer', 'hired'], true))->count(),
                'interviewed' => $iv->count(), 'offered' => $g->filter(fn ($a) => $a->offers->isNotEmpty())->count(),
                'hired' => $hired, 'rejected' => $g->whereIn('stage', ['rejected', 'withdrawn'])->count(), 'conv' => $g->count() ? number_format($hired / $g->count() * 100, 1).'%' : '',
                'score' => $sc->isNotEmpty() ? number_format($sc->avg('average'), 2) : ''];
        })->values();
        $src = $apps->groupBy('source')->map(fn ($g, $k) => (\App\Models\TalentApplicant::SOURCES[$k] ?? $k).' '.$g->count().' ('.$g->where('stage', 'hired')->count().' hired)')->implode(' · ');

        return ['cols' => ['vac' => 'Vacancy', 'dept' => 'Department', 'apps' => 'Applicants', 'screened' => 'Screened', 'interviewed' => 'Interviewed', 'offered' => 'Offered', 'hired' => 'Hired',
            'rejected' => 'Rejected / withdrawn', 'conv' => 'Applicant → hire', 'score' => 'Avg interview score'], 'rows' => $rows, 'money' => [],
            'sum' => ['apps', 'screened', 'interviewed', 'offered', 'hired', 'rejected'],
            'summary' => ['Applicants' => (string) $apps->count(), 'Hired' => (string) $apps->where('stage', 'hired')->count(),
                'Still in progress' => (string) $apps->whereIn('stage', \App\Models\TalentApplicant::OPEN)->count(), 'Anonymised' => (string) $apps->whereNotNull('anonymised_at')->count()],
            'note' => $src ? 'By source: '.$src : null];
    }

    protected function r_talent_appraisals($from, $to): array
    {
        $list = \App\Models\TalentAppraisal::with(['cycle', 'employee.department', 'reviewer', 'signer'])
            ->whereHas('cycle', fn ($q) => $q->whereBetween('period_to', [$from->toDateString(), $to->toDateString()]))->get()->sortBy(fn ($a) => $a->cycle_id.'|'.$a->employee->name)->values();
        $rows = $list->map(fn ($a) => ['cycle' => $a->cycle->name, 'emp' => $a->employee->name, 'dept' => $a->employee->department?->name ?? '', 'rev' => $a->reviewer?->name ?? '',
            'goals' => $a->goals_score !== null ? (float) $a->goals_score : null, 'comp' => $a->comp_score !== null ? (float) $a->comp_score : null,
            'overall' => $a->overall !== null ? number_format($a->overall, 2) : '', 'band' => $a->bandLabel() ?? '', 'pip' => $a->pip ? 'PIP' : '',
            'status' => $a->statusLabel(), 'signed' => $a->signed_at ? $a->signer?->name.' · '.$a->signed_at->format('d M') : '', 'ack' => $a->acknowledged_at?->format('d M Y') ?? '',
            '_class' => $a->pip ? 'row-red' : ($a->status !== 'signed' ? 'row-amber' : '')]);
        $scored = $list->whereNotNull('overall');

        return ['cols' => ['cycle' => 'Cycle', 'emp' => 'Employee', 'dept' => 'Department', 'rev' => 'Reviewer', 'goals' => 'Goals', 'comp' => 'Competencies', 'overall' => 'Overall',
            'band' => 'Band', 'pip' => 'PIP', 'status' => 'Status', 'signed' => 'Signed off', 'ack' => 'Acknowledged'], 'rows' => $rows, 'money' => [], 'sum' => [],
            'summary' => ['Appraisals' => (string) $list->count(), 'Signed off' => (string) $list->where('status', 'signed')->count(),
                'Average overall' => $scored->isNotEmpty() ? number_format($scored->avg('overall'), 2) : '—', 'On a PIP' => (string) $list->where('pip', true)->count(),
                'Exceeds or better' => (string) $scored->where('band', '>=', 4)->count()]];
    }

    protected function r_talent_discipline($from, $to): array
    {
        $u = auth()->user();
        $list = \App\Models\TalentCase::with(['employee.department', 'raiser', 'decider'])->whereBetween('incident_on', [$from->toDateString(), $to->toDateString()])->orderByDesc('incident_on')->get();
        $rows = $list->map(function ($c) use ($u) {
            $see = \App\Services\TalentService::canSeeCase($c, $u);

            return ['no' => $c->number, 'kind' => ucfirst($c->kind), 'emp' => $see ? $c->employee->name : 'Confidential', 'dept' => $c->employee->department?->name ?? '',
                'cat' => $see ? $c->categoryLabel() : 'Confidential', 'sev' => $c->severity ? \App\Models\TalentCase::SEVERITY[$c->severity] : '', 'on' => $c->incident_on->format('d M Y'),
                'query' => $c->query_issued_on ? $c->query_issued_on->format('d M').' → '.($c->replied_on ? 'replied '.$c->replied_on->format('d M') : 'reply by '.$c->reply_due->format('d M')) : '',
                'hearing' => $c->hearing_at ? $c->hearing_at->format('d M').($c->hearing_held ? ' · held' : '') : '', 'finding' => $c->finding ? ($c->finding === 'proven' ? 'Proven' : 'Not proven') : '',
                'outcome' => $see ? ($c->outcomeLabel() ?? ($c->kind === 'grievance' && $c->resolution ? 'Resolved' : '')) : '', 'expires' => $c->expires_on?->format('d M Y') ?? '',
                'status' => $c->statusLabel(), '_class' => $c->outcome === 'dismissal' ? 'row-red' : ''];
        });
        $disc = $list->where('kind', 'discipline');

        return ['cols' => ['no' => 'Case', 'kind' => 'Type', 'emp' => 'Employee', 'dept' => 'Department', 'cat' => 'Category', 'sev' => 'Severity', 'on' => 'Incident', 'query' => 'Query',
            'hearing' => 'Hearing', 'finding' => 'Finding', 'outcome' => 'Sanction / resolution', 'expires' => 'On file until', 'status' => 'Status'], 'rows' => $rows, 'money' => [], 'sum' => [],
            'summary' => ['Disciplinary cases' => (string) $disc->count(), 'Proven' => (string) $disc->where('finding', 'proven')->count(), 'Dismissals' => (string) $disc->where('outcome', 'dismissal')->count(),
                'Grievances' => (string) $list->where('kind', 'grievance')->count(), 'Open' => (string) $list->whereNotIn('status', ['closed', 'withdrawn', 'decided', 'resolved'])->count()]];
    }

    protected function r_talent_exits($from, $to): array
    {
        $list = \App\Models\TalentExit::with('employee.department')->where('status', '!=', 'withdrawn')->whereBetween('last_day', [$from->toDateString(), $to->toDateString()])->orderBy('last_day')->get();
        $rows = $list->map(fn ($x) => ['no' => $x->number, 'emp' => $x->employee->name, 'dept' => $x->employee->department?->name ?? '', 'type' => $x->typeLabel(),
            'service' => number_format(\App\Services\TalentService::serviceYears($x->employee, $x->last_day), 1), 'notice' => $x->notice_days, 'last' => $x->last_day->format('d M Y'),
            'pay' => $x->final_pay_due->format('d M Y'), 'pilon' => $x->pilon ? (float) $x->pilon_amount : null,
            'why' => isset($x->interview['primary']) ? (\App\Services\TalentService::EXIT_REASONS[$x->interview['primary']] ?? '') : '', 'reg' => $x->regretted ? 'Yes' : '', 'rehire' => $x->rehire ? 'Yes' : 'No',
            'status' => $x->statusLabel(), '_class' => $x->status === 'notice' ? 'row-amber' : '']);
        $t = \App\Services\TalentService::turnover($to->copy());

        return ['cols' => ['no' => 'Ref', 'emp' => 'Employee', 'dept' => 'Department', 'type' => 'Type', 'service' => 'Service (yrs)', 'notice' => 'Notice (days)', 'last' => 'Last day',
            'pay' => 'Final pay by', 'pilon' => 'Pay in lieu', 'why' => 'Main reason', 'reg' => 'Regretted', 'rehire' => 'Re-hire', 'status' => 'Status'], 'rows' => $rows,
            'money' => ['pilon'], 'sum' => ['pilon'],
            'summary' => ['Leavers' => (string) $list->count(), 'Voluntary' => (string) $list->filter->isVoluntary()->count(), 'Regretted' => (string) $list->where('regretted', true)->count(),
                '12-month turnover to '.$to->format('d M Y') => $t['rate'].'%', 'Voluntary turnover' => $t['voluntary_rate'].'%']];
    }

    protected function r_talent_headcount($from, $to): array
    {
        $d = $to->toDateString();
        $y = $to->copy()->subYear()->addDay()->toDateString();
        $emps = \App\Models\Employee::get();
        $inPost = fn ($e, $on) => (! $e->joining_date || $e->joining_date->toDateString() <= $on) && ($e->leaving_date ? $e->leaving_date->toDateString() >= $on : $e->is_active);
        $vac = \App\Models\TalentVacancy::whereIn('status', ['open', 'on_hold', 'pending'])->get()->groupBy('department_id');
        $rows = \App\Models\Department::orderBy('name')->get()->map(function ($dep) use ($emps, $inPost, $d, $y, $vac) {
            $mine = $emps->where('department_id', $dep->id);
            $now = $mine->filter(fn ($e) => $inPost($e, $d))->count();
            $start = $mine->filter(fn ($e) => $inPost($e, $y))->count();
            $leavers = $mine->filter(fn ($e) => $e->leaving_date && $e->leaving_date->toDateString() >= $y && $e->leaving_date->toDateString() <= $d)->count();
            $avg = ($now + $start) / 2;

            return ['dept' => $dep->name, 'est' => $dep->establishment, 'staff' => $now, 'gap' => $dep->establishment !== null ? $dep->establishment - $now : null,
                'open' => $vac->get($dep->id, collect())->sum(fn ($v) => $v->remaining()) ?: null,
                'starters' => $mine->filter(fn ($e) => $e->joining_date && $e->joining_date->toDateString() >= $y && $e->joining_date->toDateString() <= $d)->count(),
                'leavers' => $leavers, 'turn' => $avg > 0 ? number_format($leavers / $avg * 100, 1).'%' : '',
                'prob' => $mine->filter(fn ($e) => $inPost($e, $d) && $e->probation_end && ! $e->confirmed_on)->count(),
                '_class' => $dep->establishment !== null && $now > $dep->establishment ? 'row-amber' : ''];
        });

        return ['cols' => ['dept' => 'Department', 'est' => 'Establishment', 'staff' => 'In post', 'gap' => 'Gap', 'open' => 'Open posts', 'starters' => 'Starters (12 m)', 'leavers' => 'Leavers (12 m)',
            'turn' => 'Turnover', 'prob' => 'On probation'], 'rows' => $rows, 'money' => [], 'sum' => ['est', 'staff', 'gap', 'open', 'starters', 'leavers', 'prob'],
            'summary' => ['In post' => (string) $rows->sum('staff'), 'Establishment' => (string) $rows->sum('est'), 'Open posts' => (string) $rows->sum('open'),
                'Starters (12 m)' => (string) $rows->sum('starters'), 'Leavers (12 m)' => (string) $rows->sum('leavers')]];
    }

    // ───────── v7.0 sustainability & ESG

    protected function r_esg_carbon($from, $to): array
    {
        $ES = \App\Services\EsgService::class;
        $cb = $ES::carbon($from->toDateString(), $to->toDateString());
        $h = $ES::hcmi($from->toDateString(), $to->toDateString());
        $rows = collect($cb['rows'])->sortBy('scope')->map(fn ($r) => ['scope' => 'Scope '.$r['scope'], 'source' => $r['label'], 'qty' => round($r['qty'], 1), 'unit' => $r['unit'],
            'factor' => $r['factor'] ?: null, 'kg' => round($r['kg'], 1), 'share' => $cb['total_kg'] > 0 ? number_format($r['kg'] / $cb['total_kg'] * 100, 1).'%' : '', 'note' => $r['note']])->values();

        return ['cols' => ['scope' => 'Scope', 'source' => 'Source', 'qty' => 'Activity', 'unit' => 'Unit', 'factor' => 'kg CO₂e / unit', 'kg' => 'kg CO₂e', 'share' => 'Share', 'note' => 'Note'],
            'rows' => $rows, 'money' => [], 'sum' => ['kg'],
            'summary' => ['Total t CO₂e' => number_format($cb['total_kg'] / 1000, 2), 'Scope 1 t' => number_format($cb['scopes'][1] / 1000, 2), 'Scope 2 t' => number_format($cb['scopes'][2] / 1000, 2),
                'Scope 3 t' => number_format($cb['scopes'][3] / 1000, 2), 'HCMI kg / occupied room' => $h['per_room'] !== null ? number_format($h['per_room'], 2) : '—',
                'HCMI kg / m²·h meeting' => $h['per_m2h'] !== null ? number_format($h['per_m2h'], 4) : '—', 'HWMI L / occupied room' => $h['hwmi_room'] !== null ? number_format($h['hwmi_room']) : '—',
                'Occupied rooms' => number_format($h['occupied'])],
            'note' => 'HCMI boundary = Scope 1 + 2 + outsourced laundry, less private area, split rooms : meeting space by floor area. Grid factor '.\App\Services\UtilityService::cfg()['ef']['electricity'].' kg/kWh.'];
    }

    protected function r_esg_waste($from, $to): array
    {
        $ES = \App\Services\EsgService::class;
        $list = \App\Models\EsgWasteLog::live()->with(['department', 'supplier'])->whereBetween('date', [$from->toDateString(), $to->toDateString()])->orderBy('date')->orderBy('id')->get();
        $rows = $list->map(fn ($w) => ['no' => $w->number, 'date' => $w->date->format('d-m-Y'), 'stream' => $w->streamLabel(), 'route' => $w->disposalLabel(), 'diverted' => $w->isDiverted() ? 'Yes' : 'No',
            'kg' => (float) $w->weight_kg, 'dept' => $w->department?->name, 'contractor' => $w->supplier?->name ?? $w->contractor, 'wtn' => $w->manifest_no,
            'cost' => (float) $w->cost ?: null, 'income' => (float) $w->income ?: null, 'co2' => round((float) $w->weight_kg * $ES::wasteFactor($w->stream, $w->disposal), 1)]);
        $s = $ES::waste($from->toDateString(), $to->toDateString());

        return ['cols' => ['no' => 'Ref', 'date' => 'Date', 'stream' => 'Stream', 'route' => 'Route', 'diverted' => 'Diverted', 'kg' => 'kg', 'dept' => 'Department', 'contractor' => 'Contractor',
            'wtn' => 'Transfer note', 'cost' => 'Cost', 'income' => 'Income', 'co2' => 'kg CO₂e'], 'rows' => $rows, 'money' => ['cost', 'income'], 'sum' => ['kg', 'cost', 'income', 'co2'],
            'summary' => ['Total kg' => number_format($s['total'], 1), 'Diverted' => $s['diversion'] !== null ? $s['diversion'].'%' : '—', 'Food waste kg' => number_format($s['food'], 1),
                'kg per occupied room' => $s['per_room'] !== null ? (string) $s['per_room'] : '—', 'kg CO₂e' => number_format($s['co2'], 1), 'Avoided vs landfill kg CO₂e' => number_format($s['avoided'], 1)]];
    }

    protected function r_esg_refrigerants($from, $to): array
    {
        $R = \App\Models\EsgRefrigerantLog::class;
        $list = $R::with(['asset', 'ticket'])->whereBetween('date', [$from->toDateString(), $to->toDateString()])->orderBy('date')->get();
        $leaks = collect(\App\Services\EsgService::leakRates())->keyBy(fn ($l) => mb_strtolower($l['equipment']));
        $rows = $list->map(fn ($r) => ['no' => $r->number, 'date' => $r->date->format('d-m-Y'), 'system' => $r->equipment, 'asset' => $r->asset?->asset_no, 'gas' => $r->gas, 'gwp' => $R::gwp($r->gas),
            'work' => $R::REASONS[$r->reason] ?? $r->reason, 'added' => (float) $r->kg_added ?: null, 'recovered' => (float) $r->kg_recovered ?: null, 'co2' => $r->co2e(),
            'rate' => ($l = $leaks->get(mb_strtolower($r->equipment))) && $l['rate'] !== null ? $l['rate'].'%' : '', 'tech' => $r->technician, 'cert' => $r->certificate_no, 'ticket' => $r->ticket?->ticket_no,
            '_class' => ($l['flag'] ?? false) ? 'row-red' : '']);

        return ['cols' => ['no' => 'Ref', 'date' => 'Date', 'system' => 'System', 'asset' => 'Asset', 'gas' => 'Gas', 'gwp' => 'GWP', 'work' => 'Work', 'added' => 'kg added', 'recovered' => 'kg recovered',
            'co2' => 'kg CO₂e', 'rate' => 'Leak rate (12 m)', 'tech' => 'Technician', 'cert' => 'Certificate', 'ticket' => 'Ticket'], 'rows' => $rows, 'money' => [], 'sum' => ['added', 'recovered', 'co2'],
            'summary' => ['t CO₂e' => number_format($rows->sum('co2') / 1000, 2), 'kg recovered' => number_format($rows->sum('recovered'), 2), 'Systems above alert' => (string) $leaks->where('flag', true)->count()]];
    }

    protected function r_esg_green($from, $to): array
    {
        $list = \App\Models\EsgGreenNight::with(['room', 'guest', 'greenStay'])->whereDate('business_date', '>=', $from->toDateString())->whereDate('business_date', '<=', $to->toDateString())->orderBy('business_date')->orderBy('room_id')->get();
        $s = \App\Services\EsgService::greenStats($from->toDateString(), $to->toDateString());
        $rows = $list->map(fn ($n) => ['date' => $n->business_date->format('d-m-Y'), 'room' => $n->room?->room_no, 'guest' => $n->guest?->full_name, 'source' => $n->greenStay?->source === 'portal' ? 'Guest portal' : 'Front desk',
            'points' => $n->points ?: null, 'kwh' => $n->kwh, 'litres' => $n->litres, 'co2' => $n->co2_kg]);

        return ['cols' => ['date' => 'Date', 'room' => 'Room', 'guest' => 'Guest', 'source' => 'Chosen at', 'points' => 'Points', 'kwh' => 'kWh saved', 'litres' => 'Litres saved', 'co2' => 'kg CO₂e saved'],
            'rows' => $rows, 'money' => [], 'sum' => ['points', 'kwh', 'litres', 'co2'],
            'summary' => ['Green nights' => (string) $s['nights'], 'Uptake' => $s['uptake'] !== null ? $s['uptake'].'% of occupied room-nights' : '—', 'Points' => number_format($s['points']),
                'kg CO₂e saved' => number_format($s['co2'], 1)], 'note' => 'Savings are estimates from the per-night kWh and litres in ESG settings.'];
    }

    protected function r_esg_initiatives($from, $to): array
    {
        $I = \App\Models\EsgInitiative::class;
        $list = $I::with(['owner', 'approver'])->orderBy('number')->get();
        $rows = $list->map(fn ($i) => ['no' => $i->number, 'title' => $i->title, 'cat' => $I::CATEGORIES[$i->category] ?? $i->category, 'owner' => $i->owner?->name, 'status' => $i->statusLabel(),
            'capex' => (float) $i->capex, 'saving' => (float) $i->saving_ghs, 'payback' => $i->payback() !== null ? $i->payback().' yrs' : '', 'kwh' => $i->saving_kwh ?: null, 'm3' => $i->saving_m3 ?: null,
            'waste' => $i->saving_waste_kg ?: null, 'co2' => $i->saving_co2_kg, 'approver' => $i->approver?->name, 'due' => $i->due_on?->format('d-m-Y'), 'done' => $i->completed_on?->format('d-m-Y'),
            '_class' => $i->isLate() ? 'row-red' : '']);
        $live = $list->whereIn('status', ['approved', 'in_progress', 'done']);

        return ['cols' => ['no' => 'Ref', 'title' => 'Initiative', 'cat' => 'Category', 'owner' => 'Owner', 'status' => 'Status', 'capex' => 'Capex', 'saving' => 'Saving / yr', 'payback' => 'Payback',
            'kwh' => 'kWh / yr', 'm3' => 'm³ / yr', 'waste' => 'Waste kg / yr', 'co2' => 'kg CO₂e / yr', 'approver' => 'Approved by', 'due' => 'Due', 'done' => 'Completed'],
            'rows' => $rows, 'money' => ['capex', 'saving'], 'sum' => ['capex', 'saving', 'kwh', 'm3', 'waste', 'co2'],
            'summary' => ['Approved capex' => money($live->sum('capex')), 'Savings / yr' => money($live->sum('saving_ghs')), 't CO₂e / yr' => number_format($live->sum('saving_co2_kg') / 1000, 1),
                'Awaiting decision' => (string) $list->where('status', 'idea')->count()]];
    }

    // ───────── v7.1 IPTV & guest welcome ─────────
    protected function r_iptv_devices($from, $to): array
    {
        $D = \App\Models\TvDevice::class;
        $list = $D::with('room')->where('status', '!=', 'pending')->orderBy('kind')->orderBy('name')->get();
        $stays = \App\Models\ReservationRoom::with(['guest', 'reservation.guest'])->where('status', 'checked_in')->whereIn('room_id', $list->pluck('room_id')->filter())->get()->keyBy('room_id');
        $rows = $list->map(function ($d) use ($D, $stays) {
            $st = $d->room_id ? ($stays[$d->room_id] ?? null) : null;
            $g = $st ? ($st->guest ?? $st->reservation?->guest) : null;

            return ['name' => $d->name, 'kind' => $D::KINDS[$d->kind] ?? $d->kind, 'where' => $d->place(), 'platform' => $D::PLATFORMS[$d->platform] ?? $d->platform, 'model' => $d->model,
                'mac' => $d->mac, 'serial' => $d->serial, 'status' => $d->stateLabel(), 'seen' => $d->last_seen_at?->format('d-m-Y H:i'), 'paired' => $d->paired_at?->format('d-m-Y'),
                'guest' => $g ? trim($g->first_name.' '.$g->last_name) : null, '_class' => $d->status === 'active' && ! $d->isOnline() ? 'row-red' : ''];
        });
        $active = $list->where('status', 'active');

        return ['cols' => ['name' => 'TV', 'kind' => 'Type', 'where' => 'Room / location', 'platform' => 'Platform', 'model' => 'Model', 'mac' => 'MAC', 'serial' => 'Serial', 'status' => 'Status',
            'seen' => 'Last heartbeat', 'paired' => 'Paired', 'guest' => 'Guest now'], 'rows' => $rows, 'money' => [], 'sum' => [],
            'summary' => ['TVs' => (string) $list->count(), 'Active' => (string) $active->count(), 'Online now' => (string) $active->filter->isOnline()->count(),
                'Rooms covered' => $active->where('kind', 'room')->pluck('room_id')->filter()->unique()->count().' of '.\App\Models\Room::where('is_active', true)->count()]];
    }

    protected function r_iptv_vod($from, $to): array
    {
        $list = \App\Models\TvPurchase::with(['room', 'stay.guest', 'stay.reservation.guest', 'refunder'])->whereBetween('starts_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])->orderBy('starts_at')->get();
        $rows = $list->map(function ($p) {
            $g = $p->stay?->guest ?? $p->stay?->reservation?->guest;

            return ['no' => $p->number, 'date' => $p->starts_at->format('d-m-Y H:i'), 'room' => $p->room?->room_no, 'guest' => $g ? trim($g->first_name.' '.$g->last_name) : null, 'title' => $p->title,
                'amount' => (float) $p->amount, 'tax' => (float) $p->tax ?: null, 'total' => (float) $p->total, 'status' => $p->state(),
                'refund' => $p->refund_reason ? $p->refund_reason.' ('.($p->refunder?->name ?? '').')' : null, '_class' => $p->status === 'refunded' ? 'muted' : ''];
        });
        $live = $list->where('status', '!=', 'refunded');

        return ['cols' => ['no' => 'Ref', 'date' => 'Started', 'room' => 'Room', 'guest' => 'Guest', 'title' => 'Movie', 'amount' => 'Before tax', 'tax' => 'VAT & levies', 'total' => 'Total', 'status' => 'Status', 'refund' => 'Refund'],
            'rows' => $rows, 'money' => ['amount', 'tax', 'total'], 'sum' => [],
            'summary' => ['Rentals' => (string) $live->count(), 'Revenue before tax' => money($live->sum('amount')), 'Charged incl. tax' => money($live->sum('total')),
                'Refunded' => $list->where('status', 'refunded')->count().' · '.money($list->where('status', 'refunded')->sum('total'))],
            'note' => 'Posted to room folios as “Pay TV / Movies” (GL 4047 In-room Entertainment Revenue). Refunded rentals are voided on the folio and excluded from the totals.'];
    }

    protected function r_iptv_messages($from, $to): array
    {
        $M = \App\Models\TvMessage::class;
        $list = $M::with(['sender', 'closer'])->withCount('reads')->whereBetween('starts_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])->orderBy('starts_at')->get();
        $rows = $list->map(fn ($m) => ['no' => $m->number, 'kind' => $M::KINDS[$m->kind] ?? $m->kind, 'to' => $m->targetLabel(), 'title' => $m->title, 'prio' => $m->priority === 'high' ? 'High' : '',
            'from' => $m->starts_at->format('d-m-Y H:i'), 'until' => $m->ends_at?->format('d-m-Y H:i') ?? ($m->closed_at?->format('d-m-Y H:i')), 'by' => $m->sender?->name, 'reads' => $m->reads_count ?: null,
            'state' => $m->state(), 'note' => $m->close_note ? $m->close_note.' – '.($m->closer?->name ?? '') : null, '_class' => $m->kind === 'emergency' ? 'row-red' : '']);
        $em = $list->where('kind', 'emergency');

        return ['cols' => ['no' => 'Ref', 'kind' => 'Kind', 'to' => 'Audience', 'title' => 'Title', 'prio' => 'Priority', 'from' => 'From', 'until' => 'Until', 'by' => 'Sent by', 'reads' => 'Reads', 'state' => 'State', 'note' => 'Withdrawn / all clear'],
            'rows' => $rows, 'money' => [], 'sum' => ['reads'],
            'summary' => ['Messages' => (string) $list->where('kind', '!=', 'emergency')->count(), 'Emergency alerts' => (string) $em->count(),
                'Longest alert' => $em->filter(fn ($m) => $m->closed_at)->map(fn ($m) => (int) round($m->starts_at->diffInMinutes($m->closed_at)))->max() !== null ? $em->filter(fn ($m) => $m->closed_at)->map(fn ($m) => (int) round($m->starts_at->diffInMinutes($m->closed_at)))->max().' min' : '—']];
    }

    protected function r_iptv_engagement($from, $to): array
    {
        [$a, $b] = [$from->copy()->startOfDay(), $to->copy()->endOfDay()];
        $ev = \App\Models\TvEvent::whereBetween('created_at', [$a, $b])->selectRaw("DATE(created_at) d, COUNT(*) n, COUNT(DISTINCT tv_device_id) tvs, SUM(event = 'welcome') w, SUM(event = 'channel') ch, SUM(event = 'rent') r, SUM(event = 'order') o, SUM(event IN ('request', 'wakeup', 'checkout')) q")
            ->groupBy('d')->get()->keyBy('d');
        $rows = collect();
        for ($d = $a->copy(); $d->lte($b) && $rows->count() < 400; $d->addDay()) {
            $x = $ev->get($d->toDateString());
            $rows->push(['date' => $d->format('d-m-Y'), 'tvs' => $x ? (int) $x->tvs : null, 'n' => $x ? (int) $x->n : null, 'w' => $x && $x->w ? (int) $x->w : null, 'ch' => $x && $x->ch ? (int) $x->ch : null,
                'r' => $x && $x->r ? (int) $x->r : null, 'o' => $x && $x->o ? (int) $x->o : null, 'q' => $x && $x->q ? (int) $x->q : null]);
        }
        $an = \App\Services\IptvService::analytics($from->toDateString(), $to->toDateString());

        return ['cols' => ['date' => 'Date', 'tvs' => 'TVs used', 'n' => 'Screen actions', 'w' => 'Welcomes', 'ch' => 'Channel views', 'r' => 'Rentals', 'o' => 'Dining orders', 'q' => 'Requests & wake-ups'],
            'rows' => $rows, 'money' => [], 'sum' => ['n', 'w', 'ch', 'r', 'o', 'q'],
            'summary' => ['Stays using the TV portal' => $an['adoption'] !== null ? $an['adoption'].'% ('.$an['stays_used'].' of '.$an['stays'].')' : '—', 'Screen actions' => number_format($an['events']),
                'Rental revenue' => money($an['rental_rev']), 'Dining ordered' => money($an['order_value'])]];
    }

    protected function r_iptv_orders($from, $to): array
    {
        [$a, $b] = [$from->copy()->startOfDay(), $to->copy()->endOfDay()];
        $rows = collect();
        foreach (\App\Models\PosOrder::with(['stay.room', 'items'])->where('source', 'tv')->whereBetween('created_at', [$a, $b])->get() as $o) {
            $items = $o->items->where('is_cancelled', false);
            $rows->push(['at' => $o->created_at, 'type' => 'Dining order', 'ref' => $o->order_no, 'room' => $o->stay?->room?->room_no, 'detail' => $items->map(fn ($i) => $i->qty.'× '.$i->item_name)->implode(', '),
                'status' => ucfirst((string) $o->status), 'value' => round((float) $items->sum('amount'), 2) ?: null]);
        }
        foreach (\App\Models\GuestRequest::with('stay.room')->where('source', 'tv')->whereBetween('created_at', [$a, $b])->get() as $r) {
            $rows->push(['at' => $r->created_at, 'type' => $r->category === 'checkout' ? 'Express check-out' : 'Service request', 'ref' => $r->request_no, 'room' => $r->stay?->room?->room_no,
                'detail' => trim($r->categoryLabel().' · '.$r->item, ' ·'), 'status' => \App\Models\GuestRequest::STATUSES[$r->status] ?? $r->status, 'value' => null]);
        }
        foreach (\App\Models\WakeupCall::with('stay.room')->where('source', 'tv')->whereBetween('created_at', [$a, $b])->get() as $w) {
            $rows->push(['at' => $w->created_at, 'type' => 'Wake-up call', 'ref' => null, 'room' => $w->stay?->room?->room_no, 'detail' => $w->call_at->format('d-m-Y H:i').($w->daily ? ' · daily' : ''),
                'status' => $w->statusLabel(), 'value' => null]);
        }
        $rows = $rows->sortBy('at')->values()->map(fn ($r) => ['at' => $r['at']->format('d-m-Y H:i')] + $r);

        return ['cols' => ['at' => 'Placed', 'type' => 'Type', 'ref' => 'Ref', 'room' => 'Room', 'detail' => 'Detail', 'status' => 'Status', 'value' => 'Value'], 'rows' => $rows, 'money' => ['value'], 'sum' => ['value'],
            'summary' => collect(['Dining order', 'Service request', 'Express check-out', 'Wake-up call'])->mapWithKeys(fn ($t) => [$t.'s' => (string) $rows->where('type', $t)->count()])->all()];
    }

    protected function r_inbox_conversations($from, $to): array
    {
        $C = \App\Models\Conversation::class;
        $IS = \App\Services\InboxService::class;
        $list = $C::with('guest', 'stay.room', 'assignee')->withCount(['messages as n_in' => fn ($q) => $q->where('direction', 'in'), 'messages as n_out' => fn ($q) => $q->where('direction', 'out')])
            ->whereBetween('opened_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])->orderBy('opened_at')->get();
        $first = \App\Models\ConversationMessage::whereIn('conversation_id', $list->pluck('id'))->whereNotNull('response_sec')->orderBy('id')->get(['conversation_id', 'response_sec'])->groupBy('conversation_id')->map(fn ($g) => $g->first()->response_sec);
        $sla = $IS::cfg()['sla'] * 60;
        $rows = $list->map(fn ($c) => ['no' => $c->number, 'at' => $c->opened_at?->format('d-m-Y H:i'), 'ch' => $c->channelLabel(), 'guest' => $c->displayName(), 'room' => $c->roomNo(),
            'dept' => $c->departmentLabel(), 'agent' => $c->assignee?->name, 'msgs' => $c->n_in.' / '.$c->n_out, 'first' => isset($first[$c->id]) ? $IS::fmtSecs($first[$c->id]) : null,
            'csat' => $c->csat ? str_repeat('★', $c->csat) : null, 'status' => $c->statusLabel(),
            '_class' => $c->isOverdue($IS::cfg()['sla']) ? 'row-red' : (isset($first[$c->id]) && $first[$c->id] > $sla ? 'row-amber' : '')]);
        $rated = $list->whereNotNull('csat');

        return ['cols' => ['no' => 'Conversation', 'at' => 'Started', 'ch' => 'Channel', 'guest' => 'Guest', 'room' => 'Room', 'dept' => 'Team', 'agent' => 'Assigned', 'msgs' => 'Guest / staff msgs', 'first' => 'First response', 'csat' => 'Rating', 'status' => 'Status'],
            'rows' => $rows, 'money' => [], 'sum' => [],
            'summary' => ['Conversations' => (string) $list->count(), 'Still open' => (string) $list->whereIn('status', $C::ACTIVE)->count(),
                'Answered within target' => $first->count() ? round($first->filter(fn ($s) => $s <= $sla)->count() * 100 / $first->count()).'%' : '—',
                'Average rating' => $rated->count() ? round($rated->avg('csat'), 1).' / 5 ('.$rated->count().')' : '—']];
    }

    protected function r_inbox_response($from, $to): array
    {
        $M = \App\Models\ConversationMessage::class;
        $IS = \App\Services\InboxService::class;
        $sla = $IS::cfg()['sla'] * 60;
        $msgs = $M::whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])->whereIn('direction', ['in', 'out'])->get(['conversation_id', 'direction', 'auto', 'response_sec', 'created_at', 'broadcast_id']);
        $rows = collect();
        for ($d = $from->copy()->startOfDay(); $d->lte($to); $d->addDay()) {
            $day = $msgs->filter(fn ($m) => $m->created_at->isSameDay($d));
            $resp = $day->whereNotNull('response_sec');
            $rows->push(['day' => $d->format('d-m-Y D'), 'conv' => $day->pluck('conversation_id')->unique()->count() ?: null, 'in' => $day->where('direction', 'in')->count() ?: null,
                'out' => $day->where('direction', 'out')->where('auto', false)->whereNull('broadcast_id')->count() ?: null, 'auto' => $day->where('direction', 'out')->where('auto', true)->count() ?: null,
                'bc' => $day->whereNotNull('broadcast_id')->count() ?: null, 'avg' => $resp->count() ? $IS::fmtSecs((int) round($resp->avg('response_sec'))) : null,
                'max' => $resp->count() ? $IS::fmtSecs((int) $resp->max('response_sec')) : null,
                'sla' => $resp->count() ? round($resp->filter(fn ($m) => $m->response_sec <= $sla)->count() * 100 / $resp->count()).'%' : null]);
        }
        $all = $msgs->whereNotNull('response_sec');

        return ['cols' => ['day' => 'Day', 'conv' => 'Conversations', 'in' => 'Guest messages', 'out' => 'Staff replies', 'auto' => 'Automatic', 'bc' => 'Broadcast', 'avg' => 'Avg response', 'max' => 'Slowest', 'sla' => 'Within target'],
            'rows' => $rows, 'money' => [], 'sum' => ['in', 'out', 'auto', 'bc'],
            'summary' => ['Guest messages' => (string) $msgs->where('direction', 'in')->count(), 'Average response' => $all->count() ? $IS::fmtSecs((int) round($all->avg('response_sec'))) : '—',
                'Within the '.($sla / 60).'-minute target' => $all->count() ? round($all->filter(fn ($m) => $m->response_sec <= $sla)->count() * 100 / $all->count()).'%' : '—',
                'Answered automatically' => (string) $msgs->where('direction', 'out')->where('auto', true)->count()]];
    }

    protected function r_inbox_agents($from, $to): array
    {
        $M = \App\Models\ConversationMessage::class;
        $IS = \App\Services\InboxService::class;
        $sla = $IS::cfg()['sla'] * 60;
        [$a, $b] = [$from->copy()->startOfDay(), $to->copy()->endOfDay()];
        $out = $M::with('user')->where('direction', 'out')->where('auto', false)->whereNotNull('user_id')->whereNull('broadcast_id')->whereBetween('created_at', [$a, $b])->get();
        $notes = $M::where('direction', 'note')->whereBetween('created_at', [$a, $b])->get(['user_id'])->countBy('user_id');
        $closed = \App\Models\Conversation::whereBetween('closed_at', [$a, $b])->get(['closed_by', 'csat']);
        $rows = $out->groupBy('user_id')->map(function ($g, $uid) use ($IS, $sla, $notes, $closed) {
            $r = $g->whereNotNull('response_sec');
            $cl = $closed->where('closed_by', $uid);
            $rated = $cl->whereNotNull('csat');

            return ['who' => $g->first()->user?->name, 'conv' => $g->pluck('conversation_id')->unique()->count(), 'replies' => $g->count(), 'notes' => $notes[$uid] ?? null,
                'first' => $r->count() ?: null, 'avg' => $r->count() ? $IS::fmtSecs((int) round($r->avg('response_sec'))) : null,
                'sla' => $r->count() ? round($r->filter(fn ($m) => $m->response_sec <= $sla)->count() * 100 / $r->count()).'%' : null, 'closed' => $cl->count() ?: null,
                'csat' => $rated->count() ? round($rated->avg('csat'), 1) : null, '_class' => $r->count() && $r->avg('response_sec') > $sla ? 'row-amber' : ''];
        })->sortByDesc('replies')->values();

        return ['cols' => ['who' => 'Staff member', 'conv' => 'Conversations', 'replies' => 'Replies', 'notes' => 'Internal notes', 'first' => 'First responses', 'avg' => 'Avg response', 'sla' => 'Within target', 'closed' => 'Closed', 'csat' => 'Avg rating'],
            'rows' => $rows, 'money' => [], 'sum' => ['replies', 'notes', 'closed'],
            'summary' => ['Staff who replied' => (string) $rows->count(), 'Replies' => (string) $out->count(), 'Target' => ($sla / 60).' minutes']];
    }

    protected function r_inbox_topics($from, $to): array
    {
        $C = \App\Models\Conversation::class;
        [$a, $b] = [$from->copy()->startOfDay(), $to->copy()->endOfDay()];
        $conv = $C::whereBetween('opened_at', [$a, $b])->get(['id', 'department', 'channel', 'csat', 'status']);
        $ruleHits = \App\Models\ConversationMessage::with('rule')->whereNotNull('rule_id')->whereBetween('created_at', [$a, $b])->get(['rule_id'])->countBy('rule_id');
        $rows = collect();
        foreach ($C::DEPARTMENTS as $k => $l) {
            $g = $conv->where('department', $k);
            if ($g->isEmpty()) {
                continue;
            }
            $rated = $g->whereNotNull('csat');
            $rows->push(['kind' => 'Team', 'name' => $l, 'n' => $g->count(), 'share' => round($g->count() * 100 / max(1, $conv->count())).'%',
                'detail' => collect($C::CHANNELS)->map(fn ($cl, $ck) => $g->where('channel', $ck)->count() ? $cl.' '.$g->where('channel', $ck)->count() : null)->filter()->implode(' · '),
                'csat' => $rated->count() ? round($rated->avg('csat'), 1) : null]);
        }
        foreach (\App\Models\InboxRule::orderBy('sort')->get() as $r) {
            if (! ($n = $ruleHits[$r->id] ?? 0)) {
                continue;
            }
            $rows->push(['kind' => 'Automatic answer', 'name' => $r->name, 'n' => $n, 'share' => null, 'detail' => $r->actionsLabel(), 'csat' => null]);
        }

        return ['cols' => ['kind' => 'Type', 'name' => 'Team / rule', 'n' => 'Conversations', 'share' => 'Share', 'detail' => 'Detail', 'csat' => 'Avg rating'],
            'rows' => $rows, 'money' => [], 'sum' => [],
            'summary' => ['Conversations' => (string) $conv->count(), 'Busiest team' => ($top = $conv->countBy('department')->sortDesc()->keys()->first()) ? ($C::DEPARTMENTS[$top] ?? $top) : '—',
                'Rule answers' => (string) $ruleHits->sum()]];
    }

    // ---------------------------------------------------------------- v7.4 partner portal

    protected function r_partner_bookings($from, $to): array
    {
        $PS = \App\Services\PartnerService::class;
        $list = \App\Models\Reservation::with('company', 'partnerUser', 'guest', 'rooms.roomType')->whereNotNull('partner_user_id')
            ->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])->orderBy('created_at')->get();
        $rows = $list->map(function ($r) use ($PS) {
            $live = $r->rooms->where('status', '!=', 'cancelled');
            $set = $live->isEmpty() ? $r->rooms : $live;

            return ['no' => $r->reservation_no, 'at' => $r->created_at->format('d-m-Y H:i'), 'partner' => $r->company?->name, 'booker' => $r->partnerUser?->name, 'guest' => $r->guest?->full_name,
                'dates' => $r->arrival_date->format('d M').' – '.$r->departure_date->format('d M Y'), 'rooms' => $set->count(), 'type' => $set->first()?->roomType?->name,
                'nights' => $r->status === 'cancelled' ? 0 : $live->sum(fn ($x) => $x->nights), 'value' => $r->status === 'cancelled' ? 0 : round($live->sum(fn ($x) => $PS::stayValue($x)), 2),
                'bill' => $r->billing_instruction === 'company' ? 'Company' : 'Traveller', 'ref' => $r->partner_ref, 'status' => ['tentative' => 'Held for approval'][$r->status] ?? ucfirst(str_replace('_', ' ', $r->status)),
                '_class' => $r->status === 'tentative' ? 'row-amber' : ($r->status === 'cancelled' ? 'row-red' : '')];
        });
        $live = $list->where('status', '!=', 'cancelled');

        return ['cols' => ['no' => 'Booking', 'at' => 'Booked', 'partner' => 'Partner', 'booker' => 'Booker', 'guest' => 'Traveller', 'dates' => 'Stay', 'rooms' => 'Rooms', 'type' => 'Room type', 'nights' => 'Room nights', 'value' => 'Stay value', 'bill' => 'Bill to', 'ref' => 'Reference', 'status' => 'Status'],
            'rows' => $rows, 'money' => ['value'], 'sum' => ['rooms', 'nights', 'value'],
            'summary' => ['Portal bookings' => (string) $list->count(), 'Held for approval' => (string) $list->where('status', 'tentative')->count(), 'Cancelled' => (string) $list->where('status', 'cancelled')->count(),
                'Billed to company' => $live->count() ? round($live->where('billing_instruction', 'company')->count() * 100 / $live->count()).'%' : '—']];
    }

    protected function r_partner_production($from, $to): array
    {
        $PS = \App\Services\PartnerService::class;
        $a = $from->copy()->startOfDay();
        $b = $to->copy()->endOfDay();
        $companies = \App\Models\Company::whereIn('type', ['corporate', 'travel_agent'])->where(fn ($q) => $q->where('portal_enabled', true)->orWhereHas('partnerUsers'))->orderBy('name')->get();
        $rows = $companies->map(function ($c) use ($a, $b, $PS) {
            $all = \App\Models\Reservation::with('rooms')->where('company_id', $c->id)->whereBetween('created_at', [$a, $b])->get();
            $portal = $all->whereNotNull('partner_user_id');
            $live = $portal->where('status', '!=', 'cancelled');

            return ['partner' => $c->name, 'type' => $c->type === 'travel_agent' ? 'Travel agent' : 'Corporate', 'portal' => $c->portal_enabled ? 'On' : 'Off',
                'users' => \App\Models\PartnerUser::where('company_id', $c->id)->where('is_active', true)->whereNotNull('password')->count(),
                'logins' => \App\Models\PartnerAudit::where('company_id', $c->id)->where('action', 'login')->whereBetween('created_at', [$a, $b])->count(),
                'bookings' => $portal->count(), 'nights' => $live->sum(fn ($r) => $r->rooms->where('status', '!=', 'cancelled')->sum(fn ($x) => $x->nights)),
                'value' => round($live->sum(fn ($r) => $r->rooms->where('status', '!=', 'cancelled')->sum(fn ($x) => $PS::stayValue($x))), 2),
                'cancelled' => $portal->where('status', 'cancelled')->count(), 'cxl' => $portal->count() ? round($portal->where('status', 'cancelled')->count() * 100 / $portal->count()).'%' : '—',
                'held' => $portal->where('status', 'tentative')->count(), 'online' => $all->count() ? round($portal->count() * 100 / $all->count()).'%' : '—'];
        })->filter(fn ($r) => $r['portal'] === 'On' || $r['bookings'] > 0)->sortByDesc('value')->values();

        return ['cols' => ['partner' => 'Partner', 'type' => 'Type', 'portal' => 'Portal', 'users' => 'Active logins', 'logins' => 'Sign-ins', 'bookings' => 'Portal bookings', 'nights' => 'Room nights', 'value' => 'Stay value', 'cancelled' => 'Cancelled', 'cxl' => 'Cancel %', 'held' => 'Held', 'online' => 'Booked online %'],
            'rows' => $rows, 'money' => ['value'], 'sum' => ['users', 'logins', 'bookings', 'nights', 'value', 'cancelled', 'held'],
            'summary' => ['Partners on the portal' => (string) $companies->where('portal_enabled', true)->count(), 'Portal bookings' => (string) $rows->sum('bookings'), 'Stay value' => money($rows->sum('value'))]];
    }

    protected function r_partner_requests($from, $to): array
    {
        $list = \App\Models\PartnerRequest::with('company', 'partnerUser', 'reservation', 'decider')->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])->orderBy('created_at')->get();
        $rows = $list->map(fn ($q) => ['no' => $q->number, 'at' => $q->created_at->format('d-m-Y H:i'), 'type' => \App\Models\PartnerRequest::TYPES[$q->type], 'partner' => $q->company?->name, 'by' => $q->partnerUser?->name,
            'booking' => $q->reservation?->reservation_no, 'what' => \Illuminate\Support\Str::limit($q->reason ?: $q->details, 110), 'status' => \App\Models\PartnerRequest::STATUSES[$q->status],
            'decider' => $q->decider?->name, 'note' => \Illuminate\Support\Str::limit((string) $q->decision_note, 90), 'hours' => $q->responseHours(),
            '_class' => $q->status === 'pending' && $q->responseHours() > 24 ? 'row-red' : ($q->status === 'pending' ? 'row-amber' : '')]);
        $answered = $list->whereNotIn('status', ['pending', 'withdrawn']);

        return ['cols' => ['no' => 'Request', 'at' => 'Received', 'type' => 'Type', 'partner' => 'Partner', 'by' => 'From', 'booking' => 'Booking', 'what' => 'Reason / request', 'status' => 'Status', 'decider' => 'Answered by', 'note' => 'Answer', 'hours' => 'Hours'],
            'rows' => $rows, 'money' => [], 'sum' => [],
            'summary' => ['Requests' => (string) $list->count(), 'Still open' => (string) $list->where('status', 'pending')->count(),
                'Approvals approved' => $list->where('type', 'approval')->whereIn('status', ['approved', 'declined'])->count() ? round($list->where('type', 'approval')->where('status', 'approved')->count() * 100 / $list->where('type', 'approval')->whereIn('status', ['approved', 'declined'])->count()).'%' : '—',
                'Average answer time' => $answered->count() ? round($answered->avg(fn ($q) => $q->responseHours()), 1).' h' : '—']];
    }

    protected function r_partner_access($from, $to): array
    {
        $list = \App\Models\PartnerAudit::with('company', 'partnerUser')->whereIn('action', ['login', 'login_failed', 'login_blocked', 'locked', 'unlocked', 'logout', 'timeout', 'password', 'reset_requested', 'user'])
            ->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])->orderBy('created_at')->get();
        $rows = $list->map(fn ($x) => ['at' => $x->created_at->format('d-m-Y H:i:s'), 'partner' => $x->company?->name ?? '—', 'user' => $x->partnerUser ? $x->partnerUser->name.' <'.$x->partnerUser->email.'>' : 'unknown',
            'action' => str_replace('_', ' ', $x->action), 'detail' => $x->detail, 'ip' => $x->ip,
            '_class' => in_array($x->action, ['locked', 'login_blocked']) ? 'row-red' : ($x->action === 'login_failed' ? 'row-amber' : '')]);

        return ['cols' => ['at' => 'When', 'partner' => 'Partner', 'user' => 'Login', 'action' => 'Event', 'detail' => 'Detail', 'ip' => 'IP address'],
            'rows' => $rows, 'money' => [], 'sum' => [],
            'summary' => ['Sign-ins' => (string) $list->where('action', 'login')->count(), 'Failed passwords' => (string) $list->where('action', 'login_failed')->count(),
                'Lock-outs' => (string) $list->where('action', 'locked')->count(), 'Distinct IPs' => (string) $list->pluck('ip')->filter()->unique()->count()]];
    }

    // ---------------------------------------------------------------- v7.5 organiser portal

    protected function r_organiser_events($from, $to): array
    {
        $OS = \App\Services\OrganiserService::class;
        $list = \App\Models\BanquetEvent::with('company', 'functions')->where('portal_enabled', true)->get()
            ->sortBy(fn ($e) => [$e->functions->min('date')?->toDateString() ?? '9999', $e->event_no])->values();
        $online = \App\Models\OnlinePayment::where('purpose', 'banquet')->where('status', 'paid')->whereIn('banquet_event_id', $list->pluck('id'))
            ->selectRaw('banquet_event_id, sum(amount_received) s')->groupBy('banquet_event_id')->pluck('s', 'banquet_event_id');
        $rows = $list->map(function ($e) use ($OS, $online) {
            $m = $OS::money($e);

            return ['no' => $e->event_no, 'org' => $e->company?->name ?? \App\Models\OrganiserUser::where('banquet_event_id', $e->id)->value('name'),
                'title' => $e->title, 'n' => $e->functions->count(), 'dates' => ($e->functions->min('date')?->format('d-m-Y') ?? '—').($e->functions->count() > 1 ? ' → '.($e->functions->max('date')?->format('d-m-Y') ?? '') : ''),
                'status' => ucfirst($e->status), 'logins' => \App\Models\OrganiserUser::where('is_active', true)->whereNotNull('password')
                    ->where(fn ($q) => $q->when($e->company_id, fn ($w) => $w->orWhere('company_id', $e->company_id))->orWhere('banquet_event_id', $e->id))->count(),
                'beo' => $e->beo_approved_at ? 'Signed' : ($e->beo_published_at ? 'Published' : 'Draft'),
                'paid' => $m['deposits'], 'online' => round((float) ($online[$e->id] ?? 0), 2), 'bal' => $m['balance'],
                '_class' => $e->beo_published_at && ! $e->beo_approved_at ? 'row-amber' : ''];
        });

        return ['cols' => ['no' => 'Event', 'org' => 'Organiser', 'title' => 'Title', 'n' => 'Functions', 'dates' => 'Dates', 'status' => 'Status', 'logins' => 'Active logins', 'beo' => 'BEO', 'paid' => 'Deposits', 'online' => 'Paid online', 'bal' => 'Balance'],
            'rows' => $rows, 'money' => ['paid', 'online', 'bal'], 'sum' => ['n', 'logins', 'paid', 'online', 'bal'],
            'summary' => ['Events on the portal' => (string) $list->count(), 'BEO signed' => (string) $list->whereNotNull('beo_approved_at')->count(),
                'Awaiting signature' => (string) $list->whereNotNull('beo_published_at')->whereNull('beo_approved_at')->count(),
                'Paid online' => money($rows->sum('online')), 'Still owed' => money($rows->sum('bal'))],
            'note' => 'Current portal book – every event shared with an organiser, whatever the date range.'];
    }

    protected function r_organiser_payments($from, $to): array
    {
        $list = \App\Models\OnlinePayment::with('event')->where('purpose', 'banquet')->whereBetween('paid_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])->orderBy('paid_at')->get();
        $rows = $list->map(fn ($p) => ['at' => $p->paid_at->format('d-m-Y H:i'), 'event' => $p->event?->event_no, 'title' => \Illuminate\Support\Str::limit((string) $p->event?->title, 40),
            'payer' => $p->payer_name, 'channel' => str_replace('_', ' ', (string) $p->channel), 'amount' => (float) $p->received(), 'ref' => $p->provider_payment_id,
            'status' => ucfirst($p->status !== 'paid' ? $p->status : ($p->refunded_amount > 0 ? 'refunded' : 'paid')),
            '_class' => $p->status === 'refunded' || $p->status === 'disputed' ? 'row-red' : '']);

        return ['cols' => ['at' => 'Paid', 'event' => 'Event', 'title' => 'Title', 'payer' => 'Paid by', 'channel' => 'Channel', 'amount' => 'Amount', 'ref' => 'Gateway reference', 'status' => 'Status'],
            'rows' => $rows, 'money' => ['amount'], 'sum' => ['amount'],
            'summary' => ['Online payments' => (string) $list->count(), 'Collected' => money($list->sum(fn ($p) => $p->received())),
                'MoMo' => money($list->where('channel', 'mobile_money')->sum(fn ($p) => $p->received())), 'Card' => money($list->where('channel', 'card')->sum(fn ($p) => $p->received()))]];
    }

    protected function r_organiser_requests($from, $to): array
    {
        $list = \App\Models\OrganiserRequest::with('company', 'organiserUser', 'event', 'decider')->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])->orderBy('created_at')->get();
        $rows = $list->map(fn ($q) => ['no' => $q->number, 'at' => $q->created_at->format('d-m-Y H:i'), 'type' => \App\Models\OrganiserRequest::TYPES[$q->type],
            'org' => $q->company?->name ?? $q->organiserUser?->name, 'by' => $q->organiserUser?->name, 'event' => $q->event?->event_no,
            'what' => \Illuminate\Support\Str::limit($q->details ?: ($q->payload['title'] ?? ''), 110), 'status' => \App\Models\OrganiserRequest::STATUSES[$q->status],
            'decider' => $q->decider?->name, 'note' => \Illuminate\Support\Str::limit((string) $q->decision_note, 90), 'hours' => $q->responseHours(),
            '_class' => $q->status === 'pending' && $q->responseHours() > 24 ? 'row-red' : ($q->status === 'pending' ? 'row-amber' : '')]);
        $answered = $list->whereNotIn('status', ['pending', 'withdrawn']);

        return ['cols' => ['no' => 'Request', 'at' => 'Received', 'type' => 'Type', 'org' => 'Organiser', 'by' => 'From', 'event' => 'Event', 'what' => 'Request', 'status' => 'Status', 'decider' => 'Answered by', 'note' => 'Answer', 'hours' => 'Hours'],
            'rows' => $rows, 'money' => [], 'sum' => [],
            'summary' => ['Requests' => (string) $list->count(), 'Still open' => (string) $list->where('status', 'pending')->count(),
                'Average answer time' => $answered->count() ? round($answered->avg(fn ($q) => $q->responseHours()), 1).' h' : '—']];
    }

    protected function r_organiser_access($from, $to): array
    {
        $list = \App\Models\OrganiserAudit::with('company', 'organiserUser')->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])->orderBy('created_at')->get();
        $rows = $list->map(fn ($x) => ['at' => $x->created_at->format('d-m-Y H:i:s'), 'org' => $x->company?->name ?? '—', 'user' => $x->organiserUser ? $x->organiserUser->name.' <'.$x->organiserUser->email.'>' : 'unknown',
            'action' => str_replace('_', ' ', $x->action), 'detail' => $x->detail, 'ip' => $x->ip,
            '_class' => in_array($x->action, ['locked', 'login_blocked']) ? 'row-red' : ($x->action === 'login_failed' ? 'row-amber' : '')]);

        return ['cols' => ['at' => 'When', 'org' => 'Organiser', 'user' => 'Login', 'action' => 'Event', 'detail' => 'Detail', 'ip' => 'IP address'],
            'rows' => $rows, 'money' => [], 'sum' => [],
            'summary' => ['Sign-ins' => (string) $list->where('action', 'login')->count(), 'Failed passwords' => (string) $list->where('action', 'login_failed')->count(),
                'Lock-outs' => (string) $list->where('action', 'locked')->count(), 'Distinct IPs' => (string) $list->pluck('ip')->filter()->unique()->count()]];
    }

    protected function r_kiosk_sessions($from, $to): array
    {
        $S = \App\Models\KioskSession::class;
        $list = $S::with('kiosk', 'guest', 'reservation', 'room')->whereBetween('started_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])->orderBy('started_at')->get();
        $rows = $list->map(fn ($s) => ['no' => $s->number, 'at' => $s->started_at->format('d-m-Y H:i'), 'kiosk' => $s->kiosk?->name, 'kind' => $s->kindLabel(), 'guest' => $s->guest?->full_name,
            'res' => $s->reservation?->reservation_no, 'room' => $s->room?->room_no, 'step' => $s->stepLabel(), 'secs' => $s->durationLabel(), 'paid' => (float) $s->amount_paid ?: null,
            'status' => $s->statusLabel().($s->status === 'assist' ? ' – '.$s->assistLabel() : ''), '_class' => $s->status === 'assist' && ! $s->resolved_at ? 'row-red' : '']);
        $done = $list->where('status', 'completed');

        return ['cols' => ['no' => 'Visit', 'at' => 'Started', 'kiosk' => 'Kiosk', 'kind' => 'Service', 'guest' => 'Guest', 'res' => 'Booking', 'room' => 'Room', 'step' => 'Reached', 'secs' => 'Time', 'paid' => 'Paid', 'status' => 'Outcome'],
            'rows' => $rows, 'money' => ['paid'], 'sum' => ['paid'],
            'summary' => ['Visits' => (string) $list->count(), 'Finished at the kiosk' => $done->count().($list->count() ? ' ('.round($done->count() * 100 / $list->count()).'%)' : ''),
                'Handed to the desk' => (string) $list->where('status', 'assist')->count(), 'Walked away / started over' => (string) $list->whereIn('status', ['abandoned', 'cancelled'])->count()]];
    }

    protected function r_kiosk_adoption($from, $to): array
    {
        $S = \App\Models\KioskSession::class;
        [$a, $b] = [$from->copy()->startOfDay(), $to->copy()->endOfDay()];
        $sess = $S::whereBetween('started_at', [$a, $b])->get();
        $has = fn ($s, $step) => collect($s->trail)->contains(fn ($t) => ($t[1] ?? '') === $step);
        $ins = \App\Models\ReservationRoom::whereBetween('checked_in_at', [$a, $b])->get(['checked_in_at'])->groupBy(fn ($r) => $r->checked_in_at->toDateString());
        $outs = \App\Models\ReservationRoom::whereBetween('checked_out_at', [$a, $b])->get(['checked_out_at'])->groupBy(fn ($r) => $r->checked_out_at->toDateString());
        $rows = collect();
        $tot = ['in' => 0, 'kin' => 0];
        for ($d = $from->copy()->startOfDay(); $d->lte($to); $d->addDay()) {
            $k = $d->toDateString();
            $day = $sess->filter(fn ($s) => $s->started_at->toDateString() === $k);
            $kin = $day->where('kind', '!=', 'checkout')->filter(fn ($s) => $has($s, 'room'));
            $all = count($ins[$k] ?? []);
            $tot['in'] += $all;
            $tot['kin'] += $kin->count();
            $fin = $day->where('kind', 'checkin')->where('status', 'completed');
            $rows->push(['day' => $d->format('d-m-Y D'), 'in' => $all ?: null, 'kin' => $kin->count() ?: null, 'share' => $all ? round($kin->count() * 100 / $all).'%' : null,
                'out' => count($outs[$k] ?? []) ?: null, 'kout' => $day->where('kind', 'checkout')->filter(fn ($s) => $has($s, 'checkout'))->count() ?: null,
                'walk' => $day->where('kind', 'walkin')->filter(fn ($s) => $has($s, 'booked'))->count() ?: null, 'assist' => $day->where('status', 'assist')->count() ?: null,
                'avg' => $fin->count() ? gmdate('i:s', (int) round($fin->avg(fn ($s) => $s->seconds()))) : null]);
        }

        return ['cols' => ['day' => 'Day', 'in' => 'Check-ins', 'kin' => 'At the kiosk', 'share' => 'Kiosk share', 'out' => 'Check-outs', 'kout' => 'At the kiosk', 'walk' => 'Walk-ins booked', 'assist' => 'Handed to desk', 'avg' => 'Avg check-in (m:s)'],
            'rows' => $rows, 'money' => [], 'sum' => ['in', 'kin', 'out', 'kout', 'walk', 'assist'],
            'summary' => ['Kiosk share of check-ins' => $tot['in'] ? round($tot['kin'] * 100 / $tot['in']).'%' : '—', 'Kiosk visits' => (string) $sess->count()]];
    }

    protected function r_kiosk_payments($from, $to): array
    {
        $S = \App\Models\KioskSession::class;
        $list = $S::with('kiosk', 'guest', 'reservation')->whereBetween('started_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])->where('amount_paid', '>', 0)->orderBy('started_at')->get();
        $pays = \App\Models\OnlinePayment::whereIn('id', $list->pluck('online_payment_id')->filter())->get()->keyBy('id');
        $rows = $list->map(function ($s) use ($pays) {
            $p = $s->online_payment_id ? ($pays[$s->online_payment_id] ?? null) : null;
            $ref = collect($s->trail)->filter(fn ($t) => ($t[1] ?? '') === 'paid')->map(fn ($t) => strtok((string) $t[2], ' '))->implode(', ');

            return ['at' => $s->started_at->format('d-m-Y H:i'), 'no' => $s->number, 'kiosk' => $s->kiosk?->name, 'kind' => $s->kindLabel(), 'guest' => $s->guest?->full_name, 'res' => $s->reservation?->reservation_no,
                'link' => $ref ?: $p?->pay_no, 'purpose' => $s->kind === 'checkout' ? 'Bill balance' : ($s->kind === 'walkin' ? 'Walk-in stay' : 'Check-in deposit'), 'amount' => (float) $s->amount_paid, 'gw' => $p ? ucfirst($p->provider).' · '.$p->status : 'paid'];
        });

        return ['cols' => ['at' => 'When', 'no' => 'Visit', 'kiosk' => 'Kiosk', 'kind' => 'Service', 'guest' => 'Guest', 'res' => 'Booking', 'link' => 'Payment link', 'purpose' => 'For', 'amount' => 'Amount', 'gw' => 'Gateway'],
            'rows' => $rows, 'money' => ['amount'], 'sum' => ['amount'],
            'summary' => collect(['Check-in deposit', 'Walk-in stay', 'Bill balance'])->mapWithKeys(fn ($t) => [$t.'s' => money($rows->where('purpose', $t)->sum('amount'))])->all()];
    }

    protected function r_kiosk_assist($from, $to): array
    {
        $S = \App\Models\KioskSession::class;
        $list = $S::with('kiosk', 'guest', 'reservation', 'resolvedBy')->where('status', 'assist')->whereBetween('assist_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])->orderBy('assist_at')->get();
        $rows = $list->map(fn ($s) => ['at' => $s->assist_at->format('d-m-Y H:i'), 'no' => $s->number, 'kiosk' => $s->kiosk?->name, 'kind' => $s->kindLabel(), 'guest' => $s->guest?->full_name,
            'res' => $s->reservation?->reservation_no, 'reason' => $s->assistLabel(), 'note' => $s->assist_note, 'wait' => $s->resolved_at ? (int) round($s->assist_at->diffInMinutes($s->resolved_at)) : null,
            'by' => $s->resolvedBy?->name, 'done' => $s->resolution, '_class' => $s->resolved_at ? '' : 'row-red']);
        $resolved = $list->whereNotNull('resolved_at');

        return ['cols' => ['at' => 'When', 'no' => 'Visit', 'kiosk' => 'Kiosk', 'kind' => 'Service', 'guest' => 'Guest', 'res' => 'Booking', 'reason' => 'Reason', 'note' => 'Note', 'wait' => 'Minutes to help', 'by' => 'Dealt with by', 'done' => 'What was done'],
            'rows' => $rows, 'money' => [], 'sum' => [],
            'summary' => ['Requests' => (string) $list->count(), 'Still open' => (string) ($list->count() - $resolved->count()),
                'Average minutes to help' => $resolved->count() ? (string) round($resolved->avg(fn ($s) => $s->assist_at->diffInMinutes($s->resolved_at))) : '—']
                + $list->groupBy(fn ($s) => $s->assistLabel())->map(fn ($g) => (string) $g->count())->sortDesc()->take(4)->all()];
    }

    protected function r_iptv_channels($from, $to): array
    {
        $C = \App\Models\TvChannel::class;
        $list = $C::withCount(['programmes as ahead' => fn ($q) => $q->where('ends_at', '>', now())])->orderBy('number')->get();
        $views = \App\Models\TvEvent::where('event', 'channel')->where('created_at', '>=', now()->subDays(30))->selectRaw('target, COUNT(*) n')->groupBy('target')->pluck('n', 'target');
        $rows = $list->map(fn ($c) => ['no' => $c->number, 'name' => $c->name, 'cat' => $c->categoryLabel(), 'lang' => $c->language, 'radio' => $c->is_radio ? 'Radio' : '', 'stream' => $c->stream_url,
            'epg' => $c->epg_id, 'ahead' => $c->epg_id ? $c->ahead : null, 'views' => (int) ($views[$c->number.' '.$c->name] ?? 0) ?: null, 'active' => $c->is_active ? 'Yes' : 'No', '_class' => $c->is_active ? '' : 'muted']);

        return ['cols' => ['no' => 'No.', 'name' => 'Channel', 'cat' => 'Category', 'lang' => 'Language', 'radio' => 'Radio', 'stream' => 'Stream', 'epg' => 'Guide id', 'ahead' => 'Programmes ahead', 'views' => 'Views (30 days)', 'active' => 'In line-up'],
            'rows' => $rows, 'money' => [], 'sum' => ['views'],
            'summary' => ['Channels' => (string) $list->where('is_active', true)->count(), 'With a guide' => (string) $list->whereNotNull('epg_id')->count(), 'Most watched' => (string) ($views->sortDesc()->keys()->first() ?? '—')]];
    }

    protected function r_esg_local_spend($from, $to): array
    {
        $s = \App\Services\EsgService::localSpend($from->toDateString(), $to->toDateString());
        $rows = collect($s['suppliers'])->map(fn ($x) => ['supplier' => $x['name'], 'local' => $x['local'] ? 'Local' : '', 'bills' => $x['bills'], 'spend' => $x['spend'],
            'share' => $s['total'] > 0 ? number_format($x['spend'] / $s['total'] * 100, 1).'%' : '']);

        return ['cols' => ['supplier' => 'Supplier', 'local' => 'Local', 'bills' => 'Documents', 'spend' => 'Spend (net)', 'share' => 'Share'], 'rows' => $rows, 'money' => ['spend'], 'sum' => ['bills', 'spend'],
            'summary' => ['Total spend' => money($s['total']), 'With local suppliers' => money($s['local']), 'Local share' => $s['pct'] !== null ? $s['pct'].'%' : '—']];
    }

    protected function r_hse_permits($from, $to): array
    {
        $list = \App\Models\HsePermit::with(['requester', 'issuer', 'closer'])->whereBetween('starts_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])->orderByDesc('starts_at')->get();
        $rows = $list->map(function ($p) {
            $late = $p->closed_at && $p->status === 'closed' && $p->closed_at->gt($p->ends_at);
            $extra = array_filter([$p->fire_watch ? 'fire watch '.$p->fire_watch : null, $p->detectors_isolated ? 'detectors off: '.$p->detectors_isolated : null,
                $p->gas_o2 !== null ? 'O₂ '.number_format($p->gas_o2, 1).'%' : null, $p->isolation_points ? 'LOTO '.$p->isolation_points : null]);

            return ['no' => $p->number, 'type' => $p->typeLabel(), 'where' => $p->location, 'work' => $p->work, 'who' => $p->contractor ?? 'in-house',
                'period' => $p->starts_at->format('d M H:i').' – '.$p->ends_at->format('H:i'), 'req' => $p->requester?->name, 'issued' => $p->issuer?->name ?? '',
                'checks' => implode(' · ', $extra), 'closed' => $p->closed_at?->format('d M H:i') ?? '', 'status' => ($p->isOverdue() ? 'PAST END – open' : $p->statusLabel()).($late ? ' (late)' : ''),
                '_class' => $p->isOverdue() ? 'row-red' : ($late ? 'row-amber' : '')];
        });

        return ['cols' => ['no' => 'Permit', 'type' => 'Type', 'where' => 'Location', 'work' => 'Work', 'who' => 'Contractor', 'period' => 'Period', 'req' => 'Requested by', 'issued' => 'Issued by',
            'checks' => 'Fire watch / gas / isolation', 'closed' => 'Closed', 'status' => 'Status'], 'rows' => $rows, 'money' => [], 'sum' => [],
            'summary' => ['Permits' => (string) $list->count(), 'Hot work' => (string) $list->where('type', 'hot_work')->count(), 'Closed' => (string) $list->where('status', 'closed')->count(),
                'Open past end' => (string) $list->filter(fn ($p) => $p->isOverdue())->count(), 'Closed late' => (string) $rows->filter(fn ($r) => str_contains($r['status'], '(late)'))->count()]];
    }

    protected function r_hse_drills($from, $to): array
    {
        $list = \App\Models\HseDrill::with('leader')->whereBetween('scheduled_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])->orderByDesc('scheduled_at')->get();
        $rows = $list->map(fn ($x) => ['no' => $x->number, 'type' => $x->typeLabel(), 'shift' => ucfirst($x->shift), 'when' => ($x->alarm_at ?? $x->scheduled_at)->format('d M Y H:i'),
            'evac' => $x->evacLabel(), 'target' => intdiv($x->target_seconds, 60).' min'.($x->target_seconds % 60 ? ' '.($x->target_seconds % 60).' s' : ''),
            'roll' => $x->staff_expected ? $x->staff_accounted.' / '.$x->staff_expected : '', 'guests' => $x->guests_evacuated, 'result' => $x->status === 'done' ? strtoupper($x->result) : ucfirst($x->status),
            'lead' => $x->leader?->name, 'improve' => $x->improvements ?? '', '_class' => $x->result === 'fail' ? 'row-red' : '']);
        $done = $list->where('status', 'done');

        return ['cols' => ['no' => 'Ref', 'type' => 'Type', 'shift' => 'Shift', 'when' => 'When', 'evac' => 'Evacuation', 'target' => 'Target', 'roll' => 'Roll call', 'guests' => 'Guests',
            'result' => 'Result', 'lead' => 'Led by', 'improve' => 'Improvements'], 'rows' => $rows, 'money' => [], 'sum' => ['guests'],
            'summary' => ['Drills done' => (string) $done->count(), 'Passed' => (string) $done->where('result', 'pass')->count(),
                'Average evacuation' => $done->whereNotNull('evac_seconds')->count() ? gmdate('i:s', (int) round($done->avg('evac_seconds'))) : '—']];
    }

    protected function r_hse_risks($from, $to): array
    {
        $rows = \App\Models\HseRisk::with(['owner', 'acceptor', 'department'])->where('status', '!=', 'archived')->orderBy('number')->get()->map(function ($x) {
            [, $il] = \App\Models\HseRisk::band($x->score());
            [$rk, $rl] = \App\Models\HseRisk::band($x->residual());
            $late = $x->review_on->toDateString() < bdate()->toDateString();

            return ['no' => $x->number, 'title' => $x->title, 'area' => $x->area, 'hazard' => \Illuminate\Support\Str::limit($x->hazard, 120), 'who' => $x->who_at_risk,
                'init' => $x->score().' '.$il, 'controls' => \Illuminate\Support\Str::limit($x->controls, 140), 'res' => $x->residual().' '.$rl, 'owner' => $x->owner?->name ?? '',
                'accepted' => $x->accepted_at ? $x->acceptor?->name.' '.$x->accepted_at->format('d M Y') : ($x->status === 'pending' ? 'AWAITING' : ''),
                'review' => $x->review_on->format('d M Y').($late ? ' – overdue' : ''), '_class' => $late ? 'row-red' : ($rk === 'high' ? 'row-amber' : '')];
        });

        return ['cols' => ['no' => 'Ref', 'title' => 'Activity', 'area' => 'Area', 'hazard' => 'Hazard', 'who' => 'Who is at risk', 'init' => 'Risk', 'controls' => 'Controls', 'res' => 'Residual',
            'owner' => 'Owner', 'accepted' => 'Accepted', 'review' => 'Review'], 'rows' => $rows, 'money' => [], 'sum' => [],
            'summary' => ['Assessments' => (string) $rows->count(), 'High residual' => (string) $rows->filter(fn ($r) => str_contains($r['res'], 'High'))->count(),
                'Reviews overdue' => (string) $rows->where('_class', 'row-red')->count()],
            'note' => 'Risk = likelihood × severity (1–5 each): 1–4 low, 5–9 medium, 10–14 high (accepted by a manager other than the assessor), 15–25 very high (activity must not go ahead).'];
    }

    // ------------------------------------------------------------------ v6.8 F&B cost control

    protected function r_fbc_recipe_costs($from, $to): array
    {
        $rows = \App\Services\FbCostService::dishes()->map(fn ($m) => ['code' => $m->code, 'dish' => $m->name, 'category' => $m->category?->name, 'class' => ucfirst($m->cls),
            'price' => (float) $m->price, 'cost' => $m->cost, 'gp' => $m->gp, 'pct' => $m->pct !== null ? $m->pct.'%' : 'no recipe', 'target' => $m->target.'%',
            'suggested' => $m->suggested, 'creep' => $m->creep ? ($m->creep['pct'] > 0 ? '+' : '').$m->creep['pct'].'%' : '',
            'card' => $m->card ? 'v'.$m->card->version.($m->card->approved_at ? ' approved' : ' draft') : '',
            '_class' => $m->st === 'over' ? 'row-amber' : ($m->st === 'no_recipe' ? 'row-red' : '')]);
        $c = $rows->filter(fn ($r) => $r['cost'] !== null);

        return ['cols' => ['code' => 'Code', 'dish' => 'Dish', 'category' => 'Category', 'class' => 'Class', 'price' => 'Price', 'cost' => 'Plate cost', 'gp' => 'GP', 'pct' => 'Cost %',
            'target' => 'Target', 'suggested' => 'Price at target', 'creep' => 'Cost creep', 'card' => 'Recipe card'], 'rows' => $rows->values(), 'money' => ['price', 'cost', 'gp', 'suggested'], 'sum' => [],
            'summary' => ['Dishes' => (string) $rows->count(), 'Costed' => (string) $c->count(), 'Above target' => (string) $rows->where('_class', 'row-amber')->count(),
                'No recipe' => (string) $rows->where('_class', 'row-red')->count()],
            'note' => 'Plate cost = net quantity ÷ trim yield × '.(\App\Services\FbCostService::cfg()['basis'] === 'average' ? '90-day weighted average purchase cost' : 'last purchase cost').'. Prices exclude VAT, NHIL, GETFund and the tourism levy.'];
    }

    protected function r_fbc_menu_engineering($from, $to): array
    {
        $me = \App\Services\FbCostService::menuEngineering($from->toDateString(), $to->toDateString());
        $C = \App\Services\FbCostService::CLASSES;
        $rows = $me['rows']->map(fn ($r) => ['dish' => $r->item->name, 'category' => $r->item->category?->name, 'sold' => $r->n, 'mix' => $r->mix.'%', 'price' => $r->price, 'cost' => $r->cost,
            'cm' => $r->cm, 'revenue' => $r->revenue, 'total_cost' => $r->total_cost, 'total_cm' => $r->total_cm, 'class' => $C[$r->cls][0], 'action' => $C[$r->cls][2],
            '_class' => $r->cls === 'dog' ? 'row-red' : ($r->cls === 'plowhorse' ? 'row-amber' : '')]);

        return ['cols' => ['dish' => 'Menu item', 'category' => 'Category', 'sold' => 'Sold', 'mix' => 'Mix %', 'price' => 'Avg price', 'cost' => 'Plate cost', 'cm' => 'CM', 'revenue' => 'Revenue',
            'total_cost' => 'Total cost', 'total_cm' => 'Total CM', 'class' => 'Class', 'action' => 'Action'], 'rows' => $rows, 'money' => ['price', 'cost', 'cm', 'revenue', 'total_cost', 'total_cm'],
            'sum' => ['sold', 'revenue', 'total_cost', 'total_cm'],
            'summary' => ['Items sold' => number_format($me['n']), 'Average CM' => money($me['avg_cm']), 'Popularity threshold' => $me['threshold'].'%', 'Menu cost %' => ($me['pct'] ?? '—').'%',
                'Stars' => (string) $me['counts']['star'], 'Plowhorses' => (string) $me['counts']['plowhorse'], 'Puzzles' => (string) $me['counts']['puzzle'], 'Dogs' => (string) $me['counts']['dog']],
            'note' => 'Popular = menu mix ≥ '.\App\Services\FbCostService::cfg()['popularity'].'% of an equal share; profitable = CM ≥ the weighted average CM. Dishes without a recipe ('.$me['uncosted']->count().') are not analysed.'];
    }

    protected function r_fbc_food_cost($from, $to): array
    {
        $daily = \App\Services\FbCostService::daily($from->toDateString(), $to->toDateString());
        $cfg = \App\Services\FbCostService::cfg();
        $rows = collect($daily)->map(fn ($d, $dt) => ['date' => Carbon::parse($dt)->format('D d M Y'), 'f_rev' => $d['food']['revenue'], 'f_theo' => $d['food']['theoretical'],
            'f_act' => $d['food']['actual'], 'f_meals' => $d['food']['staff_meals'] ?: null, 'f_comps' => $d['food']['comps'] ?: null, 'f_cos' => $d['food']['cost_of_sales'],
            'f_pct' => $d['food']['pct'] !== null ? $d['food']['pct'].'%' : '', 'b_rev' => $d['beverage']['revenue'], 'b_cos' => $d['beverage']['cost_of_sales'],
            'b_pct' => $d['beverage']['pct'] !== null ? $d['beverage']['pct'].'%' : '', '_class' => $d['food']['pct'] !== null && $d['food']['pct'] > $cfg['food_target'] + 5 ? 'row-amber' : ''])->values();
        $f = \App\Services\FbCostService::statement($from->toDateString(), $to->toDateString(), 'food');
        $b = \App\Services\FbCostService::statement($from->toDateString(), $to->toDateString(), 'beverage');

        return ['cols' => ['date' => 'Date', 'f_rev' => 'Food revenue', 'f_theo' => 'Theoretical', 'f_act' => 'Cost consumed', 'f_meals' => 'Staff meals', 'f_comps' => 'Officers\' checks',
            'f_cos' => 'Cost of food sold', 'f_pct' => 'Food %', 'b_rev' => 'Beverage revenue', 'b_cos' => 'Cost of beverage sold', 'b_pct' => 'Beverage %'], 'rows' => $rows,
            'money' => ['f_rev', 'f_theo', 'f_act', 'f_meals', 'f_comps', 'f_cos', 'b_rev', 'b_cos'], 'sum' => ['f_rev', 'f_theo', 'f_act', 'f_meals', 'f_comps', 'f_cos', 'b_rev', 'b_cos'],
            'summary' => ['Food cost %' => ($f['pct'] ?? '—').'% (target '.$cfg['food_target'].'%)', 'Theoretical food %' => ($f['theoretical_pct'] ?? '—').'%', 'Food variance' => money($f['variance']),
                'Beverage cost %' => ($b['pct'] ?? '—').'% (target '.$cfg['bev_target'].'%)', 'Beverage variance' => money($b['variance'])],
            'note' => 'Cost consumed = recipe consumption + issues + waste + count adjustments from the kitchen, bar and outlet stores. USALI: employee meals and officers\' checks are transferred out to reach the cost of sales. Revenue is net of taxes and discounts.'];
    }

    protected function r_fbc_variance($from, $to): array
    {
        $q = fn ($v) => round($v, 3) ?: null;
        $list = \App\Services\FbCostService::usage($from->toDateString(), $to->toDateString());
        $rows = $list->map(fn ($r) => ['item' => $r->item->name, 'class' => ucfirst($r->item->category?->fb_class ?? ''), 'unit' => $r->item->unit, 'ideal' => $q($r->ideal), 'consumed' => $q($r->consumed),
            'issued' => $q($r->issued), 'wasted' => $q($r->wasted), 'counted' => $q($r->counted), 'actual' => $q($r->actual), 'variance' => $q($r->variance),
            'pct' => $r->pct !== null ? $r->pct.'%' : '', 'unit_cost' => $r->unit_cost, 'value' => $r->value, '_class' => $r->pct !== null && $r->pct > 10 && $r->value > 0 ? 'row-amber' : '']);

        return ['cols' => ['item' => 'Ingredient', 'class' => 'Class', 'unit' => 'Unit', 'ideal' => 'Ideal', 'consumed' => 'Recipe consumption', 'issued' => 'Issued', 'wasted' => 'Wasted',
            'counted' => 'Count adj.', 'actual' => 'Actual', 'variance' => 'Variance', 'pct' => '%', 'unit_cost' => 'Unit cost', 'value' => 'Value'], 'rows' => $rows,
            'money' => ['unit_cost', 'value'], 'sum' => ['value'],
            'summary' => ['Ingredients' => (string) $rows->count(), 'Over-usage' => money($list->where('value', '>', 0)->sum('value')), 'Net variance' => money($list->sum('value'))],
            'note' => 'Ideal = portions sold and complimentary × net recipe quantity ÷ trim yield. Actual = outflows from the kitchen, bar and outlet stores (consumption, issues, wastage and stock-count adjustments).'];
    }

    protected function r_fbc_price_changes($from, $to): array
    {
        $list = \App\Models\FbPriceChange::with(['menuItem', 'user'])->whereBetween('effective_on', [$from->toDateString(), $to->toDateString()])->orderByDesc('effective_on')->orderByDesc('id')->get();
        $rows = $list->map(fn ($c) => ['date' => $c->effective_on->format('d M Y'), 'dish' => $c->menuItem?->name, 'old' => $c->old_price, 'new' => $c->new_price,
            'change' => $c->old_price > 0 ? round(($c->new_price - $c->old_price) / $c->old_price * 100, 1).'%' : '', 'cost' => $c->cost_at,
            'before' => $c->old_price > 0 && $c->cost_at > 0 ? round($c->cost_at / $c->old_price * 100, 1).'%' : '', 'after' => $c->new_price > 0 && $c->cost_at > 0 ? round($c->cost_at / $c->new_price * 100, 1).'%' : '',
            'reason' => $c->reason, 'by' => $c->user?->name]);

        return ['cols' => ['date' => 'Date', 'dish' => 'Dish', 'old' => 'Old price', 'new' => 'New price', 'change' => 'Change', 'cost' => 'Plate cost', 'before' => 'Cost % before', 'after' => 'Cost % after',
            'reason' => 'Reason', 'by' => 'By'], 'rows' => $rows, 'money' => ['old', 'new', 'cost'], 'sum' => [],
            'summary' => ['Changes' => (string) $rows->count(), 'Increases' => (string) $list->filter(fn ($c) => $c->new_price > $c->old_price)->count(),
                'Decreases' => (string) $list->filter(fn ($c) => $c->new_price < $c->old_price)->count()]];
    }
}
