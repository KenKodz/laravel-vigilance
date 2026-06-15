<?php

namespace Vigilance\Tests;

/**
 * Boots the app with log capture enabled (and tracing on, sample_rate = 1) so
 * the MessageLogged listener is wired at boot and every log emitted in a traced
 * request is correlated and kept for assertions.
 */
class LogsTestCase extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('vigilance.logs.enabled', true);
        $app['config']->set('vigilance.logs.level', 'debug');

        $app['config']->set('vigilance.tracing.enabled', true);
        $app['config']->set('vigilance.tracing.sample_rate', 1);
    }
}
