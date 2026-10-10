<?php

namespace App\Http\Controllers;

use App\Models\KotPrintJob;
use App\Models\KotPrinter;
use App\Models\MenuCategory;
use App\Models\Outlet;
use App\Services\ActivityLogger;
use App\Services\KotPrintService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** KOT printer master, category routing and the print log. */
class KotPrinterController extends Controller
{
    public function __construct(protected KotPrintService $kot) {}

    public function index()
    {
        $stations = MenuCategory::whereNotNull('kitchen_station')->distinct()->orderBy('kitchen_station')->pluck('kitchen_station')
            ->prepend('Kitchen')->unique()->values();

        return view('pos.printers', [
            'printers' => KotPrinter::with('outlet')->orderBy('sort_order')->orderBy('name')->get(),
            'outlets' => Outlet::where('is_active', true)->orderBy('name')->pluck('name', 'id'),
            'stations' => $stations,
            'categories' => MenuCategory::with('outlet', 'printer')->withCount('items')->orderBy('sort_order')->orderBy('name')->get(),
            'jobs' => KotPrintJob::with('printer', 'order', 'user')->latest('id')->limit(25)->get(),
        ]);
    }

    public function store(Request $request)
    {
        KotPrinter::create($this->validated($request));
        ActivityLogger::log('pos', 'kot_printer', 'Added KOT printer '.$request->input('name'));

        return back()->with('success', 'Printer added. Map menu categories below, or leave them on station matching.');
    }

    public function update(Request $request, KotPrinter $printer)
    {
        $printer->update($this->validated($request, $printer->id));
        ActivityLogger::log('pos', 'kot_printer', 'Updated KOT printer '.$printer->name, $printer);

        return back()->with('success', $printer->name.' saved.');
    }

    public function destroy(KotPrinter $printer)
    {
        if ($printer->jobs()->exists()) {
            $printer->update(['is_active' => false]);

            return back()->with('success', $printer->name.' has print history, so it was switched off instead of deleted.');
        }
        $name = $printer->name;
        $printer->delete();
        ActivityLogger::log('pos', 'kot_printer', 'Removed KOT printer '.$name);

        return back()->with('success', $name.' removed.');
    }

    public function routing(Request $request)
    {
        $data = $request->validate([
            'category' => 'nullable|array',
            'category.*' => 'nullable|integer',
        ]);
        $allowed = KotPrinter::pluck('id')->all();
        foreach (MenuCategory::orderBy('id')->get() as $cat) {
            $id = (int) ($data['category'][$cat->id] ?? 0);
            $cat->update(['kot_printer_id' => $id && in_array($id, $allowed, true) ? $id : null]);
        }
        ActivityLogger::log('pos', 'kot_routing', 'Updated KOT printer routing');

        return back()->with('success', 'Routing saved. New KOTs use this mapping; reprints of old tickets keep the printer they were sent to.');
    }

    public function test(KotPrinter $printer)
    {
        return view('pos.kot-test', ['printer' => $printer]);
    }

    public function sendTest(KotPrinter $printer)
    {
        if ($printer->connection !== 'network') {
            return redirect()->route('pos.printers.test', $printer)->with('success', 'This printer uses the browser. Use Print on the test slip.');
        }
        try {
            $this->kot->guardHost($printer->host);
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }
        $errno = 0;
        $err = '';
        $fp = @stream_socket_client('tcp://'.$printer->host.':'.((int) $printer->port ?: 9100), $errno, $err, 2);
        if (! $fp) {
            return back()->with('error', $printer->name.' did not answer'.($err ? ': '.$err : '.'));
        }
        stream_set_timeout($fp, 2);
        fwrite($fp, $this->kot->testTicket($printer));
        fclose($fp);
        ActivityLogger::log('pos', 'kot_printer_test', 'Test page sent to '.$printer->name, $printer);

        return back()->with('success', 'Test page sent to '.$printer->name.'.');
    }

    public function retry(KotPrintJob $job)
    {
        $job->load('printer');
        $job->forceFill(['status' => 'queued', 'error' => null])->save();
        if ($job->printer?->connection === 'network') {
            $this->kot->sendJob($job->fresh('printer'));
            $job->refresh();

            return back()->with($job->status === 'failed' ? 'error' : 'success', $job->status === 'failed'
                ? ($job->printer?->name.' is still offline. '.$job->error)
                : 'Sent again to '.$job->printer?->name.'.');
        }

        return redirect()->route('pos.printkot', ['order' => $job->pos_order_id, 'kot' => $job->kot_no, 'job' => $job->id]);
    }

    protected function validated(Request $request, ?int $id = null): array
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:12', 'regex:/^[A-Za-z0-9_-]+$/', Rule::unique('kot_printers', 'code')->where('hotel_id', hotel()->id)->ignore($id)],
            'name' => 'required|string|max:80',
            'outlet_id' => 'nullable|integer|owned:outlets',
            'station' => 'nullable|string|max:40',
            'role' => 'required|in:station,captain',
            'connection' => 'required|in:browser,network',
            'host' => 'nullable|string|max:80',
            'port' => 'nullable|integer|min:1|max:65535',
            'paper' => 'required|in:58,80',
            'copies' => 'required|integer|min:1|max:5',
            'header' => 'nullable|string|max:80',
            'sort_order' => 'nullable|integer|min:0|max:999',
            'notes' => 'nullable|string|max:200',
        ]);
        $data['code'] = strtoupper($data['code']);
        $data['beep'] = $request->boolean('beep');
        $data['is_fallback'] = $request->boolean('is_fallback') && $data['role'] === 'station';
        $data['is_active'] = $request->boolean('is_active');
        $data['outlet_id'] = ($data['outlet_id'] ?? null) ?: null;
        $data['station'] = $data['role'] === 'captain' ? null : (($data['station'] ?? null) ?: null);
        $data['header'] = $data['header'] ?? null;
        $data['notes'] = $data['notes'] ?? null;
        $data['port'] = (int) ($data['port'] ?? 9100);
        $data['sort_order'] = (int) ($data['sort_order'] ?? 0);
        $data['host'] = $data['connection'] === 'network' ? trim((string) ($data['host'] ?? '')) : null;
        if ($data['connection'] === 'network') {
            try {
                $this->kot->guardHost($data['host']);
            } catch (\InvalidArgumentException $e) {
                throw ValidationException::withMessages(['host' => $e->getMessage()]);
            }
        }

        return $data;
    }
}
