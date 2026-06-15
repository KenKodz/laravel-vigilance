<?php

namespace Vigilance\Support;

use Illuminate\Support\Str;

/**
 * Matches a request path against a list of patterns. Each pattern is either a
 * delimited regex (starts with "#", e.g. "#^/livewire/#") or a wildcard glob
 * (e.g. "/admin/*", "livewire-*") matched with Str::is — so the global
 * vigilance.ignore_paths list reads naturally while staying backward-compatible
 * with the regex-style per-recorder ignore lists.
 */
final class PathMatcher
{
    /**
     * @param  iterable<int, string>  $patterns
     */
    public static function matchesAny(string $path, iterable $patterns): bool
    {
        $trimmed = ltrim($path, '/');

        foreach ($patterns as $pattern) {
            $pattern = (string) $pattern;

            if ($pattern === '') {
                continue;
            }

            if (str_starts_with($pattern, '#')) {
                if (@preg_match($pattern, $path) === 1) {
                    return true;
                }

                continue;
            }

            // Wildcard glob — accept patterns written with or without a leading
            // slash ("/admin/*" and "admin/*" both match "/admin/users").
            if (Str::is($pattern, $path) || Str::is($pattern, $trimmed)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the path is on the global ignore list (vigilance.ignore_paths) —
     * the one place to exclude noisy endpoints (admin panels, Livewire, …) from
     * APM, tracing, RUM and request-error capture at once.
     */
    public static function ignored(string $path): bool
    {
        return self::matchesAny($path, (array) config('vigilance.ignore_paths', []));
    }
}
