<?php

declare(strict_types=1);

namespace App\Modules\ActionCenter\Services;

use App\Modules\ActionCenter\Contracts\ActionProvider;
use App\Modules\ActionCenter\Exceptions\UnknownActionType;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Collection;

/**
 * The list of providers, in catalogue order (plan §6). Registered as a tagged
 * array by `ActionCenterServiceProvider`, so a later slice adds a provider by
 * adding one line there and nothing else.
 *
 * Filtering is per provider, never per role (plan §10.2): the viewer sees
 * exactly the queues they hold the permission to clear, so finance never sees
 * a KYC backlog it cannot touch.
 */
final class ActionCenterRegistry
{
    /** @var Collection<int, ActionProvider> */
    private Collection $providers;

    /** @param iterable<ActionProvider> $providers */
    public function __construct(iterable $providers = [])
    {
        $this->providers = Collection::make($providers)->values();
    }

    /**
     * Every registered provider, enabled or not.
     *
     * @return Collection<int, ActionProvider>
     */
    public function all(): Collection
    {
        return $this->providers;
    }

    /**
     * The providers this user may both see (permission) and query (feature
     * flag on). A provider whose flag is off degrades quietly — it is hidden
     * rather than shown as a zero.
     *
     * @return Collection<int, ActionProvider>
     */
    public function for(User $user): Collection
    {
        return $this->providers
            ->filter(fn (ActionProvider $provider): bool => $provider->enabled() && $user->can($provider->permission()))
            ->values();
    }

    public function find(string $key): ActionProvider
    {
        $provider = $this->providers->first(fn (ActionProvider $p): bool => $p->key() === $key);

        if (! $provider instanceof ActionProvider) {
            throw UnknownActionType::for($key);
        }

        return $provider;
    }

    /** The provider for `$key`, but only if this user may act on it. */
    public function findFor(User $user, string $key): ActionProvider
    {
        $provider = $this->find($key);

        if (! $provider->enabled() || ! $user->can($provider->permission())) {
            throw UnknownActionType::for($key);
        }

        return $provider;
    }
}
