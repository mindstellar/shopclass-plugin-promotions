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

namespace mindstellar\promotions;

use Closure;

/**
 * Works out a cart's discount lines and its coupon refusal from the offers. Every automatic
 * offer that applies adds a line; the cart's one code adds a line or a refusal. Together they
 * never take off more than the items and shipping cost. Pure: no settings, no storage.
 */
final class Discounts
{
    public const POINTER = '/coupon';

    /** Id prefix of this plugin's discount lines. */
    public const LINE_PREFIX = 'promo.';

    /**
     * @param Offer[]                    $offers
     * @param array<string,mixed>        $cart  core's cart: subtotal, currency, user_id, coupon, shipping_lines
     * @param Closure(int,string):string $money an amount for a message, e.g. "₹999"
     *
     * @return array{lines:array<int,array<string,mixed>>,problem:?array{code:string,pointer:string,message:string}}
     */
    public static function evaluate(array $offers, array $cart, int $now, Usage $usage, Closure $money): array
    {
        $coupon   = is_string($cart['coupon'] ?? null) && $cart['coupon'] !== '' ? $cart['coupon'] : null;
        $currency = (string) ($cart['currency'] ?? '');
        $room     = max(0, (int) ($cart['subtotal'] ?? 0)) + Offer::shipping($cart);
        $lines    = array();
        $problem  = null;
        $matched  = false;

        foreach ($offers as $offer) {
            if ($offer->code === null) {
                if (!$offer->live($now) || $offer->check($cart, $now, $usage) !== null) {
                    continue;
                }
                $amount = min($offer->amount($cart), $room);
                if ($amount <= 0) {
                    continue;
                }
                $room   -= $amount;
                $lines[] = self::line($offer, $offer->label, $amount, $currency);
                continue;
            }
            if ($coupon === null || $offer->code !== $coupon) {
                continue;
            }
            $matched = true;
            $refused = $offer->check($cart, $now, $usage);
            if ($refused !== null) {
                $problem = array('code' => $refused, 'pointer' => self::POINTER, 'message' => self::message($refused, $offer, $currency, $usage, $money));
                continue;
            }
            // A code that takes nothing off (free delivery on a free parcel) still shows as applied.
            $amount  = min($offer->amount($cart), $room);
            $room   -= $amount;
            $lines[] = self::line($offer, $offer->code . ' · ' . $offer->label, $amount, $currency);
        }
        if ($coupon !== null && !$matched) {
            $problem = array('code' => Offer::INVALID, 'pointer' => self::POINTER, 'message' => self::message(Offer::INVALID, null, $currency, $usage, $money));
        }

        return array('lines' => $lines, 'problem' => $problem);
    }

    /**
     * What the shopper reads under the code field.
     *
     * @param Closure(int,string):string $money
     */
    public static function message(string $code, ?Offer $offer, string $currency, Usage $usage, Closure $money): string
    {
        switch ($code) {
            case Offer::NOT_STARTED:
                $starts = $offer !== null ? $offer->starts : 0;

                return sprintf(__('This code starts on %s.', 'promotions'), date(date('H:i', $starts) === '00:00' ? 'Y-m-d' : 'Y-m-d H:i', $starts));
            case Offer::EXPIRED:
                return __('This code has expired.', 'promotions');
            case Offer::MIN:
                return sprintf(__('This code needs items worth at least %s.', 'promotions'), $money($offer !== null ? $offer->minSubtotal : 0, $currency));
            case Offer::USED:
                return $offer !== null && $offer->usesLimit > 0 && $usage->count($offer->usesKey()) >= $offer->usesLimit
                    ? __('This code has run out.', 'promotions')
                    : __('You have already used this code.', 'promotions');
            case Offer::SIGN_IN:
                return __('Sign in to use this code.', 'promotions');
            case Offer::FIRST_ORDER:
                return __('This code is for a first order only.', 'promotions');
            default:
                return __('This code is not valid.', 'promotions');
        }
    }

    /**
     * A free delivery line says `applies_to: shipping` in its meta, so tax takes it off the
     * shipping, not the items.
     *
     * @return array{id:string,label:string,amount:int,currency:string,meta:array<string,mixed>}
     */
    private static function line(Offer $offer, string $label, int $amount, string $currency): array
    {
        return array(
            'id'       => self::LINE_PREFIX . $offer->id,
            'label'    => mb_substr($label, 0, 200, 'UTF-8'),
            'amount'   => -$amount,
            'currency' => $currency,
            'meta'     => array('offer' => $offer->id, 'code' => $offer->code)
                + ($offer->kind === Offer::FREE_SHIPPING ? array('applies_to' => 'shipping') : array()),
        );
    }

    /**
     * The offer ids of this plugin's discount lines, from a cart's lines or an order's.
     *
     * @param mixed $lines
     *
     * @return string[]
     */
    public static function offerIds($lines): array
    {
        $ids = array();
        foreach (is_array($lines) ? $lines : array() as $line) {
            if (!is_array($line) || ($line['kind'] ?? 'discount') !== 'discount') {
                continue;
            }
            $meta  = is_array($line['meta'] ?? null) ? $line['meta'] : array();
            $offer = $meta['offer'] ?? null;
            // A cart line carries its id; a stored order line keeps it in meta.
            $id    = is_string($line['id'] ?? null) ? $line['id'] : ($meta['id'] ?? null);
            if (is_string($offer) && is_string($id) && $id === self::LINE_PREFIX . $offer) {
                $ids[$offer] = $offer;
            }
        }

        return array_values($ids);
    }
}
