<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class UserManager
{
    public function update(int $actorId, int $userId, array $data): User
    {
        return DB::transaction(function () use ($actorId, $userId, $data) {
            // Stable lock order also serializes cross-demotions by two different owners.
            $users = User::orderBy('id')->lockForUpdate()->get();
            $actor = $users->find($actorId);
            abort_unless($actor?->is_active && $actor->role === 'owner', 403);
            $user = $users->find($userId);
            abort_unless($user, 404);
            if ($actorId === $userId && ($data['role'] !== 'owner' || !$data['is_active'])) {
                throw ValidationException::withMessages(['role' => 'You cannot deactivate or demote your own owner account.']);
            }
            if ($user->role === 'owner' && $user->is_active && ($data['role'] !== 'owner' || !$data['is_active'])
                && $users->where('role', 'owner')->where('is_active', true)->count() <= 1) {
                throw ValidationException::withMessages(['role' => 'There must be at least one active owner.']);
            }
            $user->update($data);
            AuditEvent::create(['user_id' => $actorId, 'action' => 'user.updated', 'subject' => 'user', 'subject_id' => $user->id]);
            return $user;
        }, 3);
    }
}
