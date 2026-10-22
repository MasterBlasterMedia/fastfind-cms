<?php

namespace App\Events;

use App\Models\Page;
use Illuminate\Foundation\Events\Dispatchable;

final class PageChanged
{
    use Dispatchable;

    public function __construct(public readonly Page $page, public readonly string $action) {}
}
