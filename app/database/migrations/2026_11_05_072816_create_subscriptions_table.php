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
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('client_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('project_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('property_unit_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('subscription_number', 40)->unique();
            $table->unsignedSmallInteger('payment_plan_months')->default(1);
            $table->decimal('land_price', 15, 2);
            $table->decimal('development_fee', 15, 2)->default(0);
            $table->decimal('deed_fee', 15, 2)->default(0);
            $table->decimal('survey_fee', 15, 2)->default(0);
            $table->decimal('interest_amount', 15, 2)->default(0);
            $table->string('status', 20)->default('active');
            $table->timestamps();
            $table->index(['organization_id', 'status']);
            $table->index(['property_unit_id', 'status']);
        });

        $permissions = [
            'subscriptions.view' => 'View subscriptions and installment schedules',
            'subscriptions.manage' => 'Create and manage subscriptions',
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
        Schema::dropIfExists('subscriptions');
    }
};
