<?php

namespace App\Console\Commands;

use App\Themes\ThemeManifest;
use App\Themes\ThemePackageInstaller;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use ZipArchive;

final class PackageTheme extends Command
{
    protected $signature = 'fastfind:theme-package {directory : Theme source directory} {--key= : Private Ed25519 signing key file (base64)} {--publisher= : Approved publisher ID}';
    protected $description = 'Package a data-only FastFind theme with detached signature metadata';

    public function handle(ThemeManifest $manifests): int
    {
        $path = realpath($this->argument('directory'));
        $keyPath = $this->option('key');
        $publisher = $this->option('publisher');
        if (!$path || !is_dir($path) || !$keyPath || !is_file($keyPath) || !is_string($publisher) || !preg_match('/^[a-z][a-z0-9-]{1,79}$/D', $publisher)) {
            $this->error('Provide theme source, --key=/private/signing-key and --publisher=publisher-id.'); return self::FAILURE;
        }
        $key = base64_decode(trim(file_get_contents($keyPath)), true);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) { $this->error('Invalid Ed25519 private key.'); return self::FAILURE; }
        $target = null;
        try {
            $manifest = $manifests->read($path.'/theme.json');
            if ($issues = $manifests->issues($manifest)) throw new \RuntimeException(implode(' ', $issues));
            if (File::directories($path)) throw new \RuntimeException('Theme packages cannot contain subdirectories.');
            $files = File::files($path, true);
            foreach ($files as $file) {
                if ($file->isLink() || !in_array($file->getFilename(), ThemePackageInstaller::FILES, true) || $file->getSize() > 65536) throw new \RuntimeException('Only bounded theme.json, README.md and LICENSE.txt files are supported.');
            }
            $output = config('themes.output_path');
            File::ensureDirectoryExists($output, 0700);
            $target = $output.'/'.$manifest['id'].'-'.$manifest['version'].'-'.Str::uuid().'.zip';
            $zip = new ZipArchive;
            if ($zip->open($target, ZipArchive::CREATE | ZipArchive::EXCL) !== true) throw new \RuntimeException('Cannot create theme package.');
            try { foreach ($files as $file) if (!$zip->addFile($file->getPathname(), $file->getFilename())) throw new \RuntimeException('Cannot add theme file.'); }
            finally { $zip->close(); }
            $metadata = ['type' => 'theme', 'id' => $manifest['id'], 'version' => $manifest['version'], 'publisher' => $publisher,
                'sha256' => hash_file('sha256', $target), 'signature' => base64_encode(sodium_crypto_sign_detached(file_get_contents($target), $key)),
                'public_key' => base64_encode(sodium_crypto_sign_publickey_from_secretkey($key))];
            if (file_put_contents($target.'.json', json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)) === false) throw new \RuntimeException('Cannot write signature metadata.');
            chmod($target, 0600); chmod($target.'.json', 0600);
            $this->info('Package: '.$target);
            $this->info('Signature metadata: '.$target.'.json');
            $this->line('The receiving installation must independently approve this publisher public key.');
            return self::SUCCESS;
        } catch (\Throwable $error) {
            if ($target) { File::delete($target, $target.'.json'); }
            $this->error($error->getMessage());
            return self::FAILURE;
        } finally { sodium_memzero($key); }
    }
}
