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
        Schema::create('payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('subscription_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('installment_schedule_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('submitted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('payment_category', 30);
            $table->decimal('amount', 15, 2);
            $table->string('payment_method', 30);
            $table->string('transaction_reference', 100)->nullable();
            $table->string('proof_document_path', 500);
            $table->string('status', 20)->default('pending');
            $table->text('rejection_reason')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'status', 'created_at']);
            $table->index(['installment_schedule_id', 'status']);
        });

        $permissions = [
            'payments.view' => 'View payment ledger',
            'payments.submit' => 'Submit payment records and proof',
            'payments.review' => 'Approve or reject submitted payments',
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
        Schema::dropIfExists('payments');
    }
};
