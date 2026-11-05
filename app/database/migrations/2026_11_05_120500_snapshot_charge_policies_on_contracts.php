<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->json('charge_snapshot')->nullable();
        });

        Schema::table('installment_schedules', function (Blueprint $table) {
            $table->dropUnique('schedule_category_number_unique');
            $table->foreignUuid('charge_type_id')->nullable()->after('subscription_id')->constrained('organization_charge_types')->nullOnDelete();
            $table->string('charge_name_snapshot', 120)->nullable();
            $table->string('charge_classification_snapshot', 30)->nullable();
            $table->string('charge_calculation_method_snapshot', 30)->nullable();
            $table->json('charge_policy_snapshot')->nullable();
            $table->unique(
                ['subscription_id', 'charge_type_id', 'category', 'installment_number'],
                'schedule_charge_policy_number_unique',
            );
        });

        Schema::table('rent_schedules', function (Blueprint $table) {
            $table->dropUnique('rent_schedule_period_unique');
            $table->foreignUuid('charge_type_id')->nullable()->after('tenancy_id')->constrained('organization_charge_types')->nullOnDelete();
            $table->string('charge_name_snapshot', 120)->nullable();
            $table->string('charge_classification_snapshot', 30)->nullable();
            $table->string('charge_calculation_method_snapshot', 30)->nullable();
            $table->json('charge_policy_snapshot')->nullable();
            $table->unique(
                ['tenancy_id', 'charge_type_id', 'category', 'period_starts_on'],
                'rent_schedule_charge_policy_period_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('rent_schedules', function (Blueprint $table) {
            $table->dropUnique('rent_schedule_charge_policy_period_unique');
            $table->dropConstrainedForeignId('charge_type_id');
            $table->dropColumn([
                'charge_name_snapshot',
                'charge_classification_snapshot',
                'charge_calculation_method_snapshot',
                'charge_policy_snapshot',
            ]);
            $table->unique(['tenancy_id', 'category', 'period_starts_on'], 'rent_schedule_period_unique');
        });

        Schema::table('installment_schedules', function (Blueprint $table) {
            $table->dropUnique('schedule_charge_policy_number_unique');
            $table->dropConstrainedForeignId('charge_type_id');
            $table->dropColumn([
                'charge_name_snapshot',
                'charge_classification_snapshot',
                'charge_calculation_method_snapshot',
                'charge_policy_snapshot',
            ]);
            $table->unique(['subscription_id', 'category', 'installment_number'], 'schedule_category_number_unique');
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn('charge_snapshot');
        });
    }
};
