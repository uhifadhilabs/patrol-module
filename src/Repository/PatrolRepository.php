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
use Symfony\Component\Uid\Uuid;
use Uhifadhi\Entity\AreaOfInterest;
use Uhifadhi\Entity\Department;
use UhifadhiLabs\Patrol\Entity\Patrol;
use UhifadhiLabs\Patrol\Enum\PatrolStatusEnum;

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
     * The patrol a field device already created, if any — API-CONTRACT.md §4's
     * upsert key. A repeated create with a clientUuid we hold is answered with
     * the SAME patrol, which is the promise the app's retry loop depends on.
     */
    public function findOneByClientUuid(Uuid $clientUuid): ?Patrol
    {
        return $this->findOneBy(['clientUuid' => $clientUuid]);
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
     * Every discarded patrol whose retention clock is RUNNING — the purge
     * command's working set.
     *
     * Deliberately not filtered by age in SQL. "Older than the window" is
     * measured from {@see Patrol::discardedAt()}, which reads the last
     * `discarded` EVENT and falls back through `endedAt` to `createdAt`; that is
     * a three-way coalesce across a joined table, and expressing it here would
     * put the definition of "when it was discarded" in two places that could
     * drift. The set is small by construction — a deployment's undeleted
     * discards, minus the held ones — so the age test is done in PHP where it is
     * defined once and unit-testable.
     *
     * @return list<Patrol>
     */
    public function findDiscardedNotHeld(): array
    {
        /** @var list<Patrol> $patrols */
        $patrols = $this->createQueryBuilder('p')
            ->andWhere('p.status = :discarded')
            ->andWhere('p.heldAt IS NULL')
            ->setParameter('discarded', PatrolStatusEnum::Discarded)
            ->orderBy('p.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $patrols;
    }

    /**
     * PL·03 — the SHARE of the area's surface lying within $bufferMetres of any
     * track recorded in a half-open window, as a fraction of 1 (0.63 = 63 %).
     *
     * DISCARDED patrols are absent from the numerator: a discard says the effort
     * did not happen as recorded, so buffering its track would report ground
     * nobody walked. The denominator is untouched — it is the area's whole
     * surface either way.
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
                  AND p.%s <> :discarded
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
            $patrol->getColumnName('status'),
            $startedAt = $patrol->getColumnName('startedAt'),
            $startedAt,
            $areaId,
            $areaGeom,
        );

        $fraction = $this->getEntityManager()->getConnection()->fetchOne($sql, [
            'buffer' => $bufferMetres,
            'area' => $area->getId(),
            'discarded' => PatrolStatusEnum::Discarded->value,
            'from' => $from,
            'until' => $until,
        ], [
            'buffer' => Types::FLOAT,
            'area' => Types::INTEGER,
            'discarded' => Types::STRING,
            // Bound as Doctrine types, not as pre-formatted strings, so the
            // window is written exactly the way the ORM wrote started_at.
            'from' => Types::DATETIME_IMMUTABLE,
            'until' => Types::DATETIME_IMMUTABLE,
        ]);

        return is_numeric($fraction) ? (float) $fraction : null;
    }

    /**
     * PL·03 FOR ONE DEPARTMENT — the same share, measured over only the tracks
     * THIS DEPARTMENT'S PEOPLE recorded.
     *
     * Identical geometry to {@see self::coverageFractionWithin()} — buffer each
     * track in GEOGRAPHY so the distance is metres on the spheroid, union the
     * buffers so overlapping patrols are not counted twice, clip the union to
     * the boundary, divide the two geodesic areas — over a strictly smaller set
     * of tracks, and with DISCARDED patrols absent for the same reason they are
     * absent from the area-wide figure. Nothing about the AREA changes: the denominator is the whole
     * boundary, because the question is "how much of this place did they walk",
     * not "how much of their own patrolling was inside it". So a department's
     * figure is always ≤ the area's, and two departments walking different
     * ground both read less than the area does. That inequality is the reason
     * this method exists: handing every department the area-wide number would
     * credit each of them with ground only the others covered.
     *
     * THE SLICE is the module's one and only slice, the same one
     * {@see \UhifadhiLabs\Patrol\Module\PatrolDepartmentKpiProvider} counts
     * patrols by: patrol → lead → position → department. Three INNER JOINs
     * express in SQL exactly what that provider expresses in PHP, and a patrol
     * that fails any link — no lead, a lead with no position, a position filed
     * under no department — drops out of EVERY department's coverage rather
     * than being shared among them. It still counts towards the area's, which
     * is why the two figures are allowed to disagree.
     *
     * $area NULL asks across every area the department recorded a track in, as
     * ONE ratio: the covered surfaces summed over the boundary surfaces summed.
     * Coverage over two areas is not the sum of two coverages and not their
     * mean — it is a single share of a single larger surface, and the areas the
     * department never set foot in are absent from BOTH sums rather than
     * dragging the figure towards zero with boundaries nobody was asked about.
     *
     * Null — never 0.0 — where there is nothing to measure: no track recorded
     * by this department's people in the window, an area with no stored
     * boundary, or a department the host has not persisted yet. Unknown
     * coverage and zero coverage are different facts, and the plate says the
     * first with the design's em dash.
     */
    public function coverageFractionForDepartment(?AreaOfInterest $area, Department $department, float $bufferMetres, \DateTimeImmutable $from, \DateTimeImmutable $until): ?float
    {
        $departmentId = $department->getId();
        if (null === $departmentId) {
            return null;
        }

        $entityManager = $this->getEntityManager();
        $patrol = $this->getClassMetadata();
        $areaMeta = $entityManager->getClassMetadata(AreaOfInterest::class);
        // Walked from the mapping rather than named, for the same reason the
        // area-wide query reads its own columns from metadata: the host owns
        // User and Position and may map them with another naming strategy.
        $userMeta = $entityManager->getClassMetadata($patrol->getAssociationTargetClass('lead'));
        $positionMeta = $entityManager->getClassMetadata($userMeta->getAssociationTargetClass('position'));

        // Table names go through the quote strategy — unlike the columns, one of
        // them genuinely needs it: the host stores users in `"user"`, which is a
        // reserved word in PostgreSQL and unparseable bare.
        $platform = $entityManager->getConnection()->getDatabasePlatform();
        $quotes = $entityManager->getConfiguration()->getQuoteStrategy();

        // The per-area shares are computed in the subquery (one row per area,
        // because ST_Union is an aggregate) and folded into one ratio outside
        // it. With an area named, that is a single row and the fold is a no-op.
        $sql = \sprintf(
            <<<'SQL'
                SELECT SUM(covered) / NULLIF(SUM(total), 0) AS fraction
                FROM (
                    SELECT ST_Area(
                               ST_Intersection(
                                   ST_Union(ST_Buffer(p.%s::geography, :buffer)::geometry),
                                   a.%s
                               )::geography
                           ) AS covered,
                           ST_Area(a.%s::geography) AS total
                    FROM %s a
                    INNER JOIN %s p ON p.%s = a.%s
                    INNER JOIN %s u ON u.%s = p.%s
                    INNER JOIN %s pos ON pos.%s = u.%s
                    WHERE (CAST(:area AS INTEGER) IS NULL OR a.%s = CAST(:area AS INTEGER))
                      AND pos.%s = :department
                      AND a.%s IS NOT NULL
                      AND p.%s IS NOT NULL
                      AND p.%s <> :discarded
                      AND p.%s >= :from
                      AND p.%s < :until
                    GROUP BY a.%s, a.%s
                ) per_area
                SQL,
            $patrol->getColumnName('track'),
            $areaGeom = $areaMeta->getColumnName('geom'),
            $areaGeom,
            $quotes->getTableName($areaMeta, $platform),
            $quotes->getTableName($patrol, $platform),
            $patrol->getSingleAssociationJoinColumnName('area'),
            $areaId = $areaMeta->getSingleIdentifierColumnName(),
            // patrol → lead
            $quotes->getTableName($userMeta, $platform),
            $patrol->getSingleAssociationReferencedJoinColumnName('lead'),
            $patrol->getSingleAssociationJoinColumnName('lead'),
            // lead → position
            $quotes->getTableName($positionMeta, $platform),
            $userMeta->getSingleAssociationReferencedJoinColumnName('position'),
            $userMeta->getSingleAssociationJoinColumnName('position'),
            // position → department
            $areaId,
            $positionMeta->getSingleAssociationJoinColumnName('department'),
            $areaGeom,
            $patrol->getColumnName('track'),
            $patrol->getColumnName('status'),
            $startedAt = $patrol->getColumnName('startedAt'),
            $startedAt,
            $areaId,
            $areaGeom,
        );

        $fraction = $entityManager->getConnection()->fetchOne($sql, [
            'buffer' => $bufferMetres,
            'area' => $area?->getId(),
            'department' => $departmentId,
            'discarded' => PatrolStatusEnum::Discarded->value,
            'from' => $from,
            'until' => $until,
        ], [
            'buffer' => Types::FLOAT,
            'area' => Types::INTEGER,
            'department' => Types::INTEGER,
            'discarded' => Types::STRING,
            // Bound as Doctrine types, not as pre-formatted strings, so the
            // window is written exactly the way the ORM wrote started_at.
            'from' => Types::DATETIME_IMMUTABLE,
            'until' => Types::DATETIME_IMMUTABLE,
        ]);

        return is_numeric($fraction) ? (float) $fraction : null;
    }
}
