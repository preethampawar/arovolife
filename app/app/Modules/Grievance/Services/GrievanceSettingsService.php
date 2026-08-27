<?php

declare(strict_types=1);

namespace App\Modules\Grievance\Services;

use App\Modules\Grievance\Enums\EscalationLevel;
use App\Modules\Grievance\Enums\TicketCategory;
use Illuminate\Support\Facades\DB;

/**
 * Single source of truth for every tunable grievance parameter.
 *
 * The SLA table at `/p/grievance` §2 and the escalation clocks at §4 are
 * published commitments to complainants and to the regulator. They are stored
 * as settings rather than constants so that changing a published promise is an
 * audited settings edit with a version bump — the same evidence trail the
 * policy document itself carries — instead of a silent deploy.
 *
 * Bound as a singleton (see GrievanceServiceProvider) so the settings table is
 * read at most once per request or command run.
 *
 * Mirrors the shape of CompensationPlanSettingsService deliberately; if that
 * one grows a shared base class, this should follow.
 */
final class GrievanceSettingsService
{
    /** Registry defaults — used when a key is absent from the settings table. */
    private const SCALAR_DEFAULTS = [
        // Policy §2 — Service-Level Commitments.
        'grievance.sla.acknowledgement_hours' => 48,
        'grievance.sla.first_response_working_days' => 5,
        'grievance.sla.resolution_days' => 30,
        'grievance.sla.third_party_resolution_days' => 60,
        'grievance.sla.status_update_interval_days' => 15,

        // Policy §4 — Escalation matrix. Step 1 → 2 after 7 days, 2 → 3 after 15.
        'grievance.escalation.auto' => true,
        'grievance.escalation.step_2_after_days' => 7,
        'grievance.escalation.step_3_after_days' => 15,

        // Policy §6.2 — DSR 2021 Rule 12 retention.
        'grievance.retention_years' => 7,

        // Policy §3.1 — evidence attachments.
        'grievance.attachment.max_mb' => 10,
        'grievance.attachment.max_count' => 5,

        // Policy §1 — officer mailboxes. These are the placeholders that the
        // launch gate replaces with provisioned addresses.
        'grievance.mailbox.customer_care' => 'care@arovolife.com',
        'grievance.mailbox.grievance_officer' => 'grievance@arovolife.com',
        'grievance.mailbox.nodal_officer' => 'nodal@arovolife.com',
        'grievance.mailbox.compliance_committee' => 'compliance@arovolife.com',
        // Policy §1 publishes a DPO and §4 step 8 presumes the DPO handled the
        // matter before the Data Protection Board hears it. DPDP §13(3) means
        // that published route has to actually go somewhere.
        'grievance.mailbox.dpo' => 'dpo@arovolife.com',
    ];

    /** @var array<string, mixed>|null */
    private ?array $scalarCache = null;

    public function acknowledgementHours(): int
    {
        return $this->scalarInt('grievance.sla.acknowledgement_hours');
    }

    public function firstResponseWorkingDays(): int
    {
        return $this->scalarInt('grievance.sla.first_response_working_days');
    }

    public function resolutionDays(bool $thirdPartyDependent = false): int
    {
        return $thirdPartyDependent
            ? $this->scalarInt('grievance.sla.third_party_resolution_days')
            : $this->scalarInt('grievance.sla.resolution_days');
    }

    public function statusUpdateIntervalDays(): int
    {
        return $this->scalarInt('grievance.sla.status_update_interval_days');
    }

    public function autoEscalationEnabled(): bool
    {
        return $this->scalarBool('grievance.escalation.auto');
    }

    /**
     * Days a ticket may sit at the given level before it becomes eligible for
     * automatic escalation. Levels 3 and 4 are the end of the internal ladder
     * and have no published clock, so they return null.
     */
    public function autoEscalationAfterDays(EscalationLevel $level): ?int
    {
        return match ($level) {
            EscalationLevel::CustomerCare => $this->scalarInt('grievance.escalation.step_2_after_days'),
            EscalationLevel::GrievanceOfficer => $this->scalarInt('grievance.escalation.step_3_after_days'),
            EscalationLevel::NodalOfficer, EscalationLevel::ComplianceCommittee => null,
        };
    }

    public function retentionYears(): int
    {
        return $this->scalarInt('grievance.retention_years');
    }

    public function attachmentMaxKilobytes(): int
    {
        return $this->scalarInt('grievance.attachment.max_mb') * 1024;
    }

    public function attachmentMaxCount(): int
    {
        return $this->scalarInt('grievance.attachment.max_count');
    }

    public function mailboxFor(EscalationLevel $level): string
    {
        return $this->mailboxAt($level->contactSettingKey());
    }

    /**
     * Where a ticket's alerts should go.
     *
     * Privacy complaints go to the DPO rather than to whichever officer owns
     * the escalation step: the DPO is the contact published under DPDP §13,
     * and a data complaint routed anywhere else makes that published route a
     * fiction. Everything else follows the §4 ladder.
     */
    public function mailboxForTicket(TicketCategory $category, EscalationLevel $level): string
    {
        if ($category === TicketCategory::Privacy) {
            return $this->mailboxAt('grievance.mailbox.dpo');
        }

        return $this->mailboxFor($level);
    }

    private function mailboxAt(string $key): string
    {
        $value = $this->scalar($key);

        return $value !== null && $value !== ''
            ? $value
            : (string) (self::SCALAR_DEFAULTS[$key] ?? '');
    }

    // ── Internals ────────────────────────────────────────────────────────────

    private function scalarInt(string $key): int
    {
        $value = $this->scalar($key);

        return $value !== null ? (int) $value : (int) (self::SCALAR_DEFAULTS[$key] ?? 0);
    }

    private function scalarBool(string $key): bool
    {
        $value = $this->scalar($key);
        if ($value === null) {
            return (bool) (self::SCALAR_DEFAULTS[$key] ?? false);
        }

        return in_array(strtolower((string) $value), ['1', 'true', 'on', 'yes'], true);
    }

    private function scalar(string $key): ?string
    {
        if ($this->scalarCache === null) {
            $this->scalarCache = DB::table('settings')->pluck('value', 'key')->all();
        }

        $value = $this->scalarCache[$key] ?? null;

        return $value === null ? null : (string) $value;
    }
}
