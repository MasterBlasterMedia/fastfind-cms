<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class NavigationItem extends Model
{
    protected $fillable = ['label', 'url', 'position', 'visible'];
    protected $casts = ['visible' => 'boolean'];
}
