<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('countries', function (Blueprint $table) {
            $table->char('code', 2)->primary();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('india_admin_regions', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('type');
            $table->string('source')->default('Government of India ISO 3166-2 reference');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('communities', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('timezone')->default('Asia/Kolkata');
            $table->string('country_code', 2)->default('IN');
            $table->string('state_code')->nullable();
            $table->string('district')->nullable();
            $table->string('taluk')->nullable();
            $table->string('locality')->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->text('address')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['state_code', 'district']);
        });

        Schema::create('community_memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('community_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role')->default('member');
            $table->string('status')->default('active');
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('joined_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->unique(['community_id', 'user_id']);
            $table->index(['user_id', 'status']);
        });

        Schema::create('community_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('community_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invited_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('email')->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('role')->default('member');
            $table->string('code_hash', 64)->unique();
            $table->string('status')->default('pending');
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();
            $table->index(['community_id', 'status']);
        });

        Schema::create('community_join_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('community_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('message')->nullable();
            $table->string('status')->default('pending');
            $table->foreignId('decided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->unique(['community_id', 'user_id']);
            $table->index(['community_id', 'status']);
        });

        Schema::create('farms', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->decimal('area_square_metres', 14, 2)->default(0);
            $table->string('timezone')->default('Asia/Kolkata');
            $table->string('country_code', 2)->default('IN');
            $table->string('state_code')->nullable();
            $table->string('district')->nullable();
            $table->string('taluk')->nullable();
            $table->string('locality')->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->text('address')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['state_code', 'district']);
        });

        Schema::create('farm_memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('farm_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('role')->default('worker');
            $table->string('status')->default('active');
            $table->foreignId('invited_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('joined_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->unique(['farm_id', 'user_id']);
            $table->index(['user_id', 'status']);
        });

        Schema::create('farm_member_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('farm_membership_id')->constrained()->cascadeOnDelete();
            $table->string('permission');
            $table->boolean('allowed')->default(true);
            $table->timestamps();
            $table->unique(['farm_membership_id', 'permission']);
        });

        Schema::create('farm_community_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('farm_id')->constrained()->cascadeOnDelete();
            $table->foreignId('community_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('pending');
            $table->foreignId('linked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('analytics_scopes')->nullable();
            $table->json('farm_access_permissions')->nullable();
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->text('revocation_reason')->nullable();
            $table->timestamps();
            $table->unique(['farm_id', 'community_id']);
            $table->index(['community_id', 'status']);
        });

        Schema::create('farm_community_link_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('farm_community_link_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event');
            $table->string('from_status')->nullable();
            $table->string('to_status')->nullable();
            $table->json('context')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['farm_community_link_id', 'created_at']);
        });

        Schema::create('fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('farm_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->decimal('area_square_metres', 14, 2)->default(0);
            $table->string('soil_type')->nullable();
            $table->json('boundary')->nullable();
            $table->string('status')->default('active');
            $table->unsignedBigInteger('workspace_revision')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['farm_id', 'name']);
            $table->index(['farm_id', 'status']);
        });

        Schema::create('field_zones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('field_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->decimal('area_square_metres', 14, 2)->default(0);
            $table->json('boundary')->nullable();
            $table->string('colour', 20)->nullable();
            $table->boolean('is_whole_field')->default(false);
            $table->timestamps();
            $table->unique(['field_id', 'name']);
        });

        Schema::create('field_markers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('field_id')->constrained()->cascadeOnDelete();
            $table->foreignId('field_zone_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type');
            $table->string('label')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->json('position')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['field_id', 'type']);
        });

        Schema::create('crops', function (Blueprint $table) {
            $table->id();
            $table->foreignId('farm_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('scientific_name')->nullable();
            $table->string('category')->nullable();
            $table->boolean('is_global')->default(false);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('legacy_source')->nullable();
            $table->unsignedBigInteger('legacy_id')->nullable();
            $table->timestamps();
            $table->index(['farm_id', 'name']);
            $table->index(['is_global', 'name']);
            $table->unique(['legacy_source', 'legacy_id']);
        });

        Schema::create('crop_varieties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('crop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('farm_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_global')->default(false);
            $table->timestamps();
            $table->unique(['crop_id', 'farm_id', 'name']);
        });

        Schema::create('crop_seasons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('farm_id')->constrained()->cascadeOnDelete();
            $table->foreignId('field_id')->constrained()->restrictOnDelete();
            $table->foreignId('field_zone_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('crop_id')->constrained()->restrictOnDelete();
            $table->foreignId('crop_variety_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name')->nullable();
            $table->date('starts_on');
            $table->date('expected_ends_on')->nullable();
            $table->date('ended_on')->nullable();
            $table->decimal('planted_area_square_metres', 14, 2)->nullable();
            $table->string('status')->default('planned');
            $table->text('notes')->nullable();
            $table->string('legacy_group_key', 64)->nullable()->unique();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['field_id', 'status', 'starts_on']);
            $table->index(['farm_id', 'status']);
        });

        Schema::create('crop_condition_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('crop_season_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('condition');
            $table->unsignedTinyInteger('severity')->nullable();
            $table->text('notes')->nullable();
            $table->json('observations')->nullable();
            $table->timestamp('observed_at');
            $table->timestamps();
            $table->index(['crop_season_id', 'observed_at']);
        });

        Schema::create('crop_harvests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('crop_season_id')->constrained()->restrictOnDelete();
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('quantity', 14, 3);
            $table->string('unit', 32);
            $table->date('harvested_on');
            $table->string('quality_grade')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['crop_season_id', 'harvested_on']);
        });

        Schema::create('crop_rotation_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('field_id')->constrained()->cascadeOnDelete();
            $table->foreignId('field_zone_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('crop_season_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('crop_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('season_year');
            $table->string('crop_family')->nullable();
            $table->string('source')->default('crop_season');
            $table->timestamps();
            $table->index(['field_id', 'season_year']);
        });

        Schema::create('planning_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('farm_id')->constrained()->cascadeOnDelete();
            $table->foreignId('field_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event');
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['farm_id', 'created_at']);
        });

        Schema::create('work_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('farm_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('community_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('field_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('crop_season_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('assigned_to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status')->default('pending');
            $table->string('priority')->default('medium');
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['farm_id', 'status', 'due_at']);
            $table->index(['community_id', 'status', 'due_at']);
        });

        Schema::create('stock_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('farm_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('community_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('category')->nullable();
            $table->decimal('quantity', 14, 3)->default(0);
            $table->string('unit', 32);
            $table->decimal('reorder_level', 14, 3)->nullable();
            $table->timestamps();
            $table->index(['farm_id', 'name']);
            $table->index(['community_id', 'name']);
        });

        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('work_task_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type');
            $table->decimal('quantity', 14, 3);
            $table->decimal('balance_after', 14, 3);
            $table->text('notes')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->index(['stock_item_id', 'occurred_at']);
        });

        Schema::create('shared_resources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('community_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('type')->nullable();
            $table->string('status')->default('available');
            $table->string('timezone')->default('Asia/Kolkata');
            $table->boolean('requires_approval')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['community_id', 'status']);
        });

        Schema::create('resource_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shared_resource_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('farm_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('decided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status')->default('pending');
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->text('purpose')->nullable();
            $table->text('decision_notes')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->index(['shared_resource_id', 'status', 'starts_at', 'ends_at'], 'resource_reservation_conflict_lookup');
            $table->index(['requested_by_user_id', 'status']);
        });

        Schema::create('recommendations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('farm_id')->constrained()->cascadeOnDelete();
            $table->foreignId('field_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('crop_season_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type');
            $table->string('severity')->default('info');
            $table->string('title');
            $table->text('message');
            $table->json('weather_context')->nullable();
            $table->string('status')->default('active');
            $table->timestamp('valid_until')->nullable();
            $table->timestamps();
            $table->index(['farm_id', 'status', 'created_at']);
        });

        Schema::create('otp_challenges', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('phone', 32);
            $table->string('purpose');
            $table->string('code_hash');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->unsignedTinyInteger('max_attempts')->default(5);
            $table->timestamp('expires_at');
            $table->timestamp('resend_available_at');
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
            $table->index(['phone', 'purpose', 'created_at']);
        });

        Schema::create('otp_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid('otp_challenge_id')->nullable();
            $table->foreign('otp_challenge_id')->references('id')->on('otp_challenges')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('phone', 32);
            $table->string('event');
            $table->string('ip_address', 45)->nullable();
            $table->json('context')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['phone', 'created_at']);
        });

        Schema::create('onboarding_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('current_step')->default('profile');
            $table->json('completed_steps')->nullable();
            $table->json('draft')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique('user_id');
        });

        Schema::create('legacy_migration_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->string('status')->default('pending');
            $table->boolean('dry_run')->default(true);
            $table->unsignedInteger('chunk_size')->default(250);
            $table->unsignedBigInteger('last_legacy_id')->default(0);
            $table->json('counts')->nullable();
            $table->json('options')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['type', 'status']);
        });

        Schema::create('legacy_record_mappings', function (Blueprint $table) {
            $table->id();
            $table->string('legacy_type');
            $table->unsignedBigInteger('legacy_id');
            $table->string('target_type')->nullable();
            $table->unsignedBigInteger('target_id')->nullable();
            $table->string('classification');
            $table->string('status')->default('classified');
            $table->decimal('confidence', 5, 4)->nullable();
            $table->json('evidence')->nullable();
            $table->text('notes')->nullable();
            $table->uuid('migration_run_id')->nullable();
            $table->foreign('migration_run_id')->references('id')->on('legacy_migration_runs')->nullOnDelete();
            $table->timestamps();
            $table->unique(['legacy_type', 'legacy_id']);
            $table->index(['classification', 'status']);
        });

        Schema::create('legacy_migration_audits', function (Blueprint $table) {
            $table->id();
            $table->uuid('migration_run_id');
            $table->foreign('migration_run_id')->references('id')->on('legacy_migration_runs')->cascadeOnDelete();
            $table->string('event');
            $table->string('legacy_type')->nullable();
            $table->unsignedBigInteger('legacy_id')->nullable();
            $table->json('context')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['migration_run_id', 'created_at']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('phone', 32)->nullable()->unique();
            $table->timestamp('phone_verified_at')->nullable();
            $table->string('locale', 10)->default('en');
            $table->string('status')->default('active');
            $table->timestamp('deactivated_at')->nullable();
        });

        Schema::table('community_posts', function (Blueprint $table) {
            $table->foreignId('field_id')->nullable()->constrained('fields')->nullOnDelete();
            $table->boolean('is_legacy')->default(true);
        });

        DB::table('countries')->insertOrIgnore([
            'code' => 'IN', 'name' => 'India', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->seedIndiaRegions();
    }

    private function seedIndiaRegions(): void
    {
        $regions = [
            ['AP', 'Andhra Pradesh', 'state'], ['AR', 'Arunachal Pradesh', 'state'], ['AS', 'Assam', 'state'],
            ['BR', 'Bihar', 'state'], ['CG', 'Chhattisgarh', 'state'], ['GA', 'Goa', 'state'],
            ['GJ', 'Gujarat', 'state'], ['HR', 'Haryana', 'state'], ['HP', 'Himachal Pradesh', 'state'],
            ['JH', 'Jharkhand', 'state'], ['KA', 'Karnataka', 'state'], ['KL', 'Kerala', 'state'],
            ['MP', 'Madhya Pradesh', 'state'], ['MH', 'Maharashtra', 'state'], ['MN', 'Manipur', 'state'],
            ['ML', 'Meghalaya', 'state'], ['MZ', 'Mizoram', 'state'], ['NL', 'Nagaland', 'state'],
            ['OD', 'Odisha', 'state'], ['PB', 'Punjab', 'state'], ['RJ', 'Rajasthan', 'state'],
            ['SK', 'Sikkim', 'state'], ['TN', 'Tamil Nadu', 'state'], ['TG', 'Telangana', 'state'],
            ['TR', 'Tripura', 'state'], ['UP', 'Uttar Pradesh', 'state'], ['UK', 'Uttarakhand', 'state'],
            ['WB', 'West Bengal', 'state'], ['AN', 'Andaman and Nicobar Islands', 'union_territory'],
            ['CH', 'Chandigarh', 'union_territory'], ['DN', 'Dadra and Nagar Haveli and Daman and Diu', 'union_territory'],
            ['DL', 'Delhi', 'union_territory'], ['JK', 'Jammu and Kashmir', 'union_territory'],
            ['LA', 'Ladakh', 'union_territory'], ['LD', 'Lakshadweep', 'union_territory'],
            ['PY', 'Puducherry', 'union_territory'],
        ];

        DB::table('india_admin_regions')->insert(array_map(fn (array $region) => [
            'code' => $region[0], 'name' => $region[1], 'type' => $region[2],
            'source' => 'Government of India ISO 3166-2 reference', 'active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ], $regions));
    }

    public function down(): void
    {
        Schema::table('community_posts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('field_id');
            $table->dropColumn('is_legacy');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['phone']);
            $table->dropColumn(['phone', 'phone_verified_at', 'locale', 'status', 'deactivated_at']);
        });

        foreach (['legacy_migration_audits', 'legacy_record_mappings', 'legacy_migration_runs', 'onboarding_progress',
            'otp_audit_logs', 'otp_challenges', 'recommendations', 'resource_reservations', 'shared_resources',
            'inventory_movements', 'stock_items', 'work_tasks', 'planning_history', 'crop_rotation_entries',
            'crop_harvests', 'crop_condition_records', 'crop_seasons', 'crop_varieties', 'crops', 'field_markers', 'field_zones',
            'fields', 'farm_community_link_events', 'farm_community_links', 'farm_member_permissions',
            'farm_memberships', 'farms', 'community_join_requests', 'community_invitations',
            'community_memberships', 'communities', 'india_admin_regions', 'countries'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
