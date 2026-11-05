<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('installment_schedules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('subscription_id')->constrained()->cascadeOnDelete();
            $table->string('category', 30);
            $table->unsignedSmallInteger('installment_number');
            $table->date('due_date');
            $table->decimal('amount', 15, 2);
            $table->decimal('amount_paid', 15, 2)->default(0);
            $table->string('status', 20)->default('unpaid');
            $table->timestamps();
            $table->unique(['subscription_id', 'category', 'installment_number'], 'schedule_category_number_unique');
            $table->index(['subscription_id', 'due_date', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('installment_schedules');
    }
};
