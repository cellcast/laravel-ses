<?php

use Aws\Exception\AwsException;
use Aws\Result;
use Aws\SesV2\SesV2Client;
use Illuminate\Support\Facades\Mail;
use OpeTech\LaravelSes\Models\LaravelSesBatch;
use OpeTech\LaravelSes\Models\LaravelSesSentEmail;
use OpeTech\LaravelSes\Tests\Resources\Mailables\TestMailable;
use OpeTech\LaravelSes\Transport\LaravelSesTransport;
use Symfony\Component\Mailer\Exception\TransportException;

function fakeSesTransport(?SesV2Client $client = null): LaravelSesTransport
{
    if (! $client) {
        $client = Mockery::mock(SesV2Client::class);
        $client->shouldReceive('sendEmail')->andReturn(new Result(['MessageId' => 'ses-message-id-123']));
    }

    $transport = new LaravelSesTransport($client);

    Mail::mailer('laravel-ses')->setSymfonyTransport($transport);

    return $transport;
}

it('wraps an AWS failure in a TransportException carrying the AWS reason', function () {
    $awsException = Mockery::mock(AwsException::class);
    $awsException->shouldReceive('getAwsErrorMessage')->andReturn('Daily message quota exceeded');

    $client = Mockery::mock(SesV2Client::class);
    $client->shouldReceive('sendEmail')->andThrow($awsException);

    fakeSesTransport($client);

    Mail::mailer('laravel-ses')
        ->to('example@example.com')
        ->send(new TestMailable);
})->throws(TransportException::class, 'Request to AWS SES V2 API failed. Reason: Daily message quota exceeded.');

it('records nothing when the AWS send fails', function () {
    $awsException = Mockery::mock(AwsException::class);
    $awsException->shouldReceive('getAwsErrorMessage')->andReturn('Daily message quota exceeded');

    $client = Mockery::mock(SesV2Client::class);
    $client->shouldReceive('sendEmail')->andThrow($awsException);

    fakeSesTransport($client);

    try {
        Mail::mailer('laravel-ses')->withBatch('doomed')->to('example@example.com')->send(new TestMailable);
    } catch (TransportException) {
        // expected
    }

    expect(LaravelSesSentEmail::count())->toBe(0)
        ->and(LaravelSesBatch::count())->toBe(0);
});

it('records one sent email per recipient sharing the single SES message id', function () {
    // Pins the current multi-recipient behaviour the transport's TODO describes:
    // one SES API call, every recipient logged against the same message id.
    fakeSesTransport();

    Mail::mailer('laravel-ses')
        ->to(['first@example.com', 'second@example.com'])
        ->send(new TestMailable);

    expect(LaravelSesSentEmail::count())->toBe(2)
        ->and(LaravelSesSentEmail::pluck('message_id')->unique()->all())->toBe(['ses-message-id-123'])
        ->and(LaravelSesSentEmail::pluck('email')->sort()->values()->all())->toBe(['first@example.com', 'second@example.com']);
});

it('reuses an existing batch instead of creating a duplicate', function () {
    fakeSesTransport();

    Mail::mailer('laravel-ses')->withBatch('welcome')->to('one@example.com')->send(new TestMailable);
    Mail::mailer('laravel-ses')->withBatch('welcome')->to('two@example.com')->send(new TestMailable);

    expect(LaravelSesBatch::count())->toBe(1)
        ->and(LaravelSesSentEmail::pluck('batch_id')->unique()->all())->toBe([LaravelSesBatch::first()->id]);
});

it('resets the batch after each send so the next send is unbatched', function () {
    fakeSesTransport();

    Mail::mailer('laravel-ses')->withBatch('one-off')->to('one@example.com')->send(new TestMailable);
    Mail::mailer('laravel-ses')->to('two@example.com')->send(new TestMailable);

    expect(LaravelSesSentEmail::whereEmail('two@example.com')->first()->batch_id)->toBeNull();
});

it('stamps the SES message id onto the sent message headers', function () {
    fakeSesTransport();

    $sent = Mail::mailer('laravel-ses')
        ->to('example@example.com')
        ->send(new TestMailable);

    $headers = $sent->getSymfonySentMessage()->getOriginalMessage()->getHeaders();

    expect($headers->get('X-Message-ID')->getBodyAsString())->toBe('ses-message-id-123')
        ->and($headers->get('X-SES-Message-ID')->getBodyAsString())->toBe('ses-message-id-123');
});

it('exposes its client, options and transport name', function () {
    $client = Mockery::mock(SesV2Client::class);
    $transport = new LaravelSesTransport($client);

    expect($transport->ses())->toBe($client)
        ->and($transport->setOptions(['foo' => 'bar']))->toBe(['foo' => 'bar'])
        ->and($transport->getOptions())->toBe(['foo' => 'bar'])
        ->and((string) $transport)->toBe('laravel-ses');
});
