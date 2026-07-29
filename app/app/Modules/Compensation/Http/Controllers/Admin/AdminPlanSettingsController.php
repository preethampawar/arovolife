<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Http\Controllers\Admin;

use App\Modules\Compensation\Events\CompensationPlanChanged;
use App\Modules\Compensation\Services\GsbDailyPoolService;
use App\Modules\Compliance\Models\AuditLog;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

/**
 * Admin editor for the tabular compensation-plan ladders (GSB slabs, rank
 * tiers, Fortune levels, Fortune tiers). Scalar rates/caps are edited on the
 * existing /admin/settings page (the `compensation_plan` group).
 *
 * Every change writes an AuditLog row and dispatches CompensationPlanChanged so
 * the rest of the system can react. Changes take effect on the next engine run
 * (the settings service caches per-request).
 */
final class AdminPlanSettingsController extends Controller
{
    public function index(): View
    {
        return view('admin.compensation.plan-settings.index', [
            'slabs' => DB::table('gsb_slabs')->orderBy('slab')->get(),
            'rankTiers' => DB::table('rank_tiers')->orderBy('rank_number')->get(),
            'fortuneLevels' => DB::table('fortune_bonus_levels')->orderBy('level')->get(),
            'fortuneTiers' => DB::table('fortune_bonus_tiers')->orderBy('sort_order')->get(),
        ]);
    }

    public function updateGsbSlab(Request $request, int $slab): RedirectResponse
    {
        $row = DB::table('gsb_slabs')->where('slab', $slab)->first();
        abort_unless($row !== null, 404);

        // Slabs 3–7 are priced daily from the 45% pool (KP 2026-07-29): their
        // score and score value are not admin-editable. Enforced server-side —
        // a crafted POST must not change them — by dropping the fields from
        // validation and reusing the stored values below.
        $variableSlab = GsbDailyPoolService::isVariableSlab($slab);

        $rules = [
            'title' => ['nullable', 'string', 'max:100'],
            'title_min_bv_paise' => ['required', 'integer', 'min:0', 'max:1000000000000'],
            'matched_bv_paise' => ['required', 'integer', 'min:0', 'max:1000000000000'],
            'msb_score' => ['required', 'integer', 'min:0', 'max:1000000'],
            'msb_score_value_paise' => ['required', 'integer', 'min:1', 'max:1000000000'],
            'agp_per_occurrence' => ['required', 'integer', 'min:0', 'max:100000'],
            'carry_forward_lifetime' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ];
        if (! $variableSlab) {
            $rules['score'] = ['nullable', 'integer', 'min:0', 'max:1000000'];
            $rules['score_value_paise'] = ['required', 'integer', 'min:1', 'max:1000000000'];
        }

        $data = $request->validate($rules);

        // Bonus follows the score × per-slab score-value model (KP 2026-07-21).
        // Null score ⇒ null bonus, which disables a slab's matching payout
        // (kept for any future TBD slab). For variable slabs bonus_paise stays
        // a nominal ceiling (score × fixed value) — the engine's slab loop
        // requires it non-null, and price() overrides the actual gross daily.
        if ($variableSlab) {
            $score = $row->score !== null ? (int) $row->score : null;
            $scoreValuePaise = (int) $row->score_value_paise;
        } else {
            $score = $data['score'] !== null ? (int) $data['score'] : null;
            $scoreValuePaise = (int) $data['score_value_paise'];
        }
        $bonusPaise = $score !== null ? $score * $scoreValuePaise : null;

        $new = [
            'title' => $data['title'] !== '' ? $data['title'] : null,
            'title_min_bv_paise' => (int) $data['title_min_bv_paise'],
            'matched_bv_paise' => (int) $data['matched_bv_paise'],
            'score' => $score,
            'score_value_paise' => $scoreValuePaise,
            // MSB income (msb_score × msb_score_value_paise) is computed at
            // credit time from these two fields — never stored on the slab.
            'msb_score' => (int) $data['msb_score'],
            'msb_score_value_paise' => (int) $data['msb_score_value_paise'],
            'bonus_paise' => $bonusPaise,
            'agp_per_occurrence' => (int) $data['agp_per_occurrence'],
            'carry_forward_lifetime' => $request->boolean('carry_forward_lifetime'),
            'is_active' => $request->boolean('is_active'),
        ];

        return $this->persistRow('gsb_slabs', 'slab', $slab, $new, 'gsb_slab', (string) $slab, $request);
    }

    public function updateRankTier(Request $request, int $rank): RedirectResponse
    {
        abort_unless(DB::table('rank_tiers')->where('rank_number', $rank)->exists(), 404);

        $data = $request->validate([
            'rank_name' => ['required', 'string', 'max:100'],
            'pool_pct' => ['required', 'numeric', 'min:0', 'max:100'],
            'pyp_required' => ['required', 'integer', 'min:0', 'max:50'],
            'personal_bv_required_paise' => ['required', 'integer', 'min:0', 'max:1000000000000'],
            'group_bv_required_paise' => ['nullable', 'integer', 'min:0', 'max:1000000000000'],
            'weaker_leg_topup_bv_paise' => ['required', 'integer', 'min:0', 'max:1000000000000'],
            'structural_qualifiers_per_side' => ['nullable', 'integer', 'min:0', 'max:50'],
            'carry_forward_months' => ['required', 'integer', 'min:0', 'max:24'],
            'repurchase_bv_paise' => ['required', 'integer', 'min:0', 'max:1000000000'],
            'lifetime_award_budget_paise' => ['required', 'integer', 'min:0', 'max:100000000000'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $new = [
            'rank_name' => $data['rank_name'],
            'pool_pct' => (float) $data['pool_pct'],
            'pyp_required' => (int) $data['pyp_required'],
            'personal_bv_required_paise' => (int) $data['personal_bv_required_paise'],
            'group_bv_required_paise' => $data['group_bv_required_paise'] !== null ? (int) $data['group_bv_required_paise'] : null,
            'weaker_leg_topup_bv_paise' => (int) $data['weaker_leg_topup_bv_paise'],
            'structural_qualifiers_per_side' => $data['structural_qualifiers_per_side'] !== null ? (int) $data['structural_qualifiers_per_side'] : null,
            'carry_forward_months' => (int) $data['carry_forward_months'],
            'repurchase_bv_paise' => (int) $data['repurchase_bv_paise'],
            'lifetime_award_budget_paise' => (int) $data['lifetime_award_budget_paise'],
            'is_active' => $request->boolean('is_active'),
        ];

        return $this->persistRow('rank_tiers', 'rank_number', $rank, $new, 'rank_tier', (string) $rank, $request);
    }

    public function updateFortuneLevel(Request $request, int $level): RedirectResponse
    {
        abort_unless(DB::table('fortune_bonus_levels')->where('level', $level)->exists(), 404);

        $data = $request->validate([
            'bonus_paise' => ['required', 'integer', 'min:0', 'max:1000000000'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $new = [
            'bonus_paise' => (int) $data['bonus_paise'],
            'is_active' => $request->boolean('is_active'),
        ];

        return $this->persistRow('fortune_bonus_levels', 'level', $level, $new, 'fortune_level', (string) $level, $request);
    }

    public function updateFortuneTier(Request $request, string $tier): RedirectResponse
    {
        abort_unless(DB::table('fortune_bonus_tiers')->where('tier', $tier)->exists(), 404);

        $data = $request->validate([
            'bv_required_paise' => ['required', 'integer', 'min:0', 'max:1000000000000'],
            'slabs_required' => ['required', 'integer', 'min:0', 'max:50'],
        ]);

        $new = [
            'bv_required_paise' => (int) $data['bv_required_paise'],
            'slabs_required' => (int) $data['slabs_required'],
        ];

        return $this->persistRow('fortune_bonus_tiers', 'tier', $tier, $new, 'fortune_tier', $tier, $request);
    }

    /**
     * Update one config row, write a before/after audit log, and dispatch the
     * domain event. Centralised so all four editors behave identically.
     *
     * @param  array<string, mixed>  $new
     */
    private function persistRow(
        string $table,
        string $keyColumn,
        int|string $keyValue,
        array $new,
        string $area,
        string $eventKey,
        Request $request,
    ): RedirectResponse {
        $before = (array) DB::table($table)->where($keyColumn, $keyValue)->first();

        DB::table($table)->where($keyColumn, $keyValue)->update([
            ...$new,
            'updated_at' => now(),
        ]);

        AuditLog::create([
            'actor_id' => auth()->id(),
            'action' => 'compensation.plan.'.$area.'.updated',
            'subject_type' => $table,
            'subject_id' => null,
            'details' => [
                'key' => $eventKey,
                'before' => $before,
                'after' => $new,
            ],
            'ip' => $request->ip(),
        ]);

        event(new CompensationPlanChanged(area: $area, key: $eventKey, actorId: auth()->id()));

        return redirect()
            ->route('admin.compensation.plan-settings.index')
            ->with('status', 'Compensation plan updated. Takes effect on the next engine run.')
            ->with('saved_key', $area.':'.$eventKey);
    }
}
