# Feature Flags (T-5.4)

Powered by [Laravel Pennant](https://laravel.com/docs/pennant) with the
`database` driver. The `features` table holds one row per resolved flag.

## How to add a new flag

1. Create a resolver class under `app/Modules/Shared/Features/` (or the
   relevant module's `Features/` directory). The class must expose a
   `resolve(mixed $scope): mixed` method. The class name is the Pennant
   key — do not refactor without migrating the `features.name` column.
2. Register the flag in `AdminFeatureFlagController::registry()` so the
   admin UI lists it.
3. Read the flag at the call site:

   ```php
   if (! \Laravel\Pennant\Feature::active(\App\Modules\Shared\Features\MyFlag::class)) {
       // killswitch path
   }
   ```

4. Default value lives in the resolver class — typically `return true`
   for "default on, kill if needed", `return false` for "off until
   admin opts in".

## How to flip a flag

- Admin UI at `/admin/feature-flags`
- Or `php artisan pennant:activate <key>` / `:deactivate`
- Every change via the admin UI writes an `audit_log` entry of action
  `feature_flag.toggled` with `from` / `to` state.

## Conventions

- Default to ON for killswitches, OFF for in-progress features.
- Scope is intentionally unused for now — flags are global. Per-user
  scoping is supported by Pennant when we need canary releases.
- Feature classes are stateless; they never read DB or auth context
  inside `resolve()`. If you need contextual logic, accept a scope.
- In-progress wizards / authenticated flows do NOT re-check
  killswitches mid-session. Killswitches are for new entries only.

## Current flags

| Key                       | Default | Purpose                                                                                                                       |
| ------------------------- | ------- | ----------------------------------------------------------------------------------------------------------------------------- |
| `registration.killswitch` | ON      | Master kill for `/register`, `/join`, `/register/account`. Deactivate during compliance pauses or payment-gateway incidents. |
