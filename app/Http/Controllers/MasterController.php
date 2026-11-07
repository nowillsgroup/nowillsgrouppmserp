<?php

namespace App\Http\Controllers;

use App\Models\RoomImage;
use App\Services\ActivityLogger;
use App\Services\RoomPhotoService;
use App\Support\Numbering;
use App\Support\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Generic CRUD for master data defined in config/masters.php.
 */
class MasterController extends Controller
{
    protected function def(string $master): array
    {
        $def = config("masters.$master");
        abort_unless($def, 404);
        abort_unless(can_do($def['perm'] ?? 'masters.manage'), 403);

        return $def + ['slug' => $master];
    }

    protected function options(array $def): array
    {
        $out = [];
        foreach ($def['fields'] as $name => $f) {
            if (($f[1] ?? '') !== 'select') {
                continue;
            }
            $src = $f[3] ?? [];
            if (is_string($src) && str_starts_with($src, 'model:')) {
                [$cls, $label] = explode(',', substr($src, 6));
                $cls = 'App\\Models\\'.$cls;
                $q = $cls::query();
                // models without the tenant trait (e.g. User) are filtered to the current hotel explicitly
                if (! in_array(\App\Models\Concerns\BelongsToHotel::class, class_uses_recursive($cls))) {
                    $q->where('hotel_id', Tenant::id())->when(\Illuminate\Support\Facades\Schema::hasColumn((new $cls)->getTable(), 'is_active'), fn ($w) => $w->where('is_active', true));
                }
                $out[$name] = $q->orderBy($label)->pluck($label, 'id')->all();
            } elseif (is_string($src) && str_starts_with($src, 'config:')) {
                $out[$name] = config(substr($src, 7));
            } else {
                $out[$name] = $src;
            }
        }

        return $out;
    }

    public function index(Request $request, string $master)
    {
        $def = $this->def($master);
        $term = trim((string) $request->input('q', ''));
        if ($master === 'menu-items') {
            $filters = $request->validate([
                'category' => 'nullable|integer|owned:menu_categories',
                'outlet' => 'nullable|integer|owned:outlets',
                'availability' => ['nullable', Rule::in(['available', 'hidden'])],
            ]);
            $textCols = collect($def['fields'])->filter(fn ($f) => in_array($f[1], ['text', 'email']))->keys();
            $q = \App\Models\MenuItem::query()
                ->when($term !== '', fn ($builder) => $builder->where(fn ($w) => $textCols->each(fn ($c) => $w->orWhere($c, 'like', '%'.$term.'%'))))
                ->when(! empty($filters['category']), fn ($builder) => $builder->where('menu_category_id', $filters['category']))
                ->when(! empty($filters['outlet']), fn ($builder) => $builder->where('outlet_id', $filters['outlet']));
            $summary = (clone $q)->selectRaw(
                'COUNT(*) as total_count,
                 COALESCE(SUM(CASE WHEN is_available = 1 THEN 1 ELSE 0 END), 0) as available_count,
                 COALESCE(SUM(CASE WHEN is_available = 0 THEN 1 ELSE 0 END), 0) as hidden_count'
            )->first();
            if (($filters['availability'] ?? null) === 'available') {
                $q->where('is_available', true);
            } elseif (($filters['availability'] ?? null) === 'hidden') {
                $q->where('is_available', false);
            }
            $rows = $q->with($def['with'] ?? [])->withCount('variants')->orderBy($def['order'] ?? 'id')->orderBy('id')->paginate(50)->withQueryString();

            return view('masters.menu-items', [
                'def' => $def, 'rows' => $rows, 'summary' => $summary, 'q' => $term,
                'filters' => $filters,
                'categories' => \App\Models\MenuCategory::orderBy('name')->pluck('name', 'id'),
                'outlets' => \App\Models\Outlet::where('is_active', true)->orderBy('name')->pluck('name', 'id'),
            ]);
        }

        $q = $def['model']::query()->with($def['with'] ?? []);
        if ($term !== '') {
            $textCols = collect($def['fields'])->filter(fn ($f) => in_array($f[1], ['text', 'email']))->keys();
            $q->where(fn ($w) => $textCols->each(fn ($c) => $w->orWhere($c, 'like', '%'.$term.'%')));
        }
        $rows = $q->orderBy($def['order'] ?? 'id')->orderBy('id')->paginate(50)->withQueryString();

        return view('masters.index', ['def' => $def, 'rows' => $rows, 'columns' => $this->columns($def)]);
    }

    /** Normalise column list to [path => label]. */
    protected function columns(array $def): array
    {
        $cols = [];
        foreach ($def['columns'] as $k => $v) {
            is_int($k) ? $cols[$v] = $def['fields'][$v][0] ?? ucwords(str_replace('_', ' ', $v)) : $cols[$k] = $v;
        }

        return $cols;
    }

    public function create(string $master)
    {
        $def = $this->def($master);

        $view = $master === 'menu-items' ? 'masters.menu-item-form' : 'masters.form';

        return view($view, ['def' => $def, 'row' => new $def['model'](['is_active' => true, 'is_available' => true, 'show_online' => true]), 'options' => $this->options($def)]);
    }

    public function edit(string $master, int $id)
    {
        $def = $this->def($master);

        $view = $master === 'menu-items' ? 'masters.menu-item-form' : 'masters.form';
        $row = $def['model']::findOrFail($id);
        if ($master === 'menu-items') {
            $row->load('variants');
        }
        $extra = [];
        if (! empty($def['photos'])) {
            $extra['photos'] = $row->images()->get();
        }

        return view($view, ['def' => $def, 'row' => $row, 'options' => $this->options($def)] + $extra);
    }

    protected function validated(Request $request, array $def, ?int $id = null): array
    {
        $table = (new $def['model'])->getTable();
        $rules = [];
        foreach ($def['fields'] as $name => $f) {
            $rules[$name] = explode('|', $f[2]);
            if (($f[1] ?? '') === 'image' || ! in_array($name, $def['unique'] ?? [])) {
                continue;
            }
            $rules[$name][] = Rule::unique($table, $name)->where('hotel_id', Tenant::id())->ignore($id);
        }
        $data = $request->validate($rules, [], collect($def['fields'])->map(fn ($f) => strtolower($f[0]))->all());
        foreach ($def['fields'] as $name => $f) {
            if ($f[1] === 'image') {
                unset($data[$name]);
            } elseif ($f[1] === 'bool') {
                $data[$name] = $request->boolean($name);
            } elseif ($f[1] === 'number' && ($data[$name] ?? null) === null && ! str_contains($f[2], 'required')) {
                // nullable numeric columns with DB defaults: keep null only where the column is nullable
                $data[$name] = in_array($name, ['slab_to']) ? null : ($name === 'credit_days' ? 30 : 0);
            }
        }
        foreach ($def['autonumber'] ?? [] as $field => $key) {
            if (empty($data[$field]) && ! $id) {
                $data[$field] = Numbering::next($key);
            } elseif (empty($data[$field])) {
                unset($data[$field]);
            }
        }

        return $data;
    }

    protected function validatedMenuItemVariants(Request $request, string $master): ?array
    {
        if ($master !== 'menu-items' || ! $request->has('variants_present')) {
            return null;
        }

        $variants = $request->input('variants', []);
        if (is_array($variants)) {
            $variants = array_values(array_filter($variants, function ($variant) {
                if (! is_array($variant)) {
                    return true;
                }

                foreach (['name', 'rate', 'cost'] as $key) {
                    $value = $variant[$key] ?? null;
                    if (($value !== null && ! is_scalar($value)) || (is_scalar($value) && trim((string) $value) !== '')) {
                        return true;
                    }
                }

                return false;
            }));
            $variants = array_map(function ($variant) {
                if (is_array($variant) && array_key_exists('name', $variant)) {
                    $variant['name'] = trim((string) $variant['name']);
                }

                return $variant;
            }, $variants);
        }

        $data = Validator::make(['variants' => $variants], [
            'variants' => ['nullable', 'array', 'max:30'],
            'variants.*' => ['array:name,rate,cost'],
            'variants.*.name' => ['required', 'string', 'max:100', 'distinct:ignore_case'],
            // Rate and cost are optional: a side dish included in the item's own price (or one the
            // kitchen does not cost separately yet) is saved at 0 rather than being refused.
            'variants.*.rate' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'variants.*.cost' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
        ], [
            'variants.*.name.distinct' => 'Each side dish variant must have a unique name.',
        ], [
            'variants.*.name' => 'side dish name', 'variants.*.rate' => 'side dish rate', 'variants.*.cost' => 'side dish cost',
        ])->validate();

        return array_values($data['variants'] ?? []);
    }

    protected function syncMenuItemVariants($row, array $variants): void
    {
        $row->variants()->delete();
        foreach ($variants as $sort => $variant) {
            $row->variants()->create([
                'name' => $variant['name'],
                'rate' => round((float) ($variant['rate'] ?? 0), 2),
                'cost' => round((float) ($variant['cost'] ?? 0), 2),
                'sort_order' => $sort,
            ]);
        }
    }

    /**
     * A dish may insist on its side dish (v9.30).
     *
     * The flag lives beside the variants because that is where the rule is decided. A dish that offers no side dish
     * cannot require one — that would be a rule nobody can satisfy, and the waiter would be stuck on a picker with
     * nothing in it — so the save is refused instead, naming the reason.
     */
    protected function applySideDishRule($row, Request $request, string $master): void
    {
        if ($master !== 'menu-items' || ! $request->has('variants_present')) {
            return;
        }

        $required = $request->boolean('side_dish_required');
        if ($required && $row->variants()->count() === 0) {
            throw ValidationException::withMessages([
                'side_dish_required' => 'Add at least one side dish before making it required — a dish cannot insist on a side dish it does not offer.',
            ]);
        }

        $row->forceFill(['side_dish_required' => $required])->save();
    }

    public function store(Request $request, string $master)
    {
        $def = $this->def($master);
        $this->validateRoomPhotos($request, $def);
        $data = $this->validated($request, $def);
        $variants = $this->validatedMenuItemVariants($request, $master);
        $row = DB::transaction(function () use ($def, $data, $variants, $request, $master) {
            $row = $def['model']::create($data);
            if ($variants !== null) {
                $this->syncMenuItemVariants($row, $variants);
            }
            $this->applySideDishRule($row, $request, $master);

            return $row;
        });
        $this->storeImages($request, $def, $row);
        $this->storeRoomPhotos($request, $def, $row);
        ActivityLogger::log('masters', 'create', "Created {$def['title']}: ".$this->label($row), $row);

        return redirect()->route('masters.index', $master)->with('success', 'Saved.');
    }

    public function update(Request $request, string $master, int $id)
    {
        $def = $this->def($master);
        $row = $def['model']::findOrFail($id);
        $this->validateRoomPhotos($request, $def);
        $data = $this->validated($request, $def, $id);
        $variants = $this->validatedMenuItemVariants($request, $master);
        if ($row->getAttribute('system_key') && isset($data['type']) && $data['type'] !== $row->type) {
            return back()->withInput()->with('error', 'This is a system account used by automatic postings – its type cannot be changed.');
        }
        if ($row->getAttribute('system_key') && array_key_exists('is_active', $data) && ! $data['is_active']) {
            return back()->withInput()->with('error', 'System accounts cannot be deactivated.');
        }
        DB::transaction(function () use ($row, $data, $variants, $request, $master) {
            $row->update($data);
            if ($variants !== null) {
                $this->syncMenuItemVariants($row, $variants);
            }
            $this->applySideDishRule($row, $request, $master);
        });
        $this->storeImages($request, $def, $row);
        $this->storeRoomPhotos($request, $def, $row);
        ActivityLogger::log('masters', 'update', "Updated {$def['title']}: ".$this->label($row), $row);

        return redirect()->route('masters.index', $master)->with('success', 'Updated.');
    }

    public function toggleAvailability(Request $request, string $master, int $id)
    {
        abort_unless($master === 'menu-items', 404);
        $def = $this->def($master);
        $request->validate(['is_available' => 'required|boolean']);
        $row = \App\Models\MenuItem::findOrFail($id);
        $row->is_available = $request->boolean('is_available');
        $row->save();
        ActivityLogger::log('masters', 'update', "Updated {$def['title']} availability: ".$this->label($row), $row);

        return back()->with('success', $row->is_available ? "{$row->name} is available on POS." : "{$row->name} is hidden from POS.");
    }

    public function destroy(string $master, int $id)
    {
        $def = $this->def($master);
        $row = $def['model']::findOrFail($id);
        if ($row->getAttribute('system_key')) {
            return back()->with('error', 'System accounts are used by automatic postings and cannot be deleted.');
        }
        foreach ($def['refs'] ?? [] as [$table, $col]) {
            if (DB::table($table)->where($col, $id)->exists()) {
                return back()->with('error', 'This record is in use ('.str_replace('_', ' ', $table).') and cannot be deleted.'.(array_key_exists('is_active', $def['fields']) ? ' Deactivate it instead.' : ''));
            }
        }
        $row->delete();
        ActivityLogger::log('masters', 'delete', "Deleted {$def['title']}: ".$this->label($row));

        return back()->with('success', 'Deleted.');
    }

    /* ── room photos ───────────────────────────────────────────────────────── */

    /** Masters whose records carry photos (rooms, for now). */
    protected function photoMaster(array $def): bool
    {
        return ! empty($def['photos']);
    }

    /** Check the upload before the room row is written, so a bad file cannot leave a half-saved room. */
    protected function validateRoomPhotos(Request $request, array $def): void
    {
        if (! $this->photoMaster($def) || ! $request->hasFile('photos')) {
            return;
        }
        $request->validate(RoomPhotoService::rules(), RoomPhotoService::validationMessages(), ['photos' => 'room photos', 'photos.*' => 'room photo']);
    }

    /** Save the photos that came with the room form. */
    protected function storeRoomPhotos(Request $request, array $def, $row): void
    {
        if (! $this->photoMaster($def) || ! $request->hasFile('photos')) {
            return;
        }
        $caption = $request->input('photo_caption');
        $caption = is_string($caption) ? $caption : null;

        foreach ((array) $request->file('photos') as $file) {
            if ($file instanceof \Illuminate\Http\UploadedFile && $file->isValid()) {
                RoomPhotoService::store($row, $file, $caption);
            }
        }
    }

    /** Add photos to a room from its own card, after the room exists. */
    public function storePhoto(Request $request, string $master, int $id)
    {
        $def = $this->def($master);
        abort_unless($this->photoMaster($def), 404);
        $row = $def['model']::findOrFail($id);

        $request->validate(RoomPhotoService::rules(), RoomPhotoService::validationMessages(), ['photos' => 'room photos', 'photos.*' => 'room photo']);
        $caption = $request->input('photo_caption');
        $caption = is_string($caption) ? $caption : null;

        $saved = 0;
        foreach ((array) $request->file('photos') as $file) {
            if ($file instanceof \Illuminate\Http\UploadedFile && $file->isValid()) {
                RoomPhotoService::store($row, $file, $caption);
                $saved++;
            }
        }
        if ($saved === 0) {
            return back()->with('error', 'Choose at least one photo to upload.');
        }
        ActivityLogger::log('masters', 'update', "Added {$saved} photo(s) to {$def['title']} ".$this->label($row), $row);

        return back()->with('success', $saved === 1 ? 'Room photo saved.' : $saved.' room photos saved.');
    }

    /** Choose the picture the desk shows for this room. */
    public function photoPrimary(string $master, int $id, int $image)
    {
        $def = $this->def($master);
        abort_unless($this->photoMaster($def), 404);
        $row = $def['model']::findOrFail($id);
        $photo = $row->images()->findOrFail($image);

        RoomPhotoService::makePrimary($photo);
        ActivityLogger::log('masters', 'update', "Primary photo set for {$def['title']} ".$this->label($row), $row);

        return back()->with('success', 'Primary photo updated.');
    }

    /** Take a photo off the room record (hidden, never deleted). */
    public function photoHide(string $master, int $id, int $image)
    {
        $def = $this->def($master);
        abort_unless($this->photoMaster($def), 404);
        $row = $def['model']::findOrFail($id);
        $photo = $row->images()->findOrFail($image);

        RoomPhotoService::hide($photo);
        ActivityLogger::log('masters', 'update', "Removed a photo from {$def['title']} ".$this->label($row), $row);

        return back()->with('success', 'Photo removed from the room record.');
    }

    /** Save a menu photo as a small JPEG. Shared demo pictures are never deleted; only this hotel's own upload is. */
    protected function storeImages(Request $request, array $def, $row): void
    {
        foreach ($def['fields'] as $name => $f) {
            if (($f[1] ?? '') !== 'image') {
                continue;
            }
            $column = $f[3] ?? 'image_path';
            $old = $row->{$column};
            if ($request->hasFile($name)) {
                $dir = public_path('uploads/menu/'.$row->hotel_id);
                is_dir($dir) || mkdir($dir, 0755, true);
                $rel = 'uploads/menu/'.$row->hotel_id.'/'.$row->id.'-'.substr(bin2hex(random_bytes(4)), 0, 8).'.jpg';
                $this->saveMenuJpeg($request->file($name)->getRealPath(), public_path($rel), $name);
                $row->update([$column => $rel]);
                $this->deleteMenuUpload($old, (int) $row->hotel_id);
            } elseif ($request->boolean('remove_'.$name)) {
                $row->update([$column => null]);
                $this->deleteMenuUpload($old, (int) $row->hotel_id);
            }
        }
    }

    protected function saveMenuJpeg(string $src, string $dest, string $field): void
    {
        $info = @getimagesize($src);
        $im = $info ? match ($info[2]) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($src),
            IMAGETYPE_PNG => @imagecreatefrompng($src),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($src) : false,
            default => false,
        } : false;
        if (! $im) {
            throw \Illuminate\Validation\ValidationException::withMessages([$field => 'Could not read that image. Use a JPG, PNG or WebP photo.']);
        }
        $w = imagesx($im);
        $h = imagesy($im);
        $scale = min(1, 800 / max($w, $h));
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));
        $out = imagecreatetruecolor($nw, $nh);
        $white = imagecolorallocate($out, 255, 255, 255);
        imagefilledrectangle($out, 0, 0, $nw, $nh, $white);
        imagecopyresampled($out, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagejpeg($out, $dest, 82);
        imagedestroy($im);
        imagedestroy($out);
    }

    protected function deleteMenuUpload(?string $path, int $hotelId): void
    {
        if (! $path || str_contains($path, '..') || ! preg_match('#^uploads/menu/'.$hotelId.'/#', $path)) {
            return;
        }
        $full = public_path($path);
        if (is_file($full)) {
            @unlink($full);
        }
    }

    protected function label($row): string
    {
        return (string) ($row->name ?? $row->room_no ?? $row->table_no ?? $row->code ?? '#'.$row->id);
    }
}
