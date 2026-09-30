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
 * trust decision is never cached, only the fetched certificate body. Failed
 * fetches are returned uncached so the validator rejects the message and the
 * next request retries the download.
 */
class CachedSigningCertificate
{
    /**
     * @return string|false
     */
    public function __invoke(string $certUrl)
    {
        $seconds = (int) config('laravelses.sns_certificate_cache_seconds');

        if ($seconds <= 0) {
            return $this->fetch($certUrl);
        }

        $key = 'laravel-ses:sns-certificate:'.sha1($certUrl);

        $certificate = Cache::get($key);

        if ($certificate !== null) {
            return $certificate;
        }

        $certificate = $this->fetch($certUrl);

        if ($certificate !== false) {
            Cache::put($key, $certificate, $seconds);
        }

        return $certificate;
    }

    /**
     * @return string|false
     */
    protected function fetch(string $certUrl)
    {
        return @file_get_contents($certUrl);
    }
}
