<?php

namespace App\Services;

use App\Models\Page;
use Illuminate\Validation\Rule;

final class PageDataRules
{
    public static function for(Page $page): array
    {
        return [
            'title' => ['required', 'string', 'max:180'],
            'slug' => ['required', 'string', 'max:200', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('pages', 'slug')->ignore($page->id)],
            'summary' => ['nullable', 'string', 'max:500'],
            'seo_title' => ['nullable', 'string', 'max:180'],
            'seo_description' => ['nullable', 'string', 'max:320'],
            'show_in_search' => ['required', 'boolean'], 'document' => ['required', 'array'],
            'extensions' => ['nullable', 'array', 'max:30'],
        ];
    }
}
