<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Core platform schema: hotel groups, hotels (tenants), RBAC, users, sessions, settings, audit log.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hotel_groups', function (Blueprint $t) {
            $t->id();
            $t->string('code', 30)->unique();
            $t->string('name');
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('hotels', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_group_id')->nullable()->constrained('hotel_groups')->nullOnDelete();
            $t->string('code', 30)->unique();          // "Hotel ID" used at login
            $t->string('name');
            $t->string('legal_name')->nullable();
            $t->string('property_type', 30)->default('hotel'); // hotel, resort, guest_house, restaurant, cafe
            $t->string('address')->nullable();
            $t->string('city')->nullable();
            $t->string('state')->nullable();
            $t->string('country')->nullable();
            $t->string('pincode', 20)->nullable();
            $t->string('phone', 30)->nullable();
            $t->string('email')->nullable();
            $t->string('website')->nullable();
            $t->string('tax_number', 40)->nullable();   // GSTIN / VAT / TIN
            $t->string('currency', 10)->default('INR');
            $t->string('currency_symbol', 5)->default('₹');
            $t->string('timezone', 50)->default('Asia/Kolkata');
            $t->time('checkin_time')->default('12:00:00');
            $t->time('checkout_time')->default('11:00:00');
            $t->date('business_date');                   // PMS business (audit) date
            $t->string('logo_path')->nullable();
            $t->text('invoice_footer')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('roles', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('name', 60);
            $t->string('description')->nullable();
            $t->boolean('is_system')->default(false);
            $t->timestamps();
            $t->unique(['hotel_id', 'name']);
        });

        Schema::create('permissions', function (Blueprint $t) {
            $t->id();
            $t->string('key', 80)->unique();
            $t->string('module', 60);
            $t->string('label');
        });

        Schema::create('permission_role', function (Blueprint $t) {
            $t->foreignId('role_id')->constrained()->cascadeOnDelete();
            $t->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $t->primary(['role_id', 'permission_id']);
        });

        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->nullable()->constrained()->cascadeOnDelete();
            $t->foreignId('hotel_group_id')->nullable()->constrained('hotel_groups')->nullOnDelete();
            $t->foreignId('role_id')->nullable()->constrained()->nullOnDelete();
            $t->string('name');
            $t->string('username', 60);
            $t->string('email')->nullable();
            $t->string('phone', 30)->nullable();
            $t->string('password');
            $t->boolean('is_active')->default(true);
            $t->boolean('is_super_admin')->default(false); // full access inside hotel
            $t->boolean('is_group_admin')->default(false); // may log in via Group Login and switch hotels
            $t->timestamp('last_login_at')->nullable();
            $t->string('last_login_ip', 45)->nullable();
            $t->unsignedTinyInteger('failed_logins')->default(0);
            $t->timestamp('locked_until')->nullable();
            $t->rememberToken();
            $t->timestamps();
            $t->unique(['hotel_id', 'username']);
        });

        Schema::create('password_reset_tokens', function (Blueprint $t) {
            $t->string('email')->primary();
            $t->string('token');
            $t->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $t) {
            $t->string('id')->primary();
            $t->foreignId('user_id')->nullable()->index();
            $t->string('ip_address', 45)->nullable();
            $t->text('user_agent')->nullable();
            $t->longText('payload');
            $t->integer('last_activity')->index();
        });

        Schema::create('settings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('key', 80);
            $t->text('value')->nullable();
            $t->unique(['hotel_id', 'key']);
        });

        Schema::create('activity_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->nullable()->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('module', 60);
            $t->string('action', 60);
            $t->string('description', 500);
            $t->string('subject_type')->nullable();
            $t->unsignedBigInteger('subject_id')->nullable();
            $t->json('properties')->nullable();
            $t->string('ip', 45)->nullable();
            $t->string('user_agent', 255)->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['hotel_id', 'created_at']);
        });
    }

    public function down(): void
    {
        foreach (['activity_logs', 'settings', 'sessions', 'password_reset_tokens', 'users', 'permission_role', 'permissions', 'roles', 'hotels', 'hotel_groups'] as $tbl) {
            Schema::dropIfExists($tbl);
        }
    }
};

