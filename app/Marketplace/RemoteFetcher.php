<?php

namespace App\Marketplace;

use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\StreamWrapper;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** All marketplace HTTP traffic passes through a bounded, pinned HTTPS connection. */
final class RemoteFetcher
{
    public function __construct(private DnsResolver $dns) {}

    public function validateUrl(string $url): string
    {
        $parts = parse_url($url);
        $host = strtolower($parts['host'] ?? '');
        if (strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f\\\\]/', $url) || !filter_var($url, FILTER_VALIDATE_URL)
            || !$parts || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || ($parts['port'] ?? 443) !== 443 || !preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{1,62}$/D', $host)
            || !in_array($host, config('marketplace.hosts', []), true)) {
            $this->fail('Marketplace URLs must use HTTPS on an explicitly approved domain and port 443.');
        }
        return $host;
    }

    public function read(string $url, int $limit): string
    {
        $contents = '';
        $this->transfer($url, $limit, function (string $chunk) use (&$contents) { $contents .= $chunk; });
        return $contents;
    }

    /** The caller must remove this private file in a finally block after validation/install. */
    public function download(string $url, int $limit): string
    {
        $root = config('marketplace.download_path');
        File::ensureDirectoryExists($root, 0700);
        $path = $root.'/'.Str::uuid().'.zip';
        $output = fopen($path, 'xb');
        if (!$output) $this->fail('The private package download directory is not writable.');
        chmod($path, 0600);
        try {
            $this->transfer($url, $limit, function (string $chunk) use ($output) {
                if (fwrite($output, $chunk) !== strlen($chunk)) $this->fail('The package download could not be saved.');
            });
            return $path;
        } catch (\Throwable $error) {
            fclose($output);
            @unlink($path);
            throw $error;
        } finally {
            if (is_resource($output)) fclose($output);
        }
    }

    private function transfer(string $url, int $limit, callable $consume): void
    {
        $host = $this->validateUrl($url);
        $addresses = $this->dns->addresses($host);
        if (!$addresses) $this->fail('Marketplace DNS resolution failed.');
        foreach ($addresses as $ip) {
            if (!is_string($ip) || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE)) $this->fail('Marketplace domains cannot resolve to private or reserved addresses.');
            // Restrict IPv6 to global unicast; reject mapped/transition address encodings.
            if (str_contains($ip, ':') && ((ord(inet_pton($ip)[0]) & 0xe0) !== 0x20 || str_starts_with(strtolower($ip), '2002:'))) $this->fail('Marketplace DNS returned an unsupported address.');
        }
        $ip = $addresses[0];
        $resolve = $host.':443:'.(str_contains($ip, ':') ? '['.$ip.']' : $ip);
        $stream = null;
        $received = 0;
        $abort = null;
        $deadline = microtime(true) + 20;
        $write = function (string $chunk) use ($limit, $consume, &$received, &$abort, $deadline): int {
            $received += strlen($chunk);
            if ($received > $limit) $this->fail($abort = 'Marketplace response exceeds the size limit.');
            if (microtime(true) > $deadline) $this->fail($abort = 'Marketplace response timed out.');
            $consume($chunk);
            return strlen($chunk);
        };
        // The default Guzzle streaming handler ignores cURL's DNS pin. Force cURL,
        // and stream each bounded chunk into the caller's private destination.
        $sink = FnStream::decorate(Utils::streamFor(''), ['write' => $write]);
        $resource = StreamWrapper::getResource($sink);
        try {
            if (!extension_loaded('curl')) $this->fail('Marketplace downloads require the PHP cURL extension.');
            $response = Http::withHeaders(['Accept' => 'application/json, application/zip', 'Accept-Encoding' => 'identity'])
                ->setHandler(new CurlHandler)->connectTimeout(5)->timeout(20)->withoutRedirecting()->withOptions([
                    'sink' => $resource, 'decode_content' => false, 'proxy' => '',
                    'curl' => [CURLOPT_RESOLVE => [$resolve]],
                    'on_headers' => function ($response) use ($limit, &$abort) {
                        $length = $response->getHeaderLine('Content-Length');
                        if ($length !== '' && (!ctype_digit($length) || (float) $length > $limit)) $this->fail($abort = 'Marketplace response exceeds the size limit.');
                    },
                    'progress' => function ($total, $received) use ($limit, &$abort) {
                        if ($total > $limit || $received > $limit) $this->fail($abort = 'Marketplace response exceeds the size limit.');
                    },
                ])->get($url);
            $stream = $response->toPsrResponse()->getBody();
            if ($abort !== null) $this->fail($abort);
            if (!$response->successful()) $this->fail('Marketplace response unavailable. Redirects are not followed.');
            // Apply the same bounds to any unread body returned by custom transports.
            while (!$stream->eof()) {
                $chunk = $stream->read(min(65536, $limit - $received + 1));
                if ($chunk === '' && !$stream->eof()) $this->fail('Marketplace response timed out.');
                $write($chunk);
            }
        } catch (ValidationException $error) {
            throw $error;
        } catch (\Throwable) {
            $this->fail($abort ?? 'Marketplace connection failed. Check the configured address, approved hosts and PHP trusted certificates.');
        } finally {
            $stream?->close();
            if (is_resource($resource)) fclose($resource);
            $sink->close();
        }
    }

    private function fail(string $message): never { throw ValidationException::withMessages(['marketplace' => $message]); }
}
