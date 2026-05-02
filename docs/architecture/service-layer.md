# Service Layer — conventions

The service layer is the **single source of truth** for state changes.
Controllers, console commands, queue jobs and tests all call services.
Services never call each other through the Facade — they inject one
another via constructor promotion.

## Module layout

```
app/app/Modules/<Context>/
├── Services/                  # one final class per use case
├── Models/                    # Eloquent models
├── Http/
│   ├── Controllers/
│   └── Requests/              # FormRequest validation
├── Policies/
├── Events/
├── Listeners/
├── Database/
│   ├── Migrations/
│   ├── Factories/
│   └── Seeders/
└── ModuleServiceProvider.php
```

## Naming

- Service: verb-noun, e.g., `RegisterDistributor`, `PlaceDistributor`,
  `CancelCoolingOff`. Located under `Modules/<Context>/Services/`.
- Method: invokable class preferred (`__invoke(...)`).
- DTOs: `RegisterDistributorInput`, `PlacementResult`, etc. Placed next
  to the service.

## Rules

1. **Never** touch the DB in a controller.
2. **Never** cross module boundaries except through events or service
   contracts in `Modules/<Context>/Contracts/`.
3. **Never** return raw Eloquent models across module boundaries. Return
   DTOs.
4. **Always** wrap multi-statement writes in a DB transaction.
5. **Always** emit a domain event when a state change completes.
6. **Always** write an `audit_log` row for admin actions.

## Example skeleton

```php
<?php
declare(strict_types=1);

namespace App\Modules\Genealogy\Services;

use App\Modules\Genealogy\DTO\PlaceDistributorInput;
use App\Modules\Genealogy\DTO\PlacementResult;
use App\Modules\Genealogy\Events\PlacementCreated;
use App\Modules\Shared\Services\SettingsRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

final class PlaceDistributor
{
    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly PlacementStrategyResolver $resolver,
        private readonly ClosureTableWriter $closure,
    ) {}

    public function __invoke(PlaceDistributorInput $in): PlacementResult
    {
        return DB::transaction(function () use ($in) {
            // 1. advisory lock on placement_id
            // 2. descendant validation
            // 3. resolve strategy + side
            // 4. walk down to first empty slot
            // 5. insert distributor + closure + sponsorship
            // 6. emit event
        });
    }
}
```

## Testing

- Every service has at least one unit test.
- Services that perform DB writes have a feature test that asserts
  event dispatch AND the persisted rows.
- Services with branching (strategy resolution, placement walk) have a
  property-based test.
