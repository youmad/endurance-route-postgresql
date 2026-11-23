<?php

declare(strict_types=1);

namespace Youmad\Endurance\RoutePostgresql;

use Youmad\Endurance\Foundation\ValueObject\Coordinate;
use Youmad\Endurance\Foundation\ValueObject\Instant;
use Youmad\Endurance\Geo\Elevation;
use Youmad\Endurance\Geo\ElevationOrigin;
use Youmad\Endurance\Geo\PathPoint;
use Youmad\Endurance\Geo\PathPosition;
use Youmad\Endurance\Geo\ViaPoint;
use Youmad\Endurance\Route\Entity\Route;
use Youmad\Endurance\Route\Entity\RouteRevision;
use Youmad\Endurance\Route\ValueObject\GeometryAnchor;
use Youmad\Endurance\Route\ValueObject\GeometryCoverage;
use Youmad\Endurance\Route\ValueObject\NavigationCue;
use Youmad\Endurance\Route\ValueObject\ReportedDistance;
use Youmad\Endurance\Route\ValueObject\RevisionProperties;
use Youmad\Endurance\Route\ValueObject\RevisionProvenance;
use Youmad\Endurance\Route\ValueObject\RouteContentSummary;
use Youmad\Endurance\Route\ValueObject\RouteId;
use Youmad\Endurance\Route\ValueObject\RouteMetadata;
use Youmad\Endurance\Route\ValueObject\RouteRevisionId;
use Youmad\Endurance\Route\ValueObject\RouteVersion;
use Youmad\Endurance\Route\ValueObject\RouteWaypoint;
use Youmad\Endurance\Route\ValueObject\ViaPointAnchor;

final class RouteReadProjection
{
    public static function cardColumns(string $alias): string
    {
        $prefix = self::qualifier($alias);

        return "{$prefix}id,{$prefix}name,{$prefix}description,{$prefix}created_at,{$prefix}updated_at,{$prefix}current_revision_id,{$prefix}version";
    }

    /** Optional distance_order is the native sortable value; distance_bits is the exact value for decoding. */
    public static function revisionColumns(string $alias, bool $includeDistanceOrder = false): string
    {
        $prefix = self::qualifier($alias);
        $distance = "{$prefix}reported_distance_m";

        return "{$prefix}id AS revision_id,{$prefix}route_id AS revision_route_id,{$prefix}created_at AS revision_created_at,"
            ."{$prefix}properties::text AS revision_properties,{$prefix}summary::text AS revision_summary,".self::numberColumn($distance, 'distance_bits')
            .($includeDistanceOrder ? ','.$distance.' AS distance_order' : '');
    }

    public static function geometryColumns(string $alias): string
    {
        $prefix = self::qualifier($alias);

        return "{$prefix}part_index,{$prefix}point_index,".self::coordinateColumns($prefix).','.self::elevationColumns($prefix);
    }

    public static function viaPointColumns(string $alias): string
    {
        $prefix = self::qualifier($alias);

        return "{$prefix}point_index,".self::coordinateColumns($prefix).','.self::elevationColumns($prefix);
    }

    public static function waypointColumns(string $alias): string
    {
        $prefix = self::qualifier($alias);

        return "{$prefix}point_index,{$prefix}name,".self::coordinateColumns($prefix).','.self::elevationColumns($prefix).','.self::anchorColumns($prefix);
    }

    public static function cueColumns(string $alias): string
    {
        $prefix = self::qualifier($alias);

        return "{$prefix}point_index,{$prefix}instruction,".self::coordinateColumns($prefix).','.self::anchorColumns($prefix);
    }

    /** @param array<string, mixed> $row */
    public static function readCard(array $row): Route
    {
        return new Route(
            RouteId::fromString($row['id']),
            new RouteMetadata($row['name'], $row['description']),
            self::instant($row['created_at']),
            self::instant($row['updated_at']),
            RouteRevisionId::fromString($row['current_revision_id']),
            new RouteVersion((int) $row['version']),
        );
    }

    /** @param array<string, mixed> $row */
    public static function readRevision(array $row): RouteRevision
    {
        $properties = json_decode($row['revision_properties'], true, flags: JSON_THROW_ON_ERROR);
        $summary = json_decode($row['revision_summary'], true, flags: JSON_THROW_ON_ERROR);
        $distance = self::numberFromBits($row['distance_bits']);

        return new RouteRevision(
            RouteRevisionId::fromString($row['revision_id']),
            RouteId::fromString($row['revision_route_id']),
            self::instant($row['revision_created_at']),
            new RevisionProperties(
                GeometryCoverage::from($properties['coverage']),
                null === $distance ? null : new ReportedDistance($distance),
                new RevisionProvenance(...$properties['provenance']),
            ),
            new RouteContentSummary(...$summary),
        );
    }

    /** @param array<string, mixed> $row */
    public static function readGeometryPoint(array $row): PathPoint
    {
        return new PathPoint(new PathPosition((int) $row['part_index'], (int) $row['point_index']), self::readCoordinate($row), self::readElevation($row));
    }

    /** @param array<string, mixed> $row */
    public static function readViaPoint(array $row): ViaPoint
    {
        return new ViaPoint((int) $row['point_index'], self::readCoordinate($row), self::readElevation($row));
    }

    /** @param array<string, mixed> $row */
    public static function readWaypoint(array $row): RouteWaypoint
    {
        return new RouteWaypoint((int) $row['point_index'], self::readCoordinate($row), $row['name'], self::readElevation($row), self::readAnchor($row));
    }

    /** @param array<string, mixed> $row */
    public static function readCue(array $row): NavigationCue
    {
        return new NavigationCue((int) $row['point_index'], $row['instruction'], null === $row['latitude_bits'] ? null : self::readCoordinate($row), self::readAnchor($row));
    }

    private static function coordinateColumns(string $prefix): string
    {
        return self::numberColumn($prefix.'latitude', 'latitude_bits').','.self::numberColumn($prefix.'longitude', 'longitude_bits');
    }

    private static function elevationColumns(string $prefix): string
    {
        return self::numberColumn($prefix.'elevation_m', 'elevation_bits').",{$prefix}elevation_origin,{$prefix}elevation_source,{$prefix}elevation_datum";
    }

    private static function anchorColumns(string $prefix): string
    {
        return "{$prefix}revision_id,{$prefix}anchor_part,{$prefix}anchor_point,{$prefix}anchor_via";
    }

    private static function numberColumn(string $column, string $alias): string
    {
        return "encode(float8send({$column}),'hex') AS {$alias}";
    }

    /** @param array<string, mixed> $row */
    private static function readCoordinate(array $row): Coordinate
    {
        $latitude = self::numberFromBits($row['latitude_bits']);
        $longitude = self::numberFromBits($row['longitude_bits']);
        if (null === $latitude || null === $longitude) {
            throw new \UnexpectedValueException('Stored Route point requires coordinates.');
        }

        return new Coordinate($latitude, $longitude);
    }

    /** @param array<string, mixed> $row */
    private static function readElevation(array $row): ?Elevation
    {
        $height = self::numberFromBits($row['elevation_bits']);

        return null === $height ? null : new Elevation($height, ElevationOrigin::from($row['elevation_origin']), $row['elevation_source'], $row['elevation_datum']);
    }

    /** @param array<string, mixed> $row */
    private static function readAnchor(array $row): GeometryAnchor|ViaPointAnchor|null
    {
        if (null === $row['anchor_part'] && null === $row['anchor_via']) {
            return null;
        }
        $revision = RouteRevisionId::fromString($row['revision_id']);

        return null === $row['anchor_part']
            ? new ViaPointAnchor($revision, (int) $row['anchor_via'])
            : new GeometryAnchor($revision, new PathPosition((int) $row['anchor_part'], (int) $row['anchor_point']));
    }

    private static function numberFromBits(?string $bits): ?float
    {
        if (null === $bits) {
            return null;
        }
        if (1 !== preg_match('/\\A[0-9a-f]{16}\\z/i', $bits)) {
            throw new \UnexpectedValueException('Invalid stored Route number.');
        }
        $value = unpack('Evalue', hex2bin($bits));
        if (false === $value) {
            throw new \UnexpectedValueException('Invalid stored Route number.');
        }

        return $value['value'];
    }

    private static function instant(string $value): Instant
    {
        return Instant::fromDateTimeImmutable(new \DateTimeImmutable($value));
    }

    private static function qualifier(string $alias): string
    {
        if (1 !== preg_match('/\\A[a-z_][a-z0-9_]*\\z/', $alias)) {
            throw new \InvalidArgumentException('Expected a simple Route projection table alias.');
        }

        return $alias.'.';
    }
}
