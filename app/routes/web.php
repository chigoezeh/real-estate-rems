<?php

use App\Http\Controllers\OrganizationController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\SetupController;
use App\Http\Controllers\StaffController;
use App\Models\AuditLog;
use App\Models\InstallationState;
use App\Models\Organization;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return redirect()->route(InstallationState::isInstalled() ? 'login' : 'setup');
});

Route::get('/setup', [SetupController::class, 'create'])->name('setup');
Route::post('/setup', [SetupController::class, 'store'])->name('setup.store');

Route::get('/dashboard', function () {
    $user = request()->user();
    $organizationIds = Organization::accessibleIdsFor($user, 'organizations.view');
    $organization = $user->organizations()->orderByPivot('created_at')->first();

    return Inertia::render('Dashboard', [
        'company' => $organization ? [
            'name' => $organization->name,
            'country' => $organization->country,
            'currency' => $organization->currency,
            'timezone' => $organization->timezone,
        ] : null,
        'organizationCount' => count($organizationIds),
        'staffCount' => DB::table('organization_user')
            ->whereIn('organization_id', $organizationIds)
            ->where('status', 'active')
            ->distinct()
            ->count('user_id'),
        'recentActivity' => AuditLog::query()
            ->with('user:id,name')
            ->whereIn('organization_id', $organizationIds)
            ->latest('created_at')
            ->limit(5)
            ->get(['id', 'organization_id', 'user_id', 'action', 'created_at']),
        'canManageOrganizations' => $user->hasPermission('organizations.manage'),
    ]);
})->middleware('auth')->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/inventory', [InventoryController::class, 'index'])->name('inventory.index');
    Route::post('/projects', [InventoryController::class, 'storeProject'])->name('projects.store');
    Route::post('/projects/{project}/units', [InventoryController::class, 'storeUnit'])->name('projects.units.store');
    Route::post('/inventory/{propertyUnit}/reserve', [InventoryController::class, 'reserve'])->name('inventory.reserve');
    Route::post('/inventory/{propertyUnit}/release', [InventoryController::class, 'release'])->name('inventory.release');
    Route::get('/organizations', [OrganizationController::class, 'index'])->name('organizations.index');
    Route::post('/organizations', [OrganizationController::class, 'store'])->name('organizations.store');
    Route::put('/organizations/{organization}', [OrganizationController::class, 'update'])->name('organizations.update');
    Route::get('/access/roles', [RoleController::class, 'index'])->name('roles.index');
    Route::post('/access/roles', [RoleController::class, 'store'])->name('roles.store');
    Route::put('/access/roles/{role}', [RoleController::class, 'update'])->name('roles.update');
    Route::get('/staff', [StaffController::class, 'index'])->name('staff.index');
    Route::post('/staff', [StaffController::class, 'store'])->name('staff.store');
    Route::patch('/staff/{staff}', [StaffController::class, 'update'])->name('staff.update');
});

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
