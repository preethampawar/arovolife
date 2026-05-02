<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

final class AdminAuditLogController extends Controller
{
    public function index(Request $request): View
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

        $actionGroups = DB::table('audit_log')
            ->selectRaw("SUBSTRING_INDEX(action, '.', 2) as grp, count(*) as cnt")
            ->groupByRaw("SUBSTRING_INDEX(action, '.', 2)")
            ->orderByDesc('cnt')
            ->limit(10)
            ->pluck('cnt', 'grp');

        return view('admin.audit.index', compact('logs', 'actionGroups'));
    }
}
