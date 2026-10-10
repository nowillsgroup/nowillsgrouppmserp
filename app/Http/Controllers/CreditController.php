<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\CreditLog;
use App\Models\Folio;
use App\Models\RateContract;
use App\Models\Setting;
use App\Services\ActivityLogger;
use App\Services\CreditService;
use Illuminate\Http\Request;

/**
 * v2.7 Credit control for direct-billing accounts: exposure (city ledger + in-house folios billed to the company)
 * against the credit limit, FIFO ageing against the company's credit days, manual / automatic credit holds,
 * terms changes with an audit log, and statements by e-mail.
 */
class CreditController extends Controller
{
    public const FILTERS = ['all' => 'All accounts', 'attention' => 'Needs attention', 'hold' => 'On hold', 'over' => 'Over limit', 'overdue' => 'Overdue'];

    public function index(Request $request)
    {
        $filter = array_key_exists($request->show, self::FILTERS) ? $request->show : 'all';
        $all = CreditService::positions()->filter(fn ($p) => $p['company']->is_active || $p['outstanding'] != 0);
        $rows = $all->filter(fn ($p) => match ($filter) {
            'hold' => $p['company']->onHold(), 'over' => $p['over_limit'], 'overdue' => $p['overdue'] > 0,
            'attention' => $p['company']->onHold() || $p['over_limit'] || $p['overdue'] > 0,
            default => true,
        })->sortByDesc('exposure')->values();

        return view('credit.index', ['rows' => $rows, 'filter' => $filter, 'settings' => [
            'auto' => setting('credit_auto_hold', '1') === '1', 'grace' => (int) setting('credit_hold_grace_days', '15')],
            'stats' => ['exposure' => $all->sum('exposure'), 'outstanding' => $all->sum(fn ($p) => max(0, $p['outstanding'])), 'overdue' => $all->sum('overdue'),
                'pending' => $all->sum('pending'), 'hold' => $all->filter(fn ($p) => $p['company']->onHold())->count(), 'over' => $all->where('over_limit', true)->count()],
        ]);
    }

    public function show(Company $company)
    {
        $p = CreditService::position($company);

        return view('credit.show', [
            'co' => $company->load('accountManager'), 'p' => $p,
            'logs' => CreditLog::with('user', 'folio')->where('company_id', $company->id)->latest('id')->limit(50)->get(),
            'inhouse' => Folio::with('guest', 'stay.room')->where('status', 'open')->where('company_id', $company->id)->get()->filter(fn ($f) => $f->balance() > 0),
            'contracts' => RateContract::where('company_id', $company->id)->orderByDesc('valid_to')->limit(5)->get(),
        ]);
    }

    public function hold(Request $request, Company $company)
    {
        $data = $request->validate(['reason' => 'required|string|min:5|max:200']);
        abort_if($company->onHold(), 422, 'Already on hold.');
        CreditService::hold($company, $data['reason']);

        return back()->with('success', "{$company->name} is on credit hold – new city-ledger billing needs a credit override.");
    }

    public function release(Request $request, Company $company)
    {
        $data = $request->validate(['reason' => 'required|string|min:5|max:200']);
        abort_unless($company->onHold(), 422, 'Not on hold.');
        CreditService::release($company, $data['reason']);

        return back()->with('success', "Credit hold on {$company->name} released.");
    }

    public function terms(Request $request, Company $company)
    {
        $data = $request->validate(['credit_limit' => 'required|numeric|min:0|max:1000000000', 'credit_days' => 'required|integer|min:0|max:365',
            'direct_billing' => 'boolean', 'note' => 'nullable|string|max:150']);
        $n = CreditService::setTerms($company, (float) $data['credit_limit'], (int) $data['credit_days'], $request->boolean('direct_billing'), $data['note'] ?? null);

        return back()->with($n ? 'success' : 'warning', $n ? 'Credit terms updated.' : 'No changes.');
    }

    public function statement(Company $company)
    {
        if (! $company->email) {
            return back()->with('error', "{$company->name} has no e-mail address – add it under Companies / Agents.");
        }

        return CreditService::sendStatement($company)
            ? back()->with('success', "Statement e-mailed to {$company->email}.")
            : back()->with('error', 'The statement could not be sent – check the mail settings / e-mail log.');
    }

    /** Run the automatic hold / release review now (it also runs in the night audit and at 06:10) and save the policy. */
    public function review(Request $request)
    {
        $data = $request->validate(['credit_auto_hold' => 'nullable|boolean', 'credit_hold_grace_days' => 'nullable|integer|min:0|max:180']);
        if ($request->has('credit_hold_grace_days')) {
            Setting::updateOrCreate(['key' => 'credit_auto_hold'], ['value' => $request->boolean('credit_auto_hold') ? '1' : '0']);
            Setting::updateOrCreate(['key' => 'credit_hold_grace_days'], ['value' => (string) (int) $data['credit_hold_grace_days']]);
            Setting::$version++;
        }
        $r = CreditService::review();
        ActivityLogger::log('credit', 'review', "Credit review: {$r['held']} held, {$r['released']} released", null);

        return back()->with('success', "Credit review done – {$r['held']} put on hold, {$r['released']} released.");
    }
}
