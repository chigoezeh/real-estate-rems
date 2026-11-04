<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 50);
            $table->string('location')->nullable();
            $table->text('description')->nullable();
            $table->string('status', 30)->default('active');
            $table->json('settings')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'code']);
        });

        Schema::create('property_units', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')->constrained()->restrictOnDelete();
            $table->string('phase', 80)->default('');
            $table->string('block', 80)->default('');
            $table->string('code', 80);
            $table->string('unit_type', 40)->default('land');
            $table->string('usage', 40)->nullable();
            $table->decimal('area_sqm', 12, 2)->nullable();
            $table->decimal('base_price', 15, 2)->default(0);
            $table->string('title_type', 80)->nullable();
            $table->string('title_status', 80)->nullable();
            $table->string('status', 40)->default('available');
            $table->boolean('is_corner')->default(false);
            $table->decimal('corner_premium_percent', 5, 2)->default(0);
            $table->decimal('topography_premium_amount', 15, 2)->default(0);
            $table->json('attributes')->nullable();
            $table->timestamps();
            $table->unique(['project_id', 'phase', 'block', 'code']);
            $table->index(['project_id', 'status']);
        });

        Schema::create('reservations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('project_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('property_unit_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('reserved_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('status', 20)->default('active');
            $table->timestamp('reserved_until')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamps();
            $table->index(['project_id', 'status']);
            $table->index(['property_unit_id', 'status']);
        });

        $permissions = [
            'inventory.view' => 'View property inventory',
            'inventory.manage' => 'Manage projects and inventory',
            'inventory.reserve' => 'Reserve available inventory',
        ];
        $permissionIds = [];

        foreach ($permissions as $key => $label) {
            $permission = DB::table('permissions')->where('key', $key)->first();
            $permissionId = $permission?->id ?? (string) Str::uuid();

            if ($permission === null) {
                DB::table('permissions')->insert([
                    'id' => $permissionId,
                    'key' => $key,
                    'label' => $label,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $permissionIds[$key] = $permissionId;
        }

        $ownerRoleIds = DB::table('roles')
            ->where('key', 'owner')
            ->where('is_system', true)
            ->pluck('id');

        foreach ($ownerRoleIds as $roleId) {
            foreach ($permissionIds as $permissionId) {
                DB::table('role_permission')->insertOrIgnore([
                    'role_id' => $roleId,
                    'permission_id' => $permissionId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('reservations');
        Schema::dropIfExists('property_units');
        Schema::dropIfExists('projects');

        $permissionIds = DB::table('permissions')
            ->whereIn('key', ['inventory.view', 'inventory.manage', 'inventory.reserve'])
            ->pluck('id');

        DB::table('role_permission')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('permissions')->whereIn('id', $permissionIds)->delete();
    }
};
