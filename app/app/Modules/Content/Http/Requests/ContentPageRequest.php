<?php

declare(strict_types=1);

namespace App\Modules\Content\Http\Requests;

use App\Modules\Consent\Services\PlanChangeNotices;
use App\Modules\Content\Models\ContentPage;
use App\Modules\Shared\Features\FaqLibraryFeature;
use App\Modules\Shared\Rules\NoIncomeProjection;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Laravel\Pennant\Feature;

final class ContentPageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && $this->user()->isSuperStaff();
    }

    /**
     * A blank sort order means "no particular order", which is 0 — not NULL.
     * The column is `unsignedSmallInteger default 0` and not nullable, so
     * letting the empty string through as null turned an ordinary save of an
     * untyped page into a 500 on the insert. Normalised here rather than in
     * the controller so both store() and update() get it.
     */
    protected function prepareForValidation(): void
    {
        // Only when the field was actually on the form: the FAQ fields are not
        // rendered while the library is off, and an absent key must stay absent
        // so saving a page from that form doesn't reset a stored order to 0.
        if ($this->has('sort_order') && in_array($this->input('sort_order'), [null, ''], true)) {
            $this->merge(['sort_order' => 0]);
        }
    }

    public function rules(): array
    {
        $id = $this->route('page')?->id;

        $copyRules = $this->auditsCopy() ? [new NoIncomeProjection] : [];
        $editingPlan = $this->isStoredPlanPage();

        return [
            'title' => ['required', 'string', 'max:200', ...$copyRules],
            // The plan page's slug is fixed: renaming it would take it out of
            // the second-person approval (R-125) and off /p/compensation.
            'slug' => $editingPlan
                ? ['required', Rule::in([PlanChangeNotices::SLUG])]
                : ['required', 'string', 'max:120', 'regex:/^[a-z0-9-]+$/', Rule::unique('content_pages', 'slug')->ignore($id)],
            // Read by whoever approves the change (R-125).
            'change_note' => $editingPlan ? ['required', 'string', 'max:1000'] : ['prohibited'],
            // 'faq' is offered only while the library is live: a draft
            // authored against a surface nobody can reach is a draft that gets
            // forgotten and published later by accident.
            'type' => ['nullable', Rule::in($this->allowedTypes())],
            'category' => ['nullable', 'string', 'max:80'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'meta_description' => ['nullable', 'string', 'max:300'],
            'body' => ['nullable', 'string', 'max:100000', ...$copyRules],
            'status' => ['required', Rule::in([ContentPage::STATUS_DRAFT, ContentPage::STATUS_PUBLISHED, ContentPage::STATUS_ARCHIVED])],
        ];
    }

    /**
     * Does this page's copy have to pass the income-projection rule?
     *
     * Yes for every *typed* page — faq, blog, news, seminar, hub. All of them
     * are copy an administrator writes in a textarea and publishes without a
     * code review, which is the whole reason the rule exists.
     *
     * No for the untyped pages other than the plan, and that exemption is
     * narrow and deliberate:
     * `type === null` is exactly the policy/legal set — the Code of Ethics,
     * the Privacy Policy, the T&C — whose bodies *quote* the banned phrases in
     * order to forbid them ("Phrases such as … 'passive income' … are
     * prohibited in every medium"). A blanket rule would make the Code of
     * Ethics uneditable, which is the false positive {@see NoIncomeProjection}
     * warns about in its own docblock.
     *
     * The type is resolved as submitted-or-stored, treating a blank submission
     * as absent. That matters: the editor's type <select> can post an empty
     * string, and reading it literally would let the ordinary act of saving an
     * existing page skip the rule on its body.
     */
    private function auditsCopy(): bool
    {
        return $this->resolvedType() !== null || $this->isPlanPage();
    }

    /**
     * The Compensation Plan is the one untyped page held to the rule too.
     * From 2026-10-26 administrators change it in the portal with no code
     * review (R-125), and it is the page a projection would do most harm on;
     * its published text passes the rule as it stands.
     */
    private function isPlanPage(): bool
    {
        $page = $this->route('page');

        return $this->input('slug') === PlanChangeNotices::SLUG
            || ($page instanceof ContentPage && $page->slug === PlanChangeNotices::SLUG);
    }

    /** Is the page being saved the stored plan page, whatever slug was submitted? */
    private function isStoredPlanPage(): bool
    {
        $page = $this->route('page');

        return $page instanceof ContentPage && $page->slug === PlanChangeNotices::SLUG;
    }

    /**
     * The type this save is about: what was submitted if anything was, else
     * what the page already is.
     */
    private function resolvedType(): ?string
    {
        $submitted = $this->input('type');

        if (is_string($submitted) && $submitted !== '') {
            return $submitted;
        }

        $page = $this->route('page');

        return $page instanceof ContentPage ? $page->type : null;
    }

    /**
     * @return list<string>
     */
    private function allowedTypes(): array
    {
        $types = ['blog', 'seminar', 'news', 'hub'];

        if (Feature::for(null)->active(FaqLibraryFeature::class)) {
            $types[] = ContentPage::TYPE_FAQ;
        }

        return $types;
    }

    public function messages(): array
    {
        return [
            'slug.regex' => 'Slug must be lowercase letters, numbers and hyphens only.',
            'slug.in' => 'The Compensation Plan\'s address cannot be changed.',
            'change_note.required' => 'Say what you changed and why, for the person who approves it.',
        ];
    }
}
