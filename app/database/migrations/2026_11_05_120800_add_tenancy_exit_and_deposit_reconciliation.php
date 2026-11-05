<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenancy_notices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenancy_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('completed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('notice_type', 30);
            $table->date('notice_on');
            $table->date('proposed_vacate_on');
            $table->text('reason');
            $table->string('status', 20)->default('served');
            $table->date('handed_over_on')->nullable();
            $table->text('handover_notes')->nullable();
            $table->timestamps();
            $table->index(['tenancy_id', 'status'], 'tenancy_notices_tenancy_status_idx');
        });

        Schema::create('tenancy_deposit_reconciliations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenancy_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignUuid('prepared_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('finalized_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('draft');
            $table->decimal('deposit_received_amount', 15, 2)->default(0);
            $table->decimal('deduction_total', 15, 2)->default(0);
            $table->decimal('refund_due_amount', 15, 2)->default(0);
            $table->timestamp('finalized_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->string('refund_reference', 120)->nullable();
            $table->text('refund_notes')->nullable();
            $table->timestamps();
        });

        Schema::create('tenancy_deposit_deductions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('reconciliation_id')
                ->constrained('tenancy_deposit_reconciliations')
                ->restrictOnDelete();
            $table->string('category', 30);
            $table->string('description', 180);
            $table->decimal('amount', 15, 2);
            $table->string('evidence_reference', 200)->nullable();
            $table->timestamps();
            $table->index('reconciliation_id', 'deposit_deductions_reconciliation_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenancy_deposit_deductions');
        Schema::dropIfExists('tenancy_deposit_reconciliations');
        Schema::dropIfExists('tenancy_notices');
    }
};
