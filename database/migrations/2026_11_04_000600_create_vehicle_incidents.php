<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v7.61 – fleet incident log: accidents, damage, breakdowns and theft. Inspections catch what is wrong
 * with a car before it leaves; this records what happened to it on the road, what the police said and
 * what it is expected to cost, so an insurance claim has something to stand on.
 *
 * Nothing is seeded. A hotel that never has an incident simply never sees a row.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('vehicle_incidents')) {
            return;
        }
        Schema::create('vehicle_incidents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->foreignId('driver_id')->nullable()->constrained('drivers')->nullOnDelete();
            $table->dateTime('occurred_at');
            $table->string('kind', 16)->default('accident');       // accident | damage | breakdown | theft | other
            $table->string('severity', 12)->default('minor');      // minor | major | critical
            $table->string('location', 150)->nullable();
            $table->string('description', 1000);
            $table->string('third_party', 150)->nullable();        // the other vehicle / person / property
            $table->string('police_ref', 60)->nullable();
            $table->decimal('estimated_cost', 12, 2)->nullable();  // an estimate, never treated as posted money
            $table->string('status', 16)->default('open');         // open | investigating | closed
            $table->string('closure_note', 255)->nullable();
            $table->dateTime('closed_at')->nullable();
            $table->foreignId('work_order_id')->nullable()->constrained('vehicle_maintenance_records')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['hotel_id', 'occurred_at']);
            $table->index(['hotel_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_incidents');
    }
};
