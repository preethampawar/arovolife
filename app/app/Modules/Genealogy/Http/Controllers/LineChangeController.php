<?php

declare(strict_types=1);

namespace App\Modules\Genealogy\Http\Controllers;

use App\Modules\Genealogy\Models\LineChangeRequest;
use App\Modules\Genealogy\Services\Exceptions\LineChangeAlreadyProcessedError;
use App\Modules\Genealogy\Services\Exceptions\LineChangeAlreadyRequestedError;
use App\Modules\Genealogy\Services\Exceptions\LineChangeHasDownlineError;
use App\Modules\Genealogy\Services\Exceptions\LineChangeNewParentTooNewError;
use App\Modules\Genealogy\Services\Exceptions\LineChangePlacementSlotFullError;
use App\Modules\Genealogy\Services\Exceptions\LineChangeWindowExpiredError;
use App\Modules\Genealogy\Services\RequestLineChange;
use App\Modules\Identity\Models\Distributor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

final class LineChangeController extends Controller
{
    public function __construct(
        private readonly RequestLineChange $request,
    ) {}

    public function show(): View|RedirectResponse
    {
        $self = Auth::user()?->distributor;
        if ($self === null) {
            return redirect()->route('dashboard');
        }

        $businessDaysSince = (int) $self->effective_date->diffInWeekdays(now());
        $existing = LineChangeRequest::query()
            ->where('distributor_id', $self->id)
            ->latest('requested_at')
            ->first();

        // One change per distributor, ever.
        $alreadyUsed = LineChangeRequest::query()
            ->where('distributor_id', $self->id)
            ->where('status', 'approved')
            ->exists();

        return view('genealogy.line-change', [
            'self' => $self,
            'businessDaysSince' => $businessDaysSince,
            'isWithinWindow' => $businessDaysSince <= 5,
            'existing' => $existing,
            'alreadyUsed' => $alreadyUsed,
        ]);
    }

    public function submit(Request $request): RedirectResponse
    {
        $request->merge([
            'to_parent_adn' => strtoupper(trim((string) $request->input('to_parent_adn', ''))),
        ]);
        $validated = $request->validate([
            'to_parent_adn' => ['required', 'string', 'regex:/^[0-9]{9}$/'],
            'reason' => ['nullable', 'string', 'max:512'],
        ], [
            'to_parent_adn.regex' => 'Placement parent ADN must be exactly 9 digits, e.g. 111222333.',
        ]);

        $self = Auth::user()?->distributor;
        if ($self === null) {
            return redirect()->route('dashboard');
        }

        $newParent = Distributor::query()
            ->where('adn', $validated['to_parent_adn'])
            ->first();
        if ($newParent === null) {
            return back()->withInput()->withErrors([
                'to_parent_adn' => 'No distributor found with that ADN.',
            ]);
        }

        if ($newParent->id === $self->id) {
            return back()->withInput()->withErrors([
                'to_parent_adn' => 'You cannot request a line-change to yourself.',
            ]);
        }

        if ($newParent->spouse_distributor_id !== null && ! $newParent->is_primary_couple) {
            return back()->withInput()->withErrors([
                'to_parent_adn' => 'That ADN belongs to a couple-secondary record. Use the primary spouse\'s ADN instead.',
            ]);
        }

        try {
            ($this->request)(
                distributorId: $self->id,
                toPlacementParentId: $newParent->id,
                actorUserId: (int) Auth::id(),
                reason: $validated['reason'] ?? null,
            );
        } catch (LineChangeWindowExpiredError) {
            return back()->withErrors(['line_change' => 'The 5-business-day window has ended.']);
        } catch (LineChangeHasDownlineError) {
            return back()->withErrors(['line_change' => 'You already have referrals in your tree; line-change is not available.']);
        } catch (LineChangeAlreadyRequestedError) {
            return back()->withErrors(['line_change' => 'A line-change request is already pending for your account.']);
        } catch (LineChangeAlreadyProcessedError) {
            return back()->withErrors(['line_change' => 'You have already used your one line change; a further change is not allowed.']);
        } catch (LineChangePlacementSlotFullError) {
            return back()->withInput()->withErrors(['to_parent_adn' => 'That placement parent has no free position (both legs are taken). Choose another ADN.']);
        } catch (LineChangeNewParentTooNewError) {
            return back()->withInput()->withErrors(['to_parent_adn' => 'You can only move under someone who registered before you. Please pick an ADN that registered earlier than your own registration date.']);
        }

        return redirect()->route('line-change.show')->with(
            'status',
            'Your line-change request has been submitted for review.',
        );
    }
}
