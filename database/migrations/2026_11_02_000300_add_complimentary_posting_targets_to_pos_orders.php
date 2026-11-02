<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_orders', function (Blueprint $table) {
            $table->string('comp_post_to', 20)->nullable()->after('comp_receiver_name');
            $table->foreignId('comp_post_company_id')->nullable()->after('comp_post_to')->constrained('companies')->nullOnDelete();
            $table->foreignId('comp_post_reservation_room_id')->nullable()->after('comp_post_company_id')->constrained('reservation_rooms')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('pos_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('comp_post_company_id');
            $table->dropConstrainedForeignId('comp_post_reservation_room_id');
            $table->dropColumn('comp_post_to');
        });
    }
};
