<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Services;

use Illuminate\Support\Facades\DB;

/**
 * Renders raw audit_log rows as plain-English summaries for the admin
 * dashboard and audit-log page. Non-technical readers (operations staff,
 * compliance reviewers) get sentences like
 *
 *     "Admin signed in as Demo Sponsoree One for support"
 *
 * instead of `admin.impersonate.start, user#33, by admin@arovolife.test`.
 *
 * Lookups: a single batch query at construction time pulls every
 * referenced distributor's ADN + linked user's name so each row's
 * title-line is rendered without N+1 queries.
 *
 * Unknown / new action keys fall back to a title-cased echo of the key
 * — never throws, always renders something readable, so adding a new
 * audit event doesn't break the dashboard until this presenter learns
 * about it.
 */
final class AuditLogPresenter
{
    /** @var array<int, string> distributor_id => "{name} ({adn})" */
    private array $distributorLabels = [];

    /** @var array<int, string> user_id => "{name}" */
    private array $userLabels = [];

    /**
     * Pre-warm the lookup caches from a batch of audit rows.
     *
     * @param  iterable<object>  $rows  audit_log rows with at least `subject_type`, `subject_id`, `details` (JSON or array) and `actor_email`
     */
    public function warmCaches(iterable $rows): void
    {
        $distributorIds = [];
        $userIds = [];
        foreach ($rows as $row) {
            $type = (string) ($row->subject_type ?? '');
            $id = (int) ($row->subject_id ?? 0);
            if ($id > 0) {
                if ($type === 'distributor') {
                    $distributorIds[] = $id;
                } elseif ($type === 'user') {
                    $userIds[] = $id;
                }
            }
            // Some actions stash IDs in the JSON details too (e.g.
            // genealogy.placement.created has sponsor_id, placement_id).
            $details = $this->decodeDetails($row->details ?? null);
            foreach (['sponsor_id', 'placement_id', 'parent_id', 'distributor_id'] as $k) {
                if (isset($details[$k]) && is_numeric($details[$k])) {
                    $distributorIds[] = (int) $details[$k];
                }
            }
            foreach (['user_id'] as $k) {
                if (isset($details[$k]) && is_numeric($details[$k])) {
                    $userIds[] = (int) $details[$k];
                }
            }
        }
        $distributorIds = array_values(array_unique(array_filter($distributorIds)));
        $userIds = array_values(array_unique(array_filter($userIds)));

        if ($distributorIds !== []) {
            $rows = DB::table('distributors')
                ->leftJoin('users', 'users.id', '=', 'distributors.user_id')
                ->whereIn('distributors.id', $distributorIds)
                ->select('distributors.id', 'distributors.adn', 'users.full_name', 'users.email')
                ->get();
            foreach ($rows as $r) {
                $name = (string) ($r->full_name ?: $r->email ?: 'Distributor');
                $this->distributorLabels[(int) $r->id] = $name.' ('.$r->adn.')';
            }
        }
        if ($userIds !== []) {
            $rows = DB::table('users')
                ->whereIn('id', $userIds)
                ->select('id', 'full_name', 'email')
                ->get();
            foreach ($rows as $r) {
                $this->userLabels[(int) $r->id] = (string) ($r->full_name ?: $r->email ?: ('user #'.$r->id));
            }
        }
    }

    /**
     * Render one audit row to a {title, subtitle} pair.
     *
     * @return array{title: string, subtitle: string}
     */
    public function present(object $row): array
    {
        $action = (string) ($row->action ?? '');
        $details = $this->decodeDetails($row->details ?? null);
        $actorEmail = (string) ($row->actor_email ?? '');

        $subjectLabel = $this->labelFor((string) ($row->subject_type ?? ''), (int) ($row->subject_id ?? 0));

        // Map of action → title closure. Each closure returns the plain
        // sentence; subtitles ("by admin@…") are filled in below.
        $title = match ($action) {
            'admin.kyc.approved' => 'Approved KYC for '.$subjectLabel,
            'admin.kyc.rejected' => 'Rejected KYC for '.$subjectLabel.($this->reasonFromDetails($details) ?? ''),
            'admin.impersonate.start' => 'Signed in as '.$this->userOrSubjectLabel($subjectLabel, $details).' for support',
            'admin.impersonate.stop' => 'Stopped viewing as '.$this->userOrSubjectLabel($subjectLabel, $details),
            'profile.id_photo.updated' => $subjectLabel.' updated their ID photo',
            'profile.id_photo.deleted' => $subjectLabel.' removed their ID photo',
            'feature_flag.toggled' => $this->describeFeatureFlagToggle($details),
            'platform.reset' => 'Platform data was reset to the default state',
            'distributor.status_changed' => 'Distributor '.($details['adn'] ?? $subjectLabel).' marked '.($details['to'] ?? 'updated'),
            'distributor.frozen' => 'Distributor '.$subjectLabel.' was suspended',
            'distributor.unfrozen' => 'Distributor '.$subjectLabel.' was reactivated',
            'contact_inquiry.retention_purge' => 'Old contact-form inquiries were cleared (data-retention sweep)',
            'genealogy.placement.created' => 'New distributor joined the tree'.$this->placementDescription($details),
            'genealogy.placement.rejected' => 'A placement attempt was rejected ('.($details['reason'] ?? 'unknown reason').')',
            'registration.completed' => 'Registration completed by '.$this->distributorByDetails($details, $subjectLabel),
            'registration.completed.couple' => 'Couple registration completed for '.$this->distributorByDetails($details, $subjectLabel),
            default => $this->humanizeUnknownAction($action),
        };

        $subtitleParts = [];
        if ($actorEmail !== '') {
            $subtitleParts[] = 'by '.$actorEmail;
        }
        $subtitle = implode(' · ', $subtitleParts);

        return [
            'title' => $title,
            'subtitle' => $subtitle,
        ];
    }

    /** @return array<string, mixed> */
    private function decodeDetails(mixed $details): array
    {
        if (is_array($details)) {
            return $details;
        }
        if (is_string($details) && $details !== '') {
            $decoded = json_decode($details, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    private function labelFor(string $type, int $id): string
    {
        if ($id <= 0) {
            return '';
        }
        if ($type === 'distributor') {
            return $this->distributorLabels[$id] ?? ('Distributor #'.$id);
        }
        if ($type === 'user') {
            return $this->userLabels[$id] ?? ('User #'.$id);
        }

        return $type !== '' ? (ucfirst($type).' #'.$id) : '';
    }

    /** @param  array<string, mixed>  $details */
    private function userOrSubjectLabel(string $subjectLabel, array $details): string
    {
        if ($subjectLabel !== '') {
            return $subjectLabel;
        }
        if (isset($details['user_id'])) {
            return $this->userLabels[(int) $details['user_id']] ?? ('User #'.$details['user_id']);
        }

        return 'a user';
    }

    /** @param  array<string, mixed>  $details */
    private function distributorByDetails(array $details, string $fallbackSubject): string
    {
        if (isset($details['distributor_id'])) {
            $id = (int) $details['distributor_id'];

            return $this->distributorLabels[$id] ?? $fallbackSubject;
        }

        return $fallbackSubject !== '' ? $fallbackSubject : 'a new distributor';
    }

    /** @param  array<string, mixed>  $details */
    private function describeFeatureFlagToggle(array $details): string
    {
        $flag = (string) ($details['flag'] ?? 'a feature flag');
        $to = $details['to'] ?? null;
        $state = $to === true ? 'turned ON' : ($to === false ? 'turned OFF' : 'changed');

        return 'Feature flag "'.$flag.'" was '.$state;
    }

    /** @param  array<string, mixed>  $details */
    private function placementDescription(array $details): string
    {
        $side = $details['side'] ?? null;
        $depth = $details['depth'] ?? null;
        $bits = [];
        if ($side === 'L') {
            $bits[] = 'on the left leg';
        } elseif ($side === 'R') {
            $bits[] = 'on the right leg';
        }
        if (is_numeric($depth)) {
            $bits[] = 'at level '.$depth;
        }

        return $bits === [] ? '' : ' ('.implode(', ', $bits).')';
    }

    /** @param  array<string, mixed>  $details */
    private function reasonFromDetails(array $details): ?string
    {
        if (isset($details['reason']) && is_string($details['reason']) && $details['reason'] !== '') {
            return ' — '.$details['reason'];
        }

        return null;
    }

    /**
     * Fallback for action keys this presenter doesn't recognise. Turns
     * `something.weird_thing_happened` into "Something weird thing
     * happened" — readable, never throws.
     */
    private function humanizeUnknownAction(string $action): string
    {
        $cleaned = str_replace(['.', '_'], ' ', $action);
        $cleaned = trim(preg_replace('/\s+/', ' ', $cleaned) ?? '');

        return $cleaned === '' ? 'System activity' : ucfirst($cleaned);
    }
}
