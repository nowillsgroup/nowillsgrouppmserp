<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The employee file: a photograph and the papers that go with it.
 *
 * Staff could already upload their own documents through self-service, but nobody in HR could
 * put anything on file — no photograph for the roster or the door, no way to load a signed
 * contract on somebody's behalf. The photograph lives on the employee; the documents keep
 * their own table and gain a source, so the review desk can tell a paper the member of staff
 * sent from one HR loaded and already checked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('photo_path', 300)->nullable()->after('name');
        });

        Schema::table('hr_documents', function (Blueprint $table) {
            // ess = uploaded by the member of staff and awaiting review; hr = loaded by HR, already checked
            $table->string('source', 10)->default('ess')->after('kind')->index();
        });
    }

    public function down(): void
    {
        Schema::table('hr_documents', function (Blueprint $table) {
            $table->dropColumn('source');
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('photo_path');
        });
    }
};
