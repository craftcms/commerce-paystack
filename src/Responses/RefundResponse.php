<?php

declare(strict_types=1);

namespace CraftCms\CommercePaystack\Responses;

use CraftCms\Commerce\Payment\Gateway\Contracts\RequestResponseInterface;

use function CraftCms\Cms\t;

/**
 * Wraps the response to a Create Refund request, or the error Paystack returned instead.
 *
 * Paystack queues refunds, so a new refund is usually `pending`. It counts as successful unless it `failed`.
 *
 * @see https://paystack.com/docs/api/refund/#create
 */
class RefundResponse implements RequestResponseInterface
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
        return $this->isRefund() && ($this->data['data']['status'] ?? null) !== 'failed';
    }

    public function isProcessing(): bool
    {
        return false;
    }

    public function isRedirect(): bool
    {
        return false;
    }

    public function getRedirectMethod(): string
    {
        return '';
    }

    public function getRedirectData(): array
    {
        return [];
    }

    public function getRedirectUrl(): string
    {
        return '';
    }

    public function getTransactionReference(): string
    {
        return $this->isRefund() ? (string)$this->data['data']['id'] : '';
    }

    public function getCode(): string
    {
        return (string)($this->data['data']['status'] ?? '');
    }

    public function getData(): mixed
    {
        return $this->data;
    }

    public function getMessage(): string
    {
        if ($this->isRefund() && !$this->isSuccessful()) {
            return t('The refund failed.', category: 'commerce-paystack');
        }

        return (string)($this->data['message'] ?? t('Unable to reach Paystack.', category: 'commerce-paystack'));
    }

    private function isRefund(): bool
    {
        return ($this->data['status'] ?? null) === true && isset($this->data['data']['id']);
    }
}
