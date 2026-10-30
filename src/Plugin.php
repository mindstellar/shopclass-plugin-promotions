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
use mindstellar\billing\event\CartDiscountLines;
use mindstellar\billing\event\CartProblems;
use mindstellar\billing\event\CheckoutStarting;
use mindstellar\billing\event\Line;
use mindstellar\billing\event\OrderEvent;
use mindstellar\billing\event\OrderPlaced;
use mindstellar\currency\Money;

/**
 * Registration, settings access and the cart and order hooks. The hooks are thin: they read the
 * offers and hand the cart to Discounts and the counters to Ledger.
 */
final class Plugin
{
    public const PAGE = 'promotions';

    /** Statuses that make an order count against a first-order offer. */
    public const ORDER_STATUSES = array('pending', 'paid', 'shipped', 'delivered');

    /** fn(): int -- replaces the clock in tests. */
    public static ?Closure $clock = null;

    /** Replaces core's key-value store in tests. */
    public static ?Store $store = null;

    /** fn(int $userId): int -- replaces core's order count in tests. */
    public static ?Closure $orderCount = null;

    private static ?Ledger $ledger = null;

    /** @var array{0:?string,1:?array} the last offers read, keyed by the raw preference */
    private static array $memo = array(null, null);

    /** @var array{0:string,1:array}|null the last cart worked out, for cart_problems to reuse */
    private static ?array $last = null;

    public static function install(): void
    {
    }

    /** Remove the offers, the settings and every usage counter. */
    public static function uninstall(): void
    {
        foreach (array('offers', 'first_order_banner') as $name) {
            osc_delete_preference($name, self::PAGE);
        }
        osc_kv_delete_group(KvStore::GROUP);
    }

    /**
     * The offers, from the preference core keeps in memory; null when it is malformed.
     *
     * @return Offer[]|null
     */
    public static function offers(): ?array
    {
        $raw = (string) osc_get_preference('offers', self::PAGE);
        if (self::$memo[0] !== $raw) {
            self::$memo = array($raw, Offers::parse($raw));
        }

        return self::$memo[1];
    }

    /**
     * @param Offer[] $offers
     */
    public static function saveOffers(array $offers): void
    {
        osc_set_preference('offers', Offers::encode($offers), self::PAGE);
        self::$memo = array(null, null);
    }

    public static function store(): Store
    {
        return self::$store ??= new KvStore();
    }

    public static function ledger(): Ledger
    {
        if (self::$ledger === null) {
            self::$ledger = new Ledger(self::store());
            // Uses taken for an order this request never wrote are given back when it ends.
            $ledger = self::$ledger;
            register_shutdown_function(static function () use ($ledger): void {
                try {
                    $ledger->release();
                } catch (\Throwable $e) {
                }
            });
        }

        return self::$ledger;
    }

    /** Forget cached state; tests swap the store and clock between cases. */
    public static function reset(): void
    {
        self::$ledger = null;
        self::$memo   = array(null, null);
        self::$last   = null;
    }

    public static function now(): int
    {
        return self::$clock !== null ? (int) (self::$clock)() : time();
    }

    /** Typed listener for cart_discount_lines. */
    public static function onDiscountLines(CartDiscountLines $event): void
    {
        $cart = $event->cartArray();
        foreach (self::evaluate($cart)['lines'] as $row) {
            $line = Line::fromArray($row, (string) ($cart['currency'] ?? ''));
            if ($line !== null) {
                $event->addLine($line);
            }
        }
    }

    /** Typed listener for cart_problems. */
    public static function onProblems(CartProblems $event): void
    {
        $problem = self::evaluate($event->cartArray())['problem'];
        if ($problem !== null) {
            $event->add($problem['code'], $problem['pointer'], $problem['message']);
        }
    }

    /** Typed listener for cart_checkout_before: refuses the order at /coupon when an offer has run out. */
    public static function onCheckoutStarting(CheckoutStarting $event): void
    {
        $cart   = $event->cartArray();
        $offers = self::offers();
        if ($offers === null) {
            return;
        }
        $taking = array();
        foreach (Discounts::offerIds($cart['discount_lines'] ?? array()) as $id) {
            $offer = Offers::find($offers, $id);
            if ($offer !== null) {
                $taking[] = $offer;
            }
        }
        if (!self::ledger()->reserve($taking, (int) ($cart['user_id'] ?? 0))) {
            self::$last = null;
            $event->refuse(Discounts::POINTER, Offer::USED, __('An offer in your cart has just run out. Check the new total and try again.', 'promotions'));
        }
    }

    /** Typed listener for billing_order_placed. */
    public static function onOrderPlaced(OrderPlaced $event): void
    {
        self::orderPlaced($event->orderId(), $event->userId());
    }

    /** Typed listener for the cancelled and failed order events. */
    public static function onOrderEnded(OrderEvent $event): void
    {
        self::orderCancelled($event->orderId());
    }

    /**
     * cart_discount_lines: one line per automatic offer that applies and for the cart's code.
     *
     * @param mixed $lines
     * @param mixed $cart
     *
     * @return mixed
     */
    public static function discountLines($lines, $cart = array())
    {
        if (!is_array($lines) || !is_array($cart)) {
            return $lines;
        }
        $result = self::evaluate($cart);

        return array_merge($lines, $result['lines']);
    }

    /**
     * cart_problems: why the cart's code was refused, on /coupon.
     *
     * @param mixed $problems
     * @param mixed $cart
     *
     * @return mixed
     */
    public static function problems($problems, $cart = array())
    {
        if (!is_array($problems) || !is_array($cart)) {
            return $problems;
        }
        $result = self::evaluate($cart);
        if ($result['problem'] !== null) {
            $problems[] = $result['problem'];
        }

        return $problems;
    }

    /**
     * billing_order_placed: the uses taken belong to this order now.
     *
     * @param mixed $orderId
     * @param mixed $userId
     */
    public static function orderPlaced($orderId = 0, $userId = 0): void
    {
        self::$last = null;
        $orderId    = (int) $orderId;
        if ($orderId <= 0) {
            return;
        }
        self::ledger()->placed($orderId, (int) $userId, Discounts::offerIds(osc_order_lines($orderId)), self::offers() ?? array());
    }

    /**
     * Give back the uses the order took. Called for cancelled and failed orders through
     * onOrderEnded(), and for billing_order_refunded, which has no event yet.
     *
     * @param mixed $orderId
     */
    public static function orderCancelled($orderId = 0): void
    {
        self::$last = null;
        if ((int) $orderId > 0) {
            self::ledger()->cancelled((int) $orderId);
        }
    }

    /**
     * The first-order banner text from the settings, while an automatic first-order offer is
     * live; '' otherwise. Plain text: the theme escapes it.
     */
    public static function banner(): string
    {
        $text = trim((string) osc_get_preference('first_order_banner', self::PAGE));
        if ($text === '') {
            return '';
        }
        $now = self::now();
        foreach (self::offers() ?? array() as $offer) {
            if ($offer->code === null && $offer->firstOrderOnly && $offer->live($now)) {
                return $text;
            }
        }

        return '';
    }

    /** A typed amount in the site currency as micros, or null when it is not one. */
    public static function amount(string $text): ?int
    {
        $text = str_replace(',', '.', trim($text));

        return preg_match('/^\d{1,12}(\.\d{1,6})?$/', $text) === 1 ? Money::toMicros($text) : null;
    }

    /** An amount the way the cart shows prices, e.g. "₹5,000", with the currency's symbol. */
    public static function money(int $micros, string $currency): string
    {
        $code   = $currency !== '' ? $currency : (string) osc_currency();
        $symbol = $code;
        foreach (osc_get_currencies() as $row) {
            if (strcasecmp((string) ($row['pk_c_code'] ?? ''), $code) === 0) {
                $symbol = (string) ($row['s_description'] ?? '') !== '' ? (string) $row['s_description'] : $code;
                break;
            }
        }

        return (string) osc_format_price($micros, $symbol);
    }

    /**
     * The cart's lines and problem, worked out once for both filters of one quote.
     *
     * @param array<string,mixed> $cart
     *
     * @return array{lines:array<int,array<string,mixed>>,problem:?array<string,string>}
     */
    private static function evaluate(array $cart): array
    {
        $offers = self::offers();
        if ($offers === null) {
            return array('lines' => array(), 'problem' => null);
        }
        $key = serialize(array(
            $cart['coupon'] ?? null,
            $cart['user_id'] ?? 0,
            $cart['currency'] ?? '',
            $cart['subtotal'] ?? 0,
            array_map(static fn ($l): array => is_array($l) ? array($l['variant'] ?? null, $l['qty'] ?? null, $l['amount'] ?? null) : array(), is_array($cart['lines'] ?? null) ? $cart['lines'] : array()),
            Offer::shipping($cart),
        ));
        if (self::$last !== null && self::$last[0] === $key) {
            $result     = self::$last[1];
            self::$last = null;

            return $result;
        }
        $store = self::store();
        $count = self::$orderCount ?? static fn (int $userId): int => osc_billing_user_order_count($userId, self::ORDER_STATUSES);
        $usage = new Usage(
            static fn (string $k): int => $store->count($k),
            $count
        );
        $result     = Discounts::evaluate($offers, $cart, self::now(), $usage, Closure::fromCallable(array(self::class, 'money')));
        self::$last = array($key, $result);

        return $result;
    }
}
