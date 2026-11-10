<?php

namespace craft\commerce\paystack\gateways;

// Gateways saved with Paystack for Craft Commerce 2 store this class name as their type.
/** @deprecated in 3.0.0. Use {@see \CraftCms\CommercePaystack\Gateway} instead. */
class_alias(\CraftCms\CommercePaystack\Gateway::class, 'craft\commerce\paystack\gateways\Paystack');

/** @phpstan-ignore-next-line */
if (false) {
    class Paystack extends \CraftCms\CommercePaystack\Gateway
    {
    }
}
