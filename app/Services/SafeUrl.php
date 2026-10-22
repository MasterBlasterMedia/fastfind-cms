<?php

namespace App\Services;

final class SafeUrl
{
    public static function valid(mixed $value, bool $localOnly = false): bool
    {
        if (!is_string($value) || $value === '' || mb_strlen($value) > 1500 || preg_match('/[\x00-\x20\x7f\\\\<>"\']/', $value)) {
            return false;
        }

        // Reject encoded controls and slashes too: servers and browsers can normalize them differently.
        $decoded = rawurldecode($value);
        if (preg_match('/[\x00-\x20\x7f\\\\]/', $decoded) || str_starts_with($decoded, '//')) {
            return false;
        }
        if (str_starts_with($value, '/') && !str_starts_with($value, '//')) {
            return true;
        }
        if ($localOnly) return false;
        if (str_starts_with($value, '#')) return true;

        $parts = parse_url($value);
        return is_array($parts) && in_array(strtolower($parts['scheme'] ?? ''), ['https', 'http'], true)
            && !empty($parts['host']) && !isset($parts['user']) && !isset($parts['pass'])
            && filter_var($value, FILTER_VALIDATE_URL) !== false;
    }
}
