<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * v10.0 Multi-tenant & multi-property management.
 *
 * hotel_groups becomes an accountable tenant account: identity, brand, the defaults every new
 * property inherits, and the entitlement that caps how many properties it may open.
 * hotels becomes a lifecycle-managed property: draft -> active <-> suspended -> archived.
 * property_user is the explicit grant that lets one person work across several properties, and
 * property_events is the audit trail of every lifecycle and access decision taken on a property.
 *
 * Nothing here bypasses BelongsToHotel: the new tables are read inside the existing tenant scope,
 * and the only global-by-design reads (the property register) go through PropertyAccessService.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hotel_groups', function (Blueprint $t) {
            if (! Schema::hasColumn('hotel_groups', 'legal_name')) {
                $t->string('legal_name')->nullable();
            }
            if (! Schema::hasColumn('hotel_groups', 'brand')) {
                $t->string('brand')->nullable();
            }
            if (! Schema::hasColumn('hotel_groups', 'management_company')) {
                $t->string('management_company')->nullable();
            }
            if (! Schema::hasColumn('hotel_groups', 'description')) {
                $t->text('description')->nullable();
            }
            if (! Schema::hasColumn('hotel_groups', 'contact_name')) {
                $t->string('contact_name')->nullable();
            }
            if (! Schema::hasColumn('hotel_groups', 'contact_email')) {
                $t->string('contact_email')->nullable();
            }
            if (! Schema::hasColumn('hotel_groups', 'contact_phone')) {
                $t->string('contact_phone', 30)->nullable();
            }
            if (! Schema::hasColumn('hotel_groups', 'country')) {
                $t->string('country')->nullable();
            }
            if (! Schema::hasColumn('hotel_groups', 'currency')) {
                $t->string('currency', 10)->nullable();
            }
            if (! Schema::hasColumn('hotel_groups', 'timezone')) {
                $t->string('timezone', 50)->nullable();
            }
            if (! Schema::hasColumn('hotel_groups', 'default_property_type')) {
                $t->string('default_property_type', 30)->nullable();
            }
            if (! Schema::hasColumn('hotel_groups', 'max_properties')) {
                $t->unsignedInteger('max_properties')->nullable();
            }
            if (! Schema::hasColumn('hotel_groups', 'status')) {
                $t->string('status', 20)->default('active');
            }
            if (! Schema::hasColumn('hotel_groups', 'suspended_at')) {
                $t->timestamp('suspended_at')->nullable();
            }
            if (! Schema::hasColumn('hotel_groups', 'notes')) {
                $t->text('notes')->nullable();
            }
        });

        Schema::table('hotels', function (Blueprint $t) {
            if (! Schema::hasColumn('hotels', 'status')) {
                $t->string('status', 20)->default('active');  // draft | active | suspended | archived
            }
            if (! Schema::hasColumn('hotels', 'status_reason')) {
                $t->string('status_reason')->nullable();
            }
            if (! Schema::hasColumn('hotels', 'suspended_at')) {
                $t->timestamp('suspended_at')->nullable();
            }
            if (! Schema::hasColumn('hotels', 'archived_at')) {
                $t->timestamp('archived_at')->nullable();
            }
            if (! Schema::hasColumn('hotels', 'opened_on')) {
                $t->date('opened_on')->nullable();
            }
            if (! Schema::hasColumn('hotels', 'closed_on')) {
                $t->date('closed_on')->nullable();
            }
            if (! Schema::hasColumn('hotels', 'brand')) {
                $t->string('brand')->nullable();
            }
            if (! Schema::hasColumn('hotels', 'star_rating')) {
                $t->unsignedTinyInteger('star_rating')->nullable();
            }
            if (! Schema::hasColumn('hotels', 'sort_order')) {
                $t->unsignedInteger('sort_order')->default(0);
            }
            if (! Schema::hasColumn('hotels', 'is_head_office')) {
                $t->boolean('is_head_office')->default(false);
            }
        });

        if (! Schema::hasTable('property_user')) {
            Schema::create('property_user', function (Blueprint $t) {
                $t->id();
                $t->foreignId('user_id')->constrained()->cascadeOnDelete();
                $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
                $t->foreignId('role_id')->nullable()->constrained()->nullOnDelete(); // overrides the user's role in this property only
                $t->boolean('is_default')->default(false);
                $t->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
                $t->timestamp('last_used_at')->nullable();
                $t->timestamps();
                $t->unique(['user_id', 'hotel_id']);
            });
        }

        if (! Schema::hasTable('property_events')) {
            Schema::create('property_events', function (Blueprint $t) {
                $t->id();
                $t->foreignId('hotel_group_id')->nullable()->constrained('hotel_groups')->nullOnDelete();
                $t->foreignId('hotel_id')->nullable()->constrained()->cascadeOnDelete();
                $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $t->string('action', 40);
                $t->string('summary');
                $t->json('details')->nullable();
                $t->timestamp('created_at')->useCurrent();
                $t->index(['hotel_id', 'created_at']);
                $t->index(['hotel_group_id', 'created_at']);
            });
        }

        // Existing rows: a property switched off before v10.0 is a suspended property, not a draft.
        DB::table('hotels')->where('is_active', false)->whereNull('archived_at')->update(['status' => 'suspended', 'suspended_at' => now()]);
        DB::table('hotel_groups')->where('is_active', false)->update(['status' => 'suspended', 'suspended_at' => now()]);
        DB::table('hotel_groups')->whereNull('status')->update(['status' => 'active']);
        DB::table('hotels')->whereNull('status')->update(['status' => 'active']);

        // Group defaults inherit the head office property so a new property starts with the
        // same country, currency and clock as the group it belongs to.
        foreach (DB::table('hotel_groups')->get() as $group) {
            $first = DB::table('hotels')->where('hotel_group_id', $group->id)->orderBy('id')->first();
            if (! $first) {
                continue;
            }
            DB::table('hotel_groups')->where('id', $group->id)->update([
                'country' => $group->country ?? $first->country,
                'currency' => $group->currency ?? $first->currency,
                'timezone' => $group->timezone ?? $first->timezone,
                'default_property_type' => $group->default_property_type ?? $first->property_type,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('property_events');
        Schema::dropIfExists('property_user');

        Schema::table('hotels', function (Blueprint $t) {
            foreach (['status', 'status_reason', 'suspended_at', 'archived_at', 'opened_on', 'closed_on', 'brand', 'star_rating', 'sort_order', 'is_head_office'] as $c) {
                if (Schema::hasColumn('hotels', $c)) {
                    $t->dropColumn($c);
                }
            }
        });

        Schema::table('hotel_groups', function (Blueprint $t) {
            foreach (['legal_name', 'brand', 'management_company', 'description', 'contact_name', 'contact_email', 'contact_phone', 'country', 'currency', 'timezone', 'default_property_type', 'max_properties', 'status', 'suspended_at', 'notes'] as $c) {
                if (Schema::hasColumn('hotel_groups', $c)) {
                    $t->dropColumn($c);
                }
            }
        });
    }
};
