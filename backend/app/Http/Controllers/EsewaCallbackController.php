<?php
namespace App\Http\Controllers;
use App\Models\Order;
use App\Services\EsewaPaymentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
class EsewaCallbackController extends Controller
{
    public function success(Request $request, EsewaPaymentService $payment): RedirectResponse
    {
        $encoded = $request->query('data');
        $response = is_string($encoded) ? json_decode(base64_decode($encoded, true) ?: '', true) : null;
        abort_unless(is_array($response) && $payment->verifyCallback($response), 400, 'Invalid eSewa response.');
        $order = Order::where('transaction_uuid', $response['transaction_uuid'])->firstOrFail();
        abort_unless($order->payment_provider === 'esewa', 400, 'This order uses a different payment provider.');
        abort_unless($payment->matchesOrder($response, $order), 400, 'Payment details do not match this order.');
        if (in_array($order->status, ['PENDING', 'AMBIGUOUS'], true)) {
            $check = $payment->checkStatus($order);
            if (in_array($check['esewa_status'], ['UNAVAILABLE', 'INVALID_RESPONSE'], true)) {
                $payment->recordVerifiedCallback($order, $response);
            } else {
                $payment->syncOrderStatus($order, $check);
            }
        }
        return redirect()->away(
            rtrim(config('services.esewa.frontend_url'), '/').'/?order='.$order->transaction_uuid
        );
}
public function failure(Request $request): RedirectResponse
    {
        $order = $request->query('order');
        $query = ['payment' => 'failed'];
        if (is_string($order) && Order::where('transaction_uuid', $order)->exists()) {
            $query['order'] = $order;
        }
        return redirect()->away(rtrim(config('services.esewa.frontend_url'), '/').'/'.'?'.http_build_query($query));
    }
}