<?php

namespace App\Services;

use App\Events\PaymentUpdated;
use App\Models\Order;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

class EsewaPaymentService
{
    public function checkout(Order $order): array
    {
        $signedFields = [
            'total_amount' => number_format((float) $order->total_amount, 2, '.', ''),
            'transaction_uuid' => $order->transaction_uuid,
            'product_code' => config('services.esewa.product_code'),
        ];
        $signatureMessage = collect($signedFields)
            ->map(fn (string $value, string $key): string => "$key=$value")
            ->implode(',');

        return [
            'gateway_url' => config('services.esewa.form_url'),
            'fields' => $signedFields + [
                'amount' => $signedFields['total_amount'],
                'tax_amount' => '0',
                'product_service_charge' => '0',
                'product_delivery_charge' => '0',
                'success_url' => route('esewa.success'),
                'failure_url' => route('esewa.failure', ['order' => $order->transaction_uuid]),
                'signed_field_names' => 'total_amount,transaction_uuid,product_code',
                'signature' => $this->sign($signatureMessage),
            ],
        ];
    }

    public function verifyCallback(array $response): bool
    {
        $requiredFields = ['transaction_code', 'status', 'total_amount', 'transaction_uuid', 'product_code', 'signed_field_names'];
        if (! is_string($response['signed_field_names'] ?? null) || ! is_string($response['signature'] ?? null)) {
            return false;
        }

        $signedFields = explode(',', $response['signed_field_names']);
        if (array_diff($requiredFields, $signedFields) !== []) {
            return false;
        }

        foreach ($signedFields as $field) {
            if (! isset($response[$field]) || ! is_scalar($response[$field])) {
                return false;
            }
        }

        $message = collect($signedFields)
            ->map(fn (string $field): ?string => isset($response[$field]) ? "$field={$response[$field]}" : null)
            ->filter()
            ->implode(',');

        return isset($response['signature'])
            && hash_equals($this->sign($message), $response['signature']);
    }

    public function matchesOrder(array $response, Order $order): bool
    {
        return ($response['transaction_uuid'] ?? null) === $order->transaction_uuid
            && $this->formatAmount((string) ($response['total_amount'] ?? '0')) === (string) $order->total_amount
            && ($response['product_code'] ?? null) === config('services.esewa.product_code')
            && ($response['status'] ?? null) === 'COMPLETE'
            && is_string($response['transaction_code'] ?? null);
    }

    public function checkStatus(Order $order): array
    {
        try {
            $response = Http::connectTimeout(3)->timeout(8)->get(config('services.esewa.status_url'), [
                'product_code' => config('services.esewa.product_code'),
                'total_amount' => $order->total_amount,
                'transaction_uuid' => $order->transaction_uuid,
            ]);
        } catch (ConnectionException) {
            return ['gateway_http_status' => null, 'esewa_status' => 'UNAVAILABLE', 'ref_id' => null];
        }

        $data = $response->json();
        if (! $response->successful()) {
            return ['gateway_http_status' => $response->status(), 'esewa_status' => 'UNAVAILABLE', 'ref_id' => null];
        }

        if (! is_array($data)) {
            return ['gateway_http_status' => $response->status(), 'esewa_status' => 'INVALID_RESPONSE', 'ref_id' => null];
        }

        if (isset($data['error_message']) || (string) ($data['code'] ?? '') === '0') {
            return ['gateway_http_status' => $response->status(), 'esewa_status' => 'UNAVAILABLE', 'ref_id' => null];
        }

        if (($data['transaction_uuid'] ?? null) !== $order->transaction_uuid
            || ($data['product_code'] ?? null) !== config('services.esewa.product_code')
            || $this->formatAmount((string) ($data['total_amount'] ?? '0')) !== (string) $order->total_amount) {
            return ['gateway_http_status' => $response->status(), 'esewa_status' => 'INVALID_RESPONSE', 'ref_id' => null];
        }

        $knownStatuses = ['PENDING', 'COMPLETE', 'AMBIGUOUS', 'NOT_FOUND', 'CANCELED', 'FULL_REFUND', 'PARTIAL_REFUND'];
        $status = $data['status'] ?? null;
        if (! is_string($status) || ! in_array($status, $knownStatuses, true)) {
            return ['gateway_http_status' => $response->status(), 'esewa_status' => 'UNAVAILABLE', 'ref_id' => null];
        }

        return [
            'gateway_http_status' => $response->status(),
            'esewa_status' => $status,
            'ref_id' => $data['ref_id'] ?? null,
        ];
    }

    public function syncOrderStatus(Order $order, array $check): bool
    {
        $status = $check['esewa_status'] ?? null;
        $knownStatuses = ['PENDING', 'COMPLETE', 'AMBIGUOUS', 'NOT_FOUND', 'CANCELED', 'FULL_REFUND', 'PARTIAL_REFUND'];
        if (! is_string($status) || ! in_array($status, $knownStatuses, true)) {
            return false;
        }

        $reference = $status === 'COMPLETE' ? ($check['ref_id'] ?? null) : null;
        if ($order->status === $status && $order->esewa_ref === $reference && $order->verification_source === 'status_api') {
            return false;
        }

        $updated = Order::query()
            ->whereKey($order->getKey())
            ->where('payment_provider', 'esewa')
            ->where('status', $order->status)
            ->update([
                'status' => $status,
                'esewa_ref' => $reference,
                'verification_source' => 'status_api',
            ]);
        if ($updated === 0) {
            return false;
        }

        $this->broadcastStatus($order, $status);

        return true;
    }

    public function recordVerifiedCallback(Order $order, array $callback): bool
    {
        if (! $this->verifyCallback($callback)
            || ! $this->matchesOrder($callback, $order)
            || ! in_array($order->status, ['PENDING', 'AMBIGUOUS'], true)) {
            return false;
        }

        $updated = Order::query()
            ->whereKey($order->getKey())
            ->where('payment_provider', 'esewa')
            ->whereIn('status', ['PENDING', 'AMBIGUOUS'])
            ->update([
                'status' => 'COMPLETE',
                'esewa_ref' => $callback['transaction_code'],
                'verification_source' => 'signed_callback',
            ]);
        if ($updated === 0) {
            return false;
        }

        $this->broadcastStatus($order, 'COMPLETE');

        return true;
    }

    private function broadcastStatus(Order $order, string $status): void
    {
        try {
            PaymentUpdated::dispatch($order->transaction_uuid, $status);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function sign(string $message): string
    {
        return base64_encode(hash_hmac('sha256', $message, config('services.esewa.secret_key'), true));
    }

    private function formatAmount(string $amount): string
    {
        return number_format((float) str_replace(',', '', $amount), 2, '.', '');
    }
}
