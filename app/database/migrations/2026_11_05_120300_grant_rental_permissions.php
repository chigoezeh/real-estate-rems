<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const PERMISSIONS = [
        'tenancies.view' => 'View rental tenancies',
        'tenancies.manage' => 'Create and manage rental tenancies',
        'rent_payments.view' => 'View rent payments and schedules',
        'rent_payments.submit' => 'Submit rent payments and proof',
        'rent_payments.review' => 'Approve or reject rent payments',
        'maintenance.view' => 'View property maintenance requests',
        'maintenance.manage' => 'Manage property maintenance and work orders',
        'charges.manage' => 'Manage company charge policies',
    ];

    public function up(): void
    {
        DB::transaction(function (): void {
            $permissionIds = [];

            foreach (self::PERMISSIONS as $key => $label) {
                $permissionIds[] = Permission::query()->firstOrCreate(
                    ['key' => $key],
                    ['label' => $label],
                )->id;
            }

            Role::query()
                ->where('key', 'owner')
                ->where('is_system', true)
                ->get()
                ->each(fn (Role $role) => $role->permissions()->syncWithoutDetaching($permissionIds));
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            $permissions = Permission::query()->whereIn('key', array_keys(self::PERMISSIONS))->get();

            foreach ($permissions as $permission) {
                $permission->roles()->detach();
                $permission->delete();
            }
        });
    }
};
