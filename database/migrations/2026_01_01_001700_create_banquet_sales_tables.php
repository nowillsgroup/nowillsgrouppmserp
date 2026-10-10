<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * v2.5 banquet & conference sales: venue setups / capacities / half- & full-day rates, menu packages, event services,
 * multi-function events with extras, tentative holds with option dates, deposits on the event folio, guarantees,
 * cancellation fees and lost business.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('banquet_halls', function (Blueprint $t) {
            $t->string('code', 12)->nullable()->after('name');
            $t->unsignedInteger('area_sqft')->nullable()->after('capacity');
            foreach (['theatre', 'classroom', 'banquet', 'ushape', 'cocktail', 'boardroom'] as $s) {
                $t->unsignedInteger('cap_'.$s)->nullable();
            }
            $t->decimal('rate_full_day', 12, 2)->default(0)->after('rate_per_session'); // rate_per_session = half-day (session) rate
            $t->boolean('is_outdoor')->default(false);
            $t->unsignedSmallInteger('sort')->default(0);
        });

        Schema::create('banquet_packages', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('name', 120);
            $t->string('type', 20)->default('food');   // food, beverage, break
            $t->string('style', 20)->default('buffet'); // buffet, plated, cocktail, break, bar
            $t->decimal('price_per_pax', 12, 2)->default(0);
            $t->unsignedInteger('min_pax')->default(0);
            $t->decimal('tax_rate', 5, 2)->default(18);
            $t->text('items')->nullable(); // one dish / inclusion per line
            $t->boolean('is_active')->default(true);
            $t->unsignedSmallInteger('sort')->default(0);
            $t->timestamps();
        });

        Schema::create('banquet_services', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('name', 120);
            $t->string('category', 20)->default('equipment'); // av, decor, entertainment, equipment, staffing, other
            $t->string('unit', 12)->default('event');         // event, day, piece, pax, hour
            $t->decimal('price', 12, 2)->default(0);
            $t->decimal('tax_rate', 5, 2)->default(18);
            $t->foreignId('department_id')->nullable()->constrained()->nullOnDelete(); // who delivers it (BEO routing)
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::table('banquet_events', function (Blueprint $t) {
            $t->date('end_date')->nullable()->after('event_date');
            $t->string('contact_email', 150)->nullable()->after('contact_phone');
            $t->string('source', 20)->nullable()->after('event_type'); // phone, email, walk_in, website, agent, repeat
            $t->foreignId('sales_user_id')->nullable()->after('source')->constrained('users')->nullOnDelete();
            $t->foreignId('room_block_id')->nullable()->after('company_id')->constrained()->nullOnDelete();
            $t->date('option_date')->nullable()->after('status');
            $t->decimal('deposit_due', 12, 2)->default(0)->after('advance');
            $t->date('deposit_due_date')->nullable()->after('deposit_due');
            $t->string('lost_reason', 255)->nullable();
            $t->decimal('cancellation_fee', 12, 2)->nullable();
            $t->string('cancel_reason', 255)->nullable();
            $t->timestamp('confirmed_at')->nullable();
            $t->timestamp('closed_at')->nullable(); // completed / cancelled / lost
            $t->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('billed_at')->nullable();
            $t->text('internal_notes')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->index(['hotel_id', 'status', 'event_date']);
        });

        Schema::create('banquet_functions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('banquet_event_id')->constrained()->cascadeOnDelete();
            $t->foreignId('banquet_hall_id')->constrained()->restrictOnDelete();
            $t->string('name', 120);
            $t->date('date');
            $t->time('start_time');
            $t->time('end_time');
            $t->string('setup_style', 20)->default('banquet');
            $t->unsignedInteger('expected_pax')->default(0);
            $t->unsignedInteger('guaranteed_pax')->nullable();
            $t->unsignedInteger('actual_pax')->nullable();
            $t->foreignId('banquet_package_id')->nullable()->constrained()->nullOnDelete();
            $t->decimal('rate_per_pax', 12, 2)->default(0);
            $t->decimal('fnb_tax_rate', 5, 2)->default(18);
            $t->decimal('hall_rate', 12, 2)->default(0);
            $t->decimal('hall_tax_rate', 5, 2)->default(18);
            $t->text('notes')->nullable(); // kitchen / set-up instructions for the BEO
            $t->unsignedSmallInteger('sort')->default(0);
            $t->timestamps();
            $t->index(['hotel_id', 'banquet_hall_id', 'date']);
        });

        Schema::create('banquet_event_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('banquet_function_id')->constrained()->cascadeOnDelete();
            $t->foreignId('banquet_service_id')->nullable()->constrained()->nullOnDelete();
            $t->string('description', 150);
            $t->decimal('qty', 10, 2)->default(1);
            $t->decimal('unit_price', 12, 2)->default(0);
            $t->decimal('tax_rate', 5, 2)->default(18);
            $t->timestamps();
        });

        // ---- carry existing events over: one function each; "confirmed" becomes "definite"
        DB::table('banquet_events')->where('status', 'confirmed')->update(['status' => 'definite']);
        DB::table('banquet_halls')->where('rate_full_day', 0)->update(['rate_full_day' => DB::raw('rate_per_session')]);
        foreach (DB::table('banquet_events')->get() as $e) {
            DB::table('banquet_events')->where('id', $e->id)->update(['end_date' => $e->event_date]);
            $fid = DB::table('banquet_functions')->insertGetId([
                'hotel_id' => $e->hotel_id, 'banquet_event_id' => $e->id, 'banquet_hall_id' => $e->banquet_hall_id,
                'name' => ucfirst($e->event_type ?: 'Function'), 'date' => $e->event_date, 'start_time' => $e->start_time, 'end_time' => $e->end_time,
                'setup_style' => 'banquet', 'expected_pax' => $e->expected_pax, 'guaranteed_pax' => $e->guaranteed_pax ?: null,
                'rate_per_pax' => $e->rate_per_pax, 'fnb_tax_rate' => $e->tax_rate, 'hall_rate' => $e->hall_charge, 'hall_tax_rate' => $e->tax_rate,
                'notes' => trim(($e->menu_package ? 'Menu: '.$e->menu_package."\n" : '').($e->requirements ?? '')) ?: null, 'created_at' => now(), 'updated_at' => now(),
            ]);
            if ((float) $e->other_charges > 0) {
                DB::table('banquet_event_items')->insert(['hotel_id' => $e->hotel_id, 'banquet_function_id' => $fid, 'description' => 'Other event services',
                    'qty' => 1, 'unit_price' => $e->other_charges, 'tax_rate' => $e->tax_rate, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('banquet_event_items');
        Schema::dropIfExists('banquet_functions');
        Schema::table('banquet_events', function (Blueprint $t) {
            $t->dropForeign(['sales_user_id']);
            $t->dropForeign(['room_block_id']);
            $t->dropForeign(['closed_by']);
            $t->dropForeign(['created_by']);
            $t->dropIndex(['hotel_id', 'status', 'event_date']);
            $t->dropColumn(['end_date', 'contact_email', 'source', 'sales_user_id', 'room_block_id', 'option_date', 'deposit_due', 'deposit_due_date', 'lost_reason',
                'cancellation_fee', 'cancel_reason', 'confirmed_at', 'closed_at', 'closed_by', 'billed_at', 'internal_notes', 'created_by']);
        });
        Schema::dropIfExists('banquet_services');
        Schema::dropIfExists('banquet_packages');
        Schema::table('banquet_halls', function (Blueprint $t) {
            $t->dropColumn(['code', 'area_sqft', 'cap_theatre', 'cap_classroom', 'cap_banquet', 'cap_ushape', 'cap_cocktail', 'cap_boardroom', 'rate_full_day', 'is_outdoor', 'sort']);
        });
    }
};

