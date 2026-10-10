<?php

namespace App\Http\Controllers;

use App\Services\ActivityLogger;
use App\Support\Numbering;
use App\Support\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

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
        $q = $def['model']::query()->with($def['with'] ?? []);
        if ($term = $request->input('q')) {
            $textCols = collect($def['fields'])->filter(fn ($f) => in_array($f[1], ['text', 'email']))->keys();
            $q->where(fn ($w) => $textCols->each(fn ($c) => $w->orWhere($c, 'like', "%$term%")));
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

        return view('masters.form', ['def' => $def, 'row' => new $def['model'](['is_active' => true, 'is_available' => true, 'show_online' => true]), 'options' => $this->options($def)]);
    }

    public function edit(string $master, int $id)
    {
        $def = $this->def($master);

        return view('masters.form', ['def' => $def, 'row' => $def['model']::findOrFail($id), 'options' => $this->options($def)]);
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

    public function store(Request $request, string $master)
    {
        $def = $this->def($master);
        $row = $def['model']::create($this->validated($request, $def));
        $this->storeImages($request, $def, $row);
        ActivityLogger::log('masters', 'create', "Created {$def['title']}: ".$this->label($row), $row);

        return redirect()->route('masters.index', $master)->with('success', 'Saved.');
    }

    public function update(Request $request, string $master, int $id)
    {
        $def = $this->def($master);
        $row = $def['model']::findOrFail($id);
        $data = $this->validated($request, $def, $id);
        if ($row->getAttribute('system_key') && isset($data['type']) && $data['type'] !== $row->type) {
            return back()->withInput()->with('error', 'This is a system account used by automatic postings – its type cannot be changed.');
        }
        if ($row->getAttribute('system_key') && array_key_exists('is_active', $data) && ! $data['is_active']) {
            return back()->withInput()->with('error', 'System accounts cannot be deactivated.');
        }
        $row->update($data);
        $this->storeImages($request, $def, $row);
        ActivityLogger::log('masters', 'update', "Updated {$def['title']}: ".$this->label($row), $row);

        return redirect()->route('masters.index', $master)->with('success', 'Updated.');
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
