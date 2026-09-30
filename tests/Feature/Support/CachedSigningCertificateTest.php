<?php

use Illuminate\Support\Facades\Cache;
use OpeTech\LaravelSes\Support\CachedSigningCertificate;

function partialCertificateClient(): CachedSigningCertificate
{
    return Mockery::mock(CachedSigningCertificate::class)
        ->makePartial()
        ->shouldAllowMockingProtectedMethods();
}

function certificateA(): string
{
    return file_get_contents(__DIR__.'/../../Fixtures/certificate-a.pem');
}

function certificateB(): string
{
    return file_get_contents(__DIR__.'/../../Fixtures/certificate-b.pem');
}

beforeEach(function () {
    Cache::flush();
});

test('the certificate is fetched once and served from cache afterwards', function () {
    config(['laravelses.sns_certificate_cache_seconds' => 3600]);

    $client = partialCertificateClient();
    $client->shouldReceive('fetch')->once()->andReturn(certificateA());

    expect($client('https://sns.eu-west-2.amazonaws.com/cert.pem'))->toBe(certificateA());
    expect($client('https://sns.eu-west-2.amazonaws.com/cert.pem'))->toBe(certificateA());
});

test('different certificate urls are cached independently', function () {
    config(['laravelses.sns_certificate_cache_seconds' => 3600]);

    $client = partialCertificateClient();
    $client->shouldReceive('fetch')->twice()->andReturn(certificateA(), certificateB());

    expect($client('https://sns.eu-west-2.amazonaws.com/a.pem'))->toBe(certificateA());
    expect($client('https://sns.eu-west-2.amazonaws.com/b.pem'))->toBe(certificateB());
    expect($client('https://sns.eu-west-2.amazonaws.com/a.pem'))->toBe(certificateA());
});

test('a failed fetch is not cached and is retried on the next call', function () {
    config(['laravelses.sns_certificate_cache_seconds' => 3600]);

    $client = partialCertificateClient();
    $client->shouldReceive('fetch')->twice()->andReturn(false, certificateA());

    expect($client('https://sns.eu-west-2.amazonaws.com/cert.pem'))->toBeFalse();
    expect($client('https://sns.eu-west-2.amazonaws.com/cert.pem'))->toBe(certificateA());
});

test('an empty download is rejected, never cached, and retried on the next call', function () {
    config(['laravelses.sns_certificate_cache_seconds' => 3600]);

    $client = partialCertificateClient();
    $client->shouldReceive('fetch')->twice()->andReturn('', certificateA());

    expect($client('https://sns.eu-west-2.amazonaws.com/cert.pem'))->toBeFalse();
    expect($client('https://sns.eu-west-2.amazonaws.com/cert.pem'))->toBe(certificateA());
});

test('a body that does not parse as a certificate is rejected and never cached', function () {
    config(['laravelses.sns_certificate_cache_seconds' => 3600]);

    $truncated = substr(certificateA(), 0, 200);

    $client = partialCertificateClient();
    $client->shouldReceive('fetch')->times(3)->andReturn('not a pem', $truncated, certificateA());

    expect($client('https://sns.eu-west-2.amazonaws.com/cert.pem'))->toBeFalse();
    expect($client('https://sns.eu-west-2.amazonaws.com/cert.pem'))->toBeFalse();
    expect($client('https://sns.eu-west-2.amazonaws.com/cert.pem'))->toBe(certificateA());
});

test('a cache ttl of zero fetches on every call', function () {
    config(['laravelses.sns_certificate_cache_seconds' => 0]);

    $client = partialCertificateClient();
    $client->shouldReceive('fetch')->twice()->andReturn(certificateA(), certificateA());

    expect($client('https://sns.eu-west-2.amazonaws.com/cert.pem'))->toBe(certificateA());
    expect($client('https://sns.eu-west-2.amazonaws.com/cert.pem'))->toBe(certificateA());
});

test('the package ships with a one day cache by default', function () {
    expect(file_get_contents(__DIR__.'/../../../config/laravelses.php'))
        ->toContain("'sns_certificate_cache_seconds' => env('SES_SNS_CERT_CACHE_SECONDS', 86400),");
});
