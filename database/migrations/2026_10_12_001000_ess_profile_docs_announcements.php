<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** v7.40 Employee self-service — profile change requests, document uploads, HR announcements. */
return new class extends Migration
{
    public function up(): void
    {
                if (Schema::hasTable('hr_profile_updates')) {
            return;
        }

        Schema::create('hr_profile_updates', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('hotel_id')->index();
            $t->unsignedBigInteger('employee_id')->index();
            $t->unsignedBigInteger('requested_by');
            $t->json('changes'); // {field: {"old": …, "new": …}}
            $t->string('status', 20)->default('pending')->index(); // pending | approved | rejected
            $t->unsignedBigInteger('reviewed_by')->nullable();
            $t->timestamp('reviewed_at')->nullable();
            $t->string('review_note', 500)->nullable();
            $t->timestamps();
        });

                if (Schema::hasTable('hr_documents')) {
            return;
        }

        Schema::create('hr_documents', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('hotel_id')->index();
            $t->unsignedBigInteger('employee_id')->index();
            $t->unsignedBigInteger('uploaded_by');
            $t->string('title', 200);
            $t->string('kind', 60)->nullable(); // e.g. ID, certificate, contract
            $t->string('file_path', 500);
            $t->string('mime', 100)->nullable();
            $t->unsignedBigInteger('bytes')->default(0);
            $t->string('status', 20)->default('new')->index(); // new | verified | rejected
            $t->string('review_note', 500)->nullable();
            $t->unsignedBigInteger('reviewed_by')->nullable();
            $t->timestamp('reviewed_at')->nullable();
            $t->timestamps();
        });

                if (Schema::hasTable('hr_announcements')) {
            return;
        }

        Schema::create('hr_announcements', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('hotel_id')->index();
            $t->string('title', 200);
            $t->text('body');
            $t->string('audience', 20)->default('staff'); // all | staff | department
            $t->unsignedBigInteger('department_id')->nullable();
            $t->boolean('is_pinned')->default(false);
            $t->timestamp('publish_at')->nullable();
            $t->unsignedBigInteger('created_by');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_announcements');
        Schema::dropIfExists('hr_documents');
        Schema::dropIfExists('hr_profile_updates');
    }
};
