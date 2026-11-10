# Upgrading to Paystack for Craft Commerce 3.0 (Commerce 6)

Paystack for Craft Commerce 3.0 supports Craft Commerce 6. It talks to the [Paystack API](https://paystack.com/docs/api/) directly, rather than through Omnipay.

## Requirements

- Craft CMS 6.0 and Craft Commerce 6.0 or later
- PHP 8.5 or later
- `craftcms/yii2-adapter` is not needed.

## Upgrade steps

Paystack for Craft Commerce 2.0 supports Craft 3 and Craft Commerce 3 only. There is no release for Craft Commerce 4 or 5, so the plugin can’t stay installed while your project is upgraded through Craft 4 and 5.

1. Remove the plugin before upgrading to Craft 4: `composer remove craftcms/commerce-paystack`. Uninstalling it leaves your Paystack gateways in place. Commerce shows them as missing gateways until the plugin is installed again.
2. Upgrade your project to Craft 6 and Craft Commerce 6.
3. Install the plugin: `composer require craftcms/commerce-paystack:^3.0`, then `php artisan craft:plugin:install commerce-paystack`.
4. Set your webhook URL in the Paystack dashboard (see [Webhooks](README.md#webhooks)).
5. Review the breaking changes below.

Existing Paystack gateways keep working: gateways saved with the old `craft\commerce\paystack\gateways\Paystack` type load as `CraftCms\CommercePaystack\Gateway`, and save with the new class name the next time they’re saved.

## Breaking changes

### Omnipay

The plugin no longer depends on `craftcms/commerce-omnipay`, `paystackhq/omnipay-paystack` or `omnipay/common`. If your project uses Omnipay classes directly, require those packages yourself.

### Events

The Omnipay gateway events are gone. To change the request sent to Paystack when a payment is created, listen for `CraftCms\CommercePaystack\Events\PaymentRequestBuilding` and modify its `$request` array, which is the body of the [Initialize Transaction](https://paystack.com/docs/api/transaction/#initialize) request.

| Before | After |
| --- | --- |
| `craft\commerce\omnipay\base\Gateway::EVENT_BEFORE_SEND_PAYMENT_REQUEST` | `CraftCms\CommercePaystack\Events\PaymentRequestBuilding` |
| `craft\commerce\omnipay\base\Gateway::EVENT_BEFORE_GATEWAY_REQUEST_SEND` | No replacement |
| `craft\commerce\omnipay\base\Gateway::EVENT_AFTER_CREATE_ITEM_BAG` | No replacement |

Before:

```php
use craft\commerce\omnipay\events\SendPaymentRequestEvent;
use craft\commerce\paystack\gateways\Paystack;
use yii\base\Event;

Event::on(Paystack::class, Paystack::EVENT_BEFORE_SEND_PAYMENT_REQUEST, function(SendPaymentRequestEvent $event) {
    $data = $event->requestData;
    $data['channels'] = ['card', 'bank_transfer'];
    $event->modifiedRequestData = $data;
});
```

After:

```php
use CraftCms\CommercePaystack\Events\PaymentRequestBuilding;
use Illuminate\Support\Facades\Event;

Event::listen(function(PaymentRequestBuilding $event): void {
    $event->request['channels'] = ['card', 'bank_transfer'];
});
```

The keys of the request are Paystack’s own parameter names, as before. Don’t change `reference`: it’s the Commerce transaction hash, which the plugin uses to match webhook events to transactions.

### Classes and namespaces

| Before | After |
| --- | --- |
| `craft\commerce\paystack\Plugin` | `CraftCms\CommercePaystack\Plugin` |
| `craft\commerce\paystack\gateways\Paystack` | `CraftCms\CommercePaystack\Gateway` (the old name is kept as an alias) |
| `craft\commerce\paystack\models\PaymentForm` | `CraftCms\Commerce\Payment\Forms\OffsitePaymentForm` |
| `craft\commerce\paystack\web\assets\paystack\PaystackBundle` | Removed |
| `craft\commerce\paystack\migrations\Install` | Removed (it did nothing) |

The old payment form model’s `email` property was removed. It was always replaced with the order’s email address before the request was sent, and the order’s email address is still what Paystack receives.

### Settings

The **Secret Key** (`secretKey`) setting is now required. The **Public Key** (`publicKey`) setting is unchanged and optional. The gateway doesn’t use it, but templates can read it with `gateway.getPublicKey()`.

Only the “Purchase” payment type is available, as before: Paystack doesn’t support authorizing a payment and capturing it later.

### Templates and Twig

`gateway.getPaymentFormHtml()` still works, and the payment form still shows a “You will be redirected” message and loads Paystack’s JavaScript (`https://js.paystack.co/v2/paystack.js`). It has no fields: customers enter their payment details on Paystack’s checkout.

The form is now built by `CraftCms\CommercePaystack\Gateway::paymentFormUi()` rather than the `commerce-paystack/paymentForm` template, so you can no longer override that template. To change the form, render your own markup instead of calling `gateway.getPaymentFormHtml()`, or extend the gateway class and override `paymentFormUi()`.

The plugin’s `paymentForm.js` was removed. It only waited for Paystack’s JavaScript to load and logged a message to the browser console.

### Payment behavior

- Transactions that Paystack is still processing when the customer returns (`ongoing`, `pending`, `processing` or `queued`, such as bank transfers) are now recorded as processing, so the order is completed and marked as paid when Paystack’s webhook reports the payment. They were recorded as failed before.
- Paystack `charge.success` webhook events are now processed. Before, the webhook accepted every request and did nothing. Requests without a valid `x-paystack-signature` header are rejected with a 400 response.
- Refunds are now supported, in full or in part.
- Amounts in currencies without a subunit, such as West African CFA francs (XOF), are now multiplied by 100 [as Paystack requires](https://paystack.com/docs/api/#supported-currency). Before, an XOF 5,000 order was sent to Paystack as XOF 50.
