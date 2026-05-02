<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

final class AdminDashboardController extends Controller
{
    public function index(): View
    {
        $stats = [
            'total_users' => DB::table('users')->count(),
            'active_distributors' => DB::table('distributors')
                ->join('users', 'distributors.user_id', '=', 'users.id')
                ->where('users.status', 'active')->count(),
            'pending_users' => DB::table('users')->where('status', 'pending')->count(),
            'cooling_off_active' => DB::table('distributors')
                ->where('cooling_off_end_at', '>', now())->count(),
            'cooling_off_expiring' => DB::table('distributors')
                ->where('cooling_off_end_at', '>', now())
                ->where('cooling_off_end_at', '<=', now()->addDays(7))->count(),
            'frozen_users' => DB::table('users')->where('status', 'frozen')->count(),
            'audit_entries_today' => DB::table('audit_log')
                ->whereDate('created_at', today())->count(),
        ];

        $recentAudit = DB::table('audit_log')
            ->leftJoin('users', 'audit_log.actor_id', '=', 'users.id')
            ->select('audit_log.*', 'users.email as actor_email')
            ->orderByDesc('audit_log.created_at')
            ->limit(10)
            ->get();

        $recentDistributors = DB::table('distributors')
            ->join('users', 'distributors.user_id', '=', 'users.id')
            ->select('distributors.id', 'distributors.adn', 'distributors.depth',
                'distributors.placement_side', 'distributors.effective_date',
                'distributors.cooling_off_end_at', 'users.email', 'users.full_name', 'users.status')
            ->orderByDesc('distributors.id')
            ->limit(8)
            ->get();

        return view('admin.dashboard', compact('stats', 'recentAudit', 'recentDistributors'));
    }
}
