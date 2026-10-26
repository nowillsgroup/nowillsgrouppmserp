<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reservation command centre additions. Additive only: no reservation, guest,
 * room, folio or payment table is rebuilt. Existing reservation IDs are untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
                if (Schema::hasTable('room_amenities')) {
            return;
        }

        Schema::create('room_amenities', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('name', 80);
            $t->string('category', 40)->default('general');
            $t->string('description', 255)->nullable();
            $t->boolean('show_on_desk')->default(true);
            $t->boolean('show_online')->default(true);
            $t->boolean('is_active')->default(true);
            $t->unsignedSmallInteger('sort_order')->default(0);
            $t->timestamps();
            $t->unique(['hotel_id', 'name']);
        });

                if (Schema::hasTable('room_amenity_links')) {
            return;
        }

        Schema::create('room_amenity_links', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('room_amenity_id')->constrained('room_amenities')->cascadeOnDelete();
            $t->foreignId('room_id')->nullable()->constrained()->cascadeOnDelete();
            $t->foreignId('room_type_id')->nullable()->constrained()->cascadeOnDelete();
            $t->timestamps();
            $t->index(['hotel_id', 'room_id']);
            $t->index(['hotel_id', 'room_type_id']);
        });

                if (Schema::hasTable('room_images')) {
            return;
        }

        Schema::create('room_images', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('room_id')->nullable()->constrained()->cascadeOnDelete();
            $t->foreignId('room_type_id')->nullable()->constrained()->cascadeOnDelete();
            $t->string('path');
            $t->string('caption', 160)->nullable();
            $t->boolean('is_primary')->default(false);
            $t->unsignedSmallInteger('sort_order')->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

                if (Schema::hasTable('room_outages')) {
            return;
        }

        Schema::create('room_outages', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('room_id')->constrained()->cascadeOnDelete();
            $t->date('starts_on');
            $t->date('ends_on'); // exclusive, same convention as a departure date
            $t->string('reason', 30)->default('maintenance');
            $t->string('notes', 255)->nullable();
            $t->string('status', 20)->default('active'); // active, released
            $t->timestamp('released_at')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['hotel_id', 'status', 'starts_on']);
        });

                if (Schema::hasTable('reservation_waitlists')) {
            return;
        }

        Schema::create('reservation_waitlists', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('guest_id')->nullable()->constrained()->nullOnDelete();
            $t->string('contact_name', 120);
            $t->string('phone', 30)->nullable();
            $t->string('email')->nullable();
            $t->date('arrival_date');
            $t->date('departure_date');
            $t->foreignId('room_type_id')->nullable()->constrained()->nullOnDelete();
            $t->unsignedSmallInteger('adults')->default(1);
            $t->unsignedSmallInteger('children')->default(0);
            $t->unsignedSmallInteger('priority')->default(0);
            $t->string('notes', 500)->nullable();
            $t->string('status', 20)->default('waiting'); // waiting, converted, cancelled
            $t->foreignId('reservation_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['hotel_id', 'status', 'arrival_date']);
        });

                if (Schema::hasTable('reservation_desk_views')) {
            return;
        }

        Schema::create('reservation_desk_views', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('name', 80);
            $t->boolean('is_default')->default(false);
            $t->string('query', 1000);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservation_desk_views');
        Schema::dropIfExists('reservation_waitlists');
        Schema::dropIfExists('room_outages');
        Schema::dropIfExists('room_images');
        Schema::dropIfExists('room_amenity_links');
        Schema::dropIfExists('room_amenities');
    }
};

