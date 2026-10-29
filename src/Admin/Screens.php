<?php

/*
 * This file is part of the Promotions plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace mindstellar\promotions\Admin;

use mindstellar\promotions\Offer;
use mindstellar\promotions\Offers;
use mindstellar\promotions\Plugin;
use Params;

/**
 * The Offers screens: the menu entry, the page headers and the posts. Only an administrator
 * reaches them, and every post checks the CSRF token first.
 */
final class Screens
{
    public const ROUTE = 'promotions-offers';

    public const EDIT_ROUTE = 'promotions-offer';

    /** @var array{post:array<string,string>,errors:string[]}|null a refused save, for the editor to show again */
    public static ?array $retry = null;

    /** The one entry, Billing > Offers and coupons: the admin_menu_init hook. The offer editor and the old settings URL light it. */
    public static function menu(): void
    {
        osc_add_admin_submenu_page(
            'billing',
            __('Offers and coupons', 'promotions'),
            osc_route_admin_url(self::ROUTE),
            'promotions-offers',
            'administrator',
            null,
            array(osc_route_admin_url(self::EDIT_ROUTE), osc_settings_page_url(Plugin::PAGE)),
            20
        );
    }

    /** The header core draws around each screen: the init_admin hook. */
    public static function pages(): void
    {
        osc_admin_plugin_page(self::ROUTE, array(
            'title'   => __('Offers and coupons', 'promotions'),
            'help'    => static function () {
                echo '<p>' . osc_esc_html(__('An offer with a code applies when a shopper types the code at checkout; one code per order. An offer without a code applies by itself to every cart that meets its rules, and these add up. Together they never take off more than the items and delivery cost.', 'promotions')) . '</p>';
            },
            'actions' => array(
                array('icon' => 'bi-plus-circle-fill', 'url' => osc_route_admin_url(self::EDIT_ROUTE), 'title' => __('Add offer', 'promotions')),
            ),
        ));
        osc_admin_plugin_page(self::EDIT_ROUTE, array('title' => __('Offer', 'promotions')));
    }

    /** Whether the signed-in user may manage offers. */
    public static function allowed(): bool
    {
        return osc_is_admin_user_logged_in() && !osc_is_moderator();
    }

    /** Act on a post to one of the screens: the init_admin hook. */
    public static function handlePost(): void
    {
        $route = Params::getParamString('route');
        if (!in_array($route, array(self::ROUTE, self::EDIT_ROUTE), true)
            || Params::getServerParam('REQUEST_METHOD') !== 'POST'
            || Params::getParamString('pr_do') === ''
        ) {
            return;
        }
        osc_csrf_check();
        if (!self::allowed()) {
            osc_add_flash_error_message(__('Only an administrator can manage offers.', 'promotions'), 'admin');
            osc_redirect_to(osc_admin_base_url(true));
        }
        $offers = Plugin::offers();
        if ($offers === null) {
            osc_add_flash_error_message(__('The stored offers could not be read, so nothing was changed. Discounts are off until they are fixed.', 'promotions'), 'admin');
            osc_redirect_to(osc_route_admin_url(self::ROUTE));
        }
        $id = Params::getParamString('offer');
        switch (Params::getParamString('pr_do')) {
            case 'save':
                self::save($offers, $id);

                return;
            case 'delete':
                $kept = array_values(array_filter($offers, static fn (Offer $o): bool => $o->id !== $id));
                if (count($kept) === count($offers)) {
                    osc_add_flash_error_message(__('That offer no longer exists.', 'promotions'), 'admin');
                    break;
                }
                Plugin::saveOffers($kept);
                osc_add_flash_ok_message(__('The offer is deleted. Orders already placed keep their discount.', 'promotions'), 'admin');
                break;
            case 'reset':
                $offer = Offers::find($offers, $id);
                if ($offer === null) {
                    osc_add_flash_error_message(__('That offer no longer exists.', 'promotions'), 'admin');
                    break;
                }
                Plugin::store()->delete($offer->usesKey());
                $offer->round++;
                Plugin::saveOffers($offers);
                osc_add_flash_ok_message(__('Uses start again from 0, for every shopper.', 'promotions'), 'admin');
                break;
        }
        osc_redirect_to(osc_route_admin_url(self::ROUTE));
    }

    /**
     * @param Offer[] $offers
     */
    private static function save(array $offers, string $id): void
    {
        $editing = $id !== '' ? Offers::find($offers, $id) : null;
        $post    = array();
        foreach (OfferForm::FIELDS as $field) {
            $post[$field] = Params::getParamString($field);
        }
        $result = OfferForm::read($post, array(Plugin::class, 'amount'), $offers, $editing, strtoupper((string) osc_currency()));
        if ($result['offer'] === null) {
            foreach ($result['errors'] as $error) {
                osc_add_flash_error_message($error, 'admin');
            }
            // No redirect: the editor draws this request's values again.
            self::$retry = array('post' => $post, 'errors' => $result['errors']);

            return;
        }
        $out   = array();
        $found = false;
        foreach ($offers as $offer) {
            if ($editing !== null && $offer->id === $editing->id) {
                $offer = $result['offer'];
                $found = true;
            }
            $out[] = $offer;
        }
        if (!$found) {
            $out[] = $result['offer'];
        }
        Plugin::saveOffers($out);
        osc_add_flash_ok_message(__('The offer is saved.', 'promotions'), 'admin');
        osc_redirect_to(osc_route_admin_url(self::ROUTE));
    }
}
