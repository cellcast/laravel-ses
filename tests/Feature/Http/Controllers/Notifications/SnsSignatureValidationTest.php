<?php

use OpeTech\LaravelSes\Models\LaravelSesEmailBounce;
use OpeTech\LaravelSes\Models\LaravelSesSentEmail;
use Symfony\Component\HttpKernel\Exception\HttpException;

function postUnsignedBounce(): void
{
    $content = json_decode(file_get_contents(__DIR__.'/../../../../Resources/Sns/SnsBounceExample.json'), true);

    // a certificate URL outside sns.<region>.amazonaws.com fails validation without any network call
    $content['SigningCertURL'] = 'https://attacker.example.com/cert.pem';

    LaravelSesSentEmail::factory()->create([
        'email' => 'recipient@example.com',
        'message_id' => json_decode($content['Message'], true)['mail']['messageId'],
    ]);

    test()->call(
        method: 'post',
        uri: '/laravel-ses/sns-notification',
        server: ['HTTP_x-amz-sns-message-type' => 'Notification'],
        content: json_encode($content),
    );
}

it('rejects a notification whose signature cannot be verified and persists nothing', function () {
    config()->set('laravelses.aws_sns_validator', true);

    try {
        postUnsignedBounce();
        $this->fail('Expected a 401 HttpException.');
    } catch (HttpException $e) {
        expect($e->getStatusCode())->toBe(401);
    }

    expect(LaravelSesEmailBounce::count())->toBe(0);
});

it('processes the same unsigned notification when the validator is disabled', function () {
    config()->set('laravelses.aws_sns_validator', false);

    postUnsignedBounce();

    expect(LaravelSesEmailBounce::count())->toBe(1);
});

it('ships with the validator enabled by default', function () {
    expect(file_get_contents(__DIR__.'/../../../../../config/laravelses.php'))
        ->toContain("'aws_sns_validator' => env('SES_SNS_VALIDATOR', true),");
});
