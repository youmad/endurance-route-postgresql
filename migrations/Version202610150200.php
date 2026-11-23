<?php

declare(strict_types=1);

namespace Endurance\RoutePostgresql\Migrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version202610150200 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Immutable route revisions, bounded streams and retained references';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'Endurance Route storage supports PostgreSQL only.');
        $this->addSql(<<<'SQL'
            CREATE TABLE route_identities (
                id UUID PRIMARY KEY
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE route_revisions (
                id UUID PRIMARY KEY,
                route_id UUID NOT NULL REFERENCES route_identities(id) ON DELETE CASCADE,
                state TEXT NOT NULL CHECK (state IN ('staging','published','purged')),
                write_token UUID,
                touched_at TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp(),
                created_at TIMESTAMPTZ,
                properties JSONB,
                summary JSONB,
                reported_distance_m DOUBLE PRECISION,
                UNIQUE (route_id,id),
                CHECK (reported_distance_m IS NULL OR (reported_distance_m >= 0 AND reported_distance_m <
                    'Infinity'::float8)),
                CHECK ((state='staging' AND write_token IS NOT NULL AND created_at IS NULL AND properties IS NULL
                    AND summary IS NULL AND reported_distance_m IS NULL) OR (state='published' AND write_token IS
                    NULL AND created_at IS NOT NULL AND properties IS NOT NULL AND jsonb_typeof(properties)='object'
                    AND summary IS NOT NULL AND jsonb_typeof(summary)='object') OR (state='purged' AND write_token
                    IS NULL AND created_at IS NULL AND properties IS NULL AND summary IS NULL AND
                    reported_distance_m IS NULL))
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX route_staging_age ON route_revisions(touched_at,id) WHERE state='staging'
            SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX route_revisions_route ON route_revisions(route_id,id)
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE routes (
                id UUID PRIMARY KEY REFERENCES route_identities(id),
                name TEXT,
                description TEXT,
                created_at TIMESTAMPTZ NOT NULL,
                updated_at TIMESTAMPTZ NOT NULL CHECK(updated_at >= created_at),
                current_revision_id UUID NOT NULL,
                version BIGINT NOT NULL CHECK(version > 0),
                FOREIGN KEY(id,current_revision_id) REFERENCES route_revisions(route_id,id)
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE route_geometry_points (
                revision_id UUID NOT NULL REFERENCES route_revisions(id) ON DELETE CASCADE,
                point_index BIGINT NOT NULL CHECK(point_index >= 0),
                part_index BIGINT NOT NULL CHECK(part_index >= 0),
                latitude DOUBLE PRECISION NOT NULL CHECK(latitude BETWEEN -90 AND 90),
                longitude DOUBLE PRECISION NOT NULL CHECK(longitude BETWEEN -180 AND 180),
                elevation_m DOUBLE PRECISION,
                elevation_origin TEXT,
                elevation_source TEXT,
                elevation_datum TEXT,
                CHECK ((elevation_m IS NULL AND elevation_origin IS NULL AND elevation_source IS NULL AND
                    elevation_datum IS NULL) OR (elevation_m IS NOT NULL AND elevation_m > '-Infinity'::float8 AND
                    elevation_m < 'Infinity'::float8 AND elevation_origin IS NOT NULL AND elevation_origin IN
                    ('reported','derived','unknown'))),
                CHECK(elevation_source IS NULL OR btrim(elevation_source) <> ''),
                CHECK(elevation_datum IS NULL OR btrim(elevation_datum) <> ''),
                PRIMARY KEY(revision_id,part_index,point_index)
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE route_via_points (
                revision_id UUID NOT NULL REFERENCES route_revisions(id) ON DELETE CASCADE,
                point_index BIGINT NOT NULL CHECK(point_index >= 0),
                latitude DOUBLE PRECISION NOT NULL CHECK(latitude BETWEEN -90 AND 90),
                longitude DOUBLE PRECISION NOT NULL CHECK(longitude BETWEEN -180 AND 180),
                elevation_m DOUBLE PRECISION,
                elevation_origin TEXT,
                elevation_source TEXT,
                elevation_datum TEXT,
                CHECK ((elevation_m IS NULL AND elevation_origin IS NULL AND elevation_source IS NULL AND
                    elevation_datum IS NULL) OR (elevation_m IS NOT NULL AND elevation_m > '-Infinity'::float8 AND
                    elevation_m < 'Infinity'::float8 AND elevation_origin IS NOT NULL AND elevation_origin IN
                    ('reported','derived','unknown'))),
                CHECK(elevation_source IS NULL OR btrim(elevation_source) <> ''),
                CHECK(elevation_datum IS NULL OR btrim(elevation_datum) <> ''),
                PRIMARY KEY(revision_id,point_index)
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE route_waypoints (
                revision_id UUID NOT NULL REFERENCES route_revisions(id) ON DELETE CASCADE,
                point_index BIGINT NOT NULL CHECK(point_index >= 0),
                latitude DOUBLE PRECISION NOT NULL CHECK(latitude BETWEEN -90 AND 90),
                longitude DOUBLE PRECISION NOT NULL CHECK(longitude BETWEEN -180 AND 180),
                name TEXT,
                elevation_m DOUBLE PRECISION,
                elevation_origin TEXT,
                elevation_source TEXT,
                elevation_datum TEXT,
                CHECK ((elevation_m IS NULL AND elevation_origin IS NULL AND elevation_source IS NULL AND
                    elevation_datum IS NULL) OR (elevation_m IS NOT NULL AND elevation_m > '-Infinity'::float8 AND
                    elevation_m < 'Infinity'::float8 AND elevation_origin IS NOT NULL AND elevation_origin IN
                    ('reported','derived','unknown'))),
                CHECK(elevation_source IS NULL OR btrim(elevation_source) <> ''),
                CHECK(elevation_datum IS NULL OR btrim(elevation_datum) <> ''),
                anchor_part BIGINT,
                anchor_point BIGINT,
                anchor_via BIGINT,
                CHECK ((anchor_part IS NULL AND anchor_point IS NULL) OR (anchor_part IS NOT NULL AND anchor_point
                    IS NOT NULL AND anchor_part >= 0 AND anchor_point >= 0)),
                CHECK (anchor_via IS NULL OR (anchor_via >= 0 AND anchor_part IS NULL AND anchor_point IS NULL)),
                FOREIGN KEY(revision_id,anchor_part,anchor_point) REFERENCES
                    route_geometry_points(revision_id,part_index,point_index),
                FOREIGN KEY(revision_id,anchor_via) REFERENCES route_via_points(revision_id,point_index),
                PRIMARY KEY(revision_id,point_index)
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE route_cues (
                revision_id UUID NOT NULL REFERENCES route_revisions(id) ON DELETE CASCADE,
                point_index BIGINT NOT NULL CHECK(point_index >= 0),
                instruction TEXT NOT NULL CHECK(btrim(instruction) <> ''),
                latitude DOUBLE PRECISION,
                longitude DOUBLE PRECISION,
                CHECK((latitude IS NULL AND longitude IS NULL) OR (latitude IS NOT NULL AND longitude IS NOT NULL
                    AND latitude BETWEEN -90 AND 90 AND longitude BETWEEN -180 AND 180)),
                anchor_part BIGINT,
                anchor_point BIGINT,
                anchor_via BIGINT,
                CHECK ((anchor_part IS NULL AND anchor_point IS NULL) OR (anchor_part IS NOT NULL AND anchor_point
                    IS NOT NULL AND anchor_part >= 0 AND anchor_point >= 0)),
                CHECK (anchor_via IS NULL OR (anchor_via >= 0 AND anchor_part IS NULL AND anchor_point IS NULL)),
                FOREIGN KEY(revision_id,anchor_part,anchor_point) REFERENCES
                    route_geometry_points(revision_id,part_index,point_index),
                FOREIGN KEY(revision_id,anchor_via) REFERENCES route_via_points(revision_id,point_index),
                PRIMARY KEY(revision_id,point_index)
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE route_revision_references (
                reference_key TEXT PRIMARY KEY CHECK(btrim(reference_key) <> ''),
                revision_id UUID NOT NULL REFERENCES route_revisions(id)
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX route_references_revision ON route_revision_references(revision_id)
            SQL);
        $this->addSql(<<<'SQL'
            CREATE FUNCTION guard_route_revision() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
             IF TG_OP='DELETE' THEN
              IF OLD.state <> 'staging' THEN RAISE EXCEPTION 'Published revision identity cannot be deleted' USING ERRCODE='23514'; END IF;
              RETURN OLD;
             END IF;
             IF NEW.id <> OLD.id OR NEW.route_id <> OLD.route_id OR OLD.state='purged' THEN
              RAISE EXCEPTION 'Revision identity is immutable' USING ERRCODE='23514';
             END IF;
             IF OLD.state='published' THEN
              IF NEW.state <> 'purged' THEN RAISE EXCEPTION 'Published revision is immutable' USING ERRCODE='23514'; END IF;
              IF EXISTS(SELECT 1 FROM routes WHERE current_revision_id=OLD.id) OR EXISTS(SELECT 1 FROM route_revision_references WHERE revision_id=OLD.id) THEN
               RAISE EXCEPTION 'Revision is retained' USING ERRCODE='23503';
              END IF;
             END IF;
             RETURN NEW;
            END $$
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TRIGGER route_revision_guard
                BEFORE UPDATE OR DELETE ON route_revisions
                FOR EACH ROW EXECUTE FUNCTION guard_route_revision()
            SQL);
        $this->addSql(<<<'SQL'
            CREATE FUNCTION guard_route_content() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE parent_state TEXT;
            BEGIN
             IF TG_OP='UPDATE' AND NEW.revision_id <> OLD.revision_id THEN RAISE EXCEPTION 'Content cannot change revision' USING ERRCODE='23514'; END IF;
             SELECT state INTO parent_state FROM route_revisions WHERE id=CASE WHEN TG_OP='DELETE' THEN OLD.revision_id ELSE NEW.revision_id END FOR SHARE;
             IF parent_state='staging' THEN RETURN CASE WHEN TG_OP='DELETE' THEN OLD ELSE NEW END; END IF;
             IF TG_OP='DELETE' AND (parent_state IS NULL OR parent_state='purged') THEN RETURN OLD; END IF;
             RAISE EXCEPTION 'Only staging content is writable' USING ERRCODE='23514';
            END $$
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TRIGGER route_content_guard
                BEFORE INSERT OR UPDATE OR DELETE ON route_geometry_points
                FOR EACH ROW EXECUTE FUNCTION guard_route_content()
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TRIGGER route_content_guard
                BEFORE INSERT OR UPDATE OR DELETE ON route_via_points
                FOR EACH ROW EXECUTE FUNCTION guard_route_content()
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TRIGGER route_content_guard
                BEFORE INSERT OR UPDATE OR DELETE ON route_waypoints
                FOR EACH ROW EXECUTE FUNCTION guard_route_content()
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TRIGGER route_content_guard
                BEFORE INSERT OR UPDATE OR DELETE ON route_cues
                FOR EACH ROW EXECUTE FUNCTION guard_route_content()
            SQL);
        $this->addSql(<<<'SQL'
            CREATE FUNCTION require_published_route_revision() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE revision_state TEXT; parent_id UUID; target_id UUID;
            BEGIN
             IF TG_TABLE_NAME='routes' THEN target_id=NEW.current_revision_id; ELSE target_id=NEW.revision_id; END IF;
             SELECT state,route_id INTO revision_state,parent_id FROM route_revisions WHERE id=target_id FOR SHARE;
             IF revision_state IS DISTINCT FROM 'published' THEN RAISE EXCEPTION 'A published revision is required' USING ERRCODE='23503'; END IF;
             IF TG_TABLE_NAME='routes' THEN
              IF parent_id <> NEW.id THEN RAISE EXCEPTION 'Revision belongs to another route' USING ERRCODE='23503'; END IF;
             END IF;
             RETURN NEW;
            END $$
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TRIGGER route_published_required
                BEFORE INSERT OR UPDATE ON routes
                FOR EACH ROW EXECUTE FUNCTION require_published_route_revision()
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TRIGGER route_published_required
                BEFORE INSERT OR UPDATE ON route_revision_references
                FOR EACH ROW EXECUTE FUNCTION require_published_route_revision()
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'Endurance Route storage supports PostgreSQL only.');
        $this->abortIf((bool) $this->connection->fetchOne('SELECT EXISTS(SELECT 1 FROM route_revisions)'), 'Route data must be explicitly erased before removing this schema.');
        $this->addSql('DROP TABLE route_revision_references, route_cues, route_waypoints, route_via_points, route_geometry_points, routes, route_revisions, route_identities');
        $this->addSql('DROP FUNCTION require_published_route_revision(), guard_route_content(), guard_route_revision()');
    }
}
