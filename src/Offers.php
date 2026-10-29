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
 * The stored list of offers: the `offers` preference, a JSON list of at most MAX offers.
 */
final class Offers
{
    public const MAX = 200;

    /**
     * The offers in a stored value. An empty value is no offers; a value that is not a JSON list
     * of at most MAX is null, which switches the discounts off rather than guess. A single entry
     * out of range is left out.
     *
     * @return Offer[]|null
     */
    public static function parse(string $json): ?array
    {
        if (trim($json) === '') {
            return array();
        }
        $rows = json_decode($json, true);
        if (!is_array($rows) || ($rows !== array() && array_keys($rows) !== range(0, count($rows) - 1)) || count($rows) > self::MAX) {
            return null;
        }
        $out   = array();
        $codes = array();
        foreach ($rows as $row) {
            $offer = Offer::fromArray($row);
            if ($offer === null || isset($out[$offer->id]) || ($offer->code !== null && isset($codes[$offer->code]))) {
                continue;
            }
            $out[$offer->id] = $offer;
            if ($offer->code !== null) {
                $codes[$offer->code] = true;
            }
        }

        return array_values($out);
    }

    /**
     * @param Offer[] $offers
     */
    public static function encode(array $offers): string
    {
        return (string) json_encode(array_map(static fn (Offer $o): array => $o->toArray(), array_values($offers)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param Offer[] $offers
     */
    public static function find(array $offers, string $id): ?Offer
    {
        foreach ($offers as $offer) {
            if ($offer->id === $id) {
                return $offer;
            }
        }

        return null;
    }

    /**
     * The offer, other than $exceptId, that holds this code.
     *
     * @param Offer[] $offers
     */
    public static function withCode(array $offers, string $code, string $exceptId = ''): ?Offer
    {
        foreach ($offers as $offer) {
            if ($offer->code === $code && $offer->id !== $exceptId) {
                return $offer;
            }
        }

        return null;
    }

    /**
     * A new id from the code or label: a-z, 0-9 and -, unused in the list.
     *
     * @param Offer[] $offers
     */
    public static function newId(string $from, array $offers): string
    {
        $base = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($from)) ?? '', '-');
        $base = $base === '' ? 'offer' : rtrim(substr($base, 0, 32), '-');
        $id   = $base;
        for ($n = 2; self::find($offers, $id) !== null; $n++) {
            $id = $base . '-' . $n;
        }

        return $id;
    }
}
