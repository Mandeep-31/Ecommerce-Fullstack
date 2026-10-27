<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Product;
use App\Services\EsewaPaymentService;
use App\Services\KhaltiPaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class CheckoutController extends Controller
{
    public function __invoke(Request $request, EsewaPaymentService $esewa, KhaltiPaymentService $khalti): JsonResponse
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:20'],
            'items.*.product_id' => ['required', 'integer', 'distinct', 'exists:products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:10'],
            'payment_provider' => ['required', 'in:esewa,khalti'],
            'customer.name' => ['required', 'string', 'max:120'],
            'customer.email' => ['required', 'email', 'max:255'],
            'customer.phone' => ['required', 'string', 'min:7', 'max:30'],
            'customer.address' => ['required', 'string', 'max:1000'],
        ]);

        if ($data['payment_provider'] === 'khalti' && ! filled(config('services.khalti.secret_key'))) {
            return response()->json([
                'message' => 'Khalti is not configured yet. Add your merchant Secret Key to backend/.env as KHALTI_SECRET_KEY, then run php artisan config:clear.',
            ], 503);
        }

        $order = DB::transaction(function () use ($data): Order {
            $products = Product::query()
                ->whereIn('id', collect($data['items'])->pluck('product_id'))
                ->get()
                ->keyBy('id');
            $totalInCents = collect($data['items'])->sum(fn (array $item): int => (int) round((float) $products[$item['product_id']]->price * 100) * $item['quantity']
            );
            $firstItem = $data['items'][0];
            $order = Order::create([
                'product_id' => $firstItem['product_id'],
                'quantity' => collect($data['items'])->sum('quantity'),
                'total_amount' => number_format($totalInCents / 100, 2, '.', ''),
                'transaction_uuid' => Str::uuid()->toString(),
                'payment_provider' => $data['payment_provider'],
                'customer_name' => $data['customer']['name'],
                'customer_email' => $data['customer']['email'],
                'customer_phone' => $data['customer']['phone'],
                'delivery_address' => $data['customer']['address'],
            ]);

            foreach ($data['items'] as $item) {
                $product = $products[$item['product_id']];
                $order->items()->create([
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'product_image_url' => $product->image_url,
                    'unit_price' => $product->price,
                    'quantity' => $item['quantity'],
                    'line_total' => number_format((int) round((float) $product->price * 100) * $item['quantity'] / 100, 2, '.', ''),
                ]);
            }

            return $order;
        });

        if ($order->payment_provider === 'khalti') {
            try {
                return response()->json($khalti->initiate($order));
            } catch (RuntimeException $exception) {
                report($exception);
                $order->update(['status' => 'CANCELED']);

                return response()->json([
                    'message' => 'Khalti could not start the payment. Check its sandbox configuration and try again.',
                ], 502);
            }
        }

        return response()->json($esewa->checkout($order));
    }
}
