#!/usr/bin/env node
// Read-only static server for the preview screens (dashboard, fleet, POS, shift totals).
import { createServer } from 'node:http';
import { readFile, stat } from 'node:fs/promises';
import { extname, resolve, sep } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = resolve(fileURLToPath(new URL('../../', import.meta.url)));
const PORT = Number(process.env.PORT ?? 4182);
// PREVIEW_LANDING lets a second instance open straight onto one screen (the root keeps the fleet dashboard by default).
const LANDING_SLUG = /^[a-z0-9-]+$/.test(process.env.PREVIEW_LANDING ?? '') ? process.env.PREVIEW_LANDING : 'fleet-overview';
const MIME = { '.css': 'text/css; charset=utf-8', '.html': 'text/html; charset=utf-8', '.jpg': 'image/jpeg', '.png': 'image/png', '.svg': 'image/svg+xml', '.webp': 'image/webp' };

const server = createServer(async (req, res) => {
  const pathname = new URL(req.url ?? '/', 'http://preview.local').pathname;
  let file;
  if (pathname === '/previews' || pathname === '/__preview') {
    file = resolve(ROOT, 'deploy/dev/previews/index.html');
  } else if (pathname === '/') {
    // The root is a preview screen itself, so the panel opens on the module being worked on.
    file = resolve(ROOT, `deploy/dev/previews/${LANDING_SLUG}.html`);
  } else if (pathname === '/reports-library' || pathname === '/__preview/reports-library') {
    file = resolve(ROOT, 'deploy/dev/previews/reports-library.html');
  } else if (pathname === '/reports-report' || pathname === '/__preview/reports-report') {
    file = resolve(ROOT, 'deploy/dev/previews/reports-report.html');
  } else if (pathname === '/recreation-outlet' || pathname === '/__preview/recreation-outlet') {
    file = resolve(ROOT, 'deploy/dev/previews/recreation-outlet.html');
  } else if (pathname === '/recreation-outlet-closed' || pathname === '/__preview/recreation-outlet-closed') {
    file = resolve(ROOT, 'deploy/dev/previews/recreation-outlet-closed.html');
  } else if (pathname === '/recreation-shift' || pathname === '/__preview/recreation-shift') {
    file = resolve(ROOT, 'deploy/dev/previews/recreation-shift.html');
  } else if (pathname === '/recreation-day' || pathname === '/__preview/recreation-day') {
    file = resolve(ROOT, 'deploy/dev/previews/recreation-day.html');
  } else if (pathname === '/recreation-gate' || pathname === '/__preview/recreation-gate') {
    file = resolve(ROOT, 'deploy/dev/previews/recreation-gate.html');
  } else if (pathname === '/briefing-hub' || pathname === '/__preview/briefing-hub') {
    file = resolve(ROOT, 'deploy/dev/previews/briefing-hub.html');
  } else if (pathname === '/briefing-fnb' || pathname === '/__preview/briefing-fnb') {
    file = resolve(ROOT, 'deploy/dev/previews/briefing-fnb.html');
  } else if (pathname === '/briefing-housekeeping' || pathname === '/__preview/briefing-housekeeping') {
    file = resolve(ROOT, 'deploy/dev/previews/briefing-housekeeping.html');
  } else if (pathname === '/briefing-laundry' || pathname === '/__preview/briefing-laundry') {
    file = resolve(ROOT, 'deploy/dev/previews/briefing-laundry.html');
  } else if (pathname === '/briefing-finance' || pathname === '/__preview/briefing-finance') {
    file = resolve(ROOT, 'deploy/dev/previews/briefing-finance.html');
  } else if (pathname === '/configuration' || pathname === '/__preview/configuration') {
    file = resolve(ROOT, 'deploy/dev/previews/configuration.html');
  } else if (pathname === '/rentals-settings' || pathname === '/__preview/rentals-settings') {
    file = resolve(ROOT, 'deploy/dev/previews/rentals-settings.html');
  } else if (pathname === '/hr-settings' || pathname === '/__preview/hr-settings') {
    file = resolve(ROOT, 'deploy/dev/previews/hr-settings.html');
  } else if (pathname === '/configuration-search' || pathname === '/__preview/configuration-search') {
    file = resolve(ROOT, 'deploy/dev/previews/configuration-search.html');
  } else if (pathname === '/configuration-module' || pathname === '/__preview/configuration-module') {
    file = resolve(ROOT, 'deploy/dev/previews/configuration-module.html');
  } else if (pathname === '/configuration-module-findings' || pathname === '/__preview/configuration-module-findings') {
    file = resolve(ROOT, 'deploy/dev/previews/configuration-module-findings.html');
  } else if (pathname === '/configuration-changed' || pathname === '/__preview/configuration-changed') {
    file = resolve(ROOT, 'deploy/dev/previews/configuration-changed.html');
  } else if (pathname === '/configuration-on-null' || pathname === '/__preview/configuration-on-null') {
    file = resolve(ROOT, 'deploy/dev/previews/configuration-on-null.html');
  } else if (pathname === '/configuration-history' || pathname === '/__preview/configuration-history') {
    file = resolve(ROOT, 'deploy/dev/previews/configuration-history.html');
  } else if (pathname === '/configuration-handover' || pathname === '/__preview/configuration-handover') {
    file = resolve(ROOT, 'deploy/dev/previews/configuration-handover.html');
  } else if (pathname === '/configuration-register' || pathname === '/__preview/configuration-register') {
    file = resolve(ROOT, 'deploy/dev/previews/configuration-register.html');
  } else if (pathname === '/briefing-banquet' || pathname === '/__preview/briefing-banquet') {
    file = resolve(ROOT, 'deploy/dev/previews/briefing-banquet.html');
  } else if (pathname === '/briefing-it' || pathname === '/__preview/briefing-it') {
    file = resolve(ROOT, 'deploy/dev/previews/briefing-it.html');
  } else if (pathname === '/briefing-sales' || pathname === '/__preview/briefing-sales') {
    file = resolve(ROOT, 'deploy/dev/previews/briefing-sales.html');
  } else if (pathname === '/briefing-guestexp' || pathname === '/__preview/briefing-guestexp') {
    file = resolve(ROOT, 'deploy/dev/previews/briefing-guestexp.html');
  } else if (pathname === '/briefing-revenue' || pathname === '/__preview/briefing-revenue') {
    file = resolve(ROOT, 'deploy/dev/previews/briefing-revenue.html');
  } else if (pathname === '/briefing-maintenance' || pathname === '/__preview/briefing-maintenance') {
    file = resolve(ROOT, 'deploy/dev/previews/briefing-maintenance.html');
  } else if (pathname === '/briefing-kitchen' || pathname === '/__preview/briefing-kitchen') {
    file = resolve(ROOT, 'deploy/dev/previews/briefing-kitchen.html');
  } else if (pathname === '/briefing-stores' || pathname === '/__preview/briefing-stores') {
    file = resolve(ROOT, 'deploy/dev/previews/briefing-stores.html');
  } else if (pathname === '/briefing-frontoffice' || pathname === '/__preview/briefing-frontoffice') {
    file = resolve(ROOT, 'deploy/dev/previews/briefing-frontoffice.html');
  } else if (pathname === '/briefing-security' || pathname === '/__preview/briefing-security') {
    file = resolve(ROOT, 'deploy/dev/previews/briefing-security.html');
  } else if (pathname === '/briefing-hr' || pathname === '/__preview/briefing-hr') {
    file = resolve(ROOT, 'deploy/dev/previews/briefing-hr.html');
  } else if (pathname === '/briefing-parking' || pathname === '/__preview/briefing-parking') {
    file = resolve(ROOT, 'deploy/dev/previews/briefing-parking.html');
  } else if (pathname === '/briefing-fleet' || pathname === '/__preview/briefing-fleet') {
    file = resolve(ROOT, 'deploy/dev/previews/briefing-fleet.html');
  } else if (pathname === '/briefing-recreation' || pathname === '/__preview/briefing-recreation') {
    file = resolve(ROOT, 'deploy/dev/previews/briefing-recreation.html');
  } else if (pathname === '/briefing-exec' || pathname === '/__preview/briefing-exec') {
    file = resolve(ROOT, 'deploy/dev/previews/briefing-exec.html');
  } else if (pathname === '/briefing-print' || pathname === '/__preview/briefing-print') {
    file = resolve(ROOT, 'deploy/dev/previews/briefing-print.html');
  } else if (pathname === '/briefing-book' || pathname === '/__preview/briefing-book') {
    file = resolve(ROOT, 'deploy/dev/previews/briefing-book.html');
  } else if (pathname === '/recreation-types' || pathname === '/__preview/recreation-types') {
    file = resolve(ROOT, 'deploy/dev/previews/recreation-types.html');
  } else if (pathname === '/recreation-z' || pathname === '/__preview/recreation-z') {
    file = resolve(ROOT, 'deploy/dev/previews/recreation-z.html');
  } else if (pathname === '/recreation-receipt' || pathname === '/__preview/recreation-receipt') {
    file = resolve(ROOT, 'deploy/dev/previews/recreation-receipt.html');
  } else if (pathname === '/recreation-sales' || pathname === '/__preview/recreation-sales') {
    file = resolve(ROOT, 'deploy/dev/previews/recreation-sales.html');
  } else if (pathname === '/laundry-order' || pathname === '/__preview/laundry-order') {
    file = resolve(ROOT, 'deploy/dev/previews/laundry-order.html');
  } else if (pathname === '/laundry-settled' || pathname === '/__preview/laundry-settled') {
    file = resolve(ROOT, 'deploy/dev/previews/laundry-settled.html');
  } else if (pathname === '/laundry-list' || pathname === '/__preview/laundry-list') {
    file = resolve(ROOT, 'deploy/dev/previews/laundry-list.html');
  } else if (pathname === '/laundry-prices' || pathname === '/__preview/laundry-prices') {
    file = resolve(ROOT, 'deploy/dev/previews/laundry-prices.html');
  } else if (pathname === '/reports-report-date' || pathname === '/__preview/reports-report-date') {
    file = resolve(ROOT, 'deploy/dev/previews/reports-report-date.html');
  } else if (pathname === '/reports-views' || pathname === '/__preview/reports-views') {
    file = resolve(ROOT, 'deploy/dev/previews/reports-views.html');
  } else if (pathname === '/reports-schedules' || pathname === '/__preview/reports-schedules') {
    file = resolve(ROOT, 'deploy/dev/previews/reports-schedules.html');
  } else if (pathname === '/reports-alerts' || pathname === '/__preview/reports-alerts') {
    file = resolve(ROOT, 'deploy/dev/previews/reports-alerts.html');
  } else if (pathname === '/fleet-overview' || pathname === '/__preview/fleet-overview') {
    file = resolve(ROOT, 'deploy/dev/previews/fleet-overview.html');
  } else if (pathname === '/management-dashboard' || pathname === '/__preview/management-dashboard') {
    file = resolve(ROOT, 'deploy/dev/previews/management-dashboard.html');
  } else if (pathname === '/tape-chart' || pathname === '/__preview/tape-chart') {
    file = resolve(ROOT, 'deploy/dev/previews/tape-chart.html');
  } else if (pathname === '/revenue-dashboard' || pathname === '/__preview/revenue-dashboard') {
    file = resolve(ROOT, 'deploy/dev/previews/revenue-dashboard.html');
  } else if (pathname === '/revenue-pricing' || pathname === '/__preview/revenue-pricing') {
    file = resolve(ROOT, 'deploy/dev/previews/revenue-pricing.html');
  } else if (pathname === '/club-plans' || pathname === '/__preview/club-plans') {
    file = resolve(ROOT, 'deploy/dev/previews/club-plans.html');
  } else if (pathname === '/revenue-rules' || pathname === '/__preview/revenue-rules') {
    file = resolve(ROOT, 'deploy/dev/previews/revenue-rules.html');
  } else if (pathname === '/gl' || pathname === '/gl-ledger' || pathname === '/__preview/gl-ledger') {
    file = resolve(ROOT, 'deploy/dev/previews/gl-ledger.html');
  } else if (pathname === '/payments-settings' || pathname === '/__preview/payments-settings') {
    file = resolve(ROOT, 'deploy/dev/previews/payments-settings.html');
  } else if (pathname === '/wht-desk' || pathname === '/__preview/wht-desk') {
    file = resolve(ROOT, 'deploy/dev/previews/wht-desk.html');
  } else if (pathname === '/gst-settings' || pathname === '/__preview/gst-settings') {
    file = resolve(ROOT, 'deploy/dev/previews/gst-settings.html');
  } else if (pathname === '/close' || pathname === '/__preview/close') {
    file = resolve(ROOT, 'deploy/dev/previews/close.html');
  } else if (pathname === '/close-locked' || pathname === '/__preview/close-locked') {
    file = resolve(ROOT, 'deploy/dev/previews/close-locked.html');
  } else if (pathname === '/treasury-accounts' || pathname === '/__preview/treasury-accounts') {
    file = resolve(ROOT, 'deploy/dev/previews/treasury-accounts.html');
  } else if (pathname === '/treasury-transfers' || pathname === '/__preview/treasury-transfers') {
    file = resolve(ROOT, 'deploy/dev/previews/treasury-transfers.html');
  } else if (pathname === '/vouchers' || pathname === '/__preview/vouchers') {
    file = resolve(ROOT, 'deploy/dev/previews/vouchers.html');
  } else if (pathname === '/vouchers-off' || pathname === '/__preview/vouchers-off') {
    file = resolve(ROOT, 'deploy/dev/previews/vouchers-off.html');
  } else if (pathname === '/budgets' || pathname === '/__preview/budgets') {
    file = resolve(ROOT, 'deploy/dev/previews/budgets.html');
  } else if (pathname === '/budgets-no-golive' || pathname === '/__preview/budgets-no-golive') {
    file = resolve(ROOT, 'deploy/dev/previews/budgets-no-golive.html');
  } else if (pathname === '/receivables' || pathname === '/__preview/receivables') {
    file = resolve(ROOT, 'deploy/dev/previews/receivables.html');
  } else if (pathname === '/claims' || pathname === '/expense-claims' || pathname === '/__preview/claims') {
    file = resolve(ROOT, 'deploy/dev/previews/claims.html');
  } else if (pathname === '/claims-drift' || pathname === '/__preview/claims-drift') {
    file = resolve(ROOT, 'deploy/dev/previews/claims-drift.html');
  } else if (pathname === '/petty-cash' || pathname === '/accounts/petty-cash' || pathname === '/__preview/petty-cash') {
    file = resolve(ROOT, 'deploy/dev/previews/petty-cash.html');
  } else if (pathname === '/petty-cash-drift' || pathname === '/__preview/petty-cash-drift') {
    file = resolve(ROOT, 'deploy/dev/previews/petty-cash-drift.html');
  } else if (pathname === '/commissions' || pathname === '/accounts/commissions' || pathname === '/__preview/commissions') {
    file = resolve(ROOT, 'deploy/dev/previews/commissions.html');
  } else if (pathname === '/commissions-drift' || pathname === '/__preview/commissions-drift') {
    file = resolve(ROOT, 'deploy/dev/previews/commissions-drift.html');
  } else if (pathname === '/vouchers-register' || pathname === '/accounts/vouchers' || pathname === '/__preview/vouchers') {
    file = resolve(ROOT, 'deploy/dev/previews/vouchers.html');
  } else if (pathname === '/vouchers-register-drift' || pathname === '/__preview/vouchers-drift') {
    file = resolve(ROOT, 'deploy/dev/previews/vouchers-drift.html');
  } else if (pathname === '/pnl' || pathname === '/accounts/profit-loss' || pathname === '/__preview/pnl') {
    file = resolve(ROOT, 'deploy/dev/previews/pnl.html');
  } else if (pathname === '/pnl-drift' || pathname === '/__preview/pnl-drift') {
    file = resolve(ROOT, 'deploy/dev/previews/pnl-drift.html');
  } else if (pathname === '/cash-book' || pathname === '/accounts/cash-book' || pathname === '/__preview/cash-book') {
    file = resolve(ROOT, 'deploy/dev/previews/cash-book.html');
  } else if (pathname === '/cash-book-overdrawn' || pathname === '/__preview/cash-book-overdrawn') {
    file = resolve(ROOT, 'deploy/dev/previews/cash-book-overdrawn.html');
  } else if (pathname === '/city-ledger' || pathname === '/accounts/city-ledger' || pathname === '/__preview/city-ledger') {
    file = resolve(ROOT, 'deploy/dev/previews/city-ledger.html');
  } else if (pathname === '/city-ledger-drift' || pathname === '/__preview/city-ledger-drift') {
    file = resolve(ROOT, 'deploy/dev/previews/city-ledger-drift.html');
  } else if (pathname === '/company-ledger' || pathname === '/accounts/company' || pathname === '/accounts/company/21'
    || pathname === '/accounts/city-ledger/21' || pathname === '/__preview/company-ledger') {
    file = resolve(ROOT, 'deploy/dev/previews/company-ledger.html');
  } else if (pathname === '/ap-ageing' || pathname === '/__preview/ap-ageing') {
    file = resolve(ROOT, 'deploy/dev/previews/ap-ageing.html');
  } else if (pathname === '/ap-bills' || pathname === '/__preview/ap-bills') {
    file = resolve(ROOT, 'deploy/dev/previews/ap-bills.html');
  } else if (pathname === '/ap-payments' || pathname === '/__preview/ap-payments') {
    file = resolve(ROOT, 'deploy/dev/previews/ap-payments.html');
  } else if (pathname === '/ap-payments-drift' || pathname === '/__preview/ap-payments-drift') {
    file = resolve(ROOT, 'deploy/dev/previews/ap-payments-drift.html');
  } else if (pathname === '/ap-statement' || pathname === '/__preview/ap-statement') {
    file = resolve(ROOT, 'deploy/dev/previews/ap-statement.html');
  } else if (pathname === '/ap-bill-show' || pathname === '/__preview/ap-bill-show') {
    file = resolve(ROOT, 'deploy/dev/previews/ap-bill-show.html');
  } else if (pathname === '/ap-payment-show' || pathname === '/__preview/ap-payment-show') {
    file = resolve(ROOT, 'deploy/dev/previews/ap-payment-show.html');
  } else if (pathname === '/purchases' || pathname === '/inventory/purchases' || pathname === '/__preview/purchases') {
    file = resolve(ROOT, 'deploy/dev/previews/purchases.html');
  } else if (pathname === '/purchases-drift' || pathname === '/__preview/purchases-drift') {
    file = resolve(ROOT, 'deploy/dev/previews/purchases-drift.html');
  } else if (pathname === '/movement' || pathname === '/inventory/movement' || pathname === '/__preview/movement') {
    file = resolve(ROOT, 'deploy/dev/previews/movement.html');
  } else if (pathname === '/movement-drift' || pathname === '/__preview/movement-drift') {
    file = resolve(ROOT, 'deploy/dev/previews/movement-drift.html');
  } else if (pathname === '/stocktake-sheet' || pathname === '/inventory/stock-takes/121' || pathname === '/__preview/stocktake-sheet') {
    file = resolve(ROOT, 'deploy/dev/previews/stocktake-sheet.html');
  } else if (pathname === '/stocktake-sheet-drift' || pathname === '/__preview/stocktake-sheet-drift') {
    file = resolve(ROOT, 'deploy/dev/previews/stocktake-sheet-drift.html');
  } else if (pathname === '/stock' || pathname === '/inventory' || pathname === '/inventory/stock' || pathname === '/__preview/stock') {
    file = resolve(ROOT, 'deploy/dev/previews/stock-on-hand.html');
  } else if (pathname === '/stock-drift' || pathname === '/__preview/stock-drift') {
    file = resolve(ROOT, 'deploy/dev/previews/stock-on-hand-drift.html');
  } else if (pathname === '/ledger' || pathname === '/inventory/ledger' || pathname === '/__preview/ledger') {
    file = resolve(ROOT, 'deploy/dev/previews/ledger.html');
  } else if (pathname === '/ledger-drift' || pathname === '/__preview/ledger-drift') {
    file = resolve(ROOT, 'deploy/dev/previews/ledger-drift.html');
  } else if (pathname === '/stocktakes' || pathname === '/inventory/stock-takes' || pathname === '/inventory/stocktakes' || pathname === '/__preview/stocktakes') {
    file = resolve(ROOT, 'deploy/dev/previews/stocktakes.html');
  } else if (pathname === '/stocktakes-drift' || pathname === '/__preview/stocktakes-drift') {
    file = resolve(ROOT, 'deploy/dev/previews/stocktakes-drift.html');
  } else if (pathname === '/reorder' || pathname === '/inventory/reorder' || pathname === '/__preview/reorder') {
    file = resolve(ROOT, 'deploy/dev/previews/reorder.html');
  } else if (pathname === '/reorder-drift' || pathname === '/__preview/reorder-drift') {
    file = resolve(ROOT, 'deploy/dev/previews/reorder-drift.html');
  } else if (pathname === '/scorecard' || pathname === '/inventory/supplier-scorecard' || pathname === '/__preview/scorecard') {
    file = resolve(ROOT, 'deploy/dev/previews/scorecard.html');
  } else if (pathname === '/scorecard-drift' || pathname === '/__preview/scorecard-drift') {
    file = resolve(ROOT, 'deploy/dev/previews/scorecard-drift.html');
  } else if (pathname === '/po-show' || pathname === '/purchases/302' || pathname === '/__preview/po-show') {
    file = resolve(ROOT, 'deploy/dev/previews/po-show.html');
  } else if (pathname === '/po-show-drift' || pathname === '/__preview/po-show-drift') {
    file = resolve(ROOT, 'deploy/dev/previews/po-show-drift.html');
  } else if (pathname === '/requisitions' || pathname === '/inventory/requisitions' || pathname === '/__preview/requisitions') {
    file = resolve(ROOT, 'deploy/dev/previews/requisitions.html');
  } else if (pathname === '/requisitions-drift' || pathname === '/__preview/requisitions-drift') {
    file = resolve(ROOT, 'deploy/dev/previews/requisitions-drift.html');
  } else if (pathname === '/rfq-show' || pathname === '/rfqs/55' || pathname === '/__preview/rfq-show') {
    file = resolve(ROOT, 'deploy/dev/previews/rfq-show.html');
  } else if (pathname === '/rfqs' || pathname === '/__preview/rfqs') {
    file = resolve(ROOT, 'deploy/dev/previews/rfqs.html');
  } else if (pathname === '/rfqs-drift' || pathname === '/__preview/rfqs-drift') {
    file = resolve(ROOT, 'deploy/dev/previews/rfqs-drift.html');
  } else if (pathname === '/grns' || pathname === '/inventory/goods-receipts' || pathname === '/__preview/grns') {
    file = resolve(ROOT, 'deploy/dev/previews/grns.html');
  } else if (pathname === '/grns-drift' || pathname === '/__preview/grns-drift') {
    file = resolve(ROOT, 'deploy/dev/previews/grns-drift.html');
  } else if (pathname === '/budget-capital' || pathname === '/__preview/budget-capital') {
    file = resolve(ROOT, 'deploy/dev/previews/budget-capital.html');
  } else if (pathname === '/budget-approval' || pathname === '/__preview/budget-approval') {
    file = resolve(ROOT, 'deploy/dev/previews/budget-approval.html');
  } else if (pathname === '/budget-variance' || pathname === '/__preview/budget-variance') {
    file = resolve(ROOT, 'deploy/dev/previews/budget-variance.html');
  } else if (pathname === '/budget-variance-signed' || pathname === '/__preview/budget-variance-signed') {
    file = resolve(ROOT, 'deploy/dev/previews/budget-variance-signed.html');
  } else if (pathname === '/budget-cost-centres' || pathname === '/__preview/budget-cost-centres') {
    file = resolve(ROOT, 'deploy/dev/previews/budget-cost-centres.html');
  } else if (pathname === '/bankrec' || pathname === '/__preview/bankrec') {
    file = resolve(ROOT, 'deploy/dev/previews/bankrec.html');
  } else if (pathname === '/bankrec-show' || pathname === '/__preview/bankrec-show') {
    file = resolve(ROOT, 'deploy/dev/previews/bankrec-show.html');
  } else if (pathname === '/walkin-checkin' || pathname === '/__preview/walkin-checkin') {
    file = resolve(ROOT, 'deploy/dev/previews/walkin-checkin.html');
  } else if (pathname === '/reservation-form' || pathname === '/__preview/reservation-form') {
    file = resolve(ROOT, 'deploy/dev/previews/reservation-form.html');
  } else if (pathname === '/guest-registration-card' || pathname === '/__preview/guest-registration-card') {
    file = resolve(ROOT, 'deploy/dev/previews/guest-registration-card.html');
  } else if (pathname === '/egrc-signature' || pathname === '/__preview/egrc-signature') {
    file = resolve(ROOT, 'deploy/dev/previews/egrc-signature.html');
  } else if (pathname === '/reservations-workspace' || pathname === '/__preview/reservations-workspace') {
    file = resolve(ROOT, 'deploy/dev/previews/reservations-workspace.html');
  } else if (pathname === '/rooms-create' || pathname === '/__preview/rooms-create') {
    file = resolve(ROOT, 'deploy/dev/previews/rooms-create.html');
  } else if (pathname === '/rooms-edit' || pathname === '/__preview/rooms-edit') {
    file = resolve(ROOT, 'deploy/dev/previews/rooms-edit.html');
  } else if (pathname === '/fleet-maintenance' || pathname === '/__preview/fleet-maintenance') {
    file = resolve(ROOT, 'deploy/dev/previews/fleet-maintenance.html');
  } else if (pathname === '/fleet-fuel' || pathname === '/__preview/fleet-fuel') {
    file = resolve(ROOT, 'deploy/dev/previews/fleet-fuel.html');
  } else if (pathname === '/fleet-vehicle' || pathname === '/__preview/fleet-vehicle') {
    file = resolve(ROOT, 'deploy/dev/previews/fleet-vehicle.html');
  } else if (pathname === '/fleet-work-order' || pathname === '/__preview/fleet-work-order') {
    file = resolve(ROOT, 'deploy/dev/previews/fleet-work-order.html');
  } else if (pathname === '/pos-terminal' || pathname === '/__preview/pos-terminal') {
    file = resolve(ROOT, 'deploy/dev/previews/pos-terminal.html');
  } else if (pathname === '/qr-menu' || pathname === '/__preview/qr-menu') {
    file = resolve(ROOT, 'deploy/dev/previews/qr-menu.html');
  } else if (pathname === '/service-shift-totals' || pathname === '/__preview/service-shift-totals') {
    file = resolve(ROOT, 'deploy/dev/previews/service-shift-totals.html');
  } else if (pathname === '/fleet-cost-statement' || pathname === '/__preview/fleet-cost-statement') {
    file = resolve(ROOT, 'deploy/dev/previews/fleet-cost-statement.html');
  } else if (pathname === '/fleet-tracking' || pathname === '/__preview/fleet-tracking') {
    file = resolve(ROOT, 'deploy/dev/previews/fleet-tracking.html');
  } else if (pathname === '/pos-dashboard' || pathname === '/__preview/pos-dashboard') {
    file = resolve(ROOT, 'deploy/dev/previews/pos-dashboard.html');
  } else if (pathname === '/waiter-pos' || pathname === '/__preview/waiter-pos') {
    file = resolve(ROOT, 'deploy/dev/previews/waiter-pos.html');
  } else if (pathname === '/sidebar-navigation' || pathname === '/__preview/sidebar-navigation') {
    file = resolve(ROOT, 'deploy/dev/previews/sidebar-navigation.html');
  } else if (pathname === '/offline-pos' || pathname === '/__preview/offline-pos') {
    file = resolve(ROOT, 'deploy/dev/previews/offline-pos.html');
  } else if (pathname === '/offline-frontdesk' || pathname === '/__preview/offline-frontdesk') {
    file = resolve(ROOT, 'deploy/dev/previews/offline-frontdesk.html');
  } else if (pathname === '/offline-outbox' || pathname === '/__preview/offline-outbox') {
    file = resolve(ROOT, 'deploy/dev/previews/offline-outbox.html');
  } else if (pathname === '/css/fleet.css') {
    file = resolve(ROOT, 'public/css/fleet.css');
  } else if (pathname === '/css/app.css') {
    file = resolve(ROOT, 'public/css/app.css');
  } else if (pathname === '/css/dashboard.css') {
    file = resolve(ROOT, 'public/css/dashboard.css');
  } else if (pathname === '/css/offline.css') {
    file = resolve(ROOT, 'public/css/offline.css');
  } else if (pathname.startsWith('/preview-assets/')) {
    // sample photography for the preview screens (never shipped to the application)
    file = resolve(ROOT, 'deploy/dev/previews/assets', pathname.slice('/preview-assets/'.length));
  } else if (pathname.startsWith('/images/')) {
    file = resolve(ROOT, 'public', `.${pathname}`);
  }

  if (!file || !(file === ROOT || file.startsWith(ROOT + sep))) {
    res.writeHead(404, { 'content-type': 'text/plain; charset=utf-8' });
    res.end('Not found');
    return;
  }
  try {
    const info = await stat(file);
    if (!info.isFile()) throw new Error('Not a file');
    const body = await readFile(file);
    res.writeHead(200, {
      'content-type': MIME[extname(file).toLowerCase()] ?? 'application/octet-stream',
      'content-length': body.length,
      'cache-control': 'no-store',
      'x-content-type-options': 'nosniff',
    });
    res.end(body);
  } catch {
    res.writeHead(404, { 'content-type': 'text/plain; charset=utf-8' });
    res.end('Not found');
  }
});

server.listen(PORT, '0.0.0.0', () => console.log(`Preview server on http://0.0.0.0:${PORT}/ — root = ${LANDING_SLUG}, /previews = all screens`));
