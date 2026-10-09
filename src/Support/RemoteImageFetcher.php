<?php

declare(strict_types=1);

namespace Asignua\RichEditorToolkit\Support;

use Closure;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use RuntimeException;
use Throwable;

/**
 * Downloads ONE picture from a remote host on behalf of the editor: Google Docs puts
 * `<img src="https://lh7-rt.googleusercontent.com/...">` on the clipboard, and a browser cannot
 * fetch that itself (no CORS headers).
 *
 * This is a server-side request whose URL comes from the browser, i.e. an SSRF primitive, so every
 * step is a gate and none is optional:
 *
 *  1. SYNTAX. https only, port 443, no credentials, no whitespace/control characters/backslashes
 *     (parsers disagree about those: `https://good.test\@evil.test`), a plain DNS name (an IP
 *     literal, `localhost`, a numeric last label such as `2130706433` are all refused).
 *  2. ALLOW-LIST. The host must match `paste_clean.remote_image_hosts`: `*.example.com` matches any
 *     subdomain (never the bare domain), anything else matches exactly.
 *  3. DNS. The name is resolved HERE and EVERY address must be public ({@see self::isPublicIp()}): loopback,
 *     private, link-local (cloud metadata), CGNAT, documentation, multicast and the transition
 *     ranges that wrap an IPv4 address (IPv4-mapped, NAT64, 6to4, Teredo) are refused. One bad
 *     answer refuses the host: a name that resolves to both a public and a private address is how
 *     rebinding attacks are built.
 *  4. PINNING. The connection is made to the address that was just checked (`CURLOPT_RESOLVE`), so
 *     a second lookup by the HTTP client cannot return something else (DNS rebinding between check
 *     and use). TLS still verifies the certificate against the host name.
 *  5. NO REDIRECTS. A 3xx is a failure: a redirect is a new URL that would need steps 1-4 again, and a
 *     allow-listed host that redirects elsewhere is exactly the open-redirect abuse this guards.
 *  6. LIMITS. Connect/total timeout and a byte cap on the BYTES THAT COME OFF THE WIRE: `Content-Length`
 *     (`on_headers`), libcurl's own `CURLOPT_MAXFILESIZE_LARGE`, and a capped sink that refuses the
 *     write that crosses the cap (which makes libcurl abort the transfer). The response is never
 *     decompressed ({@see self::requestOptions()}): a body that arrives `Content-Encoding: gzip`
 *     is refused, because a few kilobytes of gzip inflate into gigabytes (a "gzip bomb").
 *  6a. NO PROXY. The request goes out WITHOUT a proxy, whatever `HTTPS_PROXY` / `ALL_PROXY` say:
 *     the pin in step 4 only fixes where the client connects, and with a proxy that is the proxy,
 *     which then resolves the name itself and can reach an internal address. A server that can only
 *     get out through an egress proxy cannot use remote pictures (Word's keep working).
 *  7. CONTENT. The type is decided by the file's MAGIC BYTES (png, jpeg, gif, webp), never by the
 *     `Content-Type` the remote server claims; anything else (svg, html, ...) is refused.
 *
 * The DNS lookup is injectable (constructor) so the guard is testable without a network.
 */
final class RemoteImageFetcher
{
    public const string ERR_URL = 'url';

    public const string ERR_HOST = 'host';

    public const string ERR_DNS = 'dns';

    public const string ERR_PRIVATE = 'private';

    public const string ERR_HTTP = 'http';

    public const string ERR_SIZE = 'size';

    private const string TOO_LARGE = 'asignua-rte: too large';

    private const string ENCODED = 'asignua-rte: content-encoding';

    public const string ERR_TYPE = 'type';

    /**
     * Addresses that are not on the public internet, as `network/prefix`.
     *
     * @var list<string>
     */
    private const array NON_PUBLIC_V4 = [
        '0.0.0.0/8',        // "this network"
        '10.0.0.0/8',       // private
        '100.64.0.0/10',    // carrier-grade NAT
        '127.0.0.0/8',      // loopback
        '169.254.0.0/16',   // link-local, cloud metadata (169.254.169.254)
        '172.16.0.0/12',    // private
        '192.0.0.0/24',     // IETF protocol assignments
        '192.0.2.0/24',     // documentation
        '192.88.99.0/24',   // 6to4 relay (deprecated)
        '192.168.0.0/16',   // private
        '198.18.0.0/15',    // benchmarking
        '198.51.100.0/24',  // documentation
        '203.0.113.0/24',   // documentation
        '224.0.0.0/4',      // multicast
        '240.0.0.0/4',      // reserved + broadcast
    ];

    /** @var list<string> */
    private const array NON_PUBLIC_V6 = [
        '::/128',           // unspecified
        '::1/128',          // loopback
        '::ffff:0:0:0/96',  // IPv4-translated, SIIT (RFC 2765): ::ffff:0:a.b.c.d wraps an IPv4 address
        '64:ff9b:1::/48',   // local-use NAT64
        '100::/64',         // discard-only
        '2001::/32',        // Teredo (wraps an IPv4 address)
        '2001:2::/48',      // benchmarking
        '2001:10::/28',     // ORCHID
        '2001:db8::/32',    // documentation
        '3fff::/20',        // documentation (RFC 9637)
        '5f00::/16',        // SRv6 segment identifiers (RFC 9602)
        '2002::/16',        // 6to4 (wraps an IPv4 address)
        'fc00::/7',         // unique local
        'fe80::/10',        // link-local
        'fec0::/10',        // site-local (deprecated)
        'ff00::/8',         // multicast
    ];

    /**
     * Second-level public suffixes under which unrelated parties register names: a wildcard over
     * one of them (`*.co.uk`) would allow-list every site of the country. Best effort, NOT the
     * Public Suffix List: it holds the common ones. The same list lives in
     * `resources/js/src/paste-images.js` and `tests/fixtures/host-allow.json` pins them together.
     *
     * @var list<string>
     */
    public const array PUBLIC_SUFFIXES = [
        'ac.jp',
        'ac.uk',
        'co.id',
        'co.il',
        'co.in',
        'co.jp',
        'co.kr',
        'co.nz',
        'co.th',
        'co.uk',
        'co.za',
        'com.ar',
        'com.au',
        'com.br',
        'com.cn',
        'com.co',
        'com.eg',
        'com.es',
        'com.hk',
        'com.mx',
        'com.my',
        'com.pe',
        'com.ph',
        'com.pk',
        'com.pl',
        'com.ru',
        'com.sa',
        'com.sg',
        'com.tr',
        'com.tw',
        'com.ua',
        'com.ve',
        'com.vn',
        'edu.au',
        'edu.cn',
        'edu.ua',
        'go.jp',
        'gov.au',
        'gov.br',
        'gov.cn',
        'gov.ua',
        'gov.uk',
        'in.ua',
        'kiev.ua',
        'ltd.uk',
        'lviv.ua',
        'me.uk',
        'ne.jp',
        'net.au',
        'net.br',
        'net.cn',
        'net.in',
        'net.nz',
        'net.pl',
        'net.ru',
        'net.tr',
        'net.ua',
        'net.uk',
        'or.jp',
        'or.kr',
        'org.au',
        'org.br',
        'org.cn',
        'org.in',
        'org.nz',
        'org.pl',
        'org.ru',
        'org.tr',
        'org.ua',
        'org.uk',
        'org.za',
        'plc.uk',
        'sch.uk',
    ];

    /** @var Closure(string): list<string> */
    private readonly Closure $resolver;

    /**
     * @param (callable(string): list<string>)|null $resolver host name => IP addresses (A and AAAA);
     *                                                        null = the system resolver
     */
    public function __construct(?callable $resolver = null)
    {
        $this->resolver = $resolver !== null ? Closure::fromCallable($resolver) : self::systemResolver(...);
    }

    /**
     * @param list<string>      $hosts  the allow-list ({@see self::hostAllowed()})
     * @param list<string>|null $accept allowed mime types (null = png, jpeg, gif, webp)
     *
     * @throws RemoteImageException
     */
    public function fetch(string $url, array $hosts, int $maxBytes, ?array $accept = null): RemoteImage
    {
        $host = self::validatedHost($url, $hosts);
        $path = self::requestUrl($url);

        $address = $this->publicAddress($host);

        $sink = new CappedSink($maxBytes);

        try {
            $response = Http::withOptions(self::requestOptions($host, $address, $maxBytes, $sink))
                ->withoutRedirecting()
                ->connectTimeout(5)
                ->timeout(15)
                ->accept('image/*')
                ->withHeaders(['Accept-Encoding' => 'identity'])
                ->get($path);
        } catch (Throwable $exception) {
            throw new RemoteImageException($sink->exceeded || self::isTooLarge($exception) ? self::ERR_SIZE : self::ERR_HTTP, $exception);
        }

        if (!$response->successful()) {
            throw new RemoteImageException(self::ERR_HTTP);
        }

        // The same two checks as `on_headers`, on the finished response: a client that never ran that
        // hook (a fake, a handler that replaces it) must not skip them.
        $encoding = strtolower(trim($response->header('Content-Encoding')));

        if ($encoding !== '' && $encoding !== 'identity') {
            throw new RemoteImageException(self::ERR_HTTP);
        }

        if ((int) $response->header('Content-Length') > $maxBytes) {
            throw new RemoteImageException(self::ERR_SIZE);
        }

        $body = self::readCapped($response->toPsrResponse()->getBody(), $maxBytes);

        $mime = self::sniffMime($body);

        if ($mime === null || ($accept !== null && !in_array($mime, $accept, true))) {
            throw new RemoteImageException(self::ERR_TYPE);
        }

        return new RemoteImage($mime, $body);
    }

    /**
     * The Guzzle options of the request, one place so that a test can pin every one of them.
     *
     * @internal public for the test suite
     *
     * @return array<string, mixed>
     */
    public static function requestOptions(string $host, string $address, int $maxBytes, CappedSink $sink): array
    {
        return [
            // Step 6a. An empty string is Guzzle's "no proxy, and do not look at the environment"
            // (a selected proxy is final; only a missing option falls back to HTTPS_PROXY/ALL_PROXY).
            'proxy' => '',
            // Step 6: the bytes are counted as they arrive, so they must be the bytes that were sent.
            'decode_content' => false,
            'sink' => $sink->stream,
            'curl' => [
                // Step 4. IPv6 goes in brackets.
                CURLOPT_RESOLVE => [$host.':443:'.(str_contains($address, ':') ? '['.$address.']' : $address)],
                // NOT CURLOPT_MAXFILESIZE_LARGE: Guzzle 8 keeps an allow-list of curl options and refuses
                // it with an InvalidArgumentException. The capped sink below is the hard stop instead.
            ],
            'on_headers' => static function (ResponseInterface $response) use ($maxBytes): void {
                if ((int) $response->getHeaderLine('Content-Length') > $maxBytes) {
                    throw new RuntimeException(self::TOO_LARGE);
                }

                $encoding = strtolower(trim($response->getHeaderLine('Content-Encoding')));

                if ($encoding !== '' && $encoding !== 'identity') {
                    throw new RuntimeException(self::ENCODED);
                }
            },
        ];
    }

    /**
     * Reads a body in chunks and never holds more than `$maxBytes` + one chunk.
     *
     * @throws RemoteImageException
     */
    private static function readCapped(StreamInterface $stream, int $maxBytes): string
    {
        if ($stream->isSeekable()) {
            $stream->rewind();
        }

        $body = '';

        while (!$stream->eof()) {
            $body .= $stream->read(8192);

            if (strlen($body) > $maxBytes) {
                throw new RemoteImageException(self::ERR_SIZE);
            }
        }

        return $body;
    }

    /**
     * Steps 1-2: the host of a URL that is syntactically safe and on the allow-list.
     *
     * @param list<string> $hosts
     *
     * @throws RemoteImageException
     */
    public static function validatedHost(string $url, array $hosts): string
    {
        // Backslashes, whitespace and control characters are where URL parsers disagree with each
        // other (and with curl): refuse them instead of guessing which one is right.
        if ($url === '' || strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f\\\\]/', $url) === 1) {
            throw new RemoteImageException(self::ERR_URL);
        }

        $parts = parse_url($url);

        if ($parts === false
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || isset($parts['user']) || isset($parts['pass'])
            || (isset($parts['port']) && $parts['port'] !== 443)
            || !isset($parts['host'])) {
            throw new RemoteImageException(self::ERR_URL);
        }

        $host = strtolower($parts['host']);

        if (!self::isDnsName($host) || !self::hostAllowed($host, $hosts)) {
            throw new RemoteImageException(self::ERR_HOST);
        }

        return $host;
    }

    /**
     * A plain DNS name: letters, digits and hyphens in at least two labels, the last one not
     * numeric (`2130706433`, `0x7f.1`, `127.1` are IP addresses to a browser or curl) and no
     * trailing dot.
     */
    public static function isDnsName(string $host): bool
    {
        if (strlen($host) > 253 || preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $host) !== 1) {
            return false;
        }

        $labels = explode('.', $host);

        return preg_match('/[a-z]/', (string) end($labels)) === 1;
    }

    /**
     * `*.example.com` = any subdomain of example.com (not example.com itself); otherwise an exact,
     * case-insensitive match. A wildcard needs at least two labels after it and they must not be a
     * known public suffix ({@see self::PUBLIC_SUFFIXES}): `*.com`, `*.co.uk` and `*.com.ua` are refused.
     *
     * @param list<string> $patterns
     */
    public static function hostAllowed(string $host, array $patterns): bool
    {
        $host = strtolower($host);

        foreach ($patterns as $pattern) {
            $pattern = strtolower(trim($pattern));

            if ($pattern === '') {
                continue;
            }

            if (str_starts_with($pattern, '*.')) {
                $suffix = substr($pattern, 1);

                if (substr_count($suffix, '.') >= 2
                    && !in_array(substr($suffix, 1), self::PUBLIC_SUFFIXES, true)
                    && str_ends_with($host, $suffix) && strlen($host) > strlen($suffix)) {
                    return true;
                }

                continue;
            }

            if ($host === $pattern) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether an address is on the public internet. Anything that does not parse is NOT public.
     */
    public static function isPublicIp(string $ip): bool
    {
        $binary = @inet_pton($ip);

        if ($binary === false) {
            return false;
        }

        if (strlen($binary) === 4) {
            return !self::inAnyRange($binary, self::NON_PUBLIC_V4);
        }

        // An IPv4 address in IPv6 clothes is judged as the IPv4 address it carries.
        if (str_starts_with($binary, str_repeat("\0", 10)."\xff\xff")) {
            return self::isPublicIp((string) inet_ntop(substr($binary, 12)));
        }

        // NAT64 (64:ff9b::/96): the last four bytes are the IPv4 destination.
        if (str_starts_with($binary, "\0\x64\xff\x9b".str_repeat("\0", 8))) {
            return self::isPublicIp((string) inet_ntop(substr($binary, 12)));
        }

        // The deprecated IPv4-compatible form (::a.b.c.d) is not public either way.
        if (str_starts_with($binary, str_repeat("\0", 12))) {
            return false;
        }

        return !self::inAnyRange($binary, self::NON_PUBLIC_V6);
    }

    /**
     * The first four bytes of a file decide what it is. Returns null for everything that is not
     * png, jpeg, gif or webp (SVG and HTML included, which can carry script).
     */
    public static function sniffMime(string $bytes): ?string
    {
        return match (true) {
            str_starts_with($bytes, "\x89PNG\r\n\x1a\n") => 'image/png',
            str_starts_with($bytes, "\xff\xd8\xff") => 'image/jpeg',
            str_starts_with($bytes, 'GIF87a'), str_starts_with($bytes, 'GIF89a') => 'image/gif',
            strlen($bytes) >= 12 && str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP' => 'image/webp',
            default => null,
        };
    }

    /**
     * Step 3: resolves the host and returns an address to pin the connection to.
     *
     * @throws RemoteImageException
     */
    private function publicAddress(string $host): string
    {
        try {
            $addresses = ($this->resolver)($host);
        } catch (Throwable) {
            $addresses = [];
        }

        if ($addresses === []) {
            throw new RemoteImageException(self::ERR_DNS);
        }

        foreach ($addresses as $address) {
            if (!self::isPublicIp($address)) {
                throw new RemoteImageException(self::ERR_PRIVATE);
            }
        }

        return $addresses[0];
    }

    /**
     * The URL that is requested: rebuilt from the parsed parts (host lower-cased, no fragment, no
     * port), so that what was checked is exactly what is sent.
     */
    private static function requestUrl(string $url): string
    {
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));

        return 'https://'.$host.($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '');
    }

    /**
     * Guzzle wraps whatever a callback throws; look through the chain for our own marker.
     */
    private static function isTooLarge(Throwable $exception): bool
    {
        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof RuntimeException && $current->getMessage() === self::TOO_LARGE) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private static function systemResolver(string $host): array
    {
        $addresses = [];

        foreach (@dns_get_record($host, DNS_A | DNS_AAAA) ?: [] as $record) {
            $address = $record['ip'] ?? $record['ipv6'] ?? null;

            if (is_string($address)) {
                $addresses[] = $address;
            }
        }

        return $addresses;
    }

    /**
     * @param list<string> $ranges
     */
    private static function inAnyRange(string $binary, array $ranges): bool
    {
        foreach ($ranges as $range) {
            [$network, $bits] = explode('/', $range);
            $networkBinary = (string) inet_pton($network);

            if (strlen($networkBinary) !== strlen($binary)) {
                continue;
            }

            $bits = (int) $bits;
            $bytes = intdiv($bits, 8);

            if (substr($binary, 0, $bytes) !== substr($networkBinary, 0, $bytes)) {
                continue;
            }

            $rest = $bits % 8;

            if ($rest === 0) {
                return true;
            }

            $mask = (0xFF << (8 - $rest)) & 0xFF;

            if ((ord($binary[$bytes]) & $mask) === (ord($networkBinary[$bytes]) & $mask)) {
                return true;
            }
        }

        return false;
    }
}
