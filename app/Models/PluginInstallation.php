<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PluginInstallation extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';
    protected $guarded = [];
    protected $casts = ['manifest' => 'array', 'files' => 'array', 'settings' => 'array'];
}
