<?php
/**
 * Bank statement formats — what the importer reads, proven end to end.
 *
 * One story in four files (built by deploy/dev/audit/bank_fixtures.py): an Ecobank current account for August 2026,
 * nine transactions, GH₵125,000.50 opening and GH₵117,366.25 closing. Each file has to arrive at exactly the same
 * nine parsed lines — same dates, same signed amounts, same references — because from the parser onwards there is only
 * one code path. The Excel file keeps its dates as serials under a date number format, the MT940 as two-digit years
 * with comma decimals and no running balance, and the camt.053 batches the two salary payments into one entry.
 *
 * TINKER='require "/app/deploy/dev/audit/bank_formats.php";' deploy/dev/bin/php -f deploy/dev/tinker.php
 */

use App\Models\AccountHead;
use App\Models\BankStatement;
use App\Services\BankRecService as Rec;

$dir = '/app/deploy/dev/audit/fixtures';
$pass = 0;
$fail = 0;
$ok = function (string $what, $got, $want) use (&$pass, &$fail) {
    if ($got === $want) {
        $pass++;
        printf("  ok    %-58s %s%s", $what, is_scalar($got) ? (is_float($got) ? number_format($got, 2) : (string) $got) : json_encode($got), PHP_EOL);
    } else {
        $fail++;
        printf("  FAIL  %-58s got %s, wanted %s%s", $what,
            is_scalar($got) ? var_export($got, true) : json_encode($got),
            is_scalar($want) ? var_export($want, true) : json_encode($want), PHP_EOL);
    }
};

// An account with no statements of its own, so nothing is treated as already imported: parse() de-duplicates against
// the account's existing lines, and bank_import_flow.py deliberately leaves one statement behind as demo evidence.
$account = AccountHead::where('is_active', true)->where('type', 'asset')
    ->whereNotIn('id', BankStatement::query()->select('account_head_id'))->orderBy('id')->first();
if (! $account) {
    exit("no asset account head without a statement to parse against\n");
}
printf("Parsing against account head %d (%s %s)%s%s", $account->id, $account->code, $account->name, PHP_EOL, PHP_EOL);

$files = [
    'csv' => ['ecobank_aug26.csv', 'CSV / tab-delimited text'],
    'xlsx' => ['ecobank_aug26.xlsx', 'Excel workbook (xlsx)'],
    'mt940' => ['ecobank_aug26.mt940', 'SWIFT MT940 / MT942'],
    'camt' => ['ecobank_aug26.camt053.xml', 'ISO 20022 camt.052 / camt.053'],
];

$want = [   // date, description, reference, signed amount, running balance
    ['2026-08-03', 'Guest settlement folio 4471 - room and F&B', 'INV-4471', 18450.00, 143450.50],
    ['2026-08-05', 'EFT to Accra Foods Ltd - produce invoice 2291', 'AFD-2291', -9320.50, 134130.00],
    ['2026-08-07', 'Bank charge - EFT commission', 'CHG-000881', -145.00, 133985.00],
    ['2026-08-10', 'MoMo merchant settlement', 'MOMO-7741203', 6240.75, 140225.75],
    ['2026-08-12', 'Cheque 004412 deposited - Nowills Group HQ', 'CHQ-004412', 25000.00, 165225.75],
    ['2026-08-14', 'Reversal - EFT posted in error', 'REV-AFD2291', 9320.50, 174546.25],
    ['2026-08-17', 'Salary batch August 2026 - A Mensah', 'PAY-2026-08', -30000.00, 144546.25],
    ['2026-08-17', 'Salary batch August 2026 - J Thomas', 'PAY-2026-08', -12180.00, 132366.25],
    ['2026-08-21', 'Standing order - rent, Airport City', 'SO-RENT-08', -15000.00, 117366.25],
];

$parsed = [];
foreach ($files as $key => [$name, $label]) {
    printf("%s — %s%s", $label, $name, PHP_EOL);
    $read = Rec::readFile($dir.'/'.$name, $name);
    $ok('  detected as', $read['label'], $label);
    $ok('  rows in the normalised CSV', $read['rows'] >= 9, true);

    [$lines, $skipped, $dups, $warn] = Rec::parse($account->id, $read['text'], [], 'auto', false);
    $parsed[$key] = array_map(fn ($l) => [$l['date'], $l['description'], $l['reference'], $l['amount'], $l['balance']], $lines);
    $ok('  parsed lines', count($lines), 9);
    $ok('  duplicates against existing statements', $dups, 0);

    if (in_array($key, ['csv', 'xlsx'], true)) {
        $ok('  preamble / total rows skipped', $skipped >= 1, true);   // three heading rows and the Total row
    }
    if ($key === 'xlsx') {
        $ok('  dates came out of Excel serials', $lines[0]['date'], '2026-08-03');
        $ok('  no serial number leaked into a date', (bool) preg_match('/^\d{5}$/', (string) $lines[0]['date']), false);
    }
    if (in_array($key, ['mt940', 'camt'], true)) {
        $ok('  account read from the file', $read['account'], '1441000235678');
        $ok('  currency read from the file', $read['currency'], 'GHS');
        $ok('  opening balance in the file', $read['opening'], 125000.50);
        $ok('  closing balance in the file', $read['closing'], 117366.25);
        $ok('  rebuilt balance ends at the file’s own closing', round($lines[8]['balance'], 2), $read['closing']);
        $ok('  no warning about a missing page', (bool) array_filter($read['notes'], fn ($n) => str_contains($n, 'missing')), false);
    }
    if ($key === 'mt940') {
        $ok('  two-digit year read as 2026', $lines[0]['date'], '2026-08-03');
        $ok('  comma decimal read as pesewas', $lines[2]['amount'], -145.00);
        $ok('  customer reference taken from the :61: line', $lines[0]['reference'], 'INV-4471');
        $ok('  information to customer became the description', $lines[1]['description'], 'EFT to Accra Foods Ltd - produce invoice 2291');
    }
    if ($key === 'camt') {
        $ok('  batched salary entry split into its transactions', array_slice(array_column($parsed['camt'], 0), 6, 2), ['2026-08-17', '2026-08-17']);
        $ok('  narrative came from the remittance information', $lines[3]['description'], 'MoMo merchant settlement');
        $ok('  end-to-end id became the reference', $lines[8]['reference'], 'SO-RENT-08');
    }
    printf("  notes: %s%s%s", implode(' | ', $read['notes']) ?: '(none)', PHP_EOL, PHP_EOL);
}

printf("%s — every format gives the same nine lines%s", 'Cross-check', PHP_EOL);
foreach (['xlsx', 'mt940', 'camt'] as $key) {
    $same = count($parsed['csv']) === count($parsed[$key]);
    foreach ($parsed['csv'] as $i => $row) {
        // the description of a SWIFT or camt line is the bank’s own wording, so compare date, amount, reference, balance
        $same = $same && $row[0] === $parsed[$key][$i][0] && $row[2] === $parsed[$key][$i][2]
            && abs($row[3] - $parsed[$key][$i][3]) < 0.005 && abs((float) $row[4] - (float) $parsed[$key][$i][4]) < 0.005;
    }
    $ok('  '.$files[$key][1].' matches the CSV line for line', $same, true);
}
foreach ($want as $i => [$d, $desc, $ref, $amt, $bal]) {
    $ok('  line '.($i + 1).' '.$d.' '.$ref, [$parsed['csv'][$i][0], $parsed['csv'][$i][2], $parsed['csv'][$i][3], round((float) $parsed['csv'][$i][4], 2)], [$d, $ref, $amt, $bal]);
}
printf("%s", PHP_EOL);

printf("%s — files it should refuse politely%s", 'Rejections', PHP_EOL);
$xls = tempnam(sys_get_temp_dir(), 'xls').'.xls';
file_put_contents($xls, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1".'old binary excel');
try {
    Rec::readFile($xls, 'statement.xls');
    $ok('  an Excel 97-2003 .xls is refused', 'no exception', 'exception');
} catch (\Throwable $e) {
    $ok('  an Excel 97-2003 .xls is refused', str_contains($e->getMessage(), '.xls') && str_contains($e->getMessage(), '.xlsx'), true);
    printf("        “%s”%s", $e->getMessage(), PHP_EOL);
}
@unlink($xls);

$junk = tempnam(sys_get_temp_dir(), 'junk').'.csv';
file_put_contents($junk, "one,two,three\n4,5,6\n");
try {
    Rec::readFile($junk, 'junk.csv');
    Rec::parse($account->id, (string) file_get_contents($junk), [], 'auto', false);
    $ok('  a file with no date column is refused', 'no exception', 'exception');
} catch (\Throwable $e) {
    $ok('  a file with no date column is refused', str_contains(strtolower($e->getMessage()), 'date'), true);
    printf("        “%s”%s", $e->getMessage(), PHP_EOL);
}
@unlink($junk);

$empty = tempnam(sys_get_temp_dir(), 'mt').'.mt940';
file_put_contents($empty, "{1:F01X}{4:\n:20:REF\n:25:123\n:60F:C260801GHS100,00\n:62F:C260801GHS100,00\n-}");
try {
    Rec::readFile($empty, 'empty.mt940');
    $ok('  an MT940 with no :61: lines is refused', 'no exception', 'exception');
} catch (\Throwable $e) {
    $ok('  an MT940 with no :61: lines is refused', str_contains($e->getMessage(), ':61:'), true);
    printf("        “%s”%s", $e->getMessage(), PHP_EOL);
}
@unlink($empty);

printf("%s%d passed, %d failed%s", PHP_EOL, $pass, $fail, PHP_EOL);
exit($fail ? 1 : 0);
