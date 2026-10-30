<?php

/*
 * This file is part of the Promotions plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use mindstellar\promotions\Admin\OfferForm;
use mindstellar\promotions\Admin\Screens;
use mindstellar\promotions\Offer;
use mindstellar\promotions\Plugin;

if (!defined('ABS_PATH')) {
    exit('Direct access is not allowed.');
}
if (!Screens::allowed()) {
    osc_admin_empty(array('icon' => 'bi-shield-lock', 'title' => __('Only an administrator can manage offers.', 'promotions')));

    return;
}

$offers  = Plugin::offers();
$listUrl = osc_route_admin_url(Screens::ROUTE);
$store   = Plugin::store();
$now     = Plugin::now();

osc_admin_page_head(__('Offers and coupons', 'promotions'));

if ($offers === null) {
    osc_admin_empty(array(
        'icon'  => 'bi-exclamation-triangle',
        'title' => __('The stored offers could not be read', 'promotions'),
        'text'  => __('No discount applies until they are fixed. Uninstalling the plugin clears them.', 'promotions'),
    ));

    return;
}

$discount = static function (Offer $o): string {
    switch ($o->kind) {
        case Offer::PERCENT:
            $text = rtrim(rtrim(number_format($o->value / 100, 2, '.', ''), '0'), '.') . '%';
            break;
        case Offer::FIXED:
            $text = Plugin::money($o->value, $o->currency);
            break;
        default:
            $text = __('Free delivery', 'promotions');
    }
    if ($o->maxDiscount > 0) {
        $text .= ' · ' . sprintf(__('at most %s', 'promotions'), Plugin::money($o->maxDiscount, $o->currency));
    }

    return $text;
};
$rules = static function (Offer $o): string {
    $out = array();
    if ($o->minSubtotal > 0) {
        $out[] = sprintf(__('Orders over %s', 'promotions'), Plugin::money($o->minSubtotal, $o->currency));
    }
    if ($o->firstOrderOnly) {
        $out[] = __('First order only', 'promotions');
    }
    if ($o->perUserLimit > 0) {
        $out[] = sprintf(__('%d per shopper', 'promotions'), $o->perUserLimit);
    }
    if ($o->starts > 0) {
        $out[] = sprintf(__('From %s', 'promotions'), OfferForm::show($o->starts));
    }
    if ($o->ends > 0) {
        $out[] = sprintf(__('Until %s', 'promotions'), OfferForm::showEnd($o->ends));
    }

    return $out === array() ? '—' : implode(' · ', $out);
};
?>
<div class="table-contains-actions osc-table-stack">
    <table class="table">
        <thead>
        <tr>
            <th><?php _e('Offer', 'promotions'); ?></th>
            <th><?php _e('Takes off', 'promotions'); ?></th>
            <th><?php _e('Rules', 'promotions'); ?></th>
            <th><?php _e('Uses', 'promotions'); ?></th>
            <th><?php _e('Status', 'promotions'); ?></th>
        </tr>
        </thead>
        <tbody>
        <?php if ($offers === array()) {
            osc_admin_table_empty(5, array(
                'icon'  => 'bi-ticket-perforated',
                'title' => __('No offers yet', 'promotions'),
                'text'  => __('Add a coupon code, or an automatic discount such as one for a first order.', 'promotions'),
            ));
        }
        foreach ($offers as $offer) {
            $uses = $store->count($offer->usesKey()); ?>
            <tr>
                <td data-col-name="<?php echo osc_esc_html(__('Offer', 'promotions')); ?>">
                    <strong><?php echo osc_esc_html($offer->code ?? __('Automatic', 'promotions')); ?></strong>
                    <small class="text-muted"><?php echo osc_esc_html($offer->label); ?></small>
                    <div class="actions"><ul>
                        <li><a href="<?php echo osc_esc_html(osc_route_admin_url(Screens::EDIT_ROUTE, array('offer' => $offer->id))); ?>"><?php _e('Edit', 'promotions'); ?></a></li>
                        <li><a href="#" onclick="document.getElementById('pr-reset-<?php echo osc_esc_html($offer->id); ?>').showModal(); return false;"><?php _e('Reset uses', 'promotions'); ?></a></li>
                        <li><a href="#" onclick="document.getElementById('pr-delete-<?php echo osc_esc_html($offer->id); ?>').showModal(); return false;"><?php _e('Delete', 'promotions'); ?></a></li>
                    </ul></div></td>
                <td data-col-name="<?php echo osc_esc_html(__('Takes off', 'promotions')); ?>"><?php echo osc_esc_html($discount($offer)); ?></td>
                <td data-col-name="<?php echo osc_esc_html(__('Rules', 'promotions')); ?>"><?php echo osc_esc_html($rules($offer)); ?></td>
                <td data-col-name="<?php echo osc_esc_html(__('Uses', 'promotions')); ?>"><?php
                    echo osc_esc_html($offer->usesLimit > 0 ? sprintf(__('%1$d of %2$d', 'promotions'), $uses, $offer->usesLimit) : (string) $uses); ?></td>
                <td data-col-name="<?php echo osc_esc_html(__('Status', 'promotions')); ?>"><?php
                    if (!$offer->on) {
                        osc_admin_status('inactive', __('Off', 'promotions'));
                    } elseif ($offer->ends > 0 && $now >= $offer->ends) {
                        osc_admin_status('expired', __('Ended', 'promotions'));
                    } elseif ($offer->starts > 0 && $now < $offer->starts) {
                        osc_admin_status('new', __('Not started', 'promotions'));
                    } else {
                        osc_admin_status('active', __('On', 'promotions'));
                    } ?></td>
            </tr>
        <?php } ?>
        </tbody>
    </table>
</div>
<?php
osc_admin_form_section(__('Settings', 'promotions'), array('spaced' => true));
osc_admin_settings_form(Plugin::PAGE, array('values' => osc_settings_values(Plugin::PAGE), 'titles' => false));
foreach ($offers as $offer) {
    $name = $offer->code ?? $offer->label;
    osc_admin_confirm_dialog(array(
        'id'      => 'pr-reset-' . $offer->id,
        'url'     => $listUrl,
        'fields'  => array('pr_do' => 'reset', 'offer' => $offer->id),
        'title'   => sprintf(__('Reset the uses of "%s"?', 'promotions'), $name),
        'text'    => __('The count starts again from 0, and every shopper may use it again up to its limits.', 'promotions'),
        'confirm' => __('Reset uses', 'promotions'),
        'tone'    => 'plain',
    ));
    osc_admin_confirm_dialog(array(
        'id'      => 'pr-delete-' . $offer->id,
        'url'     => $listUrl,
        'fields'  => array('pr_do' => 'delete', 'offer' => $offer->id),
        'title'   => sprintf(__('Delete "%s"?', 'promotions'), $name),
        'text'    => __('Orders already placed keep their discount.', 'promotions'),
        'confirm' => __('Delete', 'promotions'),
    ));
}
