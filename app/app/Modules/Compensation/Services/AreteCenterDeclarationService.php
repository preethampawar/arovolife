<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services;

use App\Modules\Compensation\Models\AreteCenter;
use App\Modules\Compensation\Models\AreteCenterDeclaration;
use App\Modules\Compensation\Support\AreteCenterDeclarations;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Compliance\Support\AuditDigests;
use App\Modules\Identity\Models\Distributor;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;

/**
 * Records a centre's acceptance of the declarations at the version in force.
 *
 * This is the mechanism R-95 said was missing. Until v3 the question never
 * arose: declarations were captured once, on the application form, and the
 * version had never moved. It has now, so every live centre owes a fresh
 * acceptance, and a centre created straight from the admin console owes its
 * first one — `AdminAreteCenterController::store()` makes a centre with no
 * application behind it and therefore no declaration in any version.
 *
 * There are two entry points rather than one with a role check, and that is
 * the whole design: a declaration is a signature. An admin who could accept on
 * a distributor's behalf would be fabricating the evidence the dispatch gate
 * exists to produce, which is worse than having no evidence at all. So the
 * owner signs for an owned centre, the company signs for its own centre, and
 * neither method can be pointed at the other kind.
 */
final class AreteCenterDeclarationService
{
    public function __construct(private readonly DatabaseManager $db) {}

    /**
     * The centre's own distributor accepts.
     *
     * @param  list<string>  $keys
     */
    public function acceptByOwner(
        AreteCenter $centre,
        Distributor $distributor,
        array $keys,
        ?int $userId,
        ?string $ip,
    ): void {
        if ($centre->assigned_distributor_id !== $distributor->id) {
            throw new InvalidArgumentException(
                'A centre\'s declarations can only be accepted by the distributor it is assigned to.'
            );
        }

        $this->record($centre, $keys, $userId, $ip, 'owner');
    }

    /**
     * A company-run centre has no distributor to sign, so a named member of
     * staff accepts on arovolife's behalf. Refused for an assigned centre —
     * see the class docblock.
     *
     * @param  list<string>  $keys
     */
    public function acceptForCompanyCentre(
        AreteCenter $centre,
        int $adminUserId,
        array $keys,
        ?string $ip,
    ): void {
        if ($centre->assigned_distributor_id !== null) {
            throw new InvalidArgumentException(
                'This centre is assigned to a distributor, so only that distributor can accept its declarations. '
                .'Ask them to accept from My Arete Development Centre.'
            );
        }

        $this->record($centre, $keys, $adminUserId, $ip, 'company');
    }

    /** @param list<string> $keys */
    private function record(AreteCenter $centre, array $keys, ?int $userId, ?string $ip, string $signedBy): void
    {
        $required = AreteCenterDeclarations::keys();
        $missing = array_diff($required, $keys);

        // Every one, not any. Accepting four of five is not accepting the
        // bargain, and a partial row set would satisfy nothing downstream
        // while looking, in the table, like progress.
        if ($missing !== []) {
            throw new InvalidArgumentException('Every declaration must be accepted.');
        }

        $this->db->transaction(function () use ($centre, $required, $userId, $ip, $signedBy): void {
            $acceptedAt = now();

            // The state this row is evidence about is whether the centre stood
            // accepted at the version in force — not the row contents. A
            // re-acceptance of an already-live set digests identically on both
            // sides, which is the truthful answer: nothing changed.
            $before = [
                'version' => AreteCenterDeclarations::VERSION,
                'accepted' => AreteCenterDeclaration::currentVersionAcceptedBy($centre->id),
            ];

            foreach ($required as $key) {
                AreteCenterDeclaration::updateOrCreate(
                    [
                        'center_id' => $centre->id,
                        'declaration_key' => $key,
                        'version' => AreteCenterDeclarations::VERSION,
                    ],
                    [
                        'accepted_at' => $acceptedAt,
                        'ip' => $ip,
                        'accepted_by_user_id' => $userId,
                    ],
                );
            }

            AuditLog::create([
                'actor_id' => $userId,
                'action' => 'arete_center.declarations_accepted',
                'subject_type' => 'arete_center',
                'subject_id' => $centre->id,
                'before_hash' => AuditDigests::of($before),
                'after_hash' => AuditDigests::of(['version' => AreteCenterDeclarations::VERSION, 'accepted' => true]),
                'details' => [
                    'centre_name' => $centre->name,
                    'version' => AreteCenterDeclarations::VERSION,
                    'keys' => $required,
                    'signed_by' => $signedBy,
                    'accepted_at' => $acceptedAt->toIso8601String(),
                    'before' => $before,
                    'after' => ['version' => AreteCenterDeclarations::VERSION, 'accepted' => true],
                ],
                'ip' => $ip,
            ]);
        });
    }
}
