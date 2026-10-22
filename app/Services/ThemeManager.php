<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\SiteSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Themes are reviewed data manifests; the public renderer interprets only safe design tokens. */
final class ThemeManager
{
    public function all(): array
    {
        $themes = [];
        foreach (glob(resource_path('themes/*/theme.json')) ?: [] as $manifest) {
            $theme = json_decode(file_get_contents($manifest), true, 32, JSON_THROW_ON_ERROR);
            $themes[] = $theme;
        }
        return $themes;
    }

    public function current(): array
    {
        $selection = SiteSetting::find('theme')?->value ?? ['id' => 'professional', 'settings' => []];
        $theme = collect($this->all())->firstWhere('id', $selection['id']) ?? collect($this->all())->firstWhere('id', 'professional');
        return ['active' => $theme['id'], 'settings' => [...$theme['defaults'], ...($selection['settings'] ?? [])], 'theme' => $theme];
    }

    public function activate(string $id, array $settings, int $userId): array
    {
        Gate::forUser(User::findOrFail($userId))->authorize('manage-site');
        $theme = collect($this->all())->firstWhere('id', $id);
        if (!$theme) throw ValidationException::withMessages(['id' => 'Unknown theme.']);
        if (array_diff(array_keys($settings), ['accent', 'font', 'width'])) throw ValidationException::withMessages(['settings' => 'Unsupported theme setting.']);
        $settings = Validator::make([...$theme['defaults'], ...$settings], [
            'accent' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'font' => ['required', Rule::in(['sans', 'serif'])],
            'width' => ['required', Rule::in(['normal', 'wide'])],
        ])->validate();
        DB::transaction(function () use ($id, $settings, $userId) {
            SiteSetting::updateOrCreate(['key' => 'theme'], ['value' => ['id' => $id, 'settings' => $settings]]);
            AuditEvent::create(['user_id' => $userId, 'action' => 'theme.activated', 'subject' => 'theme', 'context' => ['id' => $id]]);
        });
        return $this->current();
    }
}
