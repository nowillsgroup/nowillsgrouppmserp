<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('folios') && ! Schema::hasColumn('folios', 'consolidated_into_folio_id')) {
            // IDs are retained as historical identifiers, even if a folio is
            // later removed. Do not cascade-delete or null out provenance.
            Schema::table('folios', fn (Blueprint $t) => $t->unsignedBigInteger('consolidated_into_folio_id')->nullable()->index());
        }
        if (! Schema::hasTable('reservation_bill_transfers')) {
            Schema::create('reservation_bill_transfers', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('hotel_id')->index();
                $t->unsignedBigInteger('reservation_id')->index();
                $t->unsignedBigInteger('folio_transaction_id')->unique();
                $t->unsignedBigInteger('source_folio_id')->index();
                $t->unsignedBigInteger('target_folio_id')->index();
                $t->unsignedBigInteger('actor_user_id')->nullable();
                $t->timestamp('created_at');
            });
        }
    }

    public function down(): void
    {
        if ((Schema::hasTable('reservation_bill_transfers') && DB::table('reservation_bill_transfers')->exists())
            || (Schema::hasTable('folios') && Schema::hasColumn('folios', 'consolidated_into_folio_id') && DB::table('folios')->whereNotNull('consolidated_into_folio_id')->exists())) {
            throw new RuntimeException('Cannot remove reservation bill consolidation history.');
        }
        Schema::dropIfExists('reservation_bill_transfers');
        if (Schema::hasTable('folios') && Schema::hasColumn('folios', 'consolidated_into_folio_id')) {
            if (Schema::hasIndex('folios', ['consolidated_into_folio_id'])) {
                Schema::table('folios', fn (Blueprint $t) => $t->dropIndex(['consolidated_into_folio_id']));
            }
            Schema::table('folios', fn (Blueprint $t) => $t->dropColumn('consolidated_into_folio_id'));
        }
    }
};
