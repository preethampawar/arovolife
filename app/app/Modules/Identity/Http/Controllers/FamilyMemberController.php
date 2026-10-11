<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Models\DistributorFamilyMember;
use App\Modules\Identity\Services\FamilyMemberService;
use App\Modules\Shared\Features\FamilyCardFeature;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Laravel\Pennant\Feature;

/**
 * My Profile → Family members: the distributor registers up to five family
 * members for the arovolife Family Card, each with a consent declaration and,
 * for a child, a parent/guardian consent (DPDP Act 2023 §6, §9).
 */
final class FamilyMemberController extends Controller
{
    public function store(Request $request, FamilyMemberService $service): RedirectResponse
    {
        abort_unless(Feature::for(null)->active(FamilyCardFeature::class), 404);

        $user = Auth::user();
        $distributor = $user?->distributor;
        abort_unless($user !== null && $distributor !== null, 403);

        $year = (int) Carbon::now('Asia/Kolkata')->year;

        $validated = $request->validate([
            'full_name' => ['required', 'string', 'min:2', 'max:120', "regex:/^[\\pL\\s.'\\-]+$/u"],
            'relation' => ['required', Rule::in(array_keys(DistributorFamilyMember::RELATIONS))],
            'is_minor' => ['required', Rule::in(['0', '1'])],
            'turns_18_year' => ['exclude_unless:is_minor,1', 'required', 'integer', 'min:'.$year, 'max:'.($year + 18)],
            'consent' => ['accepted'],
            'guardian_consent' => ['exclude_unless:is_minor,1', 'accepted'],
        ], [
            'full_name.required' => 'Please enter the family member\'s full name.',
            'full_name.min' => 'Please enter the family member\'s full name.',
            'full_name.regex' => 'The name can contain only letters, spaces, full stops, apostrophes and hyphens.',
            'relation.required' => 'Please choose how this person is related to you.',
            'relation.in' => 'Please choose how this person is related to you.',
            'is_minor.required' => 'Please tell us whether this family member is under 18.',
            'is_minor.in' => 'Please tell us whether this family member is under 18.',
            'turns_18_year.required' => 'Please enter the year this child turns 18.',
            'turns_18_year.integer' => 'Please enter the year this child turns 18.',
            'turns_18_year.min' => 'A child under 18 turns 18 this year or later.',
            'turns_18_year.max' => 'Please check the year this child turns 18.',
            'consent.accepted' => 'Please confirm the family member has agreed before adding them.',
            'guardian_consent.accepted' => 'Please give your consent as the child\'s parent or guardian before adding them.',
        ]);

        $isMinor = $validated['is_minor'] === '1';

        $service->add($distributor, $user, [
            'full_name' => trim((string) preg_replace('/\s+/', ' ', $validated['full_name'])),
            'relation' => $validated['relation'],
            'is_minor' => $isMinor,
            'turns_18_year' => $isMinor ? (int) $validated['turns_18_year'] : null,
        ], $request->ip());

        return redirect()->to(route('profile.show').'#family-members')
            ->with('status', 'Family member added.');
    }

    /**
     * Deliberately NOT flag-gated: a distributor who already has family
     * members registered must always be able to remove them (Privacy Policy
     * §10.3), even while the Family Card is switched off.
     */
    public function destroy(Request $request, int $member, FamilyMemberService $service): RedirectResponse
    {
        $user = Auth::user();
        $distributor = $user?->distributor;
        abort_unless($user !== null && $distributor !== null, 403);

        $service->remove($distributor, $member, $user, $request->ip());

        return redirect()->to(route('profile.show').'#family-members')
            ->with('status', 'Family member removed.');
    }
}
