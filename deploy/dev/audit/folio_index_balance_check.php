<?php

// Compare SQL-preaggregated Folio register balances to the canonical model calculation.
// Run with: deploy/dev/bin/php artisan tinker --execute="require 'deploy/dev/audit/folio_index_balance_check.php';"

use App\Models\Folio;
use App\Models\Hotel;
use App\Support\Tenant;

Tenant::set(Hotel::findOrFail(1));
$folios = Folio::withListBalance()->orderBy('folios.id')->limit(100)->get();
$checked = 0;
foreach ($folios as $folio) {
    $listed = round((float) $folio->listing_balance, 2);
    $canonical = $folio->balance();
    if (abs($listed - $canonical) > 0.005) {
        throw new RuntimeException("{$folio->folio_no} list balance {$listed} does not match canonical balance {$canonical}.");
    }
    ++$checked;
}

echo "Matched {$checked} SQL-preaggregated Folio register balances to the canonical ledger totals.".PHP_EOL;
