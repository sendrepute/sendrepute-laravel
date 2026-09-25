<?php

return [
    /*
     * Paid classification is impossible until both this consent and the mail
     * hook are enabled. Keep this false in the package and opt in deliberately.
     */
    'paid_analysis_consent' => (bool) env('SENDREPUTE_PAID_ANALYSIS_CONSENT', false),

    'api_key' => env('SENDREPUTE_API_KEY'),
    'base_url' => env('SENDREPUTE_API_BASE_URL'),

    /*
     * Exact host allowlist for the selected deployment. Wildcards are rejected.
     * Example: ['api.your-sendrepute-deployment.example']
     */
    'trusted_hosts' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('SENDREPUTE_TRUSTED_HOSTS', ''))
    ))),

    'timeout_seconds' => 10.0,
    'connect_timeout_seconds' => 3.0,
    'max_response_bytes' => 1048576,

    /*
     * Optional atomic price authorization. Before enabling it, fetch the
     * authenticated /v1/pricing response and deliberately copy all four
     * effective values below. maximum_charge_millicents is a separate,
     * per-request ceiling and may be lower than the published maximum.
     */
    'price_authorization' => [
        'enabled' => (bool) env('SENDREPUTE_PRICE_AUTHORIZATION_ENABLED', false),
        'expected_pricing' => [
            'classificationBaseMillicents' => env('SENDREPUTE_EXPECTED_CLASSIFICATION_BASE_MILLICENTS'),
            'includedUniqueTerms' => env('SENDREPUTE_EXPECTED_INCLUDED_UNIQUE_TERMS'),
            'additionalTermMillicents' => env('SENDREPUTE_EXPECTED_ADDITIONAL_TERM_MILLICENTS'),
            'maximumClassificationMillicents' => env('SENDREPUTE_EXPECTED_MAXIMUM_CLASSIFICATION_MILLICENTS'),
        ],
        'maximum_charge_millicents' => env('SENDREPUTE_MAXIMUM_CHARGE_MILLICENTS'),
    ],

    'mail' => [
        'enabled' => (bool) env('SENDREPUTE_MAIL_ENABLED', false),
        'opt_in_header' => 'X-SendRepute-Classify',
        'mode' => 'advisory', // advisory or blocking
        'spam_probability_threshold' => 0.8,
        'failure_policy' => 'allow', // allow or block
        'model' => null,
    ],
];