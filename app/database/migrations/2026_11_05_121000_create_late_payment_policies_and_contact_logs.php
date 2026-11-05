<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_late_payment_policies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->string('name', 120);
            $table->unsignedSmallInteger('grace_period_days')->default(0);
            $table->string('calculation_method', 20);
            $table->decimal('amount', 15, 2);
            $table->unsignedSmallInteger('recurrence_interval_days')->nullable();
            $table->unsignedSmallInteger('maximum_occurrences')->default(1);
            $table->decimal('maximum_fee_amount', 15, 2)->nullable();
            $table->json('applies_to');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['organization_id', 'name']);
            $table->index(['organization_id', 'is_active'], 'late_payment_policy_org_active_idx');
        });

        Schema::create('late_fee_assessments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('rent_schedule_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('policy_id')->constrained('organization_late_payment_policies')->restrictOnDelete();
            $table->unsignedSmallInteger('occurrence');
            $table->date('assessed_on');
            $table->decimal('amount', 15, 2);
            $table->json('policy_snapshot');
            $table->timestamps();
            $table->unique(['rent_schedule_id', 'policy_id', 'occurrence'], 'late_fee_assessment_occurrence_unique');
            $table->index(['rent_schedule_id', 'assessed_on'], 'late_fee_schedule_assessed_idx');
        });

        Schema::create('rent_contact_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('tenancy_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('rent_schedule_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('contacted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('channel', 30);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['rent_schedule_id', 'created_at'], 'rent_contact_schedule_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rent_contact_logs');
        Schema::dropIfExists('late_fee_assessments');
        Schema::dropIfExists('organization_late_payment_policies');
    }
};
