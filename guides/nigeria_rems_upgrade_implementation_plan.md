# Nigeria-Focused REMS Upgrade: Implementation Plan

**Status:** Active implementation plan  
**Product scope:** Multi-organization real-estate operations for Nigerian and international property businesses  
**Primary framework:** Laravel modular monolith with Inertia, React, TypeScript, and Tailwind CSS  
**Database approach:** Relational schema, UUID identifiers, portable Laravel migrations; spatial database features are optional, not a prerequisite

## 0.1 Implementation checkpoint

The initial schema and a first usable rental/maintenance workflow increment are in place:

- Normalized country, state, province, city, and city-to-province relationship tables and Eloquent models.
- Project location/developer fields, property prototypes and Nigeria-specific property identifiers, amenities/features, and title-document verification records.
- Company-scoped charge definitions, tenancy/rent schedule/payment allocation tables, maintenance request/work-order tables, and their initial models.
- A transactional rent-payment review service that locks rent schedules, validates allocations against approved balances, records review audit events, and prevents over-allocation.
- Shared exact minor-unit arithmetic used by the existing sales subscription/payment controllers.
- Rental listing controls on inventory, tenancy draft creation/activation with overlap checks, and rent/service-charge/deposit schedule generation.
- Permission-scoped rent payment submission, private proof downloads, pending-balance reservations, and approval/rejection.
- Permission-scoped maintenance request and work-order creation, approval, completion, and audit events.
- Rental and maintenance permissions for new installations and existing owner roles, while preserving organization hierarchy access.
- Feature tests for tenancy scheduling, rent payment review and balance limits, maintenance work orders, alongside the geography/property and money tests.

**Still to implement:** traceable reference-geography imports and location selectors; charge-policy administration and wiring configurable charges into sale and rental workflows; tenancy agreements, renewals, notices, terminations, deposit accounting, arrears, and tenant self-service; richer maintenance evidence/vendor controls and policy-based approval thresholds; the remaining sales/commission/reporting workflows; and migration-history consolidation. The original migrations have not been deleted or reset; the new tables are currently added through forward migrations. The current workflow is a foundation, not a complete release.

## 1. Product decisions

This plan resolves the current product scope as follows:

- The first release includes **property and land sales, land subscriptions/installments, rentals, tenancy, rent collection, and property maintenance**.
- Companies configure their charge catalog, charge classification, payment plans, allocation thresholds, late-payment rules, and commission policies. REMS supplies defaults and calculations, not a single hard-coded company policy.
- Organization hierarchy and sub-organization mode remain supported. Parent/child access is governed by explicit permissions and scope rules; a child is never treated as an unrelated tenant or automatically given unrestricted access to every sibling.
- Geography is global reference data with country-specific administrative terminology. The user-facing label **Province** may be used for the requested level, while the stored type and official name preserve the local designation (for example, Nigeria LGA or FCT Area Council).
- The Nigeria-specific schema is the preferred source for property-domain structure. The two older REMS guides remain references for workflows and features that do not conflict with this plan.
- Because the application has never been deployed, the existing application schema can be redesigned for a clean install. Do not remove authentication, auditability, authorization, organization hierarchy, or working UX merely to obtain a smaller migration set.

## 2. Domain model

### 2.1 Tenancy and organizations

`organizations` remain the tenant and business-unit boundary. All organization-owned records must have an explicit `organization_id` (or be reachable only through an organization-owned parent whose scope is enforced consistently). The global geography and carefully selected lookup catalogs are shared.

Keep:

- Parent/child organizations, organization memberships, scoped roles, permissions, branding, currency, locale, and timezone.
- Organization-aware policies and query scopes for every commercial workflow.
- Audit events associated with the acting organization and user.

Define whether permissions flow from a parent into its descendants, whether a user can act across sibling branches, and how delegated access is granted. Do not rely on implicit tree traversal as the only authorization boundary. Organization deletion must not cascade-delete financial, legal, or audit history.

### 2.2 Geography and addresses

Use normalized reference data:

- `countries`: ISO country code, display names, default currency/time zone as suggestions rather than hard constraints.
- `states` (or first-level subdivisions): linked to a country and capable of representing a state, territory, or equivalent.
- `provinces` / administrative areas: linked to the applicable first-level subdivision; store an official `administrative_type` and source name separately from the UI label.
- `cities` / localities: linked to a state/territory and optionally related to one or more lower-level administrative areas where boundaries do not map one-to-one.
- Property/project address details: district, neighborhood, estate, street, postal code, coordinates, and free-text directions where needed.

For Nigeria, seed the 36 states and FCT as first-level areas. Preserve FCT Area Council terminology and model boundaries without pretending they are identical to state LGAs. A city or urban area may cross lower-level boundaries; do not make a single province foreign key the only means of locating a city. Record geography source, version, and import date so reference data can be updated safely.

### 2.3 Development inventory

- `projects` represent estates, developments, or land schemes; include organization/developer ownership, location, lifecycle, phase information, and project-level amenities.
- `prototypes` represent reusable building plans and contain room counts, BQ, floor area, description, and indicative base price.
- `properties` represent actual saleable or lettable assets: land plots, houses, apartments, commercial spaces, or other units. A prototype is optional, so bare land remains a first-class property.
- Preserve Nigeria-specific identifiers and attributes: plot/block/house numbers, survey/beacon references, plot area, property use, finishing state (including carcass/shell/semi-finished/finished), corner status, and configurable price adjustments.
- Model inventory availability, reservation, construction/condition, sales, and tenancy as distinct concerns rather than overloading one status column.
- Add project amenities, prototype/property features, and media galleries. Prefer referentially safe attachment tables over unconstrained polymorphic IDs where practical.

### 2.4 Sales, subscriptions, and allocation

- A sale/subscription records the agreed buyer, property, organization, price, charge snapshot, payment plan, referral source, and agreement/status history.
- Installment schedules distinguish land/property consideration from company-configured additional charges.
- A property reservation is temporary and expiring; a subscription is a commercial commitment; provisional allocation and physical/site allocation are separate states.
- Allocation eligibility is a company-configured policy evaluated from approved ledger entries. The guide's 100% land / 50% fees threshold may be offered as a preset but is not a universal rule.
- Reservation and sale operations must be transactional and prevent a property from being concurrently sold/reserved twice.

### 2.5 Company-configured charge catalog

Provide a per-organization charge catalog and application rules. Each charge needs a name, category, calculation method (fixed, percentage, per-area, or manually quoted where applicable), applicability, effective dates, currency, and whether it is statutory, contractual, or company-defined. Record the applied amount and rule snapshot on the deal so later changes do not rewrite existing contracts.

Do not label development, deed-preparation, survey, service, or other charges as universally statutory. Support configurable allocation thresholds, installment plans, grace periods, late fees, and waivers with permission checks and audit events.

### 2.6 Rentals, tenancy, and rent collection

Keep rental operations distinct from sales subscriptions:

- `tenancies` connect a tenant, property, and landlord/management organization with term dates, rent amount/frequency, deposit, escalation terms, notice terms, renewal status, and signed-document references.
- `rent_charges` or receivables represent amounts due by period; `payments` and allocations record how receipts settle rent, deposits, service charges, penalties, or other balances.
- Support recurring rent schedules, reminders, receipt issuance, arrears, partial payment, advance payment, renewal, termination, handover, and refund/deposit reconciliation.
- Company policies configure payment frequencies, fees, grace periods, and escalation; state/jurisdiction and agreement data remain explicit.
- A property may have historical sales and tenancies, but it cannot have overlapping active exclusive tenancies unless an authorized exception is recorded.

### 2.7 Maintenance and facilities

- `maintenance_requests` capture reporter, property, issue category, urgency, description, attachments, and status history.
- Work orders capture assignee/vendor, inspection, quotation/approval, scheduled work, expenses, completion evidence, and tenant/owner communication.
- Property maintenance, common-area work, and project infrastructure are distinguishable. Organization permissions control whether tenants can submit requests and who can approve/close work.

### 2.8 Payments, ledger, commissions, and audit

- Store submitted payment attempts and approved financial entries as distinct statuses; use a unique provider/reference key for idempotency.
- Keep immutable ledger transactions and allocations as the financial source of truth. Totals shown on subscriptions, tenancies, wallets, or dashboards must be reconcilable to ledger records.
- Support manual bank proof review and payment-provider confirmation without treating unverified submissions as cash received.
- Configure commission rules per organization, project, agent tier/referral, eligible charge/payment category, percentage/fixed basis, effective date, and payout workflow.
- Record commission accruals and withdrawals as ledger events; do not make a mutable wallet balance the only record.
- Audit sensitive changes to price, charges, title records, payment approval/reversal, allocation, tenancy, KYC, commissions, and maintenance approvals.

## 3. Application architecture and user experience

Retain the Laravel modular-monolith architecture and existing Inertia/React/TypeScript approach. Keep controllers lean, centralize transactional rules in domain/application services, validate through Form Requests, and authorize via policies and scoped organization access.

Add or evolve user surfaces for:

1. Geography pickers, organization settings, and configurable company policy.
2. Projects, prototypes, properties, galleries, amenities, title vault, and inventory map/list.
3. Leads/clients, KYC, inspections, reservations, sale subscriptions, installment plans, and allocation.
4. Rental listings, tenant onboarding, tenancy agreements, rent schedules, receipts, arrears, renewal, and termination.
5. Maintenance requests, work orders, vendors, approvals, and completion evidence.
6. Payment review, ledger, charge catalog, realtor referrals/commissions, and reports.

Preserve the current organization switch/selection behavior and ensure each page/API request is scoped to the user's authorized organization set.

## 4. Fresh-start schema and migration strategy

This application has not been deployed. Use that flexibility to create a clean, coherent migration history rather than carrying forward an accidental prototype schema. Before removing or consolidating domain migrations:

1. Inventory all current models, controller behavior, permissions, test fixtures, and UI screens; identify working behavior to retain.
2. Keep framework-required user/authentication, cache, and queue tables as appropriate.
3. Establish an intentional migration baseline and rebuild database migrations in dependency order: identity/access; shared geography; organization settings and audit; projects/inventory; clients/documents; sales and tenancy; payments/ledger/commissions; maintenance; reports and notifications.
4. Keep UUIDs and precise decimal money columns; use foreign keys, uniqueness constraints, indexes, and database-portable types supported by the project.
5. Do not commit generated SQLite databases, secrets, or local uploads. Confirm tracked/untracked status before any cleanup.
6. Verify fresh install, migration rollback where supported, seed idempotency, and existing feature behavior.

If example/local data is needed, provide factories and seeders instead of production-like hard-coded tenant records. Seed reference geography from a traceable dataset and allow repeatable updates without duplicating rows.

## 5. Phased implementation and gates

### Phase 0 — Domain contract and safe reset

- Map existing application behavior to this plan.
- Confirm vocabulary and relationships for project/phase/property, sale/subscription, tenancy, charges, ledger, and organization scope.
- Define initial company-policy defaults and which reports are release-critical.
- Rebuild the migration baseline only after preserving existing organization and feature behavior in tests.

**Gate:** approved ERD/data dictionary; clean migration succeeds; key existing UX and sub-organization access tests pass.

### Phase 1 — Shared foundation

- Add countries, states/territories, administrative areas/provinces, cities/localities, address handling, and maintainable reference-data imports.
- Establish tenant-safe policies, organization context, global lookups, audit conventions, and company settings.
- Add factories, seeders, shared validation, and feature tests.

**Gate:** a user can manage records in permitted parent/child organizations while unrelated tenant data remains inaccessible.

### Phase 2 — Nigerian property inventory and title vault

- Build projects, phases, prototypes, physical properties/plots, amenities, features, media, and title/document records.
- Implement status separation, inventory search/filtering, document access, and concurrent reservation safeguards.

**Gate:** bare-land and built-property examples can be represented and reserved; no duplicate inventory code or concurrent reservation is accepted.

### Phase 3 — Client, sales, and allocation

- Complete CRM/KYC, inspection booking, reservation, subscription/contract, charge snapshots, schedules, payment submission/review, and configurable allocation policies.
- Keep signed contract/document generation and provisional/physical allocation history.

**Gate:** a full purchase workflow is auditable from prospect/inspection through settled balance and allocation.

### Phase 4 — Rental, tenancy, and rent accounting

- Add tenant onboarding, tenancy terms, rent schedules/charges, receipts, payment allocation, arrears, renewals, notices, terminations, and deposit accounting.
- Enforce overlap rules and organization-specific settings.

**Gate:** a tenancy can be created, billed, paid partially/in full, renewed or ended, with reconciled balances and no unauthorized overlapping tenancy.

### Phase 5 — Maintenance, vendors, commissions, and notifications

- Add maintenance intake/work orders/approvals/vendors and costs.
- Implement configurable realtor/referral commission policies and auditable payout ledger.
- Add notification templates and delivery tracking for due dates, rent, inspections, payment decisions, renewals, and maintenance updates.

**Gate:** approvals, expenses, commissions, payouts, and messages have traceable ownership and status.

### Phase 6 — Reporting, hardening, and release

- Add operational/financial reports and exports.
- Review access to KYC, bank, payment, title, tenancy, and document data; implement retention and download controls.
- Validate backups, migration recovery, accessibility, responsive layouts, and performance on expected data sizes.

**Gate:** clean install and core end-to-end flows pass automated tests; tenant isolation is verified; finance reports reconcile to ledger transactions.

## 6. Cross-cutting acceptance criteria

- **Organization isolation:** every organization-owned query and mutation is scoped; tests cover parent, child, sibling, member, and unrelated tenant cases.
- **Geography:** country selection filters first-level divisions; “Province” is a configurable display label; official Nigerian LGA/Area Council types are preserved; cities do not require a false one-to-one administrative mapping.
- **Inventory:** uniqueness rules reflect the actual project/phase/block/plot context; status transitions are validated and audited.
- **Money:** no binary floating-point financial calculations; all balances reconcile to approved ledger transactions and allocations.
- **Payment safety:** repeated webhooks/submissions do not duplicate receipts, balances, or commissions; reversals are explicit compensating events.
- **Tenancy:** contractual terms and charge snapshots remain immutable after signing; overlapping active leases require an explicit privileged exception.
- **Privacy and documents:** identity and financial files are stored privately; authorization is checked on every download; sensitive changes are audited.
- **UI and code quality:** PHP tests, TypeScript checks, and production build pass; validation and authorization errors are surfaced consistently.

## 7. Research references and domain caveats

- [Nigeria-specific database proposal](./nigeria_real_estate_schema.md)
- [Initial REMS workflow/master guide](./REMS_Copilot_Master_Specification.md)
- [Initial comprehensive REMS guide](./rems_complete_master_specification.md)
- [Nigeria Land Use Act (FAOLEX copy)](https://faolex.fao.org/docs/pdf/nig67625.pdf)
- [FCTA Land Administration Department](https://www.fcta.gov.ng/ova_dep/land-administration-department/)
- [AGIS land administration and title services](https://agis.fcta.gov.ng/fctlands/)
- [Nigeria Data Protection Commission](https://ndpc.gov.ng/)
- [Corporate Affairs Commission](https://www.cac.gov.ng/)

These sources inform the product model; they are not legal advice. State/FCT land registration processes, transaction documents, tenancy terms, and applicable charges must be verified with the relevant land authority and qualified Nigerian legal/property professionals before company policy or customer contracts are finalized.
