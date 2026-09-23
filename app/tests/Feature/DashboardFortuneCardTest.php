<?php

declare(strict_types=1);

/**
 * The dashboard's Fortune Bonus card follows the engine flag: present with the
 * flag on, and no trace of it at all with the flag off.
 */

use App\Modules\Identity\Models\Distributor;
use App\Modules\Shared\Features\FortuneBonusFeature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;

uses(RefreshDatabase::class);

function fbCardDistributor(): Distributor
{
    disableTestForeignKeys();
    try {
        $distributor = Distributor::factory()->create();
    } finally {
        enableTestForeignKeys();
    }
    DB::table('genealogy_closure')->insert(['ancestor_id' => $distributor->id, 'descendant_id' => $distributor->id, 'depth' => 0]);

    return $distributor;
}

it('shows the Fortune Bonus card to the distributor while the flag is on', function (): void {
    Feature::for(null)->activate(FortuneBonusFeature::class);

    $distributor = fbCardDistributor();

    $this->actingAs($distributor->user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Fortune Bonus', false)
        ->assertSee('Your tier', false)
        ->assertSee('You did not take part last month.', false);
});

it('leaves no trace of Fortune Bonus on the dashboard while the flag is off', function (): void {
    Feature::for(null)->deactivate(FortuneBonusFeature::class);

    $distributor = fbCardDistributor();

    $this->actingAs($distributor->user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee('Fortune Bonus', false)
        ->assertDontSee('Your tier', false);
});
