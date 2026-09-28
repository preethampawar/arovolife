<?php
declare(strict_types=1);

it('no user-facing view, help page or label says Left/Right group', function () {
    $files = array_merge(
        glob(resource_path('views/**/*.blade.php')) ?: [],
        iterator_to_array(new RegexIterator(new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('views'))), '/\.blade\.php$/'), false),
        glob(resource_path('help/*.md')) ?: [],
        [app_path('Modules/Compensation/Services/IncomeOverviewService.php'),
         app_path('Modules/Compensation/Services/GenosBvLedgerService.php'),
         app_path('Modules/Compensation/Services/DTOs/GenosLedgerDay.php'),
         app_path('Modules/Compensation/Services/DTOs/GsbSlabRow.php'),
         app_path('Modules/Compensation/Services/DTOs/GsbSlabProgress.php')],
    );
    $offenders = [];
    foreach (array_unique(array_map('strval', $files)) as $f) {
        // Strip Blade and PHP comments so developer notes don't count.
        $src = preg_replace(['/\{\{--.*?--\}\}/s', '#/\*.*?\*/#s', '#^\s*//.*$#m'], '', (string) file_get_contents($f));
        if (preg_match('/\b(Left|Right) group\b/i', $src)) {
            $offenders[] = str_replace(base_path().'/', '', $f);
        }
    }
    expect($offenders)->toBe([]);
});
