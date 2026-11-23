<?php

declare(strict_types=1);

namespace Endurance\RoutePostgresql;

use Endurance\Foundation\ValueObject\Coordinate;
use Endurance\Foundation\ValueObject\Instant;
use Endurance\Geo\Elevation;
use Endurance\Route\Entity\Route;
use Endurance\Route\ValueObject\GeometryAnchor;
use Endurance\Route\ValueObject\RevisionProperties;
use Endurance\Route\ValueObject\RouteRevisionId;
use Endurance\Route\ValueObject\ViaPointAnchor;

/** @internal Values written to Route tables. */
final class RowCodec
{
    public static function number(?float $value): ?string
    {
        return null === $value ? null : sprintf('%.17h', $value);
    }

    public static function time(Instant $instant): string
    {
        return $instant->toDateTimeImmutable()->format('Y-m-d H:i:s.uP');
    }

    /** @return array<string, int|string|null> */
    public static function coordinate(?Coordinate $point): array
    {
        return ['latitude' => self::number($point?->latitude), 'longitude' => self::number($point?->longitude)];
    }

    /** @return array<string, string|null> */
    public static function elevation(?Elevation $height): array
    {
        return ['elevation_m' => self::number($height?->metres), 'elevation_origin' => $height?->origin->value, 'elevation_source' => $height?->source, 'elevation_datum' => $height?->verticalDatum];
    }

    /** @return array<string, int|null> */
    public static function anchor(GeometryAnchor|ViaPointAnchor|null $anchor, RouteRevisionId $id): array
    {
        if (null !== $anchor && !$anchor->revisionId->equals($id)) {
            throw new \InvalidArgumentException('Anchor must belong to the staged revision.');
        }

        return ['anchor_part' => $anchor instanceof GeometryAnchor ? $anchor->position->partIndex : null, 'anchor_point' => $anchor instanceof GeometryAnchor ? $anchor->position->pointIndex : null, 'anchor_via' => $anchor instanceof ViaPointAnchor ? $anchor->index : null];
    }

    /** @return array<string, string|int|null> */
    public static function card(Route $route): array
    {
        return ['id' => $route->id->toString(), 'name' => $route->metadata->name, 'description' => $route->metadata->description, 'created_at' => self::time($route->createdAt), 'updated_at' => self::time($route->updatedAt), 'current_revision_id' => $route->currentRevisionId->toString(), 'version' => $route->version->value];
    }

    public static function properties(RevisionProperties $properties): string
    {
        return json_encode(['coverage' => $properties->geometryCoverage->value, 'provenance' => get_object_vars($properties->provenance)], JSON_THROW_ON_ERROR);
    }
}
