<?php

namespace App\Services;

use App\Models\KotPrintJob;
use App\Models\KotPrinter;
use App\Models\PosOrder;
use App\Models\PosOrderItem;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Routes a KOT to one or more kitchen printers (by dish, category or station) and records a print job
 * for each slip: a browser print station, a direct ESC/POS network printer, or a captain / cashier copy.
 */
class KotPrintService
{
    public const CONNECTIONS = ['browser' => 'This computer (browser / USB)', 'network' => 'Network printer (ESC/POS, port 9100)'];

    public const ROLES = ['station' => 'Station — only this station\'s items', 'captain' => 'Captain / cashier — every item'];

    public const PAPERS = ['80' => '80 mm', '58' => '58 mm'];

    public const MODES = [
        'split' => 'Split to each station printer',
        'combined' => 'One combined sheet',
        'ask' => 'Ask which printers each time',
    ];

    /** Active printers that serve this outlet (outlet-specific plus hotel-wide). */
    public function forOutlet(?int $outletId): Collection
    {
        return KotPrinter::where('is_active', true)
            ->where(fn ($q) => $q->whereNull('outlet_id')->when($outletId, fn ($w) => $w->orWhere('outlet_id', $outletId)))
            ->orderBy('sort_order')->orderBy('id')->get();
    }

    public function stationOf(PosOrderItem $item): string
    {
        return $item->kitchen_station ?: ($item->menuItem?->category?->kitchen_station ?: 'Kitchen');
    }

    /** Which station printer should cook this line. Captain printers are never a station target. */
    public function resolve(PosOrderItem $item, Collection $printers, ?int $outletId): ?KotPrinter
    {
        $stations = $printers->where('role', 'station');
        $explicit = $item->menuItem?->kot_printer_id ?: $item->menuItem?->category?->kot_printer_id;
        if ($explicit && ($hit = $stations->firstWhere('id', (int) $explicit))) {
            return $hit;
        }
        $station = $this->stationOf($item);
        $match = $stations->filter(fn ($p) => strcasecmp((string) $p->station, $station) === 0);
        if ($hit = $match->first(fn ($p) => (int) $p->outlet_id === (int) $outletId)) {
            return $hit;
        }
        if ($hit = $match->first(fn ($p) => $p->outlet_id === null)) {
            return $hit;
        }
        $fallback = $stations->where('is_fallback', true);
        if ($hit = $fallback->first(fn ($p) => (int) $p->outlet_id === (int) $outletId)) {
            return $hit;
        }

        return $fallback->first(fn ($p) => $p->outlet_id === null) ?? $fallback->first();
    }

    /** Remember the printer on each line of a KOT so reprints stay stable if the menu mapping changes later. */
    public function stamp(PosOrder $order, int $kot): void
    {
        $printers = $this->forOutlet($order->outlet_id);
        foreach ($order->items()->with('menuItem.category')->where('kot_no', $kot)->get() as $line) {
            $id = $this->resolve($line, $printers, $order->outlet_id)?->id;
            if ((int) $line->kot_printer_id !== (int) $id) {
                $line->forceFill(['kot_printer_id' => $id])->save();
            }
        }
    }

    public function whereText(PosOrder $order): string
    {
        if ($order->table) {
            return 'TABLE '.$order->table->table_no;
        }
        if ($order->stay) {
            return 'ROOM '.($order->stay->room?->room_no ?? '');
        }

        return strtoupper(str_replace('_', ' ', (string) $order->order_type));
    }

    /**
     * Slips to print. Options: printers (ids), force (every selected printer gets every item),
     * combined, void (cancelled lines only), captain (bool, default from settings).
     *
     * @return array<int, array{printer: ?KotPrinter, kind: string, items: Collection, copies: int, sections: array, void: bool}>
     */
    public function plan(PosOrder $order, int $kot, array $opt = []): array
    {
        $order->loadMissing('items.menuItem.category', 'outlet', 'table', 'stay.room');
        $void = ! empty($opt['void']);
        $lines = $order->items->where('kot_no', (int) $kot)->where('is_cancelled', $void)->values();
        if ($lines->isEmpty()) {
            return [];
        }
        $printers = $this->forOutlet($order->outlet_id);
        $selected = collect($opt['printers'] ?? [])->map(fn ($id) => (int) $id)->filter()->unique()->values();
        if ($selected->isNotEmpty()) {
            $printers = $printers->concat(KotPrinter::where('is_active', true)->whereIn('id', $selected)->get())->unique('id')->values();
        }
        $force = ! empty($opt['force']);
        $captain = array_key_exists('captain', $opt) ? (bool) $opt['captain'] : setting('pos_kot_captain', '1') === '1';

        if (! empty($opt['combined']) || ($printers->isEmpty() && $selected->isEmpty())) {
            return [$this->slip(null, $void ? 'cancel' : 'combined', $lines, $void, $printers->isEmpty() ? [] : $this->sections($lines, $printers, $order))];
        }

        $grouped = $lines->groupBy(fn ($l) => (int) ($l->kot_printer_id ?: ($this->resolve($l, $printers, $order->outlet_id)?->id ?? 0)));
        $targets = $selected->isNotEmpty()
            ? $printers->whereIn('id', $selected->all())->values()
            : $printers->where('role', 'station')->values();

        $slips = [];
        foreach ($targets as $p) {
            if ($p->role === 'captain') {
                $slips[] = $this->slip($p, 'captain', $lines, $void);
                continue;
            }
            $items = $force ? $lines : $grouped->get($p->id, collect());
            if (! $force && $p->is_fallback) {
                $items = $items->concat($grouped->get(0, collect()))->unique('id')->values();
            }
            if ($items->isEmpty()) {
                continue;
            }
            $slips[] = $this->slip($p, $void ? 'cancel' : 'station', $items, $void);
        }

        if ($selected->isEmpty() && $grouped->has(0) && ! $printers->contains(fn ($p) => $p->is_fallback && $p->role === 'station')) {
            $slips[] = $this->slip(null, $void ? 'cancel' : 'unrouted', $grouped->get(0), $void);
        }
        if ($captain && $selected->isEmpty()) {
            foreach ($printers->where('role', 'captain') as $p) {
                if ($p->outlet_id && (int) $p->outlet_id !== (int) $order->outlet_id) {
                    continue;
                }
                $slips[] = $this->slip($p, 'captain', $lines, $void);
            }
        }

        return $slips;
    }

    /** Stamp the KOT and queue one job per slip. Network printers are sent immediately. Ask-mode waits for a choice. */
    public function fire(PosOrder $order, int $kot, array $opt = []): Collection
    {
        $this->stamp($order, $kot);
        $order->unsetRelation('items');
        $order->load('items.menuItem.category', 'outlet', 'table', 'stay.room');

        $mode = $opt['mode'] ?? 'auto';
        if ($this->forOutlet($order->outlet_id)->isEmpty() && empty($opt['printers']) && empty($opt['combined'])) {
            return collect();
        }
        $ask = $mode === 'auto' && empty($opt['printers']) && empty($opt['combined']) && empty($opt['void'])
            && setting('pos_kot_print_mode', 'split') === 'ask';
        if ($ask) {
            return collect();
        }
        if ($mode === 'auto' && empty($opt['printers']) && empty($opt['void']) && setting('pos_kot_print_mode', 'split') === 'combined') {
            $opt['combined'] = true;
        }

        return $this->queue($order, $kot, $this->plan($order, $kot, $opt), $mode, ! empty($opt['void']));
    }

    /** A cancelled line: void slip to its station printer and the captain printer. */
    public function voidLine(PosOrder $order, PosOrderItem $line): Collection
    {
        if (! $line->kot_no) {
            return collect();
        }
        $order->loadMissing('outlet', 'table', 'stay.room');
        $line->loadMissing('menuItem.category');
        $printers = $this->forOutlet($order->outlet_id);
        $p = $line->kot_printer_id
            ? ($printers->firstWhere('id', (int) $line->kot_printer_id) ?? KotPrinter::find($line->kot_printer_id))
            : $this->resolve($line, $printers, $order->outlet_id);
        $targets = collect($p ? [$p] : []);
        if (setting('pos_kot_captain', '1') === '1') {
            $targets = $targets->concat($printers->where('role', 'captain')->filter(
                fn ($c) => ! $c->outlet_id || (int) $c->outlet_id === (int) $order->outlet_id
            ));
        }
        if ($targets->isEmpty()) {
            $targets = collect([null]);
        }
        $slips = $targets->map(fn ($printer) => $this->slip($printer, 'cancel', collect([$line]), true))->all();

        return $this->queue($order, (int) $line->kot_no, $slips, 'void', true, 'Void slip for '.$line->item_name.' KOT #'.$line->kot_no);
    }

    /** Captain released held dishes: a FIRE slip to each station, and the captain copy. */
    public function release(PosOrder $order, Collection $lines): Collection
    {
        $order->loadMissing('outlet', 'table', 'stay.room');
        $lines->load('menuItem.category');
        $printers = $this->forOutlet($order->outlet_id);
        $jobs = collect();
        foreach ($lines->groupBy(fn ($l) => (int) $l->kot_no) as $kot => $group) {
            $targets = collect();
            foreach ($group->groupBy(fn ($l) => (int) ($l->kot_printer_id ?? 0)) as $pid => $items) {
                $p = $pid
                    ? ($printers->firstWhere('id', $pid) ?? KotPrinter::find($pid))
                    : $this->resolve($items->first(), $printers, $order->outlet_id);
                $targets->push($this->slip($p, 'fire', $items, false));
            }
            if (setting('pos_kot_captain', '1') === '1') {
                foreach ($printers->where('role', 'captain') as $p) {
                    if (! $p->outlet_id || (int) $p->outlet_id === (int) $order->outlet_id) {
                        $targets->push($this->slip($p, 'fire', $group, false));
                    }
                }
            }
            if ($targets->isEmpty()) {
                $targets->push($this->slip(null, 'fire', $group, false));
            }
            $jobs = $jobs->concat($this->queue($order, (int) $kot, $targets->all(), 'fire', false, 'Fire KOT #'.$kot.' on '.$order->order_no));
        }

        return $jobs;
    }

    /** Whole-order void: one cancel slip per printer that had live items, plus the captain copy. */
    public function voidKot(PosOrder $order, int $kot): Collection
    {
        $order->loadMissing('items.menuItem.category', 'outlet', 'table', 'stay.room');
        $lines = $order->items->where('kot_no', $kot)->where('is_cancelled', false)->values();
        if ($lines->isEmpty()) {
            return collect();
        }
        if ($lines->contains(fn ($l) => ! $l->kot_printer_id)) {
            $this->stamp($order, $kot);
            $order->unsetRelation('items');
            $order->load('items.menuItem.category');
            $lines = $order->items->where('kot_no', $kot)->where('is_cancelled', false)->values();
        }
        $printers = $this->forOutlet($order->outlet_id);
        $slips = [];
        foreach ($lines->groupBy(fn ($l) => (int) ($l->kot_printer_id ?? 0)) as $pid => $items) {
            $slips[] = $this->slip($pid ? $printers->firstWhere('id', $pid) : null, 'cancel', $items, true);
        }
        if (setting('pos_kot_captain', '1') === '1') {
            foreach ($printers->where('role', 'captain') as $p) {
                if (! $p->outlet_id || (int) $p->outlet_id === (int) $order->outlet_id) {
                    $slips[] = $this->slip($p, 'cancel', $lines, true);
                }
            }
        }

        return $this->queue($order, $kot, $slips, 'void', true, 'Void KOT #'.$kot.' on '.$order->order_no);
    }

    public function jobSummary(Collection $jobs): string
    {
        if ($jobs->isEmpty()) {
            return '';
        }

        return $jobs->map(function ($j) {
            $name = $j->printer?->name ?? match ($j->kind) {
                'combined' => 'Combined sheet',
                'unrouted' => 'Unrouted',
                default => 'Printer',
            };

            return match ($j->status) {
                'failed' => $name.' (offline)',
                'sent' => $name.' (sent)',
                'printed' => $name.' (printed)',
                default => $name,
            };
        })->implode(', ');
    }

    public function sendJob(KotPrintJob $job): void
    {
        $printer = $job->relationLoaded('printer') ? $job->printer : $job->printer()->first();
        if (! $printer || $printer->connection !== 'network') {
            return;
        }
        $order = $job->order()->with('outlet', 'table', 'stay.room')->first();
        $items = PosOrderItem::whereIn('id', $job->item_ids ?? [])->orderBy('id')->get();
        try {
            $this->guardHost($printer->host);
            $copies = max(1, (int) $job->copies);
            $payload = '';
            for ($i = 1; $i <= $copies; $i++) {
                $payload .= $this->escpos($order, (int) $job->kot_no, $items, $printer, $job->kind, $i, $copies);
            }
            $errno = 0;
            $err = '';
            $fp = @stream_socket_client('tcp://'.$printer->host.':'.((int) $printer->port ?: 9100), $errno, $err, 2);
            if (! $fp) {
                throw new InvalidArgumentException($err ?: 'Printer did not answer');
            }
            stream_set_timeout($fp, 2);
            $ok = fwrite($fp, $payload);
            fclose($fp);
            if ($ok === false) {
                throw new InvalidArgumentException('Could not write to the printer');
            }
            $job->forceFill(['status' => 'sent', 'printed_at' => now(), 'error' => null])->save();
        } catch (\Throwable $e) {
            $job->forceFill(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 250)])->save();
        }
    }

    /** Reject public and link-local addresses so a printer host cannot be used to probe the network. */
    public function guardHost(?string $host): void
    {
        $host = trim((string) $host);
        if ($host === '') {
            throw new InvalidArgumentException('Enter the printer IP address.');
        }
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            if (! $this->isLan($host)) {
                throw new InvalidArgumentException('Network printers must be on the hotel LAN (a private IP such as 192.168.x.x).');
            }

            return;
        }
        if (! preg_match('/^[A-Za-z0-9.-]{1,80}$/', $host) || str_contains($host, '..')) {
            throw new InvalidArgumentException('Enter a printer IP address on the hotel LAN.');
        }
        $ip = @gethostbyname($host);
        if (! $ip || $ip === $host || ! $this->isLan($ip)) {
            throw new InvalidArgumentException('That name does not resolve to a private LAN address.');
        }
    }

    public function isLan(string $ip): bool
    {
        if ($ip === '127.0.0.1') {
            return true;
        }
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return false;
        }
        $long = ip2long($ip);
        $in = function (string $subnet, int $bits) use ($long) {
            $mask = -1 << (32 - $bits);

            return ($long & $mask) === (ip2long($subnet) & $mask);
        };

        return $in('10.0.0.0', 8) || $in('172.16.0.0', 12) || $in('192.168.0.0', 16);
    }

    public function escpos(PosOrder $order, int $kot, Collection $items, KotPrinter $printer, string $kind, int $copy = 1, int $copies = 1): string
    {
        $width = $printer->paper === '58' ? 32 : 42;
        $line = str_repeat('-', $width);
        $out = "\x1B\x40";
        if ($printer->beep) {
            $out .= "\x07";
        }
        $out .= "\x1B\x61\x01\x1D\x21\x11";
        $out .= ($kind === 'cancel' ? "VOID\n" : ($kind === 'fire' ? "FIRE\n" : ($kind === 'captain' ? "CAPTAIN\n" : "KOT\n")));
        $out .= "\x1D\x21\x00".$this->ascii($printer->name)."\n";
        if ($printer->header) {
            $out .= $this->ascii($printer->header)."\n";
        }
        if ($copies > 1) {
            $out .= "Copy {$copy} of {$copies}\n";
        }
        $out .= "\x1B\x61\x00{$line}\n";
        $out .= 'KOT #'.$kot.'  '.now()->format('d/m H:i')."\n";
        $out .= $this->ascii($this->whereText($order).'  '.$order->order_no)."\n";
        if ($order->allergens) {
            $out .= 'ALLERGY: '.$this->ascii(AllergenService::names($order->allergens))."\n";
        }
        $out .= $line."\n\x1B\x45\x01";
        foreach ($items as $l) {
            $food = $l->is_open ? (['veg' => 'VEG ', 'non_veg' => 'NON-VEG ', 'egg' => 'EGG ', 'beverage' => 'DRINK '][$l->food_type] ?? '') : '';
            $hold = $l->kitchen_status === 'held' ? 'HOLD ' : '';
            $out .= $this->ascii(((float) $l->qty).'  '.$hold.($l->is_open ? 'SPECIAL '.$food : '').$l->item_name)."\n";
            if ($l->is_open && $l->kitchen_station) {
                $out .= '   '.$this->ascii($l->kitchen_station)."\n";
            }
            if ($l->notes) {
                $out .= '   '.$this->ascii($l->notes)."\n";
            }
            if ($l->allergy_flag && ! $l->is_cancelled) {
                $out .= '   !! '.$this->ascii($l->allergy_flag)."\n";
            }
        }
        $out .= "\x1B\x45\x00{$line}\n\x1B\x61\x01".$this->ascii(auth()->user()->name ?? '')."\n\n\n\x1D\x56\x41\x03";

        return $out;
    }

    public function testTicket(KotPrinter $printer): string
    {
        $width = $printer->paper === '58' ? 32 : 42;
        $line = str_repeat('-', $width);
        $out = "\x1B\x40".($printer->beep ? "\x07" : '')."\x1B\x61\x01\x1D\x21\x11TEST\n\x1D\x21\x00";
        $out .= $this->ascii($printer->name)."\n".$this->ascii($printer->header)."\n{$line}\n";
        $out .= "\x1B\x61\x00KOT printer test\n".now()->format('d/m/Y H:i')."\n\n\n\x1D\x56\x41\x03";

        return $out;
    }

    /** @param  array<int, array<string, mixed>>  $slips */
    protected function queue(PosOrder $order, int $kot, array $slips, string $mode, bool $void, ?string $log = null): Collection
    {
        $jobs = collect();
        foreach ($slips as $slip) {
            $job = KotPrintJob::create([
                'pos_order_id' => $order->id,
                'kot_no' => $kot,
                'kot_printer_id' => $slip['printer']?->id,
                'kind' => $slip['kind'],
                'status' => 'queued',
                'copies' => $slip['copies'],
                'item_ids' => $slip['items']->pluck('id')->values()->all(),
                'mode' => $mode,
                'user_id' => auth()->id(),
            ]);
            $job->setRelation('printer', $slip['printer']);
            if ($slip['printer']?->connection === 'network') {
                $this->sendJob($job);
            }
            $jobs->push($job->fresh('printer') ?? $job);
        }
        if ($jobs->isNotEmpty()) {
            ActivityLogger::log('pos', $void ? 'kot_void_print' : 'kot_print', ($log ?: 'KOT #'.$kot.' on '.$order->order_no).': '.$this->jobSummary($jobs), $order);
        }

        return $jobs;
    }

    protected function slip(?KotPrinter $printer, string $kind, Collection $items, bool $void, array $sections = []): array
    {
        return [
            'printer' => $printer,
            'kind' => $kind,
            'items' => $items->values(),
            'copies' => max(1, (int) ($printer->copies ?? 1)),
            'sections' => $sections,
            'void' => $void || $kind === 'cancel',
        ];
    }

    protected function sections(Collection $lines, Collection $printers, PosOrder $order): array
    {
        $out = [];
        foreach ($lines->groupBy(fn ($l) => (int) ($l->kot_printer_id ?: ($this->resolve($l, $printers, $order->outlet_id)?->id ?? 0))) as $pid => $items) {
            $p = $printers->firstWhere('id', $pid);
            $out[] = ['label' => $p?->name ?? 'UNROUTED', 'items' => $items->values()];
        }

        return $out;
    }

    protected function ascii(?string $s): string
    {
        $s = preg_replace('/[^\x20-\x7E]/', '?', (string) $s) ?? '';

        return mb_substr($s, 0, 120);
    }
}
