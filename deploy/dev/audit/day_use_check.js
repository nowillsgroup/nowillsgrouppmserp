#!/usr/bin/env node
/**
 * Day use — a stay that holds the room for a few hours, not a night.
 *
 * There is no PHP runtime in the sandbox, so this checks the wiring the feature
 * depends on, file by file: the column, the cast, the check-in that no longer
 * pushes the departure to tomorrow, the single charge, the walk-in form and the
 * places a day use has to be visible. It also parses the walk-in screen's inline
 * JavaScript, which the Blade checker does not read.
 *
 * Run: node deploy/dev/audit/day_use_check.js
 */
import { readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '../../..');
const read = (path) => readFileSync(resolve(ROOT, path), 'utf8');

let passed = 0;
const failures = [];
const ok = (name, condition, detail = '') => {
  if (condition) {
    passed += 1;
  } else {
    failures.push(`${name}${detail ? ` — ${detail}` : ''}`);
  }
};

/* ── the column ───────────────────────────────────────────────────────────── */
const migration = read('database/migrations/2026_11_06_000100_add_day_use_to_reservation_rooms.php');
ok('the migration adds the day-use flag', /->boolean\('is_day_use'\)/.test(migration));
ok('the migration records the time the room is held until', /->time\('day_use_until'\)/.test(migration));
ok('the flag defaults to an overnight stay', /boolean\('is_day_use'\)->default\(false\)/.test(migration));
ok('the migration can be rolled back', /dropColumn\(\['is_day_use', 'day_use_until'\]\)/.test(migration));

/* ── the model ────────────────────────────────────────────────────────────── */
const model = read('app/Models/ReservationRoom.php');
const walkinController = read('app/Http/Controllers/FrontDeskController.php');
ok('the flag is cast to a boolean', /'is_day_use' => 'boolean'/.test(model));
ok('the model answers whether a stay is a day use', /public function isDayUse\(\): bool/.test(model));
ok('the model renders the time the room is held until', /public function dayUseUntilLabel\(\)/.test(model));

/* ── check-in ─────────────────────────────────────────────────────────────── */
const stay = read('app/Services/StayService.php');
ok('check-in keeps a same-day departure instead of pushing it to tomorrow',
  /departure_date->lte\(bdate\(\)\) && ! \$stay->isDayUse\(\)/.test(stay));
ok('the folio line names a day-use tariff', /'Day Use Tariff' : 'Room Tariff'/.test(stay));
ok('one day-use charge is posted even though the stay has no nights',
  /Day-use = at least 1 night/.test(stay));
ok('check-out does not turn a day use into a night',
  /\$actualDeparture = \$stay->isDayUse\(\)/.test(stay));

/* ── the walk-in screen ───────────────────────────────────────────────────── */
const controller = read('app/Http/Controllers/FrontDeskController.php');
ok('the walk-in form must say which kind of stay it is', /'stay_type' => 'required\|in:overnight,day_use'/.test(controller));
ok('nights are required only for an overnight stay', /'nights' => 'required_if:stay_type,overnight/.test(controller));
ok('a day use must name how many hours it takes', /'day_use_hours' => 'required_if:stay_type,day_use\|nullable\|integer\|min:1\|max:24'/.test(controller));
ok('the record keeps the hour the room runs until', /\$data\['day_use_until'\] = now\(\)->addHours\(\(int\) \(\$data\['day_use_hours'\] \?\? 4\)\)->format\('H:i'\)/.test(controller));
ok('the confirmation names the hours, the hourly price and the hour it runs until',
  /for \{\$hours\} hour\(s\) at /.test(controller) && /->format\('H:i'\)/.test(controller) && /until \{\$data\['day_use_until'\]\}/.test(controller));
ok('a day use arrives and departs on the same business date',
  /\$dep = \$dayUse \? \$arr->copy\(\) : bdate\(\)->addDays/.test(controller));
ok('a day use still has to leave the room free for tonight',
  /\$checkedThrough = \$dayUse \? \$arr->copy\(\)->addDay\(\) : \$dep/.test(controller));
ok('the stay row carries the flag and the time', /'is_day_use' => \$dayUse, 'day_use_until' => \$dayUse \? \$data\['day_use_until'\] : null/.test(controller));
ok('the confirmation says it was a day use', /Day-use checked in to room/.test(controller));

const walkin = read('resources/views/frontdesk/walkin.blade.php');
const reservationModel = read('app/Models/Reservation.php');
const reservationShow = read('resources/views/reservations/show.blade.php');
const signatureScreen = read('resources/views/frontdesk/grc-sign.blade.php');
const reservationPrint = read('resources/views/reservations/print.blade.php');
ok('the stay-type control is named for a screen reader',
  /role="radiogroup" aria-labelledby="stayTypeLabel"/.test(walkin) && /<label id="stayTypeLabel">Stay /.test(walkin));
ok('the screen offers both stay types', /name="stay_type" value="overnight"/.test(walkin) && /name="stay_type" value="day_use"/.test(walkin));
ok('the form opens on a day use without being switched', /@checked\(old\('stay_type', 'day_use'\) === 'day_use'\)/.test(walkin)
  && /@checked\(old\('stay_type', 'day_use'\) === 'overnight'\)/.test(walkin));
ok('one tap carries both halves of the choice', /<span>Night<\/span>/.test(walkin) && /<span>Day<\/span>/.test(walkin)
  && !/walkin-staytype/.test(walkin));
ok('nothing narrates the stay type any more', !/Sold for the nights booked|Some hours today only/.test(walkin));
ok('the hour field is on screen for that default', /id="walkinDayUseField" @if\(old\('stay_type', 'day_use'\) === 'overnight'\) hidden/.test(walkin));
ok('the screen asks how many hours', /<select name="day_use_hours"/.test(walkin));
ok('the hours are a plain list of twelve', /@foreach\(\[1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12\] as \$hours\)/.test(walkin));
ok('the night count stays on the form for a day use', !/nightsField\.hidden = dayUse/.test(walkin) && !/nights\.disabled = dayUse/.test(walkin));
ok('the night count is never rendered switched off', !/id="walkinNightsField"[^>]*hidden/.test(walkin) && !/id="f_nights"[^>]*disabled/.test(walkin));
ok('the night count keeps its whole range for a day use', /id="f_nights" min="1" max="90"/.test(walkin));
ok('the night count simply stays, without a paragraph', !/walkinNightsHint/.test(walkin));
ok('a day-use submission still carries the night count', /'nights' => 'required_if:stay_type,overnight\|nullable\|integer\|min:1\|max:90'/.test(controller));
ok('a day-use reservation is known by its room lines', /public function isDayUse\(\): bool[\s\S]{0,240}every\(fn \(\$stay\) => \$stay->is_day_use\)/.test(reservationModel));
ok('the reservation summary reads day use, not a night', /@if\(\$r->isDayUse\(\)\)Day use/.test(reservationShow));
// Documents a guest reads or signs must never claim a night a day use did not sell.
ok('the signature screen chips the day use and its hour', /@if\(\$stay->is_day_use\)<span class="egrc-chip">Day use · released \{\{ substr\(\(string\) \$stay->day_use_until, 0, 5\) \}\}/.test(signatureScreen));
ok('the signature screen asks for the hour, not a departure date', /\$stay->is_day_use \? 'Room released' : 'Departure'/.test(signatureScreen));
ok('the signed length of stay is a day use, not nights', /\$stay->is_day_use \? 'Day use · charged once' : \$stay->arrival_date->diffInDays/.test(signatureScreen));
ok('the signed rate is a day-use charge', /\$stay->is_day_use \? 'Day-use charge' : 'Room rate'/.test(signatureScreen));
ok('the reservation print sheet says day use instead of a night', /@if\(\$r->isDayUse\(\)\)Day use\{\{ \$r->rooms->first\(\)\?->dayUseHoursLabel\(\)/.test(reservationPrint));
ok('the print sheet names the hours bought', /dayUseHoursLabel\(\)/.test(reservationPrint) && /· no night sold/.test(reservationPrint));
ok('the print sheet room row says day use instead of a night count', /\$stay->is_day_use \? 'Day use' : \$stay->nights/.test(reservationPrint));

ok('the nightly quote is not reused as the day-use price', /Day use selected\. No night is sold — enter the rate per hour; the guest is charged for the hours bought\./.test(walkin));
ok('a day use is priced by the hour on the walk-in desk', /const dayHourlyRate = \(\) => Math\.max\(0, Number\.parseFloat\(rate\?\.value\) \|\| 0\)/.test(walkin));
ok('the walk-in charge is hours x the hourly rate', /const dayUseCharge = \(\) => dayHourlyRate\(\) \* dayUseHoursCount\(\)/.test(walkin));
ok('the walk-in summary prices a day use by its hours', /const estimate = dayUse \? dayUseCharge\(\)/.test(walkin));
ok('the walk-in relabels the rate per hour', /dayUse \? 'Rate \/ hour ' : 'Rate \/ night '/.test(walkin));
ok('the walk-in keeps the room line charge to the hours bought', /'rate' => \$dayCharge/.test(walkinController));
ok('the walk-in keeps the hours and the hourly rate', /'day_use_hours' => \$dayUse \? \$hours : null, 'day_use_rate' => \$dayUse \? \$hourly : null/.test(walkinController));
ok('the walk-in check-in names the hourly price', /an hour \(/.test(walkinController));
ok('the confirmation names the day use and the hours',
  /isDayUse\(\) \? `a day use of \$\{dayUseHoursText\(\)\}`/.test(walkin) && /for \$\{stayWords\}\?/.test(walkin));

/* ── where a day use has to be visible ────────────────────────────────────── */
const list = read('resources/views/frontdesk/list.blade.php');
ok('the in-house roster badges a day use', /inhouse-badge-dayuse">Day use</.test(list));
ok('the in-house roster says the room is free tonight', /day use \{\{ \$s->dayUseUntilLabel\(\) \?\? 'today' \}\}, room free again tonight/.test(list));
ok('the departures list badges a day use', /departure-badge-dayuse">Day use</.test(list));
ok('the departures list counts no night for it', /Day use \{\{ \$s->dayUseUntilLabel\(\) \?\? 'today' \}\} · no night sold/.test(list));
ok('the reservation detail marks it instead of printing 1N', /Day use\{\{ \$s->dayUseHoursLabel\(\) \? ' · '\.\$s->dayUseHoursLabel\(\) : '' \}\}/.test(read('resources/views/reservations/show.blade.php')));
ok('the reservation detail names the hours sold', /\$r->rooms->first\(\)\?->dayUseHoursLabel\(\)/.test(read('resources/views/reservations/show.blade.php')));

const tape = read('resources/views/frontdesk/tapechart.blade.php');
ok('the tape chart draws a day use as its own colour', /'b-'\.\(\$stay->is_day_use \? 'day_use'/.test(tape));
ok('the tape chart legend says a day use is not a night', /Day use <em class="tape-legend-note">not a night<\/em>/.test(tape));

/* ── the booking desk can take a day use, sold by the hour ────────────────── */
const bookingController = read('app/Http/Controllers/ReservationController.php');
const hoursMigration = read('database/migrations/2026_11_06_000200_add_day_use_hours_to_reservation_rooms.php');
ok('the booking desk asks for a stay type', /'stay_type' => 'nullable\|in:overnight,day_use'/.test(bookingController));
ok('a day use demands its hours', /'day_use_hours' => 'required_if:stay_type,day_use\|nullable\|integer\|min:1\|max:12'/.test(bookingController));
ok('a day use demands its hourly rate', /'day_use_rate' => 'required_if:stay_type,day_use\|nullable\|numeric\|min:0/.test(bookingController));
ok('a day use departs the day it arrives', /A day use stays on its arrival day/.test(bookingController));
ok('a night still has to depart later', /The departure date must be after the arrival date\./.test(bookingController));
ok('the hours and the hourly rate are kept on the room line', /'day_use_hours' => \$dayUse \? \$hours : null, 'day_use_rate' => \$dayUse \? \$hourly : null/.test(bookingController));
ok('the charge is hours x the hourly rate', /'rate' => \$dayUse \? round\(\$hours \* \$hourly, 2\)/.test(bookingController));
ok('a day use leaves the room free again that night', /\$occupiedTo = \$dayUse \? Carbon::parse/.test(bookingController));
ok('the night-rate rules are not applied to a day use', /if \(! \$dayUse\) \{/.test(bookingController));
ok('the booking is looked up by hours, not nights', /->addHours\(max\(1, \$hours\)\)/.test(bookingController));
ok('an amendment keeps the stay it already has', /unset\(\$data\['stay_type'\], \$data\['day_use_hours'\], \$data\['day_use_rate'\]\)/.test(bookingController));
ok('the confirmation names the hours sold', /Day-use reservation \{\$reservation->reservation_no\} created for \{\$hours\} hour\(s\)\./.test(bookingController));

ok('the hours are stored on the room line', /->unsignedSmallInteger\('day_use_hours'\)->nullable\(\)/.test(hoursMigration));
ok('the hourly rate is stored on the room line', /->decimal\('day_use_rate', 12, 2\)->nullable\(\)/.test(hoursMigration));
ok('the hourly migration is safe to run twice', /if \(! Schema::hasColumn\('reservation_rooms', 'day_use_hours'\)\)/.test(hoursMigration));
ok('the model casts the hours', /'day_use_hours' => 'integer'/.test(model));
ok('the model casts the hourly rate', /'day_use_rate' => 'decimal:2'/.test(model));
ok('the model names the hours', /public function dayUseHoursLabel\(\): \?string/.test(model));
ok('the model names the hourly rate', /public function dayUseRateLabel\(\): \?string/.test(model));

const bookingForm = read('resources/views/reservations/form.blade.php');
ok('the form offers Night and Day use', /name="stay_type" value="overnight"[\s\S]{0,200}name="stay_type" value="day_use"/.test(bookingForm));
ok('the form keeps the day-use panel shut until Day use is chosen', /id="dayUsePanel" hidden/.test(bookingForm));
ok('the form asks for the hours as a dropdown', /name="day_use_hours" id="f_day_use_hours"/.test(bookingForm));
ok('the form asks for the rate per hour', /name="day_use_rate"/.test(bookingForm));
ok('the form relabels the rate column for a day use', /day \? 'Rate \/ hour' : 'Rate \/ night'/.test(bookingForm));
ok('the form counts hours, not nights, for a day use', /const stayUnits = \(\) => \(isDayUse\(\) \? dayHours\(\) : nights\(\)\)/.test(bookingForm));
ok('the form shows no nights for a day use', /document.getElementById\('nights'\).value = isDayUse\(\) \? 0 : nights\(\)/.test(bookingForm));
ok('the form holds the departure on the arrival day', /D.value = A.value;\n      D.readOnly = true;/.test(bookingForm));
ok('the form keeps the hours and rate out of the way when it is a night', /if \(dayUsePanel\) dayUsePanel.hidden = !day;/.test(bookingForm));

/* ── the screen's own script parses ───────────────────────────────────────── */
const scripts = [...walkin.matchAll(/<script>([\s\S]*?)<\/script>/g)].map(match => match[1]);
let parsed = 0;
let unparsed = null;
scripts.forEach((source, index) => {
  // Blade renders these to literals before the browser ever sees them.
  const js = source
    .replace(/@json\((?:[^()]|\([^()]*\))*\)/g, 'null')
    .replace(/\{\{[\s\S]*?\}\}/g, ' ' + '0')
    .replace(/@[a-zA-Z]+(?:\([^()]*\))?/g, ' ');
  if (/(^|[^@])@/.test(js)) return; // a directive the substitution did not reach: skip rather than guess
  try {
    new Function(js);
    parsed += 1;
  } catch (error) {
    unparsed = `script ${index + 1}: ${error.message}`;
  }
});
ok('the walk-in screen\'s inline JavaScript parses', parsed > 0 && unparsed === null, unparsed ?? 'no script could be read');

/* ── report ───────────────────────────────────────────────────────────────── */
if (failures.length) {
  console.log(`day use check: ${failures.length} FAILED, ${passed} passed`);
  failures.forEach(failure => console.log(`  ✗ ${failure}`));
  process.exit(1);
}
console.log(`day use check: ${passed} assertions passed (${parsed} inline script${parsed === 1 ? '' : 's'} parsed)`);
