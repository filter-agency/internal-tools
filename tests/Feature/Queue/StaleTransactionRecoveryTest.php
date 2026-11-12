<?php

use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\DB;

// No RefreshDatabase: its wrapping transaction would hide the stale-PDO state under test.

test('queue loop reconnects when PDO is stuck in a transaction Laravel does not know about', function () {
    $staleClient = DB::connection()->getPdo();
    // Simulates a DB restart mid-transaction: PDO still reports an open transaction, Laravel's level is 0.
    $staleClient->beginTransaction();

    event(new Looping('database', 'default'));

    $connection = DB::connection();
    expect($connection->getPdo())->not->toBe($staleClient)
        ->and($connection->getPdo()->inTransaction())->toBeFalse()
        ->and($connection->transaction(fn () => 'ok'))->toBe('ok');
});

test('queue loop leaves a healthy connection alone', function () {
    $client = DB::connection()->getPdo();

    event(new Looping('database', 'default'));

    expect(DB::connection()->getPdo())->toBe($client);
});
