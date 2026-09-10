<?php

declare(strict_types=1);

namespace App\Modules\Content\Http\Requests;

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

    public function rules(): array
    {
        $id = $this->route('page')?->id;

        $copyRules = $this->auditsCopy() ? [new NoIncomeProjection] : [];

        return [
            'title' => ['required', 'string', 'max:200', ...$copyRules],
            'slug' => ['required', 'string', 'max:120', 'regex:/^[a-z0-9-]+$/', Rule::unique('content_pages', 'slug')->ignore($id)],
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
     * No for the untyped pages, and that exemption is narrow and deliberate:
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
        return $this->resolvedType() !== null;
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
        ];
    }
}
