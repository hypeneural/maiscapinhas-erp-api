<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\HiperCookieService;
use PHPUnit\Framework\TestCase;

class HiperCookieServiceTest extends TestCase
{
    private HiperCookieService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new HiperCookieService();
    }

    // ─── parseTsv ───

    public function test_parse_tsv_complete_lines(): void
    {
        $tsv = implode("\n", [
            "dominio_hiper\tmaiscapinhas\tmaiscapinhas.hiper.com.br\t/\t...",
            "TempDataProvider\tabc123\tmaiscapinhas.hiper.com.br\t/\t...",
            "_ga\tGA1.2.xxx\t.hiper.com.br\t/\t...",
        ]);

        $result = $this->service->parseTsv($tsv);

        $this->assertCount(3, $result);
        $this->assertEquals('dominio_hiper', $result[0]['name']);
        $this->assertEquals('maiscapinhas', $result[0]['value']);
        $this->assertEquals('maiscapinhas.hiper.com.br', $result[0]['domain']);
    }

    public function test_parse_tsv_broken_lines(): void
    {
        // Line 1: name + value only (no domain)
        // Line 2: domain + "/"
        $tsv = implode("\n", [
            "TempDataProvider\tsomevalue",
            "app.hiper.com.br\t/\tSession\t...",
        ]);

        $result = $this->service->parseTsv($tsv);

        $this->assertCount(1, $result);
        $this->assertEquals('TempDataProvider', $result[0]['name']);
        $this->assertEquals('somevalue', $result[0]['value']);
        $this->assertEquals('app.hiper.com.br', $result[0]['domain']);
    }

    public function test_parse_tsv_empty_returns_empty(): void
    {
        $this->assertEmpty($this->service->parseTsv(''));
        $this->assertEmpty($this->service->parseTsv("\n\n\n"));
    }

    // ─── mergeIntoJson ───

    public function test_merge_into_json_fresh(): void
    {
        $parsed = [
            ['name' => 'dominio_hiper', 'value' => 'maiscapinhas', 'domain' => 'maiscapinhas.hiper.com.br'],
            ['name' => '_ga', 'value' => 'GA1.2', 'domain' => '.hiper.com.br'],
        ];

        $result = $this->service->mergeIntoJson($parsed);

        $this->assertArrayHasKey('by_domain', $result);
        $this->assertArrayHasKey('last_imported_at', $result);
        $this->assertEquals('maiscapinhas', $result['by_domain']['maiscapinhas.hiper.com.br']['dominio_hiper']);
        $this->assertEquals('GA1.2', $result['by_domain']['.hiper.com.br']['_ga']);
    }

    public function test_merge_into_json_upsert_existing(): void
    {
        $existing = [
            'by_domain' => [
                'maiscapinhas.hiper.com.br' => [
                    'dominio_hiper' => 'old_value',
                    'other_cookie' => 'keep_me',
                ],
            ],
        ];

        $parsed = [
            ['name' => 'dominio_hiper', 'value' => 'new_value', 'domain' => 'maiscapinhas.hiper.com.br'],
        ];

        $result = $this->service->mergeIntoJson($parsed, $existing);

        // Updated
        $this->assertEquals('new_value', $result['by_domain']['maiscapinhas.hiper.com.br']['dominio_hiper']);
        // Kept
        $this->assertEquals('keep_me', $result['by_domain']['maiscapinhas.hiper.com.br']['other_cookie']);
    }

    // ─── buildEssentialCookieHeader ───

    public function test_build_essential_cookie_header_exact_domain_wins(): void
    {
        $cookies = [
            'by_domain' => [
                'maiscapinhas.hiper.com.br' => [
                    'TempDataProvider' => 'correct_value',
                    'dominio_hiper' => 'maiscapinhas',
                ],
                'app.hiper.com.br' => [
                    'TempDataProvider' => 'wrong_value',
                ],
            ],
        ];

        $result = $this->service->buildEssentialCookieHeader($cookies, 'maiscapinhas.hiper.com.br');

        $this->assertStringContainsString('TempDataProvider=correct_value', $result['cookie']);
        $this->assertStringNotContainsString('wrong_value', $result['cookie']);
    }

    public function test_build_essential_cookie_header_suffix_match(): void
    {
        $cookies = [
            'by_domain' => [
                '.hiper.com.br' => [
                    'dominio_hiper' => 'maiscapinhas',
                ],
            ],
        ];

        $result = $this->service->buildEssentialCookieHeader($cookies, 'maiscapinhas.hiper.com.br');

        $this->assertStringContainsString('dominio_hiper=maiscapinhas', $result['cookie']);
    }

    public function test_build_essential_cookie_header_reports_missing(): void
    {
        $cookies = [
            'by_domain' => [
                'maiscapinhas.hiper.com.br' => [
                    'dominio_hiper' => 'maiscapinhas',
                ],
            ],
        ];

        $result = $this->service->buildEssentialCookieHeader($cookies, 'maiscapinhas.hiper.com.br');

        // Should report 4 missing essential cookies
        $this->assertContains('TempDataProvider', $result['missing']);
        $this->assertContains('__RequestVerificationToken', $result['missing']);
        $this->assertContains('.AspNet.ApplicationCookie', $result['missing']);
        $this->assertContains('.AspNet.TwoFactorRememberBrowser', $result['missing']);
    }

    public function test_build_essential_cookie_header_order(): void
    {
        $cookies = [
            'by_domain' => [
                'maiscapinhas.hiper.com.br' => [
                    'dominio_hiper' => 'maiscapinhas',
                    'TempDataProvider' => 'temp123',
                    '__RequestVerificationToken' => 'token456',
                    '.AspNet.ApplicationCookie' => 'aspnet789',
                    '.AspNet.TwoFactorRememberBrowser' => '2fa_abc',
                ],
            ],
        ];

        $result = $this->service->buildEssentialCookieHeader($cookies, 'maiscapinhas.hiper.com.br');

        $expected = 'dominio_hiper=maiscapinhas; TempDataProvider=temp123; __RequestVerificationToken=token456; .AspNet.ApplicationCookie=aspnet789; .AspNet.TwoFactorRememberBrowser=2fa_abc';
        $this->assertEquals($expected, $result['cookie']);
        $this->assertEmpty($result['missing']);
    }

    public function test_build_essential_cookie_header_tiebreak_longest_value(): void
    {
        $cookies = [
            'by_domain' => [
                'a.hiper.com.br' => [
                    'TempDataProvider' => 'short',
                ],
                'b.hiper.com.br' => [
                    'TempDataProvider' => 'this_is_a_much_longer_value',
                ],
            ],
        ];

        // Neither domain matches exactly; both score 1. Longest value wins.
        $result = $this->service->buildEssentialCookieHeader($cookies, 'other.example.com');

        $this->assertStringContainsString('TempDataProvider=this_is_a_much_longer_value', $result['cookie']);
    }

    // ─── buildCurl ───

    public function test_build_curl_format(): void
    {
        $headers = [
            'Accept' => 'application/json',
            'Referer' => 'https://example.com',
        ];

        $curl = $this->service->buildCurl('https://example.com/api', $headers, 'foo=bar');

        $this->assertStringContainsString("curl --location 'https://example.com/api'", $curl);
        $this->assertStringContainsString("--header 'Accept: application/json'", $curl);
        $this->assertStringContainsString("--header 'Cookie: foo=bar'", $curl);
    }
}
