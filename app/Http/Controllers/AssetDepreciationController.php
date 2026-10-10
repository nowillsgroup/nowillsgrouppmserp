<?php

namespace App\Http\Controllers;

use App\Models\AssetDepRun;
use App\Models\GlJournal;
use App\Services\AssetService;
use Carbon\Carbon;
use Illuminate\Http\Request;

class AssetDepreciationController extends Controller
{
    public function index()
    {
        $next = AssetService::nextPeriod();

        return view('assets.depreciation', [
            'runs' => AssetDepRun::with('creator', 'reverser')->orderByDesc('period_end')->orderByDesc('id')->paginate(24),
            'next' => $next,
            'blocker' => AssetService::blocker($next),
            'preview' => $next ? AssetService::preview($next) : collect(),
            'last' => AssetService::lastRun(),
        ]);
    }

    public function run(Request $request)
    {
        $d = $request->validate(['period' => 'required|date_format:Y-m']);
        $run = AssetService::run(Carbon::createFromFormat('Y-m-d', $d['period'].'-01')->endOfMonth()->startOfDay());

        return redirect()->route('assets.depreciation.show', $run)->with('success', "Depreciation for {$run->label()} posted: {$run->assets} assets, ".money($run->total).'.');
    }

    public function show(AssetDepRun $deprun)
    {
        $deprun->load('creator', 'reverser');

        return view('assets.dep_run', [
            'run' => $deprun,
            'lines' => $deprun->lines()->with('asset.category')->get(),
            'journal' => GlJournal::where('source_type', 'asset_dep')->where('source_key', (string) $deprun->id)->whereNull('reversal_of_id')->latest('id')->first(),
            'isLast' => AssetService::lastRun()?->id === $deprun->id,
        ]);
    }

    public function reverse(Request $request, AssetDepRun $deprun)
    {
        $d = $request->validate(['reason' => 'required|string|max:190']);
        AssetService::reverse($deprun, $d['reason']);

        return redirect()->route('assets.depreciation')->with('success', "Depreciation {$deprun->run_no} reversed – {$deprun->label()} can be run again.");
    }
}
