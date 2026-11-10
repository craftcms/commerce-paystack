<?php

declare(strict_types=1);

namespace CraftCms\CommercePaystack\Events;

use CraftCms\Commerce\Payment\Data\Transaction;
use CraftCms\Commerce\Payment\Forms\BasePaymentForm;

/**
 * Dispatched before a transaction is initialized with Paystack. Listeners may modify `$request`, which is sent as the
 * body of the [Initialize Transaction](https://paystack.com/docs/api/transaction/#initialize) request.
 */
class PaymentRequestBuilding
{
    /**
     * @param array<string, mixed> $request
     */
    public function __construct(
        public readonly Transaction $transaction,
        public readonly ?BasePaymentForm $form,
        public array $request,
    ) {
    }
}
