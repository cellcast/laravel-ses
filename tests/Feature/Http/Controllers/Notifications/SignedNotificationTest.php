<?php

use Illuminate\Testing\TestResponse;
use OpeTech\LaravelSes\Support\CachedSigningCertificate;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * End-to-end proof of the accepted path: a genuinely signed SNS payload passes
 * MessageValidator through the cached certificate client. The fixture key pair
 * (tests/Fixtures) stands in for AWS's; the client's fetch() is stubbed so no
 * network is needed, and the SigningCertURL host is a real sns.<region> one so
 * validateUrl() passes and the client is actually invoked.
 */
function signedNotification(): array
{
    $content = [
        'Type' => 'Notification',
        'MessageId' => '11111111-2222-3333-4444-555555555555',
        'TopicArn' => 'arn:aws:sns:eu-west-2:123456789012:laravel-ses-tests',
        'Message' => json_encode(['eventType' => 'Send']),
        'Timestamp' => '2026-09-30T12:00:00.000Z',
        'SignatureVersion' => '1',
        'SigningCertURL' => 'https://sns.eu-west-2.amazonaws.com/SimpleNotificationService-test.pem',
    ];

    // Mirrors MessageValidator::getStringToSign() for the keys present.
    $stringToSign = '';
    foreach (['Message', 'MessageId', 'Subject', 'SubscribeURL', 'Timestamp', 'Token', 'TopicArn', 'Type'] as $key) {
        if (isset($content[$key])) {
            $stringToSign .= "{$key}\n{$content[$key]}\n";
        }
    }

    $privateKey = openssl_pkey_get_private(file_get_contents(__DIR__.'/../../../../Fixtures/private-key-a.pem'));
    openssl_sign($stringToSign, $signature, $privateKey, OPENSSL_ALGO_SHA1);
    $content['Signature'] = base64_encode($signature);

    return $content;
}

function postSignedNotification(): TestResponse
{
    return test()->call(
        method: 'post',
        uri: '/laravel-ses/sns-notification',
        server: ['HTTP_x-amz-sns-message-type' => 'Notification'],
        content: json_encode(signedNotification()),
    );
}

function bindCertificateClient(): CachedSigningCertificate
{
    $client = Mockery::mock(CachedSigningCertificate::class)
        ->makePartial()
        ->shouldAllowMockingProtectedMethods();

    app()->instance(CachedSigningCertificate::class, $client);

    return $client;
}

it('accepts a correctly signed notification via the cached certificate client, fetching the certificate once', function () {
    config()->set('laravelses.aws_sns_validator', true);
    config()->set('laravelses.sns_certificate_cache_seconds', 3600);

    $client = bindCertificateClient();
    $client->shouldReceive('fetch')->once()
        ->andReturn(file_get_contents(__DIR__.'/../../../../Fixtures/certificate-a.pem'));

    postSignedNotification()->assertOk();
    postSignedNotification()->assertOk();
});

it('rejects a signed notification with 401 when the certificate cannot be downloaded', function () {
    config()->set('laravelses.aws_sns_validator', true);
    config()->set('laravelses.sns_certificate_cache_seconds', 3600);

    $client = bindCertificateClient();
    $client->shouldReceive('fetch')->andReturn(false);

    try {
        postSignedNotification();
        $this->fail('Expected a 401 HttpException.');
    } catch (HttpException $e) {
        expect($e->getStatusCode())->toBe(401);
    }
});

it('rejects a signed notification whose payload was tampered with after signing', function () {
    config()->set('laravelses.aws_sns_validator', true);
    config()->set('laravelses.sns_certificate_cache_seconds', 3600);

    $client = bindCertificateClient();
    $client->shouldReceive('fetch')
        ->andReturn(file_get_contents(__DIR__.'/../../../../Fixtures/certificate-a.pem'));

    $content = signedNotification();
    $content['Message'] = json_encode(['eventType' => 'Bounce', 'forged' => true]);

    try {
        test()->call(
            method: 'post',
            uri: '/laravel-ses/sns-notification',
            server: ['HTTP_x-amz-sns-message-type' => 'Notification'],
            content: json_encode($content),
        );
        $this->fail('Expected a 401 HttpException.');
    } catch (HttpException $e) {
        expect($e->getStatusCode())->toBe(401);
    }
});
