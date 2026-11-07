<?php

/*
 * GENERATED — do not edit.
 *
 * What a setting runs on when the code ships no built-in default. 67 of the 276
 * registered keys are read with no second argument, so the runtime hands back null and
 * the fallback — if there is one — lives at the read site: `?? 'log'`, `?: 30`, or a
 * default a page prints. Settings › Configuration prints it, so 'never set' reads as
 * 'running on null' or 'running on 30, written in SpaBookingService.php' rather than as
 * a bare '—'.
 *
 *   service   what a service, controller, model or command gets
 *   view      what a page prints
 *   null      nothing: the code sees null and each call site decides
 *
 * A credential has no entry (it is never read through `setting()`), and a family prefix
 * (`gl_cat_`, `fs_shelf_`, `fb_sla_`, `sms_tpl_`) holds whatever rows the property
 * created, so it has no single value either.
 *
 * Regenerate with: node deploy/dev/build-setting-fallbacks.mjs
 */

return [
    'fallbacks' => [
        'ap_tolerance_abs' => ['module' => 'ap', 'service' => '1', 'view' => null, 'service_file' => 'app/Services/ApService.php'],
        'ap_tolerance_pct' => ['module' => 'ap', 'service' => '1', 'view' => null, 'service_file' => 'app/Services/ApService.php'],
        'booking_policy' => ['module' => 'property', 'service' => null, 'view' => null, 'service_file' => 'app/Support/BookingConfirmationPdf.php', 'view_file' => 'resources/views/emails/reservation.blade.php'],
        'cancel_free_hours' => ['module' => 'property', 'service' => '48', 'view' => '48', 'service_file' => 'app/Services/CancellationService.php', 'view_file' => 'resources/views/partner/book.blade.php'],
        'gl_cutoff' => ['module' => 'gl', 'service' => '1900-01-01', 'view' => null, 'service_file' => 'app/Services/LedgerService.php'],
        'gl_lock_date' => ['module' => 'gl', 'service' => 'null', 'view' => null, 'service_file' => 'app/Services/LedgerService.php'],
        'gl_pos_revenue' => ['module' => 'gl', 'service' => null, 'view' => null, 'service_file' => 'app/Services/LedgerService.php'],
        'gl_retry' => ['module' => 'gl', 'service' => '[]', 'view' => null, 'service_file' => 'app/Services/LedgerService.php'],
        'gl_synced_at' => ['module' => 'gl', 'service' => null, 'view' => null, 'service_file' => 'app/Http/Controllers/GlController.php'],
        'hk_checklist' => ['module' => 'housekeeping', 'service' => null, 'view' => null, 'service_file' => 'app/Services/HousekeepingService.php'],
        'hk_credits_deep' => ['module' => 'housekeeping', 'service' => '3', 'view' => '3', 'service_file' => 'app/Services/HousekeepingService.php', 'view_file' => 'resources/views/housekeeping/tasks.blade.php'],
        'hk_credits_departure' => ['module' => 'housekeeping', 'service' => '2', 'view' => '2', 'service_file' => 'app/Services/HousekeepingService.php', 'view_file' => 'resources/views/housekeeping/tasks.blade.php'],
        'hk_credits_stayover' => ['module' => 'housekeeping', 'service' => '1', 'view' => '1', 'service_file' => 'app/Services/HousekeepingService.php', 'view_file' => 'resources/views/housekeeping/tasks.blade.php'],
        'minibar_store_id' => ['module' => 'housekeeping', 'service' => null, 'view' => null, 'service_file' => 'app/Services/HousekeepingService.php'],
        'pay_booking_deposit_pct' => ['module' => 'payments', 'service' => '30', 'view' => '30', 'service_file' => 'app/Services/OnlinePaymentService.php', 'view_file' => 'resources/views/public/booking.blade.php'],
        'pay_booking_hold_minutes' => ['module' => 'payments', 'service' => '60', 'view' => null, 'service_file' => 'app/Http/Controllers/BookingEngineController.php'],
        'pay_link_hours' => ['module' => 'payments', 'service' => '72', 'view' => '72', 'service_file' => 'app/Services/OnlinePaymentService.php', 'view_file' => 'resources/views/payments/_request-modal.blade.php'],
        'pay_razorpay_key_id' => ['module' => 'payments', 'service' => null, 'view' => null, 'service_file' => 'app/Services/OnlinePaymentService.php'],
        'payroll_advance_limit_months' => ['module' => 'payroll', 'service' => '2', 'view' => '2', 'service_file' => 'app/Http/Controllers/PayrollController.php', 'view_file' => 'resources/views/payroll/advances.blade.php'],
        'payroll_bonus_cap_pct' => ['module' => 'payroll', 'service' => null, 'view' => null, 'service_file' => 'app/Services/PayrollService.php', 'view_file' => 'resources/views/payroll/components.blade.php'],
        'payroll_bonus_nonresident_rate' => ['module' => 'payroll', 'service' => null, 'view' => null, 'service_file' => 'app/Services/PayrollService.php', 'view_file' => 'resources/views/payroll/components.blade.php'],
        'payroll_bonus_rate' => ['module' => 'payroll', 'service' => null, 'view' => null, 'service_file' => 'app/Services/PayrollService.php', 'view_file' => 'resources/views/payroll/components.blade.php'],
        'payroll_default_mode' => ['module' => 'payroll', 'service' => '0', 'view' => null, 'service_file' => 'app/Services/PayrollService.php', 'view_file' => 'resources/views/payroll/components.blade.php'],
        'payroll_ot_junior_cap' => ['module' => 'payroll', 'service' => null, 'view' => null, 'service_file' => 'app/Services/PayrollService.php', 'view_file' => 'resources/views/payroll/components.blade.php'],
        'payroll_ot_junior_excess_rate' => ['module' => 'payroll', 'service' => null, 'view' => null, 'service_file' => 'app/Services/PayrollService.php', 'view_file' => 'resources/views/payroll/components.blade.php'],
        'payroll_ot_junior_rate' => ['module' => 'payroll', 'service' => null, 'view' => null, 'service_file' => 'app/Services/PayrollService.php', 'view_file' => 'resources/views/payroll/components.blade.php'],
        'payroll_ot_junior_ratio' => ['module' => 'payroll', 'service' => null, 'view' => null, 'service_file' => 'app/Services/PayrollService.php', 'view_file' => 'resources/views/payroll/components.blade.php'],
        'payroll_ot_multiplier' => ['module' => 'payroll', 'service' => '1.5', 'view' => '1.5', 'service_file' => 'app/Services/PayrollService.php', 'view_file' => 'resources/views/payroll/components.blade.php'],
        'portal_amenities' => ['module' => 'portal', 'service' => null, 'view' => null, 'service_file' => 'app/Services/GuestPortalService.php'],
        'portal_days_after' => ['module' => 'portal', 'service' => '2', 'view' => '2', 'service_file' => 'app/Models/ReservationRoom.php', 'view_file' => 'resources/views/portal/expired.blade.php'],
        'portal_dining_outlet_id' => ['module' => 'portal', 'service' => null, 'view' => null, 'service_file' => 'app/Services/GuestPortalService.php'],
        'portal_info' => ['module' => 'portal', 'service' => null, 'view' => null, 'service_file' => 'app/Http/Controllers/GuestPortalController.php'],
        'portal_sla_minutes' => ['module' => 'portal', 'service' => '15', 'view' => '15', 'service_file' => 'app/Http/Controllers/GuestRequestController.php', 'view_file' => 'resources/views/requests/index.blade.php'],
        'rental_free_km' => ['module' => 'rentals', 'service' => '150', 'view' => null, 'service_file' => 'app/Services/RentalService.php'],
        'rental_fuel_price' => ['module' => 'rentals', 'service' => null, 'view' => null, 'service_file' => 'app/Services/RentalService.php'],
        'rental_late_per_hour' => ['module' => 'rentals', 'service' => null, 'view' => null, 'service_file' => 'app/Services/RentalService.php'],
        'rental_mileage_per_km' => ['module' => 'rentals', 'service' => null, 'view' => null, 'service_file' => 'app/Services/RentalService.php'],
        'rm_horizon_days' => ['module' => 'revenue', 'service' => '90', 'view' => null, 'service_file' => 'app/Services/RevenueService.php'],
        'rm_round_to' => ['module' => 'revenue', 'service' => '50', 'view' => '50', 'service_file' => 'app/Http/Controllers/RevenueController.php', 'view_file' => 'resources/views/revenue/pricing.blade.php'],
        'sms_driver' => ['module' => 'messages', 'service' => null, 'view' => 'log', 'service_file' => 'app/Services/MessageService.php', 'view_file' => 'resources/views/messages/index.blade.php'],
        'sms_http_url' => ['module' => 'messages', 'service' => null, 'view' => null, 'service_file' => 'app/Services/MessageService.php'],
        'sms_hubtel_client_id' => ['module' => 'messages', 'service' => null, 'view' => null, 'service_file' => 'app/Services/MessageService.php'],
        'sms_pre_arrival_days' => ['module' => 'messages', 'service' => '0', 'view' => null, 'service_file' => 'app/Console/Commands/PreArrivalMessages.php'],
        'sms_tpl_welcome' => ['module' => 'messages', 'service' => null, 'view' => null, 'service_file' => 'app/Services/GuestPortalService.php'],
        'sms_twilio_from' => ['module' => 'messages', 'service' => null, 'view' => null, 'service_file' => 'app/Services/MessageService.php'],
        'sms_twilio_sid' => ['module' => 'messages', 'service' => null, 'view' => null, 'service_file' => 'app/Services/MessageService.php'],
        'sms_twilio_wa_from' => ['module' => 'messages', 'service' => null, 'view' => null, 'service_file' => 'app/Services/MessageService.php'],
        'spa_slot_min' => ['module' => 'spa', 'service' => '30', 'view' => null, 'service_file' => 'app/Http/Controllers/SpaController.php'],
    ],
    'conflicting' => [
        // none
    ],
    'keys' => [
        'ap_tolerance_abs',
        'ap_tolerance_pct',
        'booking_policy',
        'cancel_free_hours',
        'gl_cutoff',
        'gl_lock_date',
        'gl_pos_revenue',
        'gl_retry',
        'gl_synced_at',
        'hk_checklist',
        'hk_credits_deep',
        'hk_credits_departure',
        'hk_credits_stayover',
        'minibar_store_id',
        'pay_booking_deposit_pct',
        'pay_booking_hold_minutes',
        'pay_link_hours',
        'pay_razorpay_key_id',
        'payroll_advance_limit_months',
        'payroll_bonus_cap_pct',
        'payroll_bonus_nonresident_rate',
        'payroll_bonus_rate',
        'payroll_default_mode',
        'payroll_ot_junior_cap',
        'payroll_ot_junior_excess_rate',
        'payroll_ot_junior_rate',
        'payroll_ot_junior_ratio',
        'payroll_ot_multiplier',
        'portal_amenities',
        'portal_days_after',
        'portal_dining_outlet_id',
        'portal_info',
        'portal_sla_minutes',
        'rental_free_km',
        'rental_fuel_price',
        'rental_late_per_hour',
        'rental_mileage_per_km',
        'rm_horizon_days',
        'rm_round_to',
        'sms_driver',
        'sms_http_url',
        'sms_hubtel_client_id',
        'sms_pre_arrival_days',
        'sms_tpl_welcome',
        'sms_twilio_from',
        'sms_twilio_sid',
        'sms_twilio_wa_from',
        'spa_slot_min',
    ],
];
