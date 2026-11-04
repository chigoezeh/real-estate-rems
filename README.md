# Real Estate Management System

A self-hosted, configurable real-estate operations platform for property companies. Each installation belongs to one customer group and can manage its parent company, subsidiaries, and branches. Nigerian market conventions are defaults; company branding and operating rules are configurable.

## Current implementation

The Laravel application is in [`app/`](app/). The first implementation milestone includes:

- One-time company and administrator setup
- UUID-backed users, organizations, roles, permissions, and audit records
- Parent, subsidiary, and branch management with permission-scoped access
- Organization-scoped custom role management with editable permission assignments and protected system roles
- Login, a company-aware dashboard, and a responsive React/Inertia admin shell
- MySQL as the default database connection

Property inventory, CRM, payments, allocation, legal documents, and realtor workflows are planned next and are not implemented yet. The HTML files in the repository root are early sketches, not application entrypoints.

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

For production, configure HTTPS, mail, backups, private document storage, and a non-root MySQL account before onboarding real client data.