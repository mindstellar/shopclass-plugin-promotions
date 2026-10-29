<?php
/*
 * This file is part of the Promotions plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Loads the plugin's classes with no core, no database and no network. The few core names the
 * hook code calls are stood in for here.
 */

namespace mindstellar\currency {
    if (!class_exists(Money::class)) {
        final class Money
        {
            public static function format(int $micros, string $currency): string
            {
                return number_format($micros / 1000000, 2, '.', ',') . ' ' . $currency;
            }

            public static function toMicros($amount): int
            {
                return (int) round((float) $amount * 1000000);
            }

            public static function fromMicros(int $micros): string
            {
                return number_format($micros / 1000000, 2, '.', '');
            }
        }
    }
}

namespace mindstellar\exception {
    if (!class_exists(InvalidException::class)) {
        final class InvalidException extends \RuntimeException
        {
            public array $errors;

            public function __construct(string $pointer, string $reason, string $message)
            {
                parent::__construct($message);
                $this->errors = array(array('pointer' => $pointer, 'code' => $reason, 'message' => $message));
            }

            public function errors(): array
            {
                return $this->errors;
            }
        }
    }
}

namespace {
    require_once __DIR__ . '/harness.php';

    date_default_timezone_set('UTC');
    ini_set('display_errors', '1');

    spl_autoload_register(static function (string $class): void {
        $prefix = 'mindstellar\\promotions\\';
        if (strncmp($class, $prefix, strlen($prefix)) === 0) {
            require __DIR__ . '/../../src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        }
    });

    // The typed events are plain classes: load them from a core checkout (SHOPCLASS_CORE, else the sibling one).
    spl_autoload_register(static function (string $class): void {
        $dir  = rtrim(getenv('SHOPCLASS_CORE') ?: dirname(__DIR__, 3) . '/shopclass', '/') . '/oc-includes/osclass/classes/';
        $path = $dir . str_replace('\\', '/', substr($class, strlen('mindstellar\\'))) . '.php';
        if (strncmp($class, 'mindstellar\\', 12) === 0 && strpos($class, 'promotions') === false && is_file($path)) {
            require $path;
        }
    });

    if (!function_exists('__')) {
        function __($text, $domain = '')
        {
            return $text;
        }
    }

    $GLOBALS['prefs']       = array();
    $GLOBALS['order_lines'] = array();
    function osc_get_preference($name, $section = 'osclass')
    {
        return $GLOBALS['prefs'][$section][$name] ?? '';
    }
    function osc_set_preference($name, $value, $section = 'osclass')
    {
        $GLOBALS['prefs'][$section][$name] = $value;
    }
    function osc_order_lines(int $orderId): array
    {
        return $GLOBALS['order_lines'][$orderId] ?? array();
    }
    function osc_currency()
    {
        return 'INR';
    }

    /** The counters in memory, with the same never-below-zero rule as core's. */
    class ArrayStore implements \mindstellar\promotions\Store
    {
        public array $rows = array();

        public function count(string $key): int
        {
            return is_int($this->rows[$key] ?? null) ? $this->rows[$key] : 0;
        }

        public function add(string $key, int $by): int
        {
            return $this->rows[$key] = max(0, $this->count($key) + $by);
        }

        public function get(string $key)
        {
            return $this->rows[$key] ?? null;
        }

        public function set(string $key, $value, ?int $ttl): void
        {
            $this->rows[$key] = $value;
        }

        public function delete(string $key): bool
        {
            $had = array_key_exists($key, $this->rows);
            unset($this->rows[$key]);

            return $had;
        }
    }

    /** An offer in the stored shape, with overrides. */
    function offer(string $id, array $fields = array()): \mindstellar\promotions\Offer
    {
        $offer = \mindstellar\promotions\Offer::fromArray($fields + array(
            'id' => $id, 'code' => strtoupper($id), 'label' => '10% off', 'kind' => 'percent', 'value' => 1000,
        ));
        if ($offer === null) {
            throw new \LogicException('bad test offer ' . $id);
        }

        return $offer;
    }

    /** A cart as core hands it to the filters. */
    function cart(int $subtotal, array $extra = array()): array
    {
        return $extra + array(
            'lines'          => array(array('variant' => 1, 'qty' => 1, 'amount' => $subtotal)),
            'currency'       => 'INR',
            'user_id'        => 7,
            'subtotal'       => $subtotal,
            'coupon'         => null,
            'shipping_lines' => array(),
        );
    }

    /** Usage over fixed counters and a fixed order count. */
    function usage(array $counters = array(), int $orders = 0): \mindstellar\promotions\Usage
    {
        return new \mindstellar\promotions\Usage(
            static fn (string $key): int => $counters[$key] ?? 0,
            static fn (int $userId): int => $orders
        );
    }

    function money(): \Closure
    {
        return static fn (int $micros, string $currency): string => number_format($micros / 1000000, 2) . ' ' . $currency;
    }

    const RS = 1000000;
}
