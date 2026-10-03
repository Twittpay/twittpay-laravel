<?php

/**
 * TwittPay - Laravel config.
 *
 * Put the two real values in your .env file, not here:
 *
 *   TWITTPAY_API_KEY=your_api_key
 *   TWITTPAY_BASE_URL=https://checkout.twittpay.com
 *
 * Then run `php artisan config:clear` (and config:cache again if you cache it).
 */
return [

    /*
     * Your brand's Brand Key. Dashboard -> Brands.
     */
    'api_key' => env('TWITTPAY_API_KEY', ''),

    /*
     * Your gateway's endpoint URL, e.g. https://checkout.twittpay.com
     *
     * Only the scheme and host are used, so a pasted path is trimmed off and
     * .../api/payment/create still works. There is no default on purpose - put
     * your own gateway address in .env.
     */

    /*
     * USD -> BDT multiplier. The gateway charges BDT only. Leave it alone if your
     * store already prices in BDT.
     */
    'currency_rate' => (float) env('TWITTPAY_CURRENCY_RATE', 120),

];
