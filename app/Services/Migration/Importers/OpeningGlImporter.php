<?php

namespace App\Services\Migration\Importers;

use App\Models\AccountHead;
use App\Models\GlJournal;
use App\Models\GlLine;
use App\Services\LedgerService;
use App\Services\Migration\BaseImporter;
use App\Services\Migration\ImportContext;
use Throwable;

/**
 * Opening trial-balance preflight. It validates explicit account-code mappings,
 * exact debit/credit balance, the stock control and the proposed GL delta. It
 * deliberately remains preflight-only while AP policy, subledger sign-off, and
 * control-account/duplicate-posting rules are unapproved or incomplete.
 */
class OpeningGlImporter extends BaseImporter
{
    public const SOURCE_KEY = 'OPENING-GL';

    private const MAX_JOURNAL_CENTS = 99999999999999;

    /** @var array<string, AccountHead>|null */
    private ?array $accountsByCode = null;

    /** @var array<string, mixed>|null */
    private ?array $analysis = null;

    private ?string $businessDateCache = null;

    private ?string $cutoffCache = null;

    private bool $lockDateLoaded = false;

    private ?string $lockDateCache = null;

    public function label(): string
    {
        return 'Full opening GL trial balance (preflight only)';
    }

    public function columns(): array
    {
        return [
            'external_id' => ['required' => true, 'desc' => 'Stable legacy account-row id (often the legacy account code)', 'example' => 'LEG-INV-1200', 'aliases' => ['id', 'row_id', 'legacy_id']],
            'source_account_code' => ['required' => false, 'desc' => 'Legacy account code for audit/reference only', 'example' => '1200', 'aliases' => ['legacy_account_code', 'source_code']],
            'source_account_name' => ['required' => false, 'desc' => 'Legacy account name for audit/reference only', 'example' => 'Stores inventory', 'aliases' => ['legacy_account_name', 'source_name']],
            'opening_date' => ['required' => true, 'desc' => 'One as-of/cutover date shared by every row in the trial balance', 'example' => '2026-10-22', 'aliases' => ['as_of_date', 'effective_date', 'date']],
            'account_code' => ['required' => true, 'desc' => 'Exact active Nowills chart-of-accounts code; no automatic account creation or name guessing', 'example' => '1200', 'aliases' => ['nowills_account_code', 'target_account_code']],
            'debit_balance' => ['required' => true, 'desc' => 'Non-negative debit balance in property currency, to two decimal places', 'example' => '0.00', 'aliases' => ['debit', 'dr', 'opening_debit']],
            'credit_balance' => ['required' => true, 'desc' => 'Non-negative credit balance in property currency, to two decimal places', 'example' => '0.00', 'aliases' => ['credit', 'cr', 'opening_credit']],
        ];
    }

    /** A trial balance needs at least two rows, so the template shows a balanced pair. */
    public function templateRows(): array
    {
        $inventoryCode = AccountHead::where('system_key', 'inventory')->value('code') ?: '1200';
        $equityCode = AccountHead::where('system_key', 'opening_equity')->value('code') ?: '3200';
        $date = $this->cutoffDate();

        return [
            ['external_id' => 'EXAMPLE-INV', 'source_account_code' => 'LEG-INV', 'source_account_name' => 'Example inventory', 'opening_date' => $date, 'account_code' => $inventoryCode, 'debit_balance' => '0.00', 'credit_balance' => '0.00'],
            ['external_id' => 'EXAMPLE-OBE', 'source_account_code' => 'LEG-EQUITY', 'source_account_name' => 'Example opening equity', 'opening_date' => $date, 'account_code' => $equityCode, 'debit_balance' => '0.00', 'credit_balance' => '0.00'],
        ];
    }

    public function supportsApply(): bool
    {
        return false;
    }

    public function validateBatch(array $rows, ImportContext $ctx): ?string
    {
        if ($rows === []) {
            return 'The opening trial-balance file has no account rows.';
        }

        $ids = [];
        $dates = [];
        foreach ($rows as $row) {
            $externalId = $this->externalId($row['external_id'] ?? null);
            if ($externalId !== null) {
                if (isset($ids[$externalId])) {
                    return "external_id {$externalId} appears more than once; every source account row must have a unique stable id.";
                }
                $ids[$externalId] = true;
            }
            $date = $this->date($row['opening_date'] ?? null);
            if ($date !== null) {
                $dates[$date] = true;
            }
        }

        if (count($dates) > 1) {
            return 'Use one opening_date for the entire trial balance so the opening position is measured at a single cutover point.';
        }

        // This is an atomic financial snapshot: one invalid row blocks the whole file.
        foreach ($rows as $index => $row) {
            $error = $this->validateRow($row, $ctx, $index + 2);
            if ($error !== null) {
                return 'Trial-balance row '.($index + 2).': '.$error;
            }
        }

        $date = $this->date($rows[0]['opening_date'] ?? null);
        if ($date === null) {
            return 'opening_date must be a valid date.';
        }

        $sourceDebitCents = 0;
        $sourceCreditCents = 0;
        $sourceByAccount = [];
        foreach ($rows as $row) {
            $account = $this->accountForCode((string) ($row['account_code'] ?? ''));
            $debit = $this->moneyCents($row['debit_balance'] ?? null);
            $credit = $this->moneyCents($row['credit_balance'] ?? null);
            if (! $account || $debit === null || $credit === null) {
                return 'The trial-balance file could not be aggregated after row validation; re-check the account mapping and amounts.';
            }
            if ($sourceDebitCents > self::MAX_JOURNAL_CENTS - $debit || $sourceCreditCents > self::MAX_JOURNAL_CENTS - $credit) {
                return 'The trial-balance total exceeds the general-ledger journal limit.';
            }
            $sourceDebitCents += $debit;
            $sourceCreditCents += $credit;
            $net = ($sourceByAccount[$account->id] ?? 0) + $debit - $credit;
            if (abs($net) > self::MAX_JOURNAL_CENTS) {
                return "The combined balance mapped to account code {$account->code} exceeds the general-ledger line limit.";
            }
            $sourceByAccount[$account->id] = $net;
        }

        if ($sourceDebitCents !== $sourceCreditCents) {
            return 'The source trial balance is not balanced: debits are '.number_format($sourceDebitCents / 100, 2, '.', ',').', credits are '.number_format($sourceCreditCents / 100, 2, '.', ',').'. No automatic Opening Balance Equity plug is permitted.';
        }

        try {
            $this->analysis = $this->buildAnalysis($date, $sourceDebitCents, $sourceCreditCents, $sourceByAccount);
        } catch (Throwable $e) {
            $this->analysis = null;

            return 'The opening trial-balance preflight could not complete: '.$e->getMessage();
        }

        if (! empty($this->analysis['blocking_error'])) {
            $error = $this->analysis['blocking_error'];
            $this->analysis = null;

            return $error;
        }

        return null;
    }

    public function validateRow(array $row, ImportContext $ctx, int $rowNo): ?string
    {
        if ($this->externalId($row['external_id'] ?? null) === null) {
            return 'external_id is required and must be a stable legacy account-row id.';
        }

        $date = $this->date($row['opening_date'] ?? null);
        if ($date === null) {
            return 'opening_date must be a valid date.';
        }
        if ($date < '2000-01-01') {
            return 'opening_date must be on or after 2000-01-01.';
        }
        if ($date > $this->businessDate()) {
            return 'opening_date cannot be after the property business date.';
        }
        $cutoff = $this->cutoffDate();
        if ($date > $cutoff) {
            return "opening_date must be on or before the closed-ledger cutoff ({$cutoff}).";
        }
        $lockDate = $this->lockDate();
        if ($lockDate && $date <= $lockDate) {
            return "opening_date must be after the GL lock date ({$lockDate}); the historical period cannot be edited.";
        }

        $code = trim((string) ($row['account_code'] ?? ''));
        if ($code === '') {
            return 'account_code is required; map each legacy account to an exact Nowills chart-of-accounts code.';
        }
        if (mb_strlen($code) > 12) {
            return 'account_code must be no longer than 12 characters.';
        }
        $account = $this->accountForCode($code);
        if (! $account) {
            return "Nowills account_code {$code} was not found in this property; assign the mapping to an existing chart account code.";
        }
        if (! $account->is_active) {
            return "Nowills account_code {$code} ({$account->name}) is inactive; activate it or map the source account to an active head.";
        }

        $debit = $this->moneyCents($row['debit_balance'] ?? null);
        if ($debit === null) {
            return 'debit_balance must be a non-negative amount with no more than two decimal places.';
        }
        $credit = $this->moneyCents($row['credit_balance'] ?? null);
        if ($credit === null) {
            return 'credit_balance must be a non-negative amount with no more than two decimal places.';
        }
        if ($debit > 0 && $credit > 0) {
            return 'Enter a balance on either debit_balance or credit_balance for this row, not both.';
        }

        return null;
    }

    public function importRow(array $row, ImportContext $ctx, bool $dryRun): array
    {
        if (! $dryRun) {
            throw new \LogicException('Opening GL is preflight-only; posting stays disabled pending AP-policy approval, subledger sign-off, and duplicate-posting controls.');
        }

        return ['status' => 'created', 'message' => 'Preflight only — no GL journal or opening balance was posted.', 'model_type' => null, 'model_id' => null];
    }

    public function reconcile(ImportContext $ctx): array
    {
        $rows = $ctx->batch->rows()->orderBy('row_no')->get()
            ->map(fn ($row) => (array) $row->payload)->all();
        $error = $this->validateBatch($rows, $ctx);
        if ($error !== null || ! $this->analysis) {
            return [
                'Preflight status' => 'BLOCKED — '.($error ?: 'the trial-balance analysis is incomplete.'),
                'Opening GL posting readiness' => 'BLOCKED — full opening-GL apply remains disabled pending AP-policy approval, subledger sign-off, and duplicate-posting controls.',
                'Posting status' => 'Not posted — resolve the preflight blocker; no opening-GL journal exists.'
            ];
        }

        $a = $this->analysis;
        $pending = $a['pending_controls'];
        $strictPending = array_values(array_filter($pending, fn ($control) => ! empty($control['strict'])));
        $otherPending = array_values(array_filter($pending, fn ($control) => empty($control['strict'])));
        $report = [
            'Preflight status' => 'PASS — source balanced, account mapping valid, inventory reconciles; no posting occurred',
            'Strict subledger control reconciliation' => $strictPending === []
                ? 'PASS — all strict subledger controls match their source trial-balance accounts.'
                : 'BLOCKED — '.count($strictPending).' strict subledger control variance(s) require resolution before posting.',
            'Other subledger control variances' => count($otherPending),
            'Opening GL posting readiness' => 'BLOCKED — apply remains disabled pending AP-policy approval, all required subledger sign-offs, and duplicate-posting controls.',
            'Opening date' => $a['date'],
            'Source account rows' => count($rows),
            'Mapped Nowills accounts' => count($a['source_by_account']),
            'Accounts with proposed adjustment' => count($a['delta_by_account']),
            'Source trial-balance debits' => $this->money($a['source_debit_cents']),
            'Source trial-balance credits' => $this->money($a['source_credit_cents']),
            'Current GL baseline debits (excluding any prior OPENING-GL journal)' => $this->money($a['baseline_debit_cents']),
            'Current GL baseline credits (excluding any prior OPENING-GL journal)' => $this->money($a['baseline_credit_cents']),
            'Provisional adjustment debit' => $this->money($a['delta_debit_cents']),
            'Provisional adjustment credit' => $this->money($a['delta_credit_cents']),
            'Inventory provisional GL adjustment' => $this->signedMoney($a['inventory_delta_cents']),
            'Opening-equity provisional GL adjustment' => $this->signedMoney($a['opening_equity_delta_cents']),
            'Inventory in source trial balance' => $this->money($a['inventory_source_cents']),
            'Inventory subledger valuation at opening date' => $this->money($a['inventory_subledger_cents']),
            'Inventory reconciliation' => 'PASS — exact to the cent',
            'Subledger controls with current variance' => count($pending),
            'Posting status' => 'Not posted — opening-GL apply is disabled; the source adjustment is only a preflight projection.'
        ];
        foreach ($a['strict_controls'] as $control) {
            $status = $control['difference'] === 0 ? 'MATCH' : 'BLOCKED';
            $report['Strict control: '.$control['label']] = $status.' — source '.$this->money($control['source'])
                .'; current Nowills subledger '.$this->money($control['subledger'])
                .'; difference '.$this->money($control['difference']).'.';
        }
        foreach ($a['account_checks'] as $account) {
            $label = $account['code'].' — '.$account['name'];
            $report['Provisional account adjustment: '.$label] = 'Source balance '.$this->signedMoney($account['source'])
                .'; current GL baseline '.$this->signedMoney($account['baseline'])
                .'; proposed adjustment '.$this->signedMoney($account['delta']).'.';
        }
        if ($pending === []) {
            $report['Other subledger control review'] = 'No variance currently visible; final subledger sign-off is still pending.';
        } else {
            foreach ($pending as $control) {
                $report['Pending control: '.$control['label']] = 'Source natural balance '.$this->money($control['source']).'; current Nowills subledger '.$this->money($control['subledger']).'; difference '.$this->money($control['difference']).'.';
            }
        }

        return $report;
    }

    /** @param array<int, int> $sourceByAccount signed debit-minus-credit cents */
    private function buildAnalysis(string $date, int $sourceDebitCents, int $sourceCreditCents, array $sourceByAccount): array
    {
        $inventory = AccountHead::where('system_key', 'inventory')->first();
        if (! $inventory) {
            return ['blocking_error' => 'The system Inventory account is missing; repair the chart of accounts before opening-balance preflight.'];
        }

        $controls = LedgerService::controls($date);
        $inventoryControl = collect($controls)->firstWhere('key', 'inventory');
        if (! $inventoryControl) {
            return ['blocking_error' => 'The inventory subledger control could not be calculated for the opening date.'];
        }
        $inventorySourceCents = $sourceByAccount[$inventory->id] ?? 0;
        $inventorySubledgerCents = (int) round((float) $inventoryControl['sub'] * 100);
        if ($inventorySourceCents !== $inventorySubledgerCents) {
            return ['blocking_error' => 'Inventory does not reconcile: the trial balance maps '.number_format($inventorySourceCents / 100, 2, '.', ',')." to Nowills Inventory, while the stock subledger is ".number_format($inventorySubledgerCents / 100, 2, '.', ',')." at {$date}. Correct the mapping or opening-stock valuation before continuing."];
        }

        $excludedIds = GlJournal::where('source_type', 'manual')->where('source_key', self::SOURCE_KEY)->pluck('id')->all();
        $totals = GlLine::query()->whereDate('date', '<=', $date)
            ->when($excludedIds !== [], fn ($q) => $q->whereNotIn('gl_journal_id', $excludedIds))
            ->groupBy('account_head_id')
            ->selectRaw('account_head_id, SUM(debit) as dr, SUM(credit) as cr')
            ->get();

        $baselineByAccount = [];
        foreach ($totals as $total) {
            $net = (int) round(((float) $total->dr - (float) $total->cr) * 100);
            if ($net !== 0) {
                $baselineByAccount[(int) $total->account_head_id] = $net;
            }
        }
        if (array_sum($baselineByAccount) !== 0) {
            return ['blocking_error' => 'The existing Nowills GL baseline is not balanced as of the opening date. Resolve the current-ledger difference first; no automatic plug is permitted.'];
        }

        $allAccountIds = array_unique(array_merge(array_keys($baselineByAccount), array_keys($sourceByAccount)));
        $heads = AccountHead::whereIn('id', $allAccountIds)->get()->keyBy('id');
        $deltaByAccount = [];
        $accountChecks = [];
        $deltaDebitCents = 0;
        $deltaCreditCents = 0;
        foreach ($allAccountIds as $accountId) {
            $head = $heads->get($accountId);
            if (! $head) {
                return ['blocking_error' => "Existing GL account id {$accountId} no longer resolves to a chart-of-accounts head."];
            }
            $sourceNet = $sourceByAccount[$accountId] ?? 0;
            $baselineNet = $baselineByAccount[$accountId] ?? 0;
            $delta = $sourceNet - $baselineNet;
            $accountChecks[] = [
                'code' => (string) $head->code,
                'name' => (string) $head->name,
                'source' => $sourceNet,
                'baseline' => $baselineNet,
                'delta' => $delta,
            ];
            if ($delta === 0) {
                continue;
            }
            if (! $head->is_active) {
                return ['blocking_error' => "Existing GL account {$head->label} has a balance but is inactive; resolve the chart mapping before a full opening balance can be posted."];
            }
            if (! $head->code) {
                return ['blocking_error' => "Existing GL account {$head->name} has a balance but no account_code; assign a code before trial-balance posting."];
            }
            if (abs($delta) > self::MAX_JOURNAL_CENTS) {
                return ['blocking_error' => "The proposed adjustment for account {$head->label} exceeds the general-ledger line limit."];
            }
            if ($delta > 0) {
                if ($deltaDebitCents > self::MAX_JOURNAL_CENTS - $delta) {
                    return ['blocking_error' => 'The provisional adjustment exceeds the general-ledger journal limit.'];
                }
                $deltaDebitCents += $delta;
            } else {
                $credit = abs($delta);
                if ($deltaCreditCents > self::MAX_JOURNAL_CENTS - $credit) {
                    return ['blocking_error' => 'The provisional adjustment exceeds the general-ledger journal limit.'];
                }
                $deltaCreditCents += $credit;
            }
            $deltaByAccount[$accountId] = $delta;
        }
        if ($deltaDebitCents !== $deltaCreditCents) {
            return ['blocking_error' => 'The provisional adjustment is not balanced against the existing GL baseline. Investigate the baseline or source mapping; no Opening Balance Equity plug is allowed.'];
        }

        $pendingControls = [];
        $controlChecks = [];
        foreach ($controls as $control) {
            if (($control['key'] ?? null) === 'inventory') {
                continue;
            }
            $head = AccountHead::where('system_key', $control['key'])->first();
            if (! $head) {
                if (! empty($control['strict'])) {
                    return ['blocking_error' => "The strict subledger control account for {$control['label']} is missing; repair the chart of accounts before opening-GL preflight."];
                }
                continue;
            }
            $sourceNet = $sourceByAccount[$head->id] ?? 0;
            // Accumulated depreciation is a contra-asset: type=asset but credit-nature. ChartOfAccounts
            // classifies it as asset, so isDebitNature() is true, yet the control subledger ($fa[1]) and the
            // LedgerService::controls() gl field (-balance()) both treat it as credit-positive. Negate the
            // debit-minus-credit source net so the natural sign matches the subledger for this account.
            $creditNature = ! $head->isDebitNature() || $head->system_key === 'acc_depreciation';
            $sourceNatural = $creditNature ? -$sourceNet : $sourceNet;
            $subledger = (int) round((float) $control['sub'] * 100);
            $difference = $sourceNatural - $subledger;
            $check = [
                'label' => $control['label'],
                'source' => $sourceNatural,
                'subledger' => $subledger,
                'difference' => $difference,
                'strict' => (bool) ($control['strict'] ?? false),
            ];
            $controlChecks[] = $check;
            if ($difference !== 0) {
                $pendingControls[] = $check;
            }
        }

        $baselineDebitCents = 0;
        $baselineCreditCents = 0;
        foreach ($baselineByAccount as $net) {
            $net > 0 ? $baselineDebitCents += $net : $baselineCreditCents += abs($net);
        }
        $openingEquityId = (int) AccountHead::where('system_key', 'opening_equity')->value('id');

        return [
            'date' => $date,
            'source_debit_cents' => $sourceDebitCents,
            'source_credit_cents' => $sourceCreditCents,
            'source_by_account' => $sourceByAccount,
            'baseline_by_account' => $baselineByAccount,
            'account_checks' => $accountChecks,
            'baseline_debit_cents' => $baselineDebitCents,
            'baseline_credit_cents' => $baselineCreditCents,
            'delta_by_account' => $deltaByAccount,
            'inventory_delta_cents' => $deltaByAccount[$inventory->id] ?? 0,
            'opening_equity_delta_cents' => $openingEquityId ? ($deltaByAccount[$openingEquityId] ?? 0) : 0,
            'delta_debit_cents' => $deltaDebitCents,
            'delta_credit_cents' => $deltaCreditCents,
            'inventory_source_cents' => $inventorySourceCents,
            'inventory_subledger_cents' => $inventorySubledgerCents,
            'strict_controls' => array_values(array_filter($controlChecks, fn ($control) => $control['strict'])),
            'pending_controls' => $pendingControls,
        ];
    }

    private function accountForCode(string $code): ?AccountHead
    {
        if ($this->accountsByCode === null) {
            $this->accountsByCode = AccountHead::whereNotNull('code')->get()->keyBy('code')->all();
        }

        return $this->accountsByCode[$code] ?? null;
    }

    private function businessDate(): string
    {
        return $this->businessDateCache ??= bdate()->toDateString();
    }

    private function cutoffDate(): string
    {
        return $this->cutoffCache ??= LedgerService::cutoff();
    }

    private function lockDate(): ?string
    {
        if (! $this->lockDateLoaded) {
            $this->lockDateCache = LedgerService::lockDate();
            $this->lockDateLoaded = true;
        }

        return $this->lockDateCache;
    }

    /** Parse non-negative property-currency money into integer cents; blank cells mean zero. */
    private function moneyCents(?string $raw): ?int
    {
        $value = trim((string) $raw);
        if ($value === '') {
            return 0;
        }
        $value = str_ireplace(['GH₵', 'GHS', '₵', '¢', '$', '£', '€'], '', $value);
        $value = preg_replace('/\s+/', '', $value) ?? '';
        if (! preg_match('/^(?:\d+|\d{1,3}(?:,\d{3})+)(?:\.\d{1,2})?$/', $value)) {
            return null;
        }
        $value = str_replace(',', '', $value);
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $whole = ltrim($whole, '0') ?: '0';
        if (strlen($whole) > 12) {
            return null;
        }
        $wholeCents = (int) $whole * 100;
        $fractionCents = (int) str_pad($fraction, 2, '0');
        $cents = $wholeCents + $fractionCents;

        return $cents <= self::MAX_JOURNAL_CENTS ? $cents : null;
    }

    private function money(int $cents): string
    {
        return number_format($cents / 100, 2, '.', ',');
    }

    private function signedMoney(int $cents): string
    {
        return ($cents < 0 ? 'Cr ' : 'Dr ').$this->money(abs($cents));
    }
}
