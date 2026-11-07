<?php

namespace App\Http\Controllers;

use App\Services\BriefingService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Daily briefings: one pack per head of department, and the consolidated pack for the
 * General Manager and the CEO.
 *
 * A pack is read-only except for the sign-off, which records that the head has seen the
 * figures. Every screen prints on the print layout, so a pack can be filed or e-mailed.
 */
class BriefingController extends Controller
{
    /** The hub: every pack this user may open, in registry order, with today's status. */
    public function index(Request $request)
    {
        $packs = BriefingService::forUser($request->user());
        $visible = $packs->where('can', true);

        abort_if($visible->isEmpty() && ! BriefingService::can('exec', $request->user()), 403);

        return view('briefings.index', [
            'packs' => $visible->values(),
            'hidden' => $packs->where('can', false)->count(),
            'canExec' => BriefingService::can('exec', $request->user()),
            'signoffs' => BriefingService::signoffs(bdate()),
            'date' => bdate(),
        ]);
    }

    public function show(Request $request, string $pack)
    {
        abort_unless(isset(BriefingService::PACKS[$pack]), 404);
        abort_unless(BriefingService::can($pack, $request->user()), 403);
        $date = $this->date($request);

        return view('briefings.show', [
            'pack' => BriefingService::pack($pack, $date),
            'date' => $date,
            'packs' => BriefingService::forUser($request->user())->where('can', true)->values(),
            'today' => bdate(),
        ]);
    }

    public function print(Request $request, string $pack)
    {
        abort_unless(isset(BriefingService::PACKS[$pack]), 404);
        abort_unless(BriefingService::can($pack, $request->user()), 403);
        $date = $this->date($request);

        return view('briefings.print', [
            'pack' => BriefingService::pack($pack, $date),
        ]);
    }

    public function exec(Request $request)
    {
        abort_unless(BriefingService::can('exec', $request->user()), 403);
        $date = $this->date($request);

        return view('briefings.exec', [
            'pack' => BriefingService::exec($date),
            'date' => $date,
            'today' => bdate(),
        ]);
    }

    public function execPrint(Request $request)
    {
        abort_unless(BriefingService::can('exec', $request->user()), 403);
        $date = $this->date($request);

        return view('briefings.print', [
            'pack' => BriefingService::exec($date),
        ]);
    }

    /** The executive brief as a spreadsheet. */
    public function execCsv(Request $request): StreamedResponse
    {
        abort_unless(BriefingService::can('exec', $request->user()), 403);

        return $this->streamCsv(BriefingService::exec($this->date($request)));
    }

    /** One head's pack as a spreadsheet, so the figures can be filed or sent on. */
    public function packCsv(Request $request, string $pack): StreamedResponse
    {
        abort_unless(isset(BriefingService::PACKS[$pack]), 404);
        abort_unless(BriefingService::can($pack, $request->user()), 403);

        return $this->streamCsv(BriefingService::pack($pack, $this->date($request)));
    }

    /**
     * The briefing book: the executive brief and every department pack this user may read,
     * in one printable document, with a contents page. This is the pile of paper the GM
     * carries into the morning meeting, produced in a single action.
     */
    public function book(Request $request)
    {
        abort_unless(BriefingService::can('exec', $request->user()), 403);
        $date = $this->date($request);
        $packs = collect(BriefingService::PACKS)->keys()
            ->filter(fn ($key) => BriefingService::can($key, $request->user()))
            ->map(fn ($key) => BriefingService::pack($key, $date))
            ->values();

        return view('briefings.book', [
            'exec' => BriefingService::exec($date),
            'packs' => $packs,
            'date' => $date,
        ]);
    }

    /**
     * Write a pack out as CSV: who it is for and whether it is signed, the headline, every
     * block in section order, then what needs attention and where the numbers came from.
     */
    private function streamCsv(array $p): StreamedResponse
    {
        $date = $p['date'];
        $heading = $p['is_exec'] ? 'Daily executive brief' : $p['label'].' — daily briefing';
        $signoff = $p['is_exec']
            ? $p['signed_count'].' of '.$p['pack_count'].' departments signed'
            : ($p['signoff']
                ? 'Signed by '.($p['signoff']->user?->name ?? 'a manager').' at '.$p['signoff']->signed_at?->format('H:i')
                : 'Not signed');
        $name = Str::slug(($p['is_exec'] ? 'executive brief' : $p['label'].' briefing').' '.$date->toDateString());

        return response()->streamDownload(function () use ($p, $heading, $signoff) {
            $out = fopen('php://output', 'w');
            fputcsv($out, [$heading, $p['date']->toDateString()]);
            fputcsv($out, [$p['role'], $signoff]);
            fputcsv($out, []);
            fputcsv($out, ['Headline']);
            foreach ($p['headline'] as $h) {
                fputcsv($out, [$h['label'], $h['value'], $h['sub'] ?? '']);
            }
            foreach ($p['sections'] as $section) {
                fputcsv($out, []);
                fputcsv($out, [$section['title']]);
                if (($section['kind'] ?? '') === 'table') {
                    fputcsv($out, array_column($section['cols'], 'label'));
                    foreach ($section['rows'] as $row) {
                        fputcsv($out, array_map(fn ($col) => $row[$col['key']]['text'] ?? '', $section['cols']));
                    }
                } elseif (($section['kind'] ?? '') === 'stats') {
                    foreach ($section['stats'] as $s) {
                        fputcsv($out, [$s['label'], $s['value'], $s['sub'] ?? '']);
                    }
                } else {
                    foreach ($section['items'] ?? [] as $i) {
                        fputcsv($out, [$i['text'], $i['value'] ?? '']);
                    }
                }
            }
            if ($p['watch'] || $p['todo']) {
                fputcsv($out, []);
                fputcsv($out, ['What needs attention']);
                foreach ($p['watch'] as $w) {
                    fputcsv($out, ['Watch', $w]);
                }
                foreach ($p['todo'] as $t) {
                    fputcsv($out, ['Action', $t]);
                }
            }
            fputcsv($out, []);
            fputcsv($out, ['Where these numbers come from', $p['sources']]);
            fclose($out);
        }, $name.'.csv', ['Content-Type' => 'text/csv']);
    }

    /** Record that the head has read today's pack. */
    public function sign(Request $request, string $pack)
    {
        abort_unless(isset(BriefingService::PACKS[$pack]), 404);
        abort_unless(BriefingService::can($pack, $request->user()), 403);
        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);
        $built = BriefingService::pack($pack, bdate());

        try {
            BriefingService::signOff($pack, bdate(), $request->user(), $data['note'] ?? null, $built['headline']);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('briefings.show', ['pack' => $pack])->with('success', 'Signed. The general manager can see that you have read today\'s briefing.');
    }

    /** Business date for the screen: today unless a valid past date is asked for. */
    private function date(Request $request): Carbon
    {
        $raw = $request->query('date');
        if (! is_string($raw) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            return bdate()->copy();
        }
        $date = Carbon::parse($raw)->startOfDay();

        return $date->gt(bdate()) ? bdate()->copy() : $date;
    }
}
