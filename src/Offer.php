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

/**
 * One offer: a coupon code, or an automatic discount when it has no code. Pure: the rules read
 * the cart, the clock and the usage counters handed in, and nothing else.
 */
final class Offer
{
    public const PERCENT       = 'percent';
    public const FIXED         = 'fixed';
    public const FREE_SHIPPING = 'free_shipping';

    public const KINDS = array(self::PERCENT, self::FIXED, self::FREE_SHIPPING);

    /** Refusal codes, in the order the checks run. */
    public const INVALID     = 'coupon_invalid';
    public const NOT_STARTED = 'coupon_not_started';
    public const EXPIRED     = 'coupon_expired';
    public const MIN         = 'coupon_min';
    public const USED        = 'coupon_used';
    public const SIGN_IN     = 'coupon_sign_in';
    public const FIRST_ORDER = 'coupon_first_order';

    public const MAX_LABEL = 100;

    /** 100% in basis points. */
    public const MAX_BP = 10000;

    /** Largest amount an offer names, in micros (a thousand million units). */
    public const MAX_MICROS = 1000000000000000;

    /** Largest uses limit. */
    public const MAX_USES = 1000000000;

    /**
     * @param string      $id             a-z, 0-9 and -, 1-40; fixed once made
     * @param string|null $code           null for an automatic offer
     * @param int         $value          basis points for percent, micros for fixed, 0 for free shipping
     * @param int         $starts         unix time it starts, 0 for now
     * @param int         $ends           unix time it stops, 0 for never
     * @param string      $currency       the site currency when it was saved; a cart in another is not served
     * @param int         $round          bumped by "Reset uses" so the counters start again
     */
    public function __construct(
        public string $id,
        public ?string $code,
        public string $label,
        public string $kind,
        public int $value,
        public int $minSubtotal = 0,
        public int $maxDiscount = 0,
        public int $starts = 0,
        public int $ends = 0,
        public int $usesLimit = 0,
        public int $perUserLimit = 0,
        public bool $firstOrderOnly = false,
        public bool $on = true,
        public string $currency = '',
        public int $round = 0
    ) {
    }

    /**
     * A code as a shopper types it and core passes it on: no spaces, upper case, 1-40 of A-Z,
     * 0-9 and -. Null when it cannot be one.
     */
    public static function normaliseCode(string $raw): ?string
    {
        $code = strtoupper(preg_replace('/\s+/u', '', $raw) ?? '');

        return preg_match('/^[A-Z0-9-]{1,40}$/', $code) === 1 ? $code : null;
    }

    /**
     * An offer from its stored shape, or null when any field is out of range.
     *
     * @param mixed $row
     */
    public static function fromArray($row): ?self
    {
        if (!is_array($row)) {
            return null;
        }
        $int = static fn ($v, int $max): ?int => is_int($v) && $v >= 0 && $v <= $max ? $v : null;

        $id    = $row['id'] ?? null;
        $code  = $row['code'] ?? null;
        $label = is_string($row['label'] ?? null) ? trim($row['label']) : '';
        $kind  = $row['kind'] ?? null;
        if (!is_string($id) || preg_match('/^[a-z0-9-]{1,40}$/', $id) !== 1
            || ($code !== null && (!is_string($code) || self::normaliseCode($code) !== $code))
            || $label === '' || mb_strlen($label, 'UTF-8') > self::MAX_LABEL
            || !in_array($kind, self::KINDS, true)
        ) {
            return null;
        }
        $value = $int($row['value'] ?? null, $kind === self::PERCENT ? self::MAX_BP : self::MAX_MICROS);
        if ($kind === self::FREE_SHIPPING) {
            $value = 0;
        } elseif ($value === null || $value < 1) {
            return null;
        }
        $fields = array();
        foreach (array('min_subtotal' => self::MAX_MICROS, 'max_discount' => self::MAX_MICROS, 'starts' => PHP_INT_MAX, 'ends' => PHP_INT_MAX,
            'uses_limit' => self::MAX_USES, 'per_user_limit' => self::MAX_USES, 'round' => self::MAX_USES) as $key => $max) {
            $fields[$key] = $int($row[$key] ?? 0, $max);
            if ($fields[$key] === null) {
                return null;
            }
        }
        $currency = $row['currency'] ?? '';
        if (($fields['ends'] > 0 && $fields['ends'] <= $fields['starts'])
            || !is_bool($row['first_order_only'] ?? false) || !is_bool($row['on'] ?? true)
            || !is_string($currency) || ($currency !== '' && preg_match('/^[A-Z]{3}$/', $currency) !== 1)
        ) {
            return null;
        }

        return new self(
            $id,
            $code,
            $label,
            $kind,
            $value,
            $fields['min_subtotal'],
            $fields['max_discount'],
            $fields['starts'],
            $fields['ends'],
            $fields['uses_limit'],
            $fields['per_user_limit'],
            $row['first_order_only'] ?? false,
            $row['on'] ?? true,
            $currency,
            $fields['round']
        );
    }

    /**
     * The stored shape.
     *
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return array(
            'id'               => $this->id,
            'code'             => $this->code,
            'label'            => $this->label,
            'kind'             => $this->kind,
            'value'            => $this->value,
            'min_subtotal'     => $this->minSubtotal,
            'max_discount'     => $this->maxDiscount,
            'starts'           => $this->starts,
            'ends'             => $this->ends,
            'uses_limit'       => $this->usesLimit,
            'per_user_limit'   => $this->perUserLimit,
            'first_order_only' => $this->firstOrderOnly,
            'on'               => $this->on,
            'currency'         => $this->currency,
            'round'            => $this->round,
        );
    }

    /** Counter of every use. */
    public function usesKey(): string
    {
        return 'uses:' . $this->counterBase();
    }

    /** Counter of one shopper's uses. */
    public function userKey(int $userId): string
    {
        return 'uses:' . $this->counterBase() . ':u' . $userId;
    }

    /**
     * How many times one shopper may use it: the per-user limit, and once for a first-order
     * offer. 0 for no limit.
     */
    public function userLimit(): int
    {
        if ($this->firstOrderOnly) {
            return 1;
        }

        return $this->perUserLimit;
    }

    /** Whether it is on and inside its dates at $now. */
    public function live(int $now): bool
    {
        return $this->on && ($this->starts === 0 || $now >= $this->starts) && ($this->ends === 0 || $now < $this->ends);
    }

    /**
     * Why this offer does not apply to the cart, as a refusal code, or null when it does.
     * Checks run in order: on and in the cart's currency, start and end dates, the minimum, uses left,
     * the shopper's uses left (a guest is asked to sign in), first order.
     *
     * @param array<string,mixed> $cart
     */
    public function check(array $cart, int $now, Usage $usage): ?string
    {
        if (!$this->on || ($this->currency !== '' && (string) ($cart['currency'] ?? '') !== $this->currency)) {
            return self::INVALID;
        }
        if ($this->starts > 0 && $now < $this->starts) {
            return self::NOT_STARTED;
        }
        if ($this->ends > 0 && $now >= $this->ends) {
            return self::EXPIRED;
        }
        if ((int) ($cart['subtotal'] ?? 0) < $this->minSubtotal) {
            return self::MIN;
        }
        if ($this->usesLimit > 0 && $usage->count($this->usesKey()) >= $this->usesLimit) {
            return self::USED;
        }
        $userId = (int) ($cart['user_id'] ?? 0);
        if (($this->perUserLimit > 0 || $this->firstOrderOnly) && $userId <= 0) {
            return self::SIGN_IN;
        }
        if ($this->perUserLimit > 0 && $usage->count($this->userKey($userId)) >= $this->perUserLimit) {
            return self::USED;
        }
        if ($this->firstOrderOnly && ($usage->count($this->userKey($userId)) > 0 || !$usage->firstOrder($userId))) {
            return self::FIRST_ORDER;
        }

        return null;
    }

    /**
     * What it takes off the cart, in micros, before the cart-wide cap: a percentage of the item
     * subtotal, a fixed amount no larger than it, or the shipping; then max_discount.
     *
     * @param array<string,mixed> $cart
     */
    public function amount(array $cart): int
    {
        $subtotal = max(0, (int) ($cart['subtotal'] ?? 0));
        switch ($this->kind) {
            case self::PERCENT:
                $amount = self::percentOf($subtotal, $this->value);
                break;
            case self::FIXED:
                $amount = min($this->value, $subtotal);
                break;
            default:
                $amount = self::shipping($cart);
        }
        if ($this->maxDiscount > 0) {
            $amount = min($amount, $this->maxDiscount);
        }

        return max(0, $amount);
    }

    /**
     * $bp basis points of $micros, half up, with no overflow for any cart.
     */
    public static function percentOf(int $micros, int $bp): int
    {
        return intdiv($micros, 10000) * $bp + intdiv(($micros % 10000) * $bp + 5000, 10000);
    }

    /**
     * What the cart's shipping lines add up to.
     *
     * @param array<string,mixed> $cart
     */
    public static function shipping(array $cart): int
    {
        $sum = 0;
        foreach (is_array($cart['shipping_lines'] ?? null) ? $cart['shipping_lines'] : array() as $line) {
            if (is_array($line) && is_int($line['amount'] ?? null) && $line['amount'] > 0) {
                $sum += $line['amount'];
            }
        }

        return $sum;
    }

    private function counterBase(): string
    {
        return $this->round === 0 ? $this->id : $this->id . '.r' . $this->round;
    }
}
