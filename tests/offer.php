<?php
/*
 * This file is part of the Promotions plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Offer::check() refusals in their order, the discount maths and caps, and date boundaries.
 */

require __DIR__ . '/lib/bootstrap.php';

use mindstellar\promotions\Offer;

$now = 1760000000;

harness_section('each refusal, in order');
$all = offer('welcome10', array(
    'min_subtotal' => 500 * RS, 'uses_limit' => 10, 'per_user_limit' => 1, 'first_order_only' => true,
    'starts' => $now - 100, 'ends' => $now + 100,
));
$used = array($all->usesKey() => 10, $all->userKey(7) => 1);
pin('a good cart passes', null, $all->check(cart(600 * RS), $now, usage()));
pin('off reads as not valid', Offer::INVALID, offer('a', array('on' => false))->check(cart(600 * RS), $now, usage()));
pin('another currency reads as not valid', Offer::INVALID, offer('a', array('currency' => 'USD'))->check(cart(600 * RS), $now, usage()));
pin('before it starts: not started', Offer::NOT_STARTED, $all->check(cart(600 * RS), $now - 101, usage()));
pin('after it ends: expired', Offer::EXPIRED, $all->check(cart(600 * RS), $now + 100, usage()));
pin('dates come before the minimum', Offer::EXPIRED, $all->check(cart(1 * RS), $now + 100, usage()));
pin('under the minimum', Offer::MIN, $all->check(cart(499 * RS), $now, usage()));
pin('exactly the minimum passes', null, $all->check(cart(500 * RS), $now, usage()));
pin('the minimum comes before uses', Offer::MIN, $all->check(cart(499 * RS), $now, usage($used)));
pin('no uses left', Offer::USED, $all->check(cart(600 * RS), $now, usage(array($all->usesKey() => 10))));
pin('uses left come before signing in', Offer::USED, $all->check(cart(600 * RS, array('user_id' => 0)), $now, usage(array($all->usesKey() => 10))));
pin('a guest is asked to sign in', Offer::SIGN_IN, $all->check(cart(600 * RS, array('user_id' => 0)), $now, usage()));
pin('this shopper used it', Offer::USED, $all->check(cart(600 * RS), $now, usage(array($all->userKey(7) => 1))));
pin('another shopper\'s use does not count', null, $all->check(cart(600 * RS), $now, usage(array($all->userKey(8) => 1))));
pin('not a first order', Offer::FIRST_ORDER, $all->check(cart(600 * RS), $now, usage(array(), 1)));
pin('a code with no per-shopper rule works for a guest', null, offer('a')->check(cart(600 * RS, array('user_id' => 0)), $now, usage()));
pin('a first-order offer needs a signed-in shopper', Offer::SIGN_IN, offer('a', array('first_order_only' => true))->check(cart(600 * RS, array('user_id' => 0)), $now, usage()));

harness_section('date boundaries');
$dated = offer('d', array('starts' => $now, 'ends' => $now + 60));
pin('live from its first second', null, $dated->check(cart(RS), $now, usage()));
pin('live in its last second', null, $dated->check(cart(RS), $now + 59, usage()));
pin('ended at its end second', Offer::EXPIRED, $dated->check(cart(RS), $now + 60, usage()));
check('live() agrees', $dated->live($now) && $dated->live($now + 59) && !$dated->live($now + 60) && !$dated->live($now - 1));

harness_section('maths');
pin('10% of 499.00', 49900000, offer('p')->amount(cart(499 * RS)));
pin('10% rounds half up to the micro', 5, offer('p')->amount(cart(45)));
pin('12.5% of 0.000001 rounds to 0', 0, Offer::percentOf(1, 1250));
pin('percent is exact on a huge cart', 1000000000000000000 / 10, Offer::percentOf(1000000000000000000, 1000));
pin('100%', 499 * RS, offer('p', array('value' => 10000))->amount(cart(499 * RS)));
pin('percent capped at max_discount', 50 * RS, offer('p', array('max_discount' => 50 * RS))->amount(cart(999 * RS)));
pin('fixed', 150 * RS, offer('f', array('kind' => 'fixed', 'value' => 150 * RS))->amount(cart(999 * RS)));
pin('fixed never more than the items', 99 * RS, offer('f', array('kind' => 'fixed', 'value' => 150 * RS))->amount(cart(99 * RS)));
pin('fixed capped at max_discount', 100 * RS, offer('f', array('kind' => 'fixed', 'value' => 150 * RS, 'max_discount' => 100 * RS))->amount(cart(999 * RS)));
$ship = cart(999 * RS, array('shipping_lines' => array(array('amount' => 49 * RS), array('amount' => 10 * RS), array('amount' => -5), array('amount' => '9'))));
pin('free shipping takes the shipping lines', 59 * RS, offer('s', array('kind' => 'free_shipping', 'value' => 0))->amount($ship));
pin('free shipping capped', 40 * RS, offer('s', array('kind' => 'free_shipping', 'value' => 0, 'max_discount' => 40 * RS))->amount($ship));
pin('free shipping with no shipping takes nothing', 0, offer('s', array('kind' => 'free_shipping'))->amount(cart(999 * RS)));
pin('a negative subtotal takes nothing', 0, offer('p')->amount(cart(-5)));

harness_section('counters');
pin('keys before a reset', array('uses:w', 'uses:w:u7'), array(offer('w')->usesKey(), offer('w')->userKey(7)));
pin('keys after a reset', array('uses:w.r2', 'uses:w.r2:u7'), array(offer('w', array('round' => 2))->usesKey(), offer('w', array('round' => 2))->userKey(7)));
pin('a first-order offer is once per shopper', 1, offer('w', array('first_order_only' => true, 'per_user_limit' => 3))->userLimit());

harness_section('codes');
pin('lowercase and spaces become the core rule', 'WELCOME10', Offer::normaliseCode(' welcome 10 '));
pin('dashes are kept', 'DIWALI-25', Offer::normaliseCode('diwali-25'));
pin('other characters are refused', null, Offer::normaliseCode('save_5!'));
pin('41 characters are refused', null, Offer::normaliseCode(str_repeat('A', 41)));
pin('a stored code off the rule is refused', null, Offer::fromArray(array('id' => 'a', 'code' => 'welcome', 'label' => 'x', 'kind' => 'percent', 'value' => 10)));

harness_section('stored shape');
foreach (array(
    'a percent over 100%'      => array('value' => 10001),
    'a zero percent'           => array('value' => 0),
    'a float value'            => array('value' => 10.5),
    'a string value'           => array('value' => '1000'),
    'an unknown kind'          => array('kind' => 'bogo'),
    'a bad id'                 => array('id' => 'Bad Id'),
    'an empty label'           => array('label' => '  '),
    'a negative limit'         => array('uses_limit' => -1),
    'an end before the start'  => array('starts' => 100, 'ends' => 100),
    'a string for on'          => array('on' => '1'),
    'a bad currency'           => array('currency' => 'inr'),
) as $label => $bad) {
    pin($label . ' is refused', null, Offer::fromArray($bad + array('id' => 'a', 'code' => 'A', 'label' => 'x', 'kind' => 'percent', 'value' => 1000)));
}
$row = offer('a', array('kind' => 'free_shipping', 'value' => 999))->toArray();
pin('free shipping stores no value', 0, $row['value']);
pin('the stored shape reads back the same', $row, Offer::fromArray($row)->toArray());

exit(harness_result());
