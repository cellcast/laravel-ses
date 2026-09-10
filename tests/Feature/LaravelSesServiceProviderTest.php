<?php

use Aws\SesV2\SesV2Client;
use Aws\Sns\SnsClient;
use Illuminate\Support\Facades\Mail;
use OpeTech\LaravelSes\Actions\Sns\CreateConfigurationSet;
use OpeTech\LaravelSes\Actions\Sns\CreateSnsTopicWithHttpSubscription;
use OpeTech\LaravelSes\Actions\Sns\GetTopicArn;
use OpeTech\LaravelSes\Transport\LaravelSesTransport;
use Symfony\Component\Mailer\Transport\TransportInterface;

it('builds the sns client from the ses services config for the sns-bound actions', function () {
    foreach ([CreateSnsTopicWithHttpSubscription::class, GetTopicArn::class] as $action) {
        $client = invade_client(app($action));

        expect($client)->toBeInstanceOf(SnsClient::class)
            ->and($client->getRegion())->toBe('eu-west-2');
    }
});

it('builds the ses v2 client from the ses services config for the configuration-set actions', function () {
    $client = invade_client(app(CreateConfigurationSet::class));

    expect($client)->toBeInstanceOf(SesV2Client::class)
        ->and($client->getRegion())->toBe('eu-west-2');
});

function invade_client(object $action): object
{
    $property = (new ReflectionClass($action))->getConstructor()->getParameters()[0]->getName();

    return (fn () => $this->{$property})->call($action);
}

it('builds the laravel-ses mailer transport when it is first used from another class', function () {
    // Regression: the Mail::extend closure once resolved SesV2Client via a contextless
    // Container::make(). Contextual when() bindings only apply to the class at the top of the
    // container's build stack. In this suite mail.default is laravel-ses, so Mail::macro() in
    // boot() built (and cached) the transport while this provider was on the stack and the
    // binding resolved. In an app whose default mailer is anything else, the transport is first
    // built from the job or command calling Mail::mailer(), the lookup misses, and AwsClient's
    // required `array $args` is unresolvable — every real send fataled. Mirror the app: drop
    // the cached mailer and build it from inside a container call on another class, with
    // nothing bound or mocked.
    Mail::forgetMailers();

    $transport = app()->call([new class
    {
        public function build(): TransportInterface
        {
            return Mail::mailer('laravel-ses')->getSymfonyTransport();
        }
    }, 'build']);

    expect($transport)->toBeInstanceOf(LaravelSesTransport::class);

    $client = (fn () => $this->ses)->call($transport);

    expect($client)->toBeInstanceOf(SesV2Client::class)
        ->and($client->getRegion())->toBe('eu-west-2')
        ->and($client->getCredentials()->wait()->getAccessKeyId())->toBe('testkey');
});
