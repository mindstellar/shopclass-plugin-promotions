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
 * What the offer checks read about use: a counter's value and whether a shopper has placed an
 * order yet. Each is read at most once per instance, and only when a check needs it.
 */
final class Usage
{
    /** @var Closure(string):int */
    private Closure $counter;

    /** @var Closure(int):int */
    private Closure $orders;

    /** @var array<string,int> */
    private array $counts = array();

    /** @var array<int,bool> */
    private array $first = array();

    /**
     * @param Closure(string):int $counter a counter's value by key
     * @param Closure(int):int    $orders  a user's open and settled orders
     */
    public function __construct(Closure $counter, Closure $orders)
    {
        $this->counter = $counter;
        $this->orders  = $orders;
    }

    public function count(string $key): int
    {
        return $this->counts[$key] ??= max(0, ($this->counter)($key));
    }

    /** Whether the user has no order pending, paid, shipped or delivered. */
    public function firstOrder(int $userId): bool
    {
        return $this->first[$userId] ??= $userId > 0 && ($this->orders)($userId) === 0;
    }
}
