<?php

declare(strict_types=1);

namespace Qiick\Audit\Support;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseTransactionRecord;
use Illuminate\Database\DatabaseTransactionsManager;
use RuntimeException;

/**
 * Schedules erasure notifications on the audit connection's own commit.
 *
 * @internal
 */
final class AuditCommitService
{
    /** @param Closure(): mixed $dispatch */
    public static function afterCommit(Connection $connection, Closure $dispatch): void
    {
        // Connection::afterCommit() registers on the newest transaction, which
        // may belong to another connection opened during erasure.
        /** @var DatabaseTransactionsManager $transactions */
        $transactions = app('db.transactions');

        $auditTransaction = $transactions->callbackApplicableTransactions()
            ->last(fn (DatabaseTransactionRecord $transaction): bool => $transaction->connection === $connection->getName())
            ?? throw new RuntimeException('The erasure transaction is not tracked by the transactions manager.');
        $auditTransaction->addCallback($dispatch);
    }
}
