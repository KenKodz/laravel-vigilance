<?php

namespace Vigilance\Apm\Recorders\Concerns;

use Vigilance\Support\PathMatcher;

trait Ignores
{
    /**
     * Whether $key matches this recorder's ignore regexes or the global
     * vigilance.ignore_paths list (admin panels, Livewire, …).
     */
    protected function shouldIgnore(string $key): bool
    {
        foreach ((array) $this->recorderConfig('ignore', []) as $pattern) {
            if (@preg_match((string) $pattern, $key) === 1) {
                return true;
            }
        }

        return PathMatcher::ignored($key);
    }
}
