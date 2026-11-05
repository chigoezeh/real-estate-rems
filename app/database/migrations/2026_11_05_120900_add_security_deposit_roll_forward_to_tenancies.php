<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('tenancies', 'security_deposit_carried_forward')) {
            Schema::table('tenancies', function (Blueprint $table) {
                $table->decimal('security_deposit_carried_forward', 15, 2)->default(0);
            });
        }

        if (! Schema::hasColumn('tenancy_deposit_deductions', 'rent_schedule_id')) {
            Schema::table('tenancy_deposit_deductions', function (Blueprint $table) {
                $table->foreignUuid('rent_schedule_id')->nullable();
                $table->foreign('rent_schedule_id', 'deposit_deductions_rent_schedule_fk')
                    ->references('id')
                    ->on('rent_schedules')
                    ->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('tenancy_deposit_deductions', 'rent_schedule_id')) {
            Schema::table('tenancy_deposit_deductions', function (Blueprint $table) {
                $table->dropForeign('deposit_deductions_rent_schedule_fk');
                $table->dropColumn('rent_schedule_id');
            });
        }
        if (Schema::hasColumn('tenancies', 'security_deposit_carried_forward')) {
            Schema::table('tenancies', function (Blueprint $table) {
                $table->dropColumn('security_deposit_carried_forward');
            });
        }
    }
};
