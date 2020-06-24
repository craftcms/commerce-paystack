<?php
namespace craft\commerce\paystack\web\assets\paystack;

use craft\web\AssetBundle;

/**
 * Asset bundle for the Paystack payment
 */
class PaystackBundle extends AssetBundle
{
    /**
     * @inheritdoc
     */
    public function init()
    {
        $this->sourcePath = __DIR__ . '/dist';

        $this->js[] = 'js/paymentForm.js';

        parent::init();
    }
}
