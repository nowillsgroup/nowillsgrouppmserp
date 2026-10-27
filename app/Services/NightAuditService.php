<?php

namespace App\Services;

use App\Models\FolioTransaction;
use App\Models\NightAudit;
use App\Models\PosOrder;
use App\Models\ReservationRoom;
use App\Models\Room;
use App\Support\Tenant;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * End-of-day process: no-shows, room-tariff posting, statistics, date roll.
 */
class NightAuditService
{
    public static function precheck(): array
    {
        $d = bdate()->toDateString();

        return [
            'pending_arrivals' => ReservationRoom::with('reservation.guest', 'roomType')->where('status', 'reserved')->whereDate('arrival_date', '<=', $d)->get(),
            'pending_departures' => ReservationRoom::with('reservation.guest', 'room')->where('status', 'checked_in')->whereDate('departure_date', '<=', $d)->get(),
            'open_pos' => PosOrder::with('outlet')->whereIn('status', ['open', 'billed'])->whereDate('business_date', '<=', $d)->get(),
            'in_house' => ReservationRoom::where('status', 'checked_in')->count(),
            // v3.4: Form C reports past their 24-hour deadline (a warning – the audit still runs)
            'formc_overdue' => FormCService::enabled() ? \App\Models\FormCRecord::with('stay.room')->where('status', 'pending')->where('deadline_at', '<=', now())->orderBy('deadline_at')->get() : collect(),
            // v3.5: front desk – open traces roll forward, urgent log items should be seen by the night manager (warnings only)
            'desk_traces' => \App\Models\Trace::with('stay.room', 'reservation')->where('status', 'open')->whereDate('trace_date', '<=', $d)->orderBy('trace_date')->get(),
            'desk_urgent' => \App\Models\LogEntry::where('status', 'open')->where('priority', 'urgent')->orderBy('id')->get(),
            // v3.9: meters not read today (warning – the next reading will simply cover two days) and lapsed critical licences
            'meters_missing' => UtilityService::cfg()['require_readings'] ? UtilityService::missing($d, true) : collect(),
            'licences_expired' => \App\Models\ComplianceItem::where('status', '!=', 'archived')->where('is_critical', true)->whereNotNull('expires_on')->whereDate('expires_on', '<', $d)->orderBy('expires_on')->get(),
        ];
    }

    public static function run(bool $markNoShows = true, bool $extendOverstays = true, ?string $notes = null): NightAudit
    {
        $audit = self::close($markNoShows, $extendOverstays, $notes);
        LedgerService::syncSafely(); // v1.6: the day just closed is posted to the general ledger
        CloseService::autoPost($audit->business_date->toDateString()); // v5.6: month end – schedules & recurring journals (never blocks)
        try {
            TreasuryService::daily(); // v5.7: post post-dated cheques now due, clear banked cheques, capture cheque receipts, stale cheques
        } catch (\Throwable $e) {
            report($e);
        }
        try {
            ConciergeService::nightly($audit->business_date->toDateString()); // v5.9: the day's confirmed activities complete (commission recognised); unconfirmed requests lapse
        } catch (\Throwable $e) {
            report($e);
        }
        try {
            RevenueService::snapshot($audit->business_date->copy()); // v1.9: on-the-books position for pickup & pace
        } catch (\Throwable $e) {
            report($e); // never blocks closing the day
        }
        try {
            PmService::generate(); // v2.3: preventive-maintenance tickets for the new business day
        } catch (\Throwable $e) {
            report($e);
        }
        try {
            BanquetSalesService::releaseExpired(); // v2.5: tentative holds past their option date release the venue
        } catch (\Throwable $e) {
            report($e);
        }
        try {
            CrmService::refreshStats();  // v2.6: stays / nights / revenue / last stay for segments
            CrmService::runJourneys();   // v2.6: automations for the new business date (idempotent – the 09:00 run skips guests already contacted)
        } catch (\Throwable $e) {
            report($e);
        }
        try {
            ChannelService::nightly(); // v2.8: horizon moved / allotments released – full ARI refresh on every channel
        } catch (\Throwable $e) {
            report($e);
        }
        try {
            ForexService::carryForward(); // v3.4: the new business day starts with yesterday's exchange rates (flagged "carried")
        } catch (\Throwable $e) {
            report($e);
        }
        try {
            ClubService::nightly(); // v5.1: freezes start/end, memberships lapse, open club visits close, auto-renewal invoices
        } catch (\Throwable $e) {
            report($e);
        }
        try {
            LeaseService::nightly(); // v5.2: rent & service-charge invoices for the coming periods, rent reviews, late fees, auto-renewals
        } catch (\Throwable $e) {
            report($e);
        }
        try {
            EsgService::nightly($audit->business_date->toDateString()); // v7.0: green-stay nights → loyalty points & savings; close green stays of departed guests
        } catch (\Throwable $e) {
            report($e);
        }
        try {
            IptvService::nightly(); // v7.1: close stale TV sessions, expire pairing codes / commands, prune the old guide and usage data
        } catch (\Throwable $e) {
            report($e);
        }
        try {
            TalentService::nightly($audit->business_date->toDateString()); // v6.9: anonymise unsuccessful applicants after the retention period
        } catch (\Throwable $e) {
            report($e);
        }
        try {
            FbCostService::nightly($audit->business_date->toDateString()); // v6.8: plate-cost snapshot of every costed dish (cost creep)
        } catch (\Throwable $e) {
            report($e);
        }
        try {
            IncomeAuditService::afterNightAudit($audit->business_date->toDateString()); // v5.3: the closed day enters income audit; authorised rates start / end
        } catch (\Throwable $e) {
            report($e);
        }
        try {
            GiftVoucherService::expireDue(); // v3.8: vouchers valid until yesterday lapse – the unused balance is breakage income
        } catch (\Throwable $e) {
            report($e);
        }
        try {
            $carried = TraceService::carryForward($audit->business_date->copy()); // v3.5: unfinished traces move to the new business day
            $d = bdate()->toDateString();
            LogBookService::system('Night audit closed for '.$audit->business_date->format('D d M Y'),
                'In house: '.ReservationRoom::where('status', 'checked_in')->count().'. Arrivals expected today: '.ReservationRoom::where('status', 'reserved')->whereDate('arrival_date', $d)->count()
                .'. Departures due today: '.ReservationRoom::where('status', 'checked_in')->whereDate('departure_date', '<=', $d)->count().'.'
                .($carried ? " {$carried} open trace(s) carried forward." : '')
                .' Wake-up calls booked before noon: '.\App\Models\WakeupCall::where('status', 'scheduled')->where('call_at', '<=', bdate()->copy()->setTime(12, 0))->count().'.',
                'handover');
        } catch (\Throwable $e) {
            report($e);
        }
        try {
            CreditService::review(); // v2.7: auto credit hold for companies overdue beyond the grace days (auto-release once cleared)
        } catch (\Throwable $e) {
            report($e);
        }
        self::sendFlash($audit->business_date);
        try {
            \App\Services\ReportScheduleService::afterAudit(); // v5.4: "after the night audit" report schedules
        } catch (\Throwable $e) {
            report($e);
        }
        try {
            \App\Services\KpiService::evaluate(\Illuminate\Support\Carbon::parse($audit->business_date)->startOfDay()); // v5.4: KPI threshold alerts for the closed day
        } catch (\Throwable $e) {
            report($e);
        }

        return $audit;
    }

    /** E-mail the flash report for the audited day. Runs after commit and never breaks the audit. */
    public static function sendFlash($date): void
    {
        $to = collect(preg_split('/[\s,;]+/', (string) setting('flash_report_emails', '')))->filter(fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL));
        if ($to->isEmpty()) {
            return;
        }
        try {
            $date = \Illuminate\Support\Carbon::parse($date);
            [, $rows] = ManagementReportService::flash($date);
            foreach ($to as $email) {
                MailService::send($email, new \App\Mail\FlashReport(Tenant::hotel(), $date, $rows), 'flash_report');
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    protected static function close(bool $markNoShows, bool $extendOverstays, ?string $notes): NightAudit
    {
        return DB::transaction(function () use ($markNoShows, $extendOverstays, $notes) {
            $hotel = Tenant::hotel()->fresh();
            $date = $hotel->business_date->copy();
            if (NightAudit::whereDate('business_date', $date->toDateString())->exists()) {
                throw new RuntimeException('Night audit already completed for '.$date->format('d M Y'));
            }
            $noShows = 0;
            if ($markNoShows) {
                $reservationIds = ReservationRoom::where('status', 'reserved')
                    ->whereDate('arrival_date', '<=', $date->toDateString())
                    ->distinct()->orderBy('reservation_id')->pluck('reservation_id');
                foreach ($reservationIds as $reservationId) {
                    // Lock the reservation before re-reading its stays. A front-desk
                    // no-show/cancellation can otherwise race the audit and cause a
                    // duplicate fee or overwrite a newer lifecycle state.
                    $res = \App\Models\Reservation::whereKey($reservationId)->lockForUpdate()->first();
                    if (! $res) {
                        continue;
                    }
                    $stays = ReservationRoom::where('reservation_id', $res->id)
                        ->where('status', 'reserved')->whereDate('arrival_date', '<=', $date->toDateString())
                        ->lockForUpdate()->get();
                    if ($stays->isEmpty()) {
                        continue;
                    }
                    // quote before the status changes (tentative bookings are never charged)
                    $quote = CancellationService::quote($res, $stays, 'noshow');
                    foreach ($stays as $s) {
                        $s->update(['status' => 'no_show']);
                        $noShows++;
                    }
                    $res->syncStatus();
                    UpsellService::closeForStays($stays->pluck('id'), 'No-show');   // v4.1
                    if ($quote['amount'] > 0 && setting('noshow_fee_auto', '1') === '1') {
                        CancellationService::post($res->fresh(), $quote['amount'], 'No-show fee');
                    }
                }
            }
            // Overstays: extend departure by one night so tariff keeps posting
            foreach (ReservationRoom::where('status', 'checked_in')->whereDate('departure_date', '<=', $date->toDateString())->get() as $s) {
                if (! $extendOverstays) {
                    throw new RuntimeException('Pending departures must be checked out first.');
                }
                $s->update(['departure_date' => $date->copy()->addDay()->toDateString()]);
            }
            // Post tonight's room tariff
            $stays = ReservationRoom::with('room', 'reservation', 'folio')->where('status', 'checked_in')->get();
            foreach ($stays as $stay) {
                StayService::postNight($stay, $date);
            }
            // v6.6 tonight's guest parking / valet goes to the room folio with the tariff
            ParkingService::postNightly($date);
            // v5.5 package entitlements whose service day has passed lapse (breakage)
            $pkg = PackageService::expireDue($date);
            if ($pkg['expired'] || $pkg['cancelled']) {
                ActivityLogger::log('packages', 'expire', "Night audit {$date->format('d M Y')}: {$pkg['expired']} package entitlement(s) expired, breakage ".money($pkg['breakage']).($pkg['cancelled'] ? ", {$pkg['cancelled']} cancelled" : ''));
            }
            // Group blocks reaching their cut-off give unpicked rooms back to inventory
            $released = BlockService::releaseDue($date);
            // Occupied rooms need daily service
            Room::where('status', 'occupied')->update(['hk_status' => 'dirty']);

            $tx = FolioTransaction::whereDate('business_date', $date->toDateString())->where('is_void', false)->get();
            $roomRev = $tx->where('type', 'charge')->whereIn('category', ['room', 'extra_bed'])->sum('amount');
            $otherRev = $tx->where('type', 'charge')->whereNotIn('category', ['room', 'extra_bed'])->sum('amount');
            $posDirect = PosOrder::whereDate('business_date', $date->toDateString())->where('status', 'settled')->where('settlement', 'payment')->get();
            $counter = \App\Services\RecreationService::counterTakings($date->toDateString()); // v7.38 desk passes + outlet takings — must be read before it is added into $otherRev
            $otherRev += $posDirect->sum(fn ($o) => $o->total - $o->tax) + $counter['revenue'];
            $taxes = $tx->where('type', 'charge')->sum('tax_amount') + $posDirect->sum('tax') + $counter['tax'];
            $collections = $tx->where('type', 'payment')->where('category', '!=', 'city_ledger')->sum('amount') + $posDirect->sum('total') + $counter['collections'];

            $totalRooms = AvailabilityService::sellableRoomCounts($date, 1)[$date->toDateString()] ?? 0;
            $occupied = $stays->count();
            $audit = NightAudit::create([
                'business_date' => $date->toDateString(),
                'rooms_total' => $totalRooms,
                'rooms_occupied' => $occupied,
                'arrivals' => ReservationRoom::whereDate('checked_in_at', '>=', $date)->whereDate('arrival_date', $date->toDateString())->count(),
                'departures' => ReservationRoom::where('status', 'checked_out')->whereDate('departure_date', $date->toDateString())->count(),
                'no_shows' => $noShows,
                'room_revenue' => $roomRev,
                'other_revenue' => $otherRev,
                'tax_collected' => $taxes,
                'collections' => $collections,
                'occupancy_pct' => $totalRooms ? round($occupied * 100 / $totalRooms, 2) : 0,
                'adr' => $occupied ? round($roomRev / $occupied, 2) : 0,
                'revpar' => $totalRooms ? round($roomRev / $totalRooms, 2) : 0,
                'run_by' => auth()->id(),
                'notes' => trim(($notes ?? '').($released ? "\n{$released} group block(s) released at cut-off." : '')) ?: null,
            ]);
            $hotel->update(['business_date' => $date->copy()->addDay()->toDateString()]);
            Tenant::set($hotel->fresh());
            ActivityLogger::log('nightaudit', 'run', 'Night audit completed for '.$date->format('d M Y'), $audit);

            return $audit;
        });
    }
}
