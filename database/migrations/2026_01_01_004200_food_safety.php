<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v4.9→5.0 Food Safety & HACCP: allergen declarations (14 major allergens, Codex / Ghana FDA labelling) on
 * ingredients and menu items with guest-allergy alerts on POS orders / KOT / KDS; cold & hot equipment and
 * probe temperature logs with critical limits and corrective actions; HACCP checklists with manager sign-off;
 * a food-waste log that moves stock; food-handler health certificates; and prep (day-dot) labels.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_items', function (Blueprint $t) {
            $t->json('allergens')->nullable()->after('tax_rate');
        });
        Schema::table('menu_items', function (Blueprint $t) {
            $t->json('allergens')->nullable()->after('description');      // declared "contains"
            $t->json('may_contain')->nullable()->after('allergens');      // cross-contact warning
            $t->timestamp('allergens_verified_at')->nullable()->after('may_contain');
            $t->foreignId('allergens_verified_by')->nullable()->after('allergens_verified_at')->constrained('users')->nullOnDelete();
        });
        Schema::table('pos_orders', function (Blueprint $t) {
            $t->json('allergens')->nullable();                            // allergies the guest(s) declared
            $t->string('allergy_note', 160)->nullable();
        });
        Schema::table('pos_order_items', function (Blueprint $t) {
            $t->string('allergy_flag', 160)->nullable();                  // conflicting allergens, acknowledged by the server
        });

        Schema::create('fs_equipment', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('name', 80);
            $t->string('type', 12);                                        // fridge | freezer | hot_hold | chiller | display
            $t->string('location', 80)->nullable();
            $t->foreignId('outlet_id')->nullable()->constrained()->nullOnDelete();
            $t->decimal('min_c', 5, 1)->nullable();
            $t->decimal('max_c', 5, 1)->nullable();
            $t->unsignedTinyInteger('checks_per_day')->default(2);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('fs_temp_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('kind', 10);                                        // equipment | receiving | cooking | reheating | cooling
            $t->foreignId('fs_equipment_id')->nullable()->constrained('fs_equipment')->nullOnDelete();
            $t->string('item', 120)->nullable();                           // food / delivery checked
            $t->string('category', 8)->nullable();                         // receiving: chilled | frozen | hot
            $t->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $t->decimal('reading_c', 5, 1);                                // cooling: after 2 h
            $t->decimal('reading2_c', 5, 1)->nullable();                   // cooling: after 6 h
            $t->timestamp('reading2_at')->nullable();
            $t->decimal('min_c', 5, 1)->nullable();                        // limits in force when recorded
            $t->decimal('max_c', 5, 1)->nullable();
            $t->boolean('in_range')->default(true);
            $t->string('corrective_action', 255)->nullable();
            $t->dateTime('recorded_at');
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamp('reviewed_at')->nullable();
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('review_note', 255)->nullable();
            $t->timestamps();
            $t->index(['hotel_id', 'recorded_at']);
        });

        Schema::create('fs_checklists', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('name', 100);
            $t->string('frequency', 8)->default('daily');                  // daily | weekly | monthly
            $t->string('area', 60)->nullable();
            $t->json('items');
            $t->time('due_time')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('fs_checklist_runs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('fs_checklist_id')->constrained('fs_checklists')->cascadeOnDelete();
            $t->date('period');                                            // the day / Monday / 1st of month
            $t->json('answers');                                           // [{item, ok, note}]
            $t->unsignedTinyInteger('failed')->default(0);
            $t->string('notes', 500)->nullable();
            $t->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->dateTime('completed_at');
            $t->foreignId('signed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('signed_at')->nullable();
            $t->string('sign_note', 255)->nullable();
            $t->timestamps();
            $t->unique(['hotel_id', 'fs_checklist_id', 'period']);
        });

        Schema::create('fs_waste', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('waste_no', 20);
            $t->date('date');
            $t->string('source', 6);                                       // raw | dish | plate
            $t->foreignId('inventory_item_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('menu_item_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('store_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('outlet_id')->nullable()->constrained()->nullOnDelete();
            $t->decimal('qty', 10, 3);
            $t->string('unit', 12)->nullable();
            $t->string('reason', 16);
            $t->decimal('cost', 12, 2)->default(0);
            $t->string('notes', 255)->nullable();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamp('cancelled_at')->nullable();
            $t->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('cancel_reason', 255)->nullable();
            $t->timestamps();
            $t->unique(['hotel_id', 'waste_no']);
            $t->index(['hotel_id', 'date']);
        });

        Schema::create('fs_certificates', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $t->string('type', 8);                                         // medical | hygiene
            $t->string('cert_no', 40)->nullable();
            $t->string('issuer', 100)->nullable();
            $t->date('issued_on');
            $t->date('expires_on');
            $t->string('notes', 255)->nullable();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamps();
            $t->index(['hotel_id', 'employee_id', 'type']);
        });

        Schema::create('fs_labels', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('item', 80);
            $t->foreignId('menu_item_id')->nullable()->constrained()->nullOnDelete();
            $t->string('storage', 8);                                      // chilled | frozen | ambient | hot
            $t->dateTime('prepared_at');
            $t->dateTime('use_by');
            $t->unsignedSmallInteger('qty')->default(1);
            $t->json('allergens')->nullable();
            $t->string('batch', 30)->nullable();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamps();
            $t->index(['hotel_id', 'use_by']);
        });
    }

    public function down(): void
    {
        foreach (['fs_labels', 'fs_certificates', 'fs_waste', 'fs_checklist_runs', 'fs_checklists', 'fs_temp_logs', 'fs_equipment'] as $tbl) {
            Schema::dropIfExists($tbl);
        }
        Schema::table('pos_order_items', fn (Blueprint $t) => $t->dropColumn('allergy_flag'));
        Schema::table('pos_orders', fn (Blueprint $t) => $t->dropColumn(['allergens', 'allergy_note']));
        Schema::table('menu_items', function (Blueprint $t) {
            $t->dropConstrainedForeignId('allergens_verified_by');
            $t->dropColumn(['allergens', 'may_contain', 'allergens_verified_at']);
        });
        Schema::table('inventory_items', fn (Blueprint $t) => $t->dropColumn('allergens'));
    }
};


