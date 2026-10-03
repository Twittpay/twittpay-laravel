# TwittPay for Laravel

Laravel service, controller and routes

Part of the [TwittPay](https://twittpay.com) addon family.

## Quick start

1. Download the latest zip from the **Releases** page of this repository.
2. Install it on your Laravel following the guide below.
3. Open the TwittPay settings and enter your **Brand Key**. You can get it from [your dashboard](https://twittpay.com/user/brands).
4. Make a small test payment to confirm everything works.

Payments are always re-verified on your server before an order or invoice is marked paid.

## Detailed installation guide

```text
===========================================================================
 TWITTPAY - Laravel SDK
===========================================================================

 WHERE IT GOES
   Extract this zip at your Laravel project root - the folder that has artisan,
   app/ and routes/ in it. Four files land in place:

     app/Services/TwittPay.php                       the client
     app/Http/Controllers/TwittPayController.php     a working example
     config/twittpay.php                             config
     routes/twittpay.php                             the example routes

   Nothing you already have is overwritten. routes/web.php is left alone on
   purpose - you add one line to it yourself, see step 3.

 SETUP
   1. Put your credentials in .env:

        TWITTPAY_API_KEY=your_api_key
        TWITTPAY_BASE_URL=https://checkout.twittpay.com
        TWITTPAY_CURRENCY_RATE=120

      The Brand Key is in your gateway dashboard under Brands. The rate only
      matters if your app prices in USD - see CURRENCY below.

   2. php artisan config:clear
      (and php artisan config:cache again if you cache your config)

   3. Add the routes. One line at the bottom of routes/web.php:

        require __DIR__ . '/twittpay.php';

   4. Visit /twittpay/pay/100 to try it end to end.

 USING IT IN YOUR OWN CODE
   use App\Services\TwittPay;

   $pay = new TwittPay();

   $result = $pay->createPayment([
       'cus_name'    => $user->name,
       'cus_email'   => $user->email,
       'amount'      => $order->total,
       'success_url' => route('checkout.back'),
       'cancel_url'  => route('checkout.cancelled'),
       'webhook_url' => route('checkout.webhook'),
       'metadata'    => ['order_id' => $order->id],
   ]);

   if (! empty($result['status']) && ! empty($result['payment_url'])) {
       return redirect()->away($result['payment_url']);
   }

   // $result['message'] tells you why not.

   On the way back, and again inside your webhook:

   $verified = $pay->verifyPayment($request->query('transactionId'));

   if ($pay->isPaid($verified, $order->total)) {
       $meta = TwittPay::decodeMetadata($verified);   // your order_id
       $order->markPaid();
   }

 CSRF - READ THIS ONE
   The webhook is a POST from the gateway's server. There is no browser, no
   session and no CSRF token, so Laravel's web middleware would answer 419 and
   the payment would silently never land.

   routes/twittpay.php already handles this with ->withoutMiddleware(...) on
   the webhook route. If you write your own webhook route instead, exclude it
   yourself:

     Laravel 11 and 12 - bootstrap/app.php:

       ->withMiddleware(function (Middleware $middleware) {
           $middleware->validateCsrfTokens(except: ['checkout/webhook']);
       })

     Laravel 10 and below - app/Http/Middleware/VerifyCsrfToken.php:

       protected $except = ['checkout/webhook'];

   Putting the webhook route in routes/api.php works too - that group has no
   CSRF at all - but remember it gets the /api prefix.

 CURRENCY
   The gateway charges BDT. If your app prices in USD, convert before sending
   and keep the real figures in metadata so your webhook can still reconcile:

     $rate = config('twittpay.currency_rate');

     'amount'   => $order->total * $rate,
     'metadata' => [
         'order_id'       => $order->id,
         'order_amount'   => $order->total,
         'order_currency' => 'USD',
     ],

 WHAT TO WATCH
   * Never believe the return URL. ?status=completed is typed by whoever asks
     for the page. verifyPayment() is the only thing that decides.
   * Always send webhook_url. The return URL only runs while the customer is
     still sitting there; a payment a merchant approves an hour later has no
     browser left to redirect.
   * PENDING is not a failure. The money has been sent and the merchant has not
     approved it yet. Leave the order unpaid and wait for the webhook - do not
     ask the customer to pay again.
   * The webhook is not signed, and it can arrive twice for one payment
     (pending, then completed). Treat it as "go and verify this id", and make
     whatever you do safe to run twice.
   * metadata needs named keys: ['order_id' => 1043]. A plain list is rejected
     by the gateway. The service casts your array to an object for you.
   * Pass the order total to isPaid() so a 1 taka payment cannot settle a
     1000 taka order.

 TESTING
   The service uses Laravel's own HTTP client, so Http::fake() works:

     Http::fake([
         '*/api/payment/create' => Http::response(['status' => 1, 'payment_url' => 'https://example.test/x']),
         '*/api/payment/verify' => Http::response(['status' => 'COMPLETED', 'amount' => '100.00']),
     ]);

 CHECKED
   All four files were checked with a lexer that balances braces only inside
   real PHP code. PHP itself was NOT run - there is no PHP binary on the machine
   this was built on, so php -l was never executed.
```
