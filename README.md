<p align="center"><img src="./src/icon.svg" width="100" height="100" alt="Paystack for Craft Commerce icon"></p>

<h1 align="center">Paystack for Craft Commerce</h1>

This plugin provides a [Paystack](https://paystack.com/) integration for [Craft Commerce](https://craftcms.com/commerce).

## Requirements

This plugin requires Craft 3.4 and Craft Commerce 3.1 or later.

This plugin uses [`paystackhq/omnipay-paystack`](https://packagist.org/packages/paystackhq/omnipay-paystack) PHP package.

## Installation

You can install this plugin from the Plugin Store or with Composer.

#### From the Plugin Store

Go to the Plugin Store in your project’s Control Panel and search for “Paystack for Craft Commerce”. Then click on the “Install” button in its modal window.

#### With Composer

Open your terminal and run the following commands:

```bash
# go to the project directory
cd /path/to/my-project.test

# tell Composer to load the plugin
composer require craftcms/commerce-paystack

# tell Craft to install the plugin
./craft install/plugin commerce-paystack
```

## Roadmap

- [ ] Add support for refunding from the CP. Requires refund notification support in the Paystack API.