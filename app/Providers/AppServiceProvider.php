<?php

namespace App\Providers;

use App\Models;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Paginator::defaultView('partials.pagination');

        // Local previews may use SQLite when MySQL is unavailable. Register the small set of
        // MySQL date/order helpers used by the reporting screens so the demo remains navigable.
        // The connection can be recreated by migrate:fresh (and by some long-running workers), so
        // listen for every newly established SQLite connection rather than registering only once.
        $registerSqliteFunctions = static function ($connection): void {
            if ($connection->getDriverName() !== 'sqlite') {
                return;
            }
            $pdo = $connection->getPdo();
            if (! method_exists($pdo, 'sqliteCreateFunction')) {
                return;
            }
            $pdo->sqliteCreateFunction('FIELD', fn ($value, ...$values) => (($i = array_search($value, $values, true)) === false ? 0 : $i + 1), -1);
            $pdo->sqliteCreateFunction('DATEDIFF', fn ($to, $from) => (int) floor((strtotime((string) $to) - strtotime((string) $from)) / 86400), 2);
            $pdo->sqliteCreateFunction('YEAR', fn ($date) => (int) date('Y', strtotime((string) $date)), 1);
            $pdo->sqliteCreateFunction('MONTH', fn ($date) => (int) date('n', strtotime((string) $date)), 1);
            $pdo->sqliteCreateFunction('LEAST', fn (...$values) => min($values), -1);
            $pdo->sqliteCreateFunction('FIND_IN_SET', fn ($needle, $haystack) => (($i = array_search((string) $needle, array_map('trim', explode(',', (string) $haystack)), true)) === false ? 0 : $i + 1), 2);
        };
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Database\Events\ConnectionEstablished::class, function ($event) use ($registerSqliteFunctions): void {
            $registerSqliteFunctions($event->connection);
        });
        if (config('database.default') === 'sqlite') {
            $registerSqliteFunctions(\Illuminate\Support\Facades\DB::connection());
        }

        // v4.0 webhooks: business events → outbox (after commit)
        foreach ([Models\Reservation::class, Models\ReservationRoom::class, Models\Room::class, Models\FolioTransaction::class, Models\NightAudit::class] as $m) {
            $m::observe(\App\Observers\WebhookObserver::class);
        }

        // v1.7 guest portal rate limits – keyed per stay token (guests share the hotel Wi-Fi's public IP), with a per-IP ceiling
        \Illuminate\Support\Facades\RateLimiter::for('portal', fn ($r) => [
            \Illuminate\Cache\RateLimiting\Limit::perMinute(90)->by('portal:'.$r->route('token')),
            \Illuminate\Cache\RateLimiting\Limit::perMinute(1200)->by('portal-ip:'.$r->ip()),
        ]);
        \Illuminate\Support\Facades\RateLimiter::for('portal-write', fn ($r) => [
            \Illuminate\Cache\RateLimiting\Limit::perMinute(15)->by('portal-w:'.$r->route('token')),
            \Illuminate\Cache\RateLimiting\Limit::perMinute(300)->by('portal-w-ip:'.$r->ip()),
        ]);

        // v7.1 in-room TVs – keyed per device token (every TV shares the hotel's public IP); a TV polls every 10–120 s
        \Illuminate\Support\Facades\RateLimiter::for('tv', fn ($r) => [
            \Illuminate\Cache\RateLimiting\Limit::perMinute(120)->by('tv:'.$r->route('token')),
            \Illuminate\Cache\RateLimiting\Limit::perMinute(6000)->by('tv-ip:'.$r->ip()),
        ]);
        \Illuminate\Support\Facades\RateLimiter::for('tv-write', fn ($r) => [
            \Illuminate\Cache\RateLimiting\Limit::perMinute(30)->by('tv-w:'.$r->route('token')),
            \Illuminate\Cache\RateLimiting\Limit::perMinute(1500)->by('tv-w-ip:'.$r->ip()),
        ]);
        \Illuminate\Support\Facades\RateLimiter::for('tv-pair', fn ($r) => \Illuminate\Cache\RateLimiting\Limit::perMinute(6)->by('tv-pair:'.$r->ip()));
        // v7.2 lobby kiosks – keyed per kiosk token; the pay-status poll runs every 3 s while a guest pays
        \Illuminate\Support\Facades\RateLimiter::for('kiosk', fn ($r) => [
            \Illuminate\Cache\RateLimiting\Limit::perMinute(90)->by('kiosk:'.$r->route('token')),
            \Illuminate\Cache\RateLimiting\Limit::perMinute(3000)->by('kiosk-ip:'.$r->ip()),
        ]);
        \Illuminate\Support\Facades\RateLimiter::for('kiosk-write', fn ($r) => [
            \Illuminate\Cache\RateLimiting\Limit::perMinute(40)->by('kiosk-w:'.$r->route('token')),
            \Illuminate\Cache\RateLimiting\Limit::perMinute(1500)->by('kiosk-w-ip:'.$r->ip()),
        ]);
        // v7.3 guest portal chat polls every few seconds while the chat is open
        \Illuminate\Support\Facades\RateLimiter::for('portal-chat', fn ($r) => [
            \Illuminate\Cache\RateLimiting\Limit::perMinute(40)->by('pchat:'.$r->route('token')),
            \Illuminate\Cache\RateLimiting\Limit::perMinute(2000)->by('pchat-ip:'.$r->ip()),
        ]);
        \Illuminate\Support\Facades\RateLimiter::for('kiosk-pair', fn ($r) => \Illuminate\Cache\RateLimiting\Limit::perMinute(6)->by('kiosk-pair:'.$r->ip()));

        // v1.7 guest portal: a finished maintenance ticket / housekeeping task completes the guest's linked request.
        \App\Models\MaintenanceRequest::updated(function ($t) {
            if ($t->wasChanged('status') && in_array($t->status, ['resolved', 'closed'])) {
                \App\Services\GuestPortalService::syncFromLinked('MaintenanceRequest', $t->id);
            }
        });
        \App\Models\HousekeepingTask::updated(function ($t) {
            if ($t->wasChanged('status') && $t->status === 'completed') {
                \App\Services\GuestPortalService::syncFromLinked('HousekeepingTask', $t->id);
            }
        });

        // v2.8 channel manager: queue ARI changes (bookings, rates, blocks, allotments, restrictions) for the next push
        \App\Services\ChannelService::registerObservers();

        // Tenant-aware "exists": owned:table[,column] — the referenced row must belong to the current hotel.
        \Illuminate\Support\Facades\Validator::extend('owned', function ($attribute, $value, $params) {
            if ($value === null || $value === '') {
                return true;
            }

            return \Illuminate\Support\Facades\DB::table($params[0])->where($params[1] ?? 'id', $value)
                ->where('hotel_id', \App\Support\Tenant::id())->exists();
        }, 'The selected :attribute is invalid.');

        // v4.3: Ghana TIN – GRA TIN (P/C/G/Q/V + 10 digits) or Ghana Card PIN (only for properties in Ghana; elsewhere any tax number is accepted)
        \Illuminate\Support\Facades\Validator::extend('tin', function ($attribute, $value) {
            return $value === null || trim((string) $value) === '' || ! \App\Services\GstService::isGhana() || \App\Services\GstService::validTin($value);
        }, 'The :attribute is not a valid Ghana TIN (e.g. C0012345678, or a Ghana Card PIN such as GHA-123456789-0).');

        $bindings = [
            'stay' => Models\ReservationRoom::class, 'tx' => Models\FolioTransaction::class,
            'transfer' => Models\TreasuryTransfer::class, 'cheque' => Models\ChequeReceived::class, 'book' => Models\ChequeBook::class, // v5.7
            'issued' => Models\ChequeIssued::class, 'fline' => Models\CashForecastLine::class, 'taccount' => Models\TreasuryAccount::class,
            'capex' => Models\CapexProject::class, 'ofee' => Models\OwnerFee::class, 'ostmt' => Models\OwnerStatement::class, 'oquery' => Models\OwnerQuery::class, 'ffe' => Models\FfeEntry::class, // v5.8
            'ticket' => Models\MaintenanceRequest::class, 'item' => Models\LostFoundItem::class,
            'line' => Models\PosOrderItem::class, 'po' => Models\PurchaseOrder::class,
            'audit' => Models\NightAudit::class, 'event' => Models\BanquetEvent::class,
            'task' => Models\HousekeepingTask::class, 'order' => Models\PosOrder::class,
            'appt' => Models\SpaAppointment::class, 'greq' => Models\GuestRequest::class,
            'bill' => Models\SupplierInvoice::class, 'spay' => Models\SupplierPayment::class, 'alloc' => Models\ApAllocation::class,
            'yrule' => Models\YieldRule::class,
            'prun' => Models\PayrollRun::class, 'slip' => Models\Payslip::class, 'pline' => Models\PayslipLine::class,
            'pcomp' => Models\PayComponent::class, 'sadv' => Models\StaffAdvance::class, 'srem' => Models\StatutoryRemittance::class, 'bstmt' => Models\BankStatement::class, 'bline' => Models\BankStatementLine::class, 'pfloat' => Models\PettyFloat::class, 'ptxn' => Models\PettyTxn::class, 'prepl' => Models\PettyReplenishment::class, 'pcount' => Models\PettyCount::class, 'eclaim' => Models\ExpenseClaim::class, 'ecline' => Models\ExpenseClaimLine::class, 'lreq' => Models\LeaveRequest::class,
            'olp' => Models\OnlinePayment::class, 'gpo' => Models\GatewayPayout::class,
            'lorder' => Models\LaundryOrder::class, 'lbatch' => Models\LinenBatch::class, 'ltype' => Models\LinenType::class, 'litem' => Models\LaundryItem::class,
            'asset' => Models\Asset::class, 'acat' => Models\AssetCategory::class, 'deprun' => Models\AssetDepRun::class, 'pmsched' => Models\PmSchedule::class, 'req' => Models\Requisition::class, 'sissue' => Models\StoreIssue::class, 'rdept' => Models\Department::class,
            'chconn' => Models\ChannelConnection::class, 'chmsg' => Models\ChannelMessage::class, 'chrestr' => Models\ChannelRestriction::class, 'preq' => Models\PrivacyRequest::class, 'breach' => Models\DataBreach::class,
            'feedback' => Models\GuestFeedback::class, 'review' => Models\OnlineReview::class, 'survey' => Models\Survey::class, 'invitation' => Models\SurveyInvitation::class,
            'fcase' => Models\FeedbackCase::class, 'rtpl' => Models\ReviewTemplate::class,
            'tres' => Models\TableReservation::class, 'ptable' => Models\PosTable::class,
            'trip' => Models\TransportTrip::class, 'vehicle' => Models\Vehicle::class, 'vexp' => Models\VehicleExpense::class,
            'formc' => Models\FormCRecord::class, 'fx' => Models\ForexExchange::class, 'surrender' => Models\ForexSurrender::class,
            'budget' => Models\Budget::class, 'bcomment' => Models\BudgetComment::class, 'logentry' => Models\LogEntry::class, 'gmsg' => Models\GuestMessage::class, 'wakeup' => Models\WakeupCall::class, 'trace' => Models\Trace::class, 'lreport' => Models\LostReport::class,
            // v3.7 interfaces
            'call' => Models\CallRecord::class, 'ext' => Models\PhoneExtension::class, 'tariff' => Models\CallTariff::class, 'card' => Models\KeyCard::class,
            'gvoucher' => Models\GiftVoucher::class, 'gvtype' => Models\GiftVoucherType::class, // v3.8
            'umeter' => Models\UtilityMeter::class, 'mreading' => Models\MeterReading::class, 'ubill' => Models\UtilityBill::class, // v3.9
            'citem' => Models\ComplianceItem::class, 'cevent' => Models\ComplianceEvent::class, // v3.9
            'apiclient' => Models\ApiClient::class, 'webhook' => Models\WebhookEndpoint::class, 'delivery' => Models\WebhookDelivery::class, // v4.0
            'uoffer' => Models\UpsellOffer::class, 'uorder' => Models\UpsellOrder::class, // v4.1
            'visitor' => Models\Visitor::class, 'watch' => Models\SecurityWatch::class, 'gatepass' => Models\GatePass::class, 'incident' => Models\SecurityIncident::class, // v4.2
            'mkey' => Models\MasterKey::class, 'loan' => Models\KeyLoan::class, 'icmd' => Models\InterfaceCommand::class,
            'rfq' => Models\Rfq::class, 'invite' => Models\RfqSupplier::class, 'sprice' => Models\SupplierPrice::class, 'grn' => Models\GoodsReceipt::class, // v4.8
            'stpl' => Models\ShiftTemplate::class, 'swap' => Models\ShiftSwap::class, 'punch' => Models\TimePunch::class, 'tip' => Models\TipCollection::class, 'scdist' => Models\ScDistribution::class, 'scline' => Models\ScDistributionLine::class, // v4.9
            'fslog' => Models\FsTempLog::class, 'fsequip' => Models\FsEquipment::class, 'fslist' => Models\FsChecklist::class, 'fsrun' => Models\FsChecklistRun::class, 'fswaste' => Models\FsWaste::class, 'fslabel' => Models\FsLabel::class, 'fscert' => Models\FsCertificate::class, // v5.0
            'clubplan' => Models\ClubPlan::class, 'membership' => Models\ClubMembership::class, 'clubmember' => Models\ClubMember::class, 'clubcharge' => Models\ClubCharge::class, 'clubpay' => Models\ClubPayment::class, 'clubvisit' => Models\ClubVisit::class, 'daypass' => Models\ClubDayPass::class, 'clubclass' => Models\ClubClass::class, 'clubbooking' => Models\ClubBooking::class, // v5.1
            'lease' => Models\Lease::class, 'leaseunit' => Models\LeaseUnit::class, 'leasecharge' => Models\LeaseCharge::class, 'leasepay' => Models\LeasePayment::class, 'leaseinspection' => Models\LeaseInspection::class, // v5.2
            'auditday' => Models\AuditDay::class, 'auditfinding' => Models\AuditFinding::class, 'revauth' => Models\RevenueAuthorisation::class, 'roomcount' => Models\RoomCount::class, // v5.3
            'rview' => Models\ReportView::class, 'rschedule' => Models\ReportSchedule::class, 'rdelivery' => Models\ReportDelivery::class, 'kpialert' => Models\KpiAlert::class, 'kpievent' => Models\KpiAlertEvent::class, // v5.4
            'pkgitem' => Models\Package::class, 'pkgredeem' => Models\PackageRedemption::class, // v5.5
            'cvendor' => Models\ActivityVendor::class, 'cactivity' => Models\Activity::class, 'cdeparture' => Models\ActivityDeparture::class, 'cbooking' => Models\ActivityBooking::class, 'csettle' => Models\ActivitySettlement::class, 'cplace' => Models\ConciergePlace::class, // v5.9
            'saccount' => Models\Company::class, 'scontact' => Models\SalesContact::class, 'opp' => Models\SalesOpportunity::class, 'sact' => Models\SalesActivity::class, 'mevent' => Models\MarketingEvent::class, 'mcollateral' => Models\MarketingCollateral::class, // v6.1
            'dmchannel' => Models\DmChannel::class, 'dmpost' => Models\DmPost::class, 'dmcampaign' => Models\DmCampaign::class, 'dmadstat' => Models\DmAdStat::class, 'dmlink' => Models\DmLink::class, 'dminfluencer' => Models\DmInfluencer::class, // v6.2
            'bdcompetitor' => Models\BdCompetitor::class, 'bdshop' => Models\BdRateShop::class, 'bdpartner' => Models\BdPartner::class, 'bdinitiative' => Models\BdInitiative::class, 'bdmilestone' => Models\BdMilestone::class, // v6.3
            'croenquiry' => Models\CroEnquiry::class, // v6.4
            'tvacancy' => Models\TalentVacancy::class, 'tapplicant' => Models\TalentApplicant::class, 'tinterview' => Models\TalentInterview::class, 'toffer' => Models\TalentOffer::class, 'temployee' => Models\Employee::class, 'ttask' => Models\TalentTask::class, 'tcycle' => Models\TalentCycle::class, 'tappraisal' => Models\TalentAppraisal::class, 'tcase' => Models\TalentCase::class, 'texit' => Models\TalentExit::class, // v6.9
            'qaaudit' => Models\QaAudit::class, 'qaaction' => Models\QaAction::class, // v6.5
            'esgwaste' => Models\EsgWasteLog::class, 'esgtarget' => Models\EsgTarget::class, 'esginitiative' => Models\EsgInitiative::class, 'esgitem' => Models\EsgChecklistItem::class, 'esgpolicy' => Models\EsgPolicy::class, 'esggreen' => Models\EsgGreenStay::class, 'esgcommunity' => Models\EsgCommunity::class, // v7.0
            'tvdevice' => Models\TvDevice::class, 'tvchannel' => Models\TvChannel::class, 'tvmovie' => Models\TvMovie::class, 'tvpurchase' => Models\TvPurchase::class, 'tvtemplate' => Models\TvWelcomeTemplate::class, 'tvpage' => Models\TvPage::class, 'tvmessage' => Models\TvMessage::class, // v7.1
            'kiosk' => Models\Kiosk::class, 'ksession' => Models\KioskSession::class, // v7.2
            'conversation' => Models\Conversation::class, 'inboxrule' => Models\InboxRule::class, 'quickreply' => Models\InboxQuickReply::class, 'broadcast' => Models\InboxBroadcast::class, // v7.3
            'bevent' => Models\BanquetEvent::class, 'orguser' => Models\OrganiserUser::class, 'orgreq' => Models\OrganiserRequest::class, // v7.5 organiser portal
            'hseequipment' => Models\HseEquipment::class, 'hsedrill' => Models\HseDrill::class, 'hsewarden' => Models\HseWarden::class, 'hserisk' => Models\HseRisk::class, 'hsepermit' => Models\HsePermit::class, 'hseaccident' => Models\HseAccident::class,
            'fbdish' => Models\MenuItem::class, 'fbyield' => Models\FbYieldTest::class, 'fbmeal' => Models\FbStaffMeal::class, // v6.8
            'parkingsession' => Models\ParkingSession::class, 'parkingvehicle' => Models\ParkingVehicle::class, 'parkingpermit' => Models\ParkingPermit::class, 'parkingzone' => Models\ParkingZone::class, // v6.6
            'sop' => Models\SopDocument::class, 'tcourse' => Models\TrainingCourse::class, 'treq' => Models\TrainingRequirement::class, 'tsession' => Models\TrainingSession::class, 'trecord' => Models\TrainingRecord::class, // v6.0
            'closetask' => Models\CloseTask::class, 'accrual' => Models\Accrual::class, 'rjournal' => Models\RecurringJournal::class, // v5.6
        ];
        foreach ($bindings as $key => $class) {
            Route::model($key, $class);
        }
    }
}
