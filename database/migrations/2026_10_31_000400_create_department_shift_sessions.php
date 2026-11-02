<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('department_shift_sessions')) {
            Schema::create('department_shift_sessions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('department', 40)->default('food_beverage');
                $table->string('shift', 20);
                $table->date('business_date');
                $table->dateTime('opened_at');
                $table->dateTime('closed_at')->nullable();
                $table->string('status', 12)->default('open');
                $table->string('opening_note', 500)->nullable();
                $table->string('closing_note', 1000)->nullable();
                $table->json('close_snapshot')->nullable();
                $table->timestamps();
                $table->index(['hotel_id', 'department', 'business_date', 'shift'], 'dept_shift_day_idx');
                $table->index(['hotel_id', 'user_id', 'status'], 'dept_shift_user_status_idx');
            });
        }

        if (Schema::hasTable('pos_orders') && ! Schema::hasColumn('pos_orders', 'department_shift_session_id')) {
            Schema::table('pos_orders', function (Blueprint $table) {
                $table->foreignId('department_shift_session_id')->nullable()->constrained('department_shift_sessions')->nullOnDelete();
                $table->index(['hotel_id', 'department_shift_session_id'], 'pos_order_dept_shift_idx');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('pos_orders') && Schema::hasColumn('pos_orders', 'department_shift_session_id')) {
            Schema::table('pos_orders', function (Blueprint $table) {
                $table->dropIndex('pos_order_dept_shift_idx');
                $table->dropConstrainedForeignId('department_shift_session_id');
            });
        }
        Schema::dropIfExists('department_shift_sessions');
    }
};
