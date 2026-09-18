<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Compensation\Support\EngineRegistry;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel;

/**
 * A stand-in for one step of a run: same artisan name, same period option, so
 * RecordEngineRun still writes its `engine_runs` row, but it records the call
 * and the period it was given and returns whatever exit code the test asks for.
 *
 * Replacing the real engines is what makes the ORDER observable. Running the
 * genuine ones would prove only that nothing threw.
 *
 * Shared by the nightly, weekly and monthly run tests: all three assert on the
 * same two static arrays, and three copies of this class would be three places
 * for one of them to stop recording periods.
 */
final class StubEngineStepCommand extends Command
{
    /** @var list<string> Engine keys, in the order they were invoked. */
    public static array $calls = [];

    /** @var array<string, string> Engine key => the period it was passed. */
    public static array $periods = [];

    /** @var array<string, int> Engine key => exit code to return. */
    public static array $exitCodes = [];

    public function __construct(private readonly string $engineKey, string $signature)
    {
        $this->signature = $signature;
        $this->description = 'Test stub for '.$engineKey;

        parent::__construct();
    }

    /**
     * Swap each named engine for a recording stub, and forget every earlier
     * call.
     *
     * @param  list<string>  $keys
     */
    public static function register(array $keys): void
    {
        self::$calls = [];
        self::$periods = [];
        self::$exitCodes = [];

        foreach ($keys as $key) {
            $definition = EngineRegistry::get($key);

            app(Kernel::class)->registerCommand(new self(
                $key,
                sprintf('%s {%s=}', $definition->commandSignature, $definition->periodOption),
            ));
        }
    }

    public function handle(): int
    {
        self::$calls[] = $this->engineKey;
        $period = $this->option(ltrim(EngineRegistry::get($this->engineKey)->periodOption, '-'));

        self::$periods[$this->engineKey] = is_string($period) ? $period : '';

        return self::$exitCodes[$this->engineKey] ?? self::SUCCESS;
    }
}
