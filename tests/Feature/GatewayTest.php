<?php

declare(strict_types=1);

use CraftCms\Cms\Support\Facades\Elements;
use CraftCms\Cms\Support\Facades\HtmlStack;
use CraftCms\Commerce\Order\Elements\Order;
use CraftCms\Commerce\Payment\Data\Transaction;
use CraftCms\Commerce\Payment\Forms\OffsitePaymentForm;
use CraftCms\Commerce\Payment\Gateway\Gateways;
use CraftCms\Commerce\Payment\Gateway\GatewayTypes;
use CraftCms\Commerce\Payment\Models\Transaction as TransactionModel;
use CraftCms\Commerce\Payment\Payments;
use CraftCms\Commerce\Payment\Transactions;
use CraftCms\CommercePaystack\Events\PaymentRequestBuilding;
use CraftCms\CommercePaystack\Gateway;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

const PAYSTACK_SECRET_KEY = 'sk_test_secret';

function paystackGateway(array $settings = ['secretKey' => PAYSTACK_SECRET_KEY, 'publicKey' => 'pk_test_public']): Gateway
{
    /** @var Gateway $gateway */
    $gateway = app(Gateways::class)->createGateway([
        'type' => Gateway::class,
        'name' => 'Paystack',
        'handle' => 'paystack',
        'paymentType' => 'purchase',
        'settings' => $settings,
    ]);

    return $gateway;
}

/**
 * Saves a gateway and an order, and returns a purchase transaction for it.
 */
function paystackTransaction(float $amount = 12.3, ?Gateway $gateway = null): Transaction
{
    $gateway ??= paystackGateway();
    app(Gateways::class)->saveGateway($gateway);

    $order = new Order();
    $order->email = 'customer@example.com';
    $order->gatewayId = $gateway->id;
    Elements::saveElement($order);

    $transaction = app(Transactions::class)->createTransaction($order, null, TransactionModel::TYPE_PURCHASE);
    $transaction->paymentAmount = $amount;
    $transaction->amount = $amount;

    return $transaction;
}

/**
 * Saves a transaction the customer was redirected to Paystack for. Its reference is the one sent to Paystack.
 */
function redirectedTransaction(?Gateway $gateway = null): Transaction
{
    $transaction = paystackTransaction(gateway: $gateway);
    $transaction->reference = $transaction->hash;
    $transaction->status = TransactionModel::STATUS_REDIRECT;
    app(Transactions::class)->saveTransaction($transaction);

    return $transaction;
}

/**
 * Returns a Verify Transaction response, as documented at https://paystack.com/docs/api/transaction/#verify.
 */
function verifiedTransaction(string $reference, array $attributes = []): array
{
    return [
        'status' => true,
        'message' => 'Verification successful',
        'data' => array_merge([
            'id' => 4099260516,
            'domain' => 'test',
            'status' => 'success',
            'reference' => $reference,
            'amount' => 1230,
            'message' => null,
            'gateway_response' => 'Successful',
            'channel' => 'card',
            'currency' => 'USD',
        ], $attributes),
    ];
}

function postPaystackWebhook(Gateway $gateway, array $event, ?string $signature = null): Illuminate\Testing\TestResponse
{
    $body = json_encode($event);
    $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];

    if ($signature !== '') {
        $server['HTTP_X_PAYSTACK_SIGNATURE'] = $signature ?? hash_hmac('sha512', $body, PAYSTACK_SECRET_KEY);
    }

    return test()->call('POST', $gateway->getWebhookUrl(), [], [], [], $server, $body);
}

function chargeSuccessEvent(string $reference): array
{
    return [
        'event' => 'charge.success',
        'data' => ['id' => 302961, 'status' => 'success', 'reference' => $reference, 'amount' => 1230, 'currency' => 'USD'],
    ];
}

it('registers the gateway type', function() {
    expect(app(GatewayTypes::class)->types()->all())->toContain(Gateway::class);
});

it('loads gateways saved with the Craft Commerce 3 class name', function() {
    $gateway = app(Gateways::class)->createGateway([
        'type' => 'craft\\commerce\\paystack\\gateways\\Paystack',
        'settings' => ['secretKey' => '$PAYSTACK_SECRET_KEY', 'publicKey' => '$PAYSTACK_PUBLIC_KEY'],
    ]);

    expect($gateway)->toBeInstanceOf(Gateway::class)
        ->and($gateway->getSettings())->toBe(['secretKey' => '$PAYSTACK_SECRET_KEY', 'publicKey' => '$PAYSTACK_PUBLIC_KEY'])
        ->and($gateway->getConfig()['type'])->toBe(Gateway::class);
});

it('parses environment variables in the keys', function() {
    putenv('PAYSTACK_TEST_PUBLIC_KEY=pk_test_from_env');
    $gateway = paystackGateway(['secretKey' => 'sk_test', 'publicKey' => '$PAYSTACK_TEST_PUBLIC_KEY']);

    expect($gateway->getPublicKey())->toBe('pk_test_from_env')
        ->and($gateway->getPublicKey(false))->toBe('$PAYSTACK_TEST_PUBLIC_KEY');
    putenv('PAYSTACK_TEST_PUBLIC_KEY');
});

it('requires a secret key and the purchase payment type', function() {
    $gateway = paystackGateway(['secretKey' => '', 'publicKey' => '']);
    $gateway->paymentType = 'authorize';

    expect($gateway->validate())->toBeFalse()
        ->and($gateway->errors()->keys())->toContain('secretKey', 'paymentType')
        ->not->toContain('publicKey');
});

it('renders the key settings', function() {
    $html = paystackGateway(['secretKey' => '$PAYSTACK_SECRET_KEY', 'publicKey' => '$PAYSTACK_PUBLIC_KEY'])->getSettingsHtml();

    expect($html)->toContain('secretKey')->toContain('$PAYSTACK_SECRET_KEY')
        ->toContain('publicKey')->toContain('$PAYSTACK_PUBLIC_KEY');
});

it('initializes a transaction and redirects to the Paystack checkout', function() {
    Http::fake(['api.paystack.co/transaction/initialize' => Http::response([
        'status' => true,
        'message' => 'Authorization URL created',
        'data' => ['authorization_url' => 'https://checkout.paystack.com/3ni8kdavz62431k', 'access_code' => '3ni8kdavz62431k', 'reference' => 're4lyvq3s3'],
    ])]);
    $transaction = paystackTransaction(19.99);
    $transaction->paymentCurrency = 'NGN';

    $response = $transaction->getGateway()->purchase($transaction, new OffsitePaymentForm());

    expect($response->isRedirect())->toBeTrue()
        ->and($response->isSuccessful())->toBeFalse()
        ->and($response->isProcessing())->toBeFalse()
        ->and($response->getRedirectMethod())->toBe('GET')
        ->and($response->getRedirectUrl())->toBe('https://checkout.paystack.com/3ni8kdavz62431k')
        ->and($response->getTransactionReference())->toBe('re4lyvq3s3')
        ->and($response->getCode())->toBe('3ni8kdavz62431k');

    Http::assertSent(function(Request $request) use ($transaction) {
        return $request->method() === 'POST'
            && $request->url() === 'https://api.paystack.co/transaction/initialize'
            && $request->hasHeader('Authorization', 'Bearer ' . PAYSTACK_SECRET_KEY)
            && $request->isJson()
            // 19.99 * 100 is 1998.9999999999998 as a float.
            && $request['amount'] === 1999
            && $request['currency'] === 'NGN'
            && $request['email'] === 'customer@example.com'
            && $request['reference'] === $transaction->hash
            && str_contains($request['callback_url'], 'commerce/payments/complete-payment')
            && str_contains($request['callback_url'], 'commerceTransactionHash=' . $transaction->hash);
    });
});

it('sends amounts in hundredths of the currency unit', function(string $currency, float $amount, int $expected) {
    Http::fake(['api.paystack.co/*' => Http::response(['status' => true, 'data' => ['authorization_url' => 'https://checkout.paystack.com/x', 'reference' => 'x']])]);
    $transaction = paystackTransaction($amount);
    $transaction->paymentCurrency = $currency;

    $transaction->getGateway()->purchase($transaction, new OffsitePaymentForm());

    Http::assertSent(fn(Request $request) => $request['amount'] === $expected);
})->with([
    'NGN' => ['NGN', 100, 10000],
    'GHS with pesewas' => ['GHS', 0.1, 10],
    'ZAR rounded to cents' => ['ZAR', 10.005, 1001],
    // Paystack multiplies XOF by 100 even though it has no subunit.
    'XOF' => ['XOF', 5000, 500000],
]);

it('lets listeners modify the request', function() {
    Http::fake(['api.paystack.co/*' => Http::response(['status' => true, 'data' => ['authorization_url' => 'https://checkout.paystack.com/x', 'reference' => 'x']])]);
    Event::listen(function(PaymentRequestBuilding $event) {
        $event->request['channels'] = ['card', 'bank_transfer'];
        $event->request['metadata'] = ['cancel_action' => 'https://example.com/cart'];
    });
    $transaction = paystackTransaction();

    $transaction->getGateway()->purchase($transaction, new OffsitePaymentForm());

    Http::assertSent(fn(Request $request) => $request['channels'] === ['card', 'bank_transfer']
        && $request['metadata'] === ['cancel_action' => 'https://example.com/cart']
        && $request['reference'] === $transaction->hash);
});

it('reports Paystack errors when initializing a transaction', function() {
    Http::fake(['api.paystack.co/*' => Http::response(['status' => false, 'message' => 'Invalid key'], 401)]);
    $transaction = paystackTransaction();

    $response = $transaction->getGateway()->purchase($transaction, new OffsitePaymentForm());

    expect($response->isRedirect())->toBeFalse()
        ->and($response->isSuccessful())->toBeFalse()
        ->and($response->isProcessing())->toBeFalse()
        ->and($response->getMessage())->toBe('Invalid key');
});

it('verifies the transaction when the customer returns', function(array $attributes, bool $successful, bool $processing, string $message) {
    $transaction = paystackTransaction();
    $transaction->reference = $transaction->hash;
    Http::fake(['api.paystack.co/transaction/verify/*' => Http::response(verifiedTransaction($transaction->hash, $attributes))]);

    $response = $transaction->getGateway()->completePurchase($transaction);

    expect($response->isSuccessful())->toBe($successful)
        ->and($response->isProcessing())->toBe($processing)
        ->and($response->isRedirect())->toBeFalse()
        ->and($response->getCode())->toBe($attributes['status'])
        ->and($response->getTransactionReference())->toBe($transaction->hash)
        ->and($response->getMessage())->toBe($message);

    Http::assertSent(fn(Request $request) => $request->method() === 'GET'
        && $request->url() === 'https://api.paystack.co/transaction/verify/' . $transaction->hash
        && $request->hasHeader('Authorization', 'Bearer ' . PAYSTACK_SECRET_KEY));
})->with([
    'success' => [['status' => 'success'], true, false, 'Successful'],
    'failed' => [['status' => 'failed', 'gateway_response' => 'Declined'], false, false, 'Declined'],
    'abandoned' => [['status' => 'abandoned', 'gateway_response' => 'The transaction was not completed'], false, false, 'The transaction was not completed'],
    'reversed' => [['status' => 'reversed', 'gateway_response' => 'Approved'], false, false, 'Approved'],
    'ongoing' => [['status' => 'ongoing', 'gateway_response' => 'Awaiting transfer'], false, true, 'Awaiting transfer'],
    'pending' => [['status' => 'pending', 'message' => 'madePayment', 'gateway_response' => 'Pending'], false, true, 'madePayment'],
    'processing' => [['status' => 'processing'], false, true, 'Successful'],
    'queued' => [['status' => 'queued'], false, true, 'Successful'],
]);

it('reports verification errors as failed', function() {
    $transaction = paystackTransaction();
    $transaction->reference = $transaction->hash;
    Http::fake(['api.paystack.co/*' => Http::response(['status' => false, 'message' => 'Transaction reference not found'], 400)]);

    $response = $transaction->getGateway()->completePurchase($transaction);

    expect($response->isSuccessful())->toBeFalse()
        ->and($response->isProcessing())->toBeFalse()
        ->and($response->getCode())->toBe('')
        ->and($response->getMessage())->toBe('Transaction reference not found');
});

it('marks the order as paid when a returning customer’s payment succeeded', function() {
    $transaction = redirectedTransaction();
    Http::fake(['api.paystack.co/*' => Http::response(verifiedTransaction($transaction->hash))]);

    $success = app(Payments::class)->completePayment($transaction, $customError);

    $children = app(Transactions::class)->getChildrenByTransactionId($transaction->id);
    expect($success)->toBeTrue()
        ->and($children)->toHaveCount(1)
        ->and($children[0]->status)->toBe(TransactionModel::STATUS_SUCCESS)
        ->and($children[0]->reference)->toBe($transaction->hash);
});

it('refunds a transaction', function() {
    Http::fake(['api.paystack.co/refund' => Http::response([
        'status' => true,
        'message' => 'Refund has been queued for processing',
        'data' => ['id' => 3018284, 'status' => 'pending', 'amount' => 500, 'currency' => 'USD', 'transaction' => ['reference' => 'ref']],
    ])]);
    $purchase = paystackTransaction(20);
    $purchase->reference = $purchase->hash;
    $purchase->status = TransactionModel::STATUS_SUCCESS;
    app(Transactions::class)->saveTransaction($purchase);

    $refund = app(Payments::class)->refundTransaction($purchase, 5, 'Damaged in transit');

    expect($refund->status)->toBe(TransactionModel::STATUS_SUCCESS)
        ->and($refund->reference)->toBe('3018284')
        ->and($refund->code)->toBe('pending')
        ->and($refund->message)->toBe('Refund has been queued for processing');

    Http::assertSent(fn(Request $request) => $request->method() === 'POST'
        && $request->hasHeader('Authorization', 'Bearer ' . PAYSTACK_SECRET_KEY)
        && $request->data() === [
            'transaction' => $purchase->hash,
            'amount' => 500,
            'currency' => 'USD',
            'merchant_note' => 'Damaged in transit',
        ]);
});

it('reports failed refunds', function(int $httpStatus, array $body, string $message) {
    Http::fake(['api.paystack.co/refund' => Http::response($body, $httpStatus)]);
    $purchase = paystackTransaction(20);
    $purchase->reference = $purchase->hash;
    $purchase->status = TransactionModel::STATUS_SUCCESS;
    app(Transactions::class)->saveTransaction($purchase);

    $refund = app(Payments::class)->refundTransaction($purchase, 20);

    expect($refund->status)->toBe(TransactionModel::STATUS_FAILED)
        ->and($refund->message)->toBe($message);
    Http::assertSent(fn(Request $request) => $request['amount'] === 2000 && !isset($request['merchant_note']));
})->with([
    'API error' => [400, ['status' => false, 'message' => 'Transaction has been fully reversed'], 'Transaction has been fully reversed'],
    'failed refund' => [200, ['status' => true, 'message' => 'Refund created', 'data' => ['id' => 1, 'status' => 'failed']], 'The refund failed.'],
]);

it('records a successful charge reported by the webhook', function() {
    $transaction = redirectedTransaction();
    $gateway = $transaction->getGateway();
    Http::fake(['api.paystack.co/*' => Http::response(verifiedTransaction($transaction->hash))]);

    postPaystackWebhook($gateway, chargeSuccessEvent($transaction->hash))
        ->assertOk()
        ->assertContent('ok');

    $children = app(Transactions::class)->getChildrenByTransactionId($transaction->id);
    expect($children)->toHaveCount(1)
        ->and($children[0]->status)->toBe(TransactionModel::STATUS_SUCCESS)
        ->and($children[0]->type)->toBe(TransactionModel::TYPE_PURCHASE)
        ->and($children[0]->reference)->toBe($transaction->hash)
        ->and($children[0]->code)->toBe('success');
    Http::assertSent(fn(Request $request) => $request->url() === 'https://api.paystack.co/transaction/verify/' . $transaction->hash);
});

it('rejects webhooks without a valid signature', function(string $case) {
    Http::fake();
    $transaction = redirectedTransaction();
    $event = chargeSuccessEvent($transaction->hash);
    $body = json_encode($event);

    $signature = match ($case) {
        'missing' => '',
        'signed with another key' => hash_hmac('sha512', $body, 'sk_test_other'),
        'signature of a different body' => hash_hmac('sha512', json_encode(chargeSuccessEvent('other')), PAYSTACK_SECRET_KEY),
        'SHA-256 signature' => hash_hmac('sha256', $body, PAYSTACK_SECRET_KEY),
    };

    postPaystackWebhook($transaction->getGateway(), $event, $signature)
        ->assertStatus(400);

    expect(app(Transactions::class)->getChildrenByTransactionId($transaction->id))->toBeEmpty();
    Http::assertNothingSent();
})->with(['missing', 'signed with another key', 'signature of a different body', 'SHA-256 signature']);

it('rejects webhooks when the gateway has no secret key', function() {
    Http::fake();
    $transaction = redirectedTransaction(paystackGateway(['secretKey' => '$PAYSTACK_UNDEFINED_KEY']));
    $event = chargeSuccessEvent($transaction->hash);

    // An empty key must not accept a signature made with an empty key.
    postPaystackWebhook($transaction->getGateway(), $event, hash_hmac('sha512', json_encode($event), ''))
        ->assertStatus(400);

    Http::assertNothingSent();
});

it('always verifies the transaction with the API', function() {
    $transaction = redirectedTransaction();
    // The event says the charge succeeded, but Paystack says it didn't.
    Http::fake(['api.paystack.co/*' => Http::response(verifiedTransaction($transaction->hash, ['status' => 'abandoned']))]);

    postPaystackWebhook($transaction->getGateway(), chargeSuccessEvent($transaction->hash))->assertOk();

    expect(app(Transactions::class)->getChildrenByTransactionId($transaction->id))->toBeEmpty();
    Http::assertSentCount(1);
});

it('asks Paystack to retry a webhook when the transaction can’t be verified', function() {
    $transaction = redirectedTransaction();
    Http::fake(['api.paystack.co/*' => Http::response(['status' => false, 'message' => 'Service unavailable'], 503)]);

    postPaystackWebhook($transaction->getGateway(), chargeSuccessEvent($transaction->hash))->assertStatus(503);

    expect(app(Transactions::class)->getChildrenByTransactionId($transaction->id))->toBeEmpty();
});

it('records a charge only once', function() {
    $transaction = redirectedTransaction();
    $gateway = $transaction->getGateway();
    Http::fake(['api.paystack.co/*' => Http::response(verifiedTransaction($transaction->hash))]);

    postPaystackWebhook($gateway, chargeSuccessEvent($transaction->hash))->assertOk();
    postPaystackWebhook($gateway, chargeSuccessEvent($transaction->hash))->assertOk();

    expect(app(Transactions::class)->getChildrenByTransactionId($transaction->id))->toHaveCount(1);
    Http::assertSentCount(1);
});

it('ignores webhooks for unknown references, other gateways and other events', function() {
    Http::fake();
    $transaction = redirectedTransaction();
    $gateway = $transaction->getGateway();
    $otherGateway = paystackGateway();
    $otherGateway->handle = 'otherPaystack';
    $otherTransaction = redirectedTransaction($otherGateway);

    postPaystackWebhook($gateway, chargeSuccessEvent('unknown-reference'))->assertOk();
    postPaystackWebhook($gateway, chargeSuccessEvent($otherTransaction->hash))->assertOk();
    postPaystackWebhook($gateway, ['event' => 'refund.processed', 'data' => ['transaction_reference' => $transaction->hash]])->assertOk();
    postPaystackWebhook($gateway, ['event' => 'transfer.success', 'data' => ['reference' => $transaction->hash]])->assertOk();

    expect(app(Transactions::class)->getChildrenByTransactionId($transaction->id))->toBeEmpty()
        ->and(app(Transactions::class)->getChildrenByTransactionId($otherTransaction->id))->toBeEmpty();
    Http::assertNothingSent();
});

it('renders the payment form and loads Paystack’s JavaScript', function() {
    HtmlStack::clear();

    $html = paystackGateway()->getPaymentFormHtml([]);

    expect($html)->toContain('You will be redirected')
        ->not->toContain('<input')
        ->not->toContain('inert')
        ->and(HtmlStack::bodyEndHtml())->toContain('<script src="https://js.paystack.co/v2/paystack.js"></script>');
});
