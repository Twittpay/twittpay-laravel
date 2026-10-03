<?php

namespace App\Http\Controllers;

use App\Services\TwittPay;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * TwittPay - a working example controller.
 *
 * Three actions, and the third one is the one people forget:
 *
 *   GET  /twittpay/pay/{amount}   start a payment
 *   GET  /twittpay/back           the customer returns here
 *   POST /twittpay/webhook        the gateway calls here, no browser involved
 *
 * Copy the parts you need into your own checkout controller and delete this file -
 * nothing else in the package refers to it.
 */
class TwittPayController extends Controller
{
    /** Start a payment. In a real app the amount comes from your own order row. */
    public function pay(Request $request, $amount = 100)
    {
        $pay = new TwittPay();

        // Your own reference. Store it before you redirect, so the way back can
        // find the order again.
        $orderId = 'ORD-' . $request->user()?->id . '-' . uniqid();

        $result = $pay->createPayment([
            'cus_name'    => $request->user()?->name  ?? 'Demo Customer',
            'cus_email'   => $request->user()?->email ?? 'demo@example.com',
            'amount'      => $amount,
            'success_url' => route('twittpay.back'),
            'cancel_url'  => route('twittpay.cancelled'),
            'webhook_url' => route('twittpay.webhook'),
            'metadata'    => [
                'order_id' => $orderId,
                'source'   => 'laravel',
            ],
        ]);

        if (! empty($result['status']) && ! empty($result['payment_url'])) {
            return redirect()->away($result['payment_url']);
        }

        return back()->with('error', 'Could not start the payment: ' . ($result['message'] ?? 'unknown error'));
    }

    /** The customer is back. The URL is a nudge; the verify call is the truth. */
    public function back(Request $request)
    {
        $transactionId = trim((string) $request->query('transactionId'));

        if ($transactionId === '') {
            return redirect('/')->with('error', 'No transaction id on the return URL.');
        }

        $pay      = new TwittPay();
        $verified = $pay->verifyPayment($transactionId);
        $status   = TwittPay::readStatus($verified);
        $meta     = TwittPay::decodeMetadata($verified);
        $orderId  = $meta['order_id'] ?? '';

        // Look the real total up from your own table by $orderId and pass it in, so
        // a 1 taka payment cannot settle a 1000 taka order.
        $expected = 0;

        if ($pay->isPaid($verified, $expected)) {
            // $this->markPaid($orderId, $transactionId);   <- must be idempotent
            return redirect('/')->with('success', 'Payment received. Reference: ' . $orderId);
        }

        if ($status === 'PENDING') {
            return redirect('/')->with('info', 'Your payment is being checked. You will not need to pay again.');
        }

        return redirect('/')->with('error', 'Payment not completed. Status: ' . ($status !== '' ? $status : 'unknown'));
    }

    public function cancelled()
    {
        return redirect('/')->with('info', 'Payment cancelled. Nothing has been charged.');
    }

    /**
     * The webhook. No browser here, so nothing redirects and nothing is flashed to
     * a session. It can arrive twice for one payment - pending, then completed -
     * so everything it does has to be safe to run twice.
     */
    public function webhook(Request $request)
    {
        $hook          = TwittPay::readWebhook();
        $transactionId = $hook['transactionId'];

        if ($transactionId === '') {
            return response('No transaction id.', 400);
        }

        $pay      = new TwittPay();
        $verified = $pay->verifyPayment($transactionId);
        $status   = TwittPay::readStatus($verified);
        $meta     = TwittPay::decodeMetadata($verified);
        $orderId  = $meta['order_id'] ?? '';

        if ($status === 'COMPLETED') {
            // $this->markPaid($orderId, $transactionId);   <- idempotent
            Log::info('TwittPay: paid', ['order' => $orderId, 'trx' => $transactionId]);
        } elseif ($status === 'PENDING') {
            // Leave the order alone. This URL will be called again with the answer.
            Log::info('TwittPay: pending', ['order' => $orderId, 'trx' => $transactionId]);
        } else {
            Log::info('TwittPay: not paid', ['order' => $orderId, 'status' => $status]);
        }

        // Always 200 once handled, so the gateway stops retrying.
        return response('OK', 200);
    }
}
