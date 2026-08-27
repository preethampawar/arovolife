<?php

declare(strict_types=1);

namespace App\Modules\Grievance\Enums;

/**
 * The escalation matrix published at `/p/grievance` §4, steps 1–4.
 *
 * Steps 5–8 of the published matrix (National Consumer Helpline, CCPA,
 * the Consumer Disputes Redressal Commissions, the Data Protection Board)
 * are external authorities. They are deliberately NOT modelled here: the
 * complainant may approach them directly and arovolife does not control or
 * track their clocks. The policy says so explicitly, and pretending to own
 * those steps in our tracker would misstate a statutory right.
 */
enum EscalationLevel: int
{
    case CustomerCare = 1;
    case GrievanceOfficer = 2;
    case NodalOfficer = 3;
    case ComplianceCommittee = 4;

    public function label(): string
    {
        return match ($this) {
            self::CustomerCare => 'Customer care agent',
            self::GrievanceOfficer => 'Grievance Officer',
            self::NodalOfficer => 'Nodal Officer',
            self::ComplianceCommittee => 'Compliance Committee',
        };
    }

    public function next(): ?self
    {
        return self::tryFrom($this->value + 1);
    }

    /**
     * Settings key holding the mailbox that owns this level.
     */
    public function contactSettingKey(): string
    {
        return match ($this) {
            self::CustomerCare => 'grievance.mailbox.customer_care',
            self::GrievanceOfficer => 'grievance.mailbox.grievance_officer',
            self::NodalOfficer => 'grievance.mailbox.nodal_officer',
            self::ComplianceCommittee => 'grievance.mailbox.compliance_committee',
        };
    }
}
