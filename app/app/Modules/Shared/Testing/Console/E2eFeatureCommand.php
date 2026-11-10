<?php

declare(strict_types=1);

namespace App\Modules\Shared\Testing\Console;

use App\Modules\Shared\Testing\E2eMode;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Laravel\Pennant\Feature;

/**
 * Switch one feature flag on the e2e database, globally (scope null, as the
 * admin toggle does). For specs that cover a flag the snapshot pins OFF —
 * including flags with no admin toggle. Undone by the next `e2e:reset`.
 */
final class E2eFeatureCommand extends Command
{
    protected $signature = 'e2e:feature
                            {feature : Class basename under App\\Modules\\Shared\\Features, e.g. InventoryFeature}
                            {state : on or off}';

    protected $description = 'Switch a feature flag on or off on the e2e database';

    public function handle(): int
    {
        E2eMode::assertSafe($this->laravel);

        $class = 'App\\Modules\\Shared\\Features\\'.$this->stringArgument('feature');
        $state = $this->stringArgument('state');

        if (! class_exists($class) || ! in_array($state, ['on', 'off'], true)) {
            throw new InvalidArgumentException("Usage: e2e:feature <FeatureClass> on|off (got {$class} {$state})");
        }

        $state === 'on' ? Feature::for(null)->activate($class) : Feature::for(null)->deactivate($class);
        Feature::flushCache();

        $this->line(class_basename($class).' '.(Feature::for(null)->active($class) ? 'on' : 'off'));

        return self::SUCCESS;
    }

    private function stringArgument(string $name): string
    {
        $value = $this->argument($name);

        return is_string($value) ? $value : '';
    }
}
