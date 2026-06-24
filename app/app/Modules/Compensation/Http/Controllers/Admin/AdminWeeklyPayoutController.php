<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Http\Controllers\Admin;

use Illuminate\Http\Response;

final class AdminWeeklyPayoutController
{
    public function index(): Response
    {
        return response('stub');
    }

    public function show(int $batch): Response
    {
        return response('stub');
    }
}
