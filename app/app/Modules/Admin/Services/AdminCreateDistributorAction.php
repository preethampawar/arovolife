<?php

declare(strict_types=1);

namespace App\Modules\Admin\Services;

use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Genealogy\Services\DTOs\PlaceDistributorInput;
use App\Modules\Genealogy\Services\DTOs\PlacementResult;
use App\Modules\Genealogy\Services\Exceptions\CrossLinePlacementError;
use App\Modules\Genealogy\Services\PlacementEngine;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Notifications\SpouseActivationNotification;
use App\Modules\Identity\Services\RegistrationService;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Admin-driven distributor creation — the "prospect handed me paper
 * documents, I'll set them up myself" flow.
 *
 * Differences from {@see RegistrationService}:
 *
 *  - The admin attests orientation + consent on the prospect's behalf
 *    (`admin_attested_orientation` / `admin_attested_consent` flags in
 *    the audit log). This is necessary for paper onboarding but compliance
 *    will review the queue periodically — see risk-register.md entry
 *    "Admin-attested registrations". Per DSR Rule 5(1)(b) the written
 *    agreement is the prospect's signature on the paper consent form,
 *    which the admin uploads to KYC; the digital `consents` row points at
 *    that paper.
 *
 *  - The user is created with `password_set_at = NULL` so they CANNOT log
 *    in until they set a password. We dispatch the spouse-activation
 *    signed-URL flow (after-commit) so the prospect can choose their own
 *    password via a magic link emailed to them. The reset-link flow is
 *    NOT suitable here because RequestPasswordReset short-circuits when
 *    password_set_at IS NULL.
 *
 *  - Couple-secondary registration is NOT supported in this action — the
 *    admin form is single-applicant by design (couples need both adults
 *    to sign agreements; that flow stays in the wizard).
 *
 *  - Cross-line guard runs via PlacementEngine; the admin form bubbles
 *    CrossLinePlacementError as a validation error on the placement_adn
 *    field so the admin gets actionable feedback inline.
 */
final class AdminCreateDistributorAction
{
    private const DOCUMENT_VERSIONS = [
        'tnc' => '1.0.0',
        'ethics' => '1.0.0',
        'plan' => '1.0.0',
        'privacy' => '1.0.0',
    ];

    // Mirrors RegistrationService::DOCUMENT_HASHES — same versioned doc
    // stubs that registration uses, kept in lock-step.
    private const DOCUMENT_HASHES = [
        'tnc' => 'ac458cd6b8f804d09ff7c4e3c15175911b506609684503b882e8df6ba73de0dc',
        'ethics' => '67e7afb1f667aee1d5cb2e3365dc2047b22f83bd696499c517dfeecb092d3417',
        'plan' => '8056e8532e9c864c44e71fc38fa53fbda662abe9d6882d3e4df6a16a457f8746',
        'privacy' => '1b88b7ee934f4262e47500055424ec4a6e137b0a90500b211290999e2113bc40',
    ];

    public function __construct(
        private readonly PlacementEngine $engine,
        private readonly DatabaseManager $db,
    ) {}

    /**
     * @param  array<string, mixed>  $input  validated form payload — see
     *                                       AdminDistributorCreateController::store()
     *                                       for the canonical shape.
     */
    public function execute(array $input, int $adminUserId): PlacementResult
    {
        // ── Resolve sponsor + placement by ADN ─────────────────────────
        // Validator already enforced ADN regex shape, so empty results
        // here mean "unknown ADN". Re-throw as validation errors so the
        // admin sees field-level feedback.
        $sponsorAdn = strtoupper((string) $input['sponsor_adn']);
        $placementAdn = strtoupper((string) $input['placement_adn']);

        $sponsor = $this->db->table('distributors')
            ->where('adn', $sponsorAdn)
            ->select('id', 'adn')
            ->first();
        if ($sponsor === null) {
            throw ValidationException::withMessages([
                'sponsor_adn' => 'No distributor found with that sponsor ADN.',
            ]);
        }

        $placement = $this->db->table('distributors')
            ->where('adn', $placementAdn)
            ->select('id', 'adn')
            ->first();
        if ($placement === null) {
            throw ValidationException::withMessages([
                'placement_adn' => 'No distributor found with that placement ADN.',
            ]);
        }

        // Cross-line pre-flight — PlacementEngine re-checks at place(),
        // but failing here yields a field-level validation error rather
        // than a half-built audit trail.
        if (! $this->engine->isSelfOrDescendant((int) $sponsor->id, (int) $placement->id)) {
            throw ValidationException::withMessages([
                'placement_adn' => 'Placement ADN must be in the sponsor\'s downline (or be the sponsor itself).',
            ]);
        }

        $panNumber = strtoupper(trim((string) $input['pan_number']));
        $aadhaarNumber = preg_replace('/\D+/', '', (string) $input['aadhaar_number']) ?? '';

        // Hard rule #6 — PAN dedup. The unique index on
        // distributors.pan_hash is the last-line defence and would surface
        // here as a UniqueConstraintViolationException; check first so the
        // error reaches the admin as a clean validation message on the
        // PAN field rather than a 500.
        $panHash = hash('sha256', $panNumber, true);
        if ($this->db->table('distributors')->where('pan_hash', $panHash)->exists()) {
            throw ValidationException::withMessages([
                'pan_number' => 'A Direct Seller account already exists for this PAN.',
            ]);
        }

        $bankAccount = (string) $input['bank_account'];
        $bankAccountEnc = Crypt::encryptString($bankAccount);

        // Phase 1 stub Aadhaar ref — same shape RegistrationService uses
        // until the UIDAI-approved AUA/KUA partner is wired in.
        $aadhaarRef = 'STUB_'.strtoupper(uniqid('REF', true));

        // ── User account ────────────────────────────────────────────────
        // password_set_at = NULL so LoginController blocks sign-in until
        // the prospect activates via the magic link below.
        $user = User::create([
            'email' => strtolower((string) $input['email']),
            'phone_e164' => $this->normalisePhone((string) $input['phone_e164']),
            'password_hash' => Hash::make(Str::random(32)),
            'password_set_at' => null,
            'full_name' => $input['full_name'] ?? null,
            'date_of_birth' => $input['date_of_birth'] ?? null,
            'status' => 'pending',
        ]);

        $placeInput = new PlaceDistributorInput(
            userId: $user->id,
            sponsorId: (int) $sponsor->id,
            placementId: (int) $placement->id,
            panHash: $panHash,
            panLast4: strtoupper(substr($panNumber, -4)),
            bankAccountEnc: $bankAccountEnc,
            bankIfsc: strtoupper((string) $input['bank_ifsc']),
            state: (string) $input['state'],
            sideOpt: ! empty($input['side']) ? $input['side'] : null,
            aadhaarRef: $aadhaarRef,
            aadhaarLast4: substr($aadhaarNumber, -4),
            isPrimaryCouple: false,
            panEncrypted: Crypt::encryptString($panNumber),
            aadhaarEncrypted: Crypt::encryptString($aadhaarNumber),
        );

        // ── Atomic write ───────────────────────────────────────────────
        // PlacementEngine::place() opens its own transaction; nesting is
        // safe under Laravel's savepoint handling. cooling_off_events +
        // orientation_views + consents must commit together with the
        // placement insert or roll back together; the closing audit log
        // must observe the same atomicity.
        try {
            $result = $this->db->connection()->transaction(function () use (
                $placeInput, $user, $adminUserId, $sponsorAdn, $placementAdn,
                $input, $panNumber, $aadhaarNumber
            ): PlacementResult {
                $result = $this->engine->place($placeInput);

                $now = now()->format('Y-m-d H:i:s.v');

                // Statutory 30-day cooling-off clock opens at registration.
                // PlacementEngine already stamped cooling_off_end_at on the
                // distributors row; this is the audit ledger row.
                $this->db->table('cooling_off_events')->insert([
                    'distributor_id' => $result->distributorId,
                    'opened_at' => $now,
                ]);

                // Admin-attested orientation. The prospect watched in
                // person at the admin's session; the playback_fingerprint
                // is "admin-attested" so a downstream auditor can filter
                // these out from the organic flow.
                $this->db->table('orientation_views')->insert([
                    'distributor_id' => $result->distributorId,
                    'video_id' => 'PHASE1_ORIENTATION_V1',
                    'started_at' => $now,
                    'completed_at' => $now,
                    'watch_percent' => 100,
                    'quiz_passed_at' => $now,
                    'playback_fingerprint' => 'admin-attested:'.$adminUserId,
                ]);

                // Admin-attested consent. IP is the admin's client IP at
                // submission time; user_agent labels the row as
                // admin-console-attested so the compliance reviewer can
                // tell it apart from a self-signed consent.
                $ip = (string) ($input['admin_ip'] ?? '0.0.0.0');
                $ua = 'admin-console-attested:'.$adminUserId;
                foreach (self::DOCUMENT_VERSIONS as $type => $version) {
                    $hash = hex2bin(self::DOCUMENT_HASHES[$type]);
                    $this->db->table('consents')->insert([
                        'distributor_id' => $result->distributorId,
                        'document_type' => $type,
                        'document_version' => $version,
                        'doc_hash_sha256' => $hash,
                        'accepted_at' => $now,
                        'ip' => $ip,
                        'user_agent' => substr($ua, 0, 512),
                    ]);
                }

                AuditLog::create([
                    'actor_id' => $adminUserId,
                    'action' => 'admin.distributor.created',
                    'subject_type' => 'distributor',
                    'subject_id' => $result->distributorId,
                    'details' => [
                        'user_id' => $user->id,
                        'sponsor_adn' => $sponsorAdn,
                        'placement_adn' => $placementAdn,
                        'side' => $result->side,
                        'side_chosen_by' => $result->sideChosenBy,
                        'depth' => $result->depth,
                        'state' => $input['state'] ?? null,
                        // Compliance-critical flags — see DSR 2021 review
                        // for admin-attested registrations. Both true
                        // means this row needs a sign-off on the
                        // periodic compliance report.
                        'admin_attested_orientation' => true,
                        'admin_attested_consent' => true,
                        // Snapshot of what the admin submitted (minus
                        // secrets) so the audit trail is self-contained.
                        'submission_snapshot' => [
                            'full_name' => $input['full_name'] ?? null,
                            'email' => $user->email,
                            'phone_e164' => $user->phone_e164,
                            'date_of_birth' => $input['date_of_birth'] ?? null,
                            'state' => $input['state'] ?? null,
                            'bank_ifsc' => strtoupper((string) $input['bank_ifsc']),
                            'pan_last4' => strtoupper(substr($panNumber, -4)),
                            'aadhaar_last4' => substr($aadhaarNumber, -4),
                        ],
                    ],
                    'ip' => (string) ($input['admin_ip'] ?? null),
                ]);

                return $result;
            });
        } catch (CrossLinePlacementError $e) {
            // PlacementEngine raised cross-line at place() time even
            // though our pre-flight passed — likely a race against a
            // concurrent line change. Roll back the orphan user row that
            // would otherwise linger (we created it OUTSIDE the
            // PlacementEngine transaction).
            $user->delete();

            throw ValidationException::withMessages([
                'placement_adn' => 'Placement is no longer in the sponsor\'s downline. Please refresh and re-enter.',
            ]);
        } catch (\Throwable $e) {
            $user->delete();

            throw $e;
        }

        // ── Activation magic link ───────────────────────────────────────
        // Dispatched AFTER commit so a rollback never enqueues a job
        // pointing at a phantom user. The spouse-activation flow handles
        // the password_set_at=NULL gate and lands the prospect on a
        // password-set page after they click the link.
        $primaryFullName = (string) ($user->full_name ?? $user->email);
        $userId = $user->id;
        $primaryAdn = (string) $this->db->table('distributors')
            ->where('id', $result->distributorId)
            ->value('adn');
        $this->db->connection()->afterCommit(function () use ($userId, $primaryFullName, $primaryAdn): void {
            $u = User::query()->find($userId);
            if ($u !== null) {
                Notification::send($u, new SpouseActivationNotification(
                    userId: $userId,
                    primaryFullName: $primaryFullName,
                    primaryAdn: $primaryAdn,
                ));
            }
        });

        return $result;
    }

    private function normalisePhone(string $phone): string
    {
        $phone = trim($phone);
        if ($phone === '') {
            return $phone;
        }
        if (! str_starts_with($phone, '+')) {
            // Indian-mobile assumption mirrors the wizard. Future change:
            // require E.164 on the admin form too.
            $phone = '+91'.ltrim($phone, '0');
        }

        return $phone;
    }
}
