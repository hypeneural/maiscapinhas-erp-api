<?php

declare(strict_types=1);

namespace App\Services;

class HiperCookieService
{
    /**
     * Essential cookies in the required order.
     */
    private const ESSENTIAL_ORDER = [
        'dominio_hiper',
        'TempDataProvider',
        '__RequestVerificationToken',
        '.AspNet.ApplicationCookie',
        '.AspNet.TwoFactorRememberBrowser',
    ];

    /**
     * Parse TSV copied from DevTools cookies tab.
     *
     * Handles "broken" lines where the domain appears on the next line.
     * Expected columns: name \t value \t domain ...
     * Continuation line: domain \t / ...
     *
     * @return array<int, array{name: string, value: string, domain: string}>
     */
    public function parseTsv(string $raw): array
    {
        $lines = array_filter(
            array_map('trim', explode("\n", str_replace("\r\n", "\n", $raw))),
            fn(string $l) => $l !== ''
        );

        $out = [];
        $pending = null;

        foreach ($lines as $line) {
            $cols = array_map('trim', explode("\t", $line));

            // Complete line: name, value, domain, ...
            if (count($cols) >= 3 && ($cols[1] ?? '') !== '/') {
                if ($pending !== null) {
                    // Flush any unfinished pending (no continuation found)
                    $out[] = ['name' => $pending['name'], 'value' => $pending['value'], 'domain' => ''];
                }
                $out[] = [
                    'name' => $cols[0],
                    'value' => $cols[1],
                    'domain' => $cols[2] ?? '',
                ];
                $pending = null;
                continue;
            }

            // Split line — name + value only (no domain yet)
            if (count($cols) === 2 && ($cols[1] ?? '') !== '/') {
                if ($pending !== null) {
                    $out[] = ['name' => $pending['name'], 'value' => $pending['value'], 'domain' => ''];
                }
                $pending = ['name' => $cols[0], 'value' => $cols[1]];
                continue;
            }

            // Continuation: domain + "/" + ...
            if ($pending !== null && count($cols) >= 2 && ($cols[1] ?? '') === '/') {
                $out[] = [
                    'name' => $pending['name'],
                    'value' => $pending['value'],
                    'domain' => $cols[0] ?? '',
                ];
                $pending = null;
                continue;
            }
        }

        // Flush last pending
        if ($pending !== null) {
            $out[] = ['name' => $pending['name'], 'value' => $pending['value'], 'domain' => ''];
        }

        return array_values(array_filter($out, fn(array $c) => $c['name'] !== '' && $c['value'] !== ''));
    }

    /**
     * Merge parsed cookies into the by_domain JSON structure.
     *
     * @param  array<int, array{name: string, value: string, domain: string}>  $parsed
     * @param  array|null  $existing  Current by_domain JSON (or null)
     * @return array  The full JSON with by_domain + last_imported_at
     */
    public function mergeIntoJson(array $parsed, ?array $existing = null): array
    {
        $byDomain = $existing['by_domain'] ?? [];

        foreach ($parsed as $cookie) {
            $domain = $cookie['domain'] ?: '_unknown';
            if (!isset($byDomain[$domain])) {
                $byDomain[$domain] = [];
            }
            $byDomain[$domain][$cookie['name']] = $cookie['value'];
        }

        return [
            'by_domain' => $byDomain,
            'last_imported_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Build the essential Cookie header string, selecting the best value per
     * cookie name based on domain scoring.
     *
     * Scoring:
     *   3 = cookie domain == request host
     *   2 = request host ends with cookie domain (suffix match)
     *   1 = everything else
     *
     * Tiebreak: longest value wins.
     *
     * @return array{cookie: string, missing: string[]}
     */
    public function buildEssentialCookieHeader(array $cookiesJson, string $requestHost): array
    {
        $byDomain = $cookiesJson['by_domain'] ?? [];
        $requestHost = strtolower($requestHost);

        // Flatten into [{name, value, domain}] for easy processing
        $flat = [];
        foreach ($byDomain as $domain => $cookies) {
            foreach ($cookies as $name => $value) {
                $flat[] = [
                    'name' => $name,
                    'value' => $value,
                    'domain' => (string) $domain,
                ];
            }
        }

        // Group by name
        $byName = [];
        foreach ($flat as $c) {
            $byName[$c['name']][] = $c;
        }

        $missing = [];
        $parts = [];

        foreach (self::ESSENTIAL_ORDER as $name) {
            $candidates = $byName[$name] ?? [];

            if (empty($candidates)) {
                $missing[] = $name;
                continue;
            }

            // Sort by score desc, then by value length desc
            usort($candidates, function (array $a, array $b) use ($requestHost) {
                $scoreA = $this->domainScore($a['domain'], $requestHost);
                $scoreB = $this->domainScore($b['domain'], $requestHost);

                if ($scoreB !== $scoreA) {
                    return $scoreB - $scoreA;
                }

                return strlen((string) $b['value']) - strlen((string) $a['value']);
            });

            $best = $candidates[0];
            $parts[] = $best['name'] . '=' . $best['value'];
        }

        return [
            'cookie' => implode('; ', $parts),
            'missing' => $missing,
        ];
    }

    /**
     * Build a minimal cURL command string.
     */
    public function buildCurl(string $url, array $headers, string $cookie): string
    {
        $lines = ["curl --location '{$url}' \\"];

        foreach ($headers as $key => $value) {
            $lines[] = "  --header '{$key}: {$value}' \\";
        }

        $lines[] = "  --header 'Cookie: {$cookie}'";

        return implode("\n", $lines);
    }

    /**
     * Score how well a cookie domain matches the request host.
     */
    private function domainScore(string $cookieDomain, string $host): int
    {
        $d = strtolower($cookieDomain);
        $h = strtolower($host);

        if ($d === '' || $h === '') {
            return 1;
        }

        if ($d === $h) {
            return 3;
        }

        // Strip leading dot for suffix comparison
        $d2 = ltrim($d, '.');

        if ($h === $d2 || str_ends_with($h, '.' . $d2)) {
            return 2;
        }

        return 1;
    }
}
