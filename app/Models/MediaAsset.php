<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class MediaAsset extends Model
{
    protected $fillable = ['filename', 'path', 'thumbnail_path', 'mime', 'size', 'width', 'height', 'alt', 'uploaded_by'];
    protected $appends = ['url', 'thumbnail_url'];
    public function getUrlAttribute(): string { return \Illuminate\Support\Facades\Storage::disk('public')->url($this->path); }
    public function getThumbnailUrlAttribute(): string { return \Illuminate\Support\Facades\Storage::disk('public')->url($this->thumbnail_path ?: $this->path); }
}
