<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v7.50 Channel bookings in a foreign currency — the audit trail of a conversion.
 *
 * An OTA sends what the guest paid in: Booking.com sends EUR, Expedia USD, a regional channel GHS. Until now a
 * booking whose currency was not the hotel currency was rejected outright, so a hotel in Accra with a USD rate plan
 * lost the booking into the channel inbox and somebody had to re-key it by hand at whatever rate they chose.
 *
 * `channel_amount` and `channel_commission` stay in the hotel currency, because that is what the reports sum
 * (ReportController channel production, ChannelService::stats) and what the folio posts. These four columns keep the
 * original, so a converted booking can always be read back the way the channel sent it:
 *
 *  channel_currency   the code the channel sent (null when it sent the hotel currency — the common case)
 *  channel_amount_fx  the total as the channel sent it, in that currency
 *  channel_fx_rate    the board rate it was converted at, on the basis the forex settings choose
 *  channel_fx_date    the date of the board that rate came from
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $t) {
            $t->string('channel_currency', 3)->nullable();
            $t->decimal('channel_amount_fx', 14, 2)->nullable();
            $t->decimal('channel_fx_rate', 14, 4)->nullable();
            $t->date('channel_fx_date')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $t) {
            $t->dropColumn(['channel_currency', 'channel_amount_fx', 'channel_fx_rate', 'channel_fx_date']);
        });
    }
};
