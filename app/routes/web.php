<?php

use App\Http\Controllers\ClientController;
use App\Http\Controllers\InspectionController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\MaintenanceController;
use App\Http\Controllers\OrganizationController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RentPaymentController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\SetupController;
use App\Http\Controllers\StaffController;
use App\Http\Controllers\SubscriptionController;
use App\Http\Controllers\TenancyController;
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
    Route::get('/inspections', [InspectionController::class, 'index'])->name('inspections.index');
    Route::post('/inspections', [InspectionController::class, 'store'])->name('inspections.store');
    Route::patch('/inspections/{inspection}', [InspectionController::class, 'update'])->name('inspections.update');
    Route::get('/tenancies', [TenancyController::class, 'index'])->name('tenancies.index');
    Route::post('/tenancies', [TenancyController::class, 'store'])->name('tenancies.store');
    Route::patch('/tenancies/{tenancy}/activate', [TenancyController::class, 'activate'])->name('tenancies.activate');
    Route::get('/rent-payments', [RentPaymentController::class, 'index'])->name('rent-payments.index');
    Route::post('/rent-payments', [RentPaymentController::class, 'store'])->name('rent-payments.store');
    Route::patch('/rent-payments/{rentPayment}/review', [RentPaymentController::class, 'review'])->name('rent-payments.review');
    Route::get('/rent-payments/{rentPayment}/proof', [RentPaymentController::class, 'downloadProof'])->name('rent-payments.proof');
    Route::get('/maintenance', [MaintenanceController::class, 'index'])->name('maintenance.index');
    Route::post('/maintenance/requests', [MaintenanceController::class, 'store'])->name('maintenance.requests.store');
    Route::post('/maintenance/requests/{maintenanceRequest}/work-orders', [MaintenanceController::class, 'storeWorkOrder'])->name('maintenance.requests.work-orders.store');
    Route::patch('/maintenance/work-orders/{maintenanceWorkOrder}/approve', [MaintenanceController::class, 'approveWorkOrder'])->name('maintenance.work-orders.approve');
    Route::patch('/maintenance/work-orders/{maintenanceWorkOrder}/complete', [MaintenanceController::class, 'completeWorkOrder'])->name('maintenance.work-orders.complete');
    Route::get('/payments', [PaymentController::class, 'index'])->name('payments.index');
    Route::post('/payments', [PaymentController::class, 'store'])->name('payments.store');
    Route::patch('/payments/{payment}/review', [PaymentController::class, 'review'])->name('payments.review');
    Route::get('/payments/{payment}/proof', [PaymentController::class, 'downloadProof'])->name('payments.proof');
    Route::get('/subscriptions', [SubscriptionController::class, 'index'])->name('subscriptions.index');
    Route::post('/subscriptions', [SubscriptionController::class, 'store'])->name('subscriptions.store');
    Route::get('/clients', [ClientController::class, 'index'])->name('clients.index');
    Route::post('/clients', [ClientController::class, 'store'])->name('clients.store');
    Route::patch('/clients/{client}/kyc', [ClientController::class, 'reviewKyc'])->name('clients.kyc.review');
    Route::get('/clients/{client}/identity-document', [ClientController::class, 'downloadIdentityDocument'])->name('clients.identity-document');
    Route::get('/inventory', [InventoryController::class, 'index'])->name('inventory.index');
    Route::post('/projects', [InventoryController::class, 'storeProject'])->name('projects.store');
    Route::put('/projects/{project}', [InventoryController::class, 'updateProject'])->name('projects.update');
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
