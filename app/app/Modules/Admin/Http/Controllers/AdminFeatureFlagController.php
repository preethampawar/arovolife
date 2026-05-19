<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers;

use App\Modules\Compliance\Models\AuditLog;
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
        ];
    }

    public function index(): View
    {
        $flags = [];
        foreach ($this->registry() as $key => $meta) {
            $flags[$key] = $meta + [
                'active' => Feature::active($meta['class']),
            ];
        }

        return view('admin.feature-flags.index', ['flags' => $flags]);
    }

    public function toggle(Request $request, string $key): RedirectResponse
    {
        $registry = $this->registry();
        abort_unless(isset($registry[$key]), 404);

        $class = $registry[$key]['class'];
        $before = Feature::active($class);
        $action = $request->input('action');
        abort_unless(in_array($action, ['activate', 'deactivate'], true), 422);

        if ($action === 'activate') {
            Feature::activate($class);
        } else {
            Feature::deactivate($class);
        }

        $after = Feature::active($class);

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
