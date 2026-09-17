<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Models;

use App\Modules\Compensation\Support\AreteCenterDeclarations;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What a centre's owner has undertaken, recorded per centre so it can be
 * re-accepted when the wording changes.
 *
 * @property int $center_id
 * @property string $declaration_key
 * @property string $version
 * @property Carbon $accepted_at
 * @property string|null $ip
 * @property int|null $accepted_by_user_id
 */
final class AreteCenterDeclaration extends Model
{
    protected $table = 'arete_center_declarations';

    protected $fillable = [
        'center_id', 'declaration_key', 'version', 'accepted_at', 'ip', 'accepted_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'center_id' => 'int',
            'accepted_at' => 'datetime',
            'accepted_by_user_id' => 'int',
        ];
    }

    /** @return BelongsTo<AreteCenter, $this> */
    public function center(): BelongsTo
    {
        return $this->belongsTo(AreteCenter::class, 'center_id');
    }

    /**
     * Has this centre accepted every declaration at the version currently in
     * force?
     *
     * Every one, not any: the undertakings are a set, and a centre that has
     * accepted four of five has not accepted the bargain. A centre created
     * straight from the admin console has no rows at all and fails here, which
     * is the point — R-95 exists because those centres carry no declaration in
     * any version, and the R-21 closure evidence does not exist for them.
     */
    public static function currentVersionAcceptedBy(int $centerId): bool
    {
        $required = array_keys(AreteCenterDeclarations::all());

        $accepted = self::query()
            ->where('center_id', $centerId)
            ->where('version', AreteCenterDeclarations::VERSION)
            ->pluck('declaration_key')
            ->all();

        return array_diff($required, $accepted) === [];
    }

    /**
     * Which declarations this centre still owes at the version in force.
     *
     * @return list<string>
     */
    public static function outstandingFor(int $centerId): array
    {
        $accepted = self::query()
            ->where('center_id', $centerId)
            ->where('version', AreteCenterDeclarations::VERSION)
            ->pluck('declaration_key')
            ->all();

        return array_values(array_diff(array_keys(AreteCenterDeclarations::all()), $accepted));
    }
}
