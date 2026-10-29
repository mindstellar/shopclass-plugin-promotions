<?php
/*
 * This file is part of the Promotions plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Ledger: a use is taken before the order is written, kept on placement, given back once on
 * cancel, and given back when the order never got written.
 */

require __DIR__ . '/lib/bootstrap.php';

use mindstellar\promotions\Ledger;

$welcome = offer('welcome10', array('uses_limit' => 2, 'per_user_limit' => 1));
$auto    = offer('auto', array('code' => null));

harness_section('reserve, place, cancel');
$store  = new ArrayStore();
$ledger = new Ledger($store);
check('a use is taken', $ledger->reserve(array($welcome, $auto), 7));
pin('from every counter', array('uses:welcome10' => 1, 'uses:welcome10:u7' => 1, 'uses:auto' => 1, 'uses:auto:u7' => 1), $store->rows);
$ledger->placed(41, 7, array('auto', 'welcome10'), array($welcome, $auto));
pin('the order remembers what it took', array('uses:welcome10', 'uses:welcome10:u7', 'uses:auto', 'uses:auto:u7'), $store->rows['order:41']);
$ledger->release();
pin('a placed order\'s uses are not given back at the end of the request', 1, $store->count('uses:welcome10'));
$ledger->cancelled(41);
pin('cancelling gives them back', array(0, 0, 0, 0), array($store->count('uses:welcome10'), $store->count('uses:welcome10:u7'), $store->count('uses:auto'), $store->count('uses:auto:u7')));
pin('and forgets the order', false, isset($store->rows['order:41']));
$ledger->reserve(array($welcome), 8);
$ledger->placed(42, 8, array('welcome10'), array($welcome));
$ledger->cancelled(41);
pin('a second cancel gives back nothing more, not even another order\'s use', 1, $store->count('uses:welcome10'));

harness_section('limits under a race');
$store  = new ArrayStore();
$ledger = new Ledger($store);
check('the shopper\'s one use', $ledger->reserve(array($welcome), 7));
$ledger->placed(1, 7, array('welcome10'), array($welcome));
check('a second order by the same shopper at once is refused', !$ledger->reserve(array($welcome), 7));
pin('and takes nothing', array(1, 1), array($store->count('uses:welcome10'), $store->count('uses:welcome10:u7')));
check('another shopper takes the last use', $ledger->reserve(array($welcome), 8));
$ledger->placed(2, 8, array('welcome10'), array($welcome));
check('a third shopper finds none left', !$ledger->reserve(array($welcome), 9));
pin('the total stays at its limit', 2, $store->count('uses:welcome10'));
pin('the third shopper\'s counter is untouched', 0, $store->count('uses:welcome10:u9'));
$first = offer('first', array('code' => null, 'first_order_only' => true));
check('a first-order offer once', (new Ledger($store))->reserve(array($first), 5));
check('not twice at once, even before the first order is placed', !(new Ledger($store))->reserve(array($first), 5));
pin('one use held', 1, $store->count('uses:first:u5'));

harness_section('an order that was never written');
$store  = new ArrayStore();
$ledger = new Ledger($store);
$ledger->reserve(array($welcome), 7);
$ledger->release();
pin('its uses are given back', array(0, 0), array($store->count('uses:welcome10'), $store->count('uses:welcome10:u7')));
$ledger->reserve(array($welcome), 7);
$ledger->reserve(array($welcome), 7);
pin('a reservation left by a failed placement is given back by the next one', array(1, 1), array($store->count('uses:welcome10'), $store->count('uses:welcome10:u7')));
$ledger->cancelled(99);
pin('cancelling an order that took nothing changes nothing', 1, $store->count('uses:welcome10'));

harness_section('placed with no reservation');
$store  = new ArrayStore();
$ledger = new Ledger($store);
$ledger->placed(5, 7, array('welcome10', 'gone'), array($welcome));
pin('the offers on the order are counted then', array(1, 1), array($store->count('uses:welcome10'), $store->count('uses:welcome10:u7')));
pin('an offer deleted since is skipped', array('uses:welcome10', 'uses:welcome10:u7'), $store->rows['order:5']);
$ledger->reserve(array($welcome), 8);
$ledger->placed(6, 8, array(), array($welcome));
pin('a reservation that does not match the order is given back', 1, $store->count('uses:welcome10'));
pin('an order with no offers remembers nothing', false, isset($store->rows['order:6']));

exit(harness_result());
