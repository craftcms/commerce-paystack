<?php

declare(strict_types=1);

namespace CraftCms\CommercePaystack;

use CraftCms\Cms\Cp\SelectOptions;
use CraftCms\Cms\Support\Env;
use CraftCms\Cms\Support\Facades\HtmlStack;
use CraftCms\Cms\Support\Url;
use CraftCms\Cms\Ui\Controls\Text;
use CraftCms\Cms\Ui\Nodes\Field;
use CraftCms\Cms\Ui\Nodes\TemplateContent;
use CraftCms\Cms\Ui\Ui;
use CraftCms\Cms\Ui\UiContext;
use CraftCms\Commerce\Exceptions\NotImplementedException;
use CraftCms\Commerce\Payment\Currencies;
use CraftCms\Commerce\Payment\Data\PaymentSource;
use CraftCms\Commerce\Payment\Data\Transaction;
use CraftCms\Commerce\Payment\Forms\BasePaymentForm;
use CraftCms\Commerce\Payment\Forms\OffsitePaymentForm;
use CraftCms\Commerce\Payment\Gateway\Contracts\RequestResponseInterface;
use CraftCms\Commerce\Payment\Gateway\Gateway as BaseGateway;
use CraftCms\Commerce\Payment\Models\Transaction as TransactionModel;
use CraftCms\Commerce\Payment\Transactions;
use CraftCms\CommercePaystack\Events\PaymentRequestBuilding;
use CraftCms\CommercePaystack\Responses\InitializeResponse;
use CraftCms\CommercePaystack\Responses\RefundResponse;
use CraftCms\CommercePaystack\Responses\VerifyResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Override;

use function CraftCms\Cms\t;

/**
 * Paystack payment gateway, using the Paystack API.
 *
 * Customers are redirected to Paystack’s hosted checkout. The transaction is verified with Paystack when they return,
 * and when Paystack sends a `charge.success` webhook event.
 */
class Gateway extends BaseGateway
{
    /**
     * Paystack’s JavaScript, which the payment form loads.
     */
    public const string JS_URL = 'https://js.paystack.co/v2/paystack.js';

    /**
     * @var string|null The Paystack secret key. Can be set to an environment variable.
     */
    public ?string $secretKey = null;

    /**
     * @var string|null The Paystack public key. Can be set to an environment variable. The gateway doesn’t use it
     * itself, but it’s available to templates that use Paystack’s JavaScript.
     */
    public ?string $publicKey = null;

    #[Override]
    public static function displayName(): string
    {
        return t('Paystack', category: 'commerce-paystack');
    }

    public function getSecretKey(bool $parse = true): ?string
    {
        return $parse ? Env::parse($this->secretKey) : $this->secretKey;
    }

    public function setSecretKey(?string $secretKey): void
    {
        $this->secretKey = $secretKey;
    }

    public function getPublicKey(bool $parse = true): ?string
    {
        return $parse ? Env::parse($this->publicKey) : $this->publicKey;
    }

    public function setPublicKey(?string $publicKey): void
    {
        $this->publicKey = $publicKey;
    }

    #[Override]
    public function getRules(): array
    {
        return array_merge(parent::getRules(), [
            'paymentType' => ['required', Rule::in(['purchase'])],
            'secretKey' => ['required'],
        ]);
    }

    #[Override]
    public function settingsUi(UiContext $context = new UiContext()): ?Ui
    {
        return Ui::make([
            Field::make(t('Secret Key', category: 'commerce-paystack'))
                ->required()
                ->control(Text::make('secretKey')
                    ->value($this->secretKey)
                    ->textExpanderTriggers(SelectOptions::getEnvTextExpanderTriggers())),
            Field::make(t('Public Key', category: 'commerce-paystack'))
                ->control(Text::make('publicKey')
                    ->value($this->publicKey)
                    ->textExpanderTriggers(SelectOptions::getEnvTextExpanderTriggers())),
        ]);
    }

    #[Override]
    public function getPaymentTypeOptions(): array
    {
        return [
            'purchase' => t('Purchase (Authorize and Capture Immediately)', category: 'commerce'),
        ];
    }

    #[Override]
    public function getPaymentFormModel(): BasePaymentForm
    {
        return new OffsitePaymentForm();
    }

    /**
     * Customers enter their payment details on Paystack’s checkout, so the form only tells them they’ll be redirected.
     * It also loads Paystack’s JavaScript, as earlier versions did, for templates that use it.
     */
    #[Override]
    public function paymentFormUi(BasePaymentForm $form, array $params = []): ?Ui
    {
        HtmlStack::jsFile(self::JS_URL);

        return Ui::make([
            TemplateContent::make('paystack-redirect', e(t('You will be redirected', category: 'commerce-paystack')))
                ->trusted(),
        ]);
    }

    #[Override]
    public function purchase(Transaction $transaction, BasePaymentForm $form): RequestResponseInterface
    {
        $event = new PaymentRequestBuilding($transaction, $form, $this->buildPaymentRequest($transaction));
        event($event);

        return new InitializeResponse($this->client()->initializeTransaction($event->request));
    }

    #[Override]
    public function completePurchase(Transaction $transaction): RequestResponseInterface
    {
        return new VerifyResponse($this->client()->verifyTransaction((string)$transaction->reference));
    }

    #[Override]
    public function refund(Transaction $transaction): RequestResponseInterface
    {
        $currency = (string)$transaction->paymentCurrency;

        // Refund transactions start with their parent’s reference, which is the Paystack transaction reference.
        $request = [
            'transaction' => (string)$transaction->reference,
            'amount' => $this->amount($transaction->paymentAmount, $currency),
            'currency' => $currency,
        ];

        if ($transaction->note) {
            $request['merchant_note'] = $transaction->note;
        }

        return new RefundResponse($this->client()->createRefund($request));
    }

    #[Override]
    public function processWebHook(): Response
    {
        $request = request();
        $secretKey = (string)$this->getSecretKey();
        $signature = (string)$request->header('x-paystack-signature');

        if ($secretKey === '' || !hash_equals(hash_hmac('sha512', $request->getContent(), $secretKey), $signature)) {
            Log::warning('Paystack webhook: invalid signature.');
            return response('Invalid signature.', 400);
        }

        // Paystack only sends events for successful charges. Acknowledge everything else.
        if ($request->json('event') !== 'charge.success') {
            return response('ok');
        }

        $transactionHash = $this->getTransactionHashFromWebhook();
        $transaction = $transactionHash ? app(Transactions::class)->getTransactionByHash($transactionHash) : null;

        if (!$transaction || $transaction->gatewayId !== (int)$this->id || $transaction->type !== TransactionModel::TYPE_PURCHASE) {
            Log::warning('Paystack webhook: purchase transaction with the reference “' . $transactionHash . '” not found.');
            return response('ok');
        }

        if (app(Transactions::class)->isTransactionSuccessful($transaction)) {
            return response('ok');
        }

        // The event payload is only used to find the transaction. Its status always comes from the API.
        $response = new VerifyResponse($this->client()->verifyTransaction((string)$transaction->reference));

        if (!$response->isTransaction()) {
            // Let Paystack retry the event later.
            Log::warning('Paystack webhook: unable to verify “' . $transaction->reference . '”: ' . $response->getMessage());
            return response('Unable to verify the transaction.', 503);
        }

        if (!$response->isSuccessful()) {
            Log::warning('Paystack webhook: “' . $transaction->reference . '” has the status “' . $response->getCode() . '”, not “success”.');
            return response('ok');
        }

        $child = app(Transactions::class)->createTransaction(null, $transaction);
        $child->type = $transaction->type;
        $child->status = TransactionModel::STATUS_SUCCESS;
        $child->response = $response->getData();
        $child->code = $response->getCode();
        $child->reference = $response->getTransactionReference();
        $child->message = $response->getMessage();
        app(Transactions::class)->saveTransaction($child);

        return response('ok');
    }

    /**
     * Returns the transaction reference from a webhook event. The plugin uses Commerce transaction hashes as Paystack
     * transaction references.
     */
    #[Override]
    public function getTransactionHashFromWebhook(): ?string
    {
        $reference = request()->json('data.reference');

        return is_string($reference) && $reference !== '' ? $reference : null;
    }

    /**
     * Builds the body of the Paystack “Initialize Transaction” request.
     *
     * @return array<string, mixed>
     */
    protected function buildPaymentRequest(Transaction $transaction): array
    {
        $order = $transaction->getOrder() ?? throw new InvalidArgumentException('The transaction has no order.');
        $currency = (string)$transaction->paymentCurrency;

        return [
            'amount' => $this->amount($transaction->paymentAmount, $currency),
            'currency' => $currency,
            'email' => (string)$order->getEmail(),
            'reference' => $transaction->hash,
            'callback_url' => Url::actionUrl('commerce/payments/complete-payment', [
                'commerceTransactionId' => $transaction->id,
                'commerceTransactionHash' => $transaction->hash,
            ]),
        ];
    }

    /**
     * Returns an amount in the form Paystack expects: an integer in hundredths of the currency unit.
     *
     * Paystack multiplies every currency by 100, including currencies without a subunit, such as XOF.
     *
     * @see https://paystack.com/docs/api/#supported-currency
     */
    protected function amount(float $amount, string $currency): int
    {
        $currencies = app(Currencies::class);
        $minorUnits = (int)$currencies->getTeller($currency)->convertToMoney($amount)->getAmount();
        $subunit = $currencies->getSubunitFor($currency);

        return $subunit <= 2
            ? $minorUnits * 10 ** (2 - $subunit)
            : (int)round($minorUnits / 10 ** ($subunit - 2));
    }

    protected function client(): Client
    {
        return new Client((string)$this->getSecretKey());
    }

    #[Override]
    public function authorize(Transaction $transaction, BasePaymentForm $form): RequestResponseInterface
    {
        throw new NotImplementedException(t('Authorizing is not supported by this gateway', category: 'commerce'));
    }

    #[Override]
    public function capture(Transaction $transaction, string $reference): RequestResponseInterface
    {
        throw new NotImplementedException(t('Capturing is not supported by this gateway', category: 'commerce'));
    }

    #[Override]
    public function completeAuthorize(Transaction $transaction): RequestResponseInterface
    {
        throw new NotImplementedException(t('Completing authorization is not supported by this gateway', category: 'commerce'));
    }

    #[Override]
    public function createPaymentSource(BasePaymentForm $sourceData, int $customerId): PaymentSource
    {
        throw new NotImplementedException(t('Payment sources are not supported by this gateway', category: 'commerce'));
    }

    #[Override]
    public function deletePaymentSource(string $token): bool
    {
        throw new NotImplementedException(t('Payment sources are not supported by this gateway', category: 'commerce'));
    }

    #[Override]
    public function supportsAuthorize(): bool
    {
        return false;
    }

    #[Override]
    public function supportsCapture(): bool
    {
        return false;
    }

    #[Override]
    public function supportsCompleteAuthorize(): bool
    {
        return false;
    }

    #[Override]
    public function supportsCompletePurchase(): bool
    {
        return true;
    }

    #[Override]
    public function supportsPaymentSources(): bool
    {
        return false;
    }

    #[Override]
    public function supportsPurchase(): bool
    {
        return true;
    }

    #[Override]
    public function supportsRefund(): bool
    {
        return true;
    }

    #[Override]
    public function supportsPartialRefund(): bool
    {
        return true;
    }

    #[Override]
    public function supportsWebhooks(): bool
    {
        return true;
    }
}
