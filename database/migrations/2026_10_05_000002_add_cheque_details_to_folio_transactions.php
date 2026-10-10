<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('folio_transactions', function (Blueprint $table) {
            $table->string('cheque_bank_name', 100)->nullable()->after('payment_mode_id');
            $table->string('cheque_number', 20)->nullable()->after('cheque_bank_name');
            $table->string('cheque_bank_branch', 100)->nullable()->after('cheque_number');
        });

        Schema::table('cheques_received', function (Blueprint $table) {
            $table->string('bank_branch', 100)->nullable()->after('bank_name');
        });
    }

    public function down(): void
    {
        Schema::table('cheques_received', function (Blueprint $table) {
            $table->dropColumn('bank_branch');
        });

        Schema::table('folio_transactions', function (Blueprint $table) {
            $table->dropColumn(['cheque_bank_name', 'cheque_number', 'cheque_bank_branch']);
        });
    }
};
