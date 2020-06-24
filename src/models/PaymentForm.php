<?php

namespace craft\commerce\paystack\models;

use craft\commerce\models\payments\CreditCardPaymentForm;
use craft\commerce\models\payments\OffsitePaymentForm;
use craft\commerce\models\PaymentSource;
use yii\base\NotSupportedException;

/**
 * Paystack payment form model.
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since  1.0
 */
class PaymentForm extends OffsitePaymentForm
{
    /**
     * @var string
     */
    public $email;
}
