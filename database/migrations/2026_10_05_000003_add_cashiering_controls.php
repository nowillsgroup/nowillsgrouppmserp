<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cashiering controls: auditable cash drawer movements and close-out evidence.
 * All additions are nullable/defaulted so existing shifts and production data remain valid.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cashier_shifts', function (Blueprint $table) {
            $table->json('counted_denominations')->nullable()->after('counted_cash');
            $table->foreignId('closed_by')->nullable()->after('counted_denominations')->constrained('users')->nullOnDelete();
        });

        Schema::create('cashier_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cashier_shift_id')->constrained('cashier_shifts')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->date('business_date');
            $table->string('kind', 8); // cash_in | cash_out
            $table->decimal('amount', 12, 2);
            $table->string('reason', 190);
            $table->string('reference', 80)->nullable();
            $table->timestamps();
            $table->index(['hotel_id', 'business_date', 'kind']);
            $table->index(['cashier_shift_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cashier_movements');
        Schema::table('cashier_shifts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('closed_by');
            $table->dropColumn('counted_denominations');
        });
    }
};
