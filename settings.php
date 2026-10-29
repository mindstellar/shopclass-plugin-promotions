<?php

/*
 * This file is part of the Promotions plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/*
 * The settings page declaration. The Offers screen draws the form under the offers list; core renders it, checks CSRF and the admin's capability,
 * validates each field and stores it under the "promotions" section. The offers are edited on
 * their own screen.
 */

return array(
    'title'  => __('Offers and coupons', 'promotions'),
    'menu'   => '',
    'return_url' => static fn (): string => osc_route_admin_url(\mindstellar\promotions\Admin\Screens::ROUTE),
    'intro'  => __('Coupon codes and automatic discounts.', 'promotions'),
    'fields' => array(
        array(
            'type'      => 'text',
            'name'      => 'first_order_banner',
            'label'     => __('First-order banner', 'promotions'),
            'help'      => __('A line a theme may show while an automatic first-order offer is on, such as "Get 150 off your first order". Leave empty for none.', 'promotions'),
            'maxlength' => 200,
            'default'   => '',
        ),
    ),
);
