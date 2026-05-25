<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('line_change_requests has placement-named columns', function () {
    expect(Schema::hasColumn('line_change_requests', 'from_placement_parent_id'))->toBeTrue();
    expect(Schema::hasColumn('line_change_requests', 'to_placement_parent_id'))->toBeTrue();
    expect(Schema::hasColumn('line_change_requests', 'chosen_side'))->toBeTrue();
    expect(Schema::hasColumn('line_change_requests', 'reviewed_by'))->toBeTrue();
    expect(Schema::hasColumn('line_change_requests', 'reviewed_at'))->toBeTrue();
    expect(Schema::hasColumn('line_change_requests', 'decision_note'))->toBeTrue();
    expect(Schema::hasColumn('line_change_requests', 'from_sponsor_id'))->toBeFalse();
    expect(Schema::hasColumn('line_change_requests', 'to_sponsor_id'))->toBeFalse();

    $fkColumns = collect(Schema::getForeignKeys('line_change_requests'))
        ->flatMap(fn ($fk) => $fk['columns'])
        ->all();
    expect($fkColumns)->toContain('reviewed_by');
});
