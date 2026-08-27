<?php

declare(strict_types=1);

namespace App\Modules\Identity\Services;

use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Consent\Services\ConsentDocuments;
use App\Modules\Genealogy\Services\DTOs\PlaceDistributorInput;
use App\Modules\Genealogy\Services\DTOs\PlacementResult;
use App\Modules\Genealogy\Services\PlacementEngine;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Notifications\SpouseActivationNotification;
use App\Modules\Identity\Services\Exceptions\IncompleteRegistrationDataError;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/*
 * Intentionally NOT marked `final` so the test suite can swap a
 * Mockery stub in for placement-race failure-path testing
 * (see PlacementRaceMessageTest). Production callers should treat
 * this as effectively final — extending for behaviour change would
 * bypass the audit + transaction guarantees finalise() makes.
 */
class RegistrationService
{
    /**
     * Document versions and hashes are no longer declared here.
     *
     * They were four hardcoded hashes of Phase-1 stub strings with a version
     * of `1.0.0` for all four, while the published documents were dated
     * 2026-05-21 and 2026-08-07 — so `consents.doc_hash_sha256` proved neither
     * the text nor the version, which is its only purpose (R-51, IT Act §10A).
     * `ConsentDocuments` reads both from the content page the public URL
     * actually serves.
     */
    public function __construct(
        private readonly PlacementEngine $engine,
        private readonly DatabaseManager $db,
        private readonly ConsentDocuments $documents,
    ) {}

    /**
     * @param  array<string, mixed>  $wizardData
     */
    public function finalise(array $wizardData, ?User $user = null): PlacementResult
    {
        $personal = $wizardData['personal'] ?? [];
        $pan = $wizardData['pan'] ?? [];
        $aadhaar = $wizardData['aadhaar'] ?? [];
        $bank = $wizardData['bank'] ?? [];
        $placement = $wizardData['placement'] ?? [];
        $consent = $wizardData['consent'] ?? [];
        $documents = $wizardData['documents']['documents'] ?? ($wizardData['documents'] ?? []);
        $couple = $wizardData['couple'] ?? null;
        $isCouple = is_array($couple) && ! empty($couple['enabled']);

        $sponsorId = (int) ($wizardData['sponsor_id'] ?? 0);
        // Per ADR-0003 the placement target is now mandatory and arrives in
        // the wizard state from the referral link (RegistrationWizardController::start()).
        $placementId = (int) ($placement['placement_id'] ?? $sponsorId);

        $panNumber = strtoupper(trim($pan['pan_number'] ?? ''));
        $panHash = hash('sha256', $panNumber, true);
        // Bank is optional. Encrypt only when an account number was
        // provided; otherwise pass null down so the distributor row is
        // created with bank_account_enc + bank_ifsc = NULL. The distributor
        // can add bank later from their dashboard before any payout.
        $bankAccountRaw = trim((string) ($bank['account_number'] ?? ''));
        $bankIfscRaw = strtoupper(trim((string) ($bank['ifsc'] ?? '')));
        $bankProvided = $bankAccountRaw !== '' && $bankIfscRaw !== '';
        $bankAccountEnc = $bankProvided ? $this->encryptBankAccount($bankAccountRaw) : null;
        $bankIfscFinal = $bankProvided ? $bankIfscRaw : null;

        // Full PAN + Aadhaar are encrypted at rest pending KYC review. After
        // ApproveKycSubmission flips verified_at NOT NULL, those columns are
        // nulled and the uploaded files are purged; only last-4 survives.
        // This is accepted risk logged in docs/compliance/risk-register.md.
        $panEncrypted = $panNumber !== '' ? Crypt::encryptString($panNumber) : null;
        $aadhaarNumber = preg_replace('/\D+/', '', (string) ($aadhaar['aadhaar_number'] ?? '')) ?? '';
        $aadhaarEncrypted = $aadhaarNumber !== '' ? Crypt::encryptString($aadhaarNumber) : null;
        $aadhaarLast4 = $aadhaarNumber !== ''
            ? substr($aadhaarNumber, -4)
            : ($aadhaar['last4'] ?? '0000');
        $panLast4 = strtoupper(substr($panNumber !== '' ? $panNumber : '0000', -4));

        // Atomic finalisation: placement + cooling_off_events + kyc_documents
        // + orientation_views + consents + user.create + audit_log all live
        // or all roll back. The placement engine starts its own transaction;
        // Laravel nests cleanly via savepoints.
        return $this->db->connection()->transaction(function () use (
            $documents, $consent, $personal, $user, $wizardData,
            $couple, $isCouple, $bankAccountEnc, $bankIfscFinal,
            $sponsorId, $placementId, $panHash, $panLast4,
            $panEncrypted, $aadhaarEncrypted, $aadhaarLast4,
            $aadhaar, $placement
        ): PlacementResult {
            // Create user inside transaction if not already created
            if ($user === null) {
                $account = $wizardData['account'] ?? [];

                // Validate required account data exists
                if (empty($account['email']) || empty($account['phone_e164']) || empty($account['password_hash'])) {
                    throw new IncompleteRegistrationDataError(
                        'Registration incomplete: missing required account data (email, phone, or password). '
                        .'Please return to the registration wizard and complete all steps.'
                    );
                }

                // status=pending until admin reviews + approves the KYC
                // documents at /admin/kyc/{id}. ApproveKycSubmission flips
                // this to 'active'. Creating with 'active' here would skip
                // the entire review queue (the queue filters by status=pending)
                // and let unverified distributors transact immediately.
                $user = User::create([
                    'email' => $account['email'],
                    'phone_e164' => $account['phone_e164'],
                    'password_hash' => $account['password_hash'],
                    'password_set_at' => now(),
                    'full_name' => $account['full_name'] ?? null,
                    'status' => 'pending',
                ]);
            }

            // Create PlaceDistributorInput now that we have a user_id
            $input = new PlaceDistributorInput(
                userId: $user->id,
                sponsorId: $sponsorId,
                placementId: $placementId,
                panHash: $panHash,
                panLast4: $panLast4,
                bankAccountEnc: $bankAccountEnc,
                bankIfsc: $bankIfscFinal,
                state: $personal['state'] ?? '',
                sideOpt: ! empty($placement['side']) ? $placement['side'] : null,
                aadhaarRef: $aadhaar['ref'] ?? 'STUB_REF_'.strtoupper(uniqid()),
                aadhaarLast4: $aadhaarLast4,
                isPrimaryCouple: false,
                panEncrypted: $panEncrypted,
                aadhaarEncrypted: $aadhaarEncrypted,
            );

            $result = $this->engine->place($input);

            $now = now()->format('Y-m-d H:i:s.v');

            // Open the statutory 30-day cooling-off clock. Cancelled_at stays
            // NULL until the distributor (or admin) invokes CancelCoolingOff.
            $this->db->table('cooling_off_events')->insert([
                'distributor_id' => $result->distributorId,
                'opened_at' => $now,
            ]);

            // KYC documents — uploaded at step 7. Pending admin review (verified_at NULL).
            foreach ($documents as $type => $doc) {
                if (! is_array($doc) || empty($doc['path']) || empty($doc['sha256'])) {
                    continue;
                }
                $this->db->table('kyc_documents')->insert([
                    'distributor_id' => $result->distributorId,
                    'type' => $type,
                    'object_storage_key' => $doc['path'],
                    'checksum_sha256' => hex2bin($doc['sha256']),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            // The T&C §2.3 eligibility declarations, recorded against the
            // distributor rather than left implicit in the agreement text.
            // Written here rather than inside PlacementEngine because they are
            // a fact about the person, not about where they sit in the tree.
            $declarations = $consent['declarations'] ?? [];

            $this->db->table('distributors')
                ->where('id', $result->distributorId)
                ->update([
                    // Null, not false, when the wizard did not ask — a `false`
                    // would read as "they declared they are not of sound mind".
                    'declared_sound_mind' => isset($declarations['sound_mind']) ? (bool) $declarations['sound_mind'] : null,
                    'declared_not_insolvent' => isset($declarations['not_insolvent']) ? (bool) $declarations['not_insolvent'] : null,
                    'declared_no_moral_turpitude' => isset($declarations['no_moral_turpitude']) ? (bool) $declarations['no_moral_turpitude'] : null,
                    'declarations_made_at' => $declarations === [] ? null : $now,
                ]);

            // Orientation record.
            //
            // This records what actually happened, which is NOT what it used
            // to claim. There is no orientation video yet — step 2 renders a
            // placeholder and the only gate is a checkbox the applicant ticks
            // themselves. Writing `watch_percent => 100` with `started_at` and
            // `completed_at` in the same instant certified 95%-plus playback
            // of a video that does not exist, for every distributor. At a DSR
            // audit `orientation_views` is exactly the table that gets
            // produced, and a false certificate is worse to be caught holding
            // than an honest gap.
            //
            // So: watch_percent stays 0 because nothing was measured,
            // completed_at stays null because nothing was completed, and the
            // fingerprint says what the evidence really is. `quiz_passed_at`
            // IS legitimate — the micro-quiz is real, server-validated, and
            // wrong answers are rejected (RegistrationWizardController).
            //
            // Hard rule 4 is not satisfied by this and cannot be until the
            // video and playback tracking ship. See C-03 / R-50.
            $this->db->table('orientation_views')->insert([
                'distributor_id' => $result->distributorId,
                'video_id' => 'PHASE1_ORIENTATION_V1',
                'started_at' => $now,
                'completed_at' => null,
                'watch_percent' => 0,
                'quiz_passed_at' => $now,
                'playback_fingerprint' => 'self-declared:no-playback-measurement',
            ]);

            // Consent records (4 documents)
            $ip = $consent['ip'] ?? '0.0.0.0';
            $ua = $consent['user_agent'] ?? '';
            foreach ($this->documents->all() as $type => $document) {
                $version = $document['version'];
                $hash = hex2bin($document['hash']);
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

            // Persist DOB. Status stays 'pending' until an admin approves the
            // KYC submission (see Modules/Admin/Services/ApproveKycSubmission).
            // Manual review replaces the automated PAN/Aadhaar gateway checks
            // during Phase 1; the gate moves but never disappears.
            $user->update([
                'date_of_birth' => $personal['date_of_birth'] ?? null,
            ]);

            // ── Couple registration (option A): spawn the secondary distributor.
            // Per CLAUDE.md hard rule #6, the couple shares ONE business unit,
            // so the secondary's ADN is derived (<primary>-S) and the row does
            // not occupy a binary tree slot (no closure, no sponsorship). Both
            // adults still sign agreements and complete KYC independently —
            // DSR Rule 5(1)(b) requires a written agreement with each direct
            // seller and DPDP §6 requires per-adult consent.
            if ($isCouple) {
                $primaryAdn = (string) $this->db->table('distributors')
                    ->where('id', $result->distributorId)
                    ->value('adn');

                $secondaryId = $this->createSecondaryDistributor(
                    primaryDistributorId: $result->distributorId,
                    primaryUserId: $user->id,
                    primaryAdn: $primaryAdn,
                    primary: [
                        'sponsor_id' => $input->sponsorId,
                        'side_chosen_by' => $result->sideChosenBy,
                        'depth' => $result->depth,
                        'effective_date' => now()->format('Y-m-d H:i:s.v'),
                        'cooling_off_end_at' => now()->addDays(30)->format('Y-m-d H:i:s.v'),
                        'state' => $personal['state'] ?? '',
                        'bank_ifsc' => $bankIfscFinal,
                        'bank_account_enc' => $bankAccountEnc,
                    ],
                    couple: $couple,
                    consent: $consent,
                    now: $now,
                );

                // Dispatch the activation notification AFTER the wrapping
                // transaction commits — otherwise a rollback would leave a
                // queue job pointing at a user_id that no longer exists.
                $spouseUserId = (int) $this->db->table('distributors')->where('id', $secondaryId)->value('user_id');
                $primaryFullName = (string) ($user->full_name ?? $user->email);
                $this->db->connection()->afterCommit(function () use ($spouseUserId, $primaryFullName, $primaryAdn): void {
                    $spouseUser = User::query()->find($spouseUserId);
                    if ($spouseUser !== null) {
                        Notification::send($spouseUser, new SpouseActivationNotification(
                            userId: $spouseUserId,
                            primaryFullName: $primaryFullName,
                            primaryAdn: $primaryAdn,
                        ));
                    }
                });
            }

            AuditLog::create([
                'actor_id' => $user->id,
                'action' => $isCouple ? 'registration.completed.couple' : 'registration.completed',
                'subject_type' => 'distributor',
                'subject_id' => $result->distributorId,
                'details' => [
                    'user_id' => $user->id,
                    'adn_issued' => true,
                    'state' => $personal['state'] ?? '',
                    'couple' => $isCouple,
                ],
            ]);

            return $result;
        });
    }

    /**
     * Insert the secondary distributor row + spouse user + spouse KYC + spouse
     * consent + spouse cooling-off, then mutually link with the primary.
     *
     * The row does NOT enter the binary tree (placement_side = NULL, no
     * closure rows). The spouse is a co-signed direct seller, not a separate
     * tree node — that's the architectural intent of `is_primary_couple`.
     *
     * @param  array<string, mixed>  $primary  shared values copied from primary at registration time
     * @param  array<string, mixed>  $couple  spouse fields captured by the wizard
     * @param  array<string, mixed>  $consent  primary's consent metadata (ip, ua) — reused for spouse since they consent at the same moment
     */
    private function createSecondaryDistributor(
        int $primaryDistributorId,
        int $primaryUserId,
        string $primaryAdn,
        array $primary,
        array $couple,
        array $consent,
        string $now,
    ): int {
        // Spouse user account. Random password is a placeholder; the spouse
        // will set their own via the activation magic-link sent below.
        // password_set_at = NULL gates LoginController until they activate.
        $spouseUser = User::create([
            'email' => $couple['spouse_email'],
            'phone_e164' => $couple['spouse_phone_e164'] ?? null,
            'password_hash' => Hash::make(Str::random(32)),
            'password_set_at' => null,
            'full_name' => $couple['spouse_full_name'] ?? null,
            'date_of_birth' => $couple['spouse_dob'] ?? null,
            'status' => 'pending',
        ]);

        $spousePan = strtoupper(trim($couple['spouse_pan_number'] ?? ''));
        $spousePanHash = hash('sha256', $spousePan, true);

        $secondaryId = $this->db->table('distributors')->insertGetId([
            'user_id' => $spouseUser->id,
            'adn' => $primaryAdn.'-S',
            'pan_hash' => $spousePanHash,
            'pan_last4' => substr($spousePan, -4),
            'aadhaar_ref' => $couple['spouse_aadhaar_ref'] ?? 'STUB_REF_'.strtoupper(uniqid()),
            'aadhaar_last4' => $couple['spouse_aadhaar_last4'] ?? '0000',
            'bank_account_enc' => $primary['bank_account_enc'],   // shared bank
            'bank_ifsc' => $primary['bank_ifsc'],
            'sponsor_id' => $primary['sponsor_id'],
            'placement_id_at_registration' => null,
            'placement_parent_id' => $primaryDistributorId,
            'placement_side' => null,                              // NOT in tree
            'side_chosen_by' => $primary['side_chosen_by'],
            'depth' => $primary['depth'],
            'effective_date' => $primary['effective_date'],
            'cooling_off_end_at' => $primary['cooling_off_end_at'],
            'state' => $primary['state'],
            'spouse_distributor_id' => $primaryDistributorId,
            'is_primary_couple' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // Mutual link + flip primary to is_primary_couple
        $this->db->table('distributors')
            ->where('id', $primaryDistributorId)
            ->update([
                'spouse_distributor_id' => $secondaryId,
                'is_primary_couple' => 1,
                'updated_at' => $now,
            ]);

        // Spouse cooling-off clock — independent of primary's
        $this->db->table('cooling_off_events')->insert([
            'distributor_id' => $secondaryId,
            'opened_at' => $now,
        ]);

        // Spouse KYC documents — separate uploads, separate review per row.
        // Approved as a couple by ApproveKycSubmission when it detects the
        // is_primary_couple link.
        $spouseDocs = $couple['spouse_documents'] ?? [];
        foreach ($spouseDocs as $type => $doc) {
            if (! is_array($doc) || empty($doc['path']) || empty($doc['sha256'])) {
                continue;
            }
            $this->db->table('kyc_documents')->insert([
                'distributor_id' => $secondaryId,
                'type' => $type,
                'object_storage_key' => $doc['path'],
                'checksum_sha256' => hex2bin($doc['sha256']),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // Spouse orientation — the spouse legally co-signs the agreement, so
        // they owe the same orientation as the primary.
        //
        // `quiz_passed_at` is null here and that is the point: the spouse
        // never sat the quiz. Only the primary answers it, once, during the
        // wizard. Stamping a pass on the spouse recorded them as having
        // demonstrated understanding they were never asked to demonstrate —
        // which is the one thing the micro-quiz exists to evidence.
        //
        // Their own orientation and quiz is part of closing C-03; until then
        // this row says plainly that neither happened.
        $this->db->table('orientation_views')->insert([
            'distributor_id' => $secondaryId,
            'video_id' => 'PHASE1_ORIENTATION_V1',
            'started_at' => $now,
            'completed_at' => null,
            'watch_percent' => 0,
            'quiz_passed_at' => null,
            'playback_fingerprint' => 'spouse:not-yet-taken',
        ]);

        // Spouse consent rows — DPDP §6 requires per-adult consent. Both
        // spouses accept all four documents at registration time.
        $ip = $consent['ip'] ?? '0.0.0.0';
        $ua = $consent['user_agent'] ?? '';
        foreach ($this->documents->all() as $type => $document) {
            $version = $document['version'];
            $hash = hex2bin($document['hash']);
            $this->db->table('consents')->insert([
                'distributor_id' => $secondaryId,
                'document_type' => $type,
                'document_version' => $version,
                'doc_hash_sha256' => $hash,
                'accepted_at' => $now,
                'ip' => $ip,
                'user_agent' => substr($ua, 0, 512),
            ]);
        }

        return $secondaryId;
    }

    private function encryptBankAccount(string $accountNumber): string
    {
        // AES-256-CBC + HMAC via Laravel Crypt; key from APP_KEY. Output is a
        // base64 JSON envelope ({iv,value,mac,tag}), well under the column's
        // VARBINARY(512) ceiling for any realistic Indian account length.
        return Crypt::encryptString($accountNumber);
    }
}
