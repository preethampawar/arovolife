<?php
declare(strict_types=1);

it('step 10 carries an upload-progress status region and a submit guard', function () {
    $blade = file_get_contents(resource_path('views/registration/step7-documents.blade.php'));
    expect($blade)->toContain('data-upload-progress')
        ->toContain('role="status"')
        ->toContain('Uploading your documents. Please keep this page open.')
        ->toContain('data-upload-form');
});
