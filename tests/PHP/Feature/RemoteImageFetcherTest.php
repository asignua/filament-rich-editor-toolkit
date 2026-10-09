<?php

declare(strict_types=1);

namespace Asignua\RichEditorToolkit\Tests\Feature;

use Asignua\RichEditorToolkit\Support\CappedSink;
use Asignua\RichEditorToolkit\Support\RemoteImageException;
use Asignua\RichEditorToolkit\Support\RemoteImageFetcher;
use Asignua\RichEditorToolkit\Tests\TestCase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\RequestInterface;

/**
 * The SSRF guard of the Google Docs picture download. The network is never touched: HTTP is
 * faked and DNS is injected, and every refusal asserts that NO request left the application.
 */
class RemoteImageFetcherTest extends TestCase
{
    private const string PNG = "\x89PNG\r\n\x1a\n\0\0\0\rIHDR\0\0\0\x01\0\0\0\x01";

    private const string URL = 'https://lh7-rt.googleusercontent.com/docsz/abc?key=1';

    /** @var list<string> */
    private const array HOSTS = ['*.googleusercontent.com'];

    private int $lookups = 0;

    /**
     * @param callable|list<string> $addresses
     */
    private function fetcher(array|callable $addresses = ['142.250.74.33']): RemoteImageFetcher
    {
        return new RemoteImageFetcher(function (string $host) use ($addresses): array {
            $this->lookups++;

            return is_callable($addresses) ? $addresses($host) : $addresses;
        });
    }

    private function refusal(callable $attempt): string
    {
        try {
            $attempt();
        } catch (RemoteImageException $exception) {
            return $exception->reason;
        }

        $this->fail('the fetch was not refused');
    }

    // --- the happy path -----------------------------------------------------------------------------

    public function test_a_google_docs_picture_is_downloaded_and_returned_as_a_data_url(): void
    {
        Http::fake(['*' => Http::response(self::PNG, 200, ['Content-Type' => 'image/png'])]);

        $image = $this->fetcher()->fetch(self::URL, self::HOSTS, 1024);

        $this->assertSame('image/png', $image->mime);
        $this->assertSame('data:image/png;base64,'.base64_encode(self::PNG), $image->toDataUrl());
        $this->assertSame(1, $this->lookups, 'the name is resolved once, here, and the connection is pinned to that answer');
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->url() === self::URL && $request->method() === 'GET');
    }

    public function test_the_request_is_rebuilt_from_the_checked_parts(): void
    {
        Http::fake(['*' => Http::response(self::PNG)]);

        $this->fetcher()->fetch('HTTPS://LH7-RT.GoogleUserContent.com:443/docsz/abc?key=1#fragment', self::HOSTS, 1024);

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://lh7-rt.googleusercontent.com/docsz/abc?key=1');
    }

    public function test_the_type_comes_from_the_magic_bytes_not_from_what_the_server_says(): void
    {
        Http::fake(['*' => Http::response(self::PNG, 200, ['Content-Type' => 'text/html'])]);

        $this->assertSame('image/png', $this->fetcher()->fetch(self::URL, self::HOSTS, 1024)->mime);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function formats(): array
    {
        return [
            'jpeg' => ["\xff\xd8\xff\xe0\0\x10JFIF", 'image/jpeg'],
            'gif87' => ['GIF87a'."\x01\0\x01\0", 'image/gif'],
            'gif89' => ['GIF89a'."\x01\0\x01\0", 'image/gif'],
            'webp' => ['RIFF'."\x24\0\0\0".'WEBPVP8 ', 'image/webp'],
        ];
    }

    #[DataProvider('formats')]
    public function test_jpeg_gif_and_webp_are_recognised(string $bytes, string $mime): void
    {
        Http::fake(['*' => Http::response($bytes)]);

        $this->assertSame($mime, $this->fetcher()->fetch(self::URL, self::HOSTS, 1024)->mime);
    }

    // --- content -----------------------------------------------------------------------------------

    /**
     * @return array<string, array{string}>
     */
    public static function notPictures(): array
    {
        return [
            'svg' => ['<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>'],
            'html' => ['<!doctype html><script>alert(1)</script>'],
            'text' => ['hello'],
            'empty' => [''],
            'riff but not webp' => ['RIFF'."\x24\0\0\0".'WAVEfmt '],
            'a truncated png signature' => ["\x89PNG"],
        ];
    }

    #[DataProvider('notPictures')]
    public function test_anything_that_is_not_a_raster_picture_is_refused_even_when_labelled_as_one(string $body): void
    {
        Http::fake(['*' => Http::response($body, 200, ['Content-Type' => 'image/png'])]);

        $this->assertSame(RemoteImageFetcher::ERR_TYPE, $this->refusal(fn () => $this->fetcher()->fetch(self::URL, self::HOSTS, 1024)));
    }

    public function test_the_fields_accepted_types_are_applied_to_the_sniffed_type(): void
    {
        Http::fake(['*' => Http::response('GIF89a'."\x01\0\x01\0")]);

        $this->assertSame(RemoteImageFetcher::ERR_TYPE, $this->refusal(fn () => $this->fetcher()->fetch(self::URL, self::HOSTS, 1024, ['image/png', 'image/jpeg'])));
        $this->assertSame('image/gif', $this->fetcher()->fetch(self::URL, self::HOSTS, 1024, ['image/gif'])->mime);
    }

    // --- the request that actually leaves ---------------------------------------------------------------

    /**
     * Runs one fetch with the real option set and returns the Guzzle options the HTTP layer saw.
     *
     * @return array<string, mixed>
     */
    private function sentOptions(string $address = '142.250.74.33', int $maxBytes = 1024): array
    {
        $seen = [];

        Http::fake(['*' => Http::response(self::PNG)]);
        // `use (&$seen)` on the OUTER closure: an arrow function would capture a copy.
        Http::globalMiddleware(static function (callable $handler) use (&$seen): callable {
            return static function (RequestInterface $request, array $options) use ($handler, &$seen) {
                $seen = $options;

                return $handler($request, $options);
            };
        });

        $this->fetcher([$address])->fetch(self::URL, self::HOSTS, $maxBytes);

        return $seen;
    }

    public function test_the_request_goes_out_without_a_proxy_whatever_the_environment_says(): void
    {
        // Guzzle would otherwise read this and send the request (and the DNS lookup) through it.
        putenv('HTTPS_PROXY=http://proxy.internal:3128');
        putenv('ALL_PROXY=http://proxy.internal:3128');

        try {
            $options = $this->sentOptions();
        } finally {
            putenv('HTTPS_PROXY');
            putenv('ALL_PROXY');
        }

        // '' is a decision ("no proxy"), not a missing option: only a missing option falls back to the environment.
        $this->assertArrayHasKey('proxy', $options);
        $this->assertSame('', $options['proxy']);
    }

    public function test_the_response_is_not_decompressed_and_written_to_a_capped_sink(): void
    {
        $options = $this->sentOptions('142.250.74.33', 4096);

        $this->assertFalse($options['decode_content']);
        $this->assertIsResource($options['sink']);
        // Guzzle 8 rejects CURLOPT_MAXFILESIZE_LARGE outright, so it must not be there.
        $this->assertArrayNotHasKey(CURLOPT_MAXFILESIZE_LARGE, $options['curl']);

        // (Http::fake already wrote the fake body into the sink; this one write crosses the cap whatever is in there.)
        $this->assertFalse(@fwrite($options['sink'], str_repeat('x', 4097)), 'the sink is capped at the byte limit it was built for');
    }

    public function test_the_connection_is_pinned_to_the_checked_address(): void
    {
        $this->assertSame(['lh7-rt.googleusercontent.com:443:142.250.74.33'], $this->sentOptions('142.250.74.33')['curl'][CURLOPT_RESOLVE]);
    }

    public function test_an_ipv6_address_is_pinned_in_brackets(): void
    {
        $this->assertSame(['lh7-rt.googleusercontent.com:443:[2a00:1450:4001:81b::200e]'], $this->sentOptions('2a00:1450:4001:81b::200e')['curl'][CURLOPT_RESOLVE]);
    }

    public function test_identity_encoding_is_requested(): void
    {
        Http::fake(['*' => Http::response(self::PNG)]);

        $this->fetcher()->fetch(self::URL, self::HOSTS, 1024);

        Http::assertSent(fn (Request $request): bool => $request->header('Accept-Encoding') === ['identity']);
    }

    public function test_a_compressed_response_is_refused_not_inflated(): void
    {
        // The compressed bytes are tiny and under any cap; what they would inflate to is not.
        Http::fake(['*' => Http::response(gzencode(self::PNG.str_repeat("\0", 100000)), 200, ['Content-Encoding' => 'gzip'])]);

        $this->assertSame(RemoteImageFetcher::ERR_HTTP, $this->refusal(fn () => $this->fetcher()->fetch(self::URL, self::HOSTS, 1024)));
    }

    public function test_an_explicit_identity_encoding_is_fine(): void
    {
        Http::fake(['*' => Http::response(self::PNG, 200, ['Content-Encoding' => 'identity'])]);

        $this->assertSame('image/png', $this->fetcher()->fetch(self::URL, self::HOSTS, 1024)->mime);
    }

    public function test_a_declared_length_over_the_cap_is_refused_before_the_body(): void
    {
        Http::fake(['*' => Http::response(self::PNG, 200, ['Content-Length' => '999999'])]);

        $this->assertSame(RemoteImageFetcher::ERR_SIZE, $this->refusal(fn () => $this->fetcher()->fetch(self::URL, self::HOSTS, 1024)));
    }

    public function test_the_sink_refuses_the_write_that_crosses_the_cap(): void
    {
        $sink = new CappedSink(10);

        $this->assertSame(6, fwrite($sink->stream, 'abcdef'));
        $this->assertFalse($sink->exceeded);
        $this->assertSame(4, fwrite($sink->stream, 'ghij'), 'exactly at the cap is allowed');

        // A failed write is what makes libcurl abort the transfer.
        $this->assertFalse(@fwrite($sink->stream, 'k'));
        $this->assertTrue($sink->exceeded);

        rewind($sink->stream);
        $this->assertSame('abcdefghij', stream_get_contents($sink->stream));
    }

    public function test_a_sink_write_over_the_cap_in_one_piece_is_refused(): void
    {
        $sink = new CappedSink(4);

        $this->assertFalse(@fwrite($sink->stream, 'abcde'));
        $this->assertTrue($sink->exceeded);
    }

    // --- wildcards and public suffixes (the JS twin reads the same fixture) --------------------------------

    public function test_wildcard_cases_from_the_shared_fixture(): void
    {
        $fixture = json_decode((string) file_get_contents(__DIR__.'/../../fixtures/host-allow.json'), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame($fixture['suffixes'], RemoteImageFetcher::PUBLIC_SUFFIXES, 'the PHP list and the fixture (and with it the JS list) must be the same');

        foreach ($fixture['cases'] as $case) {
            $this->assertSame($case['allowed'], RemoteImageFetcher::hostAllowed($case['host'], $case['patterns']), $case['host'].' against '.implode(',', $case['patterns']));
        }
    }

    public function test_a_wildcard_over_a_public_suffix_matches_nothing(): void
    {
        $this->assertFalse(RemoteImageFetcher::hostAllowed('evil.co.uk', ['*.co.uk']));
        $this->assertFalse(RemoteImageFetcher::hostAllowed('evil.com.ua', ['*.com.ua']));
        $this->assertTrue(RemoteImageFetcher::hostAllowed('img.brand.co.uk', ['*.brand.co.uk']));
    }

    // --- more IPv6 ---------------------------------------------------------------------------------------

    /**
     * @return array<string, array{string}>
     */
    public static function moreNonPublicV6(): array
    {
        return [
            'ipv4-translated loopback (RFC 2765)' => ['::ffff:0:127.0.0.1'],
            'ipv4-translated metadata' => ['::ffff:0:a9fe:a9fe'],
            'ipv4-translated public still refused' => ['::ffff:0:8.8.8.8'],
            'documentation 3fff::/20' => ['3fff::1'],
            'documentation 3fff::/20 end' => ['3fff:0fff:ffff::1'],
            'srv6 5f00::/16' => ['5f00::1'],
        ];
    }

    #[DataProvider('moreNonPublicV6')]
    public function test_more_ipv6_transition_and_special_ranges_are_not_public(string $address): void
    {
        $this->assertFalse(RemoteImageFetcher::isPublicIp($address));
    }

    public function test_neighbours_of_the_new_ipv6_ranges_stay_public(): void
    {
        $this->assertTrue(RemoteImageFetcher::isPublicIp('3fff:1000::1'), 'just outside 3fff::/20');
        $this->assertTrue(RemoteImageFetcher::isPublicIp('5f01::1'), 'just outside 5f00::/16');
        $this->assertTrue(RemoteImageFetcher::isPublicIp('2606:4700::1111'));
    }

    // --- size ----------------------------------------------------------------------------------------

    public function test_a_body_over_the_limit_is_refused(): void
    {
        Http::fake(['*' => Http::response(self::PNG.str_repeat('x', 2000))]);

        $this->assertSame(RemoteImageFetcher::ERR_SIZE, $this->refusal(fn () => $this->fetcher()->fetch(self::URL, self::HOSTS, 1024)));
    }

    public function test_a_body_exactly_at_the_limit_is_accepted(): void
    {
        $body = self::PNG.str_repeat('x', 1024 - strlen(self::PNG));
        Http::fake(['*' => Http::response($body)]);

        $this->assertSame(1024, strlen($this->fetcher()->fetch(self::URL, self::HOSTS, 1024)->bytes));
    }

    // --- HTTP -------------------------------------------------------------------------------------------

    public function test_a_redirect_is_a_failure_and_is_never_followed(): void
    {
        Http::fake([
            'lh7-rt.googleusercontent.com/*' => Http::response('', 302, ['Location' => 'http://169.254.169.254/latest/meta-data/']),
            '*' => Http::response(self::PNG),
        ]);

        $this->assertSame(RemoteImageFetcher::ERR_HTTP, $this->refusal(fn () => $this->fetcher()->fetch(self::URL, self::HOSTS, 1024)));
        Http::assertSentCount(1);
    }

    #[DataProvider('failingStatuses')]
    public function test_error_statuses_are_failures(int $status): void
    {
        Http::fake(['*' => Http::response('nope', $status)]);

        $this->assertSame(RemoteImageFetcher::ERR_HTTP, $this->refusal(fn () => $this->fetcher()->fetch(self::URL, self::HOSTS, 1024)));
    }

    /**
     * @return array<string, array{int}>
     */
    public static function failingStatuses(): array
    {
        return ['403' => [403], '404' => [404], '429' => [429], '500' => [500]];
    }

    public function test_a_connection_failure_is_a_failure_without_leaking_the_message(): void
    {
        Http::fake(['*' => fn () => throw new ConnectionException('cURL error 7: connect to 10.0.0.5 failed')]);

        try {
            $this->fetcher()->fetch(self::URL, self::HOSTS, 1024);
            $this->fail('expected a refusal');
        } catch (RemoteImageException $exception) {
            $this->assertSame(RemoteImageFetcher::ERR_HTTP, $exception->reason);
            $this->assertStringNotContainsString('10.0.0.5', $exception->getMessage());
        }
    }

    // --- the URL ----------------------------------------------------------------------------------------

    /**
     * @return array<string, array{string}>
     */
    public static function badUrls(): array
    {
        return [
            'http' => ['http://lh7-rt.googleusercontent.com/a'],
            'ftp' => ['ftp://lh7-rt.googleusercontent.com/a'],
            'file' => ['file:///etc/passwd'],
            'javascript' => ['javascript:alert(1)'],
            'data' => ['data:image/png;base64,AAAA'],
            'protocol relative' => ['//lh7-rt.googleusercontent.com/a'],
            'no scheme' => ['lh7-rt.googleusercontent.com/a'],
            'empty' => [''],
            'credentials' => ['https://user:pw@lh7-rt.googleusercontent.com/a'],
            'user only' => ['https://user@lh7-rt.googleusercontent.com/a'],
            'other port' => ['https://lh7-rt.googleusercontent.com:8443/a'],
            'port 80' => ['https://lh7-rt.googleusercontent.com:80/a'],
            'backslash confusion' => ['https://lh7-rt.googleusercontent.com\\@evil.test/a'],
            'backslash path' => ['https://lh7-rt.googleusercontent.com\\evil.test/a'],
            'space' => ['https://lh7-rt.googleusercontent.com/a b'],
            'newline' => ["https://lh7-rt.googleusercontent.com/a\nHost: evil.test"],
            'null byte' => ["https://lh7-rt.googleusercontent.com/a\0"],
            'too long' => ['https://lh7-rt.googleusercontent.com/'.str_repeat('a', 2100)],
        ];
    }

    #[DataProvider('badUrls')]
    public function test_malformed_or_unsafe_urls_are_refused_before_anything_is_resolved(string $url): void
    {
        Http::fake();

        $this->assertSame(RemoteImageFetcher::ERR_URL, $this->refusal(fn () => $this->fetcher()->fetch($url, self::HOSTS, 1024)));
        $this->assertSame(0, $this->lookups);
        Http::assertNothingSent();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function foreignHosts(): array
    {
        return [
            'another host' => ['https://example.com/a.png'],
            'the bare domain of a wildcard' => ['https://googleusercontent.com/a.png'],
            'a look-alike' => ['https://evil-googleusercontent.com/a.png'],
            'allowed name as a prefix' => ['https://lh7-rt.googleusercontent.com.evil.test/a.png'],
            'allowed name as a subdomain label' => ['https://googleusercontent.com.evil.test/a.png'],
            'trailing dot' => ['https://lh7-rt.googleusercontent.com./a.png'],
            'ipv4 literal' => ['https://127.0.0.1/a.png'],
            'public ipv4 literal' => ['https://8.8.8.8/a.png'],
            'ipv6 literal' => ['https://[::1]/a.png'],
            'decimal ipv4' => ['https://2130706433/a.png'],
            'hex ipv4' => ['https://0x7f000001/a.png'],
            'short ipv4' => ['https://127.1/a.png'],
            'localhost' => ['https://localhost/a.png'],
            'single label' => ['https://intranet/a.png'],
            'percent encoded host' => ['https://lh7%2Drt.googleusercontent.com/a.png'],
            'unicode host' => ['https://lh7-rt.googleusercontént.com/a.png'],
        ];
    }

    #[DataProvider('foreignHosts')]
    public function test_a_host_off_the_allow_list_or_not_a_dns_name_is_refused_before_resolving(string $url): void
    {
        Http::fake();

        $this->assertSame(RemoteImageFetcher::ERR_HOST, $this->refusal(fn () => $this->fetcher()->fetch($url, self::HOSTS, 1024)));
        $this->assertSame(0, $this->lookups);
        Http::assertNothingSent();
    }

    public function test_an_ip_literal_is_refused_even_if_somebody_put_it_on_the_allow_list(): void
    {
        Http::fake();

        foreach (['127.0.0.1', '8.8.8.8', '[::1]', 'localhost'] as $entry) {
            $this->assertSame(RemoteImageFetcher::ERR_HOST, $this->refusal(fn () => $this->fetcher()->fetch('https://'.$entry.'/a.png', [$entry], 1024)), $entry);
        }

        Http::assertNothingSent();
    }

    public function test_an_empty_allow_list_refuses_everything(): void
    {
        Http::fake();

        $this->assertSame(RemoteImageFetcher::ERR_HOST, $this->refusal(fn () => $this->fetcher()->fetch(self::URL, [], 1024)));
    }

    public function test_exact_entries_match_exactly_and_wildcards_match_subdomains_only(): void
    {
        $this->assertTrue(RemoteImageFetcher::hostAllowed('img.example.com', ['img.example.com']));
        $this->assertTrue(RemoteImageFetcher::hostAllowed('IMG.Example.COM', ['img.example.com']));
        $this->assertFalse(RemoteImageFetcher::hostAllowed('a.img.example.com', ['img.example.com']));
        $this->assertTrue(RemoteImageFetcher::hostAllowed('a.b.example.com', ['*.example.com']));
        $this->assertFalse(RemoteImageFetcher::hostAllowed('example.com', ['*.example.com']));
        $this->assertFalse(RemoteImageFetcher::hostAllowed('badexample.com', ['*.example.com']));
        $this->assertFalse(RemoteImageFetcher::hostAllowed('x.com', ['*.com']), 'a wildcard over a bare TLD is refused');
        $this->assertFalse(RemoteImageFetcher::hostAllowed('x.com', ['*']));
        $this->assertFalse(RemoteImageFetcher::hostAllowed('x.com', ['', ' ']));
    }

    // --- DNS ---------------------------------------------------------------------------------------------

    public function test_a_name_that_does_not_resolve_is_refused(): void
    {
        Http::fake();

        $this->assertSame(RemoteImageFetcher::ERR_DNS, $this->refusal(fn () => $this->fetcher([])->fetch(self::URL, self::HOSTS, 1024)));
        $this->assertSame(RemoteImageFetcher::ERR_DNS, $this->refusal(fn () => $this->fetcher(fn () => throw new \RuntimeException('dns down'))->fetch(self::URL, self::HOSTS, 1024)));
        Http::assertNothingSent();
    }

    /**
     * Allow-listed NAMES that resolve to something private: the DNS-rebinding shape. The name passes
     * every textual check; only the answer gives it away.
     *
     * @return array<string, array{list<string>}>
     */
    public static function privateAnswers(): array
    {
        return [
            'loopback' => [['127.0.0.1']],
            'loopback range' => [['127.8.9.10']],
            'private 10' => [['10.0.0.5']],
            'private 172' => [['172.16.0.9']],
            'private 192.168' => [['192.168.1.1']],
            'link-local metadata' => [['169.254.169.254']],
            'carrier-grade nat' => [['100.64.0.1']],
            'unspecified' => [['0.0.0.0']],
            'this network' => [['0.1.2.3']],
            'multicast' => [['224.0.0.1']],
            'reserved' => [['240.0.0.1']],
            'broadcast' => [['255.255.255.255']],
            'documentation' => [['192.0.2.1']],
            'benchmarking' => [['198.18.0.1']],
            'ipv6 loopback' => [['::1']],
            'ipv6 unspecified' => [['::']],
            'ipv6 unique local' => [['fd00::1']],
            'ipv6 unique local fc' => [['fc00::1']],
            'ipv6 link-local' => [['fe80::1']],
            'ipv6 multicast' => [['ff02::1']],
            'ipv6 documentation' => [['2001:db8::1']],
            'ipv4-mapped loopback' => [['::ffff:127.0.0.1']],
            'ipv4-mapped loopback, hex form' => [['::ffff:7f00:1']],
            'ipv4-mapped private' => [['::ffff:10.0.0.1']],
            'ipv4-compatible loopback' => [['::127.0.0.1']],
            'nat64 loopback' => [['64:ff9b::7f00:1']],
            'nat64 metadata' => [['64:ff9b::a9fe:a9fe']],
            '6to4' => [['2002:7f00:1::']],
            'teredo' => [['2001:0:4136:e378:8000:63bf:3fff:fdd2']],
            'one public, one private (rebinding answer set)' => [['142.250.74.33', '127.0.0.1']],
            'one private, one public' => [['10.0.0.1', '142.250.74.33']],
            'public v4, loopback v6' => [['142.250.74.33', '::1']],
            'garbage' => [['not-an-ip']],
        ];
    }

    /**
     * @param list<string> $answers
     */
    #[DataProvider('privateAnswers')]
    public function test_a_host_that_resolves_to_a_non_public_address_is_refused_and_nothing_is_sent(array $answers): void
    {
        Http::fake();

        $this->assertSame(RemoteImageFetcher::ERR_PRIVATE, $this->refusal(fn () => $this->fetcher($answers)->fetch('https://127.0.0.1.nip.googleusercontent.com/a.png', self::HOSTS, 1024)));
        Http::assertNothingSent();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function publicAddresses(): array
    {
        return [
            'google v4' => ['142.250.74.33'],
            'quad8' => ['8.8.8.8'],
            'just outside 10/8' => ['11.0.0.1'],
            'just outside 172.16/12' => ['172.32.0.1'],
            'just outside 100.64/10' => ['100.128.0.1'],
            'just outside 169.254/16' => ['169.255.0.1'],
            'google v6' => ['2a00:1450:4001:81b::200e'],
            'mapped public' => ['::ffff:8.8.8.8'],
            'nat64 public' => ['64:ff9b::808:808'],
        ];
    }

    #[DataProvider('publicAddresses')]
    public function test_public_addresses_pass(string $address): void
    {
        $this->assertTrue(RemoteImageFetcher::isPublicIp($address));

        Http::fake(['*' => Http::response(self::PNG)]);

        $this->assertSame('image/png', $this->fetcher([$address])->fetch(self::URL, self::HOSTS, 1024)->mime);
    }

    public function test_dns_names_are_judged_by_shape(): void
    {
        foreach (['lh7-rt.googleusercontent.com', 'a.b', 'xn--80ak6aa92e.com', 'a-b.c-d.example'] as $name) {
            $this->assertTrue(RemoteImageFetcher::isDnsName($name), $name);
        }

        foreach (['localhost', '127.0.0.1', '127.1', '2130706433', '0x7f.1', '-a.com', 'a-.com', 'a..com', '.a.com', 'a.com.', 'a_b.com', '', str_repeat('a', 64).'.com'] as $name) {
            $this->assertFalse(RemoteImageFetcher::isDnsName($name), $name);
        }
    }
}
