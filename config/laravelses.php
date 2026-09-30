<?php

return [

    /**
     * Verify the signature of incoming SNS notifications before acting on them.
     * Only disable this in tests, where payloads are hand-built and unsigned.
     */
    'aws_sns_validator' => env('SES_SNS_VALIDATOR', true),

    /**
     * How long (seconds) to cache the SNS signing certificate the validator
     * downloads. The default fetches it once a day instead of once per
     * notification, which is what keeps the webhook fast under event bursts.
     * Set to 0 to fetch on every request.
     */
    'sns_certificate_cache_seconds' => env('SES_SNS_CERT_CACHE_SECONDS', 86400),

    /**
     * Prefixed added to your AWS resources. This is so you can have multiple SES configurations in the same AWS account.
     */
    'prefix' => 'laravel-ses',

    /**
     * Turning these on saves the raw data to the respesctive tables.
     * This can be useful for debugging and auditing.
     * It's not on by default since it will consume a lot of storage in your DB.
     */
    'log_raw_data' => [
        'bounces' => false,
        'complaints' => false,
        'deliveries' => false,
        'sends' => false,
        'opens' => false,
        'clicks' => false,
        'rejects' => false,
    ],

    /**
     * Specify which queue connection to use for the the SES library.
     * If you don't specify a queue connection, it will use the default queue connection.
     */
    'queue_connection' => null,

    /**
     * Specify which queue to use for each of the features. If the queue is null, jobs will be dispatched
     * on the sync connection, meaning all jobs will run synchronously. Would not recommend using this in production.
     * Especially if you're sending mass marketing emails.
     */
    'queues' => [
        'sns_notifications' => null,
    ],
];
