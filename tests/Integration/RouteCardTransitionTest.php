<?php

declare(strict_types=1);

namespace Youmad\Endurance\RoutePostgresql\Tests\Integration;

use Doctrine\DBAL\Exception\DriverException;
use Youmad\Endurance\Foundation\Clock\FrozenClock;
use Youmad\Endurance\Foundation\ValueObject\Instant;
use Youmad\Endurance\Route\Application\UseCase\EditRouteMetadata;
use Youmad\Endurance\Route\Application\UseCase\PublishRoute;
use Youmad\Endurance\Route\ValueObject\RouteId;
use Youmad\Endurance\Route\ValueObject\RouteMetadata;
use Youmad\Endurance\Route\ValueObject\RouteRevisionId;
use Youmad\Endurance\RoutePostgresql\Tests\Support\StorageTestCase;

final class RouteCardTransitionTest extends StorageTestCase
{
    private const string PUBLICATION_TIME = '2026-10-15T13:49:56.123456Z';
    private const string MOVE_HEAD = 'UPDATE routes SET current_revision_id=?,updated_at=?,version=version+1 WHERE id=?';

    public function testRawCardEditsCannotBypassIdentityAndVersionChecks(): void
    {
        $card = $this->create();
        $id = $card->id->toString();
        $this->assertRejected("UPDATE routes SET name='Changed' WHERE id=?", [$id], 'Route version must advance by one');
        $this->assertRejected('UPDATE routes SET version=version+2 WHERE id=?', [$id], 'Route version must advance by one');
        $this->assertRejected("UPDATE routes SET version=version+1,created_at=created_at-interval '1 second' WHERE id=?", [$id], 'Route identity and creation time are immutable');

        $this->db->beginTransaction();
        try {
            $other = RouteId::generate()->toString();
            $this->db->insert('route_identities', ['id' => $other]);
            $revision = $this->publishDescriptor($other);
            $this->assertRejected(
                'UPDATE routes SET id=?,current_revision_id=?,updated_at=?,version=version+1 WHERE id=?',
                [$other, $revision, self::PUBLICATION_TIME, $id],
                'Route identity and creation time are immutable',
            );
            $this->assertRejected(
                'INSERT INTO routes(id,current_revision_id,created_at,updated_at,version) VALUES (?,?,?,?,2)',
                [$other, $revision, self::PUBLICATION_TIME, self::PUBLICATION_TIME],
                'Route must start at version one',
            );
        } finally {
            $this->db->rollBack();
        }
        self::assertEquals($card, $this->storage->get($card->id));
    }

    public function testMetadataAndNewRevisionCanCommitTogetherButCannotReattachAnEarlierHead(): void
    {
        $this->db->transactional(function (): void {
            $first = $this->create();
            $laterClock = new FrozenClock(Instant::fromDateTimeImmutable(new \DateTimeImmutable('2026-10-16T00:00:00Z')));
            $named = new EditRouteMetadata($this->storage, $laterClock)->edit($first->id, $first->expectation(), new RouteMetadata('Renamed'));
            self::assertTrue($first->currentRevisionId->equals($named->currentRevisionId));
            self::assertSame(2, $named->version->value);

            // The publishing clock may stand still or be behind the last metadata edit.
            $next = new PublishRoute($this->storage, $this->clock)->revise($first->id, $named->expectation(), RouteRevisionId::generate(), $this->properties(), $this->content(2));
            self::assertSame(3, $next->version->value);
            self::assertEquals($named->metadata, $next->metadata);
            $this->assertRejected(self::MOVE_HEAD, [$first->currentRevisionId->toString(), self::PUBLICATION_TIME, $first->id->toString()],
                'Route head requires publication for this version in the same transaction');
            self::assertEquals($next, $this->storage->get($first->id));
            self::assertCount(5, iterator_to_array($this->storage->geometry($first->currentRevisionId)));
        });
    }

    public function testDetachedPublishedRevisionCannotBeAttachedInALaterTransaction(): void
    {
        $card = $this->create();
        $revision = $this->publishDescriptor($card->id->toString());
        $this->assertRejected(self::MOVE_HEAD, [$revision, self::PUBLICATION_TIME, $card->id->toString()],
            'Route head requires publication for this version in the same transaction');
        $this->assertRejected('UPDATE route_revisions SET publication_xid=pg_current_xact_id()::text WHERE id=?', [$revision],
            'Published revision is immutable');
        self::assertEquals($card, $this->storage->get($card->id));
    }

    public function testFreshRevisionReplacementMustPreserveMetadataAndUseItsPublicationTime(): void
    {
        $card = $this->create();
        $this->db->transactional(function () use ($card): void {
            $id = $card->id->toString();
            $revision = $this->publishDescriptor($id);
            $this->assertRejected("UPDATE routes SET current_revision_id=?,updated_at=?,version=version+1,name='Changed' WHERE id=?",
                [$revision, self::PUBLICATION_TIME, $id], 'Route revision replacement must preserve metadata');
            $this->assertRejected(self::MOVE_HEAD, [$revision, '2026-10-16T00:00:00Z', $id], 'Route head time must match its revision publication');
            self::assertSame(1, $this->db->executeStatement(self::MOVE_HEAD, [$revision, self::PUBLICATION_TIME, $id]));
        });
        self::assertSame(2, $this->storage->get($card->id)->version->value);
    }

    private function publishDescriptor(string $route): string
    {
        $revision = RouteRevisionId::generate()->toString();
        $this->db->insert('route_revisions', ['id' => $revision, 'route_id' => $route, 'state' => 'published',
            'created_at' => self::PUBLICATION_TIME, 'properties' => '{}', 'summary' => '{}']);

        return $revision;
    }

    /** @param list<string> $parameters */
    private function assertRejected(string $sql, array $parameters, string $message): void
    {
        $this->db->beginTransaction();
        try {
            $this->db->executeStatement($sql, $parameters);
            self::fail('Unsupported SQL mutation succeeded.');
        } catch (DriverException $failure) {
            self::assertSame('23514', $failure->getSQLState());
            self::assertStringContainsString($message, $failure->getMessage());
        } finally {
            $this->db->rollBack();
        }
    }
}
