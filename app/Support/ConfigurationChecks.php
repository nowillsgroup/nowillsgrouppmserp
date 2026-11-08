<?php

namespace App\Support;

/**
 * Configuration checks — the contradictions a settings register can see and a screen
 * cannot.
 *
 * Every rule reads the property's *current* configuration the same way the code that
 * depends on it does, and reports only a genuine conflict: a feature switched on whose
 * dependency is off, a credential that was never pasted, a policy that can never be
 * satisfied, a tax quoted but not charged. Nothing here changes a value — a finding
 * names the module and the screen where the operator fixes it.
 *
 * Severity:
 *   blocker  something the property advertises cannot work as configured
 *   warning  two settings contradict each other; the property is exposed
 *   note     a policy choice worth seeing in daylight, not an error
 *
 * The ids are stable and are the join to the preview fixture
 * (deploy/dev/preview/configuration-sample.mjs) — deploy/dev/audit/configuration_check.py
 * proves the two lists are the same, so a rule cannot be added to one side only.
 */
class ConfigurationChecks
{
    public const SEVERITIES = ['blocker' => 'Cannot work', 'warning' => 'Contradiction', 'note' => 'Policy choice'];

    /**
     * Which module owns each rule. The register shows a module how many rules look at it,
     * and a module with none says so rather than implying it was checked — so this list has
     * to be the same list as the `$add()` calls below, and the audit proves it is two ways.
     */
    public const MODULES = [
        'vat_without_einvoicing' => 'gst',
        'einvoicing_incomplete' => 'gst',
        'inclusive_prices_unregistered' => 'gst',
        'payments_on_test_gateway' => 'payments',
        'payments_without_credentials' => 'payments',
        'portal_link_without_sms' => 'portal',
        'portal_dining_unrouted' => 'portal',
        'portal_wifi_missing' => 'portal',
        'portal_spa_without_slots' => 'portal',
        'sms_without_sender' => 'messages',
        'sms_on_the_log_driver' => 'messages',
        'sms_without_gateway_credentials' => 'messages',
        'whatsapp_on_a_driver_that_cannot_send_it' => 'messages',
        'loyalty_points_worth_nothing' => 'loyalty',
        'loyalty_earning_fallback' => 'loyalty',
        'credit_hold_no_grace' => 'credit',
        'cashier_shift_optional' => 'desk',
        'admin_2fa_optional' => 'property',
        'parking_posts_nothing' => 'parking',
        'checklist_without_inspection' => 'housekeeping',
        'stayover_without_credit' => 'housekeeping',
        'booking_engine_without_policy' => 'property',
        'spa_never_open' => 'spa',
        'qa_target_below_pass_mark' => 'qa',
        'banquet_hall_untaxed' => 'banquet',
        'sms_automations_without_sms' => 'messages',
        'no_second_approval_tier' => 'proc',
        'payables_tolerance_is_zero' => 'ap',
        'payroll_unmarked_days_are_paid' => 'payroll',
        'roster_min_rest_too_short' => 'roster',
        'locked_period_ahead_of_business_date' => 'gl',
        'self_clock_from_anywhere' => 'clock',
        'close_starts_after_today' => 'close',
        'excess_mileage_is_free' => 'rentals',
        'conversions_without_forex' => 'fx',
        'surveys_without_cooldown' => 'rep',
        'recreation_sales_without_a_shift' => 'recreation',
        'campaign_revenue_expires_the_day_it_is_sent' => 'crm',
        'listings_are_always_overdue_for_audit' => 'digital',
        'certificate_warning_arrives_on_the_last_day' => 'fs',
        'rent_cap_not_enforced' => 'leases',
        'emissions_approved_by_their_own_author' => 'esg',
        'portal_switches_on_a_closed_portal' => 'partner',
        'cro_follow_up_after_expiry' => 'cro',
        'fleet_speed_marked_parked' => 'fleet',
        'inbox_chat_under_disabled_inbox' => 'inbox',
        'tv_rental_limit_zero' => 'iptv',
        'api_http_endpoints_allowed' => 'developers',
        'api_private_endpoints_allowed' => 'developers',
        'pbx_tcp_endpoint_missing' => 'interfaces',
        'lock_tcp_endpoint_missing' => 'interfaces',
        'kiosk_walkin_without_payment' => 'kiosks',
        'organiser_pay_without_gateway' => 'organiser',
        'upsell_email_with_upsell_off' => 'upsell',
        'bankrec_same_person_finalises' => 'bankrec',
        'income_audit_four_eyes_off' => 'audit',
        'hse_permit_self_issue' => 'hse',
        'privacy_scheduled_retention_off' => 'privacy',
        'formc_no_advance_alert' => 'fg',
        'robita_no_auto_revoke' => 'robita',
        'budget_capex_no_prefix' => 'budgets',
        'voucher_min_above_max' => 'vouchers',
        'sales_stage_probabilities_unordered' => 'sales',
        'laundry_express_slower_than_normal' => 'laundry',
    ];

    /** The rules that look at one module. */
    public static function rulesFor(string $module): array
    {
        return array_keys(array_filter(self::MODULES, fn ($m) => $m === $module));
    }

    /** Modules with at least one rule — everything else is unguarded and says so. */
    public static function guardedModules(): array
    {
        return array_values(array_unique(self::MODULES));
    }

    /**
     * @return array<int, array{id:string, severity:string, module:string, title:string, detail:string, keys:array<int,string>}>
     */
    public static function run(): array
    {
        $h = hotel();
        $registered = $h === null ? null : (bool) $h->vat_registered;
        $s = fn (string $key, $default = null) => setting($key, $default);
        $on = fn (string $key) => (string) $s($key, '0') === '1';
        $blank = fn (string $key) => trim((string) $s($key, '')) === '';
        // a credential is stored as a ciphertext, so the only honest question is whether one
        // is stored *and* readable — a key pasted under another APP_KEY reads as missing
        $noSecret = fn (string $key) => \App\Services\MessageService::secret($key) === null;
        $out = [];

        $add = function (string $id, string $severity, string $module, string $title, string $detail, array $keys = []) use (&$out) {
            $out[] = compact('id', 'severity', 'module', 'title', 'detail', 'keys');
        };

        // ── VAT and e-invoicing ────────────────────────────────────────────────────
        if ($registered === true && ! $on('gst_einvoice_enabled')) {
            $add('vat_without_einvoicing', 'blocker', 'gst',
                'This property is VAT-registered but does not e-invoice',
                'E-invoicing is mandatory for every VAT-registered business with no turnover threshold. Invoices raised now are not being submitted to the GRA VSDC.',
                ['gst_einvoice_enabled']);
        }
        if ($on('gst_einvoice_enabled') && ($blank('gst_evat_url') || $blank('gst_evat_username') || $blank('gst_evat_key'))) {
            $add('einvoicing_incomplete', 'blocker', 'gst',
                'E-invoicing is switched on without the VSDC credentials',
                'The endpoint, username or API key is empty, so no invoice can be submitted. Either complete the credentials or switch e-invoicing off.',
                ['gst_evat_url', 'gst_evat_username', 'gst_evat_key']);
        }
        if ($registered === false && $on('tax_prices_inclusive')) {
            $add('inclusive_prices_unregistered', 'warning', 'gst',
                'Prices are quoted tax-inclusive at a property that is not on the VAT register',
                'Guests are shown a price that includes a tax the property does not charge — the difference is quietly absorbed rather than itemised.',
                ['tax_prices_inclusive']);
        }

        // ── online payments ───────────────────────────────────────────────────────
        if ($on('pay_enabled') && \App\Services\OnlinePaymentService::provider() === 'demo') {
            $add('payments_on_test_gateway', 'warning', 'payments',
                'Online payment is switched on, but on the built-in test gateway',
                'No real money is collected: a guest can pay a deposit or a balance and the property receives nothing. Choose the live provider and store its keys.',
                ['pay_enabled', 'pay_provider']);
        }
        if ($on('pay_enabled') && ! \App\Services\OnlinePaymentService::configured()) {
            $add('payments_without_credentials', 'blocker', 'payments',
                'Online payment is switched on but the provider has no credentials',
                'The property code treats an unconfigured provider as disabled, so no payment link is ever offered — while the setting, the booking engine and the portal all believe payment is available.',
                ['pay_enabled', 'pay_provider']);
        }

        // ── guest portal ──────────────────────────────────────────────────────────
        if ($on('portal_enabled') && $on('portal_auto_welcome') && ! $on('sms_enabled')) {
            $add('portal_link_without_sms', 'blocker', 'portal',
                'The portal link is meant to be texted to guests, but SMS is off',
                'The guest is never sent the link, so the portal, its ordering and its feedback stay invisible. Send it by another channel or switch the automatic text off.',
                ['portal_enabled', 'portal_auto_welcome', 'sms_enabled']);
        }
        if ($on('portal_enabled') && $blank('portal_dining_outlet_id')) {
            $add('portal_dining_unrouted', 'warning', 'portal',
                'The portal offers in-room dining with nowhere to send it',
                'No outlet is chosen for portal dining orders, so a guest can order and the kitchen never receives it.',
                ['portal_dining_outlet_id']);
        }
        if ($on('portal_enabled') && ($blank('portal_wifi_name') || $blank('portal_wifi_password'))) {
            $add('portal_wifi_missing', 'note', 'portal',
                'The portal does not tell guests the Wi-Fi',
                'Guests look for the network name and password on the portal before they ring the desk.',
                ['portal_wifi_name', 'portal_wifi_password']);
        }

        // ── messaging ─────────────────────────────────────────────────────────────
        // The log driver is a development stand-in: MessageService::send() records the
        // message as "sent" and never makes a request, so a property can believe it is
        // texting guests for months.
        if ($on('sms_enabled') && (string) $s('sms_driver', 'log') === 'log') {
            $add('sms_on_the_log_driver', 'blocker', 'messages',
                'SMS is switched on, but the gateway only writes to the log',
                'Nothing is sent: MessageService stores the message, marks it sent and stops there. Every confirmation, pre-arrival text and portal link is silently dropped.',
                ['sms_enabled', 'sms_driver']);
        }
        if ($on('sms_enabled') && ! $blank('sms_driver')) {
            $driver = (string) $s('sms_driver', 'log');
            $missing = match ($driver) {
                'twilio' => array_keys(array_filter(['sms_twilio_sid' => $blank('sms_twilio_sid'), 'sms_twilio_token' => $noSecret('sms_twilio_token'), 'sms_twilio_from' => $blank('sms_twilio_from')])),
                'hubtel' => array_keys(array_filter(['sms_hubtel_client_id' => $blank('sms_hubtel_client_id'), 'sms_hubtel_client_secret' => $noSecret('sms_hubtel_client_secret')])),
                'http' => $blank('sms_http_url') ? ['sms_http_url'] : [],
                default => [],
            };
            if ($missing) {
                $add('sms_without_gateway_credentials', 'blocker', 'messages',
                    'SMS is switched on but the gateway has no credentials',
                    'The driver throws before it sends, so every message is recorded as failed: the gateway is missing a value the driver requires. Paste it on the messaging screen or switch SMS off.',
                    ['sms_twilio_sid', 'sms_twilio_token', 'sms_twilio_from', 'sms_hubtel_client_id', 'sms_hubtel_client_secret', 'sms_http_url']);
            }
        }
        // WhatsApp is only carried by the Twilio and HTTP drivers; on Hubtel every
        // message on the default channel fails (MessageService::send()).
        if ($on('sms_enabled') && (string) $s('sms_default_channel', 'sms') === 'whatsapp'
            && ! in_array((string) $s('sms_driver', 'log'), ['twilio', 'http', 'log'], true)) {
            $add('whatsapp_on_a_driver_that_cannot_send_it', 'warning', 'messages',
                'WhatsApp is the default channel, but the gateway cannot carry it',
                'Hubtel sends SMS only, so a message on the default channel fails before it reaches the network.',
                ['sms_enabled', 'sms_default_channel', 'sms_driver']);
        }
        if ($on('sms_enabled') && $blank('sms_sender')) {
            $add('sms_without_sender', 'warning', 'messages',
                'SMS is on with no sender ID',
                'Some gateways reject a send without a registered sender, and an unregistered sender is filtered by the networks.',
                ['sms_sender']);
        }

        // ── loyalty ───────────────────────────────────────────────────────────────
        if ($on('loyalty_enabled') && (float) $s('loyalty_point_value', 0) <= 0) {
            $add('loyalty_points_worth_nothing', 'blocker', 'loyalty',
                'Guests earn loyalty points that are worth nothing',
                'Points accrue at settlement and can be redeemed, but a point redeems for zero — the programme promises a benefit it never pays.',
                ['loyalty_enabled', 'loyalty_point_value']);
        }
        if ($on('loyalty_enabled') && (float) $s('loyalty_spend_per_point', 0) <= 0) {
            $add('loyalty_earning_fallback', 'warning', 'loyalty',
                'The loyalty earning rate is stored as zero',
                'The programme reads zero and falls back to one unit of spend per point, so every cedi earned on a folio is handed back as a point. Set the real rate.',
                ['loyalty_spend_per_point']);
        }

        // ── credits and cash ──────────────────────────────────────────────────────
        if ($on('credit_auto_hold') && (int) $s('credit_hold_grace_days', 0) === 0) {
            $add('credit_hold_no_grace', 'warning', 'credit',
                'City ledger accounts are held the moment they fall overdue',
                'With no grace period a good payer is frozen on the first day past terms, which usually costs more in goodwill than the exposure it saves.',
                ['credit_auto_hold', 'credit_hold_grace_days']);
        }
        if (! $on('require_cashier_shift')) {
            $add('cashier_shift_optional', 'note', 'desk',
                'Money can be taken without a cashier shift being open',
                'Every payment is still recorded, but nothing ties a drawer to the person who took the money, so a variance cannot be pinned to a shift.',
                ['require_cashier_shift']);
        }
        if (! $on('require_2fa_admins')) {
            $add('admin_2fa_optional', 'note', 'property',
                'Administrator accounts are not required to use two-factor authentication',
                'An administrator can reach every module, every folio and every setting with a password alone.',
                ['require_2fa_admins']);
        }

        // ── parking ───────────────────────────────────────────────────────────────
        if ($on('parking_audit_post')
            && (float) $s('parking_hour_rate', 0) <= 0
            && (float) $s('parking_daily_max', 0) <= 0
            && (float) $s('parking_guest_rate', 0) <= 0) {
            $add('parking_posts_nothing', 'warning', 'parking',
                'Parking is posted by the night audit, but every tariff is zero',
                'The audit runs, finds a session and posts a zero charge, so the car park earns nothing and the ticket still prints a price of nothing.',
                ['parking_audit_post', 'parking_hour_rate', 'parking_daily_max', 'parking_guest_rate']);
        }

        // ── housekeeping ──────────────────────────────────────────────────────────
        // Stay-over rooms carry a workload credit; at zero the day's rooms are spread by
        // departure work alone, so the attendant who services occupied rooms looks idle.
        if ($on('hk_daily_stayover') && (int) $s('hk_credits_stayover', 1) < 1) {
            $add('stayover_without_credit', 'warning', 'housekeeping',
                'Occupied rooms are serviced daily but the work carries no credit',
                'Housekeeping spreads rooms across attendants by credit. A stay-over credit of zero means the daily service counts as nothing, and the same attendant is loaded with departures as well.',
                ['hk_daily_stayover', 'hk_credits_stayover']);
        }
        if ($on('hk_checklist_required') && ! $on('hk_require_inspection')) {
            $add('checklist_without_inspection', 'note', 'housekeeping',
                'Attendants must tick the checklist, but nobody inspects the room',
                'The checklist proves the attendant completed the steps; nothing proves the room is ready. Most properties keep one or the other, rarely both.',
                ['hk_checklist_required', 'hk_require_inspection']);
        }

        // ── spa ───────────────────────────────────────────────────────────────────
        // SpaBookingService::slots() walks from the opening time to the closing time; if
        // closing is not after opening there is not one bookable slot in the day.
        if (\App\Services\SpaBookingService::slots() === []) {
            $add('spa_never_open', 'warning', 'spa',
                'The spa has no bookable slot in the day',
                'Closing time is not after opening time, so the slot list is empty: the desk finds nothing to book and the portal offers no appointment.',
                ['spa_open', 'spa_close', 'spa_slot_min']);
        }
        if ($on('portal_enabled') && $on('portal_spa_enabled') && \App\Services\SpaBookingService::slots() === []) {
            $add('portal_spa_without_slots', 'blocker', 'portal',
                'The portal offers spa booking with nothing bookable',
                'The guest picks a service, the portal asks for a time and there are no slots to offer — the request cannot be completed anywhere in the product.',
                ['portal_enabled', 'portal_spa_enabled', 'spa_open', 'spa_close']);
        }

        // ── quality assurance ─────────────────────────────────────────────────────
        // The dashboard paints a score red under the pass mark and amber under the target,
        // so a target below the pass mark makes every "on target" score red.
        if ((float) $s('qa_target_score', 90) < (float) $s('qa_pass_mark', 85)) {
            $add('qa_target_below_pass_mark', 'warning', 'qa',
                'The quality target sits below the pass mark',
                'A score can be on target and still fail: the dashboard paints anything under the pass mark red, so the target the team is aiming at is also a failure.',
                ['qa_target_score', 'qa_pass_mark']);
        }

        // ── banquet ───────────────────────────────────────────────────────────────
        // Hall hire and cancellation fees carry banquet_hall_tax_rate. At zero on a
        // registered property the venue income goes out untaxed.
        if ($registered === true && (float) $s('banquet_hall_tax_rate', 21) <= 0) {
            $add('banquet_hall_untaxed', 'warning', 'banquet',
                'Hall hire is charged with no tax at a VAT-registered property',
                'Every banquet function and cancellation fee is posted at a zero tax rate, so venue income leaves the property untaxed while the rest of the folio is charged VAT.',
                ['banquet_hall_tax_rate']);
        }

        // ── messaging: an automation switched on under an SMS switch that is off ───
        // MessageService::notifyReservation*() gates both texts on
        // `setting('sms_enabled') === '1'`, so the automation toggle reads "on" on the
        // screen while the branch above it never runs: the guest is never texted.
        if (! $on('sms_enabled') && ($on('sms_auto_confirmation') || $on('sms_auto_thankyou'))) {
            $add('sms_automations_without_sms', 'warning', 'messages',
                'Automatic texts are switched on underneath an SMS switch that is off',
                'The confirmation and thank-you toggles read "on", but the code that sends them checks the SMS switch first and stops. Guests are never texted, and nothing on the screen says so.',
                ['sms_enabled', 'sms_auto_confirmation', 'sms_auto_thankyou']);
        }

        // ── purchasing: a second approval tier that can never be reached ──────────
        // ProcurementService::limits() returns [L1, L2]: up to L1 the store approves, up
        // to L2 purchasing.approve, above L2 purchasing.approve_high. The screen validates
        // L2 >= L1, so equality is accepted — and then no purchase order can land between
        // the tiers, which is the whole point of having two.
        [$limit1, $limit2] = \App\Services\ProcurementService::limits();
        if ($limit1 >= $limit2 && $limit2 > 0) {
            $add('no_second_approval_tier', 'warning', 'proc',
                'The second approval tier sits at or below the first, so nobody ever reaches it',
                'A purchase order up to the first limit is approved by the store; everything above it needs the high approver. The middle tier never decides anything, so the named approver is not the check the policy describes.',
                ['po_limit_1', 'po_limit_2']);
        }

        // ── payables: a tolerance of zero holds every bill that differs by a pesewa ─
        // ApService::tolerance() is max(abs, round(base × pct / 100, 2)); with both at
        // zero nothing is tolerated and every rounding difference becomes a hold.
        if ((float) $s('ap_tolerance_pct', 1) <= 0 && (float) $s('ap_tolerance_abs', 1) <= 0) {
            $add('payables_tolerance_is_zero', 'note', 'ap',
                'No difference between a bill and the goods received is tolerated',
                'A supplier bill is held for any difference at all, down to a pesewa of rounding. Most properties keep one direction a little slack to avoid holding every invoice by hand.',
                ['ap_tolerance_pct', 'ap_tolerance_abs']);
        }

        // ── payroll: an unmarked day is paid as a day worked ───────────────────────
        // PayrollService treats an unmarked day as present unless the setting says
        // 'absent' ($unmarkedPresent), so a supervisor who forgets to mark the register
        // pays for the day.
        if ((string) $s('payroll_unmarked', 'present') !== 'absent') {
            $add('payroll_unmarked_days_are_paid', 'note', 'payroll',
                'A day nobody marked is paid as a day worked',
                'When the time register is not filled in, the day is paid as present rather than held as absent. That is a policy, not an error — but it is the difference between a payroll run nobody signed and one somebody did.',
                ['payroll_unmarked']);
        }

        // ── rosters: a rest minimum the check can never enforce ────────────────────
        // RosterService warns only when the rest between two shifts falls below
        // minRest(). Set it at zero and the warning cannot fire: every roster passes.
        if (\App\Services\RosterService::minRest() < 8) {
            $add('roster_min_rest_too_short', 'warning', 'roster',
                'The minimum rest between shifts is below what the roster check calls a warning',
                'The roster only warns when the rest between two shifts falls under this number — at a few hours it never warns, and a close followed by an early start is published as fine.',
                ['roster_min_rest_hours']);
        }

        // ── the ledger: a period locked beyond the date being posted to ────────────
        // LedgerService::openDate() pushes any journal dated on or before the lock to the
        // next open day, and PayrollController refuses a posting on or before it. With the
        // lock in the future every posting today is silently moved.
        $lock = \App\Services\LedgerService::lockDate();
        if ($lock !== null && $lock > bdate()->toDateString()) {
            $add('locked_period_ahead_of_business_date', 'warning', 'gl',
                'The ledger is locked past the business date, so today cannot be posted',
                'A journal dated on or before the lock date is moved to the first open day, and payroll refuses it outright. Every posting made today lands on a future date, which the close will disagree with.',
                ['gl_lock_date']);
        }

        // ── time clock: self-service from an address nobody limited ────────────────
        // TimeClockService::ipAllowed() reads the list and returns true when it is empty
        // ("empty list = anywhere"), so the restriction the setting implies is the one
        // thing the setting cannot supply.
        if ((string) $s('timeclock_self', '1') === '1' && $blank('timeclock_self_ips')) {
            $add('self_clock_from_anywhere', 'warning', 'clock',
                'Staff can clock in from any address, not only from the property',
                'The self-service clock reads an empty address list as "anywhere", so a phone on any network can start and end a shift. That is the difference between a time record and a claim.',
                ['timeclock_self', 'timeclock_self_ips']);
        }

        // ── the period close: a start month that has not happened yet ──────────────
        // CloseService::startPeriod() takes the setting as the first month the close
        // manages, and the close lists the months from there forward. A start ahead of the
        // current period leaves the close with nothing in it.
        $closeStart = (string) $s('close_start', '');
        if (preg_match('/^\d{4}-\d{2}$/', $closeStart) && $closeStart > bdate()->format('Y-m')) {
            $add('close_starts_after_today', 'warning', 'close',
                'The period close starts at a month the ledger has not reached',
                'The close manages the months from this one forward, so every month before it is outside the close and there is nothing to close. Move the start back to the first month the ledger holds.',
                ['close_start']);
        }

        // ── rentals: a free allowance with no rate beyond it ───────────────────────
        // RentalService charges the kilometres beyond the free allowance at
        // mileagePerKm() — a multiplier of zero makes every excess kilometre free.
        if (\App\Services\RentalService::freeKmPerDay() > 0 && \App\Services\RentalService::mileagePerKm() <= 0) {
            $add('excess_mileage_is_free', 'warning', 'rentals',
                'Distance beyond the free allowance is charged at nothing',
                'The hire gives a free allowance per day and then multiplies the kilometres beyond it by the per-kilometre rate, which is zero — so a vehicle can come back a thousand kilometres over and the invoice does not move.',
                ['rental_free_km', 'rental_mileage_per_km']);
        }

        // ── foreign exchange: conversions switched on under a switch that is off ───
        // channelConvert(), apConvert() and apPay() all begin with self::enabled(); the
        // deal-rate switch is the only one that does not ask, so it is named too.
        if ((string) $s('forex_enabled', '1') !== '1'
            && ((string) $s('forex_channel_convert', '1') === '1' || (string) $s('forex_ap_convert', '1') === '1' || (string) $s('forex_ap_pay', '1') === '1')) {
            $add('conversions_without_forex', 'note', 'fx',
                'Foreign exchange is off, but its conversions are still switched on',
                'Every conversion asks whether foreign exchange itself is on before it looks at its own switch, so these read as enabled and none of them can run. The deal-rate switch is the one that does not ask.',
                ['forex_enabled', 'forex_channel_convert', 'forex_ap_convert', 'forex_ap_pay', 'forex_ap_pay_deal']);
        }

        // ── surveys: a cooldown of zero skips the check that stops repeats ─────────
        // FeedbackService only looks for a recent invitation when $days > 0, so zero
        // means every checkout is surveyed, however recently the last one was.
        if ((int) $s('fb_cooldown_days', 30) === 0) {
            $add('surveys_without_cooldown', 'warning', 'rep',
                'The same guest is surveyed on every single stay',
                'The cooldown is the only thing that stops a second invitation going out. At zero the check is skipped entirely, so a guest who stays weekly is asked weekly and the response rate falls.',
                ['fb_cooldown_days']);
        }

        // ── the recreation counter: money taken with no shift to reconcile it ──────
        // RecreationShiftService refuses a sale only when the shift is required; with it
        // off the counter takes cash into no float, and the shift close counts nothing.
        if ((string) $s('recreation_shift_required', '0') !== '1') {
            $add('recreation_sales_without_a_shift', 'warning', 'recreation',
                'Recreation takes money without an open shift, so the close reconciles nothing',
                'The counter only refuses a sale when a shift is required. With it off a cashier takes cash with no opening float, and the end-of-shift report has nothing to count the takings against.',
                ['recreation_shift_required']);
        }

        // ── campaigns: an attribution window of zero credits nothing ───────────────
        // CrmService credits a booking to a campaign when it was made inside
        // now()->subDays(attribution_days), and the campaign report says so in its own
        // note — at zero, only a booking made the same day counts.
        if ((int) $s('crm_attribution_days', 30) === 0) {
            $add('campaign_revenue_expires_the_day_it_is_sent', 'warning', 'crm',
                'A campaign is credited for one day only, so campaign revenue reads as nothing',
                'The attribution window is what decides whether a booking counts as the campaign\'s. At zero a booking only counts on the day the message went out, so every campaign shows its spend and none of its result.',
                ['crm_attribution_days']);
        }

        // ── digital marketing: a listing that is stale the moment it is audited ────
        // DigitalMarketingService calls a listing stale when it has not been audited
        // within the window; at zero the window has already closed.
        if ((int) $s('dm_audit_days', 90) === 0) {
            $add('listings_are_always_overdue_for_audit', 'warning', 'digital',
                'Every listing reads as overdue for audit the moment it is audited',
                'A listing is held stale until it is audited inside the window. At zero days the window has already passed, so the list never clears and the listing that really needs attention is lost among the rest.',
                ['dm_audit_days']);
        }

        // ── food safety: a certificate warned about only once it has lapsed ────────
        // FoodSafetyService marks a certificate 'expiring' when it runs out within
        // certWarnDays(); at zero that is the expiry date itself.
        if ((int) $s('fs_cert_warn_days', 30) === 0) {
            $add('certificate_warning_arrives_on_the_last_day', 'warning', 'fs',
                'Nothing warns before a food handler\'s certificate lapses',
                'The warning window is what puts a certificate on the list before it expires. At zero a handler is flagged only on the day their certificate runs out, by which time they should already be off food handling.',
                ['fs_cert_warn_days']);
        }

        // ── leases: the statutory cap on advance rent switched off ─────────────────
        // LeaseService refuses a residential cycle beyond the Rent Act cap only when
        // lease_enforce_rent_cap is on, and LeaseBillingService bills on the same terms.
        if ((int) $s('lease_enforce_rent_cap', 1) === 0) {
            $add('rent_cap_not_enforced', 'warning', 'leases',
                'The Rent Act cap on advance rent is not enforced',
                'Residential tenancies are billed and renewed without the statutory cap on advance rent, so a tenant can be asked for a year up front and the lease will not object. That is a decision with legal exposure, not a default.',
                ['lease_enforce_rent_cap']);
        }

        // ── ESG: the four-eyes check on the figures switched off ───────────────────
        // EsgService refuses an approval by the person who raised the initiative only
        // when four_eyes is on; with it off the author signs their own figure.
        if ((string) $s('esg_four_eyes', '1') !== '1') {
            $add('emissions_approved_by_their_own_author', 'warning', 'esg',
                'One person can approve their own emissions initiative',
                'The four-eyes check is the only thing stopping the person who proposed a figure from approving it. With it off the emissions record can be signed off by its author alone — the first thing an assurance provider asks about.',
                ['esg_four_eyes']);
        }

        // ── the partner portal: controls switched on with the door shut ────────────
        // PartnerService reads partner_pay, partner_amend and partner_cancel only after
        // the portal itself is live, so with the portal shut none of the three can act.
        if ((string) $s('partner_enabled', '1') !== '1'
            && ((string) $s('partner_pay', '1') === '1' || (string) $s('partner_amend', '1') === '1' || (string) $s('partner_cancel', '1') === '1')) {
            $add('portal_switches_on_a_closed_portal', 'note', 'partner',
                'The partner portal is closed, but its controls are still switched on',
                'Paying, amending and cancelling are read only once the portal is live, so these three read as available and none of them can be reached. Turning the portal back on will bring them with it.',
                ['partner_enabled', 'partner_pay', 'partner_amend', 'partner_cancel']);
        }

        // Default enquiry follow-up is calculated in hours; expiry uses created_at in days.
        if ((int) $s('cro_follow_up_hours', '24') > (int) $s('cro_expire_days', '14') * 24) {
            $add('cro_follow_up_after_expiry', 'warning', 'cro',
                'The default follow-up falls after the enquiry expiry window',
                'An open enquiry can lapse as no response before its default follow-up is due. Staff can choose an earlier follow-up, and the expiry job runs on its own schedule.',
                ['cro_follow_up_hours', 'cro_expire_days']);
        }

        // Fleet status and speeding use separate thresholds on the same GPS speed.
        if (max(1, (int) $s('transport_speed_moving_kph', '5')) > max(1, (int) $s('transport_speed_limit_kph', '80'))) {
            $add('fleet_speed_marked_parked', 'warning', 'fleet',
                'A speeding vehicle can still be shown as parked',
                'The moving threshold is above the speeding threshold. Speeds between them are reported as speeding but the live status can still say parked.',
                ['transport_speed_moving_kph', 'transport_speed_limit_kph']);
        }

        if ((string) $s('inbox_enabled', '1') !== '1' && (string) $s('inbox_portal_chat', '1') === '1') {
            $add('inbox_chat_under_disabled_inbox', 'note', 'inbox',
                'Portal chat is enabled under a disabled guest inbox',
                'Portal chat requires both switches. It will not be offered while the inbox is off, but will become available again when the inbox is enabled.',
                ['inbox_enabled', 'inbox_portal_chat']);
        }

        if ((string) $s('tv_enabled', '1') === '1' && (string) $s('tv_vod_enabled', '1') === '1'
            && max(0, min(20, (int) $s('tv_max_rentals', '5'))) === 0) {
            $add('tv_rental_limit_zero', 'warning', 'iptv',
                'TV movie rentals are enabled but the rental limit is zero',
                'The movie feature can be shown when active titles exist, but a new rental is refused at a zero limit. Existing active rentals can still be reopened.',
                ['tv_enabled', 'tv_vod_enabled', 'tv_max_rentals']);
        }

        // These are intentional test/on-premise options, not proof that any endpoint is unsafe.
        if ((string) $s('api_allow_http', '0') === '1') {
            $add('api_http_endpoints_allowed', 'note', 'developers',
                'Plain HTTP webhook endpoints are allowed',
                'The webhook URL and delivery checks permit unencrypted HTTP while this option is on. Review it for the property; the setting alone does not mean an HTTP endpoint exists.',
                ['api_allow_http']);
        }
        if ((string) $s('api_allow_private', '0') === '1') {
            $add('api_private_endpoints_allowed', 'note', 'developers',
                'Private network addresses are allowed for outbound integrations',
                'Webhook and channel URL checks allow private or internal addresses when this option is on. This can be needed on-premise, but broadens access to internal services; review the setting and endpoints.',
                ['api_allow_private']);
        }

        // TCP drivers reject these commands before opening a connection if host or port is missing.
        if ((string) $s('pbx_enabled', '1') === '1' && (string) $s('pbx_driver', 'demo') === 'tcp'
            && ($blank('pbx_tcp_host') || (int) $s('pbx_tcp_port', '0') < 1 || (int) $s('pbx_tcp_port', '0') > 65535)) {
            $add('pbx_tcp_endpoint_missing', 'warning', 'interfaces',
                'The TCP PABX command link has no usable host or port',
                'The TCP PABX driver rejects commands without a host and a port from 1 to 65535. Call-detail capture uses its own settings and is not assessed by this check.',
                ['pbx_enabled', 'pbx_driver', 'pbx_tcp_host', 'pbx_tcp_port']);
        }
        if ((string) $s('lock_enabled', '1') === '1' && (string) $s('lock_driver', 'demo') === 'tcp'
            && ($blank('lock_tcp_host') || (int) $s('lock_tcp_port', '0') < 1 || (int) $s('lock_tcp_port', '0') > 65535)) {
            $add('lock_tcp_endpoint_missing', 'warning', 'interfaces',
                'The TCP door-lock encoder has no usable host or port',
                'The TCP lock driver rejects encoder commands without a host and a port from 1 to 65535. A configured endpoint still needs a successful hardware test.',
                ['lock_enabled', 'lock_driver', 'lock_tcp_host', 'lock_tcp_port']);
        }

        // Kiosk walk-ins and organiser event payments use the shared payment service.
        if ((string) $s('kiosk_enabled', '1') === '1' && (string) $s('kiosk_walkin', '1') === '1'
            && ! \App\Services\OnlinePaymentService::enabled()) {
            $add('kiosk_walkin_without_payment', 'warning', 'kiosks',
                'Kiosk walk-in booking is enabled without online payments',
                'Walk-in booking is hidden when the online payment service is unavailable. Check the shared payment settings; individual kiosks must also permit walk-ins.',
                ['kiosk_enabled', 'kiosk_walkin', 'pay_enabled', 'pay_provider']);
        }
        if ((string) $s('organiser_enabled', '1') === '1' && (string) $s('organiser_pay', '1') === '1'
            && ! \App\Services\OnlinePaymentService::enabled()) {
            $add('organiser_pay_without_gateway', 'warning', 'organiser',
                'Organiser payments are enabled without an online payment service',
                'The event portal hides its online payment form when the shared payment service is unavailable. Bank transfer remains an alternative.',
                ['organiser_enabled', 'organiser_pay', 'pay_enabled', 'pay_provider']);
        }
        if ((string) $s('upsell_enabled', '1') !== '1' && (string) $s('upsell_auto_email', '1') === '1') {
            $add('upsell_email_with_upsell_off', 'note', 'upsell',
                'Automatic upsell emails are enabled while upselling is off',
                'The scheduled offer sender requires both switches. It does not send while upselling is off and will resume if the feature is enabled again.',
                ['upsell_enabled', 'upsell_auto_email']);
        }
        if ((string) $s('bankrec_segregation', '0') !== '1') {
            $add('bankrec_same_person_finalises', 'note', 'bankrec',
                'Bank statements do not require a different finaliser',
                'The statement loader is not prevented by the segregation setting from finalising their own reconciliation. Other reconciliation checks and permissions still apply.',
                ['bankrec_segregation']);
        }
        if ((int) $s('ia_four_eyes', 1) === 0) {
            $add('income_audit_four_eyes_off', 'note', 'audit',
                'Income audit four-eyes separation is switched off',
                'The same-person checks for closing a day signed by the auditor and approving a revenue request made by the approver are not enforced. Other permissions still apply.',
                ['ia_four_eyes']);
        }
        if ((string) $s('hse_issuer_differs', '1') !== '1') {
            $add('hse_permit_self_issue', 'note', 'hse',
                'A permit requester can also issue their own work permit',
                'The separate-issuer requirement is off. Issuing still requires the permit permission and the other permit checks.',
                ['hse_issuer_differs']);
        }
        if ((int) $s('retention_enabled', 1) === 0) {
            $add('privacy_scheduled_retention_off', 'note', 'privacy',
                'The scheduled privacy retention sweep is switched off',
                'The scheduled sweep skips this property even when retention periods are set. Review the manual retention process and whether automatic processing should be enabled.',
                ['retention_enabled']);
        }
        if ((string) $s('formc_enabled', '1') === '1' && max(0, (int) $s('formc_alert_hours', '6')) === 0) {
            $add('formc_no_advance_alert', 'warning', 'fg',
                'Foreign-guest reporting has no advance due-soon window',
                'A zero-hour window cannot select a pending report before its deadline for the due-soon alert. Overdue alerts remain separate and still depend on the scheduled job.',
                ['formc_enabled', 'formc_alert_hours']);
        }
        if ((string) $s('robita_auto_revoke', '1') !== '1') {
            $add('robita_no_auto_revoke', 'note', 'robita',
                'Robita local credential records are not automatically revoked on checkout',
                'Checkout and room-move hooks skip local cancellation when this switch is off. Expiry is a separate sweep. These are simulated records, not verified physical lock credentials.',
                ['robita_auto_revoke']);
        }
        if ((string) $s('budget_capex_control', 'warn') !== 'off'
            && ! preg_match('/[0-9A-Za-z]/', (string) $s('budget_capex_prefixes', '15'))) {
            $add('budget_capex_no_prefix', 'warning', 'budgets',
                'Capital budget control has no account-code prefixes to match',
                'The capital control is on, but no usable prefix is configured, so no asset account is identified as capital by this prefix rule. Operating budget control is separate.',
                ['budget_capex_control', 'budget_capex_prefixes']);
        }
        if ((string) $s('gv_enabled', '1') === '1'
            && max(0, (float) $s('gv_min_value', '500')) > max(1, (float) $s('gv_max_value', '50000'))) {
            $add('voucher_min_above_max', 'warning', 'vouchers',
                'The minimum open-amount voucher exceeds the maximum',
                'An open-amount voucher cannot meet both limits. Fixed-value vouchers have their own amount rules. Save valid limits on the voucher settings screen.',
                ['gv_enabled', 'gv_min_value', 'gv_max_value']);
        }
        $prospect = (int) $s('sales_prob_prospect', '10');
        $qualified = (int) $s('sales_prob_qualified', '25');
        $proposal = (int) $s('sales_prob_proposal', '50');
        $negotiation = (int) $s('sales_prob_negotiation', '75');
        if ($prospect >= $qualified || $qualified >= $proposal || $proposal >= $negotiation) {
            $add('sales_stage_probabilities_unordered', 'warning', 'sales',
                'Sales stage probabilities do not increase through the pipeline',
                'The sales settings screen requires each stage to have a higher probability than the one before it. Review imported or older values so weighted forecasts follow that policy.',
                ['sales_prob_prospect', 'sales_prob_qualified', 'sales_prob_proposal', 'sales_prob_negotiation']);
        }

        // Use the actual promised-hour calculation (including its minimum of one hour).
        if (\App\Services\LaundryService::hours('express') > \App\Services\LaundryService::hours('normal')) {
            $add('laundry_express_slower_than_normal', 'warning', 'laundry',
                'Express laundry is promised later than normal laundry',
                'Existing or imported turnaround values make the express promise longer than the normal promise. The prices screen permits equal times, but not express longer than normal.',
                ['laundry_express_hours', 'laundry_normal_hours']);
        }

        // ── booking engine ────────────────────────────────────────────────────────
        if ($on('booking_engine_enabled') && $blank('booking_policy')) {
            $add('booking_engine_without_policy', 'warning', 'property',
                'The booking engine sells rooms without a cancellation policy',
                'A guest books online and is shown no cancellation terms, so the property has nothing to point at when the booking is cancelled.',
                ['booking_engine_enabled', 'booking_policy']);
        }

        return $out;
    }

    /** Findings for one module of the register. */
    public static function forModule(string $module): array
    {
        return array_values(array_filter(self::run(), fn ($f) => $f['module'] === $module));
    }
}
