<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Tests\Unit\Infrastructure;

use Doctrine\DBAL\Driver\Exception as DriverExceptionInterface;
use Doctrine\DBAL\Exception\ConnectionLost;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Exception;
use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\TransactionDoctrine\ConflictCode;
use Nowo\TransactionDoctrine\Symfony\Conflict\TransientFailureConflictMapper;
use Nowo\TransactionDoctrine\TransientFailureKind;
use Nowo\TransactionDoctrine\WriteUnit\ConcurrencyConflict;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

/**
 * REQ-RUNTIME-003 rule 6 for the failures `notify()` and `forgetContext()` surface: the conflict code a host answers
 * (`CONFLICT_001` retry-safe with Retry-After, `CONFLICT_002` after a commit, no Retry-After), and what is never a
 * conflict (unique violations, lost connections). The bundle does not answer HTTP itself; the mapping is the
 * platform's, and this test pins the shapes the bundle can throw.
 */
final class ConflictMappingTest extends TestCase
{
    /**
     * @return iterable<string, array{Throwable, ConflictCode|null}>
     */
    public static function failures(): iterable
    {
        $deadlock = new DeadlockException(self::driver(1213, '40001'), null);

        yield 'transient failure of the runner, nothing written' => [TransientStorageFailure::fromPrevious($deadlock, 1), ConflictCode::RetrySafe];
        yield 'transient failure without a hint' => [new TransientStorageFailure(), ConflictCode::RetrySafe];
        yield 'raw deadlock no runner wrapped' => [$deadlock, ConflictCode::RetrySafe];
        yield 'conflict after a committed unit of the request' => [new ConcurrencyConflict(1, TransientFailureKind::Deadlock, null, $deadlock, true), ConflictCode::Committed];
        yield 'committed conflict wrapped by the host' => [new RuntimeException('host', 0, new ConcurrencyConflict(1, TransientFailureKind::Deadlock, null, $deadlock, true)), ConflictCode::Committed];
        yield 'conflict, nothing committed' => [new ConcurrencyConflict(3, TransientFailureKind::Deadlock, 1, $deadlock), ConflictCode::RetrySafe];
        yield 'lost savepoint, outcome unknown' => [new DriverException(self::driver(1305, '42000', 'SQLSTATE[42000]: 1305 SAVEPOINT DOCTRINE_2 does not exist'), null), ConflictCode::Committed];
        yield 'lost savepoint over its deadlock' => [new DriverException(self::driver(1305, '42000', 'SAVEPOINT DOCTRINE_2 does not exist', $deadlock), null), ConflictCode::RetrySafe];
    }

    #[DataProvider('failures')]
    public function testTheCodeOfEveryFailureShape(Throwable $failure, ?ConflictCode $expected): void
    {
        self::assertSame($expected, ConflictCode::forFailure($failure));
        self::assertTrue((new TransientFailureConflictMapper())->isConflict($failure), 'the host mapper answers it as a conflict');
    }

    public function testOnlyRetrySafeAnswersCarryARetryAfter(): void
    {
        self::assertTrue(ConflictCode::RetrySafe->retrySafe());
        self::assertFalse(ConflictCode::Committed->retrySafe());
        self::assertSame('CONFLICT_001', ConflictCode::RetrySafe->value);
        self::assertSame('CONFLICT_002', ConflictCode::Committed->value);
    }

    /**
     * @return iterable<string, array{Throwable}>
     */
    public static function notConflicts(): iterable
    {
        yield 'unique violation' => [new UniqueConstraintViolationException(self::driver(1062, '23000'), null)];
        yield 'lost connection' => [new ConnectionLost(self::driver(2006, 'HY000'), null)];
        yield 'plain failure' => [new RuntimeException('boom')];
    }

    #[DataProvider('notConflicts')]
    public function testUniqueViolationsLostConnectionsAndBugsAreNotConflicts(Throwable $failure): void
    {
        self::assertFalse((new TransientFailureConflictMapper())->isConflict($failure));
    }

    private static function driver(int $code, string $sqlState, string $message = 'driver error', ?Throwable $previous = null): DriverExceptionInterface
    {
        return new class($message, $code, $previous, $sqlState) extends Exception implements DriverExceptionInterface {
            public function __construct(string $message, int $code, ?Throwable $previous, private readonly string $sqlState)
            {
                parent::__construct($message, $code, $previous);
            }

            public function getSQLState(): string
            {
                return $this->sqlState;
            }
        };
    }
}
