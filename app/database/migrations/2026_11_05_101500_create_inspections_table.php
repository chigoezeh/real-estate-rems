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
        Schema::create('inspections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('client_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('project_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('assigned_to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('scheduled_at');
            $table->string('status', 20)->default('scheduled');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'scheduled_at', 'status']);
            $table->index(['assigned_to_user_id', 'scheduled_at', 'status']);
        });

        $permissions = [
            'inspections.view' => 'View site inspection bookings',
            'inspections.manage' => 'Schedule and manage site inspections',
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

            $permissionIds[] = $permissionId;
        }

        $ownerRoleIds = DB::table('roles')->where('key', 'owner')->where('is_system', true)->pluck('id');

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
        Schema::dropIfExists('inspections');

        $permissionIds = DB::table('permissions')
            ->whereIn('key', ['inspections.view', 'inspections.manage'])
            ->pluck('id');
        DB::table('role_permission')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('permissions')->whereIn('id', $permissionIds)->delete();
    }
};
