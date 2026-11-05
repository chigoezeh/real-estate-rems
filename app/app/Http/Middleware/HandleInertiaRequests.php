<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'auth' => [
                'user' => $request->user(),
                'canManageUsers' => $request->user()?->hasPermission('users.manage') ?? false,
                'canViewInventory' => $request->user()?->hasPermission('inventory.view') ?? false,
                'canViewClients' => $request->user()?->hasPermission('clients.view') ?? false,
                'canViewSubscriptions' => $request->user()?->hasPermission('subscriptions.view') ?? false,
                    'canViewPayments' => $request->user()?->hasPermission('payments.view') ?? false,
                    'canViewInspections' => $request->user()?->hasPermission('inspections.view') ?? false,
            ],
            'company' => fn (): ?array => $request->user()?->organizations()
                ->first(['organizations.id', 'organizations.name'])
                ?->only(['id', 'name']),
        ];
    }
}
