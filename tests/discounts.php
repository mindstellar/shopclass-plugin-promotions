<?php
/*
 * This file is part of the Promotions plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Discounts::evaluate(): which lines a cart gets, the stacking rule, the cart-wide cap and the
 * refusal text for each code.
 */

require __DIR__ . '/lib/bootstrap.php';

use mindstellar\promotions\Discounts;
use mindstellar\promotions\Offer;
use mindstellar\promotions\Offers;

$now  = 1760000000;
$eval = static fn (array $offers, array $cart, ?mindstellar\promotions\Usage $u = null): array => Discounts::evaluate($offers, $cart, $now, $u ?? usage(), money());

$welcome = offer('welcome10', array('label' => '10% off', 'max_discount' => 500 * RS, 'per_user_limit' => 1));
$first   = offer('first-order', array('code' => null, 'label' => 'First order', 'kind' => 'fixed', 'value' => 150 * RS, 'min_subtotal' => 999 * RS, 'first_order_only' => true));
$ship    = offer('freeship', array('code' => null, 'label' => 'Free delivery', 'kind' => 'free_shipping', 'value' => 0));
$save5   = offer('save5', array('label' => '5% off', 'value' => 500));

harness_section('a code');
$r = $eval(array($welcome), cart(499 * RS, array('coupon' => 'WELCOME10')));
pin('one line, labelled with the code first', array(array('id' => 'promo.welcome10', 'label' => 'WELCOME10 · 10% off', 'amount' => -49900000, 'currency' => 'INR', 'meta' => array('offer' => 'welcome10', 'code' => 'WELCOME10'))), $r['lines']);
pin('no problem', null, $r['problem']);
pin('no code, no line and no problem', array(array(), null), array_values($eval(array($welcome), cart(499 * RS))));
pin('an unknown code is not valid', array('code' => 'coupon_invalid', 'pointer' => '/coupon', 'message' => 'This code is not valid.'), $eval(array($welcome), cart(499 * RS, array('coupon' => 'WELCOME99')))['problem']);
pin('a code with no offers at all is not valid', 'coupon_invalid', $eval(array(), cart(499 * RS, array('coupon' => 'X')))['problem']['code']);
pin('an automatic offer cannot be typed as a code', 'coupon_invalid', $eval(array($first), cart(2000 * RS, array('coupon' => 'FIRST-ORDER')))['problem']['code']);

$atMin = $eval(array(offer('c', array('min_subtotal' => 999 * RS))), cart(999 * RS, array('coupon' => 'C')));
pin('a cart exactly at the minimum gets the coupon', array(1, null), array(count($atMin['lines']), $atMin['problem']));

harness_section('refusal text');
$messages = array();
foreach (array(
    'expired'     => array(offer('c', array('ends' => $now)), cart(RS, array('coupon' => 'C')), usage()),
    'min'         => array(offer('c', array('min_subtotal' => 999 * RS)), cart(RS, array('coupon' => 'C')), usage()),
    'run out'     => array(offer('c', array('uses_limit' => 2)), cart(RS, array('coupon' => 'C')), usage(array('uses:c' => 2))),
    'used by you' => array(offer('c', array('per_user_limit' => 1)), cart(RS, array('coupon' => 'C')), usage(array('uses:c:u7' => 1))),
    'sign in'     => array(offer('c', array('per_user_limit' => 1)), cart(RS, array('coupon' => 'C', 'user_id' => 0)), usage()),
    'first order' => array(offer('c', array('first_order_only' => true)), cart(RS, array('coupon' => 'C')), usage(array(), 2)),
) as $case => [$o, $c, $u]) {
    $p                = $eval(array($o), $c, $u)['problem'];
    $messages[$case]  = $p['code'] . ': ' . $p['message'];
    check($case . ' adds no line', $eval(array($o), $c, $u)['lines'] === array());
}
pin('each refusal says why', array(
    'expired'     => 'coupon_expired: This code has expired.',
    'min'         => 'coupon_min: This code needs items worth at least 999.00 INR.',
    'run out'     => 'coupon_used: This code has run out.',
    'used by you' => 'coupon_used: You have already used this code.',
    'sign in'     => 'coupon_sign_in: Sign in to use this code.',
    'first order' => 'coupon_first_order: This code is for a first order only.',
), $messages);

harness_section('stacking');
$c = cart(2000 * RS, array('coupon' => 'WELCOME10', 'shipping_lines' => array(array('amount' => 49 * RS))));
$r = $eval(array($first, $welcome, $ship, $save5), $c);
pin('automatic offers stack with each other and with one code', array('promo.first-order', 'promo.welcome10', 'promo.freeship'), array_column($r['lines'], 'id'));
pin('at their amounts', array(-150 * RS, -200 * RS, -49 * RS), array_column($r['lines'], 'amount'));
pin('only the free delivery line is marked as coming off the shipping', array(null, null, 'shipping'), array_map(static fn ($l) => $l['meta']['applies_to'] ?? null, $r['lines']));
pin('the other code does not join in', false, in_array('promo.save5', array_column($r['lines'], 'id'), true));
pin('a second code replaces the first', array('promo.first-order', 'promo.freeship', 'promo.save5'), array_column($eval(array($first, $welcome, $ship, $save5), array('coupon' => 'SAVE5') + $c)['lines'], 'id'));
pin('an automatic offer that does not apply is left out silently', array(array('promo.welcome10'), null), (static function ($r) {
    return array(array_column($r['lines'], 'id'), $r['problem']);
})($eval(array($first, $welcome), cart(500 * RS, array('coupon' => 'WELCOME10')))));
pin('a used first-order offer is left out silently too', array(), $eval(array($first), cart(2000 * RS), usage(array(), 1))['lines']);

harness_section('never more than the cart');
$big   = offer('big', array('code' => null, 'label' => 'Big', 'kind' => 'fixed', 'value' => 900 * RS));
$half  = offer('half', array('label' => 'Half', 'value' => 5000));
$r     = $eval(array($big, $half, $ship), cart(1000 * RS, array('coupon' => 'HALF', 'shipping_lines' => array(array('amount' => 49 * RS)))));
pin('the lines stop at items plus shipping', array(-900 * RS, -149 * RS), array_column($r['lines'], 'amount'));
pin('an automatic offer with nothing left to take adds no line', array('promo.big', 'promo.half'), array_column($r['lines'], 'id'));
$free = offer('ship', array('kind' => 'free_shipping', 'value' => 0, 'label' => 'Free delivery'));
pin('a code that takes nothing still shows as applied', array(array('promo.ship', 0)), array_map(static fn ($l) => array($l['id'], $l['amount']), $eval(array($free), cart(100 * RS, array('coupon' => 'SHIP')))['lines']));

harness_section('order lines');
pin('offer ids from a cart\'s lines', array('welcome10'), Discounts::offerIds(array(array('id' => 'promo.welcome10', 'meta' => array('offer' => 'welcome10')), array('id' => 'other.x', 'meta' => array('offer' => 'x')))));
pin('and from an order\'s stored lines', array('welcome10'), Discounts::offerIds(array(
    array('id' => 12, 'kind' => 'item', 'meta' => array()),
    array('id' => 13, 'kind' => 'discount', 'meta' => array('id' => 'promo.welcome10', 'offer' => 'welcome10', 'code' => 'WELCOME10')),
    array('id' => 14, 'kind' => 'discount', 'meta' => array('id' => 'other.welcome10', 'offer' => 'welcome10')),
    array('id' => 15, 'kind' => 'tax', 'meta' => array('id' => 'promo.first', 'offer' => 'first')),
)));

harness_section('malformed offers');
foreach (array('not json' => '{oops', 'an object' => '{"a":1}', 'a string' => '"x"', 'too many' => json_encode(array_fill(0, Offers::MAX + 1, $welcome->toArray()))) as $case => $raw) {
    pin($case . ' reads as switched off', null, Offers::parse($raw));
}
pin('empty is no offers', array(), Offers::parse(''));
$mixed = Offers::parse(json_encode(array($welcome->toArray(), array('id' => 'bad'), $welcome->toArray() + array(), array('id' => 'dup') + $welcome->toArray(), $first->toArray())));
pin('a bad entry, a repeated id and a repeated code are left out', array('welcome10', 'first-order'), array_map(static fn (Offer $o): string => $o->id, $mixed));
pin('the list round-trips', Offers::encode($mixed), Offers::encode(Offers::parse(Offers::encode($mixed))));

exit(harness_result());
