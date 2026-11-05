<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('countries', function (Blueprint $table) {
            $table->char('code', 2)->primary();
            $table->string('name', 120)->unique();
            $table->char('currency_code', 3)->nullable();
            $table->string('default_timezone', 64)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('states', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->char('country_code', 2);
            $table->string('name', 120);
            $table->string('official_name', 160)->nullable();
            $table->string('code', 12)->nullable();
            $table->string('subdivision_type', 40)->default('state');
            $table->timestamps();
            $table->foreign('country_code')->references('code')->on('countries')->restrictOnDelete();
            $table->unique(['country_code', 'name']);
            $table->unique(['country_code', 'code']);
        });

        Schema::create('provinces', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('state_id')->constrained()->restrictOnDelete();
            $table->string('name', 120);
            $table->string('official_type', 40)->nullable();
            $table->string('code', 20)->nullable();
            $table->timestamps();
            $table->unique(['state_id', 'name']);
            $table->unique(['state_id', 'code']);
        });

        Schema::create('cities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->char('country_code', 2);
            $table->foreignUuid('state_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 160);
            $table->string('place_type', 40)->default('city');
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->timestamps();
            $table->foreign('country_code')->references('code')->on('countries')->restrictOnDelete();
            $table->index(['country_code', 'name']);
            $table->index(['state_id', 'name']);
        });

        Schema::create('city_province', function (Blueprint $table) {
            $table->foreignUuid('city_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('province_id')->constrained()->cascadeOnDelete();
            $table->primary(['city_id', 'province_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('city_province');
        Schema::dropIfExists('cities');
        Schema::dropIfExists('provinces');
        Schema::dropIfExists('states');
        Schema::dropIfExists('countries');
    }
};
