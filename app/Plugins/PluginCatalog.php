<?php

namespace App\Plugins;

use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

/** A real local reviewed-source catalog; remote catalogs are metadata only. */
final class PluginCatalog
{
    public function __construct(private PluginManager $manager, private Manifest $manifests) {}

    public function listing(): array
    {
        if (!$url = config('plugins.catalog_url')) {
            return ['source' => 'local', 'name' => 'Local reviewed FastFind source', 'plugins' => array_values(array_map(function ($candidate) {
                $manifest = $candidate['manifest'];
                return [...$manifest, 'publisher' => ['id' => explode('/', $manifest['id'])[0], 'verification' => 'source-review-required'],
                    'review_status' => 'bundled-source', 'source_sha256' => hash('sha256', json_encode($this->manager->digests($candidate['path']), JSON_THROW_ON_ERROR)), 'download' => null];
            }, $this->manager->available()))];
        }
        $host = strtolower(parse_url($url, PHP_URL_HOST) ?? '');
        if (!HeadMetadata::httpUrl($url) || parse_url($url, PHP_URL_SCHEME) !== 'https' || !in_array($host, config('plugins.catalog_hosts', []), true)) $this->fail('Catalog must use HTTPS and an explicitly approved host.');
        $addresses = dns_get_record($host, DNS_A | DNS_AAAA);
        if (!$addresses) $this->fail('Catalog DNS resolution failed.');
        foreach ($addresses as $address) {
            $ip = $address['ip'] ?? $address['ipv6'] ?? '';
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) $this->fail('Catalog cannot resolve to private or reserved addresses.');
        }
        // Pin the resolved IP to close DNS-rebinding between verification and HTTP connect.
        $ip = $addresses[0]['ip'] ?? $addresses[0]['ipv6'];
        $port = parse_url($url, PHP_URL_PORT) ?: 443;
        $resolve = $host.':'.$port.':'.(str_contains($ip, ':') ? '['.$ip.']' : $ip);
        $response = Http::acceptJson()->timeout(10)->withoutRedirecting()->withOptions([
            'curl' => [CURLOPT_RESOLVE => [$resolve]],
            'progress' => function ($downloadTotal, $downloaded) { if ($downloadTotal > 1024 * 1024 || $downloaded > 1024 * 1024) $this->fail('Catalog response exceeds the limit.'); },
        ])->get($url);
        if (!$response->successful() || strlen($response->body()) > 1024 * 1024) $this->fail('Catalog response unavailable or oversized.');
        $data = $response->json();
        if (!is_array($data) || !is_array($data['plugins'] ?? null) || count($data['plugins']) > 200) $this->fail('Invalid catalog response.');
        $plugins = [];
        foreach ($data['plugins'] as $entry) {
            if (!is_array($entry)) $this->fail('Invalid catalog entry.');
            $manifest = $this->manifests->validate($entry);
            // Remote listings never become executable sources or grant publisher trust.
            $plugins[] = array_intersect_key($manifest, array_flip(['id', 'name', 'description', 'version', 'requires', 'permissions', 'dependencies']));
        }
        return ['source' => 'remote', 'name' => $host, 'plugins' => $plugins];
    }

    private function fail(string $message): never { throw ValidationException::withMessages(['catalog' => $message]); }
}
