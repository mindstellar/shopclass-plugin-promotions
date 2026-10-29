<?php

/*
 * This file is part of the Promotions plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use mindstellar\currency\Money;
use mindstellar\promotions\Admin\OfferForm;
use mindstellar\promotions\Admin\Screens;
use mindstellar\promotions\Offer;
use mindstellar\promotions\Offers;
use mindstellar\promotions\Plugin;

if (!defined('ABS_PATH')) {
    exit('Direct access is not allowed.');
}
if (!Screens::allowed()) {
    osc_admin_empty(array('icon' => 'bi-shield-lock', 'title' => __('Only an administrator can manage offers.', 'promotions')));

    return;
}

$id    = Params::getParamString('offer');
$known = $id === '' ? null : Offers::find(Plugin::offers() ?? array(), $id);
if ($known === null) {
    $id = '';
}
$money = static fn (int $micros): string => $micros === 0 ? '' : Money::fromMicros($micros);
$count = static fn (int $n): string => $n === 0 ? '' : (string) $n;
$value = Screens::$retry['post'] ?? ($known === null ? array('kind' => Offer::PERCENT, 'on' => '1') : array(
    'code'             => (string) $known->code,
    'label'            => $known->label,
    'kind'             => $known->kind,
    'value'            => $known->kind === Offer::PERCENT ? rtrim(rtrim(number_format($known->value / 100, 2, '.', ''), '0'), '.') : $money($known->value),
    'min_subtotal'     => $money($known->minSubtotal),
    'max_discount'     => $money($known->maxDiscount),
    'starts'           => OfferForm::show($known->starts),
    'ends'             => OfferForm::show($known->ends),
    'uses_limit'       => $count($known->usesLimit),
    'per_user_limit'   => $count($known->perUserLimit),
    'first_order_only' => $known->firstOrderOnly ? '1' : '',
    'on'               => $known->on ? '1' : '',
));
$v        = static fn (string $key): string => (string) ($value[$key] ?? '');
$currency = strtoupper((string) osc_currency());

osc_admin_page_head($id === '' ? __('Add offer', 'promotions') : __('Edit offer', 'promotions'), array(
    array('label' => __('Back to offers', 'promotions'), 'url' => osc_route_admin_url(Screens::ROUTE), 'variant' => 'dim'),
));
osc_admin_form_open(array(
    'url'    => osc_route_admin_url(Screens::EDIT_ROUTE),
    'fields' => array('pr_do' => 'save', 'offer' => $id),
));
osc_admin_field(array(
    'type' => 'text', 'name' => 'code', 'label' => __('Code', 'promotions'), 'value' => $v('code'), 'width' => 'key',
    'attrs' => array('maxlength' => 40, 'autocomplete' => 'off', 'spellcheck' => 'false', 'placeholder' => 'WELCOME10'),
    'help' => __('What the shopper types at checkout: letters, digits and dashes. Capitals and spaces do not matter. Leave empty for an offer that applies by itself.', 'promotions'),
));
osc_admin_field(array(
    'type' => 'text', 'name' => 'label', 'label' => __('Name at checkout', 'promotions'), 'value' => $v('label'),
    'attrs' => array('maxlength' => Offer::MAX_LABEL, 'placeholder' => __('10% off', 'promotions')),
    'help' => __('Shown on the discount line, after the code. Leave empty to say what it takes off.', 'promotions'),
));
osc_admin_field(array('type' => 'checkbox', 'name' => 'on', 'label' => __('Offer is on', 'promotions'), 'checked' => $v('on') === '1', 'value' => '1'));

osc_admin_form_section(__('Discount', 'promotions'));
osc_admin_field(array(
    'type' => 'radio', 'name' => 'kind', 'label' => __('Takes off', 'promotions'), 'value' => $v('kind'),
    'options' => array(
        Offer::PERCENT       => __('A percentage of the items', 'promotions'),
        Offer::FIXED         => __('A fixed amount', 'promotions'),
        Offer::FREE_SHIPPING => __('The delivery charge', 'promotions'),
    ),
));
osc_admin_field(array(
    'type' => 'text', 'name' => 'value', 'label' => __('How much', 'promotions'), 'value' => $v('value'), 'width' => 'num',
    'help' => sprintf(__('For a percentage, a number up to 100, such as 10. For a fixed amount, in %s. Not used for delivery.', 'promotions'), $currency),
));
osc_admin_field(array(
    'type' => 'text', 'name' => 'max_discount', 'label' => __('Most it takes off', 'promotions'), 'value' => $v('max_discount'), 'suffix' => $currency, 'width' => 'num',
    'help' => __('Leave empty for no cap.', 'promotions'),
));

osc_admin_form_section(__('Rules', 'promotions'));
osc_admin_field(array(
    'type' => 'text', 'name' => 'min_subtotal', 'label' => __('Minimum order', 'promotions'), 'value' => $v('min_subtotal'), 'suffix' => $currency, 'width' => 'num',
    'help' => __('The items must add up to this much. Leave empty for any order.', 'promotions'),
));
osc_admin_field(array(
    'type' => 'text', 'name' => 'starts', 'label' => __('Starts', 'promotions'), 'value' => $v('starts'), 'width' => 'key',
    'attrs' => array('placeholder' => '2026-11-01 09:00'),
    'help' => __('Leave empty to start now.', 'promotions'),
));
osc_admin_field(array(
    'type' => 'text', 'name' => 'ends', 'label' => __('Ends', 'promotions'), 'value' => $v('ends'), 'width' => 'key',
    'attrs' => array('placeholder' => '2026-11-30'),
    'help' => __('A day alone runs to the end of that day. Leave empty for no end.', 'promotions'),
));
osc_admin_field(array(
    'type' => 'number', 'name' => 'uses_limit', 'label' => __('Uses in all', 'promotions'), 'value' => $v('uses_limit'),
    'attrs' => array('min' => 0), 'help' => __('Leave empty for no limit.', 'promotions'),
));
osc_admin_field(array(
    'type' => 'number', 'name' => 'per_user_limit', 'label' => __('Uses per shopper', 'promotions'), 'value' => $v('per_user_limit'),
    'attrs' => array('min' => 0), 'help' => __('A limit here means the shopper must be signed in. Leave empty for no limit.', 'promotions'),
));
osc_admin_field(array(
    'type' => 'checkbox', 'name' => 'first_order_only', 'label' => __('Only for a shopper\'s first order', 'promotions'),
    'checked' => $v('first_order_only') === '1', 'value' => '1',
    'help' => __('A first order is one placed by a signed-in shopper with no other order waiting, paid, shipped or delivered.', 'promotions'),
));
osc_admin_form_close(array(
    array('type' => 'submit', 'label' => __('Save offer', 'promotions')),
));
