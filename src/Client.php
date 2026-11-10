<?php

declare(strict_types=1);

namespace CraftCms\CommercePaystack;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * A small client for the Paystack API.
 *
 * Every response is returned decoded, including errors, which have a `status` of `false` and a `message`.
 *
 * @see https://paystack.com/docs/api/
 */
class Client
{
    public const string BASE_URL = 'https://api.paystack.co';

    public function __construct(
        private readonly string $secretKey,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     * @see https://paystack.com/docs/api/transaction/#initialize
     */
    public function initializeTransaction(array $data): array
    {
        return $this->request()->post('transaction/initialize', $data)->json() ?? [];
    }

    /**
     * @return array<string, mixed>
     * @see https://paystack.com/docs/api/transaction/#verify
     */
    public function verifyTransaction(string $reference): array
    {
        return $this->request()->get('transaction/verify/' . rawurlencode($reference))->json() ?? [];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     * @see https://paystack.com/docs/api/refund/#create
     */
    public function createRefund(array $data): array
    {
        return $this->request()->post('refund', $data)->json() ?? [];
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl(self::BASE_URL)
            ->withToken($this->secretKey)
            ->acceptJson()
            ->asJson();
    }
}
