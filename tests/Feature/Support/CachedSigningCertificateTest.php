<?php

use Illuminate\Support\Facades\Cache;
use OpeTech\LaravelSes\Support\CachedSigningCertificate;

function partialCertificateClient(): CachedSigningCertificate
{
    return Mockery::mock(CachedSigningCertificate::class)
        ->makePartial()
        ->shouldAllowMockingProtectedMethods();
}

beforeEach(function () {
    Cache::flush();
});

test('the certificate is fetched once and served from cache afterwards', function () {
    config(['laravelses.sns_certificate_cache_seconds' => 3600]);

    $client = partialCertificateClient();
    $client->shouldReceive('fetch')->once()->andReturn('CERTIFICATE-BODY');

    expect($client('https://sns.eu-west-2.amazonaws.com/cert.pem'))->toBe('CERTIFICATE-BODY');
    expect($client('https://sns.eu-west-2.amazonaws.com/cert.pem'))->toBe('CERTIFICATE-BODY');
});

test('different certificate urls are cached independently', function () {
    config(['laravelses.sns_certificate_cache_seconds' => 3600]);

    $client = partialCertificateClient();
    $client->shouldReceive('fetch')->twice()->andReturn('CERT-A', 'CERT-B');

    expect($client('https://sns.eu-west-2.amazonaws.com/a.pem'))->toBe('CERT-A');
    expect($client('https://sns.eu-west-2.amazonaws.com/b.pem'))->toBe('CERT-B');
    expect($client('https://sns.eu-west-2.amazonaws.com/a.pem'))->toBe('CERT-A');
});

test('a failed fetch is not cached and is retried on the next call', function () {
    config(['laravelses.sns_certificate_cache_seconds' => 3600]);

    $client = partialCertificateClient();
    $client->shouldReceive('fetch')->twice()->andReturn(false, 'CERTIFICATE-BODY');

    expect($client('https://sns.eu-west-2.amazonaws.com/cert.pem'))->toBeFalse();
    expect($client('https://sns.eu-west-2.amazonaws.com/cert.pem'))->toBe('CERTIFICATE-BODY');
});

test('a cache ttl of zero fetches on every call', function () {
    config(['laravelses.sns_certificate_cache_seconds' => 0]);

    $client = partialCertificateClient();
    $client->shouldReceive('fetch')->twice()->andReturn('CERTIFICATE-BODY');

    $client('https://sns.eu-west-2.amazonaws.com/cert.pem');
    $client('https://sns.eu-west-2.amazonaws.com/cert.pem');
});

test('the package ships with a one day cache by default', function () {
    expect(file_get_contents(__DIR__.'/../../../config/laravelses.php'))
        ->toContain("'sns_certificate_cache_seconds' => env('SES_SNS_CERT_CACHE_SECONDS', 86400),");
});
