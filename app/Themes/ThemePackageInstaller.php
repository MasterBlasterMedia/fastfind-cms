<?php

namespace App\Themes;

use App\Models\AuditEvent;
use App\Models\SiteSetting;
use App\Models\User;
use App\Services\ThemeManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use ZipArchive;

/** Themes install data only; uploaded styles or executable templates are never accepted. */
final class ThemePackageInstaller
{
    public const FILES = ['theme.json', 'README.md', 'LICENSE.txt'];

    public function __construct(private ThemeManifest $manifests, private ThemeManager $themes) {}

    public function install(string $archive, string $publisher, string $signature, string $sha256, int $actor): array
    {
        Gate::forUser(User::findOrFail($actor))->authorize('manage-plugins');
        $staging = null;
        try {
            if (!preg_match('/^[a-z][a-z0-9-]{1,79}$/D', $publisher)) $this->fail('Invalid publisher ID.');
            $key = config('plugins.publishers.'.$publisher);
            if (!is_string($key)) $this->fail('Publisher is not explicitly trusted by this installation.');
            if (!is_file($archive) || filesize($archive) > config('themes.max_archive_bytes')) $this->fail('Theme archive exceeds the upload limit.');
            $bytes = file_get_contents($archive);
            if (!preg_match('/^[a-f0-9]{64}$/D', $sha256) || !hash_equals($sha256, hash('sha256', $bytes))) $this->fail('Archive integrity verification failed.');
            $publicKey = base64_decode($key, true);
            $detached = base64_decode($signature, true);
            if ($publicKey === false || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES || $detached === false || strlen($detached) !== SODIUM_CRYPTO_SIGN_BYTES || !sodium_crypto_sign_verify_detached($detached, $bytes, $publicKey)) $this->fail('Archive signature verification failed.');
            $zip = new ZipArchive;
            if ($zip->open($archive, ZipArchive::RDONLY) !== true) $this->fail('Invalid ZIP archive.');
            try { $files = $this->inspect($zip); }
            finally { $zip->close(); }
            try { $raw = json_decode($files['theme.json'], true, 16, JSON_THROW_ON_ERROR); }
            catch (\JsonException) { $this->fail('The theme manifest is not valid JSON.'); }
            if (!is_array($raw)) $this->fail('The theme manifest must be an object.');
            $manifest = $this->manifests->validate($raw);
            if ($issues = $this->manifests->issues($manifest)) $this->fail(implode(' ', $issues));

            return Cache::lock('fastfind:theme-state', 30)->block(5, function () use ($manifest, $files, $publisher, $sha256, $actor, &$staging) {
                if (is_dir(config('themes.bundled_path').'/'.$manifest['id']) || collect($this->themes->bundled())->contains('id', $manifest['id'])) $this->fail('A package cannot replace a bundled theme.');
                $installed = SiteSetting::find('theme_packages')?->value ?? [];
                if (!is_array($installed)) $this->fail('The installed theme registry needs repair before installing packages.');
                $previous = $installed[$manifest['id']] ?? null;
                $selection = SiteSetting::find('theme')?->value;
                if ((is_array($selection) && ($selection['id'] ?? null) === $manifest['id']) || $this->themes->current()['active'] === $manifest['id']) $this->fail('Switch to another theme before installing or upgrading this theme.');
                if (array_key_exists($manifest['id'], $installed)) {
                    if (!is_array($previous) || !is_array($previous['manifest'] ?? null) || !is_string($previous['publisher'] ?? null) || !is_string($previous['manifest']['version'] ?? null)) $this->fail('The installed theme record needs repair before upgrading.');
                    if ($previous['publisher'] !== $publisher) $this->fail('Only the installed theme publisher can provide its upgrade.');
                    if (!version_compare($manifest['version'], $previous['manifest']['version'], '>')) $this->fail('Theme upgrades require a newer version.');
                }
                $root = config('themes.package_path');
                File::ensureDirectoryExists($root, 0700);
                $staging = $root.'/'.$manifest['id'].'-'.$manifest['version'].'-'.Str::uuid();
                File::makeDirectory($staging, 0700, true);
                foreach ($files as $name => $contents) {
                    $target = $staging.'/'.$name;
                    if (file_put_contents($target, $contents, LOCK_EX) !== strlen($contents)) $this->fail('Could not store theme package.');
                    chmod($target, 0600);
                }
                $installed[$manifest['id']] = ['manifest' => $manifest, 'publisher' => $publisher, 'sha256' => $sha256, 'path' => $staging];
                DB::transaction(function () use ($installed, $manifest, $publisher, $actor, $previous) {
                    SiteSetting::updateOrCreate(['key' => 'theme_packages'], ['value' => $installed]);
                    AuditEvent::create(['user_id' => $actor, 'action' => $previous ? 'theme.upgraded' : 'theme.installed', 'subject' => 'theme',
                        'context' => ['id' => $manifest['id'], 'version' => $manifest['version'], 'publisher' => $publisher, 'previous_path' => $previous['path'] ?? null]]);
                });
                $staging = null; // Immutable releases and previous files remain available for operator recovery.
                return [...$manifest, 'source' => 'package', 'publisher' => $publisher, 'installed' => true];
            });
        } catch (\Throwable $error) {
            AuditEvent::create(['user_id' => $actor, 'action' => 'theme.package_rejected', 'subject' => 'theme', 'context' => ['publisher' => substr($publisher, 0, 80), 'exception' => $error::class]]);
            if ($error instanceof ValidationException) throw $error;
            $this->fail('Theme package could not be installed.');
        } finally {
            if ($staging && is_dir($staging)) File::deleteDirectory($staging);
        }
    }

    private function inspect(ZipArchive $zip): array
    {
        if ($zip->numFiles < 1 || $zip->numFiles > count(self::FILES)) $this->fail('Theme packages contain only theme.json and optional README.md/LICENSE.txt.');
        $files = []; $expanded = 0;
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $entry = $zip->statIndex($index);
            $name = $entry['name'];
            if (!in_array($name, self::FILES, true) || isset($files[$name])) $this->fail('Unknown, duplicate or unsafe theme archive path.');
            $zip->getExternalAttributesIndex($index, $os, $attributes);
            if (!in_array(($attributes >> 16) & 0170000, [0, 0100000], true)) $this->fail('Theme archives cannot contain symlinks or special entries.');
            if (($entry['encryption_method'] ?? 0) !== 0) $this->fail('Encrypted theme entries are not supported.');
            $expanded += $entry['size'];
            if ($entry['size'] > 65536 || $expanded > config('themes.max_expanded_bytes')) $this->fail('Theme archive decompression limit exceeded.');
            $contents = $zip->getFromIndex($index, 65537);
            if (!is_string($contents) || strlen($contents) !== $entry['size']) $this->fail('Theme archive size mismatch.');
            $files[$name] = $contents;
        }
        if (!isset($files['theme.json'])) $this->fail('theme.json must be at the archive root.');
        return $files;
    }

    private function fail(string $message): never { throw ValidationException::withMessages(['package' => $message]); }
}
