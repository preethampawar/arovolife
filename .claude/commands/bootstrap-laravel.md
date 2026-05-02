---
description: First-time scaffold of the Laravel 13 app, Docker stack, migrations, models, services and tests
allowed-tools: Bash(composer:*), Bash(php:*), Bash(php artisan:*), Bash(npm:*), Bash(docker compose:*), Bash(mkdir:*), Bash(ls:*), Bash(cat:*), Bash(cp:*), Read(**), Write(**), Edit(**), Glob(**), Grep(**)
---

# /bootstrap-laravel — first-time project scaffold

You are bootstrapping the Arovolife Phase 1 Laravel application from the
kickoff bundle. Read CLAUDE.md and docs/phase-1-prd.md first if you
haven't already this session.

## Run these steps in order, one at a time, with my approval between each

1. **Sanity check.** Confirm you are in the `arovolife-code/` folder.
   - `pwd` should end with `arovolife-code`
   - The folders `.claude/`, `docs/`, `migrations-blueprint/`, `placement-engine-spec/` must exist.
   - Stop if any are missing — abort and report.

2. **Create the Laravel app.**
   ```
   composer create-project laravel/laravel app "^11.0" --prefer-dist
   ```
   *(Use the `^11.0` constraint until Laravel 13 is available on Packagist; we will upgrade in a dedicated commit. The codebase targets Laravel 13 + PHP 8.4.)*

3. **Wire `app/.env`.** Copy `.env.example` from the project root into `app/.env`,
   then `php artisan key:generate` inside `app/`.

4. **Install required packages.**
   ```
   cd app
   composer require spatie/laravel-permission
   composer require pragmarx/google2fa-laravel
   composer require league/flysystem-aws-s3-v3
   composer require predis/predis
   composer require --dev larastan/larastan phpstan/phpstan-deprecation-rules vimeo/psalm pestphp/pest pestphp/pest-plugin-laravel laravel/pint
   ```

5. **Create the module skeleton** under `app/app/Modules/`:
   - `Identity/`, `Genealogy/`, `Kyc/`, `Consent/`, `Orientation/`, `Compliance/`, `Admin/`, `Shared/`
   - Each with `Models/`, `Services/`, `Http/Controllers/`, `Http/Requests/`, `Events/`, `Listeners/`, `Policies/`, `Database/Migrations/`
   - Add a `ModuleServiceProvider.php` per module and register them in `bootstrap/providers.php`.

6. **Generate migrations from `migrations-blueprint/`.** For each `*.sql`
   file, create the corresponding Laravel migration in the relevant module's
   `Database/Migrations/` folder. Field types, indexes and foreign keys
   must match the SQL exactly. Do NOT alter the schema — open an ADR if
   you need to.

7. **Generate Eloquent models** for the 13 Phase-1 tables in their owning
   modules. Conventions in CLAUDE.md apply.

8. **Implement the placement engine.** Read `placement-engine-spec/`
   end-to-end, then implement:
   - `Modules/Genealogy/Services/PlacementStrategyResolver.php`
   - `Modules/Genealogy/Services/PlacementEngine.php`
   - Domain events `placement.created`, `distributor.registered`
   - PHPUnit tests covering every scenario in
     `placement-engine-spec/test-scenarios.md`. Use property-based tests
     (Pest with custom generators) for randomised tree fuzzing.

9. **Wire up Docker.** Copy `docker/docker-compose.yml` and `docker/Dockerfile`
   into a working state and bring the stack up:
   ```
   docker compose -f docker/docker-compose.yml up -d
   docker compose exec app php artisan migrate
   docker compose exec app php artisan test
   ```

10. **Compliance gate.** Before reporting "done":
    - Run `/compliance-check`.
    - Confirm hard-rules §1-8 from CLAUDE.md are not violated by anything you wrote.
    - Confirm no PII is logged.
    - Confirm all tests pass.

## Stop conditions

- Abort and ask if you cannot run a step (missing tool, network, etc.).
- Abort if any step would rewrite a compliance-critical file without a
  matching ADR.
- Abort if `php artisan test` is not green.

## Output

A short report summarising:
- packages installed,
- modules created,
- migrations generated,
- placement engine test results,
- any deviations from the blueprint and why,
- next recommended `/`-command (probably `/placement-test` then
  `/compliance-check`).
