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
 * Counts each use of an offer. Uses are taken just before an order is written, one atomic
 * increment per counter, so two orders at once cannot both take the last use. The order then
 * keeps the list of counters it took; cancelling, failing or refunding it gives them back once. Uses taken for an
 * order that was never written are given back.
 */
final class Ledger
{
    /** How long an order remembers the counters it took, in seconds. */
    public const ORDER_TTL = 31536000;

    private Store $store;

    /** @var array{user:int,offers:string[],keys:string[]}|null uses taken for an order not written yet */
    private ?array $pending = null;

    public function __construct(Store $store)
    {
        $this->store = $store;
    }

    /**
     * Take one use of each offer for an order about to be written. False when one of them has
     * just run out; then nothing is kept.
     *
     * @param Offer[] $offers
     */
    public function reserve(array $offers, int $userId): bool
    {
        $this->release();
        $taken = array();
        foreach ($offers as $offer) {
            $counters = array($offer->usesKey() => $offer->usesLimit);
            if ($userId > 0) {
                $counters[$offer->userKey($userId)] = $offer->userLimit();
            }
            foreach ($counters as $key => $limit) {
                $value   = $this->store->add($key, 1);
                $taken[] = $key;
                if ($limit > 0 && $value > $limit) {
                    $this->giveBack($taken);

                    return false;
                }
            }
        }
        $this->pending = array('user' => $userId, 'offers' => self::sorted(array_map(static fn (Offer $o): string => $o->id, $offers)), 'keys' => $taken);

        return true;
    }

    /**
     * The order is written: tie the uses taken for it to its id. Offers it holds that nothing
     * reserved (a core that placed it without the reserve step) are counted now.
     *
     * @param string[] $offerIds the offers its discount lines name
     * @param Offer[]  $known    the offers that still exist, for counting late ones
     */
    public function placed(int $orderId, int $userId, array $offerIds, array $known): void
    {
        $offerIds = self::sorted($offerIds);
        $keys     = array();
        if ($this->pending !== null && $this->pending['user'] === $userId && $this->pending['offers'] === $offerIds) {
            $keys          = $this->pending['keys'];
            $this->pending = null;
        } else {
            $this->release();
            foreach ($offerIds as $id) {
                $offer = Offers::find($known, $id);
                if ($offer === null) {
                    continue;
                }
                foreach ($userId > 0 ? array($offer->usesKey(), $offer->userKey($userId)) : array($offer->usesKey()) as $key) {
                    $this->store->add($key, 1);
                    $keys[] = $key;
                }
            }
        }
        if ($keys !== array()) {
            $this->store->set('order:' . $orderId, $keys, self::ORDER_TTL);
        }
    }

    /**
     * The order is cancelled: give back what it took, once however often this runs.
     */
    public function cancelled(int $orderId): void
    {
        $keys = $this->store->get('order:' . $orderId);
        if (!is_array($keys) || !$this->store->delete('order:' . $orderId)) {
            return;
        }
        $this->giveBack(array_values(array_filter($keys, 'is_string')));
    }

    /**
     * Give back uses taken for an order that was never written.
     */
    public function release(): void
    {
        if ($this->pending === null) {
            return;
        }
        $keys          = $this->pending['keys'];
        $this->pending = null;
        $this->giveBack($keys);
    }

    /**
     * @param string[] $keys
     */
    private function giveBack(array $keys): void
    {
        foreach ($keys as $key) {
            $this->store->add($key, -1);
        }
    }

    /**
     * @param string[] $ids
     *
     * @return string[]
     */
    private static function sorted(array $ids): array
    {
        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
    }
}
