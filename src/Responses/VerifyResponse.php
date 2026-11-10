<?php

declare(strict_types=1);

namespace CraftCms\CommercePaystack\Responses;

use CraftCms\Commerce\Payment\Gateway\Contracts\RequestResponseInterface;

use function CraftCms\Cms\t;

/**
 * Wraps the response to a Verify Transaction request, or the error Paystack returned instead.
 *
 * @see https://paystack.com/docs/api/transaction/#verify
 * @see https://paystack.com/docs/payments/verify-payments/#transaction-statuses
 */
class VerifyResponse implements RequestResponseInterface
{
    /**
     * Statuses of transactions that Paystack hasn’t finished processing. Paystack sends a `charge.success` webhook
     * event if they succeed.
     */
    public const array PROCESSING_STATUSES = ['ongoing', 'pending', 'processing', 'queued'];

    /**
     * @param array<string, mixed> $data The decoded Paystack response
     */
    public function __construct(
        protected readonly array $data,
    ) {
    }

    /**
     * Returns whether Paystack returned a transaction, rather than an error.
     */
    public function isTransaction(): bool
    {
        return ($this->data['status'] ?? null) === true && isset($this->data['data']['status']);
    }

    public function isSuccessful(): bool
    {
        return $this->isTransaction() && $this->status() === 'success';
    }

    public function isProcessing(): bool
    {
        return $this->isTransaction() && in_array($this->status(), self::PROCESSING_STATUSES, true);
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
        return (string)($this->data['data']['reference'] ?? '');
    }

    public function getCode(): string
    {
        return $this->isTransaction() ? $this->status() : '';
    }

    public function getData(): mixed
    {
        return $this->data;
    }

    public function getMessage(): string
    {
        if ($this->isTransaction()) {
            return (string)(($this->data['data']['message'] ?? null) ?: ($this->data['data']['gateway_response'] ?? ''));
        }

        return (string)($this->data['message'] ?? t('Unable to reach Paystack.', category: 'commerce-paystack'));
    }

    protected function status(): string
    {
        return (string)$this->data['data']['status'];
    }
}
