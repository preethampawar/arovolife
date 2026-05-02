<?php

declare(strict_types=1);

namespace App\Modules\Content\Http\Requests;

use App\Modules\Content\Models\ContentPage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ContentPageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && $this->user()->hasRole('admin');
    }

    public function rules(): array
    {
        $id = $this->route('page')?->id;

        return [
            'title' => ['required', 'string', 'max:200'],
            'slug' => ['required', 'string', 'max:120', 'regex:/^[a-z0-9-]+$/', Rule::unique('content_pages', 'slug')->ignore($id)],
            'meta_description' => ['nullable', 'string', 'max:300'],
            'body' => ['nullable', 'string', 'max:100000'],
            'status' => ['required', Rule::in([ContentPage::STATUS_DRAFT, ContentPage::STATUS_PUBLISHED, ContentPage::STATUS_ARCHIVED])],
        ];
    }

    public function messages(): array
    {
        return [
            'slug.regex' => 'Slug must be lowercase letters, numbers and hyphens only.',
        ];
    }
}
