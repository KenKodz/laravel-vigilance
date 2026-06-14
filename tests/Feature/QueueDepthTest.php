<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Vigilance\Metrics\QueueDepth;

uses(RefreshDatabase::class);

/**
 * Bind a fake queue manager so QueueDepth's beanstalkd/sqs paths resolve our
 * stub connection objects instead of real driver clients.
 *
 * @param  array<string, object>  $connections
 */
function bindFakeQueueManager(array $connections): void
{
    app()->instance('queue', new class($connections)
    {
        /** @param array<string, object> $c */
        public function __construct(public array $c) {}

        public function connection($name = null)
        {
            return $this->c[$name] ?? null;
        }
    });
}

it('reads database queue depth from unreserved rows', function () {
    config()->set('queue.connections.db_test', ['driver' => 'database', 'table' => 'jobs', 'connection' => 'testing']);

    Schema::connection('testing')->create('jobs', function ($t) {
        $t->id();
        $t->string('queue');
        $t->longText('payload');
        $t->unsignedTinyInteger('attempts')->default(0);
        $t->unsignedInteger('reserved_at')->nullable();
        $t->unsignedInteger('available_at')->default(0);
        $t->unsignedInteger('created_at')->default(0);
    });

    DB::connection('testing')->table('jobs')->insert([
        ['queue' => 'emails', 'payload' => '{}', 'reserved_at' => null],
        ['queue' => 'emails', 'payload' => '{}', 'reserved_at' => null],
        ['queue' => 'emails', 'payload' => '{}', 'reserved_at' => 123], // reserved → excluded
        ['queue' => 'other', 'payload' => '{}', 'reserved_at' => null],
    ]);

    expect((new QueueDepth)->for('db_test', 'emails'))->toBe(2);
});

it('reads redis queue depth via LLEN', function () {
    config()->set('queue.connections.redis_test', ['driver' => 'redis', 'connection' => 'default']);

    $conn = Mockery::mock();
    $conn->shouldReceive('llen')->with('queues:emails')->andReturn(5);
    Redis::shouldReceive('connection')->with('default')->andReturn($conn);

    expect((new QueueDepth)->for('redis_test', 'emails'))->toBe(5);
});

it('reads beanstalkd queue depth via stats-tube current-jobs-ready', function () {
    config()->set('queue.connections.bean_test', ['driver' => 'beanstalkd']);

    $pheanstalk = new class
    {
        public function statsTube($tube)
        {
            return (object) ['currentJobsReady' => 7];
        }
    };

    bindFakeQueueManager(['bean_test' => new class($pheanstalk)
    {
        public function __construct(public $p) {}

        public function getPheanstalk()
        {
            return $this->p;
        }
    }]);

    expect((new QueueDepth)->for('bean_test', 'emails'))->toBe(7);
});

it('reads sqs queue depth via ApproximateNumberOfMessages', function () {
    config()->set('queue.connections.sqs_test', ['driver' => 'sqs']);

    $client = new class
    {
        public function getQueueAttributes($args)
        {
            return ['Attributes' => ['ApproximateNumberOfMessages' => '12']];
        }
    };

    bindFakeQueueManager(['sqs_test' => new class($client)
    {
        public function __construct(public $c) {}

        public function getSqs()
        {
            return $this->c;
        }

        public function getQueue($queue)
        {
            return 'http://localhost/'.$queue;
        }
    }]);

    expect((new QueueDepth)->for('sqs_test', 'emails'))->toBe(12);
});

it('returns null for non-supervisable or unknown drivers', function () {
    config()->set('queue.connections.sync_test', ['driver' => 'sync']);

    expect((new QueueDepth)->for('sync_test', 'x'))->toBeNull()
        ->and((new QueueDepth)->for('does_not_exist', 'x'))->toBeNull();
});
