<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Http\Controllers\Admin;

use Illuminate\Http\Response;

final class AdminManualControlsController
{
    public function index(): Response
    {
        return response('stub');
    }

    public function retryCutoff(): Response
    {
        return response('stub');
    }

    public function recalcCarryForward(): Response
    {
        return response('stub');
    }

    public function manualCredit(): Response
    {
        return response('stub');
    }

    public function reverseCredit(): Response
    {
        return response('stub');
    }

    public function forcePayout(): Response
    {
        return response('stub');
    }

    public function freezeGsb(): Response
    {
        return response('stub');
    }
}
