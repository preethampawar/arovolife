<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Http\Controllers\Admin;

use App\Modules\Compensation\Models\GsbCutoffResult;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;

final class AdminDailyCutoffController extends Controller
{
    private const PER_PAGE = 50;

    public function index(Request $request): View
    {
        $request->validate([
            'date' => ['nullable', 'date'],
            'status' => ['nullable', 'in:credited,reversed,failed,no_match,frozen,below_600bv,calculated'],
            'q' => ['nullable', 'string', 'max:64'],
        ]);

        $date = $request->query('date') ? Carbon::parse((string) $request->query('date')) : Carbon::today();
        $status = $request->query('status');
        $q = $request->query('q');

        $query = GsbCutoffResult::with('distributor.user')
            ->where('cutoff_date', $date->toDateString())
            ->when($status, fn ($b) => $b->where('status', $status))
            ->when($q, fn ($b) => $b->whereHas('distributor', fn ($d) => $d->where('adn', 'like', "%{$q}%")))
            ->orderByRaw("FIELD(status, 'failed', 'credited', 'no_match', 'below_600bv', 'frozen', 'calculated')");

        return view('admin.compensation.daily-cutoffs.index', [
            'rows' => $query->paginate(self::PER_PAGE)->withQueryString(),
            'date' => $date,
            'status' => $status,
            'q' => $q,
        ]);
    }

    public function show(string $date): View
    {
        $parsed = Carbon::parse($date);

        $rows = GsbCutoffResult::with('distributor.user')
            ->where('cutoff_date', $parsed->toDateString())
            ->orderByRaw("FIELD(status, 'failed', 'credited', 'no_match', 'below_600bv', 'frozen', 'calculated')")
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('admin.compensation.daily-cutoffs.show', compact('rows', 'parsed'));
    }

    public function export(Request $request): Response
    {
        $request->validate([
            'date' => ['nullable', 'date'],
            'status' => ['nullable', 'in:credited,reversed,failed,no_match,frozen,below_600bv,calculated'],
        ]);
        $date = $request->query('date') ? Carbon::parse((string) $request->query('date')) : Carbon::today();
        $status = $request->query('status');

        $rows = GsbCutoffResult::with('distributor.user')
            ->where('cutoff_date', $date->toDateString())
            ->when($status, fn ($b) => $b->where('status', $status))
            ->get();

        $csv = "ADN,Name,Left BV,Right BV,Slab,Gross GSB (Rs),Admin Charge (Rs),TDS (Rs),Net GSB (Rs),Status\n";
        foreach ($rows as $r) {
            $csv .= '"'.($r->distributor->adn ?? '').'",'
                .'"'.($r->distributor->user?->full_name ?? '').'",'
                .(int) ($r->left_bv_paise / 100).','
                .(int) ($r->right_bv_paise / 100).','
                .'"'.($r->slab ?? '').'",'
                .number_format($r->gross_gsb_paise / 100, 2).','
                .number_format($r->admin_charge_paise / 100, 2).','
                .number_format($r->tds_paise / 100, 2).','
                .number_format($r->net_gsb_paise / 100, 2).','
                .'"'.$r->status.'"'."\n";
        }

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="gsb-cutoff-'.$date->toDateString().'.csv"',
        ]);
    }
}
