<?php

use Aws\SesV2\SesV2Client;
use Aws\Sns\SnsClient;
use OpeTech\LaravelSes\Actions\Sns\CreateConfigurationSet;
use OpeTech\LaravelSes\Actions\Sns\CreateSnsTopicWithHttpSubscription;
use OpeTech\LaravelSes\Actions\Sns\GetTopicArn;

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
