<?php

namespace Vigilance\Capture;

use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobReleasedAfterException;
use Illuminate\Support\Facades\Queue;

class JobCapture
{
    /**
     * Queue::$createPayloadCallbacks is process-static, so a callback survives
     * across application boots. Vapor's Octane runtime boots the app twice in
     * one process (config:cache, then the worker); without this guard the
     * payload hook would stack and write a duplicate "queued" row per dispatch.
     */
    private static bool $payloadCallbackRegistered = false;

    public function __construct(protected Recorder $recorder) {}

    public function register(): void
    {
        if (! self::$payloadCallbackRegistered) {
            self::$payloadCallbackRegistered = true;

            // The correlation trick: a uuid lives inside the payload, so a job can
            // be tracked from "queued" through "processed/failed" across ANY queue
            // driver (sync, database, redis, sqs, beanstalkd).
            Queue::createPayloadUsing(function ($connection, $queue, $payload) {
                return $this->recorder->onJobPayloadCreate((string) $connection, $queue, $payload);
            });
        }

        $events = app('events');

        $events->listen(JobProcessing::class, fn (JobProcessing $e) => $this->recorder->jobProcessing($e));
        $events->listen(JobProcessed::class, fn (JobProcessed $e) => $this->recorder->jobProcessed($e));
        $events->listen(JobFailed::class, fn (JobFailed $e) => $this->recorder->jobFailed($e));
        $events->listen(JobReleasedAfterException::class, fn (JobReleasedAfterException $e) => $this->recorder->jobReleased($e));
    }
}
