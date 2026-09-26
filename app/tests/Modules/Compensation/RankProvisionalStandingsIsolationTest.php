<?php

declare(strict_types=1);

/*
 * Hard rule 2 / hard rule 3 guard (compliance review 2026-09-26, condition 1).
 *
 * The rank progress snapshot is progress, never a rank. If anything that pays,
 * pools, grants, announces or terminates ever read it, a provisional standing
 * would become money or a status. So only the classes that build or display
 * it may name it — anywhere in app/, every module.
 */

function isolationPhpFiles(string $dir): array
{
    $files = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }

    return $files;
}

it('lets only the snapshot\'s builders and its progress views read the provisional table', function (): void {
    $allowed = [
        'Modules/Compensation/Models/RankProvisionalStanding.php',
        'Modules/Compensation/Database/Migrations/2026_09_26_120000_create_rank_provisional_standings_table.php',
        'Modules/Compensation/Services/RankProvisionalStandingService.php',
        'Modules/Compensation/Console/Commands/RankProvisionalStandingsCommand.php',
        'Modules/Compensation/Support/EngineRegistry.php',
        'Modules/Compensation/Support/DerivedTables.php',
        'Modules/Compensation/Services/RankStatusService.php',
        'Modules/Compensation/Http/Controllers/Admin/AdminDistributorCompController.php',
        // Registers the console command — names the class, reads nothing.
        'Providers/AppServiceProvider.php',
    ];

    $offenders = [];

    foreach (isolationPhpFiles(app_path()) as $path) {
        $relative = str_replace(app_path().'/', '', $path);
        $source = (string) file_get_contents($path);

        if ((str_contains($source, 'rank_provisional_standings') || str_contains($source, 'RankProvisionalStanding'))
            && ! in_array($relative, $allowed, true)) {
            $offenders[] = $relative;
        }
    }

    expect($offenders)->toBe([]);
});

it('keeps the offer, announcement, termination and repurchase readers on recorded ranks', function (): void {
    foreach ([
        'Modules/Commerce/Services/PurchaseOfferService.php',
        'Modules/Content/Services/AnnouncementService.php',
        'Modules/Compliance/Services/InactivityTerminationService.php',
        'Modules/Compensation/Services/RepurchaseCycleService.php',
    ] as $relative) {
        $source = (string) file_get_contents(app_path($relative));

        expect(str_contains($source, 'rank_qualifications') || str_contains($source, 'RankQualification'))
            ->toBeTrue("{$relative} no longer reads recorded ranks");
    }
});
