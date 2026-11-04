<?php

use App\Http\Controllers\OrganizationController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SetupController;
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
            'currency' => $organization->currency,
            'timezone' => $organization->timezone,
        ] : null,
        'organizationCount' => count($organizationIds),
        'staffCount' => DB::table('organization_user')
            ->whereIn('organization_id', $organizationIds)
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
    Route::get('/organizations', [OrganizationController::class, 'index'])->name('organizations.index');
    Route::post('/organizations', [OrganizationController::class, 'store'])->name('organizations.store');
});

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
