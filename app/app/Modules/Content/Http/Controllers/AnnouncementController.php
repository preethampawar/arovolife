<?php

declare(strict_types=1);

namespace App\Modules\Content\Http\Controllers;

use App\Modules\Content\Models\Announcement;
use App\Modules\Content\Services\AnnouncementService;
use App\Modules\Shared\Features\AnnouncementsFeature;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Laravel\Pennant\Feature;

/**
 * What a distributor sees of the company's announcements.
 *
 * Reading one marks it read; there is no "mark all as read" action. The read
 * record is what the announcements list uses to tell a distributor what is new
 * to them, and a button that clears the lot would make that useless on the one
 * occasion it matters — the announcement they had not got to yet.
 */
final class AnnouncementController extends Controller
{
    public function index(AnnouncementService $service): View
    {
        $this->assertEnabled();

        $user = Auth::user();
        abort_if($user === null, 401);

        return view('announcements.index', [
            'announcements' => $service->forUser($user),
            'readIds' => $service->readIdsFor($user),
        ]);
    }

    public function show(Announcement $announcement, AnnouncementService $service): View
    {
        $this->assertEnabled();

        $user = Auth::user();
        abort_if($user === null, 401);

        // Membership is decided by the same query the list uses, so a
        // distributor cannot read an announcement addressed to a rank or a
        // status they do not hold by guessing its id.
        $addressed = $service->forUser($user)->contains(
            static fn (Announcement $candidate): bool => (int) $candidate->id === (int) $announcement->id,
        );

        abort_unless($addressed, 404);

        $service->markRead($user, $announcement);

        return view('announcements.show', ['announcement' => $announcement]);
    }

    private function assertEnabled(): void
    {
        abort_unless(Feature::for(null)->active(AnnouncementsFeature::class), 404);
    }
}
