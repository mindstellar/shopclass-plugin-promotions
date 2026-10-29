<?php
/*
Plugin Name: Promotions
Plugin URI: https://github.com/mindstellar/shopclass-plugin-promotions
Description: Coupon codes, automatic discounts, a first-order offer and usage limits for Shopclass shop orders. Every discount is worked out on the server, and refusals show at checkout.
Version: 0.1.1
Author: Navjot Tomer (Mindstellar)
Author URI: https://mindstellar.com
Short Name: promotions
Requires Shopclass: 7.1.0
Tested up to: 7.1
Requires PHP: 8.2
Support URI: https://github.com/mindstellar/shopclass-plugin-promotions/issues
Modes: shop
*/

/*
 * This file is part of the Promotions plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use mindstellar\promotions\Admin\Screens;
use mindstellar\promotions\Plugin;

if (!defined('ABS_PATH')) {
    exit('Direct access is not allowed.');
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'mindstellar\\promotions\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

osc_register_plugin(osc_plugin_path(__FILE__), array(Plugin::class, 'install'));
osc_add_hook(osc_plugin_path(__FILE__) . '_uninstall', array(Plugin::class, 'uninstall'));

// The plugin works through Shopclass 7.1's cart hooks, key-value counters, order count, settings
// pages and admin screens. On a core without them it loads and does nothing but say so.
if (!class_exists('mindstellar\\billing\\CartPricer')
    || !defined(\mindstellar\billing\CartPricer::class . '::PROBLEM_POINTERS')
    || !function_exists('osc_kv_increment')
    || !function_exists('osc_billing_user_order_count')
    || !function_exists('osc_order_lines')
    || !function_exists('osc_register_settings_page')
    || !function_exists('osc_admin_plugin_page')
) {
    osc_add_hook('admin_page_header', static function (): void {
        echo '<div class="flashmessage flashmessage-warning" role="status">'
            . osc_esc_html(__('Promotions needs Shopclass 7.1 or later with shop mode and is switched off. Update Shopclass to use it.', 'promotions'))
            . '</div>';
    }, 10);

    return;
}

osc_register_settings_page(Plugin::PAGE, require __DIR__ . '/settings.php');
osc_add_hook(osc_plugin_path(__FILE__) . '_configure', static function () {
    osc_redirect_to(osc_route_admin_url(Screens::ROUTE));
});

osc_add_filter('cart_discount_lines', array(Plugin::class, 'onDiscountLines'), 10);
osc_add_filter('cart_problems', array(Plugin::class, 'onProblems'), 10);

// A use is taken just before the order is written, kept once it is placed, given back when the
// order is cancelled, fails or is refunded.
osc_add_hook('cart_checkout_before', array(Plugin::class, 'onCheckoutStarting'), 10);
osc_add_hook('billing_order_placed', array(Plugin::class, 'onOrderPlaced'), 10);
foreach (array('billing_order_cancelled', 'billing_order_failed') as $prHook) {
    osc_add_hook($prHook, array(Plugin::class, 'onOrderEnded'), 10);
}
osc_add_hook('billing_order_refunded', array(Plugin::class, 'orderCancelled'), 10);

// The Offers screens.
foreach (array(Screens::ROUTE => 'admin/offers.php', Screens::EDIT_ROUTE => 'admin/offer.php') as $prRoute => $prFile) {
    osc_add_route($prRoute, str_replace('-', '/', $prRoute), str_replace('-', '/', $prRoute), osc_plugin_folder(__FILE__) . $prFile);
}
osc_add_hook('init_admin', array(Screens::class, 'handlePost'));
osc_add_hook('admin_menu_init', array(Screens::class, 'menu'));
osc_add_hook('init_admin', array(Screens::class, 'pages'));

if (!function_exists('promotions_banner')) {
    /**
     * The first-order banner text while an automatic first-order offer is on, else ''.
     * Plain text: escape it when printing.
     */
    function promotions_banner(): string
    {
        return Plugin::banner();
    }
}
