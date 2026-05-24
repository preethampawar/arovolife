<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers;

use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Shared\Features\HibpPasswordCheck;
use App\Modules\Shared\Features\RegistrationKillswitch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Laravel\Pennant\Feature;

final class AdminFeatureFlagController extends Controller
{
    /**
     * Single registry of admin-toggleable feature flags. Adding a new flag
     * means: (1) create the resolver class, (2) add a row here.
     *
     * @return array<string, array{class: class-string, label: string, description: string}>
     */
    private function registry(): array
    {
        return [
            'registration.killswitch' => [
                'class' => RegistrationKillswitch::class,
                'label' => 'Registration killswitch',
                'description' => 'When OFF, the public /register and /join entry points return a "temporarily closed" page. In-progress wizards continue.',
            ],
            'password.hibp_check' => [
                'class' => HibpPasswordCheck::class,
                'label' => 'HIBP password breach check',
                'description' => 'Extra layer of password security. When ON, every new/changed password is checked against the Have-I-Been-Pwned breach database via k-anonymity API (api.pwnedpasswords.com). When OFF, the breach check is skipped — only the zxcvbn entropy gate runs. Keep ON in production; safe to turn OFF on offline staging boxes or for demo seeding.',
            ],
        ];
    }

    public function index(): View
    {
        $flags = [];
        foreach ($this->registry() as $key => $meta) {
            $flags[$key] = $meta + [
                // Read against the global (null) scope so admins see the same
                // state that unauthenticated registration visitors see, not
                // an accidental admin-scoped override from before the fix.
                'active' => Feature::for(null)->active($meta['class']),
            ];
        }

        return view('admin.feature-flags.index', ['flags' => $flags]);
    }

    public function toggle(Request $request, string $key): RedirectResponse
    {
        $registry = $this->registry();
        abort_unless(isset($registry[$key]), 404);

        $class = $registry[$key]['class'];
        // Read against global scope (null) — without this, Pennant defaults to
        // the current authenticated user, so the admin saw their own override
        // instead of the global state that registration (unauthenticated) sees.
        $before = Feature::for(null)->active($class);
        $action = $request->input('action');
        abort_unless(in_array($action, ['activate', 'deactivate'], true), 422);

        // Admin-toggleable flags must affect ALL users, including unauthenticated
        // visitors on the registration wizard. Pennant defaults to the currently
        // authenticated user's scope; without `for(null)` we'd store an override
        // scoped to the admin alone, leaving the global default untouched.
        if ($action === 'activate') {
            Feature::for(null)->activate($class);
        } else {
            Feature::for(null)->deactivate($class);
        }

        $after = Feature::for(null)->active($class);

        AuditLog::create([
            'actor_id' => auth()->id(),
            'action' => 'feature_flag.toggled',
            'subject_type' => 'feature_flag',
            'subject_id' => null,
            'details' => [
                'flag' => $key,
                'class' => $class,
                'from' => $before,
                'to' => $after,
            ],
            'ip' => $request->ip(),
        ]);

        return back()->with('status', sprintf('Feature %s set to %s.', $key, $after ? 'active' : 'inactive'));
    }
}
