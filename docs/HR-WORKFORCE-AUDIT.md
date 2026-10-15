# HR & Workforce — completeness and Ghana statutory-compliance audit

**Date:** 13 Oct 2026 · **Branch:** `arena/49a44449-nowillsgrouppmserp` · **Base:** `64083f4` · **Release:** v7.43
**Question asked:** *are all the HR and workforce functions, features and modules fully developed — and built to meet Ghana's HR and payroll standards?*

**Answer, in two parts.**

1. **As software: yes.** The section is not a set of mock-ups. All **36** menu pages of the three
   `PEOPLE` modules return HTTP 200 and render real content, **184** HR controller routes (plus the
   6 shared master-CRUD routes behind Employees, Leave Types and Departments) resolve to real
   actions and views, all **17** permission keys the sidebar asks for are declared, seeded and held,
   and there are **0** stub markers. Four pages show an empty state, and all four are correct for the
   logged-in user (see §11).
2. **As a Ghana payroll: no — it was not.** Payroll was computing PAYE on bands that the Ghana
   Revenue Authority replaced on **1 September 2026** (Income Tax (Amendment) Act 2026, **Act 1178**),
   with no way to keep an effective-dated table; the **whole Statutory Returns module was dead** —
   it reported "no payroll run" for every month in the database, so no SSNIT, Tier 2 or PAYE
   remittance could be recorded and the overdue badge could never light; and there was **no concept
   of a public holiday anywhere in the repository**, which Labour Act 2003 (Act 651) puts at the
   centre of pay, leave and rest. Twelve defects were found (**D1–D12**, two of them critical),
   **all twelve are fixed in this branch and verified through the running application.**

Every payroll month in the demo now reconciles to the pesewa against the schedule that was in force
in it: **July and August 2026 on the GRA bands of 1 Jan 2024, September and October 2026 on Act 1178
— delta 0.00 on all 59 payslips** (§6).

---

## 1. Scorecard

### 1.1 Completeness

| Check | Result |
|---|---|
| Modules in the section | **3** (Talent Management, HR & Payroll, Learning & SOPs) |
| Menu pages | **36 / 36 return HTTP 200** (was 35 before Public Holidays was added) |
| Named routes | **184** HR controller routes (**112 write** + 72 read) + 6 shared `masters/{master}` CRUD routes |
| Controllers | **11** (Talent, Learning, Hr, Workforce, Payroll, Statutory, ServiceCharge, SelfService, Leave, Ess, Master) |
| Models referenced | **55**, of which **43** are HR-owned (payroll, attendance, roster, time clock, talent, learning, service charge, claims) |
| Stub / placeholder actions | **0** |
| Permission keys used by the HR menu | **17 / 17 declared in `config/pms.php`, 17 / 17 seeded** |
| …held by role | Administrator **17/17**, HR Manager **16/17** (all but `servicecharge.approve`, a finance duty), Accountant 6/17 |
| Scheduled automations | **1 new** (`pms:hr-run`, daily 06:40) + the night audit's `TalentService::nightly()` |
| Bytes rendered by the sweep | 12,597,347 across 36 pages (no page under 339 KB) |
| Empty states | **4**, all correct for the logged-in user (§11) |
| Genuine defects found | **12** (D1–D12) — all fixed and re-verified |

Routes by controller (write / read):

| Controller | Routes | Write | Read |
|---|---|---|---|
| TalentController | 54 | 31 | 23 |
| LearningController | 42 | 21 | 21 |
| PayrollController | 22 | 14 | 8 |
| WorkforceController | 18 | 14 | 4 |
| ServiceChargeController | 13 | 10 | 3 |
| SelfServiceController | 10 | 7 | 3 |
| EssController | 9 | 5 | 4 |
| HrController | 6 | 3 | 3 |
| LeaveController | 5 | 4 | 1 |
| StatutoryController | 5 | 3 | 2 |
| MasterController (shared, 3 HR masters) | 6 | 3 | 3 |
| **Total** | **190** | **115** | **75** |

Pages by module: Talent Management **9**, HR & Payroll **20**, Learning & SOPs **7**.

### 1.2 Ghana statutory compliance

| Area | Law | Before v7.43 | Now |
|---|---|---|---|
| PAYE bands | GRA monthly bands; **Act 1178** from 1 Sep 2026 | ❌ one hard-coded table, superseded | ✅ effective-dated schedules, month-by-month, override with a warning |
| Non-resident employment income | flat **25%** (Act 896) | ❌ taxed on resident bands | ✅ flat 25%, shown on the payslip and in the DT-107A CSV |
| Casual worker | final **5%** of gross (Act 651 s. 78 / GRA) | ❌ taxed on the bands | ✅ 5% final, `employment_type` editable in the employees master |
| Junior-staff overtime | final **5%** up to 50% of the month's basic, **10%** above; OT never joins the bands | ❌ OT added to chargeable income | ✅ separate `OT_TAX` line, OT excluded from chargeable |
| Personal reliefs | marriage 1,200 · child education 600/child · old age 1,500 · aged dependant 1,000 · education 2,000 (a year) | ❌ not modelled | ✅ `employees.tax_relief` (monthly), off chargeable income, on the payslip and in the CSV |
| SSNIT / pensions | **18.5%** of basic = Tier 1 13.5% (5.5% + 8%) + Tier 2 5%; cap **GH₵ 69,000**/month from 1 Jan 2026 | ✅ already correct | ✅ unchanged, plus the cap and the trustee on every payslip |
| Remittance deadlines | SSNIT + Tier 2 by the **14th**, PAYE by the **15th** of the following month | ✅ correct in code, **but the module could never find a payroll run** | ✅ works — 6 overdue returns now listed with amounts and dates |
| Public holidays | Act 651 ss. 72, 77; Act 601 as amended by **Act 1142** (2025) | ❌ **no concept anywhere in the repo** | ✅ gazetted calendar, editable, paid day, work on it at 2× |
| Annual leave | ≥ **15 working days** on full pay (s. 20); public holidays, certified sickness and confinement do **not** reduce it (s. 22) | ❌ counted in calendar days; approval wrote `L` over rest days and holidays | ✅ counts working days, skips rest days and public holidays |
| Maternity | ≥ **12 weeks** full pay, +2 weeks for abnormal confinement/multiples, no termination during it (s. 57) | ✅ correct | ✅ `ML` leave type seeded (84 paid days) |
| Hours and rest | 8 h/day, 40 h/week (s. 33), ≥ 12 consecutive hours daily rest (s. 41), **48 consecutive hours** weekly rest (s. 42) | ⚠️ 12 h only | ✅ 48 h weekly rest check added to the roster |
| Minimum wage | **GH₵ 21.77**/day (2026) → GH₵ 587.79/month at 27 days | ✅ correct | ✅ unchanged, alert on the run page |
| Exits | ss. 17, 18, 51, 57(8), 63, 65 (notice, final pay, redundancy) | ✅ correct | ✅ unchanged |
| Automation | — | ❌ 29 `pms:*` tasks, none HR | ✅ `pms:hr-run` |
| Notifications | Act 843 (pay is sensitive personal data) | ⚠️ leave only | ✅ payroll approved/paid (amount-free, off by default), swaps |

---

## 2. How this was verified

Nothing here is asserted from reading code alone. Every claim was produced by the running
application (Laravel on WASM PHP 8.5.11, SQLite, dev server on :8000, hotel NOWILLS + NWRESORT):

| Harness | What it does | Output |
|---|---|---|
| `deploy/dev/audit/menu-hr.json` · `hr_pages.json` | resolves the `PEOPLE` section of `config('pms.menu')` into pages, permissions and badges | 36 pages |
| `deploy/dev/audit/hr_sweep.py` | GETs all 36, asserts the structural fingerprint (doctype first, exactly one `<html>`/`<body>`), counts tables/rows/forms/write buttons, hunts stub markers and empty phrases | `hr_http.json` — **36/36 = 200**, 0 stubs |
| `deploy/dev/audit/hr_paye_engine.php` | replays the tax engine against published GRA figures: schedule selection by month, Aug→Oct deltas for 6 incomes, all four GRA treatments, seeding of components/leave types/settings, the 2026 and 2027 holiday calendars | console transcript |
| `deploy/dev/audit/hr_paye_check.php` | replays **every payslip of every payroll run** and recomputes the tax with the bands *in force for that month*, personal reliefs included | §6 tables — delta 0.00 on all four runs |
| `deploy/dev/audit/hr_paye_flow.py` | drives the UI: recalculates the October draft, creates the September run through Payroll › New run, marks 21 Sep as a worked public holiday on HR › Attendance, recalculates, reads the statutory tabs | §8, §9 |
| `deploy/dev/audit/hr_tax_cases.py` | drives the UI: edits an employee in Masters › Employees, recalculates, reads the payslip, for the four GRA special treatments — then **reverts the demo data and proves it came back** | §7 |
| `deploy/dev/audit/hr_perms.php` | every HR menu permission key vs `config/pms.php` vs the `permissions` table vs every role | §1.1 |
| `artisan pms:hr-run` | the new nightly task, for both hotels, `--dry` and live | §10 |

---

## 3. The statutory baseline used

Rates, deadlines and calendars were taken from the primary sources on 13 Oct 2026 and are the
figures now encoded in the code and shown in the UI:

- **PAYE, year of assessment 2026, effective 1 Sep 2026 (Act 1178)** — first GH₵ 588 @ 0%, next 80 @
  5%, next 100 @ 10%, next 2,900 @ 17.5%, next 16,000 @ 25%, next 30,332 @ 30%, above 50,000 @ 35%
  (cumulative ceilings 588 / 668 / 768 / 3,668 / 19,668 / 50,000). Non-resident employment income:
  flat 25%. Return and payment by the **15th** of the following month (DT 107 / 107A / 107C).
- **The schedule it replaced** (in force Jan–Aug 2026) — 490 @ 0%, 110 @ 5%, 130 @ 10%, 3,166.67 @
  17.5%, 16,000 @ 25%, 30,520 @ 30%, above 50,416.67 @ 35%. Payroll keeps it, so a reopened July or
  August run is still taxed as it should have been.
- **GRA overtime and casual rules** — junior staff (qualifying employment income ≤ GH₵ 18,000 a
  year): overtime up to 50% of the month's basic at a final 5%, the excess at 10%, and overtime is
  never added to basic; casual workers (Act 651 s. 78) a final 5% of gross; bonus ≤ 15% of annual
  basic at 5% final; non-resident overtime/bonus 20%. *(The bonus rule was a follow-up when this audit
  was written; it is implemented and verified in §14.)*
- **Pensions (Act 766 as amended by Act 971)** — 18.5% of basic: Tier 1 13.5% (employee 5.5% +
  employer 8%) to SSNIT, Tier 2 5% (employer) to a licensed trustee, Tier 3 voluntary and pre-tax.
  Both by the **14th**. Maximum insurable earnings **GH₵ 69,000 a month from 1 Jan 2026**; minimum
  insurable GH₵ 587.79 (21.77 × 27). Penalties: SSNIT 3% a month compounded; PAYE 10% plus interest
  at 125% of the Bank of Ghana base rate.
- **Labour Act 2003 (Act 651)** — s. 20 annual leave ≥ 15 working days on full pay excluding
  overtime; s. 22 public holidays, certified sickness and pregnancy/confinement do not reduce it;
  s. 27 records and ≥ 30 days' notice of the leave start; s. 28 leave splittable in two; s. 33
  8 h/day and 40 h/week; s. 35 overtime only where rates are fixed; s. 41 ≥ 12 consecutive hours
  daily rest; s. 42 **48 consecutive hours** of weekly rest in every seven days; s. 43 rest periods
  exclude public holidays; s. 57 maternity ≥ 12 weeks; s. 58 one paid nursing hour a day for 12
  months; s. 72 public holidays with pay; **s. 77 a temporary or casual worker is paid for every
  public holiday plus the proportion for the hours worked on it**. Act 651 sets no statutory sick-
  leave duration or sick-pay formula — paid sick leave is policy or collective agreement.
- **Public holidays (Act 601, as amended by Act 1142 of 2025)** — 2026: 1 Jan, 7 Jan Constitution
  Day, 6 Mar Independence, 20 Mar Eid al-Fitr, 3 Apr Good Friday, 6 Apr Easter Monday, 1 May
  Workers' Day, 27 May Eid al-Adha, 4 Aug Founders' Day, **21 Sep Kwame Nkrumah Memorial Day**,
  4 Dec Farmers' Day, 25–26 Dec and a 28 Dec substitute = **14 paid days**, with **25 May Africa
  Unity Day** and **1 Jul Republic Day** commemorative (working) days. 2027 carries **17**. Islamic
  dates and substitutes follow the gazette, so the calendar is editable per hotel per year.

---

## 4. What was already correct (and was left alone)

The payroll core was genuinely built for Ghana, not retrofitted: the 18.5% Tier 1 / Tier 2 split
with the 5.5% employee share taken off chargeable income **before** PAYE; the GH₵ 69,000 insurable
cap; the 14th / 14th / 15th deadlines with nil, draft, due and overdue states over the last three
payroll months; Ghana Card PIN reused as SSNIT number and TIN; remittance posting
Dr liability / Cr payment mode and locking the month; DT-107A CSV exports; GL reconciliation of the
payroll journals; the national minimum-wage floor (21.77 × 27) with an alert on the run page;
`GhanaTaxSetup` for the chart of accounts; Act 651-compliant exits (ss. 17, 18 notice and pay in
lieu, s. 51 disability, s. 57(8) protection during maternity, s. 63 unfair termination, s. 65
redundancy, with `clearanceFacts()` covering key returns, open advances and untaken-leave
encashment at salary × 12 / 260); and roster limits of 6 consecutive days and 12 hours' rest.

---

## 5. The twelve defects

| # | Severity | Defect | Fix | Verified |
|---|---|---|---|---|
| **D1** | 🔴 critical | PAYE used one hard-coded band table (GRA 1 Jan 2024). Act 1178 replaced the resident bands on 1 Sep 2026 and there was no effective-dating, so every month from September was taxed on superseded bands — October over-paid GRA by GH₵ 2.68 overall, over-taxing the six lowest-paid workers by GH₵ 11.15 each | `PayrollService::PAYE_SCHEDULES` + `scheduleFor($period)`: each month is taxed on the schedule in force in it; the manual override is preserved and now warned about in the UI; the components page shows which schedule applies to which month | §6 — October recomputed through the UI: **12,717.26 → 12,714.58**, delta 0.00 against Act 1178; July/August still delta 0.00 against the 2024 bands |
| **D11** | 🔴 critical | `StatutoryService::runFor()` compared a `date` column with string equality (`where('period_start', '2026-08-01')`) while SQLite stores `'2026-08-01 00:00:00'` → **it returned null for every month**. The Statutory Returns module always said "No payroll run", `stat_overdue` never lit, no SSNIT/Tier 2/PAYE remittance could be recorded. The same bug class defeated `PayrollService::createRun()`'s duplicate-month guard (a second run for a month hit the unique index → HTTP 500) and let `lockedRunFor()` / `assertOpen()` miss a locked month on its first day | `whereDate()` across `PayrollService`, `StatutoryService`, `ServiceChargeService`, `CloseService`, `ReportController`, `ServiceChargeController` — including the `<=` / `>=` comparisons | §9 — the statutory page now lists **6 overdue returns** with amounts and due dates; `pms:hr-run` reports them; `?tab=paye&period=2026-08` totals GH₵ 12,162.94 |
| **D2** | high | No casual-worker 5% final tax and no junior-overtime concession; `employment_type` was not even a field | Both rules implemented (`CASUAL_RATE`, `OT_JUNIOR_*`, settings-adjustable), `employment_type` added to the employees master (permanent / fixed-term / weekly / casual), the treatment named on the payslip and in the CSV | §7 — casual: PAYE 221.60 = 5% of gross 4,432.00; junior OT: OT tax 2.50 on 50.00 of overtime, OT excluded from chargeable income |
| **D3** | high | No public-holiday concept anywhere in the repository — no calendar, no attendance status, no premium pay, nothing in the roster or the leave engine | `HolidayService` (gazetted 2026 + 2027, editable per hotel per year), `PH` attendance status, `OTH` "Public holiday work" component at `payroll_holiday_multiplier` (2×), a **Public Holidays** page, holiday columns in the register and roster, holiday-aware clock approval, holiday notes on the run and payslip | §8 — 21 Sep 2026 marked for three workers, 9 h each → OTH 408.34 |
| **D4** | high | Annual leave was counted in **calendar** days (Act 651 s. 20 requires 15 *working* days) and the approval loop wrote `L` over rest days and public holidays (ss. 22, 43 say those days must not be consumed); no maternity (`ML`) leave type | `LeaveService::countDays()` skips the worker's rest days and gazetted holidays (setting `leave_count_all_days` restores calendar counting), `isDayOff()`, `ML` seeded at 84 paid days | engine transcript; register no longer shows leave over a holiday |
| **D5** | medium | GRA personal reliefs and Tier 3 were not applied — every resident was taxed without marriage/child/old-age relief | `employees.tax_relief` (a monthly figure) + `PayrollService::personalRelief()`, editable in the employees master, off chargeable income, printed on the payslip and exported in the DT-107A CSV | §7 — a GH₵ 100 relief moves chargeable income 1,242.65 → 1,142.65 and the basis line says so |
| **D6** | medium | Non-residents were taxed on resident bands and the PAYE CSV hard-coded `Resident = 'Y'` | `employees.tax_resident` + `isResident()`: flat 25%, the real flag in the CSV, a badge on the run page | §7 — PAYE 937.08 = 25% of chargeable 3,748.31, basis "Non-resident – flat 25%" |
| **D7** | medium | No HR or payroll automation at all — 29 `pms:*` tasks, none of them HR | `app/Console/Commands/HrRun.php` (`pms:hr-run`), scheduled daily at 06:40 | §10 — live output for both hotels |
| **D8** | medium | No notifications for payroll or shift swaps (leave already had them) | `PayrollService::notifyRun()` on approval and on payment — deliberately **amount-free** (Act 843 treats pay as sensitive personal data) and off unless `payroll_notify = 1` | code path + setting on Payroll › Components |
| **D9** | low | `me/unlinked.blade.php` hard-coded the title "My Payslips & Leave" while also serving `/me/roster` and `/me/learning` | The three actions pass their own title | sweep now lists "My Roster & Time Clock" and "My Training & SOPs" |
| **D10** | low | `.grid-2` was used by 15 blocks in 8 views but never defined in `public/css/app.css`, so every two-column layout silently collapsed to one | `.grid-2` defined, with a single-column breakpoint at 900 px | leave, payroll and statutory forms render in two columns |
| **D12** | low | `EssController::me()` called `abort(404)` for a login not linked to an employee record, so **`/me/profile`, `/me/documents` and `/me/announcements` returned a bare "Not Found"** — 11 of the 17 demo employees have no linked login | Returns `me.unlinked` with the right title; the two write actions flash an explanation instead | all three now HTTP 200, and render real content for a linked employee |

---

## 6. Evidence: PAYE reconciles to the schedule in force, month by month

`deploy/dev/audit/hr_paye_check.php` replays every payslip: chargeable income = taxable earnings −
the employee's SSNIT 5.5% − personal reliefs; the tax is then recomputed with
`PayrollService::scheduleFor(period_start)` — the bands that were law in that month.

| Run | Month | Status | Slips | PAYE as computed | PAYE under the schedule in force | Delta | Overtime paid |
|---|---|---|---|---|---|---|---|
| PAY-2026-07 | July 2026 | paid | 14 | 11,899.58 | 11,899.58 *(GRA bands from 1 Jan 2024)* | **0.00** ✓ | 1,024.19 |
| PAY-2026-08 | August 2026 | approved | 15 | 12,162.94 | 12,162.94 *(GRA bands from 1 Jan 2024)* | **0.00** ✓ | 1,089.24 |
| PAY-2026-09 | September 2026 | draft | 15 | 12,688.99 | 12,688.99 *(Act 1178, from 1 Sep 2026)* | **0.00** ✓ | 1,088.88 |
| PAY-2026-10 | October 2026 | draft | 15 | **12,714.58** | 12,714.58 *(Act 1178, from 1 Sep 2026)* | **0.00** ✓ | 148.38 |

October is the run that was wrong: before the fix it computed **12,717.26**. Recalculated through
Payroll › PAY-2026-10 › Recalculate it now produces 12,714.58, and the run page states
`PAYE bands: Act 1178, from 1 Sep 2026`.

The engine transcript also shows the boundary behaving correctly — a worker on GH₵ 588 chargeable
paid 4.90 in August and **0.00** from September (the Act 1178 zero band is wider), GH₵ 1,000 went
65.75 → 54.60, GH₵ 2,928 went 403.15 → 392.00, while higher earners went the other way
(GH₵ 20,000: 4,603.67 → 4,621.10; GH₵ 60,000: 17,082.83 → 17,121.10). Reopening a locked month
recomputes it on the schedule that month had, which is what an inspection asks for.

---

## 7. Evidence: GRA's four special treatments, driven through the UI

`deploy/dev/audit/hr_tax_cases.py` edits the employee in **Masters › Employees**, recalculates the
September run and reads the payslip back. Every change is reverted afterwards and the run
recalculated: the totals returned to gross 77,023.47 / deductions 16,892.12 / net 60,131.35 with
all three workers back on `Resident – Act 1178, from 1 Sep 2026`.

| Case | Change made in the UI | Payslip result | Rule |
|---|---|---|---|
| **A · Casual worker** | EMP012 → employment type *Casual / daily-rated* | PAYE **221.60** = 5% of gross 4,432.00; basis *"Casual worker – 5% final tax on gross pay"* | Act 651 s. 78 / GRA final tax |
| **B · Non-resident** | EMP004 → *tax resident* unticked | PAYE **937.08** = 25% of chargeable 3,748.31; basis *"Non-resident – flat 25%"* | Act 896 |
| **C · Junior overtime** | EMP002 → basic 900 (annual qualifying income ≤ 18,000) | **Overtime tax 2.50** = 5% of 50.00 of overtime (all of it under the 450.00 = 50%-of-basic threshold); chargeable income 1,242.65 **excludes** the overtime; basis *"Junior employee – overtime taxed at a final 5% / 10%, bands on the rest"* | GRA concession |
| **D · Personal reliefs** | EMP002 → personal reliefs 100.00 a month | chargeable income 1,242.65 → **1,142.65**; basis *"... less personal reliefs GH₵ 100.00"* | GRA annual reliefs ÷ 12 |

Case C is worth a note for anyone configuring a hotel: GRA tests the concession on the **year's
qualifying employment income**, so a basic of GH₵ 1,400 does *not* qualify once the 15% housing
allowance and the GH₵ 300 transport allowance are annualised (22,272 > 18,000). The engine applies
the test exactly that way — `(taxable − OT) × 12 + OT ≤ cap` — which is why the first attempt at the
case correctly declined to grant the concession.

---

## 8. Evidence: a public holiday, end to end (21 September 2026)

September 2026 is the demonstration month because it carries **Kwame Nkrumah Memorial Day** on
Sunday 21 September — and it is also the first month taxed under Act 1178.

1. **HR › Public Holidays** (`/hr/holidays`) lists the gazetted year: *"14 paid public holiday(s) ·
   2 commemorative day(s) · gazetted Ghana calendar"*, with 25 May Africa Unity Day and 1 Jul
   Republic Day shown as commemorative (working) days, and 2027 (17 days) one click away. The
   calendar is editable per hotel per year because Islamic dates and substitutes follow the gazette.
2. **HR › Attendance** for 21 Sep 2026 shows the day banner: *"Kwame Nkrumah Memorial Day – a
   gazetted public holiday is a paid, non-working day (Act 651 s. 72) and does not use annual leave
   (s. 22). Mark the day PH; anyone who actually worked keeps their clock times and payroll pays the
   hours at 2× (s. 77)."* Three workers were marked `PH` with 08:00–17:00 clock times; the sheet
   re-rendered with `PH` selected for all three.
3. **Payroll › PAY-2026-09 › Recalculate** produced, for those three:

| Employee | Basic | Holiday days | Hours worked on the holiday | `OTH` holiday pay | Net |
|---|---|---|---|---|---|
| EMP005 Deepa Nair | 3,800.00 | 1 | 9.00 | **126.67** | 3,836.25 |
| EMP009 Abena Owusu | 2,450.00 | 1 | 9.00 | **81.67** | 2,678.81 |
| EMP015 Anjali Menon | 6,000.00 | 1 | 9.00 | **200.00** | 5,526.25 |

   Each is `hours × (basic ÷ days ÷ shift) × (multiplier − 1)` — the day itself is already paid in
   the salary, so the premium part is what is added (s. 77). The run page now shows a `PH` column,
   and the payslip shows *"Public holidays 1 d · 9 h worked"*.
4. **The run page carries the note**: *"1 public holiday(s) fall in this period (21 Sep 2026 Kwame
   Nkrumah Memorial Day) – each is a paid day (Act 651 s. 72) and does not use annual leave (s. 22).
   Everyone is paid for them whether they work or not … hours clocked on a holiday are paid on the
   OTH line at 2×."* October's run correctly shows no such note.

A worked holiday is no longer gated on `ot_eligible`: a salaried manager who is asked to work on
Kwame Nkrumah Memorial Day is paid for it (EMP015 above). A hotel that gives time off in lieu
instead sets the holiday multiplier to 1, which pays the day but no premium.

---

## 9. Evidence: the statutory returns module works (D11)

`/payroll/statutory?tab=paye&period=2026-09` now renders, for a hotel whose July and August runs
are locked and unremitted:

> **Overdue:** SSNIT Tier 1 (13.5%) · Jul 2026 (GH₵ 7,854.37, due 14 Aug); Tier 2 occupational
> pension (5%) · Jul 2026 (GH₵ 2,909.04, due 14 Aug); PAYE income tax (GRA) · Jul 2026
> (GH₵ 11,899.58, due 15 Aug); SSNIT Tier 1 · Aug 2026 (GH₵ 8,041.21, due 14 Sep); Tier 2 · Aug 2026
> (GH₵ 2,978.24, due 14 Sep); PAYE · Aug 2026 (GH₵ 12,162.94, due 15 Sep) – late SSNIT contributions
> attract a penalty; PAYE paid late attracts GRA interest and penalties.

and, for the month whose run is still a draft:

> *"The September 2026 payroll (PAY-2026-09) is still a draft. The return is built from the approved
> payslips – approve the run first."* — with the correct due dates (14 / 14 / 15 Oct 2026).

All six tab-and-period combinations (`ssnit`, `tier2`, `paye` × September, August, July) return
HTTP 200. Before the fix every one of them said "No payroll run" while the runs sat in the database.

The PAYE tab also states which bands it applied: *"Bands applied: GRA bands from 1 Jan 2024 (in
force 01 Jan 2024) – 0 non-resident(s) at a flat 25%."* for August, and the Act 1178 equivalent for
September and October.

---

## 10. Evidence: the nightly HR task (D7)

`php artisan pms:hr-run` (scheduled daily at 06:40, `--hotel=`, `--month=`, `--draft`, `--dry`) —
live output for the demo hotel:

```
[NOWILLS] No published roster for next week (19 Oct 2026–25 Oct 2026) in Housekeeping, Maintenance,
          Front Office, Kitchen, F&B Service, Security, Spa & Wellness, Accounts, Management –
          publish it so the workers know their shifts in advance.
[NOWILLS] 10 time-clock item(s) need attention: missing clock-outs or past days still to approve.
[NOWILLS] PAY-2026-10 (October 2026) is still a draft: gross GH₵ 77,153.88, net GH₵ 60,166.66.
          Payday is the 28th – approve it in time, and re-check the PAYE bands (Act 1178, from
          1 Sep 2026) if the run is old.
[NOWILLS] OVERDUE SSNIT for 2026-07: GH₵ 7,854.37 by 14 Aug 2026 – file and pay it under Payroll ›
          Statutory (SSNIT charges 3% a month compounded, GRA 10% plus interest).
[NOWILLS] OVERDUE TIER2 for 2026-07: GH₵ 2,909.04 by 14 Aug 2026 – …
[NOWILLS] OVERDUE PAYE for 2026-07: GH₵ 11,899.58 by 15 Aug 2026 – …
[NOWILLS] OVERDUE SSNIT for 2026-08: GH₵ 8,041.21 by 14 Sep 2026 – …
[NOWILLS] OVERDUE TIER2 for 2026-08: GH₵ 2,978.24 by 14 Sep 2026 – …
[NOWILLS] OVERDUE PAYE for 2026-08: GH₵ 12,162.94 by 15 Sep 2026 – …
```

On a public holiday the same task marks the register (`PH` for every active employee, skipping days
owned by approved leave, which s. 22 protects), announces the holidays inside
`hr_holiday_notify_days`, chases leave requests past their SLA and approved leave starting within
seven days, runs the ss. 33 / 41 / 42 roster checks (40-hour week, 12-hour daily rest, **48-hour
weekly rest**, maximum consecutive days) for this week and next, lists swaps waiting for a manager,
flags payslips below the national minimum wage, and chases an approved-but-unpaid run past payday.
It writes only the holiday attendance rows and — with `--draft` — a draft run; approving leave,
approving a swap, approving or paying a run and remitting SSNIT / Tier 2 / PAYE all still need a
named human, which is the point of segregation of duties.

---

## 11. Product rules that look like failures but are not

- **`/me/hr`, `/me/roster`, `/me/learning`, `/me/profile`, `/me/documents`, `/me/announcements` show
  an empty state for the administrator**, because user 1 is not linked to an employee record. That
  is the designed behaviour and the pages now say so with the right title (D9, D12). Link a login and
  all six render real content — verified by temporarily linking the administrator to EMP002:
  My Payslips & Leave 5 rows, My Roster & Time Clock 34 rows, My Training & SOPs 7 rows, and the
  three ESS pages full-content, then unlinked again.
- **September's statutory totals are zero** because the run is a draft: the return is built from
  approved payslips. The page says exactly that.
- **July and August show a PAYE delta against Act 1178** if you compare them by hand — they must.
  Those months were taxed on the bands in force then, which is the legally correct answer.
- **A payroll override of the PAYE table applies to every month**, including months already locked.
  That is unavoidable with a single override row, so Payroll › Components now says so in red next to
  the schedule badges.
- **Casual and junior-overtime tax appear as separate lines** (`PAYE`, `OT_TAX`) rather than one
  blended figure, because GRA wants them reported separately.

---

## 12. What could not be exercised in this environment

- **Real filing.** No SSNIT, NPRA or GRA endpoint exists here: remittance records, DT-107A CSVs and
  the ledger postings are produced and verified, but nothing is transmitted.
- **Messaging.** `sms_driver` is `log`, so payroll and leave notifications write to `message_logs`
  rather than reaching a handset. Payroll notification is off by default (`payroll_notify = 0`).
- **Tier 3.** Voluntary and provider-led; the schema and the pre-tax treatment are there, but no
  provider file format was available to test against.
- **Biometric clocks.** The kiosk and PIN paths are exercised; no physical device is attached.
- **Collective agreements.** Where a CBA improves on Act 651 (sick pay, holiday premium, leave
  days), the settings carry it, but no CBA text was supplied for the demo hotel.
- **Gazette volatility.** Islamic holiday dates and substitute days are proclaimed each year; the
  seeded 2026/2027 calendars follow the published lists and are editable, which is the control.

---

## 13. Follow-ups (outside this audit's scope)

1. ~~**The same date-equality bug class elsewhere.**~~ **Closed by v7.44.** Every site named here
   (`LeaseBillingController:160`, `ReportController:3677`, `AssetService:311/315/444`,
   `CloseService:405`, `ClubService:388/417`) now uses `whereDate()`, and the sweep that closed them
   went far beyond HR: **103 application files, 411 insertions**, selected from the schema rather than
   from column names. Measured effect and method: `docs/DATE-STORAGE-AUDIT.md`.
2. ~~**Paternity leave.**~~ **Implemented in v7.46** — a `PL` type is now seeded (contractual, since
   Act 651 provides none), and the leave pages state plainly which types the law gives and which the
   hotel does. Detail and evidence: **§15**.
3. ~~**Bonus taxation.**~~ **Implemented in v7.45** — `BONUS` / `BONUS_TAX` components, the 5%-final
   rule up to 15% of the year's basic, the 20% non-resident rate, the casual-worker and junior-overtime
   interactions, and the GRA return columns. Rule, code map and evidence: **§14**.
4. ~~**Payroll cost budgeting.**~~ **Implemented in v7.47** — every run page now compares what the run
   charges against the approved budget for the same fiscal month and the same accounts, the approve
   confirmation says so before it is clicked, and the nightly HR task warns about a draft run that would
   break the budget. Detail and evidence: **§16**.
5. ~~**`docs/MODULES.md`.**~~ **Closed by v7.49** — the whole inventory now, not just the HR part.
   Every count, name and order in the document is the live `config('pms.menu')` registry (5 sections,
   12 groups, 53 modules, 361 pages), each group heading carries its own page count, and the four
   entries that named something the sidebar does not menu were moved to prose where they belong:
   Front Office › Desktop Sync (routed, working, unmenued), the Recreation Outlet sale receipt (a
   document per record, not a page), the whole Car Rental module (`/rentals`, `/rentals/fleet` — routed
   and permissioned, absent from the sidebar) and the version the document claimed.
   `deploy/dev/audit/modules_doc_check.py` now diffs the document against the registry and the route
   list — **210 checks, 0 discrepancies** — so the next menu change is caught rather than discovered.
   All five follow-ups from this audit are now closed.

---

## 14. Bonus / ex-gratia taxation (added after the audit, v7.45)

Follow-up 3 above. GRA taxes a bonus apart from the monthly bands, and until now a hotel could only
enter one as an ordinary taxable earning — so a festival bonus was pushed through the graduated bands
at up to 35% instead of the final 5%, and the GRA return could not show it.

### 14.1 The rule implemented

| Worker | Treatment | Where it comes from |
|---|---|---|
| Resident | A **final 5%** on the part of the bonus up to **15% of the year's basic salary** (12 × monthly basic); the **excess is added to that month's chargeable income** and taxed on the bands in force | Act 896 s. 114, GRA practice note on ex-gratia payments |
| Non-resident | The **whole bonus at a flat 20%** — not on the resident bands, and not at the 25% that applies to the rest of a non-resident's employment income | GRA PAYE guide, non-resident bonus and overtime |
| Casual (Act 651 s. 78) | No separate bonus tax: the bonus **stays inside the gross pay that carries the final 5%** | Act 651 s. 78, GRA practice note 4.12 |
| Junior employee | The bonus **counts towards the GH₵18,000 qualifying-income test** for the overtime concession (a large bonus can cost the concession), and is then taxed as a resident's bonus | GRA PAYE guide, junior-staff overtime |

The three figures are settings, not hard-coded: `payroll_bonus_cap_pct` (15), `payroll_bonus_rate` (5)
and `payroll_bonus_nonresident_rate` (20), editable under **Payroll › Components & settings** next to
the junior-overtime ones, with the statutory values as their defaults.

### 14.2 How it is entered and how it flows

* **Entry** — Payroll › a draft run › *One-off earning*: the modal now has a **Tax treatment** select.
  "Bonus / ex-gratia — GRA final tax" stores the line against the `BONUS` component with `code = 'BONUS'`
  and `taxable = false`, and takes the "Taxable earning" checkbox out of the way, because the tax on a
  bonus is computed, not chosen. An ordinary adjustment (`ADJ+`) behaves exactly as before.
* **Engine** — `PayrollService::compute()` sums the `BONUS` lines into `$bonusPaid`, keeps them **out of
  `$taxable`** (they stay in gross pay) and passes them to `incomeTax()`, which returns `bonus_tax`,
  `bonus_final`, `bonus_excess` and `bonus_cap` alongside `paye` and `ot_tax`. `compute()` then raises a
  **`BONUS_TAX`** deduction credited to PAYE payable, exactly as the `OT_TAX` line is raised.
* **Payslip** — the bonus prints as an earning, "Tax on bonus" as a deduction, and the rule line under
  *Income tax (PAYE)* says what happened, e.g. `Resident – Act 1178, from 1 Sep 2026 · bonus 4,500.00 at
  a final 5%, 1,000.00 over the 15% of annual basic cap taxed on the bands`. `payslips.paye_basis` was
  widened 120 → 255 characters for it (migration `2026_10_14_001000_hr_bonus_taxation`).
* **GRA return** — Statutory › PAYE gained **Bonus** and **Bonus tax** columns and totals, the CSV gained
  *Bonus / ex-gratia* and *Tax on bonus (final)* columns (16 in all), and the bonus tax joins the amount
  payable to GRA because it is credited to the same liability account. Allowances on the return now
  exclude the bonus so the columns add up to the gross emolument.
* **Reports** — the payroll register (`/reports/payroll_register`) gained the same two columns, and a
  bonus no longer hides inside *Allowances & other* or its tax inside *Other deductions*. The statutory
  summary report needed no change: it groups deductions by liability account, so `BONUS_TAX` already
  appears in the PAYE group and in its component list.

### 14.3 Evidence

`hr_bonus_cases.php` — **15 cases, all passing**, each expectation hand-computed from the rule rather
than read back from the code: cap arithmetic (basic 1,000 → cap 1,800; basic 12,000 → cap 21,600), a
bonus inside the cap (1,500 → **75.00**, chargeable income unmoved), a bonus over it (3,000 → **90.00**
final + 1,200 into the bands, and the resulting PAYE equals the PAYE on the same chargeable income as
salary), non-resident 5,000 → **1,000.00**, casual 4,000 + 1,000 → **250.00** with no bonus line, the
junior-overtime test won and lost by a bonus, personal reliefs still deducted before the bands, the
three settings moved and restored, and the v7.43 figures for EMP004 (937.08) and EMP012 (221.60)
unchanged when there is no bonus.

`hr_bonus_flow.py` — the same thing **through the real UI** on the October draft run. EMP001, basic
GH₵2,500.00, festival bonus **GH₵5,500.00**: cap 15% × 30,000 = **4,500.00** → final tax **225.00**,
excess **1,000.00** into chargeable income 3,037.50 → 4,037.50 → PAYE **411.16 → 613.88**, so the
payslip must read gross **8,675.00**, deductions **976.38**, net **7,698.62** — which is what it reads.
The bonus and its tax survive `POST /payroll/runs/3/recalculate`; the run summary shows `BONUS 1 slip
GH₵5,500.00` and `BONUS_TAX 1 slip GH₵225.00`; removing the line returns the payslip to 3,175.00 /
548.66 / 2,626.34 exactly.

`hr_bonus_statutory.php` — the GRA return for a run carrying a bonus (the run is locked inside a
transaction and rolled back, so the demo stays a draft): 15 employees, bonus **5,500.00**, bonus tax
**225.00**, total payable to GRA **13,144.81** = PAYE 12,919.81 + bonus tax 225.00; EMP001's row shows
gross emolument 8,675.00 = basic + allowances + overtime + bonus, chargeable 4,037.50, tax deducted
838.88 = 613.88 + 225.00.

**No regression.** The September run, which carries no bonus, recalculates to the v7.43 pesewa —
gross 77,023.47, deductions 16,892.12, net 60,131.35, PAYE **12,688.99**, OTH 408.34 — and
`hr_tax_cases.py` still reports non-resident 937.08, junior overtime tax 2.50 and the personal-relief
case unchanged. `pms:hr-run --dry` reports the October draft with the demo bonus in place
(gross 82,639.04, net 65,228.53).

---

## 15. Paternity leave (added after the audit, v7.46)

Follow-up 2 above. Act 651 gives a mother 12 weeks on full remuneration (s. 57) and gives a father
**nothing** — Ghana has no statutory paternity leave. Branded hotels and most collective agreements
offer it anyway, and until now there was no type to record it against, so a week with a new-born was
booked as casual leave (a 12-day pot meant for other things) or as leave without pay (which cost the
worker pay his own contract promised).

**What was added — one seeded row and two sentences of honest UI.** `PayrollService::ensureDefaults()`
now seeds `PL` *Paternity leave (contractual – Act 651 provides none)*, 5 working days a year, paid, no
carry-forward, and creates it on an **existing** installation the way it already did for `ML` (the gate
is now `in_array($c, ['ML', 'PL'], true)`), so an upgrade hands a hotel the type without touching the
leave types it has already configured.

**No engine work was needed, and none was done.** `LeaveService::entitlement()`, `taken()`, `carried()`
and `balances()` are generic over the type — entitlement pro-rates in the joining year, approved leave
writes the attendance register, paid leave of any type is paid by payroll in the same way, and a
gazetted public holiday falling inside the spell is not counted against the balance.

**Deliberately not restricted by gender.** `employees.gender` exists, but there is no statutory rule to
restrict against, and a hard-coded "men only" test would block an adoptive parent and a mother's
partner. Every employee has the type; the hotel sets the days it actually offers.

**The UI says which is which.** The balances tab and the *Record leave* modal both state that EL
(s. 20) and ML (s. 57) are statutory while CL, SL and PL are contractual, so nobody mistakes a 5-day PL
balance for a legal entitlement — and Masters › Leave Types is named as the place to change it.

### Evidence

`hr_paternity_flow.py` drives the whole of it and is idempotent — it cancels what an earlier pass left
before it starts. `PL` seeded as leave type 11 (5.0 days, paid, active, no carry-forward) beside CL, SL,
EL, ML and LWP.
`/hr/leave?tab=balances` grew a PL column. Recording **9–13 November 2026** for EMP003 Joseph Thomas
through the real form (`POST /hr/leave`, `approve=1`) answered *"Leave recorded and approved: Joseph
Thomas, PL 09 Nov – 13 Nov 2026"*, took his PL balance from **5 to 0**, left the other four types
untouched, and wrote the register: `/hr/attendance?date=2026-11-09` reads *On leave · PL* for him. The
request is listed at `/hr/leave?status=approved` with its 5 days and reason, and the type is editable at
`/masters/leave-types` and `/masters/leave-types/11/edit`. Cancelling the request through
`POST /hr/leave/{id}/cancel` gives the 5 days back and clears the register, and the script then puts the
demo leave back. The demo is dated in **November**, a month with no payroll run, so every October and
September figure in §6, §8 and §14 is untouched.

If Ghana ever amends Act 651 to add paternity leave, nothing structural changes: the type is already
there, and only `days_per_year` and the wording of the note do.

---

## 16. Payroll cost against the approved budget (added after the audit, v7.47)

Follow-up 4 above. A run compared against nothing: the approver saw gross, deductions and net and had
no way to know whether approving it would take the month through its salary budget — which is the one
question a budget exists to answer, and the one a hotel cannot answer after the run is locked.

### 16.1 What is compared

`PayrollService::budgetVariance(PayrollRun)` follows **`LedgerService::forPayrollRun()`**, the authority
on what payroll charges to the profit and loss, rather than assuming one "salaries" line:

| Line kind | Charged to | Sign |
|---|---|---|
| earning | its `expense_head_id` (Salaries & Wages by default) | + |
| employer contribution | its `expense_head_id` (Employer SSNIT & Pension by default) | + |
| deduction | its `liability_head_id`, **only when that is an expense account** — a fine or a recovery reduces salary cost | − |
| deduction | a liability account (SSNIT, PAYE, advances) | ignored on both sides |

So withheld SSNIT and PAYE never look like payroll cost, and the accounts compared are the ones this run
actually posts to. Against each of them the method reads two figures: the **approved budget** for that
fiscal month (`BudgetService::active(fy)` → `budget_lines.m{month}`, fiscal-month indexed, so a hotel
whose year does not start in January is handled) and what the **ledger already holds** for that month
(`BudgetService::actuals(fy)`, read to `LedgerService::cutoff()`).

The two are kept apart on purpose. Payroll is not the only thing that charges Salaries & Wages — casual
labour, staff meals and accruals land on the same head — and in the demo data October already carried
**GH₵285,000.00** of non-payroll postings against a GH₵720,000.00 budget. A card that showed only "this
run vs budget" would have said *within budget by GH₵637,360.96* while the month was in fact already
half spent. For a **draft** run the card therefore adds the run to the postings ("Month if approved");
for an **approved or paid** run the postings already include it, and the card says so instead of adding
it twice.

### 16.2 Where it appears

* **The run page** — a *Cost against budget* card between the earnings/deductions grid and the payslips
  table: one row per account charged (this run, posted this month, month if approved, budget, variance
  with its percentage), a total row, a status badge (`Within budget` / `Over budget` /
  `Account not budgeted` / `No approved budget`), a link to the budget itself, and a sentence naming the
  usual causes when it is over.
* **The approve button** — the confirmation dialog gains "This takes Oct 26 GH₵299,830.73 over the
  approved budget." before the approver clicks, not after.
* **The nightly HR task** — `pms:hr-run` reports a draft run that would break the budget, and separately
  a run charging an account the budget has no line for, which is the case that makes a variance
  meaningless.

### 16.3 Evidence

`hr_budget_variance.php` — the seeded budget is far larger than the payroll, so the warning paths never
fire in the demo; the probe lowers the October Salaries line, reads the page and the nightly task the way
a user would, and **puts the figure back byte-identically** (the budget is approved and locked, so the
only way to move it is a direct write, and it is undone in a `finally`).

| October Salaries & Wages budget | Engine | Run page | Approve dialog | `pms:hr-run --dry` |
|---|---|---|---|---|
| **GH₵720,000.00** (as seeded) | `within`, −370,169.27 (−49.6%) | badge *Within budget* | no warning | nothing |
| **GH₵50,000.00** | `over`, month to date 367,639.04 vs 50,000.00 = **+317,639.04 (+394.5%)** | badge *Over budget*, red variance | "This takes Oct 26 GH₵299,830.73 over the approved budget" | *"[NOWILLS] PAY-2026-10 would take Oct 26 GH₵299,830.73 over the approved budget (394.5%) – Salaries & Wages…"* |
| **GH₵0.00** | `unbudgeted` | badge *Account not budgeted* + an `unbudgeted` chip on the row | — | *"…charges account(s) the approved budget has no line for in Oct 26…"* |

The approve dialog and the nightly task quote the **total** variance (299,830.73, both accounts) while
the engine line quotes the single account (317,639.04 against the lowered Salaries budget) — the
Employer SSNIT line is still inside its own budget, so the totals differ by exactly that head's
under-spend.

Cross-check against the ledger, which is the real test of the cost definition: for the **paid** July run
the card's "this run" and the ledger's own postings for that fiscal month agree to the pesewa
(Salaries & Wages 71,996.46, Employer SSNIT 7,563.49), and for the **approved** August run they agree
again (74,043.46 and 7,743.41 — the latter is the run's gross less the recovery credited to salary
cost). September, a draft with no other postings, shows posted 0.00 and "month if approved" equal to the
run. `budgetVariance()` costs about 136 ms on 4,811 GL lines and the run page still renders in ~1.5 s.

---

## Appendix — reproduction

```bash
# 0. the app
deploy/dev/setup.sh && deploy/dev/recover.sh          # fresh seeded database
PMS_DEV_HOME=/channel-manager deploy/dev/bin/pms-serve # dev server on :8000 (auto-authenticated)

# 1. completeness
python3 deploy/dev/audit/hr_sweep.py                  # 36/36 = 200 -> deploy/dev/audit/hr_http.json
TINKER='require "/app/deploy/dev/audit/hr_perms.php";' deploy/dev/bin/php -f deploy/dev/tinker.php

# 2. tax engine against published GRA figures
TINKER='require "/app/deploy/dev/audit/hr_paye_engine.php";' deploy/dev/bin/php -f deploy/dev/tinker.php

# 3. every payslip of every run vs the schedule in force for that month  (§6)
TINKER='require "/app/deploy/dev/audit/hr_paye_check.php";' deploy/dev/bin/php -f deploy/dev/tinker.php

# 4. UI flows: October recompute, September run, 21 Sep holiday work     (§8, §9)
python3 deploy/dev/audit/hr_paye_flow.py

# 5. GRA's four special treatments through Masters › Employees, reverted (§7)
python3 deploy/dev/audit/hr_tax_cases.py

# 5b. paternity leave through the leave form, the balances and the register (§15)
python3 deploy/dev/audit/hr_paternity_flow.py

# 5c. payroll cost against the approved budget, including the over-budget branches (§16)
TINKER='require "/app/deploy/dev/audit/hr_budget_variance.php";' deploy/dev/bin/php -f deploy/dev/tinker.php

# 5d. bonus / ex-gratia taxation: the rule case by case, then through the UI (§14)
TINKER='require "/app/deploy/dev/audit/hr_bonus_cases.php";' deploy/dev/bin/php -f deploy/dev/tinker.php
python3 deploy/dev/audit/hr_bonus_flow.py
TINKER='require "/app/deploy/dev/audit/hr_bonus_statutory.php";' deploy/dev/bin/php -f deploy/dev/tinker.php

# 6. the nightly task                                                     (§10)
deploy/dev/bin/php artisan pms:hr-run --dry
deploy/dev/bin/php artisan pms:hr-run --hotel=NOWILLS
```

Click-paths for the preview panel: `/hr/holidays` (and `?year=2027`) · `/hr/attendance?date=2026-09-21`
· `/hr/register?month=2026-09` · `/hr/roster` (rules tab: s. 33 week, s. 41 daily rest, s. 42 weekly rest) · `/payroll` · `/payroll/runs/4`
(September, holiday note + `PH` column) · `/payroll/payslips/59` (holiday hours, chargeable income,
PAYE basis, statutory footer) · `/payroll/statutory?tab=paye&period=2026-08` ·
`/payroll/components` (schedules, holiday multiplier, junior-OT concession, payday) ·
`/masters/employees/12/edit` (employment type, tax residence, personal reliefs) · `/me/hr`.
