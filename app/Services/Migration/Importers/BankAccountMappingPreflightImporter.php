<?php

namespace App\Services\Migration\Importers;

use App\Models\AccountHead;
use App\Models\TreasuryAccount;
use App\Services\BankRecService;
use App\Services\Migration\BaseImporter;
use App\Services\Migration\ImportContext;

/**
 * Read-only validation of legacy bank/MoMo/clearing IDs against existing
 * reconcilable Nowills Treasury accounts. It never imports account details,
 * creates mappings, statements, GL entries, or supplier bank data.
 */
class BankAccountMappingPreflightImporter extends BaseImporter
{
    private const SOURCE_KINDS = ['bank', 'momo', 'clearing'];

    /** @var array<string, array{head: AccountHead|null, treasury: TreasuryAccount|null}> */
    private array $targetCache = [];

    /** @var list<int>|null */
    private ?array $reconcilableAccountIds = null;

    public function label(): string
    {
        return 'Bank / MoMo account mapping preflight (read-only)';
    }

    public function columns(): array
    {
        return [
            'external_id' => ['required' => true, 'desc' => 'Stable legacy identifier for this source account; do not include credentials or full account numbers', 'example' => 'LEGACY-BANK-001', 'aliases' => ['id', 'legacy_id', 'source_account_id']],
            'source_account_name' => ['required' => true, 'desc' => 'Neutral legacy account label; do not enter bank/branch metadata or account numbers', 'example' => 'Legacy Operating Account', 'aliases' => ['source_name', 'account_name']],
            'source_kind' => ['required' => true, 'desc' => 'One of bank, momo or clearing', 'example' => 'bank', 'aliases' => ['kind', 'account_type']],
            'target_account_code' => ['required' => true, 'desc' => 'Exact active Nowills GL code with an existing reconcilable Treasury account', 'example' => '1021', 'aliases' => ['nowills_account_code', 'target_code']],
        ];
    }

    public function templateRows(): array
    {
        $target = TreasuryAccount::with('head')->where('is_active', true)
            ->whereIn('kind', self::SOURCE_KINDS)->orderBy('id')->first();

        return [[
            'external_id' => 'LEGACY-BANK-001',
            'source_account_name' => 'Legacy Operating Account — replace with neutral label',
            'source_kind' => $target?->kind ?? 'bank',
            'target_account_code' => $target?->head?->code ?? 'REPLACE-WITH-EXISTING-CODE',
        ]];
    }

    public function supportsApply(): bool
    {
        return false;
    }

    /** Reject likely bank-account/card numbers before the uploaded CSV is retained. */
    public function sourceDataSafetyError(array $row): ?string
    {
        foreach (['external_id' => 'external_id', 'source_account_name' => 'source_account_name'] as $field => $label) {
            $value = trim((string) ($row[$field] ?? ''));
            if ($value !== '' && preg_match('/\\d{6,}|(?<!\\d)(?:\\d[ -]?){8,}(?!\\d)/u', $value)) {
                return "{$label} looks like it contains a bank or card number; replace it with a non-sensitive identifier/label before upload.";
            }
        }

        return null;
    }

    public function validateBatch(array $rows, ImportContext $ctx): ?string
    {
        if ($rows === []) {
            return 'The bank-account mapping file has no rows.';
        }

        $ids = [];
        $targets = [];
        foreach ($rows as $index => $row) {
            $externalId = $this->sourceId($row['external_id'] ?? null);
            if ($externalId !== null) {
                $key = mb_strtolower($externalId);
                if (isset($ids[$key])) {
                    return "external_id {$externalId} appears more than once; every legacy account must have a unique id.";
                }
                $ids[$key] = true;
            }

            $targetCode = trim((string) ($row['target_account_code'] ?? ''));
            if ($targetCode !== '') {
                $targetKey = mb_strtolower($targetCode);
                if (isset($targets[$targetKey])) {
                    return "target_account_code {$targetCode} is assigned more than once; map each bank statement source to one distinct reconcilable Nowills account.";
                }
                $targets[$targetKey] = true;
            }

            $error = $this->validateRow($row, $ctx, $index + 2);
            if ($error !== null) {
                return 'Bank-account mapping row '.($index + 2).': '.$error;
            }
        }

        return null;
    }

    public function validateRow(array $row, ImportContext $ctx, int $rowNo): ?string
    {
        if ($safetyError = $this->sourceDataSafetyError($row)) {
            return $safetyError;
        }

        $externalId = trim((string) ($row['external_id'] ?? ''));
        if ($externalId === '') {
            return 'external_id is required and must be a stable legacy account identifier.';
        }
        if (mb_strlen($externalId) > 100) {
            return 'external_id must be 100 characters or fewer.';
        }

        $sourceName = trim((string) ($row['source_account_name'] ?? ''));
        if ($sourceName === '') {
            return 'source_account_name is required.';
        }
        if (mb_strlen($sourceName) > 120) {
            return 'source_account_name must be 120 characters or fewer.';
        }

        $sourceKind = strtolower(trim((string) ($row['source_kind'] ?? '')));
        if (! in_array($sourceKind, self::SOURCE_KINDS, true)) {
            return 'source_kind must be one of: '.implode(', ', self::SOURCE_KINDS).'.';
        }

        $targetCode = trim((string) ($row['target_account_code'] ?? ''));
        if ($targetCode === '') {
            return 'target_account_code is required; choose an existing Nowills bank, Mobile Money or clearing account code.';
        }
        if (mb_strlen($targetCode) > 12) {
            return 'target_account_code must be 12 characters or fewer.';
        }
        ['head' => $head, 'treasury' => $treasury] = $this->targetForCode($targetCode);
        if (! $head) {
            return "Nowills account code {$targetCode} was not found in this property; no account is created or guessed.";
        }
        if (! $head->is_active) {
            return "Nowills account code {$targetCode} is inactive; activate or select an existing active account.";
        }
        if ($head->type !== 'asset' || $head->group_name !== 'Cash & Bank') {
            return "Nowills account code {$targetCode} is not an asset in the Cash & Bank group.";
        }
        if (! $treasury) {
            return "Nowills account code {$targetCode} has no active Treasury account; run the normal Treasury setup before mapping.";
        }
        if ($treasury->kind !== $sourceKind) {
            return "Source kind {$sourceKind} does not match the existing Nowills Treasury account kind {$treasury->kind} for code {$targetCode}.";
        }
        if (! in_array((int) $head->id, $this->reconcilableAccountIds(), true)) {
            return "Nowills account code {$targetCode} is not enabled for bank-statement reconciliation.";
        }

        return null;
    }

    public function importRow(array $row, ImportContext $ctx, bool $dryRun): array
    {
        if (! $dryRun) {
            throw new \LogicException('Bank-account mappings are preflight-only; no Treasury account, statement, ID map or GL entry may be created.');
        }

        return [
            'status' => 'created',
            'message' => 'Preflight only — the source ID resolves to an existing reconcilable Treasury account; no mapping or operational record was written.',
            'model_type' => null,
            'model_id' => null,
        ];
    }

    public function reconcile(ImportContext $ctx): array
    {
        $rows = $ctx->batch->rows()->orderBy('row_no')->get()
            ->map(fn ($row) => (array) $row->payload)->all();
        $error = $this->validateBatch($rows, $ctx);
        $targetCodes = [];
        $sourceKinds = [];
        foreach ($rows as $row) {
            $targetCode = trim((string) ($row['target_account_code'] ?? ''));
            if ($targetCode !== '') {
                $targetCodes[$targetCode] = true;
            }
            $kind = strtolower(trim((string) ($row['source_kind'] ?? '')));
            if ($kind !== '') {
                $sourceKinds[$kind] = ($sourceKinds[$kind] ?? 0) + 1;
            }
        }
        ksort($sourceKinds);
        $reviewMessage = $error ?? ($ctx->batch->rows_failed > 0 ? 'One or more rows are blocked; see the row outcomes.' : null);

        return [
            'Preflight status' => $reviewMessage !== null
                ? 'REVIEW — '.$reviewMessage
                : 'PASS — source IDs resolve to distinct active Nowills Treasury accounts; no mapping was applied.',
            'Legacy bank/MoMo/clearing rows reviewed' => count($rows),
            'Distinct existing Nowills account codes referenced' => count($targetCodes),
            'Source kind row counts' => $sourceKinds ? implode('; ', array_map(fn ($kind, $count) => "{$kind}: {$count}", array_keys($sourceKinds), array_values($sourceKinds))) : 'None',
            'Target account codes' => $targetCodes ? implode(', ', array_keys($targetCodes)) : 'None',
            'Treasury accounts created or changed' => 'None — preflight only.',
            'Bank statements or balances created' => 'None — no statement rows or balance changes.',
            'Data-migration ID maps created' => 'None — legacy IDs remain in the batch for review.',
            'Supplier bank details imported' => 'None — supplier banking data is excluded.',
            'GL impact' => 'None — no journal or ledger posting occurred.',
        ];
    }

    /** @return array{head: AccountHead|null, treasury: TreasuryAccount|null} */
    private function targetForCode(string $code): array
    {
        if (! array_key_exists($code, $this->targetCache)) {
            $head = AccountHead::where('code', $code)->first();
            $treasury = $head
                ? TreasuryAccount::where('account_head_id', $head->id)->where('is_active', true)->first()
                : null;
            $this->targetCache[$code] = ['head' => $head, 'treasury' => $treasury];
        }

        return $this->targetCache[$code];
    }

    /** @return list<int> */
    private function reconcilableAccountIds(): array
    {
        return $this->reconcilableAccountIds ??= BankRecService::accounts()->modelKeys();
    }

    private function sourceId(?string $raw): ?string
    {
        $value = trim((string) $raw);

        return $value === '' || mb_strlen($value) > 100 ? null : $value;
    }
}
