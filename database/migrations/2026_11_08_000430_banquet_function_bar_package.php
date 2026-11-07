<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A drinks package beside the food package.
 *
 * A function could hold one package, so a wedding with a wine package had to type the bar
 * in as a line of extras — priced per event, taxed as a service, and invisible on the menu
 * sheet the kitchen and the bar both read. A function now carries a second package slot
 * with its own rate and tax rate, billed as its own line.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('banquet_functions', function (Blueprint $table) {
            $table->foreignId('banquet_bar_package_id')->nullable()->after('banquet_package_id')->constrained('banquet_packages')->nullOnDelete();
            $table->decimal('bar_rate_per_pax', 12, 2)->default(0)->after('rate_per_pax');
            $table->decimal('bar_tax_rate', 5, 2)->default(18)->after('bar_rate_per_pax');
        });
    }

    public function down(): void
    {
        Schema::table('banquet_functions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('banquet_bar_package_id');
            $table->dropColumn(['bar_rate_per_pax', 'bar_tax_rate']);
        });
    }
};
