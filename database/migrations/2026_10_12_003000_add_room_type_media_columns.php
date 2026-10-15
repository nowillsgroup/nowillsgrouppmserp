<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * v7.41 Reservation Workspace — room-type media: size (m²) for the room
 * facts and an image for the room-board photo block.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('room_types', function (Blueprint $table) {
            $table->decimal('size_sqm', 6, 1)->nullable()->after('description');
            $table->string('image', 160)->nullable()->after('size_sqm');
        });

        // demo backfill so the workspace board shows real sizes/media
        $sizes = ['STD' => 24, 'DLX' => 32, 'EXE' => 48, 'PRE' => 85];
        $images = ['STD' => 'images/rooms/std.jpg', 'DLX' => 'images/rooms/dlx.jpg', 'EXE' => 'images/rooms/exe.jpg', 'PRE' => 'images/rooms/pre.jpg'];
        foreach ($sizes as $code => $sqm) {
            DB::table('room_types')->where('code', $code)
                ->update(['size_sqm' => $sqm, 'image' => $images[$code]]);
        }
    }

    public function down(): void
    {
        Schema::table('room_types', function (Blueprint $table) {
            $table->dropColumn(['size_sqm', 'image']);
        });
    }
};
