<?php

namespace App\Http\Controllers;

use App\Models\MarketingSegment;
use App\Services\ActivityLogger;
use App\Services\CrmService;
use Illuminate\Http\Request;

class SegmentController extends Controller
{
    public function index()
    {
        $segments = MarketingSegment::withCount(['campaigns', 'journeys'])->orderByDesc('is_active')->orderBy('name')->get()
            ->each(function ($s) {
                $q = $s->guests();
                $s->size = (clone $q)->count();
                $s->reachable = (clone $q)->where('is_blacklisted', false)->where(fn ($w) => $w->where('email_opt_in', true)->orWhere('sms_opt_in', true)->orWhere('whatsapp_opt_in', true))->count();
            });

        return view('crm.segments.index', compact('segments'));
    }

    protected function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => 'required|string|max:120', 'description' => 'nullable|string|max:255', 'match' => 'required|in:all,any',
            'rules' => 'nullable|array|max:12', 'rules.*.field' => 'nullable|string', 'rules.*.op' => 'nullable|string', 'rules.*.value' => 'nullable|string|max:200',
            'is_active' => 'boolean',
        ]);
        $data['rules'] = CrmService::cleanRules($data['rules'] ?? []);
        $data['is_active'] = $request->boolean('is_active');

        return $data;
    }

    public function create()
    {
        abort_unless(can_do('crm.manage'), 403);

        return view('crm.segments.form', ['segment' => new MarketingSegment(['match' => 'all', 'is_active' => true, 'rules' => [['field' => 'stays_count', 'op' => 'gte', 'value' => '1']]])]);
    }

    public function store(Request $request)
    {
        abort_unless(can_do('crm.manage'), 403);
        $s = MarketingSegment::create($this->validated($request) + ['created_by' => auth()->id()]);
        ActivityLogger::log('crm', 'create', "Created segment \"{$s->name}\"", $s);

        return redirect()->route('crm.segments.show', $s)->with('success', 'Segment saved.');
    }

    public function show(Request $request, MarketingSegment $segment)
    {
        $guests = $segment->guests()->with('company')->search($request->q)->orderByDesc('total_revenue')->orderBy('first_name')->paginate(25)->withQueryString();
        $base = $segment->guests()->where('is_blacklisted', false);
        $reach = collect(CrmService::CHANNELS)->map(fn ($l, $ch) => (clone $base)->where($ch.'_opt_in', true)->count());

        return view('crm.segments.show', ['segment' => $segment, 'guests' => $guests, 'reach' => $reach, 'size' => $segment->guests()->count()]);
    }

    public function edit(MarketingSegment $segment)
    {
        abort_unless(can_do('crm.manage'), 403);

        return view('crm.segments.form', compact('segment'));
    }

    public function update(Request $request, MarketingSegment $segment)
    {
        abort_unless(can_do('crm.manage'), 403);
        $segment->update($this->validated($request));
        ActivityLogger::log('crm', 'update', "Updated segment \"{$segment->name}\"", $segment);

        return redirect()->route('crm.segments.show', $segment)->with('success', 'Segment updated.');
    }

    public function destroy(MarketingSegment $segment)
    {
        abort_unless(can_do('crm.manage'), 403);
        if ($segment->campaigns()->whereIn('status', ['scheduled', 'sending'])->exists()) {
            return back()->with('error', 'A scheduled campaign uses this segment – unschedule it first.');
        }
        if ($segment->campaigns()->exists() || $segment->journeys()->exists()) {
            $segment->update(['is_active' => false]); // keep history intact

            return redirect()->route('crm.segments.index')->with('success', 'Segment is used by campaigns / automations, so it was deactivated instead of deleted.');
        }
        ActivityLogger::log('crm', 'delete', "Deleted segment \"{$segment->name}\"");
        $segment->delete();

        return redirect()->route('crm.segments.index')->with('success', 'Segment deleted.');
    }

    /** Live count + sample for the rule builder. */
    public function preview(Request $request)
    {
        $rules = CrmService::cleanRules((array) $request->input('rules', []));
        $match = $request->input('match') === 'any' ? 'any' : 'all';
        $q = CrmService::segmentQuery($rules, $match);
        $ok = (clone $q)->where('is_blacklisted', false);

        return response()->json([
            'count' => (clone $q)->count(), 'describe' => CrmService::describeRules($rules, $match),
            'reach' => collect(CrmService::CHANNELS)->map(fn ($l, $ch) => (clone $ok)->where($ch.'_opt_in', true)->count()),
            'sample' => (clone $q)->orderByDesc('total_revenue')->limit(8)->get()->map(fn ($g) => ['name' => $g->full_name, 'stays' => $g->stays_count,
                'revenue' => money($g->total_revenue), 'country' => $g->country, 'url' => route('guests.show', $g)]),
        ]);
    }

    public function export(MarketingSegment $segment)
    {
        ActivityLogger::log('crm', 'export', "Exported segment \"{$segment->name}\"", $segment);
        $rows = $segment->guests()->orderBy('first_name')->get();

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Name', 'E-mail', 'Phone', 'Country', 'Stays', 'Nights', 'Revenue', 'Last stay', 'E-mail opt-in', 'SMS opt-in', 'WhatsApp opt-in', 'Tags']);
            foreach ($rows as $g) {
                // contact details only for guests who consented on that channel (data minimisation)
                fputcsv($out, [$g->full_name, $g->email_opt_in ? $g->email : '', ($g->sms_opt_in || $g->whatsapp_opt_in) ? $g->phone : '', $g->country, $g->stays_count,
                    $g->nights_count, $g->total_revenue, $g->last_stay_on?->toDateString(), $g->email_opt_in ? 'yes' : 'no', $g->sms_opt_in ? 'yes' : 'no',
                    $g->whatsapp_opt_in ? 'yes' : 'no', $g->tags]);
            }
            fclose($out);
        }, 'segment-'.\Illuminate\Support\Str::slug($segment->name).'.csv', ['Content-Type' => 'text/csv']);
    }
}
