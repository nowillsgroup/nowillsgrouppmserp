<?php

namespace App\Http\Controllers;

use App\Models\Hotel;
use App\Models\Setting;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\BackupService;
use App\Services\HotelProvisioner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SettingController extends Controller
{
    public const KEYS = [
        'booking_engine_enabled' => ['Online booking engine enabled', 'bool'],
        'feedback_enabled' => ['Public guest feedback form enabled', 'bool'],
        'pos_auto_print_kot' => ['Auto-open the captain KOT on this till after sending (station slips go to their own printers)', 'bool'],
        'pos_kot_print_mode' => ['KOT printing', 'select', 'split', \App\Services\KotPrintService::MODES],
        'pos_kot_captain' => ['Print a captain / cashier copy of every KOT when a captain printer exists', 'bool', '1'],
        'email_auto_confirm' => ['E-mail booking confirmations / cancellations to guests automatically', 'bool', '1'],
        'food_cost_target_pct' => ['Target food-cost % (recipe costing)', 'number', '35'],
        'require_cashier_shift' => ['Cashiers must open a shift before receiving payments', 'bool', '0'],
        'require_2fa_admins' => ['Administrators must use two-factor authentication (authenticator app)', 'bool', '0'],
        'flash_report_emails' => ['E-mail the daily flash report after each night audit to (comma-separated)', 'emails'],
        'qr_menu_enabled' => ['QR table menu – guest self-ordering', 'bool', '1'],
        'loyalty_enabled' => ['Loyalty programme – guests earn points at settlement', 'bool'],
        'loyalty_spend_per_point' => ['Loyalty: net spend per 1 point', 'number', '100'],
        'loyalty_point_value' => ['Loyalty: value of 1 point when redeemed', 'number', '1'],
        'loyalty_min_redeem' => ['Loyalty: minimum points per redemption', 'number', '100'],
        'online_checkin_enabled' => ['Online pre-arrival check-in link for guests', 'bool', '1'],
        'cancel_free_hours' => ['Free cancellation until N hours before check-in time', 'number', '48'],
        'cancel_fee_type' => ['Late cancellation charge', 'select', 'none', \App\Services\CancellationService::TYPES],
        'cancel_fee_value' => ['Late cancellation: % or fixed amount', 'number', '0'],
        'noshow_fee_type' => ['No-show charge', 'select', 'first_night', \App\Services\CancellationService::TYPES],
        'noshow_fee_value' => ['No-show: % or fixed amount', 'number', '0'],
        'noshow_fee_auto' => ['Night audit posts the no-show charge automatically', 'bool', '1'],
        'hk_require_inspection' => ['Housekeeping: cleaned rooms wait for supervisor inspection', 'bool', '1'],
        'hk_checklist_required' => ['Housekeeping: attendants must tick every checklist item', 'bool', '1'],
        'hk_daily_stayover' => ['Housekeeping: daily service for occupied rooms in auto-assign', 'bool', '1'],
        'hk_credits_departure' => ['HK credits: departure / vacant-dirty room', 'number', '2'],
        'hk_credits_stayover' => ['HK credits: stay-over room', 'number', '1'],
        'hk_credits_deep' => ['HK credits: deep clean', 'number', '3'],
        'minibar_store_id' => ['Minibar stock is deducted from store', 'store'],
        'spa_open' => ['Spa / activities opening time', 'time', '09:00'],
        'spa_close' => ['Spa / activities closing time', 'time', '21:00'],
        'spa_slot_min' => ['Spa calendar slot (minutes)', 'number', '30'],
        'portal_enabled' => ['Guest mobile portal – private stay link for in-house guests', 'bool', '1'],
        'portal_auto_welcome' => ['Guest portal: text the link to the guest at check-in (needs SMS enabled)', 'bool', '0'],
        'portal_spa_enabled' => ['Guest portal: guests can book spa & activities', 'bool', '1'],
        'portal_dining_outlet_id' => ['Guest portal: in-room dining orders go to outlet', 'outlet'],
        'portal_sla_minutes' => ['Guest requests: flag as overdue after (minutes unanswered)', 'number', '15'],
        'portal_days_after' => ['Guest portal: bill & feedback stay available N days after check-out', 'number', '2'],
        'portal_wifi_name' => ['Guest portal: Wi-Fi network name', 'text'],
        'portal_wifi_password' => ['Guest portal: Wi-Fi password', 'text'],
        'ap_tolerance_pct' => ['Payables: hold supplier bills whose total differs from goods received by more than (%)', 'number', '1'],
        'ap_tolerance_abs' => ['Payables: … but always allow a difference up to (amount)', 'number', '1'],
        'rm_mode' => ['Revenue management: pricing mode', 'select', 'off', \App\Services\RevenueService::MODES],
        'rm_round_to' => ['Revenue management: round recommended rates to the nearest', 'number', '50'],
        'rm_horizon_days' => ['Revenue management: forecast & snapshot horizon (days, 14–365)', 'number', '90'],
        'payroll_segregation' => ['Payroll: a run must be approved by someone other than the person who prepared it', 'bool', '1'],
        'ap_segregation' => ['Payables: a held bill must be approved by someone other than the person who entered it', 'bool', '1'],
        'hk_checklist' => ['Room-attendant checklist (one item per line)', 'textarea', \App\Services\HousekeepingService::DEFAULT_CHECKLIST],
        'portal_amenities' => ['Guest portal: amenities guests can request (one per line; blank = defaults)', 'textarea'],
        'portal_info' => ['Guest portal: hotel information (one per line, "Label: value" shows as a row)', 'textarea', "Breakfast: 06:30 – 10:30, Garden Restaurant\nPool: 07:00 – 20:00\nFront desk: dial 9\nRoom service: dial 5"],
        'grc_terms' => ['Registration card (GRC) terms', 'textarea'],
        'invoice_terms' => ['Invoice terms & conditions', 'textarea'],
        'booking_policy' => ['Booking engine cancellation policy', 'textarea'],
    ];

    public function index(Request $request)
    {
        $hotel = hotel();
        $user = $request->user();

        return view('settings.index', [
            'hotel' => $hotel, 'keys' => self::KEYS, 'values' => Setting::pluck('value', 'key'),
            'properties' => $user->is_group_admin && $user->hotel_group_id ? Hotel::where('hotel_group_id', $user->hotel_group_id)->orderBy('name')->get() : collect(),
            'timezones' => \DateTimeZone::listIdentifiers(),
            'stores' => \App\Models\Store::orderBy('name')->pluck('name', 'id'),
            'outlets' => \App\Models\Outlet::where('is_active', true)->orderBy('name')->pluck('name', 'id'),
        ]);
    }

    public function update(Request $request)
    {
        $hotel = hotel();
        $data = $request->validate([
            'name' => 'required|string|max:255', 'legal_name' => 'nullable|string|max:255', 'property_type' => 'required|in:hotel,resort,guest_house,restaurant,cafe,service_apartment',
            'address' => 'nullable|string|max:255', 'city' => 'nullable|string|max:255', 'state' => 'nullable|string|max:255', 'country' => 'nullable|string|max:255', 'pincode' => 'nullable|string|max:20',
            'phone' => 'nullable|string|max:30', 'email' => 'nullable|email|max:255', 'website' => 'nullable|url|max:255', 'tax_number' => 'nullable|string|max:40|tin',
            'currency' => 'required|string|max:10', 'currency_symbol' => 'required|string|max:5', 'timezone' => 'required|timezone',
            'checkin_time' => 'required|date_format:H:i', 'checkout_time' => 'required|date_format:H:i', 'invoice_footer' => 'nullable|string|max:1000',
            'logo' => 'nullable|image|mimes:png,jpg,jpeg,webp|max:512', 'remove_logo' => 'nullable|boolean',
            'settings' => 'array', 'settings.*' => 'nullable|string|max:5000',
        ]);
        if ($request->hasFile('logo')) {
            $dir = public_path('uploads/logos');
            is_dir($dir) || mkdir($dir, 0755, true);
            $name = 'hotel'.$hotel->id.'_'.time().'.'.$request->file('logo')->extension();
            $request->file('logo')->move($dir, $name);
            $data['logo_path'] = 'uploads/logos/'.$name;
        } elseif ($request->boolean('remove_logo')) {
            $data['logo_path'] = null;
        }
        unset($data['logo'], $data['remove_logo']);
        $settings = $data['settings'] ?? [];
        unset($data['settings']);
        $hotel->update($data);
        foreach (self::KEYS as $key => [$label, $type]) {
            if ($type === 'select' && ! array_key_exists($settings[$key] ?? '', self::KEYS[$key][3])) {
                $settings[$key] = self::KEYS[$key][2];
            }
            if ($type === 'number' && isset($settings[$key]) && $settings[$key] !== '' && (! is_numeric($settings[$key]) || $settings[$key] < 0)) {
                return back()->withInput()->withErrors(["settings.$key" => "$label must be a positive number."]);
            }
            if ($type === 'emails' && ! empty($settings[$key])) {
                $list = collect(preg_split('/[\s,;]+/', $settings[$key]))->filter()->unique();
                if ($list->count() > 10 || $list->contains(fn ($e) => ! filter_var($e, FILTER_VALIDATE_EMAIL))) {
                    return back()->withInput()->withErrors(["settings.$key" => "$label: enter up to 10 valid e-mail addresses."]);
                }
                $settings[$key] = $list->implode(', ');
            }
            if ($type === 'time' && ! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $settings[$key] ?? '')) {
                $settings[$key] = self::KEYS[$key][2];
            }
            if ($type === 'store' && ! empty($settings[$key]) && ! \App\Models\Store::whereKey($settings[$key])->exists()) {
                $settings[$key] = null;
            }
            if ($type === 'outlet' && ! empty($settings[$key]) && ! \App\Models\Outlet::whereKey($settings[$key])->exists()) {
                $settings[$key] = null;
            }
            if ($type === 'text' && isset($settings[$key])) {
                $settings[$key] = mb_substr(trim($settings[$key]), 0, 120);
            }
            $val = $type === 'bool' ? (! empty($settings[$key]) ? '1' : '0') : ($settings[$key] ?? null);
            Setting::updateOrCreate(['key' => $key], ['value' => $val]);
        }
        ActivityLogger::log('settings', 'update', 'Updated hotel settings');

        return back()->with('success', 'Settings saved.');
    }

    /** Group admins can add a new property to their group. */
    public function storeHotel(Request $request)
    {
        $user = $request->user();
        abort_unless($user->is_group_admin && $user->hotel_group_id, 403);
        $data = $request->validate([
            'code' => 'required|alpha_dash|max:30|unique:hotels,code', 'name' => 'required|string|max:255', 'property_type' => 'required|in:hotel,resort,guest_house,restaurant,cafe,service_apartment',
            'city' => 'nullable|string|max:255', 'admin_username' => 'required|alpha_dash|max:60', 'admin_password' => 'required|string|min:8',
        ]);
        $current = hotel();
        $hotel = DB::transaction(function () use ($data, $user, $current) {
            $hotel = Hotel::create(['hotel_group_id' => $user->hotel_group_id, 'code' => strtoupper($data['code']), 'name' => $data['name'], 'property_type' => $data['property_type'], 'city' => $data['city'] ?? null,
                'country' => $current->country, 'currency' => $current->currency, 'currency_symbol' => $current->currency_symbol, 'timezone' => $current->timezone, 'business_date' => now()->toDateString()]);
            HotelProvisioner::provision($hotel);
            $admin = new User(['name' => 'Administrator', 'username' => $data['admin_username'], 'password' => $data['admin_password'], 'is_active' => true]);
            $admin->hotel_id = $hotel->id;
            $admin->is_super_admin = true;
            $admin->save();

            return $hotel;
        });
        ActivityLogger::log('settings', 'add_property', "Added property {$hotel->name} ({$hotel->code})", $hotel);

        return back()->with('success', "Property {$hotel->name} created. Use Switch Hotel to open it.");
    }

    public function backup(Request $request)
    {
        $files = collect(glob(BackupService::dir().'/*.sql'))->filter(fn ($f) => $this->canSee($request, basename($f)))
            ->map(fn ($f) => ['name' => basename($f), 'size' => filesize($f), 'time' => filemtime($f)])->sortByDesc('time')->values();

        return view('settings.backup', ['files' => $files, 'full' => $this->fullAllowed($request)]);
    }

    protected function fullAllowed(Request $request): bool
    {
        return $request->user()->is_group_admin || Hotel::count() === 1;
    }

    protected function canSee(Request $request, string $name): bool
    {
        return str_starts_with($name, 'hotel'.hotel()->id.'_') || (str_starts_with($name, 'full_') && $this->fullAllowed($request));
    }

    public function download(Request $request)
    {
        $data = $request->validate(['type' => 'nullable|in:full,hotel', 'file' => ['nullable', 'string', 'regex:/^[A-Za-z0-9_\-]+\.sql$/']]);
        if (! empty($data['file'])) {
            $path = BackupService::dir().'/'.$data['file'];
            abort_unless(is_file($path) && $this->canSee($request, $data['file']), 404);
        } else {
            $full = ($data['type'] ?? 'hotel') === 'full';
            abort_if($full && ! $this->fullAllowed($request), 403);
            @set_time_limit(300);
            $path = $full ? BackupService::full() : BackupService::hotel(hotel()->id);
            ActivityLogger::log('settings', 'backup', 'Created '.($full ? 'full database backup' : 'property data export').' '.basename($path));
        }

        return response()->download($path);
    }
}
