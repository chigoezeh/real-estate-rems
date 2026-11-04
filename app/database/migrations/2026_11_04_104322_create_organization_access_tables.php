<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('parent_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->string('name');
            $table->string('kind', 30);
            $table->string('registration_number')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->text('address')->nullable();
            $table->char('country', 2)->default('NG');
            $table->char('currency', 3)->default('NGN');
            $table->string('timezone', 64)->default('Africa/Lagos');
            $table->string('locale', 10)->default('en');
            $table->json('branding')->nullable();
            $table->json('settings')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['parent_id', 'kind']);
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('key', 80);
            $table->text('description')->nullable();
            $table->boolean('is_system')->default(false);
            $table->timestamps();
            $table->unique(['organization_id', 'key']);
        });

        Schema::create('permissions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('key', 120)->unique();
            $table->string('label');
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('role_permission', function (Blueprint $table) {
            $table->foreignUuid('role_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('permission_id')->constrained()->cascadeOnDelete();
            $table->primary(['role_id', 'permission_id']);
            $table->timestamps();
        });

        Schema::create('organization_user', function (Blueprint $table) {
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('role_id')->constrained()->restrictOnDelete();
            $table->string('status', 20)->default('active');
            $table->timestamps();
            $table->primary(['organization_id', 'user_id']);
            $table->index(['user_id', 'status']);
        });

        Schema::create('installation_states', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->boolean('is_installed')->default(false);
            $table->timestamp('installed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 120);
            $table->string('subject_type', 80)->nullable();
            $table->uuid('subject_id')->nullable();
            $table->json('metadata')->nullable();
            $table->ipAddress('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['organization_id', 'created_at']);
        });

        DB::table('installation_states')->insert([
            'id' => '018f2b5c-6a7f-7b12-9d6f-2f8a4e0c9c11',
            'is_installed' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Schema::create('organization_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('key', 120);
            $table->json('value')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('organization_settings');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('installation_states');
        Schema::dropIfExists('organization_user');
        Schema::dropIfExists('role_permission');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('organizations');
    }
};
