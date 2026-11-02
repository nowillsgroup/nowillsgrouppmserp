<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('front_desk_shift_sessions')) {
            Schema::create('front_desk_shift_sessions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();
                $table->date('business_date');
                $table->string('shift', 10); // morning | evening | night, captured at sign-in
                $table->string('status', 20)->default('open'); // open | handover_pending | closed
                $table->foreignId('started_by')->nullable()->constrained('users')->nullOnDelete();
                $table->dateTime('started_at');
                $table->text('start_note')->nullable();
                $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->dateTime('closed_at')->nullable();
                $table->foreignId('incoming_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->text('handover_note')->nullable();
                $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
                $table->dateTime('received_at')->nullable();
                $table->timestamps();
                $table->index(['hotel_id', 'status', 'started_at'], 'desk_shift_hotel_status_started_idx');
                $table->index(['hotel_id', 'started_by', 'status'], 'desk_shift_hotel_user_status_idx');
                $table->index(['hotel_id', 'incoming_user_id', 'status'], 'desk_shift_hotel_incoming_status_idx');
            });
        }

        if (Schema::hasTable('log_entries') && ! Schema::hasColumn('log_entries', 'shift_session_id')) {
            Schema::table('log_entries', function (Blueprint $table) {
                $table->foreignId('shift_session_id')->nullable()->after('shift')
                    ->constrained('front_desk_shift_sessions')->nullOnDelete();
                $table->index(['hotel_id', 'shift_session_id'], 'log_entries_hotel_shift_session_idx');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('log_entries') && Schema::hasColumn('log_entries', 'shift_session_id')) {
            Schema::table('log_entries', function (Blueprint $table) {
                $table->dropForeign(['shift_session_id']);
                $table->dropIndex('log_entries_hotel_shift_session_idx');
                $table->dropColumn('shift_session_id');
            });
        }

        Schema::dropIfExists('front_desk_shift_sessions');
    }
};
