<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_orders', function (Blueprint $table) {
            $table->string('comp_department', 100)->nullable()->after('comp_reason');
            $table->string('comp_manager_name', 120)->nullable()->after('comp_department');
            $table->string('comp_receiver_name', 120)->nullable()->after('comp_manager_name');
        });
    }

    public function down(): void
    {
        Schema::table('pos_orders', function (Blueprint $table) {
            $table->dropColumn(['comp_department', 'comp_manager_name', 'comp_receiver_name']);
        });
    }
};
