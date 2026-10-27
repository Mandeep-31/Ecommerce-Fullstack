<?php

namespace App\Services;

use App\Events\PaymentUpdated;
use App\Models\Order;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class KhaltiPaymentService
{
    public function initiate(Order $order): array
    {
        $secretKey = config('services.khalti.secret_key');
        if (! is_string($secretKey) || $secretKey === '') {
            throw new RuntimeException('Khalti is not configured. Add KHALTI_SECRET_KEY to backend/.env.');
        }

        $order->loadMissing('items');
        $amountInPaisa = (int) round((float) $order->total_amount * 100);
        if ($amountInPaisa < 1000) {
            throw new RuntimeException('Khalti requires a minimum payment of Rs. 10.');
        }

        $productDetails = $order->items->map(fn ($item): array => [
            'identity' => (string) ($item->product_id ?? $item->id),
            'name' => $item->product_name,
            'total_price' => (int) round((float) $item->line_total * 100),
            'quantity' => $item->quantity,
            'unit_price' => (int) round((float) $item->unit_price * 100),
        ])->all();

        try {
            $response = Http::withHeaders(['Authorization' => 'Key '.$secretKey])
                ->acceptJson()
                ->connectTimeout(4)
                ->timeout(15)
                ->post($this->url('epayment/initiate/'), [
                    'return_url' => route('khalti.return'),
                    'website_url' => config('services.khalti.frontend_url'),
                    'amount' => $amountInPaisa,
                    'purchase_order_id' => $order->transaction_uuid,
                    'purchase_order_name' => 'Little Things order',
                    'customer_info' => [
                        'name' => $order->customer_name,
                        'email' => $order->customer_email,
                        'phone' => $order->customer_phone,
                    ],
                    'product_details' => $productDetails,
                ]);
        } catch (ConnectionException $exception) {
            throw new RuntimeException('Could not connect to Khalti. Please try again.', previous: $exception);
        }

        $data = $response->json();
        if (! $response->successful()
            || ! is_array($data)
            || ! is_string($data['pidx'] ?? null)
            || ! $this->isPaymentUrl($data['payment_url'] ?? null)) {
            throw new RuntimeException('Khalti could not start this payment. Check the sandbox secret key and try again.');
        }

        $order->update(['khalti_pidx' => $data['pidx']]);

        return [
            'payment_provider' => 'khalti',
            'redirect_url' => $data['payment_url'],
            'pidx' => $data['pidx'],
        ];
    }

    public function checkStatus(Order $order): array
    {
        $secretKey = config('services.khalti.secret_key');
        if (! is_string($secretKey) || $secretKey === '' || ! is_string($order->khalti_pidx)) {
            return $this->result(null, 'UNAVAILABLE', 'Configuration or Khalti payment reference is missing.');
        }

        try {
            $response = Http::withHeaders(['Authorization' => 'Key '.$secretKey])
                ->acceptJson()
                ->connectTimeout(4)
                ->timeout(10)
                ->post($this->url('epayment/lookup/'), ['pidx' => $order->khalti_pidx]);
        } catch (ConnectionException) {
            return $this->result(null, 'UNAVAILABLE');
        }

        $data = $response->json();
        if (! is_array($data)
            || ($data['pidx'] ?? null) !== $order->khalti_pidx
            || (int) ($data['total_amount'] ?? -1) !== (int) round((float) $order->total_amount * 100)) {
            return $this->result($response->status(), 'INVALID_RESPONSE');
        }

        $providerStatus = $data['status'] ?? null;
        $reference = $data['transaction_id'] ?? null;
        if (! is_string($providerStatus)
            || ($this->normalizeStatus($providerStatus) === 'COMPLETE' && ! is_string($reference))) {
            return $this->result($response->status(), 'INVALID_RESPONSE');
        }

        $paymentStatus = $this->normalizeStatus($providerStatus);
        if (! in_array($response->status(), [200, 400], true)
            || ($response->status() === 400 && $paymentStatus !== 'CANCELED')) {
            return $this->result($response->status(), 'UNAVAILABLE', $providerStatus);
        }

        return $this->result($response->status(), $paymentStatus, $providerStatus, is_string($reference) ? $reference : null);
    }

    public function syncOrderStatus(Order $order, array $check): bool
    {
        $status = $check['payment_status'] ?? null;
        $knownStatuses = ['PENDING', 'COMPLETE', 'AMBIGUOUS', 'CANCELED', 'FULL_REFUND', 'PARTIAL_REFUND'];
        if (! is_string($status) || ! in_array($status, $knownStatuses, true)) {
            return false;
        }

        $reference = $status === 'COMPLETE' && is_string($check['ref_id'] ?? null) ? $check['ref_id'] : null;
        $updated = Order::query()
            ->whereKey($order->getKey())
            ->where('payment_provider', 'khalti')
            ->whereIn('status', ['PENDING', 'AMBIGUOUS'])
            ->update([
                'status' => $status,
                'esewa_ref' => $reference,
                'verification_source' => 'khalti_lookup',
            ]);
        if ($updated === 0) {
            return false;
        }

        try {
            PaymentUpdated::dispatch($order->transaction_uuid, $status);
        } catch (Throwable $exception) {
            report($exception);
        }

        return true;
    }

    private function normalizeStatus(string $status): string
    {
        return match (strtolower($status)) {
            'completed' => 'COMPLETE',
            'pending', 'initiated' => 'PENDING',
            'user canceled', 'cancelled', 'canceled', 'expired', 'failed' => 'CANCELED',
            'refunded' => 'FULL_REFUND',
            'partially refunded' => 'PARTIAL_REFUND',
            default => 'AMBIGUOUS',
        };
    }

    private function isPaymentUrl(mixed $url): bool
    {
        if (! is_string($url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        return in_array(parse_url($url, PHP_URL_HOST), config('services.khalti.payment_hosts', []), true)
            && parse_url($url, PHP_URL_SCHEME) === 'https';
    }

    private function result(?int $httpStatus, string $paymentStatus, ?string $providerStatus = null, ?string $reference = null): array
    {
        return [
            'gateway_http_status' => $httpStatus,
            'payment_status' => $paymentStatus,
            'provider_status' => $providerStatus,
            'ref_id' => $reference,
        ];
    }

    private function url(string $path): string
    {
        return rtrim(config('services.khalti.api_base_url'), '/').'/'.ltrim($path, '/');
    }
}
