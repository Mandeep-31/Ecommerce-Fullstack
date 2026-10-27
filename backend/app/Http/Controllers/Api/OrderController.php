<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\EsewaPaymentService;
use App\Services\KhaltiPaymentService;
use Illuminate\Http\JsonResponse;

class OrderController extends Controller
{
    public function show(string $uuid): JsonResponse
    {
        $order = Order::with('items')
            ->where('transaction_uuid', $uuid)
            ->firstOrFail();

        return response()->json([
            'transaction_uuid' => $order->transaction_uuid,
            'status' => $order->status,
            'total_amount' => $order->total_amount,
            'verification_source' => $order->verification_source,
            'payment_provider' => $order->payment_provider,
            'items' => $order->items->map(fn ($item): array => [
                'product_name' => $item->product_name,
                'product_image_url' => $item->product_image_url,
                'unit_price' => $item->unit_price,
                'quantity' => $item->quantity,
                'line_total' => $item->line_total,
            ]),
        ]);
    }

    public function checkStatus(string $uuid, EsewaPaymentService $esewa, KhaltiPaymentService $khalti): JsonResponse
    {
        $order = Order::where('transaction_uuid', $uuid)->firstOrFail();
        $check = ['gateway_http_status' => null, 'esewa_status' => $order->status, 'payment_status' => $order->status, 'ref_id' => $order->esewa_ref];

        if (in_array($order->status, ['PENDING', 'AMBIGUOUS'], true)) {
            if ($order->payment_provider === 'khalti') {
                $check = $khalti->checkStatus($order);
                $khalti->syncOrderStatus($order, $check);
            } else {
                $check = $esewa->checkStatus($order);
                $esewa->syncOrderStatus($order, $check);
            }
            $order->refresh();
        }

        return response()->json([
            'transaction_uuid' => $order->transaction_uuid,
            'order_status' => $order->status,
            'esewa_status' => $check['esewa_status'] ?? $check['payment_status'],
            'gateway_http_status' => $check['gateway_http_status'],
            'total_amount' => $order->total_amount,
            'verification_source' => $order->verification_source,
            'payment_provider' => $order->payment_provider,
            'provider_status' => $check['provider_status'] ?? $check['esewa_status'] ?? $check['payment_status'] ?? $order->status,
        ]);
    }
}
