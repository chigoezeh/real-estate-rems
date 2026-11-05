<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->char('country_code', 2)->nullable()->after('code');
            $table->foreignUuid('state_id')->nullable()->after('country_code')->constrained()->nullOnDelete();
            $table->foreignUuid('province_id')->nullable()->after('state_id')->constrained()->nullOnDelete();
            $table->foreignUuid('city_id')->nullable()->after('province_id')->constrained()->nullOnDelete();
            $table->foreignUuid('developer_organization_id')->nullable()->after('city_id')->constrained('organizations')->nullOnDelete();
            $table->string('district_or_area', 160)->nullable()->after('location');
            $table->string('street_address', 255)->nullable()->after('district_or_area');
            $table->string('postal_code', 24)->nullable()->after('street_address');
            $table->index(['country_code', 'state_id', 'province_id', 'city_id'], 'projects_geography_index');
            $table->foreign('country_code')->references('code')->on('countries')->nullOnDelete();
        });

        Schema::create('property_prototypes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')->constrained()->cascadeOnDelete();
            $table->string('name', 150);
            $table->unsignedSmallInteger('bedrooms')->default(0);
            $table->unsignedSmallInteger('bathrooms')->default(0);
            $table->unsignedSmallInteger('toilets')->default(0);
            $table->unsignedSmallInteger('living_rooms')->default(1);
            $table->boolean('has_bq')->default(false);
            $table->decimal('floor_area_sqm', 12, 2)->nullable();
            $table->decimal('base_price', 15, 2);
            $table->text('description')->nullable();
            $table->timestamps();
            $table->unique(['project_id', 'name']);
            $table->index(['project_id', 'base_price']);
        });

        Schema::table('property_units', function (Blueprint $table) {
            $table->foreignUuid('prototype_id')->nullable()->after('project_id')->constrained('property_prototypes')->nullOnDelete();
            $table->string('plot_number', 50)->nullable()->after('code');
            $table->string('house_number', 50)->nullable()->after('plot_number');
            $table->string('beacon_number', 100)->nullable()->after('house_number');
            $table->string('finishing_state', 40)->nullable()->after('title_status');
            $table->string('rental_status', 30)->default('not_listed')->after('finishing_state');
            $table->index(['project_id', 'prototype_id']);
        });

        Schema::create('land_title_records', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('project_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUuid('property_unit_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('document_type', 80);
            $table->string('reference_number', 120)->nullable();
            $table->string('issuing_authority', 160)->nullable();
            $table->string('jurisdiction', 120)->nullable();
            $table->string('status', 40)->default('recorded');
            $table->date('instrument_date')->nullable();
            $table->date('registered_at')->nullable();
            $table->string('survey_number', 120)->nullable();
            $table->string('document_path', 500)->nullable();
            $table->string('verification_status', 30)->default('unverified');
            $table->foreignUuid('verified_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->text('verification_notes')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'verification_status']);
            $table->index(['project_id', 'document_type']);
            $table->index(['property_unit_id', 'document_type']);
        });

        Schema::create('amenities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 120)->unique();
            $table->string('category', 40);
            $table->timestamps();
        });

        Schema::create('project_amenity', function (Blueprint $table) {
            $table->foreignUuid('project_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('amenity_id')->constrained()->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->primary(['project_id', 'amenity_id']);
        });

        Schema::create('prototype_features', function (Blueprint $table) {
            $table->foreignUuid('prototype_id')->constrained('property_prototypes')->cascadeOnDelete();
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->primary(['prototype_id', 'name']);
        });

        Schema::create('property_unit_features', function (Blueprint $table) {
            $table->foreignUuid('property_unit_id')->constrained()->cascadeOnDelete();
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->primary(['property_unit_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('property_unit_features');
        Schema::dropIfExists('prototype_features');
        Schema::dropIfExists('project_amenity');
        Schema::dropIfExists('amenities');
        Schema::dropIfExists('land_title_records');

        Schema::table('property_units', function (Blueprint $table) {
            $table->dropIndex(['project_id', 'prototype_id']);
            $table->dropConstrainedForeignId('prototype_id');
            $table->dropColumn(['plot_number', 'house_number', 'beacon_number', 'finishing_state', 'rental_status']);
        });

        Schema::dropIfExists('property_prototypes');

        Schema::table('projects', function (Blueprint $table) {
            $table->dropIndex('projects_geography_index');
            $table->dropConstrainedForeignId('state_id');
            $table->dropConstrainedForeignId('province_id');
            $table->dropConstrainedForeignId('city_id');
            $table->dropConstrainedForeignId('developer_organization_id');
            $table->dropForeign(['country_code']);
            $table->dropColumn([
                'country_code',
                'district_or_area',
                'street_address',
                'postal_code',
            ]);
        });
    }
};
