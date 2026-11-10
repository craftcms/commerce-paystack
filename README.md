<p align="center"><img src="./resources/icon.svg" width="100" height="100" alt="Paystack for Craft Commerce icon"></p>

<h1 align="center">Paystack for Craft Commerce</h1>

This plugin provides a [Paystack](https://paystack.com/) integration for [Craft Commerce](https://craftcms.com/commerce).

## Requirements

Paystack 3.x requires Craft CMS 6.0, Craft Commerce 6.0 and PHP 8.5 or later.

Paystack 2.0.x supports Craft 3.6 and Craft Commerce 3.3. If you’re upgrading from 2.x, see [UPGRADE.md](UPGRADE.md).

## Installation

You can install this plugin from the Plugin Store or with Composer.

#### From the Plugin Store

Go to the **Plugin Store** in your project’s control panel and search for “Paystack for Craft Commerce”. Then click on the **Install** button in the sidebar.

#### With Composer

Open your terminal and run the following commands:

```bash
# Navigate to your project directory:
cd /path/to/my-project.test

# Require the plugin package with Composer:
composer require craftcms/commerce-paystack

# Run the installer:
php artisan craft:plugin:install commerce-paystack
```

## Setup

To add a Paystack payment gateway, go to Commerce → Settings → Gateways, create a new gateway, and set the gateway type to “Paystack”. Enter the **Secret Key** and **Public Key** from Settings → API Keys & Webhooks in your [Paystack dashboard](https://dashboard.paystack.com/#/settings/developers).

> [!NOTE]
> The **Secret Key** and **Public Key** settings can be set to environment variables. See [Environmental Configuration](https://craftcms.com/docs/6.x/configure.html#control-panel-settings) in the Craft docs to learn more about using secrets in configuration.

Customers are redirected to Paystack’s checkout to pay, and their payment is verified with Paystack when they return to your site. The gateway only supports the “Purchase” payment type.

### Webhooks

Paystack sends a webhook event when a payment succeeds, which completes orders whose customers didn’t make it back to your site, and payments (such as bank transfers) that were still being processed when they did.

Set the **Live Webhook URL** (or **Test Webhook URL**) in the same dashboard screen to:

```
https://my-project.test/actions/commerce/webhooks/process-webhook?gateway=<gatewayId>
```

Replace `<gatewayId>` with the ID of the gateway, which is the number at the end of its edit page URL in the control panel. Paystack only allows one webhook URL per mode, so use a single Paystack gateway per Paystack account.

Webhook events are only accepted with a valid `x-paystack-signature` header, and the payment is always verified with the Paystack API before it’s recorded.

## Refunds

Payments can be refunded in full or in part from the order in the control panel. Paystack queues refunds, so a refund transaction is recorded as successful once Paystack accepts it, before the money reaches the customer.

## Customizing payment requests

To change the request sent to Paystack when a payment is created, listen for the `PaymentRequestBuilding` event and modify its `request` array. Its keys are Paystack’s [Initialize Transaction](https://paystack.com/docs/api/transaction/#initialize) parameters.

```php
use CraftCms\CommercePaystack\Events\PaymentRequestBuilding;
use Illuminate\Support\Facades\Event;

Event::listen(function(PaymentRequestBuilding $event): void {
    $event->request['channels'] = ['card', 'bank_transfer'];
});
```

Don’t change the `reference`. The plugin uses it to match webhook events to Commerce transactions.
