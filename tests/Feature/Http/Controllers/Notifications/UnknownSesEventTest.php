<?php

use Illuminate\Support\Facades\Queue;
use OpeTech\LaravelSes\Models\LaravelSesEmailBounce;
use OpeTech\LaravelSes\Models\LaravelSesEmailClick;
use OpeTech\LaravelSes\Models\LaravelSesEmailComplaint;
use OpeTech\LaravelSes\Models\LaravelSesEmailDelivery;
use OpeTech\LaravelSes\Models\LaravelSesEmailOpen;
use OpeTech\LaravelSes\Models\LaravelSesEmailReject;

it('acknowledges an unhandled ses event type without persisting anything', function () {
    Queue::fake();

    $content = json_decode(file_get_contents(__DIR__.'/../../../../Resources/Sns/SnsOpenExample.json'), true);
    $message = json_decode($content['Message'], true);
    $message['eventType'] = 'Send';
    $content['Message'] = json_encode($message);

    $response = test()->call(
        method: 'post',
        uri: '/laravel-ses/sns-notification',
        server: ['HTTP_x-amz-sns-message-type' => 'Notification'],
        content: json_encode($content),
    );

    $response->assertOk()->assertJson(['message' => 'Success.']);

    expect(LaravelSesEmailOpen::count())->toBe(0)
        ->and(LaravelSesEmailBounce::count())->toBe(0)
        ->and(LaravelSesEmailClick::count())->toBe(0)
        ->and(LaravelSesEmailComplaint::count())->toBe(0)
        ->and(LaravelSesEmailDelivery::count())->toBe(0)
        ->and(LaravelSesEmailReject::count())->toBe(0);

    Queue::assertNothingPushed();
});
