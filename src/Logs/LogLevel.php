<?php

namespace Vigilance\Logs;

/**
 * PSR-3 / Monolog severity helper. Levels are stored both as their name (for
 * display and channel-style filtering) and as a numeric value (for "this level
 * and above" range queries), using the conventional Monolog scale where a
 * higher number is more severe.
 */
final class LogLevel
{
    /** @var array<string, int> */
    public const VALUES = [
        'debug' => 100,
        'info' => 200,
        'notice' => 250,
        'warning' => 300,
        'error' => 400,
        'critical' => 500,
        'alert' => 550,
        'emergency' => 600,
    ];

    /**
     * Numeric severity for a PSR-3 level name. Unknown names fall back to a
     * mid value so they're never silently dropped by a threshold filter.
     */
    public static function value(string $level): int
    {
        return self::VALUES[strtolower($level)] ?? 200;
    }

    /**
     * The level names at or above the given name, most-severe last — used to
     * populate the explorer's "minimum level" filter.
     *
     * @return list<string>
     */
    public static function namesFrom(string $level): array
    {
        $min = self::value($level);

        return array_values(array_filter(
            array_keys(self::VALUES),
            static fn (string $name): bool => self::VALUES[$name] >= $min,
        ));
    }
}
