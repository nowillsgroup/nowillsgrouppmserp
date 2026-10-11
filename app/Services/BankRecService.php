<?php

namespace App\Services;

use App\Models\AccountHead;
use App\Models\BankMatch;
use App\Models\BankStatement;
use App\Models\BankStatementLine;
use App\Models\GlJournal;
use App\Models\GlLine;
use App\Models\Setting;
use App\Support\Numbering;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * v4.6 Bank & Mobile Money reconciliation.
 *
 * A statement (CSV import or typed in) for one Cash & Bank account is matched line by line to that account's general-ledger
 * lines: automatically by reference, by amount and date, or a day's collections settled as one credit (card / MoMo batch);
 * by hand (one statement line ↔ one or more book lines); charges, interest and MoMo fees are booked straight from the line;
 * lines the books must not carry are ignored with a reason. The reconciliation statement as at the period end is exact:
 *
 *     statement closing + book items not on the statement − statement items not in the books − opening difference = book balance
 *
 * Book lines only count from the account's first statement (earlier history is the opening balance). Statements follow on:
 * one draft per account, the next starts after the last one ends and opens with its closing balance. When a source document
 * is amended the ledger reverses and reposts its journal – matches follow the replacement line ("heal"), or fall back to
 * unmatched with a note if the amount changed.
 */
class BankRecService
{
    public const DEFAULTS = ['bankrec_window_days' => '5', 'bankrec_stale_days' => '35', 'bankrec_segregation' => '0'];

    public const ENTRY_KINDS = [
        'bank_charges' => ['Bank charges / commission', 'bank_charges'],
        'momo_fees' => ['Mobile Money charges', 'momo_fees'],
        'interest' => ['Interest earned', 'interest_income'],
        'other' => ['Other – choose the account', null],
    ];

    /** CSV header synonyms (lower-case, spaces / punctuation collapsed). */
    public const HEADERS = [
        'date' => ['date', 'transaction date', 'trans date', 'txn date', 'posting date', 'post date', 'value date', 'date time', 'transaction time', 'datetime', 'timestamp'],
        'description' => ['description', 'narration', 'details', 'particulars', 'transaction details', 'remarks', 'memo', 'transaction type', 'type', 'from name', 'to name'],
        'reference' => ['reference', 'ref', 'ref no', 'reference no', 'reference number', 'transaction id', 'txn id', 'trans id', 'external transaction id', 'financial transaction id', 'cheque no', 'cheque number', 'instrument no'],
        'amount' => ['amount', 'transaction amount', 'amount ghs', 'value'],
        'debit' => ['debit', 'debits', 'withdrawal', 'withdrawals', 'money out', 'paid out', 'dr', 'debit amount'],
        'credit' => ['credit', 'credits', 'deposit', 'deposits', 'lodgement', 'lodgements', 'money in', 'paid in', 'cr', 'credit amount'],
        'balance' => ['balance', 'running balance', 'closing balance', 'book balance', 'ledger balance', 'available balance', 'balance ghs'],
        'fee' => ['fee', 'fees', 'charges', 'charge', 'commission'],
    ];

    public const DATE_FORMATS = ['auto' => 'Detect', 'd/m/Y' => 'DD/MM/YYYY', 'm/d/Y' => 'MM/DD/YYYY', 'Y-m-d' => 'YYYY-MM-DD'];

    public static function cfg(string $k): string
    {
        return (string) setting($k, self::DEFAULTS[$k]);
    }

    public static function ensureDefaults(): void
    {
        foreach (self::DEFAULTS as $k => $v) {
            Setting::firstOrCreate(['key' => $k], ['value' => $v]);
        }
    }

    // ─────────────────────────── accounts & balances ───────────────────────────

    /** Accounts that can be reconciled: the Cash & Bank group except cash in hand. */
    public static function accounts(): Collection
    {
        return AccountHead::where('type', 'asset')->where('is_active', true)->where('group_name', 'Cash & Bank')
            ->where(fn ($q) => $q->whereNull('system_key')->orWhereNotIn('system_key', ['cash', 'forex_on_hand', 'cheques_in_hand', 'cash_in_transit']))->orderBy('code')->get();
    }

    public static function isReconcilable(int $accountId): bool
    {
        return self::accounts()->contains('id', $accountId);
    }

    public static function glBalance(int $accountId, string $asOf): float
    {
        return round((float) GlLine::where('account_head_id', $accountId)->whereDate('date', '<=', $asOf)->sum(DB::raw('debit - credit')), 2);
    }

    public static function startDate(int $accountId): ?string
    {
        $d = BankStatement::where('account_head_id', $accountId)->min('period_from');

        return $d ? Carbon::parse($d)->toDateString() : null;
    }

    public static function last(int $accountId): ?BankStatement
    {
        return BankStatement::where('account_head_id', $accountId)->orderByDesc('period_to')->orderByDesc('id')->first();
    }

    /** What the next statement for the account should start with: [period_from, opening balance]. */
    public static function nextStart(int $accountId): array
    {
        if ($l = self::last($accountId)) {
            return [$l->period_to->copy()->addDay()->toDateString(), (float) $l->closing_balance];
        }
        $from = bdate()->copy()->subMonthNoOverflow()->startOfMonth();

        return [$from->toDateString(), self::glBalance($accountId, $from->copy()->subDay()->toDateString())];
    }

    // ─────────────────────────── statements ───────────────────────────

    /** Validate the header of a new statement against the account's history. */
    protected static function assertHeader(int $accountId, string $from, string $to, float $opening): void
    {
        if (! self::isReconcilable($accountId)) {
            throw new RuntimeException('Choose a bank, Mobile Money or card settlement account.');
        }
        if ($to < $from) {
            throw new RuntimeException('The statement ends before it starts.');
        }
        if ($to > bdate()->toDateString()) {
            throw new RuntimeException('The statement cannot end after today.');
        }
        if (BankStatement::where('account_head_id', $accountId)->where('status', 'draft')->exists()) {
            throw new RuntimeException('Finish the statement already in progress for this account first.');
        }
        if ($l = self::last($accountId)) {
            if ($from <= $l->period_to->toDateString()) {
                throw new RuntimeException('The statement must start after '.dmy($l->period_to).', the end of '.$l->statement_no.'.');
            }
            if (abs($opening - (float) $l->closing_balance) > 0.005) {
                throw new RuntimeException('The opening balance must equal the closing balance of '.$l->statement_no.' ('.money($l->closing_balance).').');
            }
        }
    }

    /**
     * Create a statement with its lines. $lines: [['date','description','reference','amount','balance'?], …].
     * A null closing balance is computed from the lines.
     */
    public static function create(int $accountId, string $from, string $to, float $opening, ?float $closing, array $lines, string $source = 'manual', ?string $file = null, ?string $notes = null): BankStatement
    {
        self::assertHeader($accountId, $from, $to, $opening);
        $sum = round(array_sum(array_map(fn ($l) => (float) $l['amount'], $lines)), 2);
        $closing ??= round($opening + $sum, 2);
        foreach ($lines as $i => $l) {
            if ($l['date'] < $from || $l['date'] > $to) {
                throw new RuntimeException('Line '.($i + 1).' ('.dmy($l['date']).') falls outside the statement period.');
            }
        }

        return DB::transaction(function () use ($accountId, $from, $to, $opening, $closing, $lines, $source, $file, $notes) {
            $s = BankStatement::create(['statement_no' => Numbering::next('bank_stmt'), 'account_head_id' => $accountId, 'period_from' => $from, 'period_to' => $to,
                'opening_balance' => $opening, 'closing_balance' => $closing, 'source' => $source, 'file_name' => $file, 'notes' => $notes, 'created_by' => auth()->id()]);
            foreach (array_values($lines) as $i => $l) {
                self::insertLine($s, $l, $i + 1);
            }
            ActivityLogger::log('bankrec', 'statement', "Statement {$s->statement_no} ({$s->label()}) for {$s->account->name}: ".count($lines).' line(s), closing '.money($closing), $s);

            return $s;
        });
    }

    protected static function hashOf(int $accountId, array $l): string
    {
        return md5($accountId.'|'.$l['date'].'|'.number_format((float) $l['amount'], 2, '.', '').'|'.mb_strtolower(trim((string) ($l['reference'] ?? ''))).'|'.mb_strtolower(trim((string) ($l['description'] ?? ''))));
    }

    protected static function insertLine(BankStatement $s, array $l, int $no): BankStatementLine
    {
        return BankStatementLine::create(['bank_statement_id' => $s->id, 'line_no' => $no, 'date' => $l['date'], 'description' => mb_substr(trim((string) ($l['description'] ?? '')), 0, 255) ?: null,
            'reference' => mb_substr(trim((string) ($l['reference'] ?? '')), 0, 80) ?: null, 'amount' => round((float) $l['amount'], 2),
            'balance' => isset($l['balance']) && $l['balance'] !== null && $l['balance'] !== '' ? round((float) $l['balance'], 2) : null, 'hash' => self::hashOf($s->account_head_id, $l)]);
    }

    public static function addLine(BankStatement $s, array $l): BankStatementLine
    {
        self::assertDraft($s);
        if ($l['date'] < $s->period_from->toDateString() || $l['date'] > $s->period_to->toDateString()) {
            throw new RuntimeException('The date falls outside the statement period.');
        }
        if (round((float) $l['amount'], 2) == 0.0) {
            throw new RuntimeException('Enter an amount (+ money in, − money out).');
        }
        $line = self::insertLine($s, $l, (int) $s->lines()->max('line_no') + 1);
        ActivityLogger::log('bankrec', 'line_add', "Line added to {$s->statement_no}: ".money($line->amount).' '.($line->description ?? ''), $s);

        return $line;
    }

    public static function deleteLine(BankStatementLine $line): void
    {
        self::assertDraft($line->statement);
        if ($line->status === 'matched') {
            self::unmatch($line);
        }
        $line->delete();
    }

    public static function updateBalances(BankStatement $s, float $closing): void
    {
        self::assertDraft($s);
        $s->update(['closing_balance' => round($closing, 2)]);
    }

    public static function destroy(BankStatement $s): void
    {
        self::assertDraft($s);
        DB::transaction(function () use ($s) {
            foreach ($s->lines()->where('status', 'matched')->get() as $l) {
                self::unmatch($l);
            }
            ActivityLogger::log('bankrec', 'statement_delete', "Statement {$s->statement_no} ({$s->label()}) deleted", $s);
            $s->delete();
        });
    }

    protected static function assertDraft(BankStatement $s): void
    {
        if (! $s->isDraft()) {
            throw new RuntimeException("{$s->statement_no} is reconciled – reopen it first.");
        }
    }

    // ─────────────────────────── CSV import ───────────────────────────

    public static function norm(string $h): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', ' ', mb_strtolower(str_replace(['₵', '(', ')'], ' ', $h))));
    }

    /** Read CSV text into [headers, rows] (delimiter , ; tab or |, BOM stripped, first non-empty row = headers). */
    public static function readCsv(string $text, ?string $dateHeader = null): array
    {
        $text = preg_replace('/^\xEF\xBB\xBF/', '', str_replace(["\r\n", "\r"], "\n", $text));
        $first = implode("\n", array_slice(explode("\n", $text), 0, 10)); // sample: banks put a title / account block above the table
        $delims = [',' => substr_count($first, ','), ';' => substr_count($first, ';'), "\t" => substr_count($first, "\t"), '|' => substr_count($first, '|')];
        arsort($delims);
        $d = array_key_first($delims);
        $rows = [];
        $fh = fopen('php://temp', 'r+');
        fwrite($fh, $text);
        rewind($fh);
        while (($r = fgetcsv($fh, 0, $d, '"', '')) !== false) {
            if ($r === [null] || implode('', array_map('trim', $r)) === '') {
                continue;
            }
            $rows[] = array_map(fn ($v) => trim((string) $v), $r);
        }
        fclose($fh);
        // header = the first row that names a date column (banks often put account details above the table)
        foreach ($rows as $i => $r) {
            if (collect($r)->contains(fn ($h) => in_array(self::norm($h), $dateHeader ? [self::norm($dateHeader)] : self::HEADERS['date'], true))) {
                return [$r, array_slice($rows, $i + 1)];
            }
        }

        return [$rows[0] ?? [], array_slice($rows, 1)];
    }

    /** Column index per role, from overrides (header names typed by the user) or synonyms. */
    public static function detect(array $headers, array $override = []): array
    {
        $n = array_map(fn ($h) => self::norm($h), $headers);
        $map = [];
        foreach (self::HEADERS as $role => $syn) {
            if (! empty($override[$role])) {
                $i = array_search(self::norm($override[$role]), $n, true);
                if ($i === false) {
                    throw new RuntimeException("No column called “{$override[$role]}” in the file (columns: ".implode(', ', $headers).').');
                }
                $map[$role] = $i;

                continue;
            }
            foreach ($syn as $s) {
                $i = array_search($s, $n, true);
                if ($i !== false && ! in_array($i, $map, true)) {
                    $map[$role] = $i;
                    break;
                }
            }
        }
        if (! isset($map['date'])) {
            throw new RuntimeException('No date column found (columns: '.implode(', ', $headers).'). Type its name under “Column names”.');
        }
        if (! isset($map['amount']) && ! isset($map['debit']) && ! isset($map['credit'])) {
            throw new RuntimeException('No amount or debit / credit columns found (columns: '.implode(', ', $headers).').');
        }
        if (isset($map['amount']) && (isset($map['debit']) || isset($map['credit'])) && empty($override['amount'])) {
            unset($map['amount']); // prefer the explicit money-in / money-out columns
        }

        return $map;
    }

    /** "1,234.50", "(1,234.50)", "-1234.5", "GH₵ 1,234.50 DR", "1.234,50" → float|null */
    public static function parseAmount(?string $v): ?float
    {
        $v = trim((string) $v);
        if ($v === '' || $v === '-') {
            return null;
        }
        $neg = false;
        if (preg_match('/\((.*)\)/', $v, $m)) {
            $neg = true;
            $v = $m[1];
        }
        if (preg_match('/\b(DR|D)\s*$/i', $v)) {
            $neg = true;
        }
        $v = preg_replace('/\s*(DR|CR|D|C)\s*$/i', '', $v);
        $v = str_ireplace(['GH₵', 'GHS', 'GHC', '₵', 'GH¢', '¢', ' ', "\u{00A0}"], '', $v);
        if (str_starts_with($v, '-')) {
            $neg = ! $neg;
            $v = substr($v, 1);
        }
        $v = ltrim($v, '+');
        if (preg_match('/^\d{1,3}(\.\d{3})+(,\d{1,2})?$/', $v) || preg_match('/^\d+,\d{1,2}$/', $v)) { // 1.234,50 · 750,00 (decimal comma)
            $v = str_replace(['.', ','], ['', '.'], $v);
        }
        $v = str_replace(',', '', $v);
        if (! is_numeric($v)) {
            return null;
        }

        return round(($neg ? -1 : 1) * (float) $v, 2);
    }

    public static function parseDate(?string $v, string $fmt = 'auto'): ?string
    {
        $v = trim((string) $v);
        if ($v === '') {
            return null;
        }
        $v = preg_replace('/[ T]\d{1,2}:\d{2}(:\d{2})?(\.\d+)?\s*(AM|PM)?$/i', '', $v); // drop the time
        $formats = $fmt === 'auto' ? ['Y-m-d', 'd/m/Y', 'd-m-Y', 'd.m.Y', 'd-M-Y', 'd M Y', 'd-M-y', 'd/m/y', 'd M, Y', 'M d, Y', 'Y/m/d', 'Ymd'] : [$fmt];
        foreach ($formats as $f) {
            $d = \DateTime::createFromFormat('!'.$f, $v);
            $e = \DateTime::getLastErrors();
            if ($d && (! $e || ($e['warning_count'] === 0 && $e['error_count'] === 0)) && (int) $d->format('Y') >= 2000 && (int) $d->format('Y') <= 2100) {
                return $d->format('Y-m-d');
            }
        }

        return null;
    }

    /**
     * Parse CSV text into statement lines. Returns [lines, skipped (summary rows), duplicates, warnings].
     * Rows without a date (opening / closing balance rows, totals) are skipped. A fee column adds a separate − line.
     */
    public static function parse(int $accountId, string $text, array $override = [], string $dateFmt = 'auto', bool $invert = false): array
    {
        [$headers, $rows] = self::readCsv($text, ! empty($override['date']) ? $override['date'] : null);
        if (! $rows) {
            throw new RuntimeException('The file has no rows.');
        }
        $map = self::detect($headers, $override);
        $lines = [];
        $skipped = 0;
        $warn = [];
        foreach ($rows as $i => $r) {
            $get = fn ($role) => isset($map[$role]) ? ($r[$map[$role]] ?? '') : '';
            $desc = $get('description');
            if (preg_match('/^(opening|closing|brought|carried)\b|balance\s*(b\/f|c\/f|brought|carried)|^total/i', $desc) || preg_match('/^(opening|closing) balance/i', $get('date'))) {
                $skipped++;

                continue;
            }
            $date = self::parseDate($get('date'), $dateFmt);
            if (! $date) {
                if (trim($get('date')) !== '') {
                    $warn[] = 'Row '.($i + 2).": date “{$get('date')}” not understood – skipped.";
                }
                $skipped++;

                continue;
            }
            if (isset($map['amount'])) {
                $amt = self::parseAmount($get('amount'));
            } else {
                $cr = self::parseAmount($get('credit')) ?? 0.0;
                $dr = self::parseAmount($get('debit')) ?? 0.0;
                $amt = round(abs($cr) - abs($dr), 2);
            }
            if ($amt === null || $amt == 0.0) {
                $skipped++;

                continue;
            }
            $amt = $invert ? -$amt : $amt;
            $bal = isset($map['balance']) ? self::parseAmount($get('balance')) : null;
            $fee = isset($map['fee']) ? self::parseAmount($get('fee')) : null;
            // the running balance of a MoMo export is after the fee: it belongs to the fee line
            $lines[] = ['date' => $date, 'description' => $desc, 'reference' => $get('reference'), 'amount' => $amt, 'balance' => $fee ? null : $bal];
            if ($fee) {
                $lines[] = ['date' => $date, 'description' => 'Fee – '.($desc ?: 'transaction'), 'reference' => $get('reference'), 'amount' => -abs($fee), 'balance' => $bal];
            }
        }
        if (! $lines) {
            throw new RuntimeException('No transactions found in the file.');
        }
        usort($lines, fn ($a, $b) => strcmp($a['date'], $b['date']));
        // duplicate guard against statements already loaded for the account
        $known = \App\Models\BankStatementLine::whereIn('bank_statement_id', BankStatement::where('account_head_id', $accountId)->select('id'))->pluck('hash')->flip();
        $dups = 0;
        $lines = array_values(array_filter($lines, function ($l) use ($known, $accountId, &$dups) {
            if (isset($known[self::hashOf($accountId, $l)])) {
                $dups++;

                return false;
            }

            return true;
        }));

        return [$lines, $skipped, $dups, $warn];
    }

    /** Check a running-balance column against the opening balance; returns the first mismatch as text, or null. */
    public static function checkRunningBalance(array $lines, float $opening): ?string
    {
        $run = $opening;
        foreach ($lines as $i => $l) {
            $run = round($run + $l['amount'], 2);
            if ($l['balance'] !== null && abs($run - $l['balance']) > 0.01) {
                return 'Line '.($i + 1).' ('.dmy($l['date']).', '.money($l['amount']).'): the running balance should be '.money($run).' but the file says '.money($l['balance']).'.';
            }
        }

        return null;
    }

    public static function import(int $accountId, string $from, string $to, float $opening, ?float $closing, string $text, string $file, array $override = [], string $dateFmt = 'auto', bool $invert = false): array
    {
        [$lines, $skipped, $dups, $warn] = self::parse($accountId, $text, $override, $dateFmt, $invert);
        $outside = array_filter($lines, fn ($l) => $l['date'] < $from || $l['date'] > $to);
        if ($outside) {
            throw new RuntimeException(count($outside).' transaction(s) fall outside '.dmy($from).' – '.dmy($to).' (first: '.dmy(reset($outside)['date']).'). Adjust the period.');
        }
        if (! $dups && ($err = self::checkRunningBalance($lines, $opening))) {
            throw new RuntimeException($err.' Check the opening balance, or whether money out is shown as positive (tick “Amounts are the bank’s view”).');
        }
        if ($closing !== null && abs(round($opening + array_sum(array_column($lines, 'amount')), 2) - $closing) > 0.005 && ! $dups) {
            throw new RuntimeException('The lines add up to '.money(round($opening + array_sum(array_column($lines, 'amount')), 2)).' but the closing balance is '.money($closing).'.');
        }
        $s = self::create($accountId, $from, $to, $opening, $closing, $lines, 'csv', $file);

        return [$s, count($lines), $skipped, $dups, $warn];
    }

    // ─────────────────────────── matching ───────────────────────────

    /** GL line ids already cleared by any statement line. */
    protected static function clearedIds(): Collection
    {
        return BankMatch::pluck('gl_line_id');
    }

    /**
     * Book lines on the account that can still be matched: from the start date, not cleared, not part of a reversal pair
     * (an amended source's old journal and its reversal cancel out), up to $until.
     */
    public static function candidates(int $accountId, string $until): Collection
    {
        $start = self::startDate($accountId) ?? '1900-01-01';

        return GlLine::with('journal:id,journal_no,date,source_type,source_key,reference,narration,reversal_of_id,reversed_by_id')
            ->where('account_head_id', $accountId)->whereDate('date', '>=', $start)->whereDate('date', '<=', $until)
            ->whereNotIn('id', BankMatch::select('gl_line_id'))
            ->whereHas('journal', fn ($q) => $q->whereNull('reversal_of_id')->whereNull('reversed_by_id'))
            ->orderBy('date')->orderBy('id')->get();
    }

    protected static function signed(GlLine $l): float
    {
        return round((float) $l->debit - (float) $l->credit, 2);
    }

    protected static function refKey(?string $r): ?string
    {
        $r = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $r));

        return strlen($r) >= 4 ? $r : null;
    }

    protected static function link(BankStatementLine $line, array $glIds, string $type): void
    {
        foreach ($glIds as $id) {
            BankMatch::create(['bank_statement_line_id' => $line->id, 'gl_line_id' => $id]);
        }
        $line->forceFill(['status' => 'matched', 'match_type' => $type, 'matched_by' => auth()->id(), 'matched_at' => now(), 'note' => null, 'ignore_reason' => null])->save();
    }

    /** Auto-match a draft statement. Returns ['reference' => n, 'amount' => n, 'group' => n]. */
    public static function autoMatch(BankStatement $s): array
    {
        self::assertDraft($s);
        self::heal($s);
        $win = max(0, (int) self::cfg('bankrec_window_days'));
        $out = ['reference' => 0, 'amount' => 0, 'group' => 0];

        DB::transaction(function () use ($s, $win, &$out) {
            $pool = self::candidates($s->account_head_id, $s->period_to->copy()->addDays($win)->toDateString())->keyBy('id');
            $open = $s->lines()->where('status', 'unmatched')->get();

            // 1. same amount and the statement reference found in the journal reference / narration / memo
            foreach ($open as $k => $line) {
                if (! ($ref = self::refKey($line->reference))) {
                    continue;
                }
                $hit = $pool->first(function ($g) use ($line, $ref) {
                    $hay = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $g->journal->reference.' '.$g->journal->narration.' '.$g->memo));

                    return abs(self::signed($g) - (float) $line->amount) < 0.005 && str_contains($hay, $ref);
                });
                if ($hit) {
                    self::link($line, [$hit->id], 'reference');
                    $pool->forget($hit->id);
                    $open->forget($k);
                    $out['reference']++;
                }
            }
            // 2. same amount, closest date within the window (the bank usually books on or after the hotel)
            foreach ($open as $k => $line) {
                $d = $line->date;
                $hit = $pool->filter(fn ($g) => abs(self::signed($g) - (float) $line->amount) < 0.005 && abs($g->date->diffInDays($d, false)) <= $win)
                    ->sortBy(fn ($g) => [abs($g->date->diffInDays($d, false)), $g->id])->first();
                if ($hit) {
                    self::link($line, [$hit->id], 'amount');
                    $pool->forget($hit->id);
                    $open->forget($k);
                    $out['amount']++;
                }
            }
            // 3. one statement line = all remaining same-sign book lines of one day (card / MoMo settlement batch)
            foreach ($open as $k => $line) {
                $sign = (float) $line->amount > 0 ? 1 : -1;
                for ($back = 0; $back <= $win; $back++) {
                    $day = $line->date->copy()->subDays($back)->toDateString();
                    $grp = $pool->filter(fn ($g) => $g->date->toDateString() === $day && self::signed($g) * $sign > 0);
                    if ($grp->count() >= 2 && $grp->count() <= 60 && abs($grp->sum(fn ($g) => self::signed($g)) - (float) $line->amount) < 0.005) {
                        self::link($line, $grp->keys()->all(), 'group');
                        foreach ($grp->keys() as $id) {
                            $pool->forget($id);
                        }
                        $open->forget($k);
                        $out['group']++;
                        break;
                    }
                }
            }
        });
        if (array_sum($out)) {
            ActivityLogger::log('bankrec', 'automatch', "Auto-match on {$s->statement_no}: {$out['reference']} by reference, {$out['amount']} by amount, {$out['group']} batch(es)", $s);
        }

        return $out;
    }

    /** Match one statement line to one or more book lines by hand (the amounts must agree to the pesewa). */
    public static function match(BankStatementLine $line, array $glIds): void
    {
        $s = $line->statement;
        self::assertDraft($s);
        if ($line->status !== 'unmatched') {
            throw new RuntimeException('This statement line is already '.$line->status.'.');
        }
        $glIds = array_values(array_unique(array_map('intval', $glIds)));
        if (! $glIds) {
            throw new RuntimeException('Tick the book entries that make up this statement line.');
        }
        $until = $s->period_to->copy()->addDays(31)->toDateString();
        $ok = self::candidates($s->account_head_id, $until)->whereIn('id', $glIds);
        if ($ok->count() !== count($glIds)) {
            throw new RuntimeException('Some of the ticked entries are not open book entries of '.$s->account->name.' (already cleared, reversed or dated after '.dmy($until).').');
        }
        $sum = round($ok->sum(fn ($g) => self::signed($g)), 2);
        if (abs($sum - (float) $line->amount) > 0.005) {
            throw new RuntimeException('The ticked entries add up to '.money($sum).' but the statement line is '.money($line->amount).'.');
        }
        DB::transaction(fn () => self::link($line, $glIds, 'manual'));
        ActivityLogger::log('bankrec', 'match', "Matched {$s->statement_no} line ".money($line->amount).' to '.count($glIds).' book entr'.(count($glIds) === 1 ? 'y' : 'ies'), $s);
    }

    public static function unmatch(BankStatementLine $line): void
    {
        $s = $line->statement;
        self::assertDraft($s);
        if ($line->status !== 'matched') {
            throw new RuntimeException('This line is not matched.');
        }
        DB::transaction(function () use ($line) {
            $line->matches()->delete();
            $hadEntry = $line->entry_kind !== null;
            $line->forceFill(['status' => 'unmatched', 'match_type' => null, 'matched_by' => null, 'matched_at' => null, 'entry_kind' => null, 'entry_account_id' => null, 'entry_memo' => null])->save();
            if ($hadEntry) {
                LedgerService::reconcile('bank_rec', (string) $line->id, null, LedgerService::cutoff()); // reverse the booked entry
            }
        });
        ActivityLogger::log('bankrec', 'unmatch', "Unmatched {$s->statement_no} line ".money($line->amount).($line->description ? " ({$line->description})" : ''), $s);
    }

    public static function ignore(BankStatementLine $line, string $reason): void
    {
        self::assertDraft($line->statement);
        if ($line->status !== 'unmatched') {
            throw new RuntimeException('Only an unmatched line can be ignored.');
        }
        $line->forceFill(['status' => 'ignored', 'ignore_reason' => $reason, 'matched_by' => auth()->id(), 'matched_at' => now()])->save();
        ActivityLogger::log('bankrec', 'ignore', "Ignored {$line->statement->statement_no} line ".money($line->amount).": {$reason}", $line->statement);
    }

    public static function unignore(BankStatementLine $line): void
    {
        self::assertDraft($line->statement);
        if ($line->status !== 'ignored') {
            throw new RuntimeException('This line is not ignored.');
        }
        $line->forceFill(['status' => 'unmatched', 'ignore_reason' => null, 'matched_by' => null, 'matched_at' => null])->save();
    }

    /** Counter account for an entry booked from a statement line. */
    public static function entryAccount(string $kind, ?int $accountId): int
    {
        if (! isset(self::ENTRY_KINDS[$kind])) {
            throw new RuntimeException('Unknown entry type.');
        }
        if ($sys = self::ENTRY_KINDS[$kind][1]) {
            ChartOfAccounts::ensureLight();

            return AccountHead::sys($sys);
        }
        if (! $accountId || ! AccountHead::whereKey($accountId)->where('is_active', true)->exists()) {
            throw new RuntimeException('Choose the account to book it to.');
        }

        return $accountId;
    }

    /**
     * v7.39 Batch-book every fee / charge line on the statement. A MoMo export carries
     * one fee line per transaction (the parser splits them out); booking each by hand is
     * the slow part of the settlement review. Fee lines are booked to Mobile Money Charges
     * on a wallet account, Bank Charges & Commissions otherwise, and matched with the entry.
     */
    public static function bookFees(BankStatement $s): array
    {
        self::assertDraft($s);
        $hay = (string) ($s->account->name ?? '').' '.(string) ($s->account->code ?? '');
        $kind = preg_match('/momo|mobile money|wallet|telecel|airteltigo|tigo cash|mtn/i', $hay) ? 'momo_fees' : 'bank_charges';
        $booked = 0; $failed = 0; $sum = 0.0;
        foreach ($s->lines()->where('status', 'unmatched')->whereNull('entry_kind')->orderBy('date')->orderBy('line_no')->get() as $line) {
            $desc = (string) $line->description;
            if ((float) $line->amount >= 0 || ! preg_match('/fee|charges?|commission|levy/i', $desc)) {
                continue;
            }
            try {
                self::createEntry($line, $kind, null, mb_substr(trim($desc), 0, 150) ?: null);
                $booked++;
                $sum += abs((float) $line->amount);
            } catch (\RuntimeException $e) {
                $failed++; // after the ledger cutoff, etc. — leave for the per-line flow
            }
        }
        ActivityLogger::log('bankrec', 'book_fees', "Booked {$booked} fee line(s) on {$s->statement_no} as ".self::ENTRY_KINDS[$kind][0].' – '.money($sum), $s);

        return ['booked' => $booked, 'failed' => $failed, 'kind' => $kind, 'total' => round($sum, 2)];
    }

    /** Book a bank-only item (charges, interest, MoMo fees …) from the statement line and clear the line with it. */
    public static function createEntry(BankStatementLine $line, string $kind, ?int $accountId = null, ?string $memo = null): GlJournal
    {
        $s = $line->statement;
        self::assertDraft($s);
        if ($line->status !== 'unmatched') {
            throw new RuntimeException('Only an unmatched line can be booked.');
        }
        $counter = self::entryAccount($kind, $accountId);
        if ($counter === (int) $s->account_head_id) {
            throw new RuntimeException('Book it to a different account than '.$s->account->name.'.');
        }
        if ($line->date->toDateString() > LedgerService::cutoff()) {
            throw new RuntimeException('The ledger is posted up to '.dmy(LedgerService::cutoff()).' – book this line after the night audit closes '.dmy($line->date).'.');
        }
        if ($kind === 'interest' && (float) $line->amount < 0) {
            throw new RuntimeException('Interest earned is money in – this line is money out.');
        }
        if (in_array($kind, ['bank_charges', 'momo_fees'], true) && (float) $line->amount > 0) {
            throw new RuntimeException('Charges are money out – this line is money in (a refund of charges? use “Other”).');
        }

        return DB::transaction(function () use ($line, $kind, $counter, $memo, $s) {
            $line->forceFill(['entry_kind' => $kind, 'entry_account_id' => $counter, 'entry_memo' => $memo ? mb_substr($memo, 0, 150) : null])->save();
            LedgerService::reconcile('bank_rec', (string) $line->id, self::forEntry($line), $line->date->toDateString());
            $j = GlJournal::where('source_type', 'bank_rec')->where('source_key', (string) $line->id)->whereNull('reversed_by_id')->whereNull('reversal_of_id')->latest('id')->firstOrFail();
            $gl = $j->lines()->where('account_head_id', $s->account_head_id)->firstOrFail();
            self::link($line, [$gl->id], 'entry');
            ActivityLogger::log('bankrec', 'entry', "Booked {$s->statement_no} line ".money($line->amount).' as '.self::ENTRY_KINDS[$kind][0]." ({$j->journal_no})", $s);

            return $j;
        });
    }

    /** GL document for an entry booked from a statement line: the account ± amount against the counter account. */
    public static function forEntry(BankStatementLine $l): ?array
    {
        if (! $l->entry_kind || ! $l->entry_account_id || (float) $l->amount == 0.0) {
            return null;
        }
        $s = $l->statement;
        $what = self::ENTRY_KINDS[$l->entry_kind][0] ?? 'Bank entry';
        $memo = $l->entry_memo ?: trim(($l->description ?? '').' '.($l->reference ?? '')) ?: $what;

        return ['date' => $l->date->toDateString(), 'reference' => $l->reference ?: $s->statement_no, 'narration' => "{$what} per statement {$s->statement_no}".($l->description ? ': '.$l->description : ''),
            'lines' => [[(int) $s->account_head_id, (float) $l->amount, mb_substr($memo, 0, 150)], [(int) $l->entry_account_id, -(float) $l->amount, mb_substr($memo, 0, 150)]]];
    }

    /**
     * Repair matches whose book line belongs to a journal the ledger has since reversed (source amended): point them at
     * the replacement line (same source, account and amount), else unmatch with a note. Returns [healed, broken].
     */
    public static function heal(?BankStatement $only = null): array
    {
        $healed = 0;
        $broken = 0;
        $stale = BankMatch::with('glLine.journal', 'line.statement')
            ->whereHas('glLine.journal', fn ($q) => $q->whereNotNull('reversed_by_id'))
            ->when($only, fn ($q) => $q->whereHas('line', fn ($w) => $w->where('bank_statement_id', $only->id)))->get();
        foreach ($stale as $m) {
            $old = $m->glLine;
            $j = $old->journal;
            $line = $m->line;
            $repl = GlLine::where('account_head_id', $old->account_head_id)->whereRaw('ROUND(debit - credit, 2) = ?', [self::signed($old)])
                ->whereNotIn('id', BankMatch::select('gl_line_id'))
                ->whereHas('journal', fn ($q) => $q->where('source_type', $j->source_type)->where('source_key', $j->source_key)->whereNull('reversed_by_id')->whereNull('reversal_of_id'))
                ->orderByDesc('id')->first();
            DB::transaction(function () use ($m, $repl, $line, $j, &$healed, &$broken) {
                if ($repl) {
                    $m->forceFill(['gl_line_id' => $repl->id])->save();
                    $healed++;

                    return;
                }
                $st = $line->statement;
                $line->matches()->delete();
                $line->forceFill(['status' => 'unmatched', 'match_type' => null, 'matched_by' => null, 'matched_at' => null,
                    'note' => "Book entry {$j->journal_no} was changed after matching – match it again"])->save();
                if (! $st->isDraft()) {
                    $st->forceFill(['needs_review' => true])->save();
                }
                $broken++;
            });
        }

        return [$healed, $broken];
    }

    // ─────────────────────────── the reconciliation statement ───────────────────────────

    /**
     * The reconciliation as at the statement's end date. Returns balances, the book items not on the bank statement (U),
     * the statement items not in the books (S), the opening difference and the (should-be-zero) unexplained difference.
     */
    public static function summary(BankStatement $s): array
    {
        $acc = (int) $s->account_head_id;
        $D = $s->period_to->toDateString();
        $start = self::startDate($acc) ?? $s->period_from->toDateString();
        $first = BankStatement::where('account_head_id', $acc)->orderBy('period_from')->orderBy('id')->first();
        // SQLite stores cast dates as midnight datetimes, so date-only comparisons must use
        // whereDate (MySQL DATE columns accept either form).
        $scope = BankStatement::where('account_head_id', $acc)->whereDate('period_to', '<=', $D)->pluck('id');

        $lines = BankStatementLine::with('glLines:gl_lines.id,gl_lines.date')->whereIn('bank_statement_id', $scope)->get();
        $cleared = collect();
        $S = collect();
        foreach ($lines as $l) {
            $inScope = $l->status === 'matched' && $l->glLines->isNotEmpty() && $l->glLines->every(fn ($g) => $g->date->toDateString() <= $D);
            if ($inScope) {
                $cleared = $cleared->merge($l->glLines->pluck('id'));
            } else {
                $S->push($l);
            }
        }
        $cleared = $cleared->flip();
        $book = GlLine::with('journal:id,journal_no,date,source_type,source_key,reference,narration,reversal_of_id,reversed_by_id')
            ->where('account_head_id', $acc)->whereDate('date', '>=', $start)->whereDate('date', '<=', $D)->orderBy('date')->orderBy('id')->get();
        $U = $book->reject(fn ($g) => isset($cleared[$g->id]))->values();
        // display: a reversed journal and its reversal both outstanding cancel out – hide the pair
        $jids = $U->pluck('gl_journal_id')->flip();
        $Ushow = $U->reject(function ($g) use ($jids) {
            $j = $g->journal;

            return ($j->reversed_by_id && isset($jids[$j->reversed_by_id])) || ($j->reversal_of_id && isset($jids[$j->reversal_of_id]));
        })->values();

        $C = (float) $s->closing_balance;
        $uIn = round($U->sum(fn ($g) => max(0, self::signed($g))), 2);
        $uOut = round($U->sum(fn ($g) => min(0, self::signed($g))), 2);
        $sSum = round($S->sum(fn ($l) => (float) $l->amount), 2);
        $B = self::glBalance($acc, $D);
        $B0 = self::glBalance($acc, Carbon::parse($start)->subDay()->toDateString());
        $openDiff = round((float) ($first?->opening_balance ?? $s->opening_balance) - $B0, 2);
        $adjusted = round($C + $uIn + $uOut - $sSum, 2);
        $diff = round($adjusted - $openDiff - $B, 2);

        $own = $s->lines()->get();
        $sumLines = round($own->sum(fn ($l) => (float) $l->amount), 2);
        $prev = BankStatement::where('account_head_id', $acc)->whereDate('period_to', '<', $s->period_from->toDateString())->orderByDesc('period_to')->first();

        return [
            'closing' => $C, 'uncleared_in' => $uIn, 'uncleared_out' => $uOut, 'bank_only' => $sSum, 'adjusted' => $adjusted,
            'opening_diff' => $openDiff, 'book' => $B, 'difference' => $diff, 'start' => $start,
            'U' => $Ushow, 'S' => $S->sortBy('date')->values(),
            'lines_total' => $sumLines, 'computed_closing' => round((float) $s->opening_balance + $sumLines, 2),
            'balances_ok' => abs(round((float) $s->opening_balance + $sumLines, 2) - $C) < 0.005,
            'continuity_ok' => ! $prev || abs((float) $prev->closing_balance - (float) $s->opening_balance) < 0.005,
            'counts' => ['total' => $own->count(), 'matched' => $own->where('status', 'matched')->count(), 'unmatched' => $own->where('status', 'unmatched')->count(), 'ignored' => $own->where('status', 'ignored')->count()],
        ];
    }

    /** Reasons the statement cannot be finalised yet (empty = ready). */
    public static function blockers(BankStatement $s, ?array $sum = null): array
    {
        $sum ??= self::summary($s);
        $out = [];
        if (! $sum['balances_ok']) {
            $out[] = 'Opening '.money($s->opening_balance).' + lines '.money($sum['lines_total']).' = '.money($sum['computed_closing']).', not the closing balance '.money($s->closing_balance).'.';
        }
        if (! $sum['continuity_ok']) {
            $out[] = 'The opening balance does not follow on from the previous statement.';
        }
        if ($sum['counts']['unmatched']) {
            $out[] = $sum['counts']['unmatched'].' statement line(s) still to match, book or ignore.';
        }
        if (abs($sum['difference']) >= 0.005) {
            $out[] = 'Unexplained difference of '.money($sum['difference']).'.';
        }
        if (self::cfg('bankrec_segregation') === '1' && (int) $s->created_by === (int) auth()->id()) {
            $out[] = 'You loaded this statement – someone else must finalise it.';
        }

        return $out;
    }

    public static function finalise(BankStatement $s): BankStatement
    {
        self::assertDraft($s);
        self::heal($s);
        if ($b = self::blockers($s->fresh())) {
            throw new RuntimeException(implode(' ', $b));
        }
        $s->update(['status' => 'reconciled', 'reconciled_by' => auth()->id(), 'reconciled_at' => now(), 'needs_review' => false]);
        ActivityLogger::log('bankrec', 'finalise', "Statement {$s->statement_no} ({$s->account->name}, {$s->label()}) reconciled – closing ".money($s->closing_balance), $s);

        return $s;
    }

    public static function reopen(BankStatement $s, string $reason): BankStatement
    {
        if ($s->isDraft()) {
            throw new RuntimeException("{$s->statement_no} is not reconciled.");
        }
        if (self::last($s->account_head_id)?->id !== $s->id) {
            throw new RuntimeException('Only the latest statement of the account can be reopened.');
        }
        $s->update(['status' => 'draft', 'reconciled_by' => null, 'reconciled_at' => null, 'reopen_reason' => $reason]);
        ActivityLogger::log('bankrec', 'reopen', "Statement {$s->statement_no} reopened: {$reason}", $s);

        return $s;
    }

    // ─────────────────────────── overview & alerts ───────────────────────────

    /** Per reconcilable account: book balance, last reconciled statement, draft in progress, state. */
    public static function overview(): Collection
    {
        $today = bdate()->toDateString();
        $stale = (int) self::cfg('bankrec_stale_days');

        return self::accounts()->map(function ($a) use ($today, $stale) {
            $rec = BankStatement::where('account_head_id', $a->id)->where('status', 'reconciled')->orderByDesc('period_to')->first();
            $draft = BankStatement::where('account_head_id', $a->id)->where('status', 'draft')->first();
            $any = $rec || $draft;
            $age = $rec ? (int) $rec->period_to->diffInDays(bdate()) : null;
            $unmatched = $draft ? $draft->lines()->where('status', 'unmatched')->count() : 0;
            $state = ! $any ? 'not_started' : ($rec && $age > $stale ? 'overdue' : ($draft ? 'in_progress' : 'up_to_date'));
            if (! $rec && $draft) {
                $state = 'in_progress';
            }

            return ['account' => $a, 'book' => self::glBalance($a->id, $today), 'reconciled' => $rec, 'draft' => $draft, 'age' => $age, 'unmatched' => $unmatched,
                'state' => $state, 'review' => BankStatement::where('account_head_id', $a->id)->where('needs_review', true)->exists()];
        });
    }

    /** Nav badge: accounts overdue for reconciliation (a statement in progress does not count – only finalising does) + statements needing review. */
    public static function attentionCount(): int
    {
        $limit = bdate()->copy()->subDays((int) self::cfg('bankrec_stale_days'))->toDateString();
        $overdue = BankStatement::where('status', 'reconciled')->groupBy('account_head_id')->selectRaw('account_head_id, MAX(period_to) m')->get()
            ->filter(fn ($r) => Carbon::parse($r->m)->toDateString() < $limit)->count();

        return $overdue + BankStatement::where('needs_review', true)->count();
    }
}
