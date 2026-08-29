<?php

// One definition, two names -- see the `redis` entry under `connections` for
// why. Defined once so the two can never drift apart.
$databaseQueue = [
    'driver' => 'database',
    'connection' => env('DB_QUEUE_CONNECTION'),
    'table' => env('DB_QUEUE_TABLE', 'jobs'),
    'queue' => env('DB_QUEUE', 'default'),
    // 90s is far below the engine timeouts (RunEngineChainJob 3600s,
    // RecomputeAllJob 7200s), so a compensation job goes "available" again
    // while still running. Safe only because the compensation worker is
    // pinned to ONE process (runbook 1.9 / R-61): a second process would
    // re-reserve it and the ledger would take concurrent writes. Raising
    // this instead would delay the retry of every crashed OTP job by the
    // same amount, so the single-worker rule is the guard, not this value.
    'retry_after' => (int) env('DB_QUEUE_RETRY_AFTER', 90),
    'after_commit' => false,
];

return [

    /*
    |--------------------------------------------------------------------------
    | Default Queue Connection Name
    |--------------------------------------------------------------------------
    |
    | Laravel's queue supports a variety of backends via a single, unified
    | API, giving you convenient access to each backend using identical
    | syntax for each. The default queue connection is defined below.
    |
    */

    'default' => env('QUEUE_CONNECTION', 'database'),

    /*
    |--------------------------------------------------------------------------
    | Queue Connections
    |--------------------------------------------------------------------------
    |
    | Here you may configure the connection options for every queue backend
    | used by your application. An example configuration is provided for
    | each backend supported by Laravel. You're also free to add more.
    |
    | Drivers: "sync", "database", "beanstalkd", "sqs", "redis",
    |          "deferred", "background", "failover", "null"
    |
    */

    'connections' => [

        'sync' => [
            'driver' => 'sync',
        ],

        'database' => $databaseQueue,

        // Cloudways: same connection, forced name. Its Supervisord Jobs panel
        // has a read-only "Connection Driver" of `redis`, so every worker it
        // manages is launched as `queue:work redis ...` and there is no way to
        // make it say `database`. That argument is a NAME looked up here, not
        // the Redis server -- so the name stays and the driver underneath is
        // the database. The worker Cloudways starts drains the `jobs` table.
        //
        // This is deliberate. The server's Redis is shared with eight other
        // apps under `maxmemory-policy allkeys-lfu`, which may evict a queued
        // job silently; on the compensation path that is a distributor not
        // credited with nothing anywhere saying so. The queue therefore lives
        // in MySQL on every environment, and `QUEUE_CONNECTION` stays
        // `database` -- this alias only exists so the Cloudways worker reaches
        // the same table. CACHE_STORE and SESSION_DRIVER are unaffected; they
        // still use Redis. See docs/runbooks/cloudways-deployment.md 1.9 and
        // QueueRoutingTest, which fails if this is ever "fixed" back.
        'redis' => $databaseQueue,

        'beanstalkd' => [
            'driver' => 'beanstalkd',
            'host' => env('BEANSTALKD_QUEUE_HOST', 'localhost'),
            'queue' => env('BEANSTALKD_QUEUE', 'default'),
            'retry_after' => (int) env('BEANSTALKD_QUEUE_RETRY_AFTER', 90),
            'block_for' => 0,
            'after_commit' => false,
        ],

        'sqs' => [
            'driver' => 'sqs',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'prefix' => env('SQS_PREFIX', 'https://sqs.us-east-1.amazonaws.com/your-account-id'),
            'queue' => env('SQS_QUEUE', 'default'),
            'suffix' => env('SQS_SUFFIX'),
            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
            'after_commit' => false,
        ],

        'deferred' => [
            'driver' => 'deferred',
        ],

        'background' => [
            'driver' => 'background',
        ],

        'failover' => [
            'driver' => 'failover',
            'connections' => [
                'database',
                'deferred',
            ],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Job Batching
    |--------------------------------------------------------------------------
    |
    | The following options configure the database and table that store job
    | batching information. These options can be updated to any database
    | connection and table which has been defined by your application.
    |
    */

    'batching' => [
        'database' => env('DB_CONNECTION', 'sqlite'),
        'table' => 'job_batches',
    ],

    /*
    |--------------------------------------------------------------------------
    | Failed Queue Jobs
    |--------------------------------------------------------------------------
    |
    | These options configure the behavior of failed queue job logging so you
    | can control how and where failed jobs are stored. Laravel ships with
    | support for storing failed jobs in a simple file or in a database.
    |
    | Supported drivers: "database-uuids", "dynamodb", "file", "null"
    |
    */

    'failed' => [
        'driver' => env('QUEUE_FAILED_DRIVER', 'database-uuids'),
        'database' => env('DB_CONNECTION', 'sqlite'),
        'table' => 'failed_jobs',
    ],

];
