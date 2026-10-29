<?php
/*
 * This file is part of the Promotions plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * The plugin against core's real cart pricer, checkout, API and a scratch database: a code
 * lowers the quote's total, a used code is refused at /coupon, cancelling, a declined card and a
 * card order left unpaid return the use, and the first-order offer goes once the shopper has an
 * open order.
 *
 * Skips (exit 0) when core has no cart pricer yet. Usage:
 *   SHOPCLASS_CORE=/path/to/core php tests/integration/checkout.php
 */

$core = rtrim(getenv('SHOPCLASS_CORE') ?: dirname(__DIR__, 3) . '/shopclass', '/');
if (!is_file($core . '/oc-includes/osclass/classes/billing/CartPricer.php')) {
    echo "SKIP  core has no cart pricer (billing/CartPricer.php)\n";
    exit(getenv('REQUIRE_CORE') === '1' ? 1 : 0);
}
require_once $core . '/tests/lib/harness.php';
require_once $core . '/tests/lib/api-admin-kit.php';

use mindstellar\apikey\ApiKeys;
use mindstellar\apikey\KeyOwner;
use mindstellar\apikey\Scopes;
use mindstellar\billing\CallbackResult;
use mindstellar\billing\CartPricer;
use mindstellar\billing\CheckoutIntent;
use mindstellar\billing\GoodsCheckout;
use mindstellar\billing\Order;
use mindstellar\billing\OrderLineStore;
use mindstellar\billing\OrderStore;
use mindstellar\billing\PaymentGateway;
use mindstellar\billing\PaymentGatewayRegistry;
use mindstellar\billing\gateway\CashOnDeliveryGateway;
use mindstellar\exception\InvalidException;
use mindstellar\listing\VariantStore;
use mindstellar\promotions\Offer;
use mindstellar\promotions\Plugin;

$admin = api_admin_boot('osc_promotions_plugin');
$p     = DB_TABLE_PREFIX;

spl_autoload_register(static function (string $class): void {
    $prefix = 'mindstellar\\promotions\\';
    if (strncmp($class, $prefix, strlen($prefix)) === 0) {
        require __DIR__ . '/../../src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    }
});

$locale = seed_locale($admin);
seed_currency($admin);
seed_country($admin, 'IN', 'India');
$cat = seed_category($admin, 'Tees', seed_category($admin, 'Goods', null, $locale), $locale);
$sue = seed_user($admin, 'sue', 'sue@example.test');
$tom = seed_user($admin, 'tom', 'tom@example.test');
$ann = seed_user($admin, 'ann', 'ann@example.test');
$m   = static fn (float $units): int => (int) round($units * 1000000);
foreach (['enabled_users' => '1', 'language' => 'en_US', 'currency' => 'USD', 'billing_enabled' => '1', 'billing_currency' => 'USD', 'billing_shipping_flat' => (string) $m(49), 'billing_cod_enabled' => '1', 'site_mode' => 'shop'] as $k => $v) {
    Preference::getInstance()->set($k, $v);
}
scratchdb_forget_cache();
osc_reset_preferences();

final class PromotionsFakeGateway implements PaymentGateway
{
    public function getId(): string
    {
        return 'fake';
    }

    public function getName(): string
    {
        return 'Fake';
    }

    public function getSupportedCurrencies(): array
    {
        return ['USD'];
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function createCheckout(Order $order): CheckoutIntent
    {
        return CheckoutIntent::redirect('https://pay.example.test/' . $order->getId());
    }

    public function handleCallback(array $request): CallbackResult
    {
        return isset($request['fail']) ? CallbackResult::failed((int) $request['fail'], 'declined') : CallbackResult::ignored('none');
    }
}
PaymentGatewayRegistry::getInstance()->register(new PromotionsFakeGateway());
PaymentGatewayRegistry::getInstance()->register(new CashOnDeliveryGateway());

$tee = seed_item($admin, $cat, $sue, 'Tee', 499.0);
$v   = VariantStore::saveDefault($tee, ['i_stock' => null, 'i_price' => $m(499)]);
$mug = seed_item($admin, $cat, $sue, 'Mug', 1200.0);
$w   = VariantStore::saveDefault($mug, ['i_stock' => null, 'i_price' => $m(1200)]);

osc_add_filter('cart_discount_lines', [Plugin::class, 'onDiscountLines'], 10);
osc_add_filter('cart_problems', [Plugin::class, 'onProblems'], 10);
osc_add_hook('cart_checkout_before', [Plugin::class, 'onCheckoutStarting'], 10);
osc_add_hook('billing_order_placed', [Plugin::class, 'onOrderPlaced'], 10);
foreach (['billing_order_cancelled', 'billing_order_failed'] as $hook) {
    osc_add_hook($hook, [Plugin::class, 'onOrderEnded'], 10);
}
osc_add_hook('billing_order_refunded', [Plugin::class, 'orderCancelled'], 10);

Plugin::saveOffers([
    Offer::fromArray(['id' => 'welcome10', 'code' => 'WELCOME10', 'label' => '10% off', 'kind' => 'percent', 'value' => 1000, 'max_discount' => $m(500), 'per_user_limit' => 1, 'currency' => 'USD']),
    Offer::fromArray(['id' => 'first-order', 'code' => null, 'label' => 'First order', 'kind' => 'fixed', 'value' => $m(150), 'min_subtotal' => $m(999), 'first_order_only' => true, 'currency' => 'USD']),
]);
scratchdb_forget_cache();
osc_reset_preferences();
Plugin::reset();

$keys    = new ApiKeys(new \mindstellar\model\ApiCredential(), new Scopes(), new \mindstellar\utility\SystemClock());
$key     = static fn (int $user): string => $keys->create('key', 'k' . $user, ['orders:write', 'listings:read'], KeyOwner::user($user))->token();
$tomKey  = $key($tom);
$annKey  = $key($ann);
$call    = api_admin_caller();
$address = ['name' => 'Tom', 'phone' => '5550100', 'line1' => '1 Main St', 'line2' => '', 'city' => 'Delhi', 'state' => 'Delhi', 'postcode' => '110001', 'country' => 'IN'];
$order   = static fn (array $lines, string $gateway = 'fake', ?string $coupon = 'WELCOME10'): array => ['lines' => $lines, 'address' => $address, 'shipping_method' => 'standard', 'gateway' => $gateway] + ($coupon === null ? [] : ['coupon' => $coupon]);
$uses    = static fn (string $k): int => (int) osc_kv_get('promotions', $k, 0);

harness_section('the quote');
$r = $call('GET', 'cart/quote', null, $tomKey, [], ['lines' => $v . ':1', 'coupon' => 'welcome10']);
$q = $r->body()['data'] ?? [];
pin('the quote answers', 200, $r->status());
pin('the code\'s discount line, named by its code', [['WELCOME10 · 10% off', '-49.90']], array_map(static fn ($d) => [$d['label'], $d['amount']['amount']], $q['discounts'] ?? []));
pin('a smaller total: 499 + 49 shipping - 49.90', '498.10', $q['totals']['total']['amount'] ?? null);
pin('no problem', [], $q['problems'] ?? null);
$q = $call('GET', 'cart/quote', null, $tomKey, [], ['lines' => $v . ':1', 'coupon' => 'WELCOME99'])->body()['data'] ?? [];
pin('an unknown code is a problem on /coupon, and no discount', [[['line' => null, 'code' => 'coupon_invalid', 'pointer' => '/coupon', 'message' => 'This code is not valid.']], []], [$q['problems'] ?? null, $q['discounts'] ?? null]);
$q = $call('GET', 'cart/quote', null, $tomKey, [], ['lines' => $w . ':1'])->body()['data'] ?? [];
pin('the first-order offer applies by itself to a fresh shopper over 999', [['First order', '-150.00']], array_map(static fn ($d) => [$d['label'], $d['amount']['amount']], $q['discounts'] ?? []));

harness_section('one use per shopper');
$r = $call('POST', 'account/orders', $order([['variant' => $v, 'qty' => 1]]), $tomKey);
pin('the order is placed', 201, $r->status());
$placed = (int) ($r->body()['data']['order']['id'] ?? $r->body()['data']['id'] ?? 0);
$lines  = OrderLineStore::ofOrder($placed);
$disc   = array_values(array_filter($lines, static fn (array $l): bool => $l['kind'] === 'discount'));
pin('its discount line keeps the offer and code', [-$m(49.9), 'promo.welcome10', 'welcome10', 'WELCOME10'], [$disc[0]['amount'] ?? null, $disc[0]['meta']['id'] ?? null, $disc[0]['meta']['offer'] ?? null, $disc[0]['meta']['code'] ?? null]);
pin('the order total is server-worked', $m(499 + 49 - 49.9), OrderStore::find($placed)->getAmount());
pin('the use is counted, overall and for tom', [1, 1], [$uses('uses:welcome10'), $uses('uses:welcome10:u' . $tom)]);
$q = $call('GET', 'cart/quote', null, $tomKey, [], ['lines' => $v . ':1', 'coupon' => 'WELCOME10'])->body()['data'] ?? [];
pin('a second quote by tom says the code is used', ['coupon_used', 'You have already used this code.'], [$q['problems'][0]['code'] ?? null, $q['problems'][0]['message'] ?? null]);
$r = $call('POST', 'account/orders', $order([['variant' => $v, 'qty' => 1]]), $tomKey);
pin('a second order by tom is refused at /coupon', [422, '/coupon', 'You have already used this code.'], [$r->status(), $r->body()['errors'][0]['pointer'] ?? ($r->body()['error']['errors'][0]['pointer'] ?? null), $r->body()['errors'][0]['message'] ?? ($r->body()['error']['errors'][0]['message'] ?? null)]);
pin('and takes no use', [1, 1], [$uses('uses:welcome10'), $uses('uses:welcome10:u' . $tom)]);
$r = $call('POST', 'account/orders', $order([['variant' => $v, 'qty' => 1]]), $annKey);
pin('ann may still use it', 201, $r->status());
pin('two uses overall', 2, $uses('uses:welcome10'));

harness_section('cancelling returns the use');
check('the order is cancelled', OrderStore::cancel($placed));
pin('tom\'s use is back', [1, 0], [$uses('uses:welcome10'), $uses('uses:welcome10:u' . $tom)]);
$r = $call('POST', 'account/orders', $order([['variant' => $v, 'qty' => 1]]), $tomKey);
pin('so tom may use the code again', 201, $r->status());
OrderStore::cancel($placed);
pin('cancelling twice returns nothing more', [2, 1], [$uses('uses:welcome10'), $uses('uses:welcome10:u' . $tom)]);

harness_section('first order');
$fresh = seed_user($admin, 'fay', 'fay@example.test');
$admin->query("UPDATE {$p}t_user SET s_phone_mobile = '5550101' WHERE pk_i_id = $fresh");
$pricer = new CartPricer();
$auto   = static fn (int $user): array => array_column($pricer->quote([['variant' => $w, 'qty' => 1]], ['user_id' => $user])->extraLines(OrderLineStore::KIND_DISCOUNT), 'id');
pin('a fresh shopper gets the first-order offer', ['promo.first-order'], $auto($fresh));
$checkout = new GoodsCheckout($pricer);
$cod      = $checkout->place($fresh, [['variant' => $v, 'qty' => 1]], $address, 'standard', CashOnDeliveryGateway::ID);
pin('a pending cash on delivery order', Order::STATUS_PENDING, OrderStore::find($cod['order']->getId())->getStatus());
pin('then the offer is gone', [], $auto($fresh));
OrderStore::cancel($cod['order']->getId());
pin('a cancelled order does not count against it', ['promo.first-order'], $auto($fresh));
pin('a guest never gets it', [], $auto(0));

harness_section('a declined card and a card order left unpaid');
$card = $checkout->place($fresh, [['variant' => $w, 'qty' => 1]], $address, 'standard', 'fake')['order'];
pin('a card order with the first-order offer takes its use', 1, $uses('uses:first-order:u' . $fresh));
\mindstellar\billing\Billing::handleCallback('fake', ['fail' => $card->getId()]);
pin('declined: the order fails and the use is back', [Order::STATUS_FAILED, 0], [OrderStore::find($card->getId())->getStatus(), $uses('uses:first-order:u' . $fresh)]);
pin('so the offer shows again', ['promo.first-order'], $auto($fresh));
$card = $checkout->place($fresh, [['variant' => $w, 'qty' => 1]], $address, 'standard', 'fake')['order'];
pin('and can be placed again', 1, $uses('uses:first-order:u' . $fresh));
$admin->query("UPDATE {$p}t_billing_order SET dt_date = '" . date('Y-m-d H:i:s', time() - 8 * 86400) . "' WHERE pk_i_id = " . $card->getId());
\mindstellar\billing\Billing::releaseHeld();
pin('left unpaid past the hold: cancelled by the daily job, the use is back', [Order::STATUS_CANCELLED, 0], [OrderStore::find($card->getId())->getStatus(), $uses('uses:first-order:u' . $fresh)]);

harness_section('an order refused after the uses were taken');
$before = $uses('uses:first-order');
$busy   = static function () {
    throw new InvalidException('/lines', 'out_of_stock', 'gone');
};
osc_add_hook('cart_checkout_before', $busy, 11);
$threw = null;
try {
    $checkout->place($fresh, [['variant' => $w, 'qty' => 1]], $address, 'standard', 'fake');
} catch (InvalidException $e) {
    $threw = $e->errors()[0]['pointer'];
}
osc_remove_hook('cart_checkout_before', $busy);
pin('the placement failed', '/lines', $threw);
Plugin::ledger()->release();
pin('the use it took is given back', $before, $uses('uses:first-order'));

exit(harness_result());
