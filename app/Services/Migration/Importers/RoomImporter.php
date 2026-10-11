<?php

namespace App\Services\Migration\Importers;

use App\Models\Floor;
use App\Models\Room;
use App\Models\RoomType;
use App\Services\Migration\BaseImporter;
use App\Services\Migration\ImportContext;

/**
 * Rooms (v7.35 cutover). Room types and floors are found by code/name and
 * created from the row's own columns when missing (import order: after masters).
 */
class RoomImporter extends BaseImporter
{
    public function label(): string
    {
        return 'Rooms';
    }

    public function columns(): array
    {
        return [
            'external_id' => ['required' => true, 'desc' => 'Legacy system id (room_no is fine)', 'example' => '101', 'aliases' => ['id', 'room_id']],
            'room_no' => ['required' => true, 'desc' => 'Room number as printed on the door', 'example' => '101', 'aliases' => ['number', 'room']],
            'room_type_code' => ['required' => true, 'desc' => 'Room-type code (created if unknown)', 'example' => 'STD', 'aliases' => ['type', 'room_type', 'type_code']],
            'room_type_name' => ['desc' => 'Room-type name, used only when the code is new', 'example' => 'Standard Room'],
            'base_rate' => ['desc' => 'Room-type base rate, used only when the code is new', 'example' => '2800'],
            'floor' => ['desc' => 'Floor name (found or created)', 'example' => 'First Floor', 'aliases' => ['floor_name']],
            'extension' => ['desc' => 'Phone extension', 'example' => '1101'],
            'status' => ['desc' => 'vacant, occupied, out_of_order or out_of_service (default vacant)', 'example' => 'vacant'],
            'hk_status' => ['desc' => 'clean, dirty, inspected or touch_up (default clean)', 'example' => 'clean'],
            'is_smoking' => ['desc' => '1/0', 'example' => '0'],
            'notes' => ['desc' => 'Free-text notes', 'example' => 'Twin beds'],
            'is_active' => ['desc' => '1/0 (default 1)', 'example' => '1', 'aliases' => ['active']],
        ];
    }

    public function validateRow(array $row, ImportContext $ctx, int $rowNo): ?string
    {
        if (! $this->externalId($row['external_id'] ?? null)) {
            return 'external_id is required.';
        }
        if (! $this->str($row['room_no'] ?? null, 20)) {
            return 'room_no is required.';
        }
        if (! $this->str($row['room_type_code'] ?? null, 20)) {
            return 'room_type_code is required.';
        }
        $status = strtolower($this->str($row['status'] ?? null, 20) ?? 'vacant');
        if (! in_array($status, ['vacant', 'occupied', 'out_of_order', 'out_of_service'], true)) {
            return "status \"{$status}\" must be vacant, occupied, out_of_order or out_of_service.";
        }
        $hk = strtolower($this->str($row['hk_status'] ?? null, 20) ?? 'clean');
        if (! in_array($hk, ['clean', 'dirty', 'inspected', 'touch_up'], true)) {
            return "hk_status \"{$hk}\" must be clean, dirty, inspected or touch_up.";
        }

        return null;
    }

    public function importRow(array $row, ImportContext $ctx, bool $dryRun): array
    {
        $ext = $this->externalId($row['external_id']);
        $roomNo = $this->str($row['room_no'], 20);
        $typeCode = strtoupper($this->str($row['room_type_code'], 20));
        $floorName = $this->str($row['floor'] ?? null, 60);

        $type = RoomType::where('code', $typeCode)->first();
        if (! $type) {
            if ($dryRun) {
                $typeId = null; // created on apply
            } else {
                $type = RoomType::create([
                    'code' => $typeCode,
                    'name' => $this->str($row['room_type_name'] ?? null, 80) ?? $typeCode,
                    'base_rate' => $this->num($row['base_rate'] ?? null),
                ]);
                $typeId = $type->id;
            }
        } else {
            $typeId = $type->id;
        }

        $floorId = null;
        if ($floorName) {
            $floorId = Floor::whereRaw('lower(name) = ?', [mb_strtolower($floorName)])->value('id');
            if (! $floorId && ! $dryRun) {
                $floorId = Floor::create(['name' => $floorName])->id;
            }
        }

        $fields = [
            'room_type_id' => $typeId,
            'floor_id' => $floorId,
            'room_no' => $roomNo,
            'status' => strtolower($this->str($row['status'] ?? null, 20) ?? 'vacant'),
            'hk_status' => strtolower($this->str($row['hk_status'] ?? null, 20) ?? 'clean'),
            'extension' => $this->str($row['extension'] ?? null, 10),
            'is_smoking' => $this->bool($row['is_smoking'] ?? null, false),
            'notes' => $this->str($row['notes'] ?? null, 255),
            'is_active' => $this->bool($row['is_active'] ?? null, true),
        ];

        $id = $ctx->mappedId('rooms', $ext);
        $model = $id ? Room::find($id) : null;
        if (! $model) {
            $model = Room::where('room_no', $roomNo)->first(); // rooms are unique per hotel
        }

        if (! $model) {
            if ($dryRun) {
                return ['status' => 'created', 'message' => $typeId === null ? 'New room type '.$typeCode.' will be created' : null, 'model_type' => Room::class, 'model_id' => null];
            }
            $model = Room::create($fields);
            $ctx->map('rooms', $ext, Room::class, $model->id);

            return ['status' => 'created', 'message' => $type && $type->wasRecentlyCreated ? 'Created room type '.$typeCode : null, 'model_type' => Room::class, 'model_id' => $model->id];
        }

        $dirty = $this->dirty($model, $fields);
        unset($dirty['room_no']); // room numbers are the physical identity, never rewritten
        if (! $dirty) {
            if (! $id && ! $dryRun) {
                $ctx->map('rooms', $ext, Room::class, $model->id);
            }

            return ['status' => 'unchanged', 'message' => null, 'model_type' => Room::class, 'model_id' => $model->id];
        }
        if ($dryRun) {
            return ['status' => 'updated', 'message' => 'Would update: '.implode(', ', array_keys($dirty)), 'model_type' => Room::class, 'model_id' => $model->id];
        }
        $model->fill($dirty)->save();
        $ctx->map('rooms', $ext, Room::class, $model->id);

        return ['status' => 'updated', 'message' => 'Updated: '.implode(', ', array_keys($dirty)), 'model_type' => Room::class, 'model_id' => $model->id];
    }

    public function reconcile(ImportContext $ctx): array
    {
        return [
            'Rooms in this property now' => Room::count(),
            'Active rooms' => Room::where('is_active', true)->count(),
            'Room types' => RoomType::count(),
            'External ids mapped (all batches)' => \App\Models\DataMigrationIdMap::withoutGlobalScopes()
                ->where('hotel_id', $ctx->hotel->id)->where('dataset', 'rooms')->count(),
        ];
    }
}
