<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\Department;
use App\Models\GatePass;
use App\Models\GatePassItem;
use App\Models\GatePassReturn;
use App\Support\Numbering;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * v4.2 – material gate passes: request → approval → gate out → (returnable) returns, partial returns, overdue alerts.
 * Segregation of duties: the requester cannot approve their own pass (super admins excepted).
 */
class GatePassService
{
    /**
     * $d: type, purpose, department_id, party_name, party_phone, carrier_name, vehicle_no, expected_return_date, reason,
     *     items: [[description, asset_id, quantity, unit, est_value], …]
     */
    public static function create(array $d): GatePass
    {
        $type = ($d['type'] ?? 'returnable') === 'non_returnable' ? 'non_returnable' : 'returnable';
        if (trim((string) ($d['party_name'] ?? '')) === '') {
            throw new RuntimeException('Who is receiving the goods? Enter the vendor / party name.');
        }
        $ret = $d['expected_return_date'] ?? null;
        if ($type === 'returnable') {
            if (! $ret) {
                throw new RuntimeException('Returnable passes need an expected return date.');
            }
            if ($ret < bdate()->toDateString()) {
                throw new RuntimeException('The expected return date cannot be in the past.');
            }
        } else {
            $ret = null;
        }
        $lines = [];
        $assetIds = [];
        foreach ((array) ($d['items'] ?? []) as $row) {
            $desc = trim((string) ($row['description'] ?? ''));
            $qty = round((float) ($row['quantity'] ?? 0), 2);
            $asset = ! empty($row['asset_id']) ? Asset::find($row['asset_id']) : null;
            if ($desc === '' && ! $asset) {
                continue;
            }
            if ($qty <= 0) {
                throw new RuntimeException('Every item needs a quantity above zero.');
            }
            if (! empty($row['asset_id']) && ! $asset) {
                throw new RuntimeException('Unknown asset.');
            }
            if ($asset) {
                if ($asset->status === 'disposed') {
                    throw new RuntimeException("Asset {$asset->asset_no} is disposed – it cannot go out on a gate pass.");
                }
                if (in_array($asset->id, $assetIds, true) || self::assetOnOpenPass($asset->id)) {
                    throw new RuntimeException("Asset {$asset->asset_no} is already on an open gate pass.");
                }
                $assetIds[] = $asset->id;
                $qty = 1;
            }
            $lines[] = ['description' => mb_substr($desc !== '' ? $desc : $asset->name, 0, 200), 'asset_id' => $asset?->id, 'quantity' => $qty,
                'unit' => mb_substr(trim((string) ($row['unit'] ?? '')) ?: 'nos', 0, 15), 'est_value' => ($row['est_value'] ?? '') !== '' && $row['est_value'] !== null ? round((float) $row['est_value'], 2) : null];
        }
        if (! $lines) {
            throw new RuntimeException('Add at least one item.');
        }
        $auto = ! SecurityService::cfg()['gp_approval'];

        return DB::transaction(function () use ($d, $type, $ret, $lines, $auto) {
            $p = GatePass::create(['pass_no' => Numbering::next('gate_pass'), 'type' => $type,
                'purpose' => array_key_exists($d['purpose'] ?? '', GatePass::PURPOSES) ? $d['purpose'] : 'other',
                'department_id' => ! empty($d['department_id']) ? Department::findOrFail($d['department_id'])->id : null,
                'party_name' => mb_substr(trim($d['party_name']), 0, 150), 'party_phone' => trim((string) ($d['party_phone'] ?? '')) ?: null,
                'carrier_name' => trim((string) ($d['carrier_name'] ?? '')) ?: null, 'vehicle_no' => strtoupper(trim((string) ($d['vehicle_no'] ?? ''))) ?: null,
                'expected_return_date' => $ret, 'reason' => trim((string) ($d['reason'] ?? '')) ?: null, 'requested_by' => auth()->id(),
                'status' => $auto ? 'approved' : 'pending', 'approved_by' => $auto ? auth()->id() : null, 'approved_at' => $auto ? now() : null]);
            foreach ($lines as $l) {
                $p->items()->create($l);
            }
            ActivityLogger::log('security', 'gatepass', "Gate pass {$p->pass_no} raised – ".GatePass::TYPES[$type].", {$p->party_name}, ".count($lines).' item(s)', $p);

            return $p;
        });
    }

    public static function assetOnOpenPass(int $assetId, ?int $exceptPassId = null): bool
    {
        return GatePassItem::where('asset_id', $assetId)->whereColumn('returned_qty', '<', 'quantity')
            ->whereHas('pass', fn ($q) => $q->whereIn('status', ['pending', 'approved', 'out', 'partial'])->when($exceptPassId, fn ($w) => $w->where('id', '!=', $exceptPassId)))->exists();
    }

    public static function approve(GatePass $p): GatePass
    {
        if ($p->status !== 'pending') {
            throw new RuntimeException('This gate pass is not waiting for approval.');
        }
        if ($p->requested_by === auth()->id() && ! auth()->user()?->is_super_admin) {
            throw new RuntimeException('You raised this gate pass – another manager has to approve it.');
        }
        $p->update(['status' => 'approved', 'approved_by' => auth()->id(), 'approved_at' => now()]);
        ActivityLogger::log('security', 'gatepass', "Gate pass {$p->pass_no} approved", $p);

        return $p;
    }

    public static function reject(GatePass $p, string $reason): GatePass
    {
        if ($p->status !== 'pending') {
            throw new RuntimeException('This gate pass is not waiting for approval.');
        }
        if (trim($reason) === '') {
            throw new RuntimeException('Give a reason.');
        }
        $p->update(['status' => 'rejected', 'approved_by' => auth()->id(), 'approved_at' => now(), 'reject_reason' => mb_substr(trim($reason), 0, 255)]);
        ActivityLogger::log('security', 'gatepass', "Gate pass {$p->pass_no} rejected: {$reason}", $p);

        return $p;
    }

    public static function cancel(GatePass $p, string $reason): GatePass
    {
        if (! in_array($p->status, ['pending', 'approved'], true)) {
            throw new RuntimeException('Only passes that have not gone out can be cancelled.');
        }
        $p->update(['status' => 'cancelled', 'closed_at' => now(), 'closed_by' => auth()->id(), 'close_note' => mb_substr(trim($reason) ?: 'Cancelled', 0, 255)]);
        ActivityLogger::log('security', 'gatepass', "Gate pass {$p->pass_no} cancelled", $p);

        return $p;
    }

    /** Security at the gate checks the goods against the pass and lets them out. */
    public static function markOut(GatePass $p, ?string $carrier = null, ?string $vehicle = null): GatePass
    {
        if ($p->status !== 'approved') {
            throw new RuntimeException($p->status === 'pending' ? 'This gate pass has not been approved – the goods cannot leave.' : 'This gate pass is not valid for exit.');
        }

        return DB::transaction(function () use ($p, $carrier, $vehicle) {
            $upd = ['out_at' => now(), 'out_by' => auth()->id(), 'carrier_name' => trim((string) $carrier) ?: $p->carrier_name,
                'vehicle_no' => strtoupper(trim((string) $vehicle)) ?: $p->vehicle_no];
            if ($p->type === 'returnable') {
                $upd['status'] = 'out';
            } else {
                $upd += ['status' => 'closed', 'closed_at' => now(), 'closed_by' => auth()->id(), 'close_note' => 'Non-returnable – left the property'];
            }
            $p->update($upd);
            if ($p->purpose === 'repair') {   // assets sent for repair show as "under repair" in the asset register
                foreach ($p->items()->whereNotNull('asset_id')->with('asset')->get() as $it) {
                    if ($it->asset?->status === 'active') {
                        $it->asset->update(['status' => 'in_repair']);
                    }
                }
            }
            ActivityLogger::log('security', 'gatepass', "Gate pass {$p->pass_no}: goods out through the gate".($p->carrier_name ? " with {$p->carrier_name}" : ''), $p);

            return $p;
        });
    }

    /** Goods coming back – $lines: [item_id => qty]. Partial returns allowed. */
    public static function receive(GatePass $p, array $lines, ?string $carrier = null, ?string $note = null): GatePassReturn
    {
        if (! in_array($p->status, GatePass::AWAY, true) || $p->type !== 'returnable') {
            throw new RuntimeException('Nothing is out on this gate pass.');
        }

        return DB::transaction(function () use ($p, $lines, $carrier, $note) {
            $items = $p->items()->lockForUpdate()->get()->keyBy('id');
            $got = [];
            foreach ($lines as $id => $qty) {
                $qty = round((float) $qty, 2);
                if ($qty <= 0) {
                    continue;
                }
                $it = $items[(int) $id] ?? throw new RuntimeException('That item is not on this gate pass.');
                if ($qty > $it->outstanding() + 0.001) {
                    throw new RuntimeException("Only {$it->outstanding()} {$it->unit} of \"{$it->description}\" is still out.");
                }
                $it->update(['returned_qty' => round((float) $it->returned_qty + $qty, 2)]);
                if ($it->asset_id && $it->outstanding() <= 0 && $p->purpose === 'repair' && $it->asset?->status === 'in_repair') {
                    $it->asset->update(['status' => 'active']);
                }
                $got[] = ['item_id' => $it->id, 'qty' => $qty];
            }
            if (! $got) {
                throw new RuntimeException('Enter the quantity that came back.');
            }
            $r = GatePassReturn::create(['gate_pass_id' => $p->id, 'returned_at' => now(), 'received_by' => auth()->id(),
                'carrier_name' => trim((string) $carrier) ?: null, 'note' => trim((string) $note) ?: null, 'lines' => $got]);
            $left = $items->sum(fn ($i) => max(0, $i->fresh()->outstanding()));
            $p->update($left <= 0 ? ['status' => 'returned', 'closed_at' => now(), 'closed_by' => auth()->id()] : ['status' => 'partial']);
            ActivityLogger::log('security', 'gatepass', "Gate pass {$p->pass_no}: goods received back".($left <= 0 ? ' – all returned' : ' (partial)'), $p);

            return $r;
        });
    }

    /** Short-close a returnable pass (item scrapped at the vendor, replaced under warranty, written off…). */
    public static function close(GatePass $p, string $note): GatePass
    {
        if (! in_array($p->status, GatePass::AWAY, true)) {
            throw new RuntimeException('Only passes with goods still out can be closed.');
        }
        if (trim($note) === '') {
            throw new RuntimeException('Explain why the pass is closed without everything coming back.');
        }
        $p->update(['status' => 'closed', 'closed_at' => now(), 'closed_by' => auth()->id(), 'close_note' => mb_substr(trim($note), 0, 255)]);
        ActivityLogger::log('security', 'gatepass', "Gate pass {$p->pass_no} closed short: {$note}", $p);

        return $p;
    }

    public static function extend(GatePass $p, string $date): GatePass
    {
        if ($p->type !== 'returnable' || ! in_array($p->status, ['approved', ...GatePass::AWAY], true)) {
            throw new RuntimeException('Only open returnable passes have a return date.');
        }
        if ($date < bdate()->toDateString()) {
            throw new RuntimeException('The new return date cannot be in the past.');
        }
        $old = $p->expected_return_date?->format('d M Y');
        $p->update(['expected_return_date' => $date, 'overdue_alerted_on' => null]);
        ActivityLogger::log('security', 'gatepass', "Gate pass {$p->pass_no}: return date moved from {$old} to ".date('d M Y', strtotime($date)), $p);

        return $p;
    }

    /** One log-book alert per pass when it first becomes overdue. */
    public static function alertOverdue(): int
    {
        $n = 0;
        $bd = bdate()->toDateString();
        foreach (GatePass::with('items', 'department')->where('type', 'returnable')->whereIn('status', GatePass::AWAY)->whereDate('expected_return_date', '<', $bd)
            ->whereNull('overdue_alerted_on')->get() as $p) {
            LogBookService::system("Gate pass {$p->pass_no} overdue – goods not back from {$p->party_name}",
                'Expected back '.$p->expected_return_date->format('d M Y').'. Still out: '.$p->items->filter(fn ($i) => $i->outstanding() > 0)
                    ->map(fn ($i) => $i->outstanding().' '.$i->unit.' '.$i->description)->join(', ').'.'.($p->department ? ' Department: '.$p->department->name.'.' : ''),
                'security', 'high', ['department' => 'security'], true);
            $p->forceFill(['overdue_alerted_on' => $bd])->saveQuietly();
            $n++;
        }

        return $n;
    }
}
