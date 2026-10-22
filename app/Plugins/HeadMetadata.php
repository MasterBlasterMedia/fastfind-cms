<?php

namespace App\Plugins;

/** Host-side encoding boundary, shared by all metadata providers. */
final class HeadMetadata
{
    public static function normalize(array $head): array
    {
        $clean = ['meta' => []];
        foreach (['title' => 200, 'description' => 500] as $key => $limit) {
            if (is_string($head[$key] ?? null)) $clean[$key] = mb_substr($head[$key], 0, $limit);
        }
        if (self::httpUrl($head['canonical'] ?? null)) $clean['canonical'] = $head['canonical'];
        foreach ($head['meta'] ?? [] as $name => $value) {
            if (is_string($name) && preg_match('/^(robots|og:(title|description|image|url|type|site_name)|twitter:(card|title|description|image))$/D', $name) && is_string($value)) $clean['meta'][$name] = mb_substr($value, 0, 1500);
        }
        if (is_array($head['structured_data'] ?? null) && strlen(json_encode($head['structured_data'], JSON_THROW_ON_ERROR)) <= 16000) $clean['structured_data'] = $head['structured_data'];
        return $clean;
    }

    public static function httpUrl(mixed $url): bool
    {
        return is_string($url) && strlen($url) <= 1500 && !preg_match('/[\x00-\x20\\\\<>"\']/u', $url)
            && filter_var($url, FILTER_VALIDATE_URL) && in_array(strtolower(parse_url($url, PHP_URL_SCHEME) ?? ''), ['http', 'https'], true)
            && !parse_url($url, PHP_URL_USER) && !parse_url($url, PHP_URL_PASS);
    }
}
