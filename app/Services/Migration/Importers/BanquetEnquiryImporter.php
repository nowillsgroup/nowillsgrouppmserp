<?php

namespace App\Services\Migration\Importers;

use App\Models\BanquetEvent;
use App\Models\BanquetEventItem;
use App\Models\BanquetFunction;
use App\Models\BanquetHall;
use App\Models\BanquetPackage;
use App\Models\BanquetService as ServiceItem;
use App\Models\DataMigrationIdMap;
use App\Models\OnlinePayment;
use App\Models\OrganiserAudit;
use App\Models\SalesOpportunity;
use App\Models\Visitor;
use App\Services\BanquetSalesService;
use App\Services\Migration\BaseImporter;
use App\Services\Migration\ImportContext;
use App\Support\Tenant;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Non-binding Banquet & Events intake adapter. It creates or updates only
 * enquiry-stage records. It cannot create tentative/definite holds, deposits,
 * folios, bills or GL entries; those continue through the Banquet workflow.
 */
class BanquetEnquiryImporter extends BaseImporter
{
    private const DATASET = 'banquet_enquiries';

    public function canAccess(): bool
    {
        return auth()->user() === null
            ? app()->runningInConsole()
            : can_do('banquet.manage');
    }

    public function authorizeAccess(): void
    {
        abort_unless($this->canAccess(), 403, 'You need Banquet management permission to transfer enquiries.');
    }

    public function label(): string
    {
        return 'Banquet enquiries (no holds or financial postings)';
    }

    public function columns(): array
    {
        return [
            'external_id' => ['required' => true, 'desc' => 'Stable source id; stored in a property-scoped import map. Exported LOCAL-HOTEL ids are valid only in the same property.', 'example' => '', 'aliases' => ['source_id']],
            'contact_name' => ['required' => true, 'desc' => 'Enquiry contact name.', 'example' => ''],
            'contact_phone' => ['desc' => 'Contact phone.', 'example' => ''],
            'contact_email' => ['desc' => 'Contact e-mail.', 'example' => ''],
            'company_id' => ['desc' => 'Optional existing company id in this property.', 'example' => ''],
            'event_type' => ['required' => true, 'desc' => 'One of the Banquet event type keys.', 'example' => 'conference'],
            'title' => ['required' => true, 'desc' => 'Event title / short description.', 'example' => ''],
            'source' => ['desc' => 'Optional source key (phone, email, walk_in, website, agent, repeat, referral).', 'example' => ''],
            'requirements' => ['desc' => 'Client requirements.', 'example' => ''],
            'internal_notes' => ['desc' => 'Staff-only notes.', 'example' => ''],
            'functions_json' => ['required' => true, 'desc' => 'JSON list of 1–30 proposed functions. Each uses name, banquet_hall_id, date (YYYY-MM-DD), start_time/end_time (HH:MM), setup_style, expected_pax, optional guaranteed_pax, banquet_package_id, rate_per_pax, hall_rate, notes and items. Each item may use banquet_service_id, description, qty, unit_price and tax_rate. Related ids must be from this target property; rate fields are property-currency estimates only, never postings.', 'example' => ''],
        ];
    }

    public function templateRows(): array
    {
        return [array_fill_keys(array_keys($this->columns()), '')];
    }

    public function validateRow(array $row, ImportContext $ctx, int $rowNo): ?string
    {
        if (! $this->canAccess()) {
            return 'You do not have permission to import Banquet enquiries.';
        }
        $externalId = trim((string) ($row['external_id'] ?? ''));
        if ($externalId === '' || mb_strlen($externalId) > 100) {
            return 'external_id is required and must be no longer than 100 characters.';
        }
        if (strlen((string) ($row['functions_json'] ?? '')) > 120000) {
            return 'functions_json must be no longer than 120,000 characters.';
        }

        $data = $this->fields($row);
        $validator = Validator::make($data, [
            'contact_name' => 'required|string|max:120',
            'contact_phone' => 'nullable|string|max:30',
            'contact_email' => 'nullable|email|max:150',
            'company_id' => 'nullable|integer|owned:companies',
            'event_type' => 'required|in:'.implode(',', array_keys(BanquetEvent::TYPES)),
            'title' => 'required|string|max:150',
            'source' => 'nullable|in:'.implode(',', array_keys(BanquetEvent::SOURCES)),
            'requirements' => 'nullable|string|max:3000',
            'internal_notes' => 'nullable|string|max:3000',
        ]);
        if ($validator->fails()) {
            return implode(' ', $validator->errors()->all());
        }

        $functions = $this->parseFunctions($row['functions_json'] ?? null, $functionError);
        if ($functionError !== null) {
            return $functionError;
        }
        $functionError = $this->validateFunctions($functions);
        if ($functionError !== null) {
            return $functionError;
        }

        $existing = $this->findExisting($externalId, $ctx, $error);
        if ($error !== null) {
            return $error;
        }
        if ($existing && ($existing->status !== 'enquiry' || $existing->folio_id || $existing->billed_at)) {
            return 'This event has progressed beyond an enquiry or has a folio; edit it through the Banquet workflow instead of importing over a hold or financial record.';
        }
        if ($existing && ($activity = $this->updateBlockedReason($existing)) !== null) {
            return $activity;
        }

        return null;
    }

    public function validateBatch(array $rows, ImportContext $ctx): ?string
    {
        $seen = [];
        foreach ($rows as $index => $row) {
            $id = $this->externalId($row['external_id'] ?? null);
            if ($id === null) {
                continue;
            }
            $key = mb_strtolower($id);
            if (isset($seen[$key])) {
                return 'Duplicate external_id "'.$id.'" on rows '.$seen[$key].' and '.($index + 1).'.';
            }
            $seen[$key] = $index + 1;
        }

        return null;
    }

    public function importRow(array $row, ImportContext $ctx, bool $dryRun): array
    {
        $externalId = $this->externalId($row['external_id'] ?? null);
        $data = $this->fields($row);
        $functions = $this->parseFunctions($row['functions_json'] ?? null, $functionError);
        if ($functionError !== null) {
            return ['status' => 'failed', 'message' => $functionError, 'model_type' => BanquetEvent::class, 'model_id' => null];
        }
        $existing = $this->findExisting($externalId, $ctx, $error);
        if ($error !== null) {
            return ['status' => 'failed', 'message' => $error, 'model_type' => BanquetEvent::class, 'model_id' => null];
        }
        if ($existing && ($existing->status !== 'enquiry' || $existing->folio_id || $existing->billed_at)) {
            return ['status' => 'failed', 'message' => 'The event is no longer an unbilled enquiry; use the Banquet workflow.', 'model_type' => BanquetEvent::class, 'model_id' => (int) $existing->id];
        }
        if ($existing && ($activity = $this->updateBlockedReason($existing)) !== null) {
            return ['status' => 'failed', 'message' => $activity, 'model_type' => BanquetEvent::class, 'model_id' => (int) $existing->id];
        }
        if ($dryRun) {
            return [
                'status' => $existing ? 'updated' : 'created',
                'message' => $existing ? 'Would replace enquiry details and proposed functions only; status remains enquiry and no venue hold or payment is created.' : 'Would create an enquiry only; no venue hold or financial record is created.',
                'model_type' => BanquetEvent::class,
                'model_id' => $existing ? (int) $existing->id : null,
            ];
        }

        $isUpdate = $existing !== null;
        $event = DB::transaction(function () use ($existing, $externalId, $functions, $data, $ctx) {
            $locked = $existing ? $this->query()->whereKey($existing->id)->lockForUpdate()->first() : null;
            if ($existing && (! $locked || $locked->status !== 'enquiry' || $locked->folio_id || $locked->billed_at || $this->updateBlockedReason($locked) !== null)) {
                throw ValidationException::withMessages(['event' => 'This event progressed or gained linked workflow activity after validation. No banquet data was imported; review it in the Banquet workflow.']);
            }
            if ($locked) {
                $oldFunctions = $locked->functions()->orderBy('date')->orderBy('start_time')->orderBy('id')->get();
                foreach ($functions as $index => &$function) {
                    if (isset($oldFunctions[$index])) {
                        $function['id'] = (int) $oldFunctions[$index]->id;
                    }
                }
                unset($function);
            }
            $data['functions'] = $functions;
            $data['status'] = 'enquiry';
            $data['sales_user_id'] = $locked?->sales_user_id ?? auth()->id();
            $data['room_block_id'] = $locked?->room_block_id;
            $data['deposit_due'] = $locked?->deposit_due ?? 0;
            $data['deposit_due_date'] = $locked?->deposit_due_date?->toDateString();
            $event = BanquetSalesService::save($data, $locked);
            $ctx->map(self::DATASET, $externalId, BanquetEvent::class, (int) $event->id);

            return $event;
        });

        return [
            'status' => $isUpdate ? 'updated' : 'created',
            'message' => $isUpdate ? 'Updated enquiry details and proposed functions only; no hold or payment was created.' : 'Created enquiry only; no venue hold or financial record was created.',
            'model_type' => BanquetEvent::class,
            'model_id' => (int) $event->id,
        ];
    }

    public function reconcile(ImportContext $ctx): array
    {
        $mapped = DataMigrationIdMap::withoutGlobalScopes()
            ->where('hotel_id', $ctx->hotel->id)->where('dataset', self::DATASET)->count();

        return [
            'Unheld Banquet enquiries in this property' => $this->query()->where('status', 'enquiry')->whereNull('folio_id')->count(),
            'External ids mapped (all batches)' => $mapped,
        ];
    }

    /** Export only unheld, unbilled enquiries; local IDs can round-trip within this property. */
    public function exportRows(): iterable
    {
        $this->authorizeAccess();
        $events = $this->query()->where('status', 'enquiry')->whereNull('folio_id')->whereNull('billed_at')
            ->with(['functions.items'])->orderBy('id')->lazyById(100);
        foreach ($events as $event) {
            $functions = $event->functions->map(fn (BanquetFunction $function) => [
                'name' => $function->name,
                'banquet_hall_id' => $function->banquet_hall_id,
                'date' => $function->date?->toDateString(),
                'start_time' => substr((string) $function->start_time, 0, 5),
                'end_time' => substr((string) $function->end_time, 0, 5),
                'setup_style' => $function->setup_style,
                'expected_pax' => $function->expected_pax,
                'guaranteed_pax' => $function->guaranteed_pax,
                'banquet_package_id' => $function->banquet_package_id,
                'rate_per_pax' => $function->rate_per_pax,
                'hall_rate' => $function->hall_rate,
                'notes' => $function->notes,
                'items' => $function->items->map(fn (BanquetEventItem $item) => [
                    'banquet_service_id' => $item->banquet_service_id,
                    'description' => $item->description,
                    'qty' => $item->qty,
                    'unit_price' => $item->unit_price,
                    'tax_rate' => $item->tax_rate,
                ])->values()->all(),
            ])->values()->all();
            yield [
                'external_id' => 'LOCAL-HOTEL-'.$event->hotel_id.'-EVENT-'.$event->id,
                'contact_name' => $event->contact_name,
                'contact_phone' => $event->contact_phone,
                'contact_email' => $event->contact_email,
                'company_id' => $event->company_id,
                'event_type' => $event->event_type,
                'title' => $event->title,
                'source' => $event->source,
                'requirements' => $event->requirements,
                'internal_notes' => $event->internal_notes,
                'functions_json' => json_encode($functions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]',
            ];
        }
    }

    private function parseFunctions(?string $json, ?string &$error): ?array
    {
        $error = null;
        try {
            $functions = json_decode(trim((string) $json), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            $error = 'functions_json must be a JSON list containing one or more proposed functions.';

            return null;
        }
        if (! is_array($functions) || ! array_is_list($functions) || count($functions) < 1 || count($functions) > 30) {
            $error = 'functions_json must be a JSON list containing 1–30 proposed functions.';

            return null;
        }
        $validator = Validator::make(['functions' => $functions], [
            'functions' => ['required', 'array', 'min:1', 'max:30'],
            'functions.*' => ['array:name,banquet_hall_id,date,start_time,end_time,setup_style,expected_pax,guaranteed_pax,banquet_package_id,rate_per_pax,hall_rate,notes,items'],
            'functions.*.name' => ['nullable', 'string', 'max:120'],
            'functions.*.banquet_hall_id' => ['required', 'integer', 'owned:banquet_halls'],
            'functions.*.date' => ['required', 'date_format:Y-m-d'],
            'functions.*.start_time' => ['required', 'date_format:H:i'],
            'functions.*.end_time' => ['required', 'date_format:H:i'],
            'functions.*.setup_style' => ['required', 'in:'.implode(',', array_keys(BanquetFunction::SETUPS))],
            'functions.*.expected_pax' => ['required', 'integer', 'min:1', 'max:10000'],
            'functions.*.guaranteed_pax' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'functions.*.banquet_package_id' => ['nullable', 'integer', 'owned:banquet_packages'],
            'functions.*.rate_per_pax' => ['nullable', 'numeric', 'min:0', 'max:999999'],
            'functions.*.hall_rate' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'functions.*.notes' => ['nullable', 'string', 'max:3000'],
            'functions.*.items' => ['nullable', 'array', 'max:40'],
            'functions.*.items.*' => ['array:banquet_service_id,description,qty,unit_price,tax_rate'],
            'functions.*.items.*.banquet_service_id' => ['nullable', 'integer', 'owned:banquet_services'],
            'functions.*.items.*.description' => ['nullable', 'string', 'max:150'],
            'functions.*.items.*.qty' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'functions.*.items.*.unit_price' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'functions.*.items.*.tax_rate' => ['nullable', 'numeric', 'min:0', 'max:50'],
        ]);
        if ($validator->fails()) {
            $error = implode(' ', $validator->errors()->all());

            return null;
        }

        return array_values($functions);
    }

    private function validateFunctions(array $functions): ?string
    {
        foreach ($functions as $index => $function) {
            $label = trim((string) ($function['name'] ?? '')) ?: 'Function '.($index + 1);
            if (Carbon::parse($function['date'])->lt(bdate())) {
                return $label.': enquiry function dates cannot be in the past.';
            }
            if ($function['end_time'] <= $function['start_time']) {
                return $label.': end time must be after start time.';
            }
            $hall = BanquetHall::query()->where('is_active', true)->find((int) $function['banquet_hall_id']);
            if (! $hall) {
                return $label.': choose an active venue belonging to this property.';
            }
            $capacity = $hall->capacityFor($function['setup_style']);
            if ((int) $function['expected_pax'] > $capacity) {
                return $label.': '.$hall->name.' takes '.$capacity.' guests in this setup.';
            }
            if (isset($function['guaranteed_pax']) && (int) $function['guaranteed_pax'] > $capacity) {
                return $label.': the guarantee exceeds '.$hall->name.' capacity ('.$capacity.').';
            }
            if (! empty($function['banquet_package_id'])) {
                $package = BanquetPackage::query()->where('is_active', true)->find((int) $function['banquet_package_id']);
                if (! $package) {
                    return $label.': choose an active banquet package in this property.';
                }
                if ($package->min_pax > max((int) $function['expected_pax'], (int) ($function['guaranteed_pax'] ?? 0))) {
                    return $label.': selected package requires at least '.$package->min_pax.' guests.';
                }
            }
            foreach (($function['items'] ?? []) as $item) {
                if (! empty($item['banquet_service_id'])) {
                    $service = ServiceItem::query()->where('is_active', true)->find((int) $item['banquet_service_id']);
                    if (! $service) {
                        return $label.': service lines must reference an active banquet service in this property.';
                    }
                }
            }
        }

        return null;
    }

    private function fields(array $row): array
    {
        $fields = [];
        foreach (['contact_name', 'contact_phone', 'contact_email', 'company_id', 'event_type', 'title', 'source', 'requirements', 'internal_notes'] as $field) {
            $value = $row[$field] ?? null;
            $fields[$field] = is_string($value) && trim($value) === '' ? null : (is_string($value) ? trim($value) : $value);
        }

        return $fields;
    }

    private function findExisting(string $externalId, ImportContext $ctx, ?string &$error): ?BanquetEvent
    {
        $error = null;
        $map = DataMigrationIdMap::withoutGlobalScopes()->where('hotel_id', $ctx->hotel->id)
            ->where('dataset', self::DATASET)->where('external_id', $externalId)->first();
        if ($map) {
            if ($map->model_type !== BanquetEvent::class) {
                $error = 'The saved external-id map points to an unexpected record type.';

                return null;
            }
            $event = $this->query()->whereKey($map->model_id)->first();
            if (! $event) {
                $error = 'The saved external-id map does not point to an event in this property.';

                return null;
            }

            return $event;
        }
        if (preg_match('/^LOCAL-HOTEL-(\d+)-EVENT-(\d+)$/', $externalId, $matches)) {
            if ((int) $matches[1] !== (int) $ctx->hotel->id) {
                $error = 'This export id belongs to a different property. Replace it with a stable source external_id before cross-property migration.';

                return null;
            }
            $event = $this->query()->whereKey((int) $matches[2])->first();
            if (! $event) {
                $error = 'This property-local export id was not found. Use a stable source external_id for cross-property migration.';
            }

            return $event;
        }
        if (str_starts_with($externalId, 'LOCAL-HOTEL-')) {
            $error = 'Malformed property-local event id; use the downloaded template or a stable source external_id.';
        }

        return null;
    }

    private function updateBlockedReason(BanquetEvent $event): ?string
    {
        if ($event->confirmed_at || $event->portal_enabled || $event->beo_published_at || $event->beo_approved_at
            || $event->organiserRequests()->exists() || $event->attendees()->exists()
            || OrganiserAudit::where('banquet_event_id', $event->id)->exists()
            || OnlinePayment::where('banquet_event_id', $event->id)->exists()
            || Visitor::where('banquet_event_id', $event->id)->exists()
            || SalesOpportunity::where('banquet_event_id', $event->id)->exists()) {
            return 'This enquiry has linked organiser, visitor, online-payment, BEO or sales activity; use the Banquet workflow instead of replacing it from a CSV.';
        }
        foreach ($event->functions as $function) {
            if ((int) ($function->actual_pax ?? 0) > 0 || ($function->guaranteed_pax !== null && $function->guaranteeLocked())) {
                return 'This enquiry has recorded actual attendance or a locked guarantee; use the Banquet workflow instead of replacing its functions from a CSV.';
            }
        }

        return null;
    }

    private function query()
    {
        return BanquetEvent::query()->where('hotel_id', Tenant::id());
    }
}
