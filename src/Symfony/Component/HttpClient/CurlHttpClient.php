<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\HttpClient;

use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\Response\CurlResponse;
use Symfony\Component\HttpClient\Response\ResponseStream;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

/**
 * A performant implementation of the HttpClientInterface contracts based on the curl extension.
 *
 * This provides fully concurrent HTTP requests, with transparent
 * HTTP/2 push when a curl version that supports it is installed.
 *
 * @author Nicolas Grekas <p@tchwork.com>
 *
 * @experimental in 4.3
 */
final class CurlHttpClient implements HttpClientInterface
{
    use HttpClientTrait;

    private $defaultOptions = self::OPTIONS_DEFAULTS;
    private $multi;

    /**
     * @param array $defaultOptions     Default requests' options
     * @param int   $maxHostConnections The maximum number of connections to a single host
     *
     * @see HttpClientInterface::OPTIONS_DEFAULTS for available options
     */
    public function __construct(array $defaultOptions = [], int $maxHostConnections = 6)
    {
        if ($defaultOptions) {
            [, $this->defaultOptions] = self::prepareRequest(null, null, $defaultOptions, self::OPTIONS_DEFAULTS);
        }

        $mh = curl_multi_init();
        $this->defaultOptions['timeout'] = (float) ($this->defaultOptions['timeout'] ?? ini_get('default_socket_timeout'));

        // Don't enable HTTP/1.1 pipelining: it forces responses to be sent in order
        if (\defined('CURLPIPE_MULTIPLEX')) {
            curl_multi_setopt($mh, CURLMOPT_PIPELINING, CURLPIPE_MULTIPLEX);
        }
        curl_multi_setopt($mh, CURLMOPT_MAX_HOST_CONNECTIONS, 0 < $maxHostConnections ? $maxHostConnections : PHP_INT_MAX);

        // Use an internal stdClass object to share state between the client and its responses
        $this->multi = $multi = (object) [
            'openHandles' => [],
            'handlesActivity' => [],
            'handle' => $mh,
            'pushedResponses' => [],
            'dnsCache' => [[], [], []],
        ];

        // Skip configuring HTTP/2 push when it's unsupported or buggy, see https://bugs.php.net/76675
        if (\PHP_VERSION_ID < 70215 || \PHP_VERSION_ID === 70300 || \PHP_VERSION_ID === 70301) {
            return;
        }

        // HTTP/2 push crashes before curl 7.61
        if (!\defined('CURLMOPT_PUSHFUNCTION') || 0x073d00 > ($v = curl_version())['version_number'] || !(CURL_VERSION_HTTP2 & $v['features'])) {
            return;
        }

        // Keep a dummy "onPush" reference to work around a refcount bug in PHP
        curl_multi_setopt($mh, CURLMOPT_PUSHFUNCTION, $multi->onPush = static function ($parent, $pushed, array $rawHeaders) use ($multi) {
            return self::handlePush($parent, $pushed, $rawHeaders, $multi);
        });
    }

    /**
     * @see HttpClientInterface::OPTIONS_DEFAULTS for available options
     *
     * {@inheritdoc}
     */
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        [$url, $options] = self::prepareRequest($method, $url, $options, $this->defaultOptions);
        $scheme = $url['scheme'];
        $authority = $url['authority'];
        $host = parse_url($authority, PHP_URL_HOST);
        $url = implode('', $url);

        if ([$pushedResponse, $pushedHeaders] = $this->multi->pushedResponses[$url] ?? null) {
            unset($this->multi->pushedResponses[$url]);
            // Accept pushed responses only if their headers related to authentication match the request
            $expectedHeaders = [
                $options['headers']['authorization'] ?? null,
                $options['headers']['cookie'] ?? null,
                $options['headers']['x-requested-with'] ?? null,
                $options['headers']['range'] ?? null,
            ];

            if ('GET' === $method && !$options['body'] && $expectedHeaders === $pushedHeaders) {
                // Reinitialize the pushed response with request's options
                $pushedResponse->__construct($this->multi, $url, $options);

                return $pushedResponse;
            }
        }

        $curlopts = [
            CURLOPT_URL => $url,
            CURLOPT_USERAGENT => 'Symfony HttpClient/Curl',
            CURLOPT_TCP_NODELAY => true,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 0 < $options['max_redirects'] ? $options['max_redirects'] : 0,
            CURLOPT_COOKIEFILE => '', // Keep track of cookies during redirects
            CURLOPT_CONNECTTIMEOUT_MS => 1000 * $options['timeout'],
            CURLOPT_PROXY => $options['proxy'],
            CURLOPT_NOPROXY => $options['no_proxy'] ?? $_SERVER['no_proxy'] ?? $_SERVER['NO_PROXY'] ?? '',
            CURLOPT_SSL_VERIFYPEER => $options['verify_peer'],
            CURLOPT_SSL_VERIFYHOST => $options['verify_host'] ? 2 : 0,
            CURLOPT_CAINFO => $options['cafile'],
            CURLOPT_CAPATH => $options['capath'],
            CURLOPT_SSL_CIPHER_LIST => $options['ciphers'],
            CURLOPT_SSLCERT => $options['local_cert'],
            CURLOPT_SSLKEY => $options['local_pk'],
            CURLOPT_KEYPASSWD => $options['passphrase'],
            CURLOPT_CERTINFO => $options['capture_peer_cert_chain'],
        ];

        if (!ZEND_THREAD_SAFE) {
            $curlopts[CURLOPT_DNS_USE_GLOBAL_CACHE] = false;
        }

        if (\defined('CURLOPT_HEADEROPT')) {
            $curlopts[CURLOPT_HEADEROPT] = CURLHEADER_SEPARATE;
        }

        // curl's resolve feature varies by host:port but ours varies by host only, let's handle this with our own DNS map
        if (isset($this->multi->dnsCache[0][$host])) {
            $options['resolve'] += [$host => $this->multi->dnsCache[0][$host]];
        }

        if ($options['resolve'] || $this->multi->dnsCache[2]) {
            // First reset any old DNS cache entries then add the new ones
            $resolve = $this->multi->dnsCache[2];
            $this->multi->dnsCache[2] = [];
            $port = parse_url($authority, PHP_URL_PORT) ?: ('http:' === $scheme ? 80 : 443);

            if ($resolve && 0x072a00 > curl_version()['version_number']) {
                // DNS cache removals require curl 7.42 or higher
                // On lower versions, we have to create a new multi handle
                curl_multi_close($this->multi->handle);
                $this->multi->handle = (new self())->multi->handle;
            }

            foreach ($options['resolve'] as $host => $ip) {
                $resolve[] = null === $ip ? "-$host:$port" : "$host:$port:$ip";
                $this->multi->dnsCache[0][$host] = $ip;
                $this->multi->dnsCache[1]["-$host:$port"] = "-$host:$port";
            }

            $curlopts[CURLOPT_RESOLVE] = $resolve;
        }

        if (1.0 === (float) $options['http_version']) {
            $curlopts[CURLOPT_HTTP_VERSION] = CURL_HTTP_VERSION_1_0;
        } elseif (1.1 === (float) $options['http_version'] || 'https:' !== $scheme) {
            $curlopts[CURLOPT_HTTP_VERSION] = CURL_HTTP_VERSION_1_1;
        } elseif (CURL_VERSION_HTTP2 & curl_version()['features']) {
            $curlopts[CURLOPT_HTTP_VERSION] = CURL_HTTP_VERSION_2_0;
        }

        if ('POST' === $method) {
            // Use CURLOPT_POST to have browser-like POST-to-GET redirects for 301, 302 and 303
            $curlopts[CURLOPT_POST] = true;
        } else {
            $curlopts[CURLOPT_CUSTOMREQUEST] = $method;
        }

        if ('\\' !== \DIRECTORY_SEPARATOR && $options['timeout'] < 1) {
            $curlopts[CURLOPT_NOSIGNAL] = true;
        }

        if (!isset($options['headers']['accept-encoding'])) {
            $curlopts[CURLOPT_ENCODING] = ''; // Enable HTTP compression
        }

        foreach ($options['raw_headers'] as $header) {
            if (':' === $header[-2] && \strlen($header) - 2 === strpos($header, ': ')) {
                // curl requires a special syntax to send empty headers
                $curlopts[CURLOPT_HTTPHEADER][] = substr_replace($header, ';', -2);
            } else {
                $curlopts[CURLOPT_HTTPHEADER][] = $header;
            }
        }

        // Prevent curl from sending its default Accept and Expect headers
        foreach (['accept', 'expect'] as $header) {
            if (!isset($options['headers'][$header])) {
                $curlopts[CURLOPT_HTTPHEADER][] = $header.':';
            }
        }

        if (!\is_string($body = $options['body'])) {
            if (\is_resource($body)) {
                $curlopts[CURLOPT_INFILE] = $body;
            } else {
                $eof = false;
                $buffer = '';
                $curlopts[CURLOPT_READFUNCTION] = static function ($ch, $fd, $length) use ($body, &$buffer, &$eof) {
                    return self::readRequestBody($length, $body, $buffer, $eof);
                };
            }

            if (isset($options['headers']['content-length'][0])) {
                $curlopts[CURLOPT_INFILESIZE] = $options['headers']['content-length'][0];
            } elseif (!isset($options['headers']['transfer-encoding'])) {
                $curlopts[CURLOPT_HTTPHEADER][] = 'Transfer-Encoding: chunked'; // Enable chunked request bodies
            }

            if ('POST' !== $method) {
                $curlopts[CURLOPT_UPLOAD] = true;
            }
        } elseif ('' !== $body) {
            $curlopts[CURLOPT_POSTFIELDS] = $body;
        }

        if ($options['peer_fingerprint']) {
            if (!isset($options['peer_fingerprint']['pin-sha256'])) {
                throw new TransportException(__CLASS__.' supports only "pin-sha256" fingerprints.');
            }

            $curlopts[CURLOPT_PINNEDPUBLICKEY] = 'sha256//'.implode(';sha256//', $options['peer_fingerprint']['pin-sha256']);
        }

        if ($options['bindto']) {
            $curlopts[file_exists($options['bindto']) ? CURLOPT_UNIX_SOCKET_PATH : CURLOPT_INTERFACE] = $options['bindto'];
        }

        $ch = curl_init();

        foreach ($curlopts as $opt => $value) {
            if (null !== $value && !curl_setopt($ch, $opt, $value) && CURLOPT_CERTINFO !== $opt) {
                $constants = array_filter(get_defined_constants(), static function ($v, $k) use ($opt) {
                    return $v === $opt && 'C' === $k[0] && (0 === strpos($k, 'CURLOPT_') || 0 === strpos($k, 'CURLINFO_'));
                }, ARRAY_FILTER_USE_BOTH);

                throw new TransportException(sprintf('Curl option "%s" is not supported.', key($constants) ?? $opt));
            }
        }

        return new CurlResponse($this->multi, $ch, $options, $method, self::createRedirectResolver($options, $host));
    }

    /**
     * {@inheritdoc}
     */
    public function stream($responses, float $timeout = null): ResponseStreamInterface
    {
        if ($responses instanceof CurlResponse) {
            $responses = [$responses];
        } elseif (!\is_iterable($responses)) {
            throw new \TypeError(sprintf('%s() expects parameter 1 to be an iterable of CurlResponse objects, %s given.', __METHOD__, \is_object($responses) ? \get_class($responses) : \gettype($responses)));
        }

        while (CURLM_CALL_MULTI_PERFORM === curl_multi_exec($this->multi->handle, $active));

        return new ResponseStream(CurlResponse::stream($responses, $timeout));
    }

    public function __destruct()
    {
        $this->multi->pushedResponses = [];
        if (\defined('CURLMOPT_PUSHFUNCTION')) {
            curl_multi_setopt($this->multi->handle, CURLMOPT_PUSHFUNCTION, null);
        }
    }

    private static function handlePush($parent, $pushed, array $rawHeaders, \stdClass $multi): int
    {
        $headers = [];

        foreach ($rawHeaders as $h) {
            if (false !== $i = strpos($h, ':', 1)) {
                $headers[substr($h, 0, $i)] = substr($h, 1 + $i);
            }
        }

        if ('GET' !== $headers[':method'] || isset($headers['range'])) {
            return CURL_PUSH_DENY;
        }

        $url = $headers[':scheme'].'://'.$headers[':authority'];

        // curl before 7.65 doesn't validate the pushed ":authority" header,
        // but this is a MUST in the HTTP/2 RFC; let's restrict pushes to the original host,
        // ignoring domains mentioned as alt-name in the certificate for now (same as curl).
        if (0 !== strpos(curl_getinfo($parent, CURLINFO_EFFECTIVE_URL), $url.'/')) {
            return CURL_PUSH_DENY;
        }

        $multi->pushedResponses[$url.$headers[':path']] = [
            new CurlResponse($multi, $pushed),
            [
                $headers['authorization'] ?? null,
                $headers['cookie'] ?? null,
                $headers['x-requested-with'] ?? null,
                null,
            ],
        ];

        return CURL_PUSH_OK;
    }

    /**
     * Wraps the request's body callback to allow it to return strings longer than curl requested.
     */
    private static function readRequestBody(int $length, \Closure $body, string &$buffer, bool &$eof): string
    {
        if (!$eof && \strlen($buffer) < $length) {
            if (!\is_string($data = $body($length))) {
                throw new TransportException(sprintf('The return value of the "body" option callback must be a string, %s returned.', \gettype($data)));
            }

            $buffer .= $data;
            $eof = '' === $data;
        }

        $data = substr($buffer, 0, $length);
        $buffer = substr($buffer, $length);

        return $data;
    }

    /**
     * Resolves relative URLs on redirects and deals with authentication headers.
     *
     * Work around CVE-2018-1000007: Authorization and Cookie headers should not follow redirects - fixed in Curl 7.64
     */
    private static function createRedirectResolver(array $options, string $host): \Closure
    {
        $redirectHeaders = [];
        if (0 < $options['max_redirects']) {
            $redirectHeaders['host'] = $host;
            $redirectHeaders['with_auth'] = $redirectHeaders['no_auth'] = array_filter($options['raw_headers'], static function ($h) {
                return 0 !== stripos($h, 'Host:');
            });

            if (isset($options['headers']['authorization']) || isset($options['headers']['cookie'])) {
                $redirectHeaders['no_auth'] = array_filter($options['raw_headers'], static function ($h) {
                    return 0 !== stripos($h, 'Authorization:') && 0 !== stripos($h, 'Cookie:');
                });
            }
        }

        return static function ($ch, string $location) use ($redirectHeaders) {
            if ($redirectHeaders && $host = parse_url($location, PHP_URL_HOST)) {
                $rawHeaders = $redirectHeaders['host'] === $host ? $redirectHeaders['with_auth'] : $redirectHeaders['no_auth'];
                curl_setopt($ch, CURLOPT_HTTPHEADER, $rawHeaders);
            }

            $url = self::parseUrl(curl_getinfo($ch, CURLINFO_EFFECTIVE_URL));

            return implode('', self::resolveUrl(self::parseUrl($location), $url));
        };
    }
}
