<?php

use App\Models\Folio;
use App\Models\FolioTransaction;
use App\Models\Hotel;
use App\Models\ReservationRoom;
use App\Http\Controllers\FolioController;
use App\Services\ReservationDeskService;
use App\Support\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

Tenant::set(Hotel::firstOrFail());

$dates = [
    bdate()->copy()->subDay()->toDateString(),
    bdate()->toDateString(),
    bdate()->copy()->addDay()->toDateString(),
];
$roomFocuses = [
    'total' => ['focus' => null, 'kpi' => 'total'],
    'available' => ['focus' => 'available', 'kpi' => 'available'],
    'occupied' => ['focus' => 'occupied', 'kpi' => 'occupied'],
    'vacant_clean' => ['focus' => 'vacant_clean', 'kpi' => 'vacant_clean'],
    'vacant_dirty' => ['focus' => 'vacant_dirty', 'kpi' => 'vacant_dirty'],
    'cleaning' => ['focus' => 'cleaning', 'kpi' => 'cleaning'],
    'inspected' => ['focus' => 'inspected', 'kpi' => 'inspected'],
    'maintenance' => ['focus' => 'maintenance', 'kpi' => 'maintenance'],
    'ooo' => ['focus' => 'ooo', 'kpi' => 'ooo'],
    'oos' => ['focus' => 'oos', 'kpi' => 'oos'],
    'blocked' => ['focus' => 'blocked', 'kpi' => 'blocked'],
];
$load = fn (array $query) => ReservationDeskService::page(Request::create('/reservations', 'GET', $query));
$timelineRows = fn (array $page): int => $page['byRoom']->sum(fn ($group) => $group->count()) + $page['loose']->count();
$check = function (string $label, int $actual, int $expected): void {
    if ($actual !== $expected) {
        throw new RuntimeException("FAIL {$label}: expected {$expected}, got {$actual}");
    }
    echo "PASS {$label}: {$actual}".PHP_EOL;
};
$checkAmount = function (string $label, float $actual, float $expected): void {
    if (abs($actual - $expected) > 0.009) {
        throw new RuntimeException("FAIL {$label}: expected ".number_format($expected, 2, '.', '').', got '.number_format($actual, 2, '.', ''));
    }
    echo "PASS {$label}: ".number_format($actual, 2, '.', '').PHP_EOL;
};
$folioScope = (new FolioController())->index(Request::create('/folios', 'GET', ['status' => 'open', 'scope' => 'reservation_desk']));
$linkedOpenFolioCount = Folio::where('status', 'open')
    ->where(fn ($query) => $query->whereNotNull('reservation_id')->orWhereNotNull('reservation_room_id'))
    ->count();
$check('reservation desk open-folio register scope', $folioScope->getData()['folios']->total(), $linkedOpenFolioCount);
$malformedFolioSearch = Request::create('/folios', 'GET', ['scope' => 'reservation_desk', 'q' => ['not-a-scalar']]);
(new FolioController())->index($malformedFolioSearch);
$check('array folio search query is normalized safely', $malformedFolioSearch->query('q') === '' ? 1 : 0, 1);
$malformedFolioStatus = Request::create('/folios', 'GET', ['scope' => 'reservation_desk', 'status' => ['open']]);
(new FolioController())->index($malformedFolioStatus);
$check('array folio status query falls back to open', $malformedFolioStatus->query('status') === 'open' ? 1 : 0, 1);
$longFolioSearch = Request::create('/folios', 'GET', ['scope' => 'reservation_desk', 'q' => str_repeat('x', 200)]);
(new FolioController())->index($longFolioSearch);
$check('folio search query is bounded', mb_strlen($longFolioSearch->query('q')) , 160);
$folioSearchRows = $folioScope->getData()['folios']->getCollection();
$searchSample = $folioSearchRows->first(fn ($folio) => $folio->reservation || $folio->stay?->reservation);
if ($searchSample) {
    $searchReservation = $searchSample->reservation ?? $searchSample->stay?->reservation;
    $reservationResults = (new FolioController())->index(Request::create('/folios', 'GET', [
        'status' => 'open', 'scope' => 'reservation_desk', 'q' => $searchReservation->reservation_no,
    ]));
    $check('reservation-number search finds linked open folio', (int) $reservationResults->getData()['folios']->getCollection()->contains('id', $searchSample->id), 1);
}
$companySample = $folioSearchRows->first(fn ($folio) => $folio->company || $folio->reservation?->company || $folio->stay?->reservation?->company);
if ($companySample) {
    $companyName = $companySample->company?->name ?? $companySample->reservation?->company?->name ?? $companySample->stay?->reservation?->company?->name;
    $companyResults = (new FolioController())->index(Request::create('/folios', 'GET', [
        'status' => 'open', 'scope' => 'reservation_desk', 'q' => $companyName,
    ]));
    $check('company search finds linked open folio', (int) $companyResults->getData()['folios']->getCollection()->contains('id', $companySample->id), 1);
}

foreach (['charge', 'discount', 'allowance', 'payment', 'refund', 'opening_balance'] as $transactionType) {
    $day = bdate()->toDateString();
    $source = FolioTransaction::whereDate('business_date', $day)->where('is_void', false)->where('type', $transactionType);
    $register = (new FolioController())->transactions(Request::create('/folios/transactions', 'GET', ['date' => $day, 'type' => $transactionType]));
    $summary = $register->getData()['summary'];
    $expectedTotalExpression = match ($transactionType) {
        'charge' => DB::raw('amount + tax_amount'),
        'discount', 'allowance', 'refund' => DB::raw('-amount'),
        default => DB::raw('amount'),
    };
    $check("{$transactionType} type-filter entry count", (int) $summary->entries, $source->count());
    $checkAmount("{$transactionType} type-filter total", (float) $summary->total_posted, (float) $source->sum($expectedTotalExpression));
}

foreach ($dates as $day) {
    $dashboard = $load(['view' => 'dashboard', 'date' => $day]);
    $board = $load(['view' => 'board', 'date' => $day]);
    $boardRoomIds = $board['rooms']->pluck('id');
    $boardDeparturesShown = $board['rooms']->sum(fn ($room) => ($board['byRoom'][$room->id] ?? collect())
        ->filter(fn ($stay) => in_array($stay->status, ['checked_in', 'checked_out'], true) && $stay->departure_date->toDateString() === $day)
        ->count());
    $boardDeparturesExpected = ReservationRoom::whereIn('room_id', $boardRoomIds)->whereIn('status', ['checked_in', 'checked_out'])
        ->whereDate('departure_date', $day)->count();
    $check("{$day} Room Board includes selected-date departures", $boardDeparturesShown, $boardDeparturesExpected);
    $arrivals = $load(['view' => 'arrivals', 'date' => $day]);
    $walkIns = $load(['view' => 'arrivals', 'date' => $day, 'focus' => 'walkin']);
    $departures = $load(['view' => 'departures', 'date' => $day]);
    $dueOut = $load(['view' => 'departures', 'date' => $day, 'tab' => 'dueout']);
    $dueIn = $load(['view' => 'timeline', 'date' => $day, 'tab' => 'duein']);
    $stayovers = $load(['view' => 'timeline', 'date' => $day, 'tab' => 'stayover']);
    $vip = $load(['view' => 'timeline', 'date' => $day, 'tab' => 'vip']);
    $reserved = $load(['view' => 'timeline', 'date' => $day, 'focus' => 'reserved']);
    $groups = $load(['view' => 'groups', 'date' => $day]);
    $inhouse = $load(['view' => 'inhouse', 'date' => $day]);
    $inhouseBalances = $load(['view' => 'inhouse', 'date' => $day, 'focus' => 'balance']);
    $noShows = $load(['view' => 'list', 'date' => $day, 'status' => 'no_show', 'arrival_on' => $day]);
    $cancelled = $load(['view' => 'list', 'date' => $day, 'status' => 'cancelled', 'cancelled_on' => $day]);
    $postedRoomRegister = (new FolioController())->transactions(Request::create('/folios/transactions', 'GET', ['date' => $day, 'type' => 'charge', 'category' => 'room']));
    $postedAllRegister = (new FolioController())->transactions(Request::create('/folios/transactions', 'GET', ['date' => $day, 'type' => 'charge']));
    $depositRegister = (new FolioController())->transactions(Request::create('/folios/transactions', 'GET', ['date' => $day, 'type' => 'payment', 'category' => 'advance']));

    $posted = (float) FolioTransaction::whereDate('business_date', $day)->where('is_void', false)->where('type', 'charge')->sum(DB::raw('amount + tax_amount'));
    $roomPosted = (float) FolioTransaction::whereDate('business_date', $day)->where('is_void', false)->where('type', 'charge')->where('category', 'room')->sum(DB::raw('amount + tax_amount'));
    $deposits = (float) FolioTransaction::whereDate('business_date', $day)->where('is_void', false)->where('type', 'payment')->where('category', 'advance')->sum('amount');
    $openFolioIds = Folio::where('status', 'open')
        ->where(fn ($query) => $query->whereNotNull('reservation_id')->orWhereNotNull('reservation_room_id'))
        ->pluck('id');
    $outstanding = $openFolioIds->isEmpty() ? 0.0 : (float) FolioTransaction::whereIn('folio_id', $openFolioIds)->where('is_void', false)
        ->selectRaw("coalesce(sum(case type when 'charge' then amount + tax_amount when 'discount' then -amount when 'allowance' then -amount when 'payment' then -amount when 'refund' then amount else 0 end), 0) as bal")
        ->first()->bal;
    $deskDate = Carbon::parse($day)->startOfDay();
    $coveringStatuses = $deskDate->lt(bdate()->copy()->startOfDay()) ? ['checked_in', 'checked_out'] : ['reserved', 'checked_in'];
    $onBooks = (float) ReservationRoom::whereIn('status', $coveringStatuses)->whereDate('arrival_date', '<=', $day)->whereDate('departure_date', '>', $day)->sum('rate');

    $checkAmount("{$day} posted-all KPI/source", $dashboard['kpis']['posted'], $posted);
    $checkAmount("{$day} posted-room KPI/source", $dashboard['kpis']['room_posted'], $roomPosted);
    $checkAmount("{$day} posted-room KPI/drill-down", $dashboard['kpis']['room_posted'], (float) $postedRoomRegister->getData()['summary']->total_posted);
    $checkAmount("{$day} posted-all KPI/drill-down", $dashboard['kpis']['posted'], (float) $postedAllRegister->getData()['summary']->total_posted);
    $checkAmount("{$day} deposits KPI/source", $dashboard['kpis']['deposits'], $deposits);
    $checkAmount("{$day} deposits KPI/drill-down", $dashboard['kpis']['deposits'], (float) $depositRegister->getData()['summary']->total_posted);
    $checkAmount("{$day} reservation-linked outstanding KPI/source", $dashboard['kpis']['outstanding'], $outstanding);
    $checkAmount("{$day} expected-room KPI/source", $dashboard['kpis']['on_books'], $onBooks);

    foreach ($roomFocuses as $label => $filter) {
        $query = ['view' => 'board', 'date' => $day];
        if ($filter['focus'] !== null) {
            $query['focus'] = $filter['focus'];
        }
        $board = $load($query);
        $check("{$day} {$label} room KPI/list", $dashboard['kpis'][$filter['kpi']], $board['rooms']->count());
    }

    $check("{$day} arrivals KPI/list", $dashboard['kpis']['arrivals'], $arrivals['arrivals']->count());
    $check("{$day} arrivals tab/list", $dashboard['tabs']['arrivals'], $arrivals['arrivals']->count());
    $check("{$day} walk-in KPI/dashboard list", $dashboard['kpis']['walkins'], $dashboard['walkInArrivals']->count());
    $check("{$day} walk-in KPI/drill-down list", $dashboard['kpis']['walkins'], $walkIns['arrivals']->count());
    $check("{$day} departures tab/list", $dashboard['tabs']['departures'], $departures['departures']->count());
    $check("{$day} due-out tab/list", $dashboard['tabs']['dueout'], $dueOut['departures']->count());
    $date = \Illuminate\Support\Carbon::parse($day);
    $departureRows = $date->isSameDay(bdate()) ? $dueOut['departures']->count() : $departures['departures']->count();
    $check("{$day} departures KPI/list", $dashboard['kpis']['due_out'], $departureRows);
    $check("{$day} due-in tab/list", $dashboard['tabs']['duein'], $timelineRows($dueIn));
    $check("{$day} stayover tab/list", $dashboard['tabs']['stayover'], $timelineRows($stayovers));
    $check("{$day} VIP tab/list", $dashboard['tabs']['vip'], $timelineRows($vip));
    $check("{$day} reserved KPI/list", $dashboard['kpis']['reserved'], $timelineRows($reserved));
    $check("{$day} groups tab/list", $dashboard['tabs']['groups'], $groups['groups']->count());
    $inhouseFlags = ReservationDeskService::moneyFlags($inhouse['inhouse']);
    $expectedBalanceRows = $inhouse['inhouse']->filter(fn ($stay) => abs($inhouseFlags[$stay->id]['balance'] ?? 0) > 0.009)->count();
    $check("{$day} in-house balance drill-down", $inhouseBalances['inhouse']->count(), $expectedBalanceRows);
    $check("{$day} no-show KPI/list", $dashboard['kpis']['noshow'], $noShows['reservations']->total());
    $check("{$day} cancelled KPI/list", $dashboard['kpis']['cancelled'], $cancelled['reservations']->total());
}
