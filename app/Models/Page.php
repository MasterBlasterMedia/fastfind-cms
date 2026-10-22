<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Page extends Model
{
    protected $attributes = ['version' => 1, 'status' => 'draft', 'show_in_search' => true, 'published_in_search' => true, 'extensions' => '[]'];
    protected $fillable = ['title', 'slug', 'published_slug', 'summary', 'status', 'document', 'extensions', 'published_snapshot', 'published_search_text', 'seo_title', 'seo_description', 'show_in_search', 'published_in_search', 'published_at', 'scheduled_at', 'author_id', 'updated_by'];
    protected $casts = ['document' => 'array', 'extensions' => 'array', 'published_snapshot' => 'array', 'show_in_search' => 'boolean', 'published_in_search' => 'boolean', 'published_at' => 'datetime', 'scheduled_at' => 'datetime', 'version' => 'integer'];

    public function revisions(): HasMany { return $this->hasMany(PageRevision::class); }

    public function publishedView(): array
    {
        return $this->published_snapshot ?? [];
    }
}
