<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class MediaAsset extends Model
{
    protected $fillable = ['filename', 'path', 'mime', 'size', 'alt', 'uploaded_by'];
    protected $appends = ['url'];
    public function getUrlAttribute(): string { return \Illuminate\Support\Facades\Storage::disk('public')->url($this->path); }
}
