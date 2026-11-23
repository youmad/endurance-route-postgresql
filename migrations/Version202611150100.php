<?php

declare(strict_types=1);

namespace Endurance\RoutePostgresql\Migrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version202611150100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Enforce Route card versions and bind each head change to its revision publication.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'Endurance Route storage supports PostgreSQL only.');

        $this->addSql('ALTER TABLE route_revisions ADD COLUMN publication_xid TEXT, ADD COLUMN publication_version BIGINT');
        $this->addSql(<<<'SQL'
            CREATE FUNCTION stamp_route_revision_publication() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.state = 'published' THEN
                    -- Use the full outer transaction ID, including when the writer uses a savepoint.
                    NEW.publication_xid := pg_current_xact_id()::text;
                    NEW.publication_version := COALESCE((SELECT version FROM routes WHERE id=NEW.route_id), 0) + 1;
                ELSE
                    NEW.publication_xid := NULL;
                    NEW.publication_version := NULL;
                END IF;
                RETURN NEW;
            END $$
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TRIGGER route_revision_publication_stamp
                BEFORE INSERT OR UPDATE OF state, publication_xid, publication_version ON route_revisions
                FOR EACH ROW EXECUTE FUNCTION stamp_route_revision_publication()
            SQL);

        $this->addSql(<<<'SQL'
            CREATE FUNCTION guard_route_card_transition() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE revision_xid TEXT; revision_version BIGINT; revision_time TIMESTAMPTZ;
            BEGIN
                IF TG_OP = 'INSERT' THEN
                    IF NEW.version <> 1 OR NEW.created_at IS DISTINCT FROM NEW.updated_at THEN
                        RAISE EXCEPTION 'Route must start at version one with its publication time' USING ERRCODE='23514';
                    END IF;
                ELSE
                    IF NEW.id IS DISTINCT FROM OLD.id OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
                        RAISE EXCEPTION 'Route identity and creation time are immutable' USING ERRCODE='23514';
                    END IF;
                    IF NEW.version::numeric <> OLD.version::numeric + 1 THEN
                        RAISE EXCEPTION 'Route version must advance by one' USING ERRCODE='23514';
                    END IF;
                    IF NEW.current_revision_id = OLD.current_revision_id THEN
                        RETURN NULL;
                    END IF;
                    IF NEW.name IS DISTINCT FROM OLD.name OR NEW.description IS DISTINCT FROM OLD.description THEN
                        RAISE EXCEPTION 'Route revision replacement must preserve metadata' USING ERRCODE='23514';
                    END IF;
                END IF;

                SELECT publication_xid, publication_version, created_at INTO revision_xid, revision_version, revision_time
                    FROM route_revisions WHERE id=NEW.current_revision_id;
                IF revision_xid IS DISTINCT FROM pg_current_xact_id()::text OR revision_version IS DISTINCT FROM NEW.version THEN
                    RAISE EXCEPTION 'Route head requires publication for this version in the same transaction' USING ERRCODE='23514';
                END IF;
                IF revision_time IS DISTINCT FROM NEW.updated_at THEN
                    RAISE EXCEPTION 'Route head time must match its revision publication' USING ERRCODE='23514';
                END IF;
                RETURN NULL;
            END $$
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TRIGGER route_card_transition_guard
                AFTER INSERT OR UPDATE ON routes
                FOR EACH ROW EXECUTE FUNCTION guard_route_card_transition()
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'Endurance Route storage supports PostgreSQL only.');
        $this->addSql('DROP TRIGGER route_card_transition_guard ON routes');
        $this->addSql('DROP TRIGGER route_revision_publication_stamp ON route_revisions');
        $this->addSql('DROP FUNCTION guard_route_card_transition(), stamp_route_revision_publication()');
        $this->addSql('ALTER TABLE route_revisions DROP COLUMN publication_xid, DROP COLUMN publication_version');
    }
}
