<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use Tests\TestCase;

/**
 * `docs/compliance/dsr-2021-mapping.md` is the document a regulator is handed.
 *
 * It named seventeen test artefacts as the evidence behind each statutory
 * obligation. Eleven of them did not exist — some renamed, two correctly
 * retired by ADR-0003, three never written — and nothing noticed for months,
 * because a markdown file has no way to fail. A mapping that cites evidence
 * which cannot be produced is worse than one that admits a gap.
 *
 * This test is the missing feedback loop: rename a test and the mapping fails
 * until it is updated. It deliberately does NOT check that the tests pass —
 * that is the suite's job — only that everything the document promises can
 * actually be produced.
 */
final class DsrMappingIsHonestTest extends TestCase
{
    public function test_every_test_named_by_the_dsr_mapping_exists(): void
    {
        $path = base_path('../docs/compliance/dsr-2021-mapping.md');

        if (! is_file($path)) {
            // The docs directory is not mounted inside the app container.
            // Skipping loudly rather than passing silently: a green tick for a
            // check that did not run is exactly the failure this file is about.
            $this->markTestSkipped('docs/ is not reachable from here — run this from the host.');
        }

        $contents = (string) file_get_contents($path);

        preg_match_all('/\b([A-Z][A-Za-z0-9]*Test)\b/', $contents, $matches);

        $named = array_values(array_unique($matches[1]));

        $this->assertNotEmpty($named, 'The mapping names no tests at all — has it been gutted?');

        $missing = [];

        foreach ($named as $test) {
            $found = glob(base_path('tests/**/**/'.$test.'.php'))
                ?: glob(base_path('tests/**/'.$test.'.php'))
                ?: glob(base_path('tests/'.$test.'.php'));

            if ($found === [] || $found === false) {
                $missing[] = $test;
            }
        }

        $this->assertSame(
            [],
            $missing,
            "docs/compliance/dsr-2021-mapping.md names test(s) that do not exist:\n  ".
            implode("\n  ", $missing)."\n".
            'Either the test was renamed and the mapping needs updating, or the evidence it claims '.
            'cannot be produced. Do not leave the name in place — write "no test exists" and cite the '.
            'risk row instead.',
        );
    }
}
