<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('geography_imports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('source_key', 120)->unique();
            $table->string('source_url', 500);
            $table->string('source_version', 120);
            $table->string('license', 255)->nullable();
            $table->timestamp('imported_at');
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        foreach (['countries', 'states', 'provinces', 'cities'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->foreignUuid('source_import_id')
                    ->nullable()
                    ->constrained('geography_imports')
                    ->nullOnDelete();
            });
        }

        Schema::table('cities', function (Blueprint $table) {
            $table->unique(['country_code', 'state_id', 'name'], 'cities_country_state_name_unique');
        });
    }

    public function down(): void
    {
        Schema::table('cities', function (Blueprint $table) {
            $table->dropUnique('cities_country_state_name_unique');
        });

        foreach (['cities', 'provinces', 'states', 'countries'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropConstrainedForeignId('source_import_id');
            });
        }

        Schema::dropIfExists('geography_imports');
    }
};
