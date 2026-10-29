<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('club_day_pass_payments')) {
            return;
        }

        Schema::create('club_day_pass_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('club_day_pass_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_mode_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('reference', 80)->nullable();
            $table->string('momo_network', 20)->nullable();
            $table->string('momo_phone', 30)->nullable();
            $table->string('cheque_bank_name', 100)->nullable();
            $table->string('cheque_number', 20)->nullable();
            $table->string('cheque_bank_branch', 100)->nullable();
            $table->timestamps();
            $table->index(['hotel_id', 'club_day_pass_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('club_day_pass_payments');
    }
};
