<?php

namespace OpeTech\LaravelSes\Http\Controllers\Notifications;

use Aws\Sns\Exception\InvalidSnsMessageException;
use Aws\Sns\Message;
use Aws\Sns\MessageValidator;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use OpeTech\LaravelSes\Actions\SesEvents\PersistBounceNotification;
use OpeTech\LaravelSes\Actions\SesEvents\PersistClickNotification;
use OpeTech\LaravelSes\Actions\SesEvents\PersistComplaintNotification;
use OpeTech\LaravelSes\Actions\SesEvents\PersistDeliveryNotification;
use OpeTech\LaravelSes\Actions\SesEvents\PersistOpenNotification;
use OpeTech\LaravelSes\Actions\SesEvents\PersistRejectNotification;
use OpeTech\LaravelSes\Actions\Sns\ConfirmSubscription;
use OpeTech\LaravelSes\Enums\SesEvents;
use OpeTech\LaravelSes\Support\CachedSigningCertificate;

class NotificationController extends Controller
{
    public function notification(Request $request)
    {
        $content = json_decode($request->getContent(), true);

        $this->validateSnsSignature($content);

        if ($content['Type'] == 'Notification') {
            $content['Message'] = json_decode($content['Message'], true) ?? $content['Message'];
        }

        $snsMessage = new Message($content);

        if ($snsMessage['Message'] == 'Successfully validated SNS topic for Amazon SES event publishing.') {
            return response()->json([
                'message' => 'Success',
            ]);
        }

        if ($snsMessage['Type'] == 'SubscriptionConfirmation') {
            return $this->confirmSubscription($snsMessage);
        }

        $this->persistNotification($snsMessage);

        return response()->json([
            'message' => 'Success.',
        ]);
    }

    /**
     * Verify the SNS message signature before acting on the payload. Without
     * this, anyone who can guess a message id can forge bounces/complaints
     * (which unsubscribe recipients) or corrupt engagement stats.
     */
    protected function validateSnsSignature(array $content): void
    {
        if (! config('laravelses.aws_sns_validator')) {
            return;
        }

        try {
            (new MessageValidator(app(CachedSigningCertificate::class)))->validate(new Message($content));
        } catch (InvalidSnsMessageException $e) {
            abort(401, 'SNS message signature could not be verified.');
        }
    }

    protected function confirmSubscription(Message $message)
    {
        ConfirmSubscription::run($message);

        return response()->json([
            'message' => 'Subscription Confirmed.',
        ]);
    }

    protected function persistNotification(Message $message)
    {
        $notificationType = $message['Message']['eventType'];

        match ($notificationType) {
            SesEvents::Bounce->value => PersistBounceNotification::dispatch($message),
            SesEvents::Complaint->value => PersistComplaintNotification::dispatch($message),
            SesEvents::Open->value => PersistOpenNotification::dispatch($message),
            SesEvents::Delivery->value => PersistDeliveryNotification::dispatch($message),
            SesEvents::Click->value => PersistClickNotification::dispatch($message),
            SesEvents::Reject->value => PersistRejectNotification::dispatch($message),
            default => null,
        };
    }
}
