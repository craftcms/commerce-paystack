<?php

declare(strict_types=1);

namespace CraftCms\CommercePaystack\Responses;

use CraftCms\Commerce\Payment\Gateway\Contracts\RequestResponseInterface;

use function CraftCms\Cms\t;

/**
 * Wraps the response to an Initialize Transaction request, which sends the customer to Paystack’s checkout.
 *
 * @see https://paystack.com/docs/api/transaction/#initialize
 */
class InitializeResponse implements RequestResponseInterface
{
    /**
     * @param array<string, mixed> $data The decoded Paystack response
     */
    public function __construct(
        protected readonly array $data,
    ) {
    }

    public function isSuccessful(): bool
    {
        return false;
    }

    public function isProcessing(): bool
    {
        return false;
    }

    public function isRedirect(): bool
    {
        return ($this->data['status'] ?? null) === true && $this->getRedirectUrl() !== '';
    }

    public function getRedirectMethod(): string
    {
        return 'GET';
    }

    public function getRedirectData(): array
    {
        return [];
    }

    public function getRedirectUrl(): string
    {
        return (string)($this->data['data']['authorization_url'] ?? '');
    }

    public function getTransactionReference(): string
    {
        return (string)($this->data['data']['reference'] ?? '');
    }

    public function getCode(): string
    {
        return (string)($this->data['data']['access_code'] ?? '');
    }

    public function getData(): mixed
    {
        return $this->data;
    }

    public function getMessage(): string
    {
        return (string)($this->data['message'] ?? t('Unable to reach Paystack.', category: 'commerce-paystack'));
    }
}
