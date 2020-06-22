<?php

namespace craft\commerce\paystack\gateways;

use Craft;
use craft\commerce\base\RequestResponseInterface;
use craft\commerce\models\payments\BasePaymentForm;
use craft\commerce\models\Transaction;
use craft\commerce\omnipay\base\OffsiteGateway;
use craft\commerce\paystack\models\PaymentForm;
use craft\commerce\paystack\web\assets\paystack\PayStackBundle;
use craft\helpers\Json;
use craft\web\Response;
use craft\web\Response as WebResponse;
use craft\web\View;
use Omnipay\Common\AbstractGateway;
use Omnipay\Paystack\Gateway;

/**
 * PayStack represents the PayStack gateway
 *
 * @author    Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since     1.0
 */
class PayStack extends OffsiteGateway
{
    // Properties
    // =========================================================================

    /**
     * @var string
     */
    public $secretKey;

    /**
     * @var string
     */
    public $publicKey;

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        return Craft::t('commerce', 'PayStack');
    }

    /**
     * @inheritdoc
     */
    public function getSettingsHtml()
    {
        return Craft::$app->getView()->renderTemplate('commerce-paystack/gatewaySettings', ['gateway' => $this]);
    }

    /**
     * @inheritdoc
     */
    public function processWebHook(): WebResponse
    {
        $rawData = Craft::$app->getRequest()->getRawBody();
        $response = Craft::$app->getResponse();
        $response->format = Response::FORMAT_RAW;

        $data = Json::decodeIfJson($rawData);

        if ($data) {
            try {

            } catch (\Throwable $exception) {
                Craft::$app->getErrorHandler()->logException($exception);
            }
        } else {
            Craft::warning('Could not decode JSON payload.', 'stripe');
        }

        $response->data = 'ok';

        return $response;
    }

    /**
     * @inheritdoc
     */
    public function getPaymentFormHtml(array $params)
    {
        $defaults = [
            'gateway' => $this
        ];

        $params = array_merge($defaults, $params);

        $view = Craft::$app->getView();

        $previousMode = $view->getTemplateMode();
        $view->setTemplateMode(View::TEMPLATE_MODE_CP);

        $view->registerJsFile('https://js.paystack.co/v2/paystack.js');
        $view->registerAssetBundle(PayStackBundle::class);

        $html = Craft::$app->getView()->renderTemplate('commerce-paystack/paymentForm', $params);
        $view->setTemplateMode($previousMode);

        return $html;
    }

    /**
     * @inheritDoc
     */
    public function purchase(Transaction $transaction, BasePaymentForm $form): RequestResponseInterface
    {
        $form->email = $transaction->getOrder()->getEmail() ?? '';

        return parent::purchase($transaction, $form);
    }


    /**
     * @inheritDoc
     */
    public function populateRequest(array &$request, BasePaymentForm $paymentForm = null)
    {
        parent::populateRequest($request, $paymentForm);

        if ($paymentForm) {
            $request['email'] = $paymentForm->email;
        }
    }

    /**
     * @inheritDoc
     */
    public function getPaymentFormModel(): BasePaymentForm
    {
        return new PaymentForm();
    }

    /**
     * @inheritdoc
     */
    public function supportsPaymentSources(): bool
    {
        return false;
    }

    /**
     * @inheritDoc
     */
    public function supportsRefund(): bool
    {
        return false;
    }
    
    /**
     * @inheritDoc
     */
    public function supportsCompletePurchase(): bool
    {
        return true;
    }

    /**
     * @inheritdoc
     */
    public function supportsWebhooks(): bool
    {
        return true;
    }

    // Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    protected function createGateway(): AbstractGateway
    {
        /** @var Gateway $gateway */
        $gateway = static::createOmnipayGateway($this->getGatewayClassName());

        $gateway->setSecretKey(Craft::parseEnv($this->secretKey));
        $gateway->setPublicKey(Craft::parseEnv($this->publicKey));

        return $gateway;
    }

    /**
     * @inheritdoc
     */
    protected function getGatewayClassName()
    {
        return '\\' . Gateway::class;
    }
}
