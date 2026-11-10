<?php

declare(strict_types=1);

namespace App\Modules\Shared\Testing\Console;

use App\Modules\Shared\Testing\E2eMode;
use App\Modules\Shared\Testing\Scenarios\E2eScenarioBuilder;
use App\Modules\Shared\Testing\Scenarios\E2eScenarios;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

/**
 * Build a named scenario's input (partners, Genos placement, paid orders) on
 * the e2e database and print what was made as JSON for the Playwright helper.
 * Run it on a fresh `e2e:reset`. Row ids are stable from a clean base; ADNs
 * and order numbers are random, so read them from the JSON.
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

        // No wrapping transaction: checkout issues each invoice after its own
        // commit, and it must do so at the order's instant, not at the end.
        // A failed build is undone by the next e2e:reset.
        //
        // The world's signup, placement and KYC-approved mail is not sent: it
        // would put three messages per partner into the Mailpit the dev stack
        // shares. Mail itself is covered by the UI registration specs.
        Mail::fake();
        Notification::fake();

        $define($builder);

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
        $this->info(sprintf('%d order(s).', count($result['orders'])));

        return self::SUCCESS;
    }
}
