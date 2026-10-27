<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\KhaltiPaymentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class KhaltiCallbackController extends Controller
{
    public function __invoke(Request $request, KhaltiPaymentService $payment): RedirectResponse
    {
        $uuid = $request->query('purchase_order_id');
        $pidx = $request->query('pidx');
        abort_unless(is_string($uuid) && is_string($pidx), 400, 'Missing Khalti payment reference.');

        $order = Order::query()
            ->where('transaction_uuid', $uuid)
            ->where('payment_provider', 'khalti')
            ->where('khalti_pidx', $pidx)
            ->firstOrFail();

        if (in_array($order->status, ['PENDING', 'AMBIGUOUS'], true)) {
            $check = $payment->checkStatus($order);
            $payment->syncOrderStatus($order, $check);
        }

        return redirect()->away(
            rtrim(config('services.khalti.frontend_url'), '/').'/?'.http_build_query([
                'order' => $order->transaction_uuid,
                'payment' => 'khalti',
            ])
        );
    }
}
