<?php

declare(strict_types=1);

namespace CraftCms\CommercePaystack;

use CraftCms\Cms\Plugin\Plugin as BasePlugin;
use CraftCms\Commerce\Payment\Gateway\GatewayTypes;

/**
 * Paystack for Craft Commerce.
 */
class Plugin extends BasePlugin
{
    public function boot(GatewayTypes $gatewayTypes): void
    {
        $gatewayTypes->register(Gateway::class);
    }
}
