<?php

namespace App\Services\Migration;

use App\Models\DataMigrationBatch;

/**
 * One importable legacy dataset (v7.35). Each importer owns its CSV columns,
 * per-row validation and the create/update logic for its target model(s).
 *
 * Row results (importRow) use these statuses:
 *  - would_create / would_update / unchanged — dry-run outcome
 *  - created / updated / skipped             — applied outcome
 *  - failed                                  — error; message says why
 */
interface DatasetImporter
{
    /** Human label, e.g. "Guest profiles". */
    public function label(): string;

    /**
     * Column spec keyed by canonical name.
     * Each entry: ['required' => bool, 'desc' => string, 'example' => string].
     * Aliases accepted from legacy exports are listed in the 'aliases' key.
     */
    public function columns(): array;

    /** Null when the row is importable, otherwise a human error message. */
    public function validateRow(array $row, ImportContext $ctx, int $rowNo): ?string;

    /**
     * Apply (or dry-run) one validated row.
     *
     * @return array{status:string, message:?string, model_type:?string, model_id:?int}
     */
    public function importRow(array $row, ImportContext $ctx, bool $dryRun): array;

    /**
     * Post-import reconciliation figures (dataset counts vs the live database).
     *
     * @return array<string, int|float|string>
     */
    public function reconcile(ImportContext $ctx): array;
}
