<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Http\Controllers\Admin;

use Illuminate\Http\Response;

final class AdminDistributorCompController
{
    public function show(int $distributor): Response
    {
        return response('stub');
    }
}
