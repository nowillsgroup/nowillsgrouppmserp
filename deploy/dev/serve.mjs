#!/usr/bin/env node
// serve — PMS development server (Bun HTTP → WASM PHP), bound on 0.0.0.0.
//
// Static files under public/ are served straight from disk; everything else
// is dispatched to public/index.php like the production .htaccess does.
import { createPhp, makeMutex, REPO_ROOT, APP_MOUNT } from './lib.mjs';
import { existsSync, statSync } from 'node:fs';
import { join, normalize, extname } from 'node:path';

const PORT = Number(process.env.PORT ?? 8000);
const HOST = process.env.HOST ?? '0.0.0.0';
const PUBLIC_DIR = join(REPO_ROOT, 'public');

// Boot PHP in the background so the HTTP port binds instantly — the preview
// proxy needs to observe port 8000 within the process startup window, and
// dynamic requests simply wait for the runtime below.
let php = null;
const phpReady = createPhp().then((p) => {
  php = p;
  process.stderr.write('[pms-serve] PHP runtime ready\n');
  return p;
});
const run = makeMutex();

// ── dev preview auto-login ────────────────────────────────────────────────
// The live preview should land inside the app, not on the marketing login
// page. Requests without a session cookie are signed in behind the scenes as
// the demo admin; `/` then redirects to the preview home (reservation desk).
// Disable with PMS_DEV_AUTOLOGIN=0; tune with PMS_DEV_USER / PMS_DEV_PASS /
// PMS_DEV_HOTEL / PMS_DEV_HOME.
const DEV_LOGIN = (process.env.PMS_DEV_AUTOLOGIN ?? '1') !== '0';
const DEV_USER = process.env.PMS_DEV_USER ?? 'admin';
const DEV_PASS = process.env.PMS_DEV_PASS ?? 'Admin@123';
const DEV_HOTEL = process.env.PMS_DEV_HOTEL ?? 'NOWILLS';
const DEV_HOME = process.env.PMS_DEV_HOME ?? '/reservations';
let devCookies = null;

// ── /__dev — development changes index ────────────────────────────────────
// Standing workflow: EVERY development change is recorded here with deep
// links to the screens where it can be seen in the live preview. Newest
// first. A small "dev changes" chip is injected into previewed pages so
// this index is always one click away.
const DEV_CHANGES = [
  {
    title: 'A payroll approver could not see whether approving the run would break the month salary budget — now the run page, the approve dialog and the nightly task all say',
    detail: 'Follow-up 4 of the HR and workforce audit. A run compared against nothing: the approver saw gross, deductions and net and had no way to know what approving it would do to the budget, which is the one question a budget exists to answer and the one a hotel cannot answer once the run is locked and the attendance behind it frozen. PayrollService::budgetVariance() now compares a run with the approved budget for the same fiscal month and the same accounts, and it follows LedgerService::forPayrollRun(), the authority on what payroll charges to the profit and loss, rather than assuming one salaries line: earnings go to their expense head, employer contributions to theirs, and a deduction credited to an expense head, a fine or a recovery, reduces it, while SSNIT, PAYE and advances are liabilities and are left out on both sides so withheld tax never looks like payroll cost. Two figures are read for each account the run charges — the approved budget for that fiscal month, which is fiscal-month indexed so a hotel whose year does not start in January is handled, and what the ledger already holds for that month, read to the ledger cutoff. They are kept apart on purpose: payroll is not the only thing that charges Salaries and Wages, casual labour, staff meals and accruals land on the same head, and in the demo data October already carried GH₵ 285,000.00 of non-payroll postings against a GH₵ 720,000.00 budget, so a card that showed only this run against the budget would have said within budget by GH₵ 637,360.96 while the month was in fact already half spent. For a draft run the card therefore adds the run to the postings under Month if approved, and for an approved or paid run, whose cost is already inside the postings, it says so instead of adding it twice. It appears in three places: a Cost against budget card on the run page between the earnings grid and the payslips, with one row per account charged, a total, a status badge of Within budget, Over budget, Account not budgeted or No approved budget, a link to the budget itself and a sentence naming the usual causes when it is over; the approve confirmation, which now says This takes Oct 26 GH₵ 299,830.73 over the approved budget before the approver clicks rather than after; and the nightly HR task, which reports a draft run that would break the budget and separately a run charging an account the budget has no line for, the case that makes a variance meaningless. The seeded budget is far larger than the payroll so the warning paths never fire in the demo, and hr_budget_variance.php lowers the October salaries line to prove them and puts the figure back byte-identically in a finally block, the budget being approved and locked so a direct write is the only way to move it. At GH₵ 720,000 the engine says within, minus 370,169.27 or minus 49.6 per cent and the page badges Within budget with no warning anywhere; at GH₵ 50,000 it says over, month to date 367,639.04 against 50,000.00 giving plus 317,639.04 or plus 394.5 per cent, the badge turns to Over budget with a red variance, the approve dialog carries the warning and the nightly task prints PAY-2026-10 would take Oct 26 GH₵ 299,830.73 over the approved budget (394.5 per cent), Salaries and Wages; at zero the badge reads Account not budgeted with a chip on the row and the nightly task names the account. The dialog and the task quote the total across both accounts while the engine line quotes the lowered salaries head alone, and the difference is exactly the employer contributions line that is still inside its own budget. The real test of the cost definition is the ledger, and it agrees to the pesewa: for the paid July run the card and the ledger postings for that fiscal month both read 71,996.46 for salaries and 7,563.49 for employer contributions, for the approved August run 74,043.46 and 7,743.41, the latter being the gross less the recovery credited to salary cost, and September, a draft with no other postings, shows posted 0.00 with month if approved equal to the run. The comparison costs about 136 milliseconds on 4,811 ledger lines and the run page still renders in about 1.5 seconds. Written up as section 16 of docs/HR-WORKFORCE-AUDIT.md, whose follow-up 4 is closed there.',
    screens: [['October draft run — the Cost against budget card, with GH₵ 285,000.00 already posted by other modules', '/payroll/runs/3'], ['September draft — nothing else posted that month, so the run is the whole variance', '/payroll/runs/4'], ['July, paid — the card agrees with the ledger to the pesewa', '/payroll/runs/1'], ['The budget being measured against', '/budgets/1'], ['Budget versus actual for the year', '/budget-vs-actual']],
  },
  {
    title: 'Ghana gives a father no paternity leave, so a hotel that offers one had nowhere to record it: the week was booked as casual leave or as unpaid',
    detail: 'Follow-up 2 of the HR and workforce audit. Act 651 gives a mother twelve weeks on full remuneration under s. 57 and gives a father nothing at all, but branded hotels and most collective agreements offer paternity leave anyway, and until now there was no leave type to record it against, so a week with a new-born was booked as casual leave out of a twelve-day pot meant for other things, or as leave without pay, which cost the worker pay his own contract promised. PayrollService::ensureDefaults() now seeds PL, Paternity leave (contractual, Act 651 provides none), five working days a year, paid, no carry-forward, and creates it on an existing installation the way it already did for maternity leave, so an upgrade hands a hotel the type without touching the leave types it has configured itself. No engine work was needed and none was done: LeaveService entitlement, taken, carried and balances are generic over the type, entitlement pro-rates in the joining year, approved leave writes the attendance register, paid leave of any type is paid by payroll the same way, and a gazetted public holiday inside the spell is not counted against the balance. It is deliberately not restricted by gender — the field exists, but there is no statutory rule to restrict against, and a hard-coded men only test would block an adoptive parent and a mother partner, so every employee has the type and the hotel sets the days it really offers. Because the entitlement is contractual rather than legal, both the balances tab and the Record leave modal now say which is which: EL under s. 20 and ML under s. 57 are statutory, CL, SL and PL are contractual, and Masters, Leave Types is named as the place to change the number, so nobody mistakes a five-day PL balance for a legal right. Verified through the real form: PL seeded as leave type 11 beside the other five, a PL column on the balances tab, and 9 to 13 November 2026 recorded and approved for EMP003 Joseph Thomas answered Leave recorded and approved, PL 09 Nov to 13 Nov 2026, took his PL balance from 5 to 0, left the other four types untouched, and wrote the register, where the attendance sheet for 9 November reads On leave, PL. The request is listed under approved leave with its five days and reason and the type is editable in the leave types master. The demo is dated in November, a month with no payroll run, so every October and September payroll figure reported in the v7.43 and v7.45 cards is untouched. If Ghana ever amends Act 651 to add paternity leave nothing structural changes: the type is already there and only the day count and the wording of the note do. Cancelling the request gives the five days back and clears the register, and the harness then puts the demo leave back, so the whole cycle is reproducible with python3 deploy/dev/audit/hr_paternity_flow.py. Written up as section 15 of docs/HR-WORKFORCE-AUDIT.md, whose follow-up 2 is closed there.',
    screens: [['Leave balances — the new PL column and what the law does and does not give', '/hr/leave?tab=balances'], ['The approved paternity leave — EMP003, 9 to 13 November 2026', '/hr/leave?status=approved'], ['Attendance register for 9 November — the PL marks', '/hr/attendance?date=2026-11-09'], ['Record leave — the statutory versus contractual note', '/hr/leave'], ['Leave Types master — the hotel sets its own PL days', '/masters/leave-types']],
  },
  {
    title: 'A bonus was taxed like salary: GRA gives an ex-gratia payment a final 5 per cent up to 15 per cent of the annual basic, and payroll pushed the whole of it through the bands at up to 35 per cent',
    detail: 'This was follow-up 3 of the HR and workforce audit, and it was a real gap rather than a missing convenience: a hotel could enter a festival or performance bonus only as an ordinary taxable earning, so the whole amount joined chargeable income and was taxed on the graduated bands, and the GRA return had nowhere to show it. Act 896 s. 114 and the GRA practice note on ex-gratia payments treat a bonus apart. A resident now pays a final 5 per cent on the part up to 15 per cent of the annual basic salary, which is twelve times the monthly basic, and only the excess over that cap joins the month chargeable income and goes on the bands. A non-resident pays a flat 20 per cent on the whole bonus, which is neither the resident bands nor the 25 per cent that applies to the rest of a non-resident employment income. A casual worker gets no separate bonus line at all, because Act 651 s. 78 puts the bonus inside the gross pay that already carries the final 5 per cent. A junior employee bonus now counts towards the GH₵ 18,000 qualifying income test for the overtime concession, so a large bonus can cost the concession, and the probe proves both the won and the lost case. The three figures are settings with the statutory values as defaults, payroll_bonus_cap_pct 15, payroll_bonus_rate 5 and payroll_bonus_nonresident_rate 20, editable under Payroll, Components and settings beside the junior overtime ones. Entry is the existing One-off earning modal on a draft run, which gained a Tax treatment select: choosing Bonus stores the line against a new BONUS component with code BONUS and taxable false, and takes the Taxable earning checkbox out of the way, because the tax on a bonus is computed rather than chosen. An ordinary adjustment behaves exactly as before. PayrollService::compute() sums the BONUS lines, keeps them out of taxable income while leaving them in gross pay, and passes them to incomeTax(), which returns bonus_tax, bonus_final, bonus_excess and bonus_cap beside paye and ot_tax; compute() then raises a BONUS_TAX deduction credited to PAYE payable in the same way the OT_TAX line is raised. The payslip prints the bonus as an earning, Tax on bonus as a deduction, and the rule sentence under Income tax PAYE, for which payslips.paye_basis was widened from 120 to 255 characters by migration 2026_10_14_001000_hr_bonus_taxation, which also seeds the two components into an existing installation. The GRA return gained Bonus and Bonus tax columns and totals, its CSV gained Bonus ex-gratia and Tax on bonus final columns and now has sixteen, the bonus tax joins the amount payable to GRA because it is credited to the same liability account, and the allowances column now excludes the bonus so that basic plus allowances plus overtime plus bonus equals the gross emolument. Evidence: hr_bonus_cases.php runs 15 cases whose expectations were hand-computed from the rule rather than read back from the code, and all pass — a basic of 1,000 a month gives a cap of 1,800 and 12,000 gives 21,600; a 1,500 bonus inside the cap costs 75.00 and leaves chargeable income untouched; a 3,000 bonus costs 90.00 final and puts 1,200 into the bands, where the resulting PAYE equals the PAYE on the same chargeable income earned as salary; a non-resident 5,000 bonus costs 1,000.00; a casual gross of 4,000 plus a 1,000 bonus costs 250.00 with no bonus line; personal reliefs still come off before the bands; the three settings were moved and restored; and the v7.43 figures for EMP004 at 937.08 and EMP012 at 221.60 are unchanged when there is no bonus. hr_bonus_flow.py then drives the same rule through the real UI on the October draft: EMP001, basic GH₵ 2,500.00, festival bonus GH₵ 5,500.00, cap 15 per cent of 30,000 = 4,500.00, final tax 225.00, excess 1,000.00 lifting chargeable income from 3,037.50 to 4,037.50 and PAYE from 411.16 to 613.88, so the payslip must read gross 8,675.00, deductions 976.38 and net 7,698.62, which is what it reads; the bonus and its tax survive a recalculation, the run summary shows BONUS 5,500.00 and BONUS_TAX 225.00 on one slip each, and removing the line restores 3,175.00, 548.66 and 2,626.34 to the pesewa. hr_bonus_statutory.php locks that run inside a transaction and rolls it back so the demo stays a draft, and reads the return GRA would get: 15 employees, bonus 5,500.00, bonus tax 225.00, total payable 13,144.81 which is PAYE 12,919.81 plus bonus tax 225.00, and for EMP001 a gross emolument of 8,675.00 with tax deducted 838.88 = 613.88 + 225.00. No regression: the September run, which carries no bonus, recalculates to gross 77,023.47, deductions 16,892.12, net 60,131.35 and PAYE 12,688.99 exactly as it did in v7.43, hr_tax_cases.py still reports the non-resident at 937.08, the junior overtime tax at 2.50 and the personal relief case unchanged, and pms:hr-run --dry reports the October draft with the demo bonus in place at gross 82,639.04 and net 65,228.53. The payroll register report gained the same two columns, so a bonus no longer hides inside Allowances and other nor its tax inside Other deductions, while the statutory summary report needed no change because it groups deductions by liability account and BONUS_TAX is credited to PAYE payable. The rule, the code map and all three harnesses are written up in docs/HR-WORKFORCE-AUDIT.md section 14, whose follow-up 1 is also closed there by the v7.44 date work.',
    screens: [['October draft run — BONUS and BONUS_TAX in the summary beside PAYE', '/payroll/runs/3'], ['The payslip carrying the bonus — festival bonus, tax on bonus and the rule applied', '/payroll/payslips/30'], ['Components and settings — the three GRA bonus figures beside the overtime ones', '/payroll/components'], ['GRA PAYE return — the new Bonus and Bonus tax columns', '/payroll/statutory?tab=paye&period=2026-07'], ['September run — no bonus on it, so every figure is unchanged', '/payroll/runs/4'], ['A September payslip with the v7.43 public-holiday premium, still 200.00', '/payroll/payslips/59'], ['Payroll register — the bonus and its tax in their own columns', '/reports/payroll_register?from=2026-10-01&to=2026-10-31']],
  },
  {
    title: 'A date comparison defect class behind 318 queries: the trial balance was missing the last day of every period, and the duty roster could not be saved at all',
    detail: 'SQLite stores whatever shape a writer hands a DATE column, and this codebase hands it two. Eloquent writes a date-cast attribute as 2026-10-05 00:00:00 while a toDateString() bound is 2026-10-05, and a string comparison between the two is wrong in both directions: equality never matches, and a <= bound drops the boundary day because the longer string sorts after the shorter one. A survey of the live schema found 336 non-empty DATE columns, of which 318 store the time part, 8 store a bare date and 10 hold both shapes in the same column, folio_transactions.business_date among them at 176 bare against 370 timed. Reading that against the query layer found 318 comparisons and 11 upserts on the wrong side of it, and the cost is measurable. LedgerService::balance() and LedgerService::totals(), the two functions under every account balance, ledger listing, trial balance, profit and loss and balance sheet in the product, both used a string <= bound, so the last day of any period was missing from every cumulative figure in the system: as at 12 October the general ledger held 4,458 lines where the string form found 4,410, hiding 48 lines and GH₵ 82,239.40 of debits. On the trial balance page itself September 2026 moved from a period movement of GH₵ 2,927,809.19 to GH₵ 3,080,873.34 and from a closing balance of GH₵ 23,819,404.54 to GH₵ 24,016,209.25 once 30 September stopped being dropped, an understatement of GH₵ 153,064.15. Nothing ever reported it, because the day was dropped from both sides and the trial balance still balanced to the cent. The period close checklist carried the same defect as a standing warning: the unearned membership subscription control account disagreed with its sub-ledger by GH₵ 5,510.25 at 30 September and now agrees exactly, and the guest ledger and stores valuation sub-ledgers moved to their true figures with it. The write side was worse than a wrong number. RosterService::saveWeek() looked the existing shift up with string equality, so on any week that already had shifts every posted cell looked like a change and the updateOrCreate() that followed inserted a second row for the same employee and day, which the unique index rejected: saving the duty roster returned HTTP 500 with UNIQUE constraint failed on roster_shifts.employee_id and roster_shifts.date, so a rostered week could not be edited at all. Posting the same week now returns a redirect with 231 rows unchanged and no duplicate employee and day pairs. Ten further array-keyed upserts had the same fault and now go through two new helpers, date_upsert() and date_first_or_create() in app/helpers.php, which match date-cast key columns by calendar day and everything else with plain equality. The serious one is the income audit, where firstOrCreate() would have opened a second audit day beside a closed one for the same date and run the night audit twice over it. The fix is 411 lines across 103 application files: 114 whereBetween() calls, 204 where() and orWhere() calls including the ones nested inside closures and one with a dynamic operator, and 18 sites where the column name is used with another type in a different table and had to be resolved against the model in the call. Two traps were caught on the way and are recorded in the audit so they are not repeated: four conversions were reverted because the column is an INTEGER fiscal month or a varchar Y-m period, where whereDate() would return nothing at all, and three were reverted or rewritten because the receiver was an Eloquent Collection, which has where() but not whereDate(). One of those three was already broken before this audit and was aborting the source mix and company mix reports with a 500. Deliberately left alone are the prefix LIKE filters on the GST and withholding tax periods, which match both shapes already, and the key card and visitor expiry checks, which are real datetimes where the hour is the point. Verified: all 103 files lint clean; 462 parameter-free GET routes swept with 451 clean and the other 11 being the token-only JSON API, a permission-gated dashboard and a JSON sub-resource, and no 500 anywhere in the application; all 259 report types rendered over a real September range; the October payroll recalculated to an identical gross and net on both the patched and the pre-fix tree, so pay did not move; and pms:hr-run --dry prints the same roster, time clock, payday and statutory output as before. The measurements, the before and after tables and the reproduction commands are in docs/DATE-STORAGE-AUDIT.md.',
    screens: [['Trial balance for September 2026 — the month is GH₵ 153,064.15 larger than it was', '/gl?from=2026-09-01&to=2026-09-30'], ['Duty roster — an already-rostered week, which used to answer a save with HTTP 500', '/hr/roster?dept=9&week=2026-10-12'], ['Source mix report — room nights and revenue, which aborted on a collection call', '/reports/source?from=2026-09-01&to=2026-09-30'], ['Company mix report — the same defect on the company side', '/reports/company?from=2026-09-01&to=2026-09-30'], ['Marketing plan — the page that returned a 500 on a Collection whereDate', '/sales/marketing'], ['Compliance calendar — the year fee total, filtered on a Collection of models', '/compliance?tab=calendar'], ['Supplier statement — opening balance and running total over a date range', '/ap/suppliers/1/statement?from=2026-09-01&to=2026-09-30'], ['Accounts payable ageing — the control account behind the period close checklist', '/reports/ap_ageing?from=2026-09-01&to=2026-09-30'], ['Petty cash book — petty_txns.date is one of the ten mixed-shape columns', '/reports/petty_cash_book?from=2026-09-01&to=2026-09-30'], ['Owner USALI report — the fiscal-month budget statistics that had to stay integers', '/owner/usali']],
  },
  {
    title: 'HR and Workforce audited against Ghana payroll law: PAYE was on superseded bands, the statutory module was dead, and the repository had no concept of a public holiday',
    detail: 'All 36 menu pages of the three PEOPLE modules were fetched and every route resolved: 184 HR controller routes of which 112 are writes, plus the six shared master CRUD routes behind Employees, Leave Types and Departments; 17 permission keys declared, seeded and held (Administrator 17 of 17, HR Manager 16 of 17, the exception being service charge approval, which is a finance duty); no stub actions; 36 of 36 pages return HTTP 200 and the four empty states are all correct for the logged-in user. The statutory side is where the real defects were, and there were twelve. Two were critical. First, PAYE was computed from one hard-coded band table, the GRA schedule of 1 January 2024, while the Income Tax (Amendment) Act 2026, Act 1178, replaced the resident bands on 1 September 2026, and there was no effective-dating anywhere, so every month from September onwards was taxed on superseded bands: the October draft stood at GH₵ 12,717.26 where Act 1178 requires GH₵ 12,714.58, over-taxing the six lowest-paid workers by GH₵ 11.15 each. PayrollService now carries PAYE_SCHEDULES and scheduleFor(period), so each month is taxed on the schedule that was law in it, a reopened July or August run still recomputes on the 2024 bands, and the manual override is preserved but warned about in red beside the schedule badges. Recalculated through the UI, October now produces 12,714.58 and the audit harness reports a delta of 0.00 on all 59 payslips across four runs. Second, the whole Statutory Returns module was dead: StatutoryService::runFor() compared a date column with string equality while SQLite stores 2026-08-01 00:00:00, so it returned null for every month. The page said No payroll run for July, August and October alike, the stat_overdue badge could never light, and no SSNIT, Tier 2 or PAYE remittance could be recorded. The same bug class defeated the duplicate-month guard in createRun() (a second run for a month hit the unique index and returned HTTP 500) and let the month lock miss a locked period on its first day. Every site in payroll, statutory, service charge, month close and the payroll reports now uses whereDate(), and the statutory page lists six overdue returns with their amounts and due dates. The third defect was structural: the repository had no concept of a public holiday at all, no calendar, no attendance status and no premium pay, although Labour Act 651 puts them at the centre of pay (s. 72, a paid day; s. 77, the proportion for the hours worked on it), of leave (s. 22, a holiday does not consume annual leave) and of rest (s. 43). HolidayService now carries the gazetted calendar under Act 601 as amended by Act 1142 of 2025, 14 paid days in 2026 plus two commemorative working days and 17 in 2027, editable per hotel per year because Islamic dates and substitute days follow the gazette; PH joins the attendance vocabulary; a new Public Holidays page shows and edits the year; the roster, the monthly register, the time clock and payroll all understand it; and hours worked on a holiday are paid on a new OTH line at payroll_holiday_multiplier, which is 2 by default. Driven end to end through the UI, 21 September 2026, Kwame Nkrumah Memorial Day, was marked PH with clock times of 08:00 to 17:00 for three workers, and the September run paid 126.67, 81.67 and 200.00 of holiday premium on top of the day itself. Nine further defects were fixed with them. Casual workers were taxed on the bands instead of the GRA final 5 per cent and employment_type was not even a field; now it is, in the employees master, and a casual payslip reads 221.60 on a gross of 4,432.00. The junior-staff overtime concession did not exist; now overtime up to half the monthly basic is taxed at a final 5 per cent and the excess at 10, on its own OT_TAX line and never added to chargeable income. GRA personal reliefs were not modelled; employees.tax_relief now comes off chargeable income and prints on the payslip and in the DT-107A CSV. Non-residents were taxed on resident bands and the CSV hard-coded Resident Y; now they are taxed at a flat 25 per cent, proven at 937.08 on chargeable income of 3,748.31. Annual leave was counted in calendar days although s. 20 requires 15 working days, and the approval loop wrote L over rest days and public holidays contrary to ss. 22 and 43; it now counts working days and skips them, with a setting to restore calendar counting. No maternity leave type existed; ML is seeded at 84 paid days. The roster checked the 12 hour daily rest but not the 48 consecutive hours of weekly rest that s. 42 requires; that check is in. There was no HR automation at all among 29 scheduled tasks; pms:hr-run now runs daily at 06:40, marks the holiday register, chases leave requests and shift swaps past their SLA, runs the roster rest checks for this week and next, flags payslips below the national minimum wage of GH₵ 587.79 a month, chases an approved but unpaid run past payday, and lists every overdue SSNIT, Tier 2 and PAYE return with the penalty that applies. Payroll and swap decisions sent no notifications; they do now, deliberately amount-free because Act 843 treats pay as sensitive personal data, and off by default. Two papercuts came with them. The class .grid-2 was used by 15 blocks in 8 views but was never defined in the stylesheet, so every two-column layout silently collapsed to one. And EssController aborted with a bare 404 when a login was not linked to an employee record, which is the state of 11 of the 17 demo employees, so My Profile, My Documents and My Announcements were unreachable; they now explain themselves like every other self-service page, and the three unlinked pages carry their own titles instead of all saying My Payslips and Leave. Full evidence, the Ghana statutory baseline used, the twelve defects with their verification, and the reproduction commands are in docs/HR-WORKFORCE-AUDIT.md.',
    screens: [['Public holidays — the gazetted Ghana calendar for 2026', '/hr/holidays'], ['The same calendar for 2027 (17 paid days)', '/hr/holidays?year=2027'], ['Attendance on Kwame Nkrumah Memorial Day — three workers marked PH with clock times', '/hr/attendance?date=2026-09-21'], ['September payroll run — the holiday note, the PH column and the bands in force', '/payroll/runs/4'], ['Payslip — holiday hours worked, chargeable income, PAYE basis and the statutory footer', '/payroll/payslips/59'], ['Statutory returns — six overdue returns that were invisible before', '/payroll/statutory?tab=paye&period=2026-08'], ['Payroll settings — schedules, holiday rate, junior overtime concession, payday', '/payroll/components'], ['Employees master — employment type, tax residence and personal reliefs', '/masters/employees/12/edit'], ['Monthly register for September', '/hr/register?month=2026-09'], ['Duty roster — the rules tab: 40 hour week (s. 33), 12 hour daily rest (s. 41), 48 hour weekly rest (s. 42)', '/hr/roster']],
  },
  {
    title: 'The OTA adapters that shipped could not be configured — and a cancellation at the desk never reached the channel',
    detail: 'Four channel adapters were in the codebase and none of the interesting ones could be used. Expedia Rapid was unselectable: the provider list on the connection form and in the provider validation rule came from a hand-maintained constant that simply did not contain expedia, so the form offered four types and the rule rejected a fifth that the driver registry already knew. The endpoint URL and API key fields were hidden behind a script that showed them only for the hub type, so a Booking.com or Rapid connection could be saved with nowhere to put its credentials. And Rapid had no place to keep its signing secret at all: it signed outbound requests with the secret column, which is the PMS-generated HMAC secret the channel uses to sign INBOUND webhook pushes, so configuring Rapid correctly broke inbound verification — and the only control for that column was a rotate button that overwrote it with a random string. All four are fixed: the selectable list and the validation rule now derive from ChannelDriverRegistry::options(), so registering a driver is enough to make it usable; the endpoint, API key and API secret fields show for every adapter except the built-in simulator with a per-adapter note explaining what each one needs; a new encrypted api_secret column holds the outbound vendor credential (blank on edit keeps the stored value) and Rapid signs with it, falling back to secret only for older connections; and endpoint_url is now required for every adapter that calls out. Verified in the product: an Expedia Rapid connection was created with its API secret, mapped, tested (signed request accepted), pushed 730 room-nights in 12 requests with zero bad signatures on the simulator that rejects them, and pulled a booking that became RES-00211 at GH₵ 2,960.00 with GH₵ 444.00 commission. The second half of the two-way loop was missing entirely: every driver implemented cancelBooking() and nothing in the application ever called it, so a reservation cancelled at the desk stayed live at the OTA with the room still sold and the guest still holding a confirmation. ChannelService::cancelAtChannel() now runs after the cancellation transaction commits inside CancellationService::cancel(), which covers all five cancel paths (desk, API v1, three in the partner portal) from one call site. It never throws and never rolls the cancellation back: a channel that refuses is logged as cancel_failed and flagged on the reservation so the desk cancels it by hand, a later success clears only that flag and never an overbooking one, and partial cancellations and no-shows are deliberately not sent because the booking still stands at the channel. Cancelling RES-00210 now reads Reservation cancelled … The cancellation was sent to the channel. SiteMinder reservation SM-77120998 cancelled at the channel, and the simulator shows it arriving; with the endpoint pointed at a dead port the same cancellation succeeded locally, logged cancel_failed and raised the attention flag, then cleared it on retry. A fourth adapter joins them: SiteMinder, the channel manager that fronts Booking.com, Expedia, Agoda and Airbnb at once, and the first non-JSON dialect here — OTA_AvailRateNotifyRQ pushes with stop-sell, min-stay and closed-to-arrival as OTA restrictions, an OTA_HotelResModifyNotifRS reservation feed parsed into the same canonical rows as every other channel, and an idempotent OTA_CancelRQ. Through the Channel Manager it tested, mapped, pushed 730 room-nights in 12 requests with no malformed documents, and its XML feed created RES-00209 (GH₵ 4,120.00, commission GH₵ 618.00) and RES-00210, then cancelled RES-00209 from an inbound Cancel message. The mirror adapter was pulling and cancelling at the endpoint itself instead of the bookings path the hub protocol documents, which answered 404 on every hub-protocol feed; it now uses the same layout as the hub. Finally the adapter spec promised a contract test that this repository never had — there is no tests tree and no PHPUnit — so the promise is now a runnable harness on the same WASM PHP that serves the app: deploy/dev/audit/channel_contract.php checks all six drivers live against the simulators (identity and uniqueness, test never throws and never mutates channel state, pushAri acks exactly the accepted chunks and throws mid-stream without acking the unsent ones, pull rows survive the mapping pipeline, cancel is idempotent, and every driver is selectable in the form) and reports 54 passed, 0 failed. bash deploy/dev/sims-up.sh starts all four simulators, and the SiteMinder one has a fail hook so the mid-stream rule is proven rather than assumed.',
    screens: [['Channel Manager — connections', '/channel-manager'], ['SiteMinder connection — test, push, pull', '/channel-manager/connections/5'], ['Expedia Rapid connection — configured with its API secret', '/channel-manager/connections/6'], ['New connection — all six adapter types', '/channel-manager/connections/create'], ['Channel inbox — the currency guard at work', '/channel-manager/inbox'], ['The reservation cancelled at the desk and at the channel', '/reservations/234']],
  },
  {
    title: 'The Foreign Guests exchange desk was unusable — every rate save crashed and every exchange was refused',
    detail: 'A completeness audit of Front Office and Guest Services drove 126 write flows over HTTP and found one real defect: the whole foreign exchange desk. Saving the daily rate board returned HTTP 500 with UNIQUE constraint failed on forex_rates(hotel_id, currency_id, date), and Copy from the previous day did the same, because the service wrote a fresh row for a date that already existed. The reason is a storage-format trap: the column is a DATE, but the Eloquent date cast writes 2026-10-13 00:00:00 on SQLite, so the plain where(date, ...) lookups never matched a stored row while the unique index still saw the same day. The knock-on effect was worse than the crash: rate() and todayRates() also compared bare strings, so they returned null for rates that were in the table, and every exchange was refused with No USD rate has been set for 13 Oct 2026. The seeder skipped its FX demo rows for the same reason, so a fresh install had no rates at all and the desk looked empty from day one. All six comparison sites now use whereDate(), and saveRates() and copyPrevious() look the row up first and update it instead of inserting. Verified end to end after the fix: twelve rates saved on one POST, the previous-day copy filled a blank day, an exchange of USD 100 for a resident produced FX-00001 at GH₵ 1,145.00 credited to the room folio, the 148 KB exchange certificate printed with the FX number on it, voiding the exchange reversed the folio credit, a second cash exchange FX-00002 for USD 200 showed up as held currency on the position screen, and surrendering it recorded FXS-00001 with GH₵ 581.00 realised and a GH₵ 8.50 gain. No money arithmetic changed — only the date comparisons.',
    screens: [['Exchange rate board — twelve rates saved', '/foreign/rates'], ['New exchange — USD to cedis on a folio', '/foreign/forex/new'], ['Currency position and surrender', '/foreign/position'], ['Form C register', '/foreign/form-c']],
  },
  {
    title: 'Front Office and Guest Services audited end to end: 63 pages, 370 routes, 126 write flows driven over HTTP',
    detail: 'Every page in the ten Front Office and Guest Services modules was fetched, every route resolved to a real controller action and view, and 126 write flows were driven through the live app rather than read from the code. The structural side is clean: 370 named routes of which 228 are writes, 63 menu pages all returning HTTP 200 with their permissions present in config and in the database and held by the Front Office Manager, no stub actions, no broken route() references, no missing permission badges, and 29 scheduled automations registered. The behavioural side proved the money and the paperwork: a waitlist entry converted to RES-00205, a room block ran its full life cycle as BLK-00004, a CRO enquiry ENQ-00031 was logged, contacted and converted into RES-00208 at GH₵ 6,020, a concierge activity produced a voucher, a settlement AST-00003 and a void, call accounting imported an SMDR file that classified a local call at GH₵ 40 and an international call at GH₵ 390 and posted the matched ones to folios while queuing the rest for review, key cards were encoded, read, reported lost with two replacements, and returned, and a master key was signed out to a holder with a due time and signed back in. The guest-facing device surfaces are JSON and single-page apps rather than forms, which is why an HTML-form sweep could not see them: the kiosk check-in was driven through its whole state machine — booking lookup by number and surname, ID photo capture validated for real image dimensions, registration card signed with a PNG signature, a GH₵ 4,126.50 deposit raised as an online payment and settled through the test gateway, then room assignment and key issue — ending with the stay checked in to room 202 with its GRC signature, its ID document, its key card and its advance on the folio. The IPTV side answered the same way: a room TV rented a pay-per-view movie for GH₵ 102.85 once the guest PIN was supplied, an emergency alert went to 27 screens and was cleared again, and all seven remote commands queued to the device. Two product rules are worth knowing rather than fixing: Form C ships switched off and says so on the page, and a paid movie rental needs the guest PIN that check-in writes.',
    screens: [['Kiosk desk — the visit just completed', '/self-service/visits'], ['IPTV devices and remote commands', '/iptv/devices'], ['Call accounting — imported SMDR records', '/calls?tab=log'], ['Master keys — sign-out and sign-in', '/key-cards?tab=master'], ['Foreign exchange position', '/foreign/position']],
  },
  {
    title: 'Every check-out branch and the discount/void pair proven over HTTP — and check-in no longer fails on a blank required field',
    detail: 'A verification pass exercised the money paths that had never been run, and they held. A GH₵ 100 discount on an open folio moved the balance by exactly 100.00 and voiding it restored it to the cent, with the void reason recorded on the transaction. A same-day check-out whose advance exceeded the bill ran the refund branch: charges of GH₵ 7,078.50 against an GH₵ 8,000 advance produced a GH₵ 921.50 refund and a folio closed at zero as INV-00146. Two further departures proved the new bill assembly on transport holds rather than outlet bills — airport pick-ups of GH₵ 1,800 and GH₵ 1,200 plus tax were posted to their folios by the single action, after which both check-outs completed (INV-00145, INV-00146). The check-out quote even predicted the interim state correctly: before the night posted it read refund due GH₵ 6,548.00, and after posting the night the refund settled at GH₵ 921.50, the difference being exactly the unposted room tariff. One papercut surfaced twice during the run and is fixed: the KYC ID type is required by validation but the form left the select blank, so a check-in submitted straight from the prefilled page failed with The guest.id type field is required. The select now defaults to Ghana Card for Ghanaian guests and passport for everyone else, verified on both the check-in and the new-reservation forms. No money logic changed in this pass; the only code change is the ID type default.',
    screens: [['Check-in form with the ID type default', '/front-desk/stay/75/check-in'], ['New reservation form', '/reservations/create'], ['Check-out with the assembly panel (room 102 hold)', '/front-desk/stay/64/check-out']],
  },
  {
    title: 'One click now assembles a departure bill instead of chasing each outlet screen',
    detail: 'A departure with an unsettled outlet bill, open laundry or a room-billed trip could not check out at all: the guard refused and the desk had to leave the check-out screen, find the order in the POS terminal, post it to the room, and come back. The check-out screen and the reservations departures list now carry a single action — Post outstanding charges to this bill — that posts every unsettled outlet bill, pending laundry order and pending transport trip onto the folio in one transaction, and reports exactly what it posted and what it deliberately left alone (guest QR items awaiting confirmation, laundry not yet processed, activities waiting on their operator, an unfiled Form C). The money logic is not a second implementation: the room-post branch of the POS settle action was extracted into PosOpsService::postToRoom, and both the cashier path and the new batch path call it; a regression run then settled a fresh in-room dining order through the cashier path to prove the extraction changed nothing. Verified live on room 102: the panel listed 1 unsettled outlet bill at GH₵ 387.00, one click posted it and the settlement figure moved from GH₵ 7,636.44 to GH₵ 8,023.44 with the hold cleared from the board and the rail, a second click reported Nothing outstanding could be posted, and the cashier path then posted a further GH₵ 290.00 order taking the figure to GH₵ 8,313.44.',
    screens: [['Check-out screen — blocked panel and the action', '/front-desk/stay/64/check-out'], ['Reservations departures — Post to bill', '/reservations?view=departures'], ['Reservation Workspace rail', '/reservations/workspace']],
  },
  {
    title: 'Four more pages rendered outside the layout — caught by scanning every Blade file, not by browsing',
    detail: 'The unstyled Reservation Workspace was not a one-off. A static scan of every view that extends a layout, looking for markup sitting outside any section or push, found four pages with the same fault: the board itself plus the HR self-service desk, the offline desktop sync page and both rentals screens. Each emitted its flash alerts and its whole body ahead of the layout document, so the browser parsed the app shell after the page content and the page rendered broken or in quirks mode. The live sweep agreed — those were the only HTML responses that did not begin with a doctype — while CSV, JSON and XML exports were correctly excluded because they carry no HTML at all. All four views are now wrapped in a proper content section and the scan is clean across the whole view tree. The check is now a repeatable tool rather than a one-off: deploy/dev/page_structure_check.py walks the route table, fetches every HTML page and fails if any document does not start with a doctype or carries more than one html or body element. It is the cheapest possible guard against this class of defect, which status codes and HTML scraping both miss entirely. The stale module and page counts in the gap audit header were refreshed to match docs/MODULES.md at the same time.',
    screens: [['HR self-service desk', '/hr/ess'], ['Offline desktop sync', '/offline/desktop'], ['Rentals', '/rentals'], ['Rentals fleet', '/rentals/fleet'], ['Reservation Workspace', '/reservations/workspace']],
  },
  {
    title: 'The Reservation Workspace rendered unstyled — its CSS never reached the browser and its markup escaped the layout',
    detail: 'Opening the workspace in a real browser showed the side menu fine but the content area was two enormous red zigzag lines: the KPI sparklines drawn at full page width with no height cap, because every one of the board own CSS rules was missing from the document. Two structural faults in the view, both invisible to a text-only check. First, the board pushed its stylesheet onto a stack named styles, but the application layout only renders a stack named head, so the whole style block — sparkline size, photo height, KPI grid, rail, modal — was silently dropped; the print layout had no head stack at all and lost the Day Report styles the same way, so it now renders one. Second, the board markup was never wrapped in @section content: in a view that extends a layout, top-level output is emitted before the layout document instead of at the yield, which is why the raw board HTML appeared ahead of the html and body tags and the browser had to guess at it. The markup is now a proper content section, both views push to head, and the print layout renders that stack. Verified in the served document: the sparkline and photo rules are present, the nine KPI tiles and twenty-four photo blocks sit inside the layout after the side menu, nothing workspace-related appears before the body tag, and 49 print, report and reservations pages return 200. The lesson is recorded: scraping HTML proves the server answered, never that the page renders — this defect survived several hundred-page sweeps because not one of them looked at pixels.',
    screens: [['Reservation Workspace — now styled', '/reservations/workspace'], ['Day Report pack — now styled', '/reservations/workspace/report']],
  },
  {
    title: 'Room photos and sizes were absent on any fresh install — and the photo sat behind its own fallback',
    detail: 'The room board showed a colour gradient where the room photo belongs and the size chip showed the type code instead of square metres. Two separate faults. The v7.41 migration that adds room_types.size_sqm and room_types.image backfills them by type code, but on a fresh build every migration runs before the seeder creates the room types, so the backfill matched zero rows and both columns were left empty — the demo database had no sizes and no photos at all, and nothing anywhere reported it. The seeder now writes them, so a fresh install carries 24, 32, 48 and 85 square metres and the four standard photos; the migration backfill stays for databases that already had room types. The second fault was in the board itself: the photo was appended after the gradient inside one background shorthand, and in CSS the first layer paints on top, so the opaque gradient hid the photo even once the column held a value. The photo is now the first layer with the gradient beneath it as the fallback while it loads or if the file is missing. The desk screens read photos through ReservationDeskService::photo(), which only looks at uploaded room_images rows — an empty table here — so a new photoPath() helper falls back to the room-type media column, and the reservations desk, its room board, its room card and the workspace board all use it. Verified: 24 room photos on the board view, 27 on the desk dashboard, one on each room card (101 shows the standard photo, 202 the deluxe), 48 background images across the workspace board and its room modals, all four JPEGs served at 200 as image/jpeg, and size chips reading 24 m². A first attempt left a ->path read on a string in the desk dashboard because two identical thumbnail lines existed and only the first was replaced, and /reservations returned a 500 until both were fixed — which is why the whole reservations surface was re-checked afterwards.',
    screens: [['Reservation Workspace — board with room photos', '/reservations/workspace'], ['Reservations desk dashboard', '/reservations'], ['Room board — 24 photos', '/reservations?view=board'], ['Room card — 202 with the deluxe photo', '/reservations/rooms/10/card']],
  },
  {
    title: 'The board now flags departures that cannot check out yet',
    detail: 'A settlement figure is no use if the desk cannot collect it. Posting the city-ledger checkout for room 103 was refused — 1 unsettled POS order for the room — while the board and the Day Report both listed that guest as GH₵ 9,172.72 to settle with nothing to suggest checkout would bounce, so the guest would have been walked to the cashier and sent back. Both screens now name the holds that actually refuse a checkout: unsettled POS orders, transport trips waiting on the room bill, activities the operator has not confirmed, and a pending Form C when the FRRO block is switched on, each with the money it will add once it is cleared. Open laundry is deliberately left out because it is posted separately and does not hold a guest at the desk — room 101 checked out with a laundry order still open, and that is the evidence. The list comes from StayService::checkoutHolds(), extended with the POS and Form C lookups that used to sit inline in the quote prose, so the checkout page, the desk board and the Day Report read from one place and cannot drift from the guard that actually runs. Verified live end to end: room 103 showed the hold, the order behind it turned out to be a guest QR bill awaiting confirmation, accepting the two items priced it at GH₵ 1,040.00 plus GH₵ 218.40 tax, posting it to the room lifted the departure figure from GH₵ 9,172.72 to GH₵ 10,430.72 and cleared the hold, and the city-ledger checkout then completed — folio closed at zero with the GH₵ 10,430.72 receivable on the Booking.com ledger as INV-00144, the AR index and the company ledger agreeing, and the Day Report reading Settled. The same run proved the part-payment guard: settling GH₵ 1,000 against room 102 was refused quoting the GH₵ 6,636.44 residual, and the rolled-back transaction left no orphan payment row. The same blind spot sat on the reservations desk itself: its Blocked badge and the hold column in its departures view only looked at transport and activities, so an unsettled POS order — the commonest reason a checkout bounces — raised no warning on either screen. Both now read the same canonical count, and the hold column names the order and its amount (room 102 reads Checkout blocked. POS 1, GH₵ 387.00, and the badge beside the guest name lights up). Room 102 now carries a fresh in-room dining order (Paneer Tikka, GH₵ 387.00, KOT printed, unsettled) so the hold marker is visible on the board in the preview.',
    screens: [['Reservation Workspace — held departure in the rail', '/reservations/workspace'], ['Day Report — departures with holds', '/reservations/workspace/report'], ['Check-out quote for the held departure', '/front-desk/stay/64/check-out'], ['The unsettled in-room dining order behind the hold', '/pos/orders/504'], ['Booking.com ledger carrying the city-ledger receivable', '/accounts/city-ledger/5'], ['Reservations desk — departures hold column', '/reservations?view=departures'], ['Reservations desk — Blocked badge', '/reservations']],
  },
  {
    title: 'Departure figures understated — the desk saw the folio balance instead of what checkout will collect',
    detail: 'The Reservation Workspace and the Day Report printed a departing guest folio balance where the number the desk actually needs is what checkout will settle. Those differ whenever a stay still has charges to post: the guest in room 101 showed GH₵ 774.40 while checkout would collect GH₵ 2,589.40, so the desk was told to expect under a third of the money. Worse, a departure that had already checked out printed Settled no matter what it owed, because the checkout-quote helper refuses to run on a stay that is not in house and the surrounding catch swallowed that and left zero. Both screens now take one figure per departure: for a guest still in house it is the checkout quote (balance plus nights, extra and fixed charges not yet posted), and for one already checked out it is the closed folio balance. The room card and its detail modal now show the two numbers separately — Balance for the folio as it stands and Due at checkout for what the desk will collect — instead of labelling one figure with the other name, and the rail and the Day Report list what each departure is expected to settle. A departure whose quote still has charges to post is flagged with an asterisk and a footnote saying so. Verified on the books for 13 Oct 2026: rooms 101, 102 and 103 show GH₵ 2,589.40, GH₵ 7,636.44 and GH₵ 9,172.72, and only room 101 is asterisked because it is the only one with unposted charges. The same session also drove the real money path end to end to prove the figures: checked an arrival into room 202 with a GH₵ 2,000 card advance, posted a GH₵ 480 F&B charge (tax GH₵ 100.80 at the club 21% rate), then checked out and settled GH₵ 2,707.30 — invoice INV-00142 with GH₵ 5,130.00 charges plus GH₵ 1,077.30 tax against GH₵ 6,207.30 of payments, balance zero, room back to vacant dirty.',
    screens: [['Reservation Workspace — departures in the rail', '/reservations/workspace'], ['Day Report — departures table', '/reservations/workspace/report'], ['The settled invoice from the verification run', '/folios/183/invoice']],
  },
  {
    title: 'Night Audit could not run at all — a variable was read one line before it was assigned',
    detail: 'Running the night audit returned a 500: Undefined variable $counter. The v7.38 line that added recreation desk passes and outlet takings into other revenue was inserted after the line that reads it, so the whole close() transaction aborted. Lint is clean for this class of bug and no page renders it — it only fires when the audit is actually run, which is why a 461-page GET sweep never caught it. Reordered the two lines. The audit now completes: for 13 Oct 2026 it posted 12 room-tariff rows totalling GH₵ 25,080 (exactly the room_revenue it recorded), other revenue GH₵ 71,904, tax GH₵ 20,366.64, collections GH₵ 91,079.70, occupancy 37.5% over 9 of 24 rooms, ADR GH₵ 2,786.67 and RevPAR GH₵ 1,045, then rolled the business date to 14 Oct. Run with no-show marking off, so the four arrivals for the day stayed reserved instead of being written off. The recorded arrivals and departures are 0 and that is correct — those two fields count guests who actually checked in and out during the day, and in the demo data nobody had.',
    screens: [['Night Audit — precheck and run', '/night-audit'], ['Night Audit result (after running it)', '/night-audit/17'], ['Reservation Workspace after the audit', '/reservations/workspace']],
  },
  {
    title: 'Systemic fix — date queries now match every stored row (419 call sites)',
    detail: 'Date columns in this database are stored in two different text forms: rows written through Eloquent land as 2026-10-13 00:00:00 while raw and seeder inserts land as 2026-10-13. A query that compared a column against a plain date string therefore matched only part of the table, or nothing at all, and reported zeros and understated money with no error anywhere. Every comparison against a *_date column now goes through whereDate(), which is correct for both forms. This changes real numbers: the Reservation Workspace went from 0 arrivals and 0 departures to the 4 and 3 that were actually on the books, the reservations desk tabs and KPIs match, Night Audit picks up the full days folio revenue instead of a fraction of it, its already-audited guard can no longer miss a same-day audit, and its precheck counts same-day arrivals. Arrivals are also counted hotel-wide rather than room-wide, so an arrival that has not been given a room yet still appears on the board, in the KPI strip, in the rail, in the Day Report and in the side-menu badge. A second pass with paren-balanced scanning caught 68 more sites where the date string was nested inside a call, for example a where on arrival_date comparing against Carbon::parse($to)->toDateString() in AvailabilityService::blockingStays — the query every availability, assignment and rate decision in the app depends on. Ranges were worse than equalities: whereBetween on a date column drops the whole last day of the range, because 2026-10-13 00:00:00 is not between 2026-10-13 and 2026-10-13. A single-day revenue range returned 3 of 42 rows and a 30-day charge total was understated by GH₵ 62,570, so 128 range sites were converted to a pair of whereDate comparisons across 40 files — 58 of them in ReportController, which is most of the report library. The Daily Revenue Report for today now totals GH₵ 77,408.54 instead of a fragment, and a single-day Arrival List shows all four arrivals. Two pages that returned a 500 on SQLite are fixed as well: GREATEST() is not a SQLite function, so /inventory/reorder and /transport/fleet failed outright; both now use portable CASE WHEN. See docs/DATE-QUERIES.md.',
    screens: [['Reservation Workspace — arrivals and departures now real', '/reservations/workspace'], ['Day Report', '/reservations/workspace/report'], ['Night Audit — precheck and revenue', '/night-audit'], ['Reservations desk', '/reservations'], ['Front desk arrivals', '/front-desk/arrivals'], ['Daily Revenue Report for today (was 3 of 42 rows)', '/reports/revenue?from=2026-10-13&to=2026-10-13'], ['Arrival List for today (was empty)', '/reports/arrivals?from=2026-10-13&to=2026-10-13'], ['Inventory reorder (was a 500)', '/inventory/reorder'], ['Transport fleet (was a 500)', '/transport/fleet']],
  },
  {
    title: 'Reservation Workspace — room board sub-module (new, with room photos & sizes)',
    detail: 'A workspace under Reservations, placed before New Reservation in the side menu: a 9-tile KPI strip with sparklines (occupancy, revenue today, available/occupied/reserved, arrivals, departures, in-house, VIP), a room board with photo cards (status, arriving-today tag, stay panel with guest, source, dates, rate and running balance, or the rack rate with Reserve Room), live filters (room status / type / floor / source), and a right rail (mini calendar with arrival and departure dots, room status summary, todays arrivals and departures, VIP guests, waiting list, housekeeping). Every room opens a detail modal with room facts, the current reservation, facilities chips and 16 quick actions wired to the real front-desk, folio, GRC and messaging flows. Buttons: Walk-In, Group, Corporate, OTA. Room types now carry size (m²) and a photo (public/images/rooms) — the board photo blocks, the size chip and the modal Room Facts show real values. KPI tiles are fully live: each sparkline is the real 7-day trend and each delta is the true day-over-day change (green up / red down, computed from on-the-books stays). The Reports button opens a printable Day Report pack (KPIs, arrivals, departures, room status, VIPs, waiting list, full room board). Day navigation: prev/next arrows and clickable calendar days move the board (and the report) to any date within +/-62 days (?date=YYYY-MM-DD) — future dates badge Planning ahead, past dates Historic board. It is a control surface, not a poster: every KPI tile links to its real page (occupancy to the tape chart, revenue to the revenue dashboard, available/occupied/reserved to the room view, arrivals, departures, in-house and VIP to the in-house list), the rail panel titles link through to Arrivals, Departures and Housekeeping, clicking a room photo opens the modal for that room, and the side-menu item badges todays arrivals. Arrivals are counted hotel-wide, so an arrival that has no room assigned yet still shows on the board, in the KPI strip, in the rail and in the Day Report. And an arrival with no room can be given one without leaving the board: the rail offers a picker of the rooms the desk may actually assign for that night (same type first with the housekeeping state shown, other types below a divider), and Assign posts to the real reservations assign endpoint, so availability is re-checked under lock, the room type follows the room, and the change is written to the reservation amendment trail.',
    screens: [['Reservation Workspace', '/reservations/workspace'], ['Day Report (print pack)', '/reservations/workspace/report'], ['Side menu: Reservations > Reservation Workspace', '/Dashboard']],
  },
  {
    title: 'Dashboard: amenity filter tabs removed',
    detail: 'The Amenity filter card (AC, Bathtub, City view, Dining area, Jacuzzi, Living area, Lounge access, Minibar, Private butler, Smart TV, Tea/Coffee maker, TV) is removed from the dashboard entirely — the quick links that jumped to the reservations timeline with an amenity preset are gone; the timeline amenity dropdown in the reservations filter itself is unchanged.',
    screens: [['Dashboard', '/Dashboard'], ['Reservations timeline (amenity dropdown still here)', '/reservations?view=timeline']],
  },
  {
    title: 'Expedia Rapid channel adapter — second real OTA driver',
    detail: 'The channel framework now speaks a second protocol end to end: Expedia Rapid-style JSON with Rapid request signing (base64 HMAC-SHA512 of path+body, Authorization key:signature). Connection test, booking retrieval (new/modify/cancel mapping), signed ARI push and supplier-cancel all run against the local simulator, which REJECTS bad signatures — an e2e pass proves the signing. Bookings flow through the same receive pipeline as every channel (room-mapping gate, rate-mismatch warnings, idempotent duplicates). See docs/EXPEDIA-RAPID.md.',
    screens: [['Channel connections', '/channel-manager'], ['Connections detail', '/channel-manager/connections']],
  },
  {
    title: 'Car Rental — self-drive fleet and booking desk (new module)',
    detail: 'A first-class car rental module: rental fleet with classes, rates and deposits; bookings with clash detection per vehicle and weekly-rate pricing; the desk drives pick-up and return with odometer and tank readings — late returns, tank shortfalls and mileage beyond the free allowance compute automatic adjustments at return; rentals bill straight to an in-house guest folio or settle directly. Permissions rentals.view / rentals.manage.',
    screens: [['Rental desk', '/rentals'], ['Rental fleet', '/rentals/fleet']],
  },
  {
    title: 'Employee self-service: My Profile, My Documents, Announcements + HR review desk',
    detail: 'Staff can now request changes to their own contact, bank and tax details (old vs new kept per field — the employee record updates only after HR approves), upload ID/certificate/contract documents (PDF/JPEG/PNG up to 10 MB) for HR verification, and read pinned staff/department announcements. The HR Self-Service Desk reviews the queue: approve/reject profile requests and documents with notes, publish announcements to staff, a department, or everyone. Every action also notifies by SMS/WhatsApp through the message gateway (profile request and document uploads reach the manager and the HR managers; approvals, rejections and verification results reach the employee) - best-effort, logged when no gateway is configured. Demo link: the preview admin login is linked to employee #2 so the pages open.',
    screens: [['My Profile', '/me/profile'], ['My Documents', '/me/documents'], ['Announcements', '/me/announcements'], ['HR Self-Service Desk', '/hr/ess']],
  },
  {
    title: 'Desktop offline mode — multi-day cache-and-sync',
    detail: 'Front desks can work offline for days: download a 7-day operating pack (rooms, reservations, stays, rates, payment modes, guests, folios), queue desk operations with the date they happened (check-in, check-out, folio charges, payments, night posting), then sync the queue back — retries are idempotent (applied/duplicate/rejected with real reasons such as room-not-vacant or folio-not-zero) and an incremental delta refreshes the cache. Workspace page packs it together with the recent sync log; pms:offline:pack builds packs from cron. See docs/OFFLINE-DESKTOP.md.',
    screens: [['Desktop sync workspace', '/offline/desktop'], ['Front desk arrivals', '/front-office']],
  },
  {
    title: 'Social connectors & review feeds — Facebook posting, WhatsApp Cloud, live review pull',
    detail: 'Marking an approved calendar post published now delivers it live: Facebook page feed (Graph v20.0 /feed) and WhatsApp Cloud (/messages) per channel, with per-channel results on the post and the Facebook URL auto-attached; other platforms stay recorded-only. Reputation inbox gains Pull from feeds — Booking/Google/TripAdvisor/Expedia/Agoda/MakeMyTrip rows flow through the existing analyse/match/case pipeline with dedupe. Proven vs the local social simulator (docs/SOCIAL-REVIEWS.md). Push twin: the Review webhook button on the inbox shows the per-hotel POST /api/reviews/{token} endpoint — platforms push review rows straight in (idempotent, per-row errors reported).',
    screens: [['Review inbox', '/reputation/reviews'], ['Content calendar', '/digital/calendar'], ['Digital dashboard', '/digital']],
  },
  {
    title: 'Bank/MoMo statements — one-click fee booking + settlement review',
    detail: 'MoMo merchant exports split every service charge into its own fee line; booking each by hand was the slow part. New "Book fee lines" on a statement batch-books every fee/charge line to Mobile Money Charges (wallet accounts) or Bank Charges (banks), matches them with the entry, and reports anything left past the ledger cutoff. Proven on the seeded MoMo settlement: 2 open fee lines booked (GH₵348.73), statement 14/14 matched.',
    screens: [['Bank reconciliation', '/accounts/bank-reconciliation'], ['Statement BST-00003 (MoMo)', '/accounts/bank-reconciliation/3']],
  },
  {
    title: 'GRA E-VAT — live adapter proven end-to-end (docs/GRA-EVAT.md)',
    detail: 'The certified GRA driver (POST {url} + security_key, GRA invoice schema, ysdc* stamp response incl. the mesaage typo) is proven against a local VSDC protocol simulator: INV-00071 stamped live (VSDC-0001-LIVE, receipt 2/2 NS, QR), duplicate resubmission returns the same stamp, refund without reference rejected B19. Private/loopback endpoints now allowed via Developer settings or PMS_CHANNEL_ALLOW_PRIVATE.',
    screens: [['GRA VAT settings', '/vat/settings'], ['E-invoices (E-VAT)', '/vat/e-vat'], ['VAT return', '/vat/return']],
  },
  {
    title: 'Channel manager — Booking.com EPS adapter (pull-mode reservations)',
    detail: 'New booking channel driver speaks Booking.com EPS messages: handshake, OTA availability/rate push, OTA_HotelResNotif pull (new/modified/cancelled) and channel-side cancellation. ChannelService::pull() feeds pulled rows through the idempotent mapping pipeline; connections gain a Pull bookings button. E2E proven against a local EPS simulator (docs/BOOKING-EPS.md).',
    screens: [['Channel manager', '/channel-manager'], ['Connections', '/channel-manager/connections'], ['OTA inbox', '/channel-manager/inbox']],
  },
  {
    title: 'Tax settings — price entry: tax-inclusive or tax-exclusive',
    detail: 'GST & Tax → Settings gains a Price entry switch: tax-exclusive (prices net of tax, tax added at the till — the default, nothing changes) or tax-inclusive (VAT/NHIL/GETFund/Tourism Levy extracted from the price). POS, the recreation outlet, club day passes and membership charges all honour it; room rates keep their per-rate-plan flag.',
    screens: [['Tax settings (Price entry)', '/vat/settings'], ['Recreation outlet', '/recreation/outlet'], ['Club day passes', '/club/passes']],
  },
  {
    title: 'Recreation accounting — counter takings in GL & day close (v7.38.0)',
    detail: 'Outlet sales now post to the general ledger (split tenders debit their cash/bank/wallet accounts, change returns to the tender, revenue lands in club revenue + tax heads). Night audit and income audit count desk day-pass and outlet takings in other revenue, tax and collections — with a business_date so after-midnight sales roll to the right day. Outlet passes post once via their sale (no double count).',
    screens: [['Income audit', '/income-audit'], ['Ticket sales', '/recreation/sales'], ['Night audit', '/nightaudit']],
  },
  {
    title: 'Recreation — POS ticket outlet (walk-ins) + memberships kept',
    detail: 'Wellness & Recreation gains a full Recreation Outlet: POS-style till with ticket-type catalog (access tickets, rentals, services), split-tender payments (cash/card/MoMo/room/company), and printed entry tickets redeemed at the the new Walk-in Ticket panel on the check-in desk (scan the code on the receipt). Club membership, billing, classes and the check-in desk are untouched — members scan membership, walk-ins buy tickets.',
    screens: [['Ticket outlet (POS)', '/recreation/outlet'], ['Ticket sales', '/recreation/sales'], ['Ticket types', '/recreation/types'], ['Check-in desk + ticket scan', '/club/desk'], ['Members (kept)', '/club/members']],
  },
  {
    title: 'UI — reservation desk: Details drawer removed',
    detail: 'The fixed Details panel that overlaid the reservation desk is removed (it rendered on top of the page). Stay and room clicks now open the full record page directly.',
    screens: [['Reservation desk', '/reservations']],
  },
  {
    title: 'v7.37 — Mobile Money collections (request-to-pay)',
    detail: 'Split settlements just got automated MoMo: send a payment prompt to the guest phone (MTN/Telecel/AirtelTigo), the payment posts to the folio on approval via webhook or Check status. Webhook loop proven against the sandbox provider simulator (auto-posted folio transaction).',
    screens: [
      ['Folio — Request MoMo button + prompt modal', '/folios/159'],
      ['Payments — collection monitor (pending → paid)', '/payments'],
      ['MoMo provider simulator', '/'],
    ],
  },
  {
    title: 'v7.37 — OTA/channel driver layer (in progress)',
    detail: 'ChannelDriver contract + registry + Hub/Simulator/Mirror drivers. Test connection button on every connection; signed-JSON hub protocol proven end-to-end against the sandbox simulator (240 room-nights pushed over HTTP 200, booking pull works). Real OTA adapters plug in next.',
    screens: [
      ['Channel manager', '/channel-manager'],
      ['Connection: Sandbox Hub — Test / Push / Full sync', '/channel-manager/connections/6'],
      ['Connection: Sandbox Simulator', '/channel-manager/connections/5'],
    ],
  },
  {
    title: 'Preview delivery system',
    detail: 'Live preview auto-signs in and lands on the app; pages ship styled (inline CSS); iframe-embeddable; this changes index.',
    screens: [
      ['Development changes index', '/__dev'],
      ['Reservation desk (preview home)', '/reservations'],
    ],
  },
  {
    title: 'v7.36 — Live PABX capture',
    detail: 'pms:pbx:listen streams SMDR call records from the PABX (connect + listen modes) via a TCP driver, with command-link templates and a link test.',
    screens: [
      ['Interfaces › PABX settings', '/interfaces'],
      ['Call accounting records', '/calls'],
    ],
  },
  {
    title: 'v7.35 — Data migration & cutover toolkit',
    detail: 'Upload → validate → apply legacy data (companies, guests, employees, rooms, reservations) with dry-run, per-row errors, and CSV templates.',
    screens: [['Settings › Data migration', '/settings/migration']],
  },
];

function esc(s) {
  return String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

function devChangesPage() {
  let commits = '';
  try {
    const g = Bun.spawnSync(['git', '-C', REPO_ROOT, 'log', '--oneline', '-25'], { stdout: 'pipe' });
    commits = new TextDecoder().decode(g.stdout ?? new Uint8Array())
      .trim().split('\n').filter(Boolean)
      .map((l) => {
        const m = /^([0-9a-f]{7,40})\s+(.*)$/.exec(l);
        return m ? `<li><code>${m[1]}</code> ${esc(m[2])}</li>` : `<li>${esc(l)}</li>`;
      }).join('\n');
  } catch {
    commits = '<li>git log unavailable</li>';
  }
  const cards = DEV_CHANGES.map(
    (c) => `<article class="card"><h2>${esc(c.title)}</h2><p>${esc(c.detail)}</p>
      <p class="links">${c.screens.map(([label, path]) => `<a class="btn" href="${esc(path)}">${esc(label)}</a>`).join('')}</p></article>`
  ).join('\n');
  return new Response(
    `<!doctype html><html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Nowills PMS — development changes</title><style>
  :root { color-scheme: dark; }
  body { margin: 0; font: 14px/1.55 system-ui, sans-serif; background: #0b1220; color: #e6edf7; }
  header { padding: 22px 26px 10px; border-bottom: 1px solid #1d2a44; }
  h1 { margin: 0 0 4px; font-size: 19px; }
  .sub { color: #8db9ff; margin: 0; }
  main { padding: 18px 26px 40px; max-width: 880px; }
  .card { background: #111b2e; border: 1px solid #1d2a44; border-radius: 10px; padding: 14px 16px; margin: 0 0 14px; }
  .card h2 { margin: 0 0 6px; font-size: 15px; color: #bcd6ff; }
  .card p { margin: 0 0 10px; color: #aebdd4; }
  .links a.btn { display: inline-block; margin: 0 8px 8px 0; padding: 7px 12px; background: #1b64f2; color: #fff;
    border-radius: 7px; text-decoration: none; font-weight: 600; font-size: 13px; }
  .links a.btn:hover { background: #2c74ff; }
  h3 { margin: 22px 0 8px; font-size: 14px; color: #8db9ff; }
  ul { margin: 0; padding-left: 20px; color: #9fb0c8; }
  li code { color: #7dd3fc; }
  footer { padding: 0 26px 30px; color: #5b6b85; font-size: 12px; }
</style></head><body>
<header><h1>Development changes</h1>
  <p class="sub">Every development change, with deep links to see it in the live app.</p></header>
<main>
${cards}
  <h3>Recent commits</h3>
  <ul>
${commits}
  </ul>
</main>
<footer>Preview server · <a href="${DEV_HOME}" style="color:#8db9ff">open the app</a> · disable auto-login with PMS_DEV_AUTOLOGIN=0</footer>
</body></html>`,
    { headers: { 'content-type': 'text/html; charset=utf-8', 'cache-control': 'no-cache' } }
  );
}

// Persistent corner chip on previewed app pages → the changes index above.
const DEV_CHIP = `<a href="/__dev" style="position:fixed;right:12px;bottom:12px;z-index:2147483647;padding:7px 12px;border-radius:999px;background:#1b64f2;color:#fff;font:600 12px/1 system-ui,sans-serif;text-decoration:none;box-shadow:0 4px 14px rgba(0,0,0,.35);opacity:.92">⚡ dev changes</a>`;

async function phpRequest(method, path, { cookies = '', body = null, host = `localhost:${PORT}` } = {}) {
  await phpReady;
  const enc = body ? new TextEncoder().encode(body) : new Uint8Array(0);
  const headers = new Headers({
    host,
    'content-type': 'application/x-www-form-urlencoded',
    'x-forwarded-proto': 'https',
  });
  if (cookies) headers.set('cookie', cookies);
  const { srv, rawHeaders } = headersToServer(headers, new URL(path, `http://${host}`), method, enc.byteLength);
  const resp = await run(() =>
    php.run({
      scriptPath: `${APP_MOUNT}/public/index.php`,
      relativeUri: path,
      method,
      headers: rawHeaders,
      body: enc,
      $_SERVER: srv,
      env: { HOME: '/tmp' },
    })
  );
  const fh = flattenHeaders(resp.headers);
  const setCookies = typeof fh.getSetCookie === 'function' ? fh.getSetCookie() : [];
  return {
    status: resp.httpStatusCode || 200,
    body: new TextDecoder().decode(resp.bytes ?? new Uint8Array()),
    setCookies,
    location: fh.get('location') ?? '',
  };
}

async function ensureDevLogin() {
  if (devCookies) return devCookies;
  const g = await phpRequest('GET', '/login');
  const m = /name="_token" value="([^"]+)"/.exec(g.body);
  if (!m) throw new Error('auto-login: no CSRF token on /login');
  const jar = g.setCookies.map((c) => c.split(';')[0]);
  const body = new URLSearchParams({ _token: m[1], hotel_code: DEV_HOTEL, username: DEV_USER, password: DEV_PASS }).toString();
  const p = await phpRequest('POST', '/login', { cookies: jar.join('; '), body, host: `localhost:${PORT}` });
  const ok = p.status >= 300 && p.status < 400 && !/\/login/.test(p.location ?? '');
  process.stderr.write(`[pms-serve] auto-login POST → ${p.status} loc=${p.location} (${p.setCookies.length} cookies)\n`);
  if (!ok) throw new Error(`login POST returned ${p.status}`);
  // Dedupe by name, keeping the newest value (login regenerates the session id).
  const map = new Map();
  for (const c of [...g.setCookies, ...p.setCookies]) {
    const kv = c.split(';')[0];
    const i = kv.indexOf('=');
    if (i > 0) map.set(kv.slice(0, i), kv.slice(i + 1));
  }
  devCookies = [...map.entries()].map(([k, v]) => `${k}=${v}`).join('; ');
  process.stderr.write(`[pms-serve] dev preview signed in as ${DEV_USER}@${DEV_HOTEL} [${[...map.keys()].join(', ')}]\n`);
  return devCookies;
}

function hostFileFor(uriPath) {
  // Map a URL path onto public/ safely (no traversal).
  let p = decodeURIComponent(uriPath.split('?')[0]);
  if (p.includes('\0')) return null;
  p = normalize(p).replace(/^(\.\.[/\\])+/, '');
  const full = join(PUBLIC_DIR, p);
  if (!full.startsWith(PUBLIC_DIR)) return null;
  return full;
}

function isFile(f) {
  try {
    return existsSync(f) && statSync(f).isFile();
  } catch {
    return false;
  }
}

const MIME = {
  '.html': 'text/html; charset=utf-8',
  '.css': 'text/css; charset=utf-8',
  '.js': 'text/javascript; charset=utf-8',
  '.mjs': 'text/javascript; charset=utf-8',
  '.json': 'application/json',
  '.png': 'image/png',
  '.jpg': 'image/jpeg',
  '.jpeg': 'image/jpeg',
  '.gif': 'image/gif',
  '.svg': 'image/svg+xml',
  '.ico': 'image/x-icon',
  '.webp': 'image/webp',
  '.woff': 'font/woff',
  '.woff2': 'font/woff2',
  '.ttf': 'font/ttf',
  '.map': 'application/json',
  '.txt': 'text/plain; charset=utf-8',
  '.pdf': 'application/pdf',
  '.zip': 'application/zip',
  '.ics': 'text/calendar',
  '.xml': 'application/xml',
};

function headersToServer(headers, url, method, bodyLen) {
  const h = {};
  for (const [k, v] of headers.entries()) h[k.toLowerCase()] = v;
  const host = h['host'] ?? `localhost:${PORT}`;
  const proto = h['x-forwarded-proto'] ?? 'https';
  const serverName = host.replace(/:\d+$/, '');
  const query = url.search.startsWith('?') ? url.search.slice(1) : '';
  const srv = {
    REQUEST_METHOD: method,
    REQUEST_URI: url.pathname + url.search,
    QUERY_STRING: query,
    SERVER_PROTOCOL: 'HTTP/1.1',
    SERVER_NAME: serverName,
    SERVER_PORT: proto === 'https' ? '443' : '80',
    HTTP_HOST: host,
    HTTPS: proto === 'https' ? 'on' : 'off',
    DOCUMENT_ROOT: `${APP_MOUNT}/public`,
    SCRIPT_NAME: '/index.php',
    SCRIPT_FILENAME: `${APP_MOUNT}/public/index.php`,
    PHP_SELF: '/index.php',
    REQUEST_TIME: String(Math.floor(Date.now() / 1000)),
    REQUEST_TIME_FLOAT: String(Date.now() / 1000),
    REMOTE_ADDR: h['x-forwarded-for']?.split(',')[0]?.trim() ?? '127.0.0.1',
    REMOTE_PORT: '0',
    SERVER_SOFTWARE: 'pms-dev-server',
    CONTENT_LENGTH: String(bodyLen),
  };
  if (h['content-type']) srv.CONTENT_TYPE = h['content-type'];
  for (const [k, v] of Object.entries(h)) {
    if (k.startsWith('x-') || k === 'authorization' || k === 'cookie') {
      srv['HTTP_' + k.toUpperCase().replace(/-/g, '_')] = v;
    }
  }
  return { srv, rawHeaders: h };
}

function flattenHeaders(hdrs) {
  const out = new Headers();
  for (const [k, vals] of Object.entries(hdrs ?? {})) {
    if (k.toLowerCase() === 'status') continue;
    for (const v of vals ?? []) out.append(k, v);
  }
  return out;
}

// The Arena live preview embeds this server in a cross-site iframe on an
// https://{port}-{sandbox}.e2b.app origin. Loosen exactly what blocks that:
// CSP frame-ancestors, X-Frame-Options, and SameSite on session cookies.
let cssCache = null;
async function inlineStylesheets(html) {
  if (!cssCache) {
    cssCache = {
      'app.css': await Bun.file(`${PUBLIC_DIR}/css/app.css`).text(),
      'offline.css': await Bun.file(`${PUBLIC_DIR}/css/offline.css`).text(),
    };
  }
  return html.replace(/<link\b[^>]*href="[^"]*\/css\/(app|offline)\.css[^"]*"[^>]*>/g, (_m, name) => `<style>${cssCache[name + '.css']}</style>`);
}

function previewFriendly(h) {
  const csp = h.get('content-security-policy');
  if (csp) {
    h.set('content-security-policy', csp
      .replace(/frame-ancestors [^;]*/i, "frame-ancestors 'self' https://*.e2b.app")
      // Sandboxed preview iframes get an opaque origin, where 'self' matches
      // nothing — allow the preview host explicitly so styles/images/scripts load.
      .replace(/default-src 'self'/i, "default-src 'self' https://*.e2b.app data: blob:")
      .replace(/style-src 'self'/i, "style-src 'self' https://*.e2b.app")
      .replace(/img-src 'self'/i, "img-src 'self' https://*.e2b.app")
      .replace(/script-src 'self'/i, "script-src 'self' https://*.e2b.app")
      .replace(/font-src 'self'/i, "font-src 'self' https://*.e2b.app")
      .replace(/connect-src 'self'/i, "connect-src 'self' https://*.e2b.app"));
  }
  h.delete('x-frame-options');
  const cookies = typeof h.getSetCookie === 'function' ? h.getSetCookie() : (h.get('set-cookie') ? [h.get('set-cookie')] : []);
  if (cookies.length) {
    h.delete('set-cookie');
    for (const c of cookies) {
      h.append('set-cookie', c.replace(/;\s*[Ss]ame[Ss]ite=[^;]*/, '; SameSite=none'));
    }
  }
  return h;
}

const server = Bun.serve({
  hostname: HOST,
  port: PORT,
  maxRequestBodySize: 64 * 1024 * 1024,
  async fetch(req) {
    const url = new URL(req.url);
    const method = req.method.toUpperCase();

    // Dev preview entry: skip the marketing landing page and serve the app
    // home DIRECTLY (no redirect — some preview proxies do not follow 302s
    // inside the iframe, which shows up as a blank preview).
    if (DEV_LOGIN && (url.pathname === '/' || url.pathname === '')) {
      url.pathname = DEV_HOME;
    }

    // Development changes index (dev server only — not part of the product).
    if (url.pathname === '/__dev') return devChangesPage();

    // Static files (mirrors the rewrite rules in public/.htaccess).
    if (method === 'GET' || method === 'HEAD') {
      const f = hostFileFor(url.pathname);
      if (f && isFile(f) && extname(f).toLowerCase() !== '.php') {
        const mime = MIME[extname(f).toLowerCase()] ?? 'application/octet-stream';
        return new Response(method === 'HEAD' ? null : Bun.file(f), {
          headers: { 'content-type': mime, 'cache-control': 'no-cache' },
        });
      }
    }

    // Everything else: front controller (public/index.php), or a direct
    // .php file under public/ (e.g. install.php during setup).
    let script = `${APP_MOUNT}/public/index.php`;
    let scriptName = '/index.php';
    const f = hostFileFor(url.pathname);
    if (f && isFile(f) && extname(f).toLowerCase() === '.php') {
      const rel = f.slice(PUBLIC_DIR.length).replace(/\\/g, '/');
      script = `${APP_MOUNT}/public${rel}`;
      scriptName = rel;
    }

    const ab = method === 'GET' || method === 'HEAD' ? new Uint8Array(0) : new Uint8Array(await req.arrayBuffer());
    let reqHeaders = req.headers;
    if (DEV_LOGIN && !(reqHeaders.get('cookie') ?? '').includes('nowills-pms-session')) {
      try {
        const auth = await ensureDevLogin();
        reqHeaders = new Headers(reqHeaders);
        reqHeaders.set('cookie', [reqHeaders.get('cookie'), auth].filter(Boolean).join('; '));
        process.stderr.write(`[pms-serve] inject → ${auth.split('; ').map((c) => c.slice(0, c.indexOf('=') + 6)).join(' | ')}\n`);
      } catch (err) {
        process.stderr.write(`[pms-serve] auto-login failed: ${String(err?.message ?? err)}\n`);
      }
    }
    const { srv, rawHeaders } = headersToServer(reqHeaders, url, method, ab.byteLength);
    srv.SCRIPT_NAME = scriptName;
    srv.SCRIPT_FILENAME = script;
    srv.PHP_SELF = scriptName;

    try {
      await phpReady;
      const resp = await run(() =>
        php.run({
          scriptPath: script,
          relativeUri: url.pathname + url.search,
          method,
          headers: rawHeaders,
          body: ab,
          $_SERVER: srv,
          env: { HOME: '/tmp' },
        })
      );
      const head = previewFriendly(flattenHeaders(resp.headers));
      const status = resp.httpStatusCode || 200;
      let body = method === 'HEAD' ? null : resp.bytes;
      // Inline the stylesheets so the page is styled even when subresource
      // fetches are blocked (sandboxed iframes / strict CSP on the preview side).
      if (body && (head.get('content-type') ?? '').toLowerCase().includes('text/html')) {
        let html = await inlineStylesheets(new TextDecoder().decode(resp.bytes));
        if (DEV_LOGIN && !html.includes('/__dev')) {
          html = html.replace(/<\/body>/i, `${DEV_CHIP}</body>`);
        }
        head.delete('content-length');
        head.delete('transfer-encoding');
        body = new TextEncoder().encode(html);
      }
      return new Response(body, { status, headers: head });
    } catch (err) {
      process.stderr.write(`[pms-serve] ${method} ${url.pathname} -> ${String(err?.stack ?? err)}\n`);
      return new Response('Internal Server Error', { status: 500 });
    }
  },
});

console.log(`[pms-serve] Nowills PMS dev server on http://${HOST}:${PORT}`);
console.log(`[pms-serve] repo: ${REPO_ROOT}`);
