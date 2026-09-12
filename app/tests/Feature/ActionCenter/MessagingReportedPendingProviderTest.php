<?php

declare(strict_types=1);

use App\Modules\ActionCenter\Models\ActionCenterSnooze;
use App\Modules\ActionCenter\Providers\Compliance\MessagingReportedPendingProvider;
use App\Modules\Messaging\Models\MessageReport;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
    $this->provider = app(MessagingReportedPendingProvider::class);
});

/** @param array<string, mixed> $overrides */
function messageReport(string $status, array $overrides = []): MessageReport
{
    return MessageReport::create(array_merge([
        'message_id' => random_int(100000, 999999),
        'reported_by_user_id' => 1,
        'category' => 'income_claim',
        'status' => $status,
    ], $overrides));
}

it('counts an open report and ignores a reviewed one', function (): void {
    $open = messageReport(MessageReport::STATUS_OPEN);
    messageReport(MessageReport::STATUS_REVIEWED);

    expect($this->provider->count())->toBe(1);
    expect($this->provider->items()->first()->subjectId)->toBe($open->id);
});

it('ignores an actioned report', function (): void {
    messageReport(MessageReport::STATUS_ACTIONED);

    expect($this->provider->count())->toBe(0);
});

it('excludes a snoozed report', function (): void {
    $report = messageReport(MessageReport::STATUS_OPEN);

    ActionCenterSnooze::create([
        'action_key' => 'messaging.reported_pending',
        'subject_type' => 'message_report',
        'subject_id' => $report->id,
        'snoozed_until' => now()->addDays(2),
        'reason' => 'Awaiting a second opinion from compliance.',
    ]);

    expect($this->provider->count())->toBe(0);
});
