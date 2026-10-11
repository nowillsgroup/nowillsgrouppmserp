<?php

namespace App\Services\Migration;

use App\Models\DataMigrationBatch;
use App\Models\DataMigrationIdMap;
use App\Models\Hotel;
use App\Support\Tenant;

/**
 * Shared state for one import run (v7.35).
 */
class ImportContext
{
    public function __construct(
        public readonly DataMigrationBatch $batch,
        public readonly Hotel $hotel,
    ) {
    }

    /** Look up the local model id a legacy external id maps to. */
    public function mappedId(string $dataset, ?string $externalId): ?int
    {
        if ($externalId === null || $externalId === '') {
            return null;
        }
        $row = DataMigrationIdMap::withoutGlobalScopes()
            ->where('hotel_id', $this->hotel->id)
            ->where('dataset', $dataset)
            ->where('external_id', $externalId)
            ->first();

        return $row?->model_id;
    }

    /** Record (or refresh) a legacy external id → local model mapping. */
    public function map(string $dataset, string $externalId, string $modelType, int $modelId, ?int $batchId = null): void
    {
        DataMigrationIdMap::withoutGlobalScopes()->updateOrCreate(
            ['hotel_id' => $this->hotel->id, 'dataset' => $dataset, 'external_id' => $externalId],
            ['model_type' => $modelType, 'model_id' => $modelId, 'batch_id' => $batchId ?? $this->batch->id],
        );
    }

    /** Run a callback with this batch's hotel as the tenant. */
    public function asTenant(callable $fn): mixed
    {
        $prev = Tenant::hotel();
        Tenant::set($this->hotel);
        try {
            return $fn();
        } finally {
            Tenant::set($prev);
        }
    }
}
