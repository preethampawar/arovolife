<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers;

use App\Modules\Compliance\Services\AuditLogPresenter;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

final class AdminAuditLogController extends Controller
{
    public function index(Request $request, AuditLogPresenter $presenter): View
    {
        $query = DB::table('audit_log')
            ->leftJoin('users', 'audit_log.actor_id', '=', 'users.id')
            ->select('audit_log.*', 'users.email as actor_email');

        if ($action = $request->query('action')) {
            $query->where('audit_log.action', 'like', "%{$action}%");
        }

        if ($subject = $request->query('subject_type')) {
            $query->where('audit_log.subject_type', $subject);
        }

        if ($from = $request->query('from')) {
            $query->whereDate('audit_log.created_at', '>=', $from);
        }

        if ($to = $request->query('to')) {
            $query->whereDate('audit_log.created_at', '<=', $to);
        }

        $logs = $query->orderByDesc('audit_log.created_at')->paginate(50)->withQueryString();

        // Pre-warm name/ADN lookups for every referenced distributor + user
        // in one batch (one SELECT each), then attach the rendered
        // {title, subtitle} pair to each paginator row so the Blade stays
        // dumb. Same pattern as the dashboard's Recent Audit Events panel.
        $presenter->warmCaches($logs->items());
        foreach ($logs->items() as $row) {
            $rendered = $presenter->present($row);
            $row->display_title = $rendered['title'];
            $row->display_subtitle = $rendered['subtitle'];
        }

        // SUBSTRING_INDEX is MySQL-specific. Tests run on SQLite :memory:
        // and won't hit this page; production runs MySQL on RDS. Guarding
        // anyway so a future SQLite admin smoke test doesn't blow up.
        if (DB::getDriverName() === 'mysql') {
            $actionGroups = DB::table('audit_log')
                ->selectRaw("SUBSTRING_INDEX(action, '.', 2) as grp, count(*) as cnt")
                ->groupByRaw("SUBSTRING_INDEX(action, '.', 2)")
                ->orderByDesc('cnt')
                ->limit(10)
                ->pluck('cnt', 'grp');
        } else {
            $actionGroups = collect();
        }

        return view('admin.audit.index', compact('logs', 'actionGroups'));
    }
}
