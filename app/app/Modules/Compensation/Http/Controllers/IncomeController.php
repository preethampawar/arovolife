<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

final class IncomeController extends Controller
{
    public function dashboard(Request $request): View
    {
        $distributor = $request->user()?->distributor;
        abort_unless($distributor !== null, 403);

        return view('income.dashboard', ['distributor' => $distributor]);
    }

    public function genosBv(Request $request): View
    {
        $distributor = $request->user()?->distributor;
        abort_unless($distributor !== null, 403);

        return view('income.genos-bv', ['distributor' => $distributor, 'rows' => collect()]);
    }

    public function gsbHistory(Request $request): View
    {
        $distributor = $request->user()?->distributor;
        abort_unless($distributor !== null, 403);

        return view('income.gsb-history', ['distributor' => $distributor, 'rows' => collect()]);
    }

    public function exportGsb(Request $request): Response
    {
        $distributor = $request->user()?->distributor;
        abort_unless($distributor !== null, 403);

        return response('', 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="gsb-history.csv"',
        ]);
    }

    public function mentorship(Request $request): View
    {
        $distributor = $request->user()?->distributor;
        abort_unless($distributor !== null, 403);

        return view('income.mentorship', ['distributor' => $distributor, 'rows' => collect()]);
    }

    public function wallet(Request $request): View
    {
        $distributor = $request->user()?->distributor;
        abort_unless($distributor !== null, 403);

        return view('income.wallet', ['distributor' => $distributor, 'ledgerRows' => collect(), 'payoutRows' => collect()]);
    }

    public function exportWallet(Request $request): Response
    {
        $distributor = $request->user()?->distributor;
        abort_unless($distributor !== null, 403);

        return response('', 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="wallet-ledger.csv"',
        ]);
    }
}
