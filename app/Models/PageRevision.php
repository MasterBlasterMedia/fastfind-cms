<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PageRevision extends Model
{
    protected $fillable = ['page_id', 'version', 'snapshot', 'user_id'];
    protected $casts = ['snapshot' => 'array'];
}
