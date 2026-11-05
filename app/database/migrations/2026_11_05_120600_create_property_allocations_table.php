<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('property_allocations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('subscription_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('property_unit_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('land_title_record_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('requested_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('allocation_reference', 50)->unique();
            $table->string('status', 30)->default('pending_approval');
            $table->timestamp('requested_at');
            $table->timestamp('reviewed_at')->nullable();
            $table->date('allocated_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'status', 'requested_at']);
            $table->index(['subscription_id', 'status']);
            $table->index(['property_unit_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('property_allocations');
    }
};
