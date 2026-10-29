<?php
/*
 * This file is part of the Promotions plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * The thin hook code: the two cart filters share one evaluation, a malformed offers preference
 * switches discounts off, and the order hooks drive the counters.
 */

require __DIR__ . '/lib/bootstrap.php';

use mindstellar\billing\event\CartDiscountLines;
use mindstellar\billing\event\CartProblems;
use mindstellar\billing\event\CheckoutStarting;
use mindstellar\billing\event\OrderCancelled;
use mindstellar\billing\event\OrderPlaced;
use mindstellar\promotions\Offers;
use mindstellar\promotions\Plugin;

final class CountingStore extends ArrayStore
{
    public int $reads = 0;

    public function count(string $key): int
    {
        $this->reads++;

        return parent::count($key);
    }
}

$now     = 1760000000;
$welcome = offer('welcome10', array('label' => '10% off', 'per_user_limit' => 1));
$first   = offer('first', array('code' => null, 'label' => 'First order', 'kind' => 'fixed', 'value' => 150 * RS, 'first_order_only' => true));
$orders  = 0;
$store   = new CountingStore();
$setup   = static function (string $raw) use (&$store, &$orders, $now): void {
    $GLOBALS['prefs']['promotions']['offers'] = $raw;
    Plugin::reset();
    Plugin::$store      = $store = new CountingStore();
    Plugin::$clock      = static fn (): int => $now;
    Plugin::$orderCount = static function (int $userId) use (&$orders): int {
        return $orders;
    };
};
$setup(Offers::encode(array($welcome, $first)));

harness_section('cart filters');
$cart  = cart(1000 * RS, array('coupon' => 'WELCOME10'));
$lines = Plugin::discountLines(array(array('id' => 'other.x', 'label' => 'Other', 'amount' => -1)), $cart);
pin('another plugin\'s line is kept, ours follow', array('other.x', 'promo.welcome10', 'promo.first'), array_column($lines, 'id'));
$reads = $store->reads;
pin('no problem', array(), Plugin::problems(array(), $cart + array('discount_lines' => $lines)));
pin('cart_problems reuses the evaluation, reading no counter again', $reads, $store->reads);
pin('one counter read for the code, one for the shopper\'s first-order use', 2, $reads);
pin('not a filter value it does not know', 'x', Plugin::discountLines('x', $cart));

$orders = 1;
$cart   = cart(1000 * RS, array('coupon' => 'WELCOME10'));
Plugin::discountLines(array(), $cart);
$store->rows['uses:welcome10:u7'] = 1;
$cart['subtotal'] = 1000 * RS + 1;
pin('a changed cart is worked out again', array(array('code' => 'coupon_used', 'pointer' => '/coupon', 'message' => 'You have already used this code.')), Plugin::problems(array(), $cart));

harness_section('typed listeners');
$setup(Offers::encode(array($welcome, $first)));
$orders = 0;
$cart  = cart(1000 * RS, array('coupon' => 'WELCOME10'));
$event = new CartDiscountLines($cart);
Plugin::onDiscountLines($event);
pin('the discount event gets our lines', array('promo.welcome10', 'promo.first'), array_column($event->rawLines(), 'id'));
$event = new CartProblems(cart(1000 * RS, array('coupon' => 'NOPE')));
Plugin::onProblems($event);
pin('the problems event gets the refusal', 'coupon_invalid', $event->rawProblems()[0]['code'] ?? null);

harness_section('malformed offers');
$setup('[{"id":');
pin('no lines', array(), Plugin::discountLines(array(), cart(1000 * RS, array('coupon' => 'WELCOME10'))));
pin('no problems', array(), Plugin::problems(array(), cart(1000 * RS, array('coupon' => 'WELCOME10'))));
$setup('');
pin('no offers at all: a code is not valid', 'coupon_invalid', Plugin::problems(array(), cart(1000 * RS, array('coupon' => 'X')))[0]['code'] ?? null);

harness_section('placing and cancelling');
$setup(Offers::encode(array($welcome, $first)));
$orders = 0;
$cart   = cart(1000 * RS, array('coupon' => 'WELCOME10'));
$cart['discount_lines'] = Plugin::discountLines(array(), $cart);
$checkout = static function (array $cart): array {
    $event = new CheckoutStarting($cart);
    Plugin::onCheckoutStarting($event);

    return $event->refusals();
};
$checkout($cart);
pin('the uses are taken before the order is written', array(1, 1, 1, 1), array($store->count('uses:welcome10'), $store->count('uses:welcome10:u7'), $store->count('uses:first'), $store->count('uses:first:u7')));
$GLOBALS['order_lines'][77] = array(
    array('id' => 1, 'kind' => 'item', 'meta' => array()),
    array('id' => 2, 'kind' => 'discount', 'meta' => array('id' => 'promo.welcome10', 'offer' => 'welcome10', 'code' => 'WELCOME10')),
    array('id' => 3, 'kind' => 'discount', 'meta' => array('id' => 'promo.first', 'offer' => 'first', 'code' => null)),
);
Plugin::onOrderPlaced(new OrderPlaced(77, 7));
pin('placed: kept, not taken twice', array(1, 1), array($store->count('uses:welcome10'), $store->count('uses:first')));
$threw = $checkout($cart);
pin('a second order racing the first is refused at /coupon', array(array('pointer' => '/coupon', 'code' => 'coupon_used', 'message' => 'An offer in your cart has just run out. Check the new total and try again.')), $threw);
pin('and takes nothing', array(1, 1), array($store->count('uses:welcome10:u7'), $store->count('uses:first:u7')));
Plugin::onOrderEnded(new OrderCancelled(77, 7));
pin('cancelling gives the uses back', array(0, 0, 0, 0), array($store->count('uses:welcome10'), $store->count('uses:welcome10:u7'), $store->count('uses:first'), $store->count('uses:first:u7')));
Plugin::onOrderEnded(new OrderCancelled(77, 7));
pin('once', 0, $store->count('uses:welcome10'));
$hooks = (string) file_get_contents(dirname(__DIR__) . '/index.php');
check('a failed order gives the uses back too', str_contains($hooks, "array('billing_order_cancelled', 'billing_order_failed')"));
check('and a refunded one', str_contains($hooks, "osc_add_hook('billing_order_refunded', array(Plugin::class, 'orderCancelled')"));

$checkout(cart(10 * RS));
pin('a cart with no offers takes nothing', array('uses:welcome10' => 0, 'uses:welcome10:u7' => 0, 'uses:first' => 0, 'uses:first:u7' => 0), array_filter($store->rows, 'is_int'));

harness_section('the first-order check follows the counter');
$setup(Offers::encode(array($first)));
$orders = 0;
$store->rows['uses:first:u7'] = 1;
pin('a held first-order use leaves the offer out of the quote, as placing would refuse it', array(), Plugin::discountLines(array(), cart(1000 * RS)));

harness_section('banner');
$GLOBALS['prefs']['promotions']['first_order_banner'] = 'Get 150 off your first order';
pin('shown while a first-order offer is on', 'Get 150 off your first order', Plugin::banner());
$setup(Offers::encode(array($welcome, offer('first', array('code' => null, 'first_order_only' => true, 'ends' => $now)))));
pin('hidden once it has ended', '', Plugin::banner());
$setup(Offers::encode(array($welcome)));
pin('hidden with no first-order offer', '', Plugin::banner());

exit(harness_result());
