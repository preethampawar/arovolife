<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Http\Controllers\Admin;

use Illuminate\Http\Response;

final class CompensationOverviewController
{
    public function __invoke(): Response
    {
        return response('stub');
    }
}
