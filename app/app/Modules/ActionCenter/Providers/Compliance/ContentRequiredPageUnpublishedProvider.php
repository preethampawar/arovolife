<?php

declare(strict_types=1);

namespace App\Modules\ActionCenter\Providers\Compliance;

use App\Modules\ActionCenter\Providers\AbstractProvider;
use App\Modules\ActionCenter\Support\ActionGroup;
use App\Modules\ActionCenter\Support\ActionItem;
use App\Modules\ActionCenter\Support\Severity;
use App\Modules\Content\Models\ContentPage;
use Illuminate\Support\Collection;

/**
 * A consent-linked content page (terms, ethics, compensation plan, privacy —
 * `ConsentDocuments::types()`) that is not published (plan §4, Compliance).
 * `ConsentDocuments::all()` refuses to record a consent when one of these is
 * missing, which blocks registration outright — so this is statutory: no
 * manager may silence a page that registration itself cannot proceed
 * without (plan §5).
 */
final class ContentRequiredPageUnpublishedProvider extends AbstractProvider
{
    /**
     * The four slugs `ConsentDocuments::PAGES` maps consent types to. Kept
     * here rather than importing that private constant — the provider only
     * needs the slugs, not the consent-type keys.
     *
     * @var array<int, string>
     */
    private const REQUIRED_SLUGS = ['terms', 'ethics', 'compensation', 'privacy'];

    public function key(): string
    {
        return 'content.required_page_unpublished';
    }

    public function group(): string
    {
        return ActionGroup::COMPLIANCE;
    }

    public function label(): string
    {
        return 'Required consent page unpublished';
    }

    public function description(): string
    {
        return 'A page a joiner must consent to is not published, which blocks registration. Publish it from the content editor.';
    }

    public function permission(): string
    {
        return 'content.publish';
    }

    public function severity(): string
    {
        return Severity::CRITICAL;
    }

    public function statutory(): bool
    {
        return true;
    }

    public function subjectType(): string
    {
        return 'content_page';
    }

    public function targetRoute(): string
    {
        return 'admin.content.index';
    }

    public function count(): int
    {
        return count($this->missingSlugs());
    }

    /** @return Collection<int, ActionItem> */
    public function items(int $limit = 50): Collection
    {
        return collect($this->missingSlugs())
            ->take($limit)
            ->map(function (string $slug): ActionItem {
                return new ActionItem(
                    subjectType: $this->subjectType(),
                    subjectId: crc32($slug),
                    title: ucfirst($slug),
                    subtitle: 'Not published — registration consent cannot be recorded',
                    occurredAt: now(),
                    dueAt: null,
                    severity: $this->severity(),
                    url: route($this->targetRoute()),
                    meta: ['slug' => $slug],
                );
            })
            ->values();
    }

    /** @return array<int, string> */
    private function missingSlugs(): array
    {
        $published = ContentPage::query()
            ->whereIn('content_pages.slug', self::REQUIRED_SLUGS)
            ->where('content_pages.status', ContentPage::STATUS_PUBLISHED)
            ->pluck('content_pages.slug')
            ->all();

        return array_values(array_diff(self::REQUIRED_SLUGS, $published));
    }
}
