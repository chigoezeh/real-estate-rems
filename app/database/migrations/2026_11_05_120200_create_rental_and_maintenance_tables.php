<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_charge_types', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->string('name', 120);
            $table->string('category', 40);
            $table->string('classification', 30)->default('company_defined');
            $table->string('calculation_method', 30)->default('fixed');
            $table->decimal('amount', 15, 2)->default(0);
            $table->boolean('is_recurring')->default(false);
            $table->boolean('is_active')->default(true);
            $table->date('effective_from')->nullable();
            $table->date('effective_until')->nullable();
            $table->json('rules')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'name']);
            $table->index(['organization_id', 'category', 'is_active'], 'charge_types_org_category_active_idx');
        });

        Schema::create('tenancies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('property_unit_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('tenant_client_id')->constrained('clients')->restrictOnDelete();
            $table->foreignUuid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('tenancy_number', 50);
            $table->string('status', 30)->default('draft');
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->string('rent_frequency', 30)->default('monthly');
            $table->decimal('rent_amount', 15, 2);
            $table->decimal('security_deposit', 15, 2)->default(0);
            $table->decimal('service_charge', 15, 2)->default(0);
            $table->date('next_review_on')->nullable();
            $table->string('agreement_path', 500)->nullable();
            $table->json('terms_snapshot')->nullable();
            $table->json('policy_snapshot')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'tenancy_number']);
            $table->index(['organization_id', 'status', 'starts_on', 'ends_on'], 'tenancies_status_dates_index');
            $table->index(['property_unit_id', 'status']);
            $table->index(['tenant_client_id', 'status']);
        });

        Schema::create('rent_schedules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenancy_id')->constrained()->restrictOnDelete();
            $table->string('category', 40)->default('rent');
            $table->date('period_starts_on');
            $table->date('period_ends_on');
            $table->date('due_on');
            $table->decimal('amount_due', 15, 2);
            $table->string('status', 20)->default('unpaid');
            $table->timestamps();
            $table->unique(['tenancy_id', 'category', 'period_starts_on'], 'rent_schedule_period_unique');
            $table->index(['tenancy_id', 'due_on', 'status']);
        });

        Schema::create('rent_payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('tenancy_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('submitted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('amount', 15, 2);
            $table->string('payment_channel', 30);
            $table->string('transaction_reference', 120)->nullable();
            $table->string('proof_document_path', 500)->nullable();
            $table->string('status', 20)->default('pending');
            $table->text('rejection_reason')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'transaction_reference'], 'rent_payment_reference_unique');
            $table->index(['organization_id', 'status', 'created_at']);
            $table->index(['tenancy_id', 'status']);
        });

        Schema::create('rent_payment_allocations', function (Blueprint $table) {
            $table->foreignUuid('rent_payment_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('rent_schedule_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 15, 2);
            $table->timestamps();
            $table->primary(['rent_payment_id', 'rent_schedule_id']);
            $table->index('rent_schedule_id');
        });

        Schema::create('maintenance_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('property_unit_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('tenancy_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('reported_by_client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->foreignUuid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reference_number', 50);
            $table->string('category', 50);
            $table->string('priority', 20)->default('normal');
            $table->string('status', 30)->default('open');
            $table->string('title', 180);
            $table->text('description');
            $table->timestamp('reported_at')->useCurrent();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'reference_number']);
            $table->index(['organization_id', 'status', 'priority']);
            $table->index(['property_unit_id', 'status']);
        });

        Schema::create('maintenance_work_orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('maintenance_request_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('assigned_to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('vendor_name', 180)->nullable();
            $table->string('status', 30)->default('pending_approval');
            $table->decimal('estimated_cost', 15, 2)->nullable();
            $table->decimal('actual_cost', 15, 2)->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('completion_notes')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'status', 'scheduled_at'], 'work_orders_org_status_scheduled_idx');
            $table->index(['maintenance_request_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_work_orders');
        Schema::dropIfExists('maintenance_requests');
        Schema::dropIfExists('rent_payment_allocations');
        Schema::dropIfExists('rent_payments');
        Schema::dropIfExists('rent_schedules');
        Schema::dropIfExists('tenancies');
        Schema::dropIfExists('organization_charge_types');
    }
};
