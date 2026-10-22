<?php

namespace App\Policies;

use App\Models\Page;
use App\Models\User;

final class PagePolicy
{
    public function viewAny(User $user): bool { return $user->is_active; }
    public function view(User $user, Page $page): bool { return $this->viewAny($user); }
    public function create(User $user): bool { return $user->is_active && in_array($user->role, ['owner', 'admin', 'editor'], true); }
    public function update(User $user, Page $page): bool { return $this->create($user); }
    public function publish(User $user, Page $page): bool { return $this->create($user); }
    public function delete(User $user, Page $page): bool { return $this->create($user); }
}
