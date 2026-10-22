<?php

namespace App\Plugins;

use App\Models\PluginInstallation;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use ZipArchive;

final class PackageInstaller
{
    public function __construct(private Manifest $manifests, private PluginManager $manager) {}

    public function install(string $archive, string $publisher, string $signature, string $sha256, int $actor): PluginInstallation
    {
        \Illuminate\Support\Facades\Gate::forUser(\App\Models\User::findOrFail($actor))->authorize('manage-plugins');
        $staging = null;
        try {
            $key = config('plugins.publishers.'.$publisher);
            if (!is_string($key)) $this->fail('Publisher is not explicitly trusted by this installation.');
            if (!is_file($archive) || filesize($archive) > config('plugins.max_archive_bytes')) $this->fail('Plugin archive exceeds the upload limit.');
            if (!preg_match('/^[a-f0-9]{64}$/D', $sha256) || !hash_equals($sha256, hash_file('sha256', $archive))) $this->fail('Archive integrity verification failed.');
            $publicKey = base64_decode($key, true);
            $detached = base64_decode($signature, true);
            if ($publicKey === false || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES || $detached === false || strlen($detached) !== SODIUM_CRYPTO_SIGN_BYTES || !sodium_crypto_sign_verify_detached($detached, file_get_contents($archive), $publicKey)) $this->fail('Archive signature verification failed.');
            $zip = new ZipArchive;
            if ($zip->open($archive, ZipArchive::RDONLY) !== true) $this->fail('Invalid ZIP archive.');
            try {
                $entries = $this->inspect($zip);
                if (!isset($entries['plugin.json'])) $this->fail('plugin.json must be at the archive root.');
                $raw = $zip->getFromIndex($entries['plugin.json']['index']);
                if (strlen($raw) > 65536) $this->fail('Plugin manifest is too large.');
                $manifest = $this->manifests->validate(json_decode($raw, true, 32, JSON_THROW_ON_ERROR));
                if ($issues = $this->manifests->issues($manifest)) $this->fail(implode(' ', $issues));
                $root = config('plugins.package_path');
                File::ensureDirectoryExists($root, 0700);
                $staging = $root.'/'.str_replace('/', '-', $manifest['id']).'-'.$manifest['version'].'-'.Str::uuid();
                File::makeDirectory($staging, 0700, true);
                foreach ($entries as $name => $entry) {
                    $target = $staging.'/'.$name;
                    File::ensureDirectoryExists(dirname($target), 0700);
                    // Never use extractTo: write only inspected paths and bounded streams.
                    $stream = $zip->getStream($name);
                    if (!$stream) $this->fail('Could not read archive entry.');
                    $output = fopen($target, 'xb');
                    try { $bytes = stream_copy_to_stream($stream, $output, $entry['size'] + 1); }
                    finally { fclose($stream); fclose($output); }
                    if ($bytes !== $entry['size']) $this->fail('Archive size mismatch.');
                    chmod($target, 0600);
                }
            } finally { $zip->close(); }
            if (PluginInstallation::find($manifest['id'])) $record = $this->manager->upgradeVerified($staging, $manifest, $actor);
            else $record = $this->manager->installVerified($staging, $manifest, 'package', $actor);
            $staging = null; // Retain installed version and previous versions for rollback.
            return $record;
        } catch (\Throwable $error) {
            $this->manager->audit($actor, 'plugin.package_rejected', 'unknown', ['publisher' => substr($publisher, 0, 80), 'exception' => get_class($error)]);
            if ($error instanceof ValidationException) throw $error;
            $this->fail('Package could not be installed. No unverified PHP was executed.');
        } finally {
            // This is a generated staging directory beneath the configured private root only.
            if ($staging && is_dir($staging)) File::deleteDirectory($staging);
        }
    }

    private function inspect(ZipArchive $zip): array
    {
        if ($zip->numFiles > config('plugins.max_files')) $this->fail('Archive has too many entries.');
        $entries = []; $seen = []; $expanded = 0;
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index);
            $name = $stat['name'];
            if (strlen($name) > 200 || !preg_match('~^[a-zA-Z0-9_-][a-zA-Z0-9_./-]*$~D', $name) || str_contains($name, '//')) $this->fail('Unsafe archive path.');
            foreach (explode('/', rtrim($name, '/')) as $segment) {
                if ($segment === '.' || $segment === '..' || str_ends_with($segment, '.') || preg_match('/^(con|prn|aux|nul|com[0-9]|lpt[0-9])(?:\.|$)/i', $segment)) $this->fail('Unsafe archive path segment.');
            }
            $key = strtolower(rtrim($name, '/'));
            if (isset($seen[$key])) $this->fail('Duplicate archive paths are not permitted.');
            $seen[$key] = true;
            $zip->getExternalAttributesIndex($index, $os, $attributes);
            $mode = ($attributes >> 16) & 0170000;
            if (!in_array($mode, [0, 0100000, 0040000], true)) $this->fail('Symlinks and special archive entries are not permitted.');
            if (($stat['encryption_method'] ?? 0) !== 0) $this->fail('Encrypted ZIP entries are not supported.');
            if (str_ends_with($name, '/')) continue;
            if (!in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), ['php', 'json', 'md', 'txt', 'js', 'vue', 'css', 'scss', 'png', 'jpg', 'webp'], true)) $this->fail('Unsupported package file type.');
            $expanded += $stat['size'];
            if ($stat['size'] > 8 * 1024 * 1024 || $expanded > config('plugins.max_expanded_bytes') || ($stat['size'] > 1024 * 1024 && $stat['size'] > max(1, $stat['comp_size']) * 100)) $this->fail('Archive decompression limit exceeded.');
            $entries[$name] = ['index' => $index, 'size' => $stat['size']];
        }
        foreach (array_keys($entries) as $name) {
            $parent = dirname($name);
            while ($parent !== '.') {
                if (isset($entries[$parent])) $this->fail('Archive file/directory collision.');
                $parent = dirname($parent);
            }
        }
        return $entries;
    }
    private function fail(string $message): never { throw ValidationException::withMessages(['package' => $message]); }
}
