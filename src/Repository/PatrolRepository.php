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

namespace Uhifadhi\Patrol\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Entity\Zone;
use Uhifadhi\Patrol\Entity\Patrol;
use Uhifadhi\Patrol\Entity\PatrolType;
use Uhifadhi\Patrol\Enum\PatrolStatusEnum;

/**
 * @extends ServiceEntityRepository<Patrol>
 */
final class PatrolRepository extends ServiceEntityRepository
{
    /**
     * The tolerance the covered-ground shape is simplified to before it travels,
     * in degrees — the unit the geometry column is stored in.
     *
     * 0.0001° is roughly 11 m at the equator, well under a screen pixel at the
     * zoom a whole area is read at, so the drawn edge is unchanged while the
     * payload loses the vertices a buffered curve spends on being round.
     */
    private const string SIMPLIFY_DEGREES = '0.0001';

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
     * WHETHER THIS AREA PATROLS AT ALL — one row is enough, so this asks for one
     * row rather than counting a history that can run to thousands.
     *
     * It is the difference between "nothing happened today" and "nothing has
     * ever happened here", which is the difference between a zero the overview
     * may print and an absence it must not dress up as one.
     */
    public function areaHasAnyPatrol(AreaOfInterest $area): bool
    {
        return null !== $this->createQueryBuilder('p')
            ->select('p.id')
            ->andWhere('p.area = :area')
            ->setParameter('area', $area)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
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
     * The area's patrols that STARTED inside a half-open window, LATEST first —
     * what the dashboard's map, log and feed read now that they are scoped to the
     * month on screen (the MONTH filter), rather than to the whole all-time
     * history {@see self::findByAreaLatestFirst()} returned.
     *
     * The sibling {@see self::findByAreaStartedBetween()} orders EARLIEST first
     * for the calendar, which lays days out left to right; this one orders latest
     * first for the log, which shows the most recent patrols at the top and slices
     * the head. A patrol with no start date has no month to sit in and is left out
     * by the comparison itself — which is right for the map and log, where only a
     * finished patrol (always dated) can be drawn.
     *
     * @return list<Patrol>
     */
    public function findByAreaStartedBetweenLatestFirst(AreaOfInterest $area, \DateTimeImmutable $from, \DateTimeImmutable $until): array
    {
        /** @var list<Patrol> $patrols */
        $patrols = $this->createQueryBuilder('p')
            ->andWhere('p.area = :area')
            ->andWhere('p.startedAt >= :from')
            ->andWhere('p.startedAt < :until')
            ->setParameter('area', $area)
            ->setParameter('from', $from)
            ->setParameter('until', $until)
            ->orderBy('p.startedAt', 'DESC')
            ->addOrderBy('p.id', 'DESC')
            ->getQuery()
            ->getResult();

        return $patrols;
    }

    /**
     * THE ZONE EACH PATROL SET OUT IN — the spatial join that gives the dashboard
     * its ZONE filter without a stored zone field on the patrol.
     *
     * A patrol names the station it set off from and NO zone
     * (docs/design-decisions.md §1); the host owns the zone polygons
     * ({@see Zone}). So "which zone is this patrol in" is a spatial question,
     * answered here against the geometry rather than guessed from the station —
     * the same reasoning
     * {@see self::zoneAbsenceForArea()} states for measuring absence from tracks
     * and not from station names.
     *
     * ONE ZONE PER PATROL: its START POINT's zone, by ST_Covers — where the patrol
     * set out, the same evidence the coverage map places a station marker at
     * (PatrolDashboardService::coveragePayload). A track that wanders across a
     * boundary is filed under where it began, so a patrol appears under exactly
     * one zone in the filter and the by-zone grouping this unblocks stays a
     * partition. A hand-logged patrol has no track and no start point, so it is
     * absent from the map — unzoned, which the filter menu reads as "no zone".
     * On a shared edge ST_Covers is true for both zones; the join takes the lowest
     * zone id, so the assignment is deterministic rather than order-dependent.
     *
     * Scoped to the same half-open window the map and log read, so the join is
     * over exactly the patrols on screen and no more.
     *
     * KEYED BY UUID, not the sequential id — the module addresses a patrol by its
     * uuid everywhere it is public (a log row, a coverage track), so the map the
     * templates and the coverage payload look a patrol's zone up in is keyed the
     * same way (project convention: public addressing is by UUID).
     *
     * @return array<string, string> patrol uuid → zone name; a patrol whose start falls in no zone is absent
     */
    public function zonesForPatrols(AreaOfInterest $area, \DateTimeImmutable $from, \DateTimeImmutable $until): array
    {
        $entityManager = $this->getEntityManager();
        $patrol = $this->getClassMetadata();
        $zoneMeta = $entityManager->getClassMetadata(Zone::class);

        // DISTINCT ON (patrol) keeps one row per patrol — the lowest-id zone it
        // covers — so a start point sitting exactly on a shared edge (ST_Covers
        // true for both) resolves the same way every time.
        $sql = \sprintf(
            <<<'SQL'
                SELECT DISTINCT ON (p.%1$s) p.%2$s AS patrol_uuid, z.%3$s AS zone_name
                FROM %4$s p
                INNER JOIN %5$s z ON z.%6$s = :area AND ST_Covers(z.%7$s, ST_StartPoint(p.%8$s))
                WHERE p.%9$s = :area
                  AND p.%8$s IS NOT NULL
                  AND p.%10$s >= :from
                  AND p.%10$s < :until
                ORDER BY p.%1$s, z.%11$s
                SQL,
            $patrol->getSingleIdentifierColumnName(),
            $patrol->getColumnName('uuid'),
            $zoneMeta->getColumnName('name'),
            $patrol->getTableName(),
            $zoneMeta->getTableName(),
            $zoneMeta->getSingleAssociationJoinColumnName('area'),
            $zoneMeta->getColumnName('geom'),
            $patrol->getColumnName('track'),
            $patrol->getSingleAssociationJoinColumnName('area'),
            $patrol->getColumnName('startedAt'),
            $zoneMeta->getSingleIdentifierColumnName(),
        );

        /** @var list<array{patrol_uuid: string, zone_name: string}> $rows */
        $rows = $entityManager->getConnection()->fetchAllAssociative($sql, [
            'area' => $area->getId(),
            'from' => $from,
            'until' => $until,
        ], [
            'area' => Types::INTEGER,
            'from' => Types::DATETIME_IMMUTABLE,
            'until' => Types::DATETIME_IMMUTABLE,
        ]);

        $zones = [];
        foreach ($rows as $row) {
            // The uuid column comes back in Postgres's canonical hyphenated form,
            // which is exactly Uuid::toRfc4122() — the key the templates and the
            // coverage payload look a patrol up by.
            $zones[(string) $row['patrol_uuid']] = $row['zone_name'];
        }

        return $zones;
    }

    /**
     * WHO IS OUT RIGHT NOW — the area's patrols that have opened and not closed,
     * longest out first.
     *
     * `recording` IS "out": it is the status a patrol holds between the field
     * app opening it and the app completing it, which is exactly the window the
     * overview's live card describes. It is deliberately the one status the rest
     * of this module counts nothing from
     * ({@see PatrolStatusEnum::isPresentable()}) — an open patrol has no
     * duration and no distance total yet, and those fields are ABSENT rather
     * than zero.
     *
     * @return list<Patrol>
     */
    public function findByAreaRecording(AreaOfInterest $area): array
    {
        /** @var list<Patrol> $patrols */
        $patrols = $this->createQueryBuilder('p')
            ->andWhere('p.area = :area')
            ->andWhere('p.status = :recording')
            ->setParameter('area', $area)
            ->setParameter('recording', PatrolStatusEnum::Recording)
            ->orderBy('p.startedAt', 'ASC')
            ->addOrderBy('p.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $patrols;
    }

    /**
     * The area's patrols that CLOSED inside a half-open window — "6 closed
     * today", by the only column that says when a patrol was closed.
     *
     * By `endedAt` and not by `startedAt`, which is the sibling
     * {@see self::findByAreaStartedBetween()} the calendar reads. The two answer
     * different questions and are allowed to disagree: a night patrol that
     * opened at 20:00 and closed at 04:00 is yesterday's opening and today's
     * closing, and a card headed "closed today" that left it out because it
     * began before midnight would be quietly wrong about the night shift.
     *
     * Discarded patrols come back with the rest and are filtered by the caller
     * through {@see PatrolStatusEnum::countsTowardsStatistics()}, which is the
     * one predicate every "does this count" decision in this module goes
     * through.
     *
     * @return list<Patrol>
     */
    public function findByAreaEndedBetween(AreaOfInterest $area, \DateTimeImmutable $from, \DateTimeImmutable $until): array
    {
        /** @var list<Patrol> $patrols */
        $patrols = $this->createQueryBuilder('p')
            ->andWhere('p.area = :area')
            ->andWhere('p.endedAt >= :from')
            ->andWhere('p.endedAt < :until')
            ->setParameter('area', $area)
            ->setParameter('from', $from)
            ->setParameter('until', $until)
            ->orderBy('p.endedAt', 'ASC')
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
     * ONLY COMPLETE patrols reach the numerator, which is
     * {@see PatrolStatusEnum::countsTowardsStatistics()}
     * written in SQL. A DISCARDED patrol is out because a discard says the
     * effort did not happen as recorded, so buffering its track would report
     * ground nobody walked; a RECORDING one is out because its track has not
     * finished arriving, and buffering half a line reports ground nobody has
     * finished walking. The denominator is untouched by either — it is the
     * area's whole surface regardless of who walked what.
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
                  AND p.%s = :counted
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
            'counted' => PatrolStatusEnum::Complete->value,
            'from' => $from,
            'until' => $until,
        ], [
            'buffer' => Types::FLOAT,
            'area' => Types::INTEGER,
            'counted' => Types::STRING,
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
     * of tracks, and with DISCARDED and still-RECORDING patrols absent for the
     * same reasons they are absent from the area-wide figure.
     * Nothing about the AREA changes: the denominator is the whole
     * boundary, because the question is "how much of this place did they walk",
     * not "how much of their own patrolling was inside it". So a department's
     * figure is always ≤ the area's, and two departments walking different
     * ground both read less than the area does. That inequality is the reason
     * this method exists: handing every department the area-wide number would
     * credit each of them with ground only the others covered.
     *
     * THE SLICE is the module's one and only slice, the same one
     * {@see \Uhifadhi\Patrol\Module\PatrolDepartmentKpiProvider} counts
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
     * by this department's people in the window, or an area with no stored
     * boundary. Unknown coverage and zero coverage are different facts, and the
     * plate says the first with the design's em dash.
     *
     * THE DEPARTMENT ARRIVES AS AN ID, NOT AS AN ENTITY, and that is the whole
     * of what this query needs: the key `position.department_id` is compared
     * against. Departments belong to TeamBundle and NOTHING PUBLISHES
     * A CONTRACT FOR ONE, so a signature naming that bundle's class would make
     * every installation of this module install team's — for a value that is
     * one integer by the time it reaches SQL. The same reasoning the core's
     * KPI contract states for its own DepartmentRef, applied one layer down.
     */
    public function coverageFractionForDepartment(?AreaOfInterest $area, int $departmentId, float $bufferMetres, \DateTimeImmutable $from, \DateTimeImmutable $until): ?float
    {
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
                      AND p.%s = :counted
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
            'counted' => PatrolStatusEnum::Complete->value,
            'from' => $from,
            'until' => $until,
        ], [
            'buffer' => Types::FLOAT,
            'area' => Types::INTEGER,
            'department' => Types::INTEGER,
            'counted' => Types::STRING,
            // Bound as Doctrine types, not as pre-formatted strings, so the
            // window is written exactly the way the ORM wrote started_at.
            'from' => Types::DATETIME_IMMUTABLE,
            'until' => Types::DATETIME_IMMUTABLE,
        ]);

        return is_numeric($fraction) ? (float) $fraction : null;
    }

    /**
     * PL·A3 "Where nobody has been" — every zone of this area, worst first, with
     * the last track that ENTERED it and how much of it lies within
     * $bufferMetres of a track in the window.
     *
     * ABSENCE, NOT ACTIVITY, and measured from the last track that entered the
     * zone rather than from the last patrol that NAMED one. A patrol carries a
     * station and no zone at all (docs/design-decisions.md §1), so a station's
     * name is not evidence anybody crossed a particular polygon;
     * ST_Intersects against the host's zone geometry is. This is the module
     * asking the one generic spatial question it is allowed to ask of the host's
     * lens — it names no zone and stores none.
     *
     * TWO WINDOWS ON PURPOSE, and they are different questions. "Days since"
     * looks over ALL of history, because a zone nobody has entered for eleven
     * months is exactly the row this card exists to surface and a window would
     * hide it. The coverage share is scoped to $from/$until, because it is the
     * per-zone reading of PL·03 and must reconcile with the area-wide figure
     * printed under the table.
     *
     * ONLY COMPLETE PATROLS ARE EVIDENCE, for both columns and for the same
     * reason {@see self::coverageFractionWithin()} excludes the others: a
     * DISCARDED patrol says the effort did not happen as recorded, so its track
     * cannot prove somebody was there, and a RECORDING one's track has not
     * finished arriving.
     *
     * Null, never 0, where there is nothing to measure: a zone no track ever
     * entered has no last entry (`lastEnteredAt` and `lastPatrolId` are null,
     * and it sorts FIRST as the worst gap there is), and a window in which the
     * area recorded no track at all has no coverage share to state. Zero
     * coverage and unknown coverage are different facts.
     *
     * @return list<array{zone: string, zoneId: int, lastEnteredAt: \DateTimeImmutable|null, lastPatrolId: int|null, coverageFraction: float|null}>
     */
    public function zoneAbsenceForArea(AreaOfInterest $area, float $bufferMetres, \DateTimeImmutable $from, \DateTimeImmutable $until): array
    {
        $entityManager = $this->getEntityManager();
        $patrol = $this->getClassMetadata();
        $zoneMeta = $entityManager->getClassMetadata(Zone::class);

        // The covered surface is one geometry for the whole area, so it is
        // computed ONCE in a CTE and clipped per zone. Repeating the union
        // inside a per-zone lateral would buffer every track as many times as
        // the area has zones.
        $sql = \sprintf(
            <<<'SQL'
                WITH covered AS (
                    SELECT ST_Union(ST_Buffer(p.%1$s::geography, :buffer)::geometry) AS geom
                    FROM %2$s p
                    WHERE p.%3$s = :area
                      AND p.%1$s IS NOT NULL
                      AND p.%4$s = :counted
                      AND p.%5$s >= :from
                      AND p.%5$s < :until
                )
                SELECT z.%6$s AS zone_id,
                       z.%7$s AS zone_name,
                       entered.patrol_id AS last_patrol_id,
                       entered.%5$s AS last_entered_at,
                       ST_Area(ST_Intersection(covered.geom, z.%8$s)::geography)
                           / NULLIF(ST_Area(z.%8$s::geography), 0) AS fraction
                FROM %9$s z
                CROSS JOIN covered
                LEFT JOIN LATERAL (
                    SELECT p.%10$s AS patrol_id, p.%5$s
                    FROM %2$s p
                    WHERE p.%3$s = z.%11$s
                      AND p.%1$s IS NOT NULL
                      AND p.%4$s = :counted
                      AND ST_Intersects(p.%1$s, z.%8$s)
                    ORDER BY p.%5$s DESC NULLS LAST, p.%10$s DESC
                    LIMIT 1
                ) entered ON TRUE
                WHERE z.%11$s = :area
                ORDER BY entered.%5$s ASC NULLS FIRST, z.%7$s ASC
                SQL,
            $patrol->getColumnName('track'),
            $patrol->getTableName(),
            $patrol->getSingleAssociationJoinColumnName('area'),
            $patrol->getColumnName('status'),
            $patrol->getColumnName('startedAt'),
            $zoneMeta->getSingleIdentifierColumnName(),
            $zoneMeta->getColumnName('name'),
            $zoneMeta->getColumnName('geom'),
            $zoneMeta->getTableName(),
            $patrol->getSingleIdentifierColumnName(),
            $zoneMeta->getSingleAssociationJoinColumnName('area'),
        );

        /** @var list<array{zone_id: int|string, zone_name: string, last_patrol_id: int|string|null, last_entered_at: string|null, fraction: float|string|null}> $rows */
        $rows = $entityManager->getConnection()->fetchAllAssociative($sql, [
            'buffer' => $bufferMetres,
            'area' => $area->getId(),
            'counted' => PatrolStatusEnum::Complete->value,
            'from' => $from,
            'until' => $until,
        ], [
            'buffer' => Types::FLOAT,
            'area' => Types::INTEGER,
            'counted' => Types::STRING,
            'from' => Types::DATETIME_IMMUTABLE,
            'until' => Types::DATETIME_IMMUTABLE,
        ]);

        return array_map(static fn (array $row): array => [
            'zone' => $row['zone_name'],
            'zoneId' => (int) $row['zone_id'],
            'lastEnteredAt' => null === $row['last_entered_at'] ? null : new \DateTimeImmutable($row['last_entered_at']),
            'lastPatrolId' => null === $row['last_patrol_id'] ? null : (int) $row['last_patrol_id'],
            'coverageFraction' => is_numeric($row['fraction']) ? (float) $row['fraction'] : null,
        ], $rows);
    }

    /**
     * THE COVERAGE BUFFER AS GEOMETRY — the same set operation
     * {@see self::coverageFractionWithin()} measures, handed back as GeoJSON so
     * the operational plate can DRAW it instead of only printing its share.
     *
     * The map-legend contract is why this exists rather than the fraction being
     * reused: a layer in the legend has to be a layer on the plate, and "2 km
     * coverage buffer" is a legend entry the design ships. A percentage cannot
     * be drawn, and a legend entry with nothing behind it is a legend nobody can
     * rely on.
     *
     * Buffered in GEOGRAPHY so the distance is metres on the spheroid, unioned
     * so overlapping patrols are not double-drawn, and CLIPPED to the boundary —
     * the same three steps, in the same order, as the KPI, so the shape on the
     * map is exactly the shape the number was measured from. An area with no
     * stored boundary is not clipped, because there is nothing to clip to.
     *
     * Null — never an empty geometry — where no track was recorded in the
     * window: the layer then draws nothing and still ships its legend entry,
     * which is the honest form of "no coverage recorded this month".
     *
     * SIMPLIFIED FOR TRANSPORT, because a buffered track is a round shape and a
     * round shape is a great many vertices: a month's union reaches the page as
     * a large body of coordinates a screen cannot resolve. The simplification
     * is the topology-preserving one, which cannot hand back a torn or
     * self-intersecting polygon, at {@see self::SIMPLIFY_DEGREES} — well below
     * one screen pixel at the zoom an area is read at. A caller for which the
     * vertices themselves are the point asks for the whole shape.
     *
     * @see https://postgis.net/docs/ST_SimplifyPreserveTopology.html
     *
     * @param bool $simplify false for the geometry exactly as measured
     */
    public function coverageBufferGeoJson(AreaOfInterest $area, float $bufferMetres, \DateTimeImmutable $from, \DateTimeImmutable $until, bool $simplify = true): ?string
    {
        $entityManager = $this->getEntityManager();
        $patrol = $this->getClassMetadata();
        $areaMeta = $entityManager->getClassMetadata(AreaOfInterest::class);

        $typeMeta = $entityManager->getClassMetadata(PatrolType::class);

        /*
         * EACH TRACK AT ITS OWN TYPE'S WIDTH. How wide a patrol's track counts as
         * covered is a property of the TYPE — a walk sees a hundred and fifty metres
         * either side, a flight four hundred — so the distance is read per row and
         * the handed-in figure is what a type carrying none falls back on. One
         * union, still, so overlapping patrols of different widths are not
         * double-drawn.
         */
        $width = \sprintf('COALESCE(t.%s, :buffer)', $typeMeta->getColumnName('coverageBufferM'));

        $covered = \sprintf(
            'CASE WHEN a.%2$s IS NULL'
            ."\n     THEN ST_Union(ST_Buffer(p.%1\$s::geography, %3\$s)::geometry)"
            ."\n     ELSE ST_Intersection(ST_Union(ST_Buffer(p.%1\$s::geography, %3\$s)::geometry), a.%2\$s)"
            ."\nEND",
            $patrol->getColumnName('track'),
            $areaMeta->getColumnName('geom'),
            $width,
        );

        $sql = \sprintf(
            <<<'SQL'
                SELECT ST_AsGeoJSON(%9$s) AS geojson
                FROM %3$s a
                INNER JOIN %4$s p ON p.%5$s = a.%6$s
                LEFT JOIN %10$s t ON t.%11$s = p.%12$s
                WHERE a.%6$s = :area
                  AND p.%1$s IS NOT NULL
                  AND p.%7$s = :counted
                  AND p.%8$s >= :from
                  AND p.%8$s < :until
                GROUP BY a.%6$s, a.%2$s
                SQL,
            $patrol->getColumnName('track'),
            $areaMeta->getColumnName('geom'),
            $areaMeta->getTableName(),
            $patrol->getTableName(),
            $patrol->getSingleAssociationJoinColumnName('area'),
            $areaMeta->getSingleIdentifierColumnName(),
            $patrol->getColumnName('status'),
            $patrol->getColumnName('startedAt'),
            $simplify ? \sprintf('ST_SimplifyPreserveTopology(%s, %s)', $covered, self::SIMPLIFY_DEGREES) : $covered,
            $typeMeta->getTableName(),
            $typeMeta->getSingleIdentifierColumnName(),
            $patrol->getSingleAssociationJoinColumnName('patrolType'),
        );

        $geoJson = $entityManager->getConnection()->fetchOne($sql, [
            'buffer' => $bufferMetres,
            'area' => $area->getId(),
            'counted' => PatrolStatusEnum::Complete->value,
            'from' => $from,
            'until' => $until,
        ], [
            'buffer' => Types::FLOAT,
            'area' => Types::INTEGER,
            'counted' => Types::STRING,
            'from' => Types::DATETIME_IMMUTABLE,
            'until' => Types::DATETIME_IMMUTABLE,
        ]);

        return \is_string($geoJson) ? $geoJson : null;
    }
}
