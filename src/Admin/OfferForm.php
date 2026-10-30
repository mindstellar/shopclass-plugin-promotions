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

use DateTimeImmutable;
use mindstellar\promotions\Offer;
use mindstellar\promotions\Offers;
use mindstellar\promotions\Plugin;

/**
 * Reads the offer editor's post into an offer, checking every field on the server. Pure: the
 * amount parser, the existing offers and the currency are handed in.
 */
final class OfferForm
{
    public const FIELDS = array(
        'code', 'label', 'kind', 'value', 'min_subtotal', 'max_discount', 'starts', 'ends',
        'uses_limit', 'per_user_limit', 'first_order_only', 'on',
    );

    /**
     * @param array<string,string>   $post     FIELDS as typed
     * @param callable(string):?int  $amount   a typed amount as micros, or null
     * @param Offer[]                $offers   the offers stored now
     * @param Offer|null             $editing  the offer being edited, null for a new one
     *
     * @return array{offer:?Offer,errors:string[]}
     */
    public static function read(array $post, callable $amount, array $offers, ?Offer $editing, string $currency): array
    {
        $errors = array();
        $get    = static fn (string $k): string => trim((string) ($post[$k] ?? ''));

        $code = null;
        if ($get('code') !== '') {
            $code = Offer::normaliseCode($get('code'));
            if ($code === null) {
                $errors[] = __('A code is up to 40 letters, digits and dashes.', 'promotions');
            } elseif (Offers::withCode($offers, $code, $editing->id ?? '') !== null) {
                $errors[] = sprintf(__('Another offer already uses the code %s.', 'promotions'), $code);
            }
        }

        $kind = in_array($get('kind'), Offer::KINDS, true) ? $get('kind') : null;
        if ($kind === null) {
            $errors[] = __('Choose what the offer takes off.', 'promotions');
        }
        $value = 0;
        if ($kind === Offer::PERCENT) {
            $text = str_replace(',', '.', $get('value'));
            $bp   = preg_match('/^\d{1,3}(\.\d{1,2})?$/', $text) === 1 ? (int) round((float) $text * 100) : 0;
            if ($bp < 1 || $bp > Offer::MAX_BP) {
                $errors[] = __('A percentage is more than 0 and at most 100.', 'promotions');
            }
            $value = $bp;
        } elseif ($kind === Offer::FIXED) {
            $value = $amount($get('value')) ?? 0;
            if ($value < 1 || $value > Offer::MAX_MICROS) {
                $errors[] = __('Enter the amount the offer takes off.', 'promotions');
            }
        }

        $money = array();
        foreach (array('min_subtotal' => __('Minimum order', 'promotions'), 'max_discount' => __('Most it takes off', 'promotions')) as $field => $name) {
            $money[$field] = $get($field) === '' ? 0 : $amount($get($field));
            if ($money[$field] === null || $money[$field] < 0 || $money[$field] > Offer::MAX_MICROS) {
                $errors[] = sprintf(__('%s needs an amount, or leave it empty.', 'promotions'), $name);
                $money[$field] = 0;
            }
        }

        $counts = array();
        foreach (array('uses_limit' => __('Uses in all', 'promotions'), 'per_user_limit' => __('Uses per shopper', 'promotions')) as $field => $name) {
            $text = $get($field);
            $counts[$field] = $text === '' ? 0 : (ctype_digit($text) && strlen($text) <= 10 ? (int) $text : -1);
            if ($counts[$field] < 0 || $counts[$field] > Offer::MAX_USES) {
                $errors[] = sprintf(__('%s is a whole number, or leave it empty for no limit.', 'promotions'), $name);
                $counts[$field] = 0;
            }
        }

        $starts = self::time($get('starts'), false);
        $ends   = self::time($get('ends'), true);
        if ($starts === null || $ends === null) {
            $errors[] = __('Dates are written 2026-10-31 or 2026-10-31 18:00.', 'promotions');
        } elseif ($ends > 0 && $ends <= $starts) {
            $errors[] = __('The offer must end after it starts.', 'promotions');
        }

        $label = $get('label');
        if (mb_strlen($label, 'UTF-8') > Offer::MAX_LABEL) {
            $errors[] = sprintf(__('Keep the name under %d characters.', 'promotions'), Offer::MAX_LABEL + 1);
        }
        $label = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $label) ?? '';

        if (count($offers) >= Offers::MAX && $editing === null) {
            $errors[] = sprintf(__('At most %d offers.', 'promotions'), Offers::MAX);
        }
        if ($errors !== array() || $kind === null) {
            return array('offer' => null, 'errors' => $errors);
        }

        $offer = new Offer(
            $editing->id ?? Offers::newId($code ?? ($label !== '' ? $label : 'offer'), $offers),
            $code,
            $label,
            $kind,
            $value,
            (int) $money['min_subtotal'],
            (int) $money['max_discount'],
            (int) $starts,
            (int) $ends,
            $counts['uses_limit'],
            $counts['per_user_limit'],
            $get('first_order_only') === '1',
            $get('on') === '1',
            $currency,
            $editing->round ?? 0
        );
        if ($offer->label === '') {
            $offer->label = self::defaultLabel($offer, $currency);
        }

        return array('offer' => $offer, 'errors' => array());
    }

    /** "10% off", "₹150 off" or "Free delivery". */
    public static function defaultLabel(Offer $offer, string $currency): string
    {
        switch ($offer->kind) {
            case Offer::PERCENT:
                return sprintf(__('%s%% off', 'promotions'), rtrim(rtrim(number_format($offer->value / 100, 2, '.', ''), '0'), '.'));
            case Offer::FIXED:
                return sprintf(__('%s off', 'promotions'), Plugin::money($offer->value, $currency));
            default:
                return __('Free delivery', 'promotions');
        }
    }

    /** A unix time as the editor shows it, in the site's time zone; '' for none. */
    public static function show(int $time): string
    {
        return $time === 0 ? '' : date('Y-m-d H:i', $time);
    }

    /** An end time as the admin typed it: an end at midnight is the day before, which it runs to the end of. */
    public static function showEnd(int $time): string
    {
        return $time === 0 ? '' : (date('H:i', $time) === '00:00' ? date('Y-m-d', $time - 1) : date('Y-m-d H:i', $time));
    }

    /**
     * A typed date in the site's time zone as unix time: 0 when empty, null when it is not a
     * date. A day alone starts at midnight, or, as an end, runs to the end of that day.
     */
    private static function time(string $text, bool $end): ?int
    {
        if ($text === '') {
            return 0;
        }
        $text = str_replace('T', ' ', $text);
        foreach (array('Y-m-d H:i' => false, 'Y-m-d' => true) as $format => $dayOnly) {
            $date = DateTimeImmutable::createFromFormat('!' . $format, $text);
            if ($date !== false && $date->format($format) === $text) {
                return $dayOnly && $end ? $date->modify('+1 day')->getTimestamp() : $date->getTimestamp();
            }
        }

        return null;
    }
}
