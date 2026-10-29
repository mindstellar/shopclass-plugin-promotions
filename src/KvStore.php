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
 * The counters in core's t_key_value, through the osc_kv_* helpers. Each call is one
 * primary-key statement.
 */
final class KvStore implements Store
{
    public const GROUP = 'promotions';

    public function count(string $key): int
    {
        $value = osc_kv_get(self::GROUP, $key, 0);

        return is_numeric($value) ? max(0, (int) $value) : 0;
    }

    public function add(string $key, int $by): int
    {
        return osc_kv_increment(self::GROUP, $key, $by);
    }

    public function get(string $key)
    {
        return osc_kv_get(self::GROUP, $key);
    }

    public function set(string $key, $value, ?int $ttl): void
    {
        osc_kv_set(self::GROUP, $key, $value, $ttl);
    }

    public function delete(string $key): bool
    {
        return osc_kv_delete(self::GROUP, $key);
    }
}
