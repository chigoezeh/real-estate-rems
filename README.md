# Real Estate Management System

A self-hosted, configurable real-estate operations platform for property companies. Each installation belongs to one customer group and can manage its parent company, subsidiaries, and branches. Nigerian market conventions are defaults; company branding and operating rules are configurable.

## Current implementation

The Laravel application is in [`app/`](app/). The implementation includes:

- One-time company and administrator setup
- UUID-backed users, organizations, roles, permissions, and audit records
- Parent, subsidiary, and branch management with permission-scoped access
- Organization profile, localization, and basic brand identity settings
- Organization-scoped custom role management with editable permission assignments and protected system roles
- Staff invitation, organization membership, role assignment, and access suspension
- Login, a company-aware dashboard, and a responsive React/Inertia admin shell
- MySQL as the default database connection

The property and operations workflows include normalized geography, project/unit inventory, title records, client KYC, inspection bookings, subscriptions and installment payments, approved property allocation, company-configured charge policies, rental tenancies and rent payments, and maintenance requests/work orders. Allocation requires reconciled full payment, verified property-level title, and authorized inventory approval. Extended tenancy lifecycle, commissions, notifications, and reporting remain planned. The HTML files in the repository root are early sketches, not application entrypoints.

## Requirements

- PHP 8.3 or later with `pdo_mysql`, `mbstring`, `openssl`, `fileinfo`, `curl`, and `xml`
- Composer 2
- Node.js 22.12 or later and npm
- MySQL 8.0 or later

## Local setup

1. Create a MySQL database and user for the application.
2. From `app/`, install dependencies and create the environment file:

	```sh
	composer install
	cp .env.example .env
	```

	On PowerShell, use `Copy-Item .env.example .env` instead of `cp`.

3. Set `DB_DATABASE`, `DB_USERNAME`, and `DB_PASSWORD` in `app/.env`, then run:

	```sh
	php artisan key:generate
	php artisan migrate
	npm install
	npm run dev
	```

4. Start the Laravel server in a second terminal from `app/`:

	```sh
	php artisan serve
	```

5. Open the local application. The first visit leads to the one-time setup form. Create the primary company and owner account; public registration is disabled.

When `APP_ENV=local`, setup also offers an optional **Populate with development demo data** checkbox. Selecting it creates synthetic records across clients/KYC, organization branches, projects and inventory, charge policies, sales and installment payments, tenancies and rent payments, inspections, land-title records, and maintenance. The generated payment proof is a local placeholder image; no real client or payment information is included. This option is hidden and rejected outside the local environment.

For an already-installed local workspace, run `php artisan db:seed --class=DevelopmentDemoSeeder` from `app/`. The command is local-environment-only and does nothing if demo data has already been seeded for the primary company.

For production, configure HTTPS, mail, backups, private document storage, and a non-root MySQL account before onboarding real client data.