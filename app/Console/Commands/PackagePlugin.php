<?php

namespace App\Console\Commands;

use App\Plugins\Manifest;
use App\Plugins\PluginManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use ZipArchive;

final class PackagePlugin extends Command
{
    protected $signature = 'fastfind:plugin-package {directory : Reviewed plugin source directory} {--key= : Private Ed25519 signing key file (base64)} {--publisher= : Approved publisher ID}';
    protected $description = 'Package reviewed plugin source and write detached signature metadata to private storage';

    public function handle(Manifest $manifests, PluginManager $manager): int
    {
        $path = realpath($this->argument('directory'));
        $keyPath = $this->option('key');
        $publisher = $this->option('publisher');
        if (!$path || !$keyPath || !is_file($keyPath) || !is_string($publisher) || !preg_match('/^[a-z][a-z0-9-]{1,79}$/D', $publisher)) {
            $this->error('Provide reviewed source, --key=/private/signing-key and --publisher=publisher-id.'); return self::FAILURE;
        }
        $key = base64_decode(trim(file_get_contents($keyPath)), true);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) { $this->error('Invalid Ed25519 private key.'); return self::FAILURE; }
        $manifest = $manifests->read($path.'/plugin.json');
        $directory = storage_path('app/private/plugin-packages');
        File::ensureDirectoryExists($directory, 0700);
        $target = $directory.'/'.str_replace('/', '-', $manifest['id']).'-'.$manifest['version'].'-'.now()->format('YmdHis').'.zip';
        if (file_exists($target)) { $this->error('Output already exists. Retry with a new version or later timestamp.'); return self::FAILURE; }
        $files = $manager->digests($path);
        $zip = new ZipArchive;
        if ($zip->open($target, ZipArchive::CREATE | ZipArchive::EXCL) !== true) { $this->error('Cannot create package.'); return self::FAILURE; }
        try { foreach (array_keys($files) as $relative) $zip->addFile($path.'/'.$relative, $relative); }
        finally { $zip->close(); }
        $metadata = ['id' => $manifest['id'], 'version' => $manifest['version'], 'publisher' => $publisher,
            'sha256' => hash_file('sha256', $target), 'signature' => base64_encode(sodium_crypto_sign_detached(file_get_contents($target), $key)),
            'public_key' => base64_encode(sodium_crypto_sign_publickey_from_secretkey($key))];
        sodium_memzero($key);
        file_put_contents($target.'.json', json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $this->info('Package: '.$target);
        $this->info('Signature metadata: '.$target.'.json');
        $this->line('The receiving installation must separately approve this publisher key before upload.');
        return self::SUCCESS;
    }
}
