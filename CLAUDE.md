# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

The Laravel 13 / PHP 8.4 JSON API behind Lendyph, a cooperative lending app.

**It is API-only.** Its only client is the separate Next.js repo `lendyph-frontend`. `routes/web.php` deliberately has no routes, and there are no Blade views, so the Boost block's Vite / Tailwind / frontend-bundling advice below does not apply.

**The Boost tooling may not exist here.** The Boost MCP tools and skills that block tells you to use (`search-docs`, `database-query`, `pest-testing`, …) only exist where Boost has been installed locally; `.mcp.json`, `boost.json` and `.claude/` are gitignored. If they aren't available, read the code and use artisan instead.

**Five single-tenant deployments.** The same code runs as five deployments, one cooperative per instance and database. There is no tenant or organization model:

- Identity comes from env (`APP_NAME`).
- Settings live in bespoke singleton tables (e.g. `BrandingSetting::current()`).
- Anything one client needs goes behind config or env, never a branch.

## Commands

MySQL is required. `phpunit.xml` points at `lendyph_testing` on `root@127.0.0.1:3306`, and the migrations use MySQL `ENUM` DDL.

```bash
docker compose -f docker-compose.testing.yml up -d    # throwaway tmpfs MySQL on :3306
composer test                                         # config:clear, then php artisan test
composer test:parallel                                # what CI runs (with --processes=4)
php artisan test --compact tests/Feature/LoanAdjustmentTest.php
php artisan test --compact --filter=some_test_name
vendor/bin/pint --dirty                               # CI fails on `pint --test`
composer audit --locked                               # CI fails on high/critical advisories
```

- **The suite wipes whatever database it connects to.**
  - `tests/TestCase.php` uses `RefreshDatabase`, which runs `migrate:fresh` and seeds `DatabaseSeeder` once per worker.
  - `phpunit.xml`'s `<env>` entries do NOT override variables already set in the process environment. So a shell or container that exports `DB_HOST` / `DB_DATABASE` for a dev or staging database gets that database dropped.
  - Only run tests where `DB_*` is unset or points at a throwaway MySQL, and go through `composer test`, which clears cached config first.
- **`php artisan test --parallel` exits 0 even when tests fail.** Read the `Tests:` summary line instead. Pest colours it even without a TTY, so strip ANSI before grepping. CI does exactly this and also enforces a minimum test count (`MIN_TESTS`).
- **Never run two parallel runs against the same MySQL at once.** Parallel workers use `lendyph_testing_test_{1..N}`, so two runs clobber each other.
- **Each test runs in a rolled-back transaction** on top of the seeded baseline (user `super_admin` / `password`, branch "Main Branch").
  - A test that runs DDL must set `protected bool $wrapsEachTestInTransaction = false;`, which runs a real `migrate:fresh` before it.
  - `tests/Traits/SetupLendyPH.php` provides `seedAndLogin()` (acts as `super_admin`) and `createReleasedLoan()`, which drives the real create → submit → approve → release path.
- **Feature tests mix PHPUnit classes (most of them) and Pest functions.**
  - A Pest-style file must call `uses(Tests\TestCase::class);` itself.
  - Never add a `tests/Pest.php` folder binding: Pest then rejects the whole run (`tests/Unit/FeatureTestCaseArchTest.php`).
- **Always send `Accept: application/json`** (in tests, use `getJson` / `postJson` / …). `withExceptions` is empty, so without it a 401 tries to redirect to a nonexistent `login` route and returns a 500.

## Guards that only fail the full suite

These guards don't run in a filtered run of just your own tests, so run the full suite before opening a PR.

- **New datetime or timestamp column: register it in `app/Services/TimezoneShift.php`.**
  - New tables go in `EXCLUDED_COLUMNS`, because the one-shot UTC → Asia/Manila shift has already run on every deployment.
  - `DATE` columns are never listed.
  - Enforced by `TimezoneShiftTest`.
- **Editing `LoanService.php`, `RepaymentService.php` or `CsvImport/CsvImportProcessor.php`.**
  - `CollateralIntegrityGuardsTest` pins every write of a `released` / `ongoing` loan status by `file:line`, so an edit *above* one of those lines fails it.
  - If only the line numbers moved, renumber the expected list.
  - A genuinely new status write must lock and assert through `CollateralPledgeGuard` first.
- **Other guards:**
  - `BranchAssignmentIsDisplayOnlyTest`: a user's branch must never scope what they can see.
  - `CreditScoringNotSeededTest`: no `credit_scoring:*` permissions until that backend exists.
  - The arch tests in `tests/Unit/*ArchTest.php`.

## Architecture

**Request flow.**
- All endpoints are in `routes/api.php`, with no versioning.
- A few routes are public: `/health`, `/auth/login`, `/branches/public`, `/branding/*`, signed `/files/*`, and public registration. Everything else sits in one group: `auth:sanctum` + `CheckTokenExpiry` + `EnsureUserIsActive` + `RequirePasswordChange`.
- A request goes `Api/*Controller` → FormRequest (`app/Http/Requests`, grouped by domain) → service in `app/Services` → `JsonResource` (`app/Http/Resources`) or `{message, data}`.
- Business logic lives in services: `LoanService`, `RepaymentService`, `LoanAdjustmentService`, `LoanApprovalChainService`, `ReportService`, `Services/Accounting/`, `Services/CsvImport/`.
- Models enforce invariants in `booted()`: sequence codes (`LA-`, `LN-`, `RCP-`, `ADJ-`, …) and journal immutability.
- There are no Actions, Policies, Observers, Events, Jobs, Mail or Notifications.
- The middleware priority tweaks in `bootstrap/app.php` are load-bearing. Read their comments before touching them.

**Auth and permissions.**
- Sanctum bearer tokens only, with no cookies and no stateful domains. There is an idle timeout (`CheckTokenExpiry` + `TokenIdleWindow`), and `POST /auth/refresh` rotates the token.
- `must_change_password` makes every call return **423** (`code: password_change_required`), except `/auth/me`, `/auth/change-password` and `/auth/logout`.
- Roles and permissions use spatie/laravel-permission (`web` guard) with `resource:action` names. `super_admin` passes every check through `Gate::before`.
- **Authorize in the controller (`$this->authorize('loans:view')`) or in the FormRequest's `authorize()`, never with route middleware.** No route uses `permission:`.
- **A new permission must go in `RoleAndPermissionSeeder` *and* in a data migration** (see `database/migrations/*_add_*_permission*.php`), because deployed databases never re-run seeders.
- Approval-chain steps are role-based (`LoanApprovalChainService::canAct()`).
- Unauthorized user-management calls answer 404, not 403.

**Loan lifecycle.**
- Statuses: `draft → for_review → approved → released → ongoing → completed`, plus `rejected`, `void`, `defaulted` and `restructured`. The status sets are defined on `Loan`.
- Statuses are MySQL `ENUM` columns, so adding one needs a raw `ALTER TABLE … MODIFY` migration.
- Submitting a loan seeds `loan_approval_steps` from `ApprovalWorkflowSetting`.
- `LoanService::release()` runs in one transaction with a documented statement order: the collateral lock comes first and `CollateralPledgeGuard::assertNoDoublePledge()` comes last. It requires the `fee_fingerprint` from `release-preview` and returns 409 if the fees changed since then.
- `RepaymentService::processRepayment()` allocates penalty, then interest, then principal, across schedules in period order. Its preview runs the real method inside a rolled-back transaction.
- Adjustments (`restructure`, `penalty_waiver`, `balance_adjustment`, `term_extension`) are created, then approved, then applied (`LoanAdjustmentService`). `balance_adjustment` and `term_extension` take deltas, not resulting values.
- `POST /loans/{loan}/extend` only works for loans whose *original* term was one month (`Loan::isOneMonthTerm()`, exposed to the frontend as `is_one_month_term`).
- **Loan maths.** `term` counts periods of the loan's `frequency` (`app/Enums/LoanFrequency.php`), not months. `interest_rate` is a percentage applied once per period; the code comments that call it a "monthly rate" only hold for monthly loans. The interest methods are `straight`, `diminishing` and `upon_maturity`.

**Accounting.**
- Lending tables hold decimal pesos; accounting tables hold integer centavos (`Services/Accounting/Money.php`).
- `JournalPoster` is the only thing that writes journals. It checks `PeriodGuard` and allocates the `JE-` numbers.
- Posted journals are immutable; correct them with `reverse()`.
- Automatic postings (loan release, collection, void reversal) are explicit `AutomaticPoster` calls inside the lending transaction, never observers or events. Its docblock explains why.
- If no chart of accounts has been seeded, automatic posting does nothing. Once one exists, an unmapped posting role throws `CannotPostToTheBooksException` (422, `errors.accounting`) and rolls back the release or payment.
- **Polymorphic rows store full class names; there is no morph map.** This covers `accounting_journals.postable_type`, documents, audit logs and role assignments.
  - Never add `with('postable')`; use `AccountingJournal::attachPostables()`.
  - Don't rename a model that polymorphic rows refer to.

**Time.** `APP_TIMEZONE` is `Asia/Manila` and timestamps are stored in Manila time. The API still serializes them as UTC ISO strings, because nothing overrides `serializeDate`.

**Files.**
- Borrower photos, IDs, documents and CSV imports live on the `private` disk and are served only through signed, expiring links (`SignedFileLink`, `FileController`). Only branding stays on the `public` disk.
- The scheduler runs as root, but php-fpm runs as `www-data`. So console code must not create files on the private disk, and file-moving artisan commands must run as `www-data`.

**Background work.**
- No queue worker runs on any deployment, so never dispatch jobs.
- Recurring work is scheduled in `routes/console.php`: penalties, default checks, backups, and `imports:process` every minute.
- There is no mail or SMS.

**CSV import.** Files arrive by chunked, resumable upload into staged `csv_import_rows`. A human then confirms the product mapping, and `imports:process` writes borrowers and loans in batches. Imported historical loans post no journals.

**API conventions.**
- Lists return the paginator envelope `{data, links, meta}` (sometimes with `meta.stats`) and clamp `per_page` to 100. Report endpoints instead validate `per_page` ≤ 1000 (`ReportController::reportFilters()`).
- `GET /api/health` returns `{status, timestamp, commit, branch, env}`. `DeploymentIdentity` reads `.git` directly.
- Swagger (l5-swagger) is off unless `L5_SWAGGER_ENABLED=true`. Controllers and resources carry `#[OA\...]` attributes.
- In `routes/api.php`, literal segments must come before `{wildcard}` ones, and id parameters are constrained with `whereNumber`.

## Branches, CI and deploys

- **Branching.** Branch from and open PRs into `development`; `main` is production.
- **CI.** The required check is `pest` (`.github/workflows/test.yml`): composer audit, Pint, then the parallel suite.
- **Deploys.** Pushes auto-deploy:
  - `development` goes to both staging APIs and the portfolio demo.
  - `main` goes to **every production API immediately**, so the promotion PR is the release gate.
  - A `.github/**`-only change deploys nothing.
- **What a deploy runs.** Deploys run `migrate` and `config:cache`. So read settings with `config()`, never `env()` outside `config/`, and ship one-shot data fixes (such as new permissions) as migrations.
- **Dependabot** targets `development`.

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application and its main Laravel ecosystems package & versions are below. You are an expert with them all. Ensure you abide by these specific packages & versions.

- php - 8.4
- laravel/framework (LARAVEL) - v13
- laravel/prompts (PROMPTS) - v0
- laravel/sanctum (SANCTUM) - v4
- laravel/boost (BOOST) - v2
- laravel/mcp (MCP) - v0
- laravel/pail (PAIL) - v1
- laravel/pint (PINT) - v1
- pestphp/pest (PEST) - v4
- phpunit/phpunit (PHPUNIT) - v12
- tailwindcss (TAILWINDCSS) - v4

## Skills Activation

This project has domain-specific skills available. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

- `laravel-best-practices` — Apply this skill whenever writing, reviewing, or refactoring Laravel PHP code. This includes creating or modifying controllers, models, migrations, form requests, policies, jobs, scheduled commands, service classes, and Eloquent queries. Triggers for N+1 and query performance issues, caching strategies, authorization and security patterns, validation, error handling, queue and job configuration, route definitions, and architectural decisions. Also use for Laravel code reviews and refactoring existing Laravel code to follow best practices. Covers any task involving Laravel backend PHP code patterns.
- `pest-testing` — Use this skill for Pest PHP testing in Laravel projects only. Trigger whenever any test is being written, edited, fixed, or refactored — including fixing tests that broke after a code change, adding assertions, converting PHPUnit to Pest, adding datasets, and TDD workflows. Always activate when the user asks how to write something in Pest, mentions test files or directories (tests/Feature, tests/Unit, tests/Browser), or needs browser testing, smoke testing multiple pages for JS errors, or architecture tests. Covers: it()/expect() syntax, datasets, mocking, browser testing (visit/click/fill), smoke testing, arch(), Livewire component tests, RefreshDatabase, and all Pest 4 features. Do not use for factories, seeders, migrations, controllers, models, or non-test PHP code.
- `tailwindcss-development` — Always invoke when the user's message includes 'tailwind' in any form. Also invoke for: building responsive grid layouts (multi-column card grids, product grids), flex/grid page structures (dashboards with sidebars, fixed topbars, mobile-toggle navs), styling UI components (cards, tables, navbars, pricing sections, forms, inputs, badges), adding dark mode variants, fixing spacing or typography, and Tailwind v3/v4 work. The core use case: writing or fixing Tailwind utility classes in HTML templates (Blade, JSX, Vue). Skip for backend PHP logic, database queries, API routes, JavaScript with no HTML/CSS component, CSS file audits, build tool configuration, and vanilla CSS.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `npm run build`, `npm run dev`, or `composer run dev`. Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Always use `search-docs` before making code changes. Do not skip this step. It returns version-specific docs based on installed packages automatically.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.
- To check environment variables, read the `.env` file directly.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== tests rules ===

# Test Enforcement

- Every change must be programmatically tested. Write a new test or update an existing test, then run the affected tests to make sure they pass.
- Run the minimum number of tests needed to ensure code quality and speed. Use `php artisan test --compact` with a specific filename or filter.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

## Vite Error

- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== pest/core rules ===

## Pest

- This project uses Pest for testing. Create tests: `php artisan make:test --pest {name}`.
- Run tests: `php artisan test --compact` or filter: `php artisan test --compact --filter=testName`.
- Do NOT delete tests without approval.

</laravel-boost-guidelines>
