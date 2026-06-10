<?php

namespace App\Support\Http;

class SessionCookieDomain
{
    /**
     * Hotline session cookies are intentionally host-only. Older installers
     * wrote the app host (for example, hotline.pbb.ph) as SESSION_DOMAIN,
     * which creates a second cookie namespace beside the desired host cookie.
     */
    public static function normalize(mixed $configured, mixed $appUrl = null): ?string
    {
        return null;
    }

    /**
     * @return array<int, string>
     */
    public static function legacyDomains(mixed $appUrl = null): array
    {
        $host = self::hostFromUrl($appUrl);
        $domains = [];

        if ($host !== null) {
            $domains[] = $host;

            $parts = explode('.', $host);
            while (count($parts) > 2) {
                array_shift($parts);
                $parentDomain = implode('.', $parts);
                $domains[] = $parentDomain;
                $domains[] = '.'.$parentDomain;
            }
        }

        $domains[] = 'hotline.pbb.ph';
        $domains[] = 'pbb.ph';
        $domains[] = '.pbb.ph';

        return array_values(array_unique(array_filter($domains)));
    }

    private static function hostFromUrl(mixed $appUrl): ?string
    {
        $host = strtolower((string) (parse_url((string) $appUrl, PHP_URL_HOST) ?: ''));

        return $host !== '' ? $host : null;
    }
}
