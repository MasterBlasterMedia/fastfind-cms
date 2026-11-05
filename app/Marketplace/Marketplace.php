<?php

namespace App\Marketplace;

use App\Models\PluginInstallation;
use App\Models\User;
use App\Plugins\Manifest;
use App\Plugins\PackageInstaller;
use App\Services\ThemeManager;
use App\Themes\ThemeManifest;
use App\Themes\ThemePackageInstaller;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use ZipArchive;

final class Marketplace
{
    public function __construct(private RemoteFetcher $fetcher, private Manifest $plugins, private ThemeManifest $themes, private ThemeManager $themeManager) {}

    public function listing(int $actor): array
    {
        Gate::forUser(User::findOrFail($actor))->authorize('manage-plugins');
        $catalog = $this->catalog();
        return [...array_diff_key($catalog, ['entries' => true]), 'items' => array_map(fn ($entry) => $entry['display'], $catalog['entries'])];
    }

    public function install(array $selection, int $actor): array
    {
        Gate::forUser(User::findOrFail($actor))->authorize('manage-plugins');
        $catalog = $this->catalog();
        $entry = collect($catalog['entries'])->first(fn ($entry) => $entry['display']['type'] === ($selection['type'] ?? null)
            && $entry['manifest']['id'] === ($selection['id'] ?? null) && $entry['manifest']['version'] === ($selection['version'] ?? null));
        if (!$entry || !is_string($selection['sha256'] ?? null) || !hash_equals($entry['download']['sha256'], $selection['sha256'])) $this->fail('This marketplace release changed or is no longer listed. Refresh the catalog before installing.');
        if ($entry['display']['issues']) $this->fail(implode(' ', $entry['display']['issues']));
        $archive = null;
        try {
            $limit = min((int) config('marketplace.max_package_bytes'), (int) config($selection['type'] === 'plugin' ? 'plugins.max_archive_bytes' : 'themes.max_archive_bytes'));
            $archive = $this->fetcher->download($entry['download']['url'], $limit);
            if (!hash_equals($entry['download']['sha256'], hash_file('sha256', $archive))) $this->fail('Downloaded package integrity verification failed.');
            $publicKey = base64_decode(config('plugins.publishers', [])[$entry['publisher']] ?? '', true);
            if ($publicKey === false || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
                || !sodium_crypto_sign_verify_detached(base64_decode($entry['download']['signature'], true), file_get_contents($archive), $publicKey)) $this->fail('Downloaded package signature verification failed.');
            $this->checkIdentity($archive, $entry);
            $installer = $selection['type'] === 'plugin' ? app(PackageInstaller::class) : app(ThemePackageInstaller::class);
            $installer->install($archive, $entry['publisher'], $entry['download']['signature'], $entry['download']['sha256'], $actor);
            return ['type' => $selection['type'], 'id' => $entry['manifest']['id'], 'version' => $entry['manifest']['version'], 'status' => 'installed',
                'message' => $selection['type'] === 'plugin' ? 'Package installed. Activate it from Plugins when ready.' : 'Theme installed. Apply it from Themes when ready.'];
        } finally {
            if ($archive !== null) @unlink($archive);
        }
    }

    private function catalog(): array
    {
        $siteUrl = config('marketplace.site_url');
        if (is_string($siteUrl) && $siteUrl !== '') $this->fetcher->validateUrl($siteUrl);
        else $siteUrl = null;
        if (!$url = config('marketplace.url')) return ['configured' => false, 'source' => 'unconfigured', 'name' => 'FastFind marketplace', 'site_url' => $siteUrl,
            'message' => 'A marketplace is not connected yet. Configure its catalog address and approved hosts to browse releases.', 'entries' => []];
        try { $data = json_decode($this->fetcher->read($url, (int) config('marketplace.max_catalog_bytes')), true, 32, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { $this->fail('Marketplace catalog is not valid JSON.'); }
        if (!is_array($data) || ($data['schema_version'] ?? null) !== 1 || !is_string($data['name'] ?? null) || trim($data['name']) === '' || strlen($data['name']) > 100
            || !is_array($data['plugins'] ?? null) || !array_is_list($data['plugins']) || !is_array($data['themes'] ?? null) || !array_is_list($data['themes'])
            || count($data['plugins']) + count($data['themes']) > 200) $this->fail('Marketplace catalog does not match schema version 1.');
        $entries = []; $seen = [];
        $installedThemes = collect($this->themeManager->all())->keyBy('id');
        $activeTheme = $this->themeManager->current()['active'];
        foreach (['plugin' => $data['plugins'], 'theme' => $data['themes']] as $type => $releases) {
            foreach ($releases as $raw) {
                if (!is_array($raw) || !is_array($raw['manifest'] ?? null) || !is_string($raw['publisher'] ?? null)
                    || !preg_match('/^[a-z][a-z0-9-]{1,79}$/D', $raw['publisher']) || !is_array($raw['download'] ?? null)) $this->fail('Invalid marketplace release metadata.');
                $manifest = ($type === 'plugin' ? $this->plugins : $this->themes)->validate($raw['manifest']);
                $key = $type.':'.$manifest['id'];
                if (isset($seen[$key])) $this->fail('The catalog must list only one release per package ID and type.');
                $seen[$key] = true;
                $download = $raw['download'];
                if (!is_string($download['url'] ?? null) || !is_string($download['sha256'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $download['sha256'])
                    || !is_string($download['signature'] ?? null) || strlen($download['signature']) > 100 || strlen(base64_decode($download['signature'], true) ?: '') !== SODIUM_CRYPTO_SIGN_BYTES) $this->fail('Each release needs a bounded URL, SHA-256 digest and Ed25519 signature.');
                $this->fetcher->validateUrl($download['url']);
                $issues = ($type === 'plugin' ? $this->plugins : $this->themes)->issues($manifest);
                $keyBytes = base64_decode(config('plugins.publishers', [])[$raw['publisher']] ?? '', true);
                if ($keyBytes === false || strlen($keyBytes) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) $issues[] = 'Publisher is not trusted by this installation.';
                $record = $type === 'plugin' ? PluginInstallation::find($manifest['id']) : $installedThemes->get($manifest['id']);
                $installedVersion = $type === 'plugin' ? $record?->installed_version : ($record['version'] ?? null);
                if ($installedVersion !== null && !version_compare($manifest['version'], $installedVersion, '>')) $issues[] = 'This version or a newer version is already installed.';
                if ($type === 'plugin' && $record?->status === 'active') $issues[] = 'Deactivate the plugin before upgrading.';
                if ($type === 'theme' && $record && ($record['source'] ?? 'bundled') === 'bundled') $issues[] = 'Bundled themes cannot be replaced by downloaded packages.';
                if ($type === 'theme' && $record && $activeTheme === $manifest['id']) $issues[] = 'Apply a different theme before upgrading this theme.';
                $display = ['type' => $type, 'id' => $manifest['id'], 'name' => $manifest['name'], 'description' => $manifest['description'],
                    'version' => $manifest['version'], 'requires' => $manifest['requires'], 'publisher' => $raw['publisher'], 'sha256' => $download['sha256'],
                    'installed_version' => $installedVersion, 'can_install' => $issues === [], 'issues' => $issues];
                $entries[] = ['display' => $display, 'manifest' => $manifest, 'publisher' => $raw['publisher'], 'download' => $download];
            }
        }
        return ['configured' => true, 'source' => 'remote', 'name' => $data['name'], 'site_url' => $siteUrl, 'message' => null, 'entries' => $entries];
    }

    private function checkIdentity(string $archive, array $entry): void
    {
        $zip = new ZipArchive;
        if ($zip->open($archive, ZipArchive::RDONLY) !== true) $this->fail('The downloaded release is not a ZIP package.');
        try {
            $name = $entry['display']['type'] === 'plugin' ? 'plugin.json' : 'theme.json';
            $stat = $zip->statName($name);
            if (!$stat || $stat['size'] > 65536 || $stat['size'] < 2) $this->fail('The downloaded package has no bounded root manifest.');
            $raw = $zip->getFromName($name, 65537);
            if ($raw === false || strlen($raw) !== $stat['size']) $this->fail('The downloaded package manifest could not be read.');
            try { $manifest = json_decode($raw, true, 32, JSON_THROW_ON_ERROR); }
            catch (\JsonException) { $this->fail('The downloaded package manifest is invalid.'); }
            if (!is_array($manifest) || ($manifest['id'] ?? null) !== $entry['manifest']['id'] || ($manifest['version'] ?? null) !== $entry['manifest']['version']) $this->fail('Downloaded package identity or version does not match the selected catalog release.');
            $validated = ($entry['display']['type'] === 'plugin' ? $this->plugins : $this->themes)->validate($manifest);
            if ($validated != $entry['manifest']) $this->fail('Downloaded package manifest does not match its catalog description.');
        } finally { $zip->close(); }
    }

    private function fail(string $message): never { throw ValidationException::withMessages(['marketplace' => $message]); }
}
