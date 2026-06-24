<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Http\Controllers\Admin;

use Illuminate\Http\Response;

final class AdminDailyCutoffController
{
    public function index(): Response
    {
        return response('stub');
    }

    public function export(): Response
    {
        return response('stub');
    }

    public function show(string $date): Response
    {
        return response('stub');
    }
}
