<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers;

use App\Modules\Compliance\Models\AuditLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

final class AdminSettingsController extends Controller
{
    public function index(): View
    {
        $settings = DB::table('settings')->orderBy('key')->get()->keyBy('key');

        return view('admin.settings.index', compact('settings'));
    }

    public function updateStateAgeMinimums(Request $request): RedirectResponse
    {
        // The form posts a JSON string in 'state_age_minimums' (textarea).
        // We parse + revalidate every value as a sane integer (16..30) so a
        // typo can't accidentally allow children or block the entire country.
        $validated = $request->validate([
            'state_age_minimums' => ['required', 'string', 'max:2048'],
        ]);

        $decoded = json_decode($validated['state_age_minimums'], true);
        if (! is_array($decoded)) {
            return back()->withInput()->withErrors([
                'state_age_minimums' => 'Must be a valid JSON object mapping state codes to ages.',
            ]);
        }

        foreach ($decoded as $stateCode => $age) {
            if (! is_string($stateCode) || ! preg_match('/^[A-Z]{2}$/', $stateCode)) {
                return back()->withInput()->withErrors([
                    'state_age_minimums' => "Invalid state code: '{$stateCode}'. Use two-letter uppercase codes (e.g. MH).",
                ]);
            }
            if (! is_int($age) || $age < 16 || $age > 30) {
                return back()->withInput()->withErrors([
                    'state_age_minimums' => "Minimum age for {$stateCode} must be an integer between 16 and 30 (got: ".json_encode($age).').',
                ]);
            }
        }

        // Canonical key order so audit-log diffs and downstream string
        // comparisons don't see false changes when admins re-save the same
        // logical map.
        ksort($decoded);
        $canonical = json_encode($decoded, JSON_UNESCAPED_UNICODE);
        $old = DB::table('settings')->where('key', 'compliance.state_age_minimums')->value('value');

        DB::table('settings')->upsert([[
            'key' => 'compliance.state_age_minimums',
            'value' => $canonical,
            'version' => DB::raw('version + 1'),
            'updated_by' => auth()->id(),
            'updated_at' => now(),
        ]], ['key'], ['value', 'version', 'updated_by', 'updated_at']);

        AuditLog::create([
            'actor_id' => auth()->id(),
            'action' => 'admin.settings.state_age_minimums.changed',
            'subject_type' => 'settings',
            'subject_id' => null,
            'details' => ['before' => $old, 'after' => $canonical],
            'ip' => $request->ip(),
        ]);

        return redirect()->route('admin.settings')
            ->with('status', 'State-age minimums updated. New registrations are validated against the new rule.');
    }
}
