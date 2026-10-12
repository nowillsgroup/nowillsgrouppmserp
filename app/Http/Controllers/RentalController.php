<?php

namespace App\Http\Controllers;

use App\Models\CarRental;
use App\Models\RentalVehicle;
use App\Services\RentalService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** v7.40 Car Rental — self-drive fleet and booking desk. */
class RentalController extends Controller
{
    public function index()
    {
        $s = RentalService::snapshot();

        return view('rentals.index', [
            'dueOut' => $s['due_out'], 'dueIn' => $s['due_in'], 'fleet' => $s['fleet'],
            'bookings' => CarRental::with('vehicle')->latest('id')->limit(60)->get(),
            'vehicles' => RentalVehicle::where('status', '!=', 'retired')->orderBy('class')->orderBy('make')->get(),
            'stays' => \App\Models\ReservationRoom::with(['reservation:id,guest_id,reference', 'room:id,number'])
                ->whereHas('room', fn ($q) => $q->where('hotel_id', Auth::user()->hotel_id))
                ->where('status', 'checked_in')->orderBy('arrival_date')->limit(50)->get(),
        ]);
    }

    public function fleet()
    {
        return view('rentals.fleet', ['fleet' => RentalVehicle::orderBy('class')->orderBy('make')->get()]);
    }

    public function vehicleStore(Request $request)
    {
        $data = $request->validate([
            'reg_no' => ['required', 'string', 'max:40'],
            'make' => ['required', 'string', 'max:60'],
            'model' => ['required', 'string', 'max:60'],
            'class' => ['required', 'in:economy,standard,suv,luxury,van'],
            'seats' => ['required', 'integer', 'min:1', 'max:20'],
            'transmission' => ['required', 'in:manual,automatic'],
            'fuel' => ['required', 'in:petrol,diesel,hybrid,electric'],
            'daily_rate' => ['required', 'numeric', 'min:0'],
            'weekly_rate' => ['nullable', 'numeric', 'min:0'],
            'deposit' => ['nullable', 'numeric', 'min:0'],
        ]);
        if (RentalVehicle::where('hotel_id', Auth::user()->hotel_id)->where('reg_no', $data['reg_no'])->exists()) {
            return back()->withErrors(['reg_no' => 'A vehicle with that registration is already on the fleet.']);
        }
        $v = new RentalVehicle($data + ['deposit' => $data['deposit'] ?? 0, 'status' => 'available']);
        $v->hotel_id = Auth::user()->hotel_id; // guarded — set explicitly
        $v->save();

        return back()->with('success', "{$v->reg_no} added to the rental fleet.");
    }

    public function vehicleUpdate(Request $request, RentalVehicle $vehicle)
    {
        $data = $request->validate([
            'status' => ['required', 'in:available,rented,maintenance,retired'],
            'daily_rate' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);
        $vehicle->update($data);

        return back()->with('success', "{$vehicle->reg_no} updated.");
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'rental_vehicle_id' => ['required', 'integer'],
            'customer_name' => ['required', 'string', 'max:120'],
            'customer_phone' => ['nullable', 'string', 'max:40'],
            'license_no' => ['nullable', 'string', 'max:60'],
            'pickup_at' => ['required', 'date'],
            'return_due_at' => ['required', 'date'],
            'reservation_room_id' => ['nullable', 'integer'],
            'extras' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
        $r = RentalService::book($data, Auth::user());

        return back()->with('success', "Rental {$r->reference} booked ({$r->vehicle->reg_no}) — total GH₵ ".number_format($r->total, 2).'.');
    }

    public function pickup(Request $request, CarRental $rental)
    {
        $data = $request->validate(['mileage' => ['nullable', 'integer', 'min:0'], 'fuel' => ['nullable', 'numeric', 'min:0', 'max:100']]);
        RentalService::pickUp($rental, $data['mileage'] ?? null, isset($data['fuel']) ? (float) $data['fuel'] : null, Auth::user());

        return back()->with('success', "{$rental->reference} picked up.");
    }

    public function return(Request $request, CarRental $rental)
    {
        $data = $request->validate(['mileage' => ['nullable', 'integer', 'min:0'], 'fuel' => ['nullable', 'numeric', 'min:0', 'max:100']]);
        $r = RentalService::return($rental, $data['mileage'] ?? null, isset($data['fuel']) ? (float) $data['fuel'] : null, Auth::user());

        return back()->with('success', "{$r->reference} returned — adjustments GH₵ ".number_format($r->adjustments, 2).', total GH₵ '.number_format($r->total, 2).'.');
    }

    public function cancel(Request $request, CarRental $rental)
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:200']]);
        RentalService::cancel($rental, $data['reason'] ?? null, Auth::user());

        return back()->with('success', "{$rental->reference} cancelled.");
    }

    public function bill(CarRental $rental)
    {
        RentalService::chargeToFolio($rental);

        return back()->with('success', "{$rental->reference} billed to the guest folio.");
    }
}
