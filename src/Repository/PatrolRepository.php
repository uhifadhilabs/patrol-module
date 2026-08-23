<?php

declare(strict_types=1);

/*
 * This file is part of the UhifadhiLabs Patrol Module.
 *
 * (c) Ezekiel Mjema <https://github.com/eemjema>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace UhifadhiLabs\Patrol\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;
use Uhifadhi\Entity\AreaOfInterest;
use UhifadhiLabs\Patrol\Entity\Patrol;

/**
 * @extends ServiceEntityRepository<Patrol>
 */
final class PatrolRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Patrol::class);
    }

    /**
     * The area's patrols, latest first.
     *
     * @return list<Patrol>
     */
    public function findByAreaLatestFirst(AreaOfInterest $area, ?int $limit = null): array
    {
        return $this->findBy(['area' => $area], ['startedAt' => 'DESC', 'id' => 'DESC'], $limit);
    }

    /**
     * The area's patrols that STARTED inside a half-open window, earliest first —
     * the calendar reads a month (its grid window, see
     * PatrolDashboardService::calendarRange) without loading the area's whole
     * history. Hand-logged patrols are ordinary rows and come with the rest; a
     * patrol with no start date has no day to sit on and is left out by the
     * comparison itself.
     *
     * @return list<Patrol>
     */
    public function findByAreaStartedBetween(AreaOfInterest $area, \DateTimeImmutable $from, \DateTimeImmutable $until): array
    {
        /** @var list<Patrol> $patrols */
        $patrols = $this->createQueryBuilder('p')
            ->andWhere('p.area = :area')
            ->andWhere('p.startedAt >= :from')
            ->andWhere('p.startedAt < :until')
            ->setParameter('area', $area)
            ->setParameter('from', $from)
            ->setParameter('until', $until)
            ->orderBy('p.startedAt', 'ASC')
            ->addOrderBy('p.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $patrols;
    }

    /**
     * PL·03 — the SHARE of the area's surface lying within $bufferMetres of any
     * track recorded in a half-open window, as a fraction of 1 (0.63 = 63 %).
     *
     * One PostGIS statement, because the answer is a set operation over the
     * whole month's geometry and nothing smaller is meaningful: buffer every
     * track, union the buffers (overlapping patrols must not be counted twice),
     * clip the union to the boundary, and divide the two geodesic areas.
     *
     * The buffer is taken in GEOGRAPHY, so 2 000 is 2 000 METRES on the
     * spheroid; buffering the 4326 geometry directly would take it in DEGREES,
     * which is neither 2 km nor even a constant distance across a country. Both
     * areas are measured the same way, for the same reason.
     *
     * Null — never 0.0 — where there is nothing to measure: no track recorded in
     * the window (a manual, sketch-less patrol has no geometry and contributes
     * nothing), or an area the host has stored no boundary for. Zero coverage
     * and unknown coverage are different facts, and the KPI states the second as
     * the design's em dash rather than as a suspiciously precise 0 %.
     */
    public function coverageFractionWithin(AreaOfInterest $area, float $bufferMetres, \DateTimeImmutable $from, \DateTimeImmutable $until): ?float
    {
        // Raw SQL (DQL has no ST_Buffer/ST_Union/ST_Intersection), so every
        // table and column is read from Doctrine's metadata rather than spelled
        // out: the host owns AreaOfInterest and may name its columns with a
        // different naming strategy than this bundle's tests do.
        $patrol = $this->getClassMetadata();
        $areaMeta = $this->getEntityManager()->getClassMetadata(AreaOfInterest::class);

        $sql = \sprintf(
            <<<'SQL'
                SELECT ST_Area(
                           ST_Intersection(
                               ST_Union(ST_Buffer(p.%s::geography, :buffer)::geometry),
                               a.%s
                           )::geography
                       ) / NULLIF(ST_Area(a.%s::geography), 0) AS fraction
                FROM %s a
                INNER JOIN %s p ON p.%s = a.%s
                WHERE a.%s = :area
                  AND a.%s IS NOT NULL
                  AND p.%s IS NOT NULL
                  AND p.%s >= :from
                  AND p.%s < :until
                GROUP BY a.%s, a.%s
                SQL,
            $patrol->getColumnName('track'),
            $areaGeom = $areaMeta->getColumnName('geom'),
            $areaGeom,
            $areaMeta->getTableName(),
            $patrol->getTableName(),
            $patrol->getSingleAssociationJoinColumnName('area'),
            $areaId = $areaMeta->getSingleIdentifierColumnName(),
            $areaId,
            $areaGeom,
            $patrol->getColumnName('track'),
            $startedAt = $patrol->getColumnName('startedAt'),
            $startedAt,
            $areaId,
            $areaGeom,
        );

        $fraction = $this->getEntityManager()->getConnection()->fetchOne($sql, [
            'buffer' => $bufferMetres,
            'area' => $area->getId(),
            'from' => $from,
            'until' => $until,
        ], [
            'buffer' => Types::FLOAT,
            'area' => Types::INTEGER,
            // Bound as Doctrine types, not as pre-formatted strings, so the
            // window is written exactly the way the ORM wrote started_at.
            'from' => Types::DATETIME_IMMUTABLE,
            'until' => Types::DATETIME_IMMUTABLE,
        ]);

        return is_numeric($fraction) ? (float) $fraction : null;
    }
}
