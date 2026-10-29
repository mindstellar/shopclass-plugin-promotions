<?php
/*
 * This file is part of the Promotions plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * The offer editor's post read on the server: codes normalised to core's rule, amounts, limits
 * and dates checked, ids kept on edit.
 */

require __DIR__ . '/lib/bootstrap.php';

use mindstellar\promotions\Admin\OfferForm;
use mindstellar\promotions\Offer;
use mindstellar\promotions\Plugin;

$amount = array(Plugin::class, 'amount');
$post   = static fn (array $over = array()): array => $over + array(
    'code' => 'welcome10', 'label' => '', 'kind' => 'percent', 'value' => '10', 'min_subtotal' => '', 'max_discount' => '500',
    'starts' => '', 'ends' => '', 'uses_limit' => '', 'per_user_limit' => '1', 'first_order_only' => '', 'on' => '1',
);
$read = static fn (array $p, array $offers = array(), ?Offer $editing = null): array => OfferForm::read($p, $amount, $offers, $editing, 'INR');

harness_section('a new code');
$r = $read($post(array('code' => ' welcome 10 ')));
pin('no errors', array(), $r['errors']);
$o = $r['offer'];
pin('the code is upper case with no spaces', 'WELCOME10', $o->code);
pin('the id comes from the code', 'welcome10', $o->id);
pin('10% is 1000 basis points', 1000, $o->value);
pin('the cap is in micros', 500 * RS, $o->maxDiscount);
pin('an empty name says what it takes off', '10% off', $o->label);
pin('the site currency is kept', 'INR', $o->currency);
pin('limits', array(0, 1, false, true), array($o->usesLimit, $o->perUserLimit, $o->firstOrderOnly, $o->on));
check('what it saves reads back as stored', Offer::fromArray($o->toArray()) !== null);
pin('12.5%', 1250, $read($post(array('value' => '12,5')))['offer']->value);

harness_section('automatic and fixed');
$r = $read($post(array('code' => '', 'kind' => 'fixed', 'value' => '150', 'min_subtotal' => '999', 'max_discount' => '', 'per_user_limit' => '', 'first_order_only' => '1')));
pin('no code is automatic', null, $r['offer']->code);
pin('fixed amount and minimum in micros', array(150 * RS, 999 * RS, 0), array($r['offer']->value, $r['offer']->minSubtotal, $r['offer']->maxDiscount));
pin('its name', '150 INR off', $r['offer']->label);
pin('the id comes from the name', 'offer', $r['offer']->id);
pin('free delivery needs no amount', array(array(), 0, 'Free delivery'), (static fn ($r) => array($r['errors'], $r['offer']->value, $r['offer']->label))($read($post(array('kind' => 'free_shipping', 'value' => 'x')))));

harness_section('refused');
foreach (array(
    'a code with other characters' => array('code' => 'save_5!'),
    'a percent over 100'           => array('value' => '101'),
    'a zero percent'               => array('value' => '0'),
    'a percent that is not one'    => array('value' => 'ten'),
    'a fixed amount of nothing'    => array('kind' => 'fixed', 'value' => '0'),
    'an unknown kind'              => array('kind' => 'bogo'),
    'a cap that is not an amount'  => array('max_discount' => '-5'),
    'a limit that is not a number' => array('uses_limit' => '1.5'),
    'a date that is not one'       => array('ends' => '31/10/2026'),
    'an end before the start'      => array('starts' => '2026-11-01', 'ends' => '2026-10-31'),
    'a name too long'              => array('label' => str_repeat('n', Offer::MAX_LABEL + 1)),
) as $case => $over) {
    $r = $read($post($over));
    check($case, $r['offer'] === null && $r['errors'] !== array(), json_encode($r['errors']));
}
$taken = $read($post())['offer'];
pin('a code another offer holds', array('Another offer already uses the code WELCOME10.'), $read($post(array('code' => 'Welcome10')), array($taken))['errors']);
pin('but the offer itself may keep it', array(), $read($post(), array($taken), $taken)['errors']);
pin('a new offer past the limit', 1, count($read($post(array('code' => 'NEW')), array_fill(0, 200, $taken))['errors']));

harness_section('dates');
$r = $read($post(array('starts' => '2026-11-01 09:00', 'ends' => '2026-11-30')));
pin('a start at its minute', strtotime('2026-11-01 09:00:00 UTC'), $r['offer']->starts);
pin('an end day runs to its end', strtotime('2026-12-01 00:00:00 UTC'), $r['offer']->ends);
pin('a browser date-time is read too', strtotime('2026-11-01 09:00:00 UTC'), $read($post(array('starts' => '2026-11-01T09:00')))['offer']->starts);
pin('shown back as typed', '2026-11-01 09:00', OfferForm::show($r['offer']->starts));

harness_section('editing');
$edited = $read($post(array('code' => 'HELLO', 'value' => '20')), array($taken), $taken)['offer'];
pin('keeps its id, so its uses carry on', array('welcome10', 'HELLO', 2000), array($edited->id, $edited->code, $edited->value));
$taken->round = 3;
pin('and its reset round', 3, $read($post(), array($taken), $taken)['offer']->round);

exit(harness_result());
