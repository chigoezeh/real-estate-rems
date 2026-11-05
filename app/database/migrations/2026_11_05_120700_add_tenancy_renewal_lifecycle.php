<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenancies', function (Blueprint $table) {
            $table->foreignUuid('renewal_of_tenancy_id')
                ->nullable()
                ->after('created_by_user_id')
                ->constrained('tenancies')
                ->restrictOnDelete();
            $table->index('renewal_of_tenancy_id', 'tenancies_renewal_of_index');
        });
    }

    public function down(): void
    {
        Schema::table('tenancies', function (Blueprint $table) {
            $table->dropIndex('tenancies_renewal_of_index');
            $table->dropConstrainedForeignId('renewal_of_tenancy_id');
        });
    }
};
