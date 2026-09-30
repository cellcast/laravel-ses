<?php

namespace OpeTech\LaravelSes\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Certificate client for Aws\Sns\MessageValidator that caches the signing
 * certificate instead of fetching it over HTTPS on every notification.
 *
 * SNS serves the same certificate URL for long stretches, but the validator's
 * default client downloads it per request — under event-publishing bursts
 * (one delivery notification per recipient) that HTTPS round-trip dominates
 * webhook response time and can saturate the endpoint.
 *
 * Safe to cache because MessageValidator::validateUrl() pins the URL to a
 * genuine sns.<region>.amazonaws.com host BEFORE this client is called: the
 * trust decision is never cached, only the fetched certificate body.
 *
 * Nothing that fails to parse as a certificate is ever cached: a failed,
 * empty or truncated download (openssl_get_publickey() — the same call the
 * validator makes — rejects it) returns false uncached, so the validator
 * rejects that one message and the next request retries the download. This
 * matters: caching one bad body would 401 every notification for the full
 * TTL, and SNS retries are finite.
 */
class CachedSigningCertificate
{
    /**
     * @return string|false
     */
    public function __invoke(string $certUrl)
    {
        $seconds = (int) config('laravelses.sns_certificate_cache_seconds');

        $key = 'laravel-ses:sns-certificate:'.sha1($certUrl);

        if ($seconds > 0 && ($cached = Cache::get($key)) !== null) {
            return $cached;
        }

        $certificate = $this->fetch($certUrl);

        if (! is_string($certificate)
            || $certificate === ''
            || openssl_get_publickey($certificate) === false
        ) {
            return false;
        }

        if ($seconds > 0) {
            Cache::put($key, $certificate, $seconds);
        }

        return $certificate;
    }

    /**
     * @return string|false
     */
    protected function fetch(string $certUrl)
    {
        // The timeout stops a hung download holding a worker; a fetch cut off
        // mid-body returns partial content, which the parse gate above refuses.
        return @file_get_contents($certUrl, false, stream_context_create([
            'http' => ['timeout' => 5],
        ]));
    }
}
