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
 * Where the usage counters live: core's key-value store, group "promotions".
 */
interface Store
{
    /** A counter's value; 0 when absent. */
    public function count(string $key): int;

    /** Add $by (may be negative; never below 0) and return the new value, in one statement. */
    public function add(string $key, int $by): int;

    /**
     * @return mixed the stored value, or null
     */
    public function get(string $key);

    /**
     * @param mixed $value
     */
    public function set(string $key, $value, ?int $ttl): void;

    /** Remove a key; false when it was not there, so only one caller wins. */
    public function delete(string $key): bool;
}
