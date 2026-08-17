<?php

declare(strict_types=1);

namespace App\Modules\Shared\Console\Commands;

use Illuminate\Console\Command;
use JsonException;

/**
 * Generates the CycloneDX software bill of materials (T-6.1 finding H-2).
 *
 * An SBOM is the list of everything third-party the platform runs on, in a
 * format a scanner can read. Without one, "are we affected by this CVE?" is
 * answered by a person grepping `composer.lock` under time pressure, which is
 * how a vulnerable dependency survives an incident.
 *
 * Built from the lockfiles rather than the installed tree on purpose: the
 * lockfiles are what CI and production install from, so the SBOM describes
 * what will actually be deployed rather than what happens to be on this
 * machine.
 *
 * **Run it from the host, not the app container.** The default output lives in
 * `docs/`, which sits above the application root and is not mounted into the
 * container — same constraint as `phase:status`. Pass `--output` to write
 * somewhere else.
 */
final class GenerateSbomCommand extends Command
{
    protected $signature = 'security:sbom
        {--output= : Where to write it (defaults to docs/security/sbom.cyclonedx.json)}
        {--check : Exit non-zero if the committed SBOM is out of date, and write nothing}';

    protected $description = 'Generate the CycloneDX SBOM from composer.lock and package-lock.json';

    public function handle(): int
    {
        $output = (string) ($this->option('output') ?: base_path('../docs/security/sbom.cyclonedx.json'));

        try {
            $sbom = $this->build();
        } catch (JsonException $e) {
            $this->error('Could not read a lockfile: '.$e->getMessage());

            return self::FAILURE;
        }

        $json = json_encode($sbom, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";

        if ($this->option('check')) {
            $existing = is_file($output) ? (string) file_get_contents($output) : '';

            if ($existing !== $json) {
                $this->error('The committed SBOM is out of date. Run `php artisan security:sbom`.');

                return self::FAILURE;
            }

            $this->info('SBOM is up to date.');

            return self::SUCCESS;
        }

        $directory = dirname($output);

        if (! is_dir($directory) && ! @mkdir($directory, 0o755, true) && ! is_dir($directory)) {
            $this->error("Could not create {$directory}.");
            $this->line('If you are inside the app container, docs/ is not mounted — run this from the host,');
            $this->line('or pass --output to write somewhere the container can reach.');

            return self::FAILURE;
        }

        if (@file_put_contents($output, $json) === false) {
            $this->error("Could not write {$output}.");

            return self::FAILURE;
        }

        $runtime = count(array_filter($sbom['components'], static fn (array $c): bool => $c['scope'] === 'required'));

        $this->info(sprintf(
            'Wrote %s — %d components (%d runtime, %d dev).',
            $output,
            count($sbom['components']),
            $runtime,
            count($sbom['components']) - $runtime,
        ));

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws JsonException
     */
    private function build(): array
    {
        $composer = $this->readJson(base_path('composer.lock'));
        $npm = is_file(base_path('package-lock.json')) ? $this->readJson(base_path('package-lock.json')) : ['packages' => []];

        $components = [];

        foreach (['packages' => 'required', 'packages-dev' => 'optional'] as $key => $scope) {
            foreach ($composer[$key] ?? [] as $package) {
                $components[] = [
                    'type' => 'library',
                    'name' => $package['name'],
                    'version' => $package['version'],
                    'purl' => 'pkg:composer/'.$package['name'].'@'.$package['version'],
                    'scope' => $scope,
                    'licenses' => array_map(
                        static fn (string $license): array => ['license' => ['id' => $license]],
                        $package['license'] ?? [],
                    ),
                ];
            }
        }

        foreach ($npm['packages'] ?? [] as $path => $meta) {
            if (! str_starts_with((string) $path, 'node_modules/') || ! isset($meta['version'])) {
                continue;
            }

            $name = substr((string) $path, strlen('node_modules/'));

            $components[] = [
                'type' => 'library',
                'name' => $name,
                'version' => $meta['version'],
                'purl' => 'pkg:npm/'.$name.'@'.$meta['version'],
                // npm's `dev` flag is the same distinction as composer's
                // packages-dev: it decides whether a CVE is on the production
                // path or only on a developer's laptop.
                'scope' => ($meta['dev'] ?? false) ? 'optional' : 'required',
                'licenses' => is_string($meta['license'] ?? null)
                    ? [['license' => ['id' => $meta['license']]]]
                    : [],
            ];
        }

        usort($components, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return [
            '$schema' => 'http://cyclonedx.org/schema/bom-1.5.schema.json',
            'bomFormat' => 'CycloneDX',
            'specVersion' => '1.5',
            'version' => 1,
            'metadata' => [
                'component' => [
                    'type' => 'application',
                    'name' => 'arovolife-platform',
                    // The lockfile content hash, not a timestamp: the SBOM
                    // should change when the dependencies change and not
                    // otherwise, or `--check` becomes noise.
                    'version' => substr((string) ($composer['content-hash'] ?? ''), 0, 12),
                ],
                'properties' => [
                    ['name' => 'composer:content-hash', 'value' => (string) ($composer['content-hash'] ?? '')],
                    ['name' => 'php', 'value' => (string) ($composer['platform']['php'] ?? '')],
                ],
            ],
            'components' => $components,
        ];
    }

    /**
     * @return array<string, mixed>
     *
     * @throws JsonException
     */
    private function readJson(string $path): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
