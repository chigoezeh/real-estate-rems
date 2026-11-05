<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('portal_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('kyc_reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('full_name');
            $table->string('email')->nullable();
            $table->string('phone', 40);
            $table->text('address')->nullable();
            $table->string('next_of_kin_name')->nullable();
            $table->string('next_of_kin_phone', 40)->nullable();
            $table->string('identity_type', 40);
            $table->text('identity_number');
            $table->text('identity_document_path')->nullable();
            $table->string('kyc_status', 20)->default('pending');
            $table->timestamp('kyc_reviewed_at')->nullable();
            $table->text('kyc_rejection_reason')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'kyc_status']);
            $table->index(['organization_id', 'created_at']);
        });

        $permissions = [
            'clients.view' => 'View clients',
            'clients.manage' => 'Manage client records',
            'clients.kyc.review' => 'Review client KYC documents',
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

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('clients');

        $permissionIds = DB::table('permissions')
            ->whereIn('key', ['clients.view', 'clients.manage', 'clients.kyc.review'])
            ->pluck('id');

        DB::table('role_permission')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('permissions')->whereIn('id', $permissionIds)->delete();
    }
};
