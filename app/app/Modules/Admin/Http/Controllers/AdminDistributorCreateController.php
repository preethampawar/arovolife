<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers;

use App\Modules\Admin\Services\AdminCreateDistributorAction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Admin-driven distributor creation — the "prospect handed me paper
 * documents, I'll set them up myself" flow.
 *
 * The controller is a thin shell over {@see AdminCreateDistributorAction};
 * all sponsor/placement resolution, PAN dedup, atomic placement and audit
 * logging lives in the action so it remains testable in isolation.
 *
 * Compliance posture (see also the action's docblock):
 *  - Admin attests orientation + consent on the prospect's behalf.
 *  - The created user has password_set_at = NULL and receives a signed
 *    magic link (spouse-activation route) to set their own password —
 *    they cannot log in until they click that link.
 *  - The audit row is tagged `admin_attested_orientation` /
 *    `admin_attested_consent` so compliance can periodically sample
 *    these registrations.
 */
final class AdminDistributorCreateController extends Controller
{
    public function __construct(
        private readonly AdminCreateDistributorAction $action,
    ) {}

    public function create(): View
    {
        return view('admin.distributors.create', [
            'states' => AdminDistributorEditController::indianStates(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        // Normalise inputs BEFORE validation so the regex rules + unique
        // checks apply to canonical forms. Phone goes from 10-digit form
        // input to E.164 (+91XXXXXXXXXX) so the unique-lookup matches the
        // DB's stored format; email is lowercased so "Ravi@x.com" matches
        // an existing "ravi@x.com".
        $rawPhone = preg_replace('/\D+/', '', (string) $request->input('phone_e164', '')) ?? '';
        $normalisedPhone = $rawPhone !== '' ? '+91'.ltrim($rawPhone, '0') : '';
        $request->merge([
            'sponsor_adn' => strtoupper(trim((string) $request->input('sponsor_adn', ''))),
            'placement_adn' => strtoupper(trim((string) $request->input('placement_adn', ''))),
            'pan_number' => strtoupper(trim((string) $request->input('pan_number', ''))),
            'aadhaar_number' => preg_replace('/\D+/', '', (string) $request->input('aadhaar_number', '')) ?? '',
            'bank_ifsc' => strtoupper(trim((string) $request->input('bank_ifsc', ''))),
            'side' => strtoupper(trim((string) $request->input('side', ''))) ?: null,
            'email' => strtolower(trim((string) $request->input('email', ''))),
            'phone_e164' => $normalisedPhone,
        ]);

        $validated = $request->validate([
            'sponsor_adn' => ['required', 'string', 'regex:/^[0-9]{9}(-S)?$/'],
            'placement_adn' => ['required', 'string', 'regex:/^[0-9]{9}(-S)?$/'],
            'side' => ['nullable', 'in:L,R'],

            'full_name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:191', 'unique:users,email'],
            // Validate the normalised E.164 form so the unique check
            // actually catches duplicates against the stored values.
            'phone_e164' => ['required', 'regex:/^\+91[6-9]\d{9}$/', 'unique:users,phone_e164'],
            'date_of_birth' => ['required', 'date', 'before:-18 years'],

            'pan_number' => ['required', 'regex:/^[A-Z]{5}[0-9]{4}[A-Z]$/'],
            // Phase 1 — admin keys in the 12-digit Aadhaar from the
            // paper form. The number is encrypted at rest and last-4 is
            // surfaced for audit only.
            'aadhaar_number' => ['required', 'digits:12'],

            // Bank is optional — admin can record offline-docs distributors
            // who haven't shared bank details yet. required_with enforces
            // all-or-nothing: if either field is filled, both must validate.
            'bank_account' => ['nullable', 'string', 'min:9', 'max:18', 'regex:/^\d+$/', 'required_with:bank_ifsc'],
            'bank_ifsc' => ['nullable', 'regex:/^[A-Z]{4}0[A-Z0-9]{6}$/', 'required_with:bank_account'],

            'state' => ['required', 'in:'.implode(',', array_keys(AdminDistributorEditController::indianStates()))],
        ], [
            'sponsor_adn.regex' => 'Sponsor ADN must be 9 digits (optionally followed by -S).',
            'placement_adn.regex' => 'Placement ADN must be 9 digits (optionally followed by -S).',
            'side.in' => 'Side must be L or R.',
            'phone_e164.regex' => 'Enter a 10-digit Indian mobile number (must start with 6, 7, 8, or 9).',
            'phone_e164.unique' => 'A user with this phone number already exists.',
            'email.unique' => 'A user with this email already exists.',
            'date_of_birth.before' => 'Distributor must be at least 18 years old.',
            'pan_number.regex' => 'PAN must be exactly 10 characters: 5 letters, 4 digits, then 1 letter.',
            'aadhaar_number.digits' => 'Aadhaar must be exactly 12 digits.',
            'bank_account.regex' => 'Bank account number must contain digits only.',
            'bank_ifsc.regex' => 'IFSC must be 11 characters: 4 letters, 0, then 6 alphanumeric.',
            'state.in' => 'Please pick a valid Indian state.',
        ]);

        // Stamp the admin's IP so the audit log + consent rows carry the
        // canonical actor address. (The action takes this from $input so
        // it stays testable without needing a Request.)
        $validated['admin_ip'] = $request->ip();

        $adminId = (int) Auth::id();
        $result = $this->action->execute($validated, $adminId);

        return redirect()
            ->route('admin.distributors.show', $result->distributorId)
            ->with('status', 'Distributor created. An activation link has been emailed to '.$validated['email'].'.');
    }
}
