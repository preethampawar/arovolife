<?php

declare(strict_types=1);

namespace App\Modules\Shared\Testing\Console;

use App\Modules\Shared\Testing\E2eMode;
use App\Modules\Shared\Testing\Scenarios\E2eScenarioBuilder;
use App\Modules\Shared\Testing\Scenarios\E2eScenarios;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Build a named scenario's input (partners, Genos placement, paid orders) on
 * the e2e database and print what was made as JSON for the Playwright helper.
 * Run it on a fresh `e2e:reset`; the IDs are only stable from a clean base.
 */
final class E2eScenarioCommand extends Command
{
    protected $signature = 'e2e:scenario {name : Scenario name, see E2eScenarios} {--json : Print the result as one JSON line}';

    protected $description = 'Build a deterministic e2e scenario (partners and paid orders)';

    public function handle(): int
    {
        E2eMode::assertSafe($this->laravel);

        $name = $this->argument('name');
        $name = is_string($name) ? $name : '';
        $define = E2eScenarios::get($name);
        $builder = new E2eScenarioBuilder;

        DB::transaction(static function () use ($define, $builder): void {
            $define($builder);
        });

        $result = ['scenario' => $name] + $builder->result();

        if ($this->option('json')) {
            $this->line((string) json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->table(['Partner', 'ADN', 'E-mail'], array_map(
            static fn (string $key, array $p): array => [$key, $p['adn'], $p['email']],
            array_keys($result['partners']),
            $result['partners'],
        ));
        $this->info(sprintf('%d order(s). Password for every partner: %s', count($result['orders']), $result['password']));

        return self::SUCCESS;
    }
}
