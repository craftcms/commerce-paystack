# Release Notes for Paystack for Craft Commerce

## Unreleased

> [!WARNING]
> This release requires Craft Commerce 6. See [UPGRADE.md](UPGRADE.md) for the breaking changes.

- Paystack for Craft Commerce now requires Craft CMS 6, Craft Commerce 6 and PHP 8.5.
- The plugin now uses the Paystack API directly, and no longer requires `craftcms/commerce-omnipay` or `paystackhq/omnipay-paystack`.
- Moved all classes to the `CraftCms\CommercePaystack` namespace. `craft\commerce\paystack\gateways\Paystack` is kept as an alias of `CraftCms\CommercePaystack\Gateway`.
- Added support for full and partial refunds.
- The gateway now processes Paystack’s `charge.success` webhook events, after checking their signature and verifying the transaction with Paystack.
- Payments that Paystack is still processing when the customer returns are now recorded as processing, rather than failed.
- Added `CraftCms\CommercePaystack\Events\PaymentRequestBuilding`, which replaces the Omnipay request events.
- The **Secret Key** gateway setting is now required.
- The payment form is now built with `paymentFormUi()` instead of a Twig template.
- Fixed a bug where amounts in West African CFA francs (XOF) were sent to Paystack as 1/100 of the order total.

## 2.0.0 - 2021-04-20

### Changed
- The plugin now requires Craft 3.6 and Commerce 3.3 or later.
- The plugin now requires Guzzle 7.

## 1.0.0 - 2020-06-24

### Added
- Initial Release
