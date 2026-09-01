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
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Persistence\ManagerRegistry;
use UhifadhiLabs\Patrol\Entity\TrackPoint;

/**
 * @extends ServiceEntityRepository<TrackPoint>
 */
final class TrackPointRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TrackPoint::class);
    }

    /**
     * WHEN EACH PATROL WAS LAST HEARD FROM, AND THE TRAIL IT HAS LEFT.
     *
     * THERE IS NO LAST-PING COLUMN, deliberately. A patrol is heard from when a
     * track point arrives, so "last ping" is MAX(recorded_at) over its points
     * and the ping itself is that row's position. A stored last_seen field would
     * be a second copy of a fact the points already state, and the day the sync
     * forgot to update it the strip would report a patrol as silent that was
     * pinging every minute.
     *
     * THE TRAIL COMES BACK IN THE SAME PASS because a live patrol drawn on the
     * operational plate is the line its points make: a RECORDING patrol's
     * `track` is null until it closes (a half-finished line is not a claim about
     * ground covered, see PatrolDashboardService), so the live layer has nothing
     * else to draw. Asking twice would let the line and the ring that heads it
     * be measured a query apart.
     *
     * ORDERED BY WHEN A POINT WAS RECORDED, never by when it arrived: batches
     * reach the server out of order after a day with no signal, and a line drawn
     * in arrival order is a route nobody walked.
     *
     * A single point is a ping and NOT a line — one position states where
     * somebody is, and drawing it as a route would claim a direction of travel
     * nobody recorded. A patrol that has never pinged is absent from the result
     * rather than present with nulls: it has said nothing, which is not the same
     * as having said it is nowhere.
     *
     * Raw SQL because DQL has neither ST_MakeLine nor an ordered aggregate, with
     * every table and column read from Doctrine's metadata as this bundle's
     * other geometry queries read theirs.
     *
     * @param list<int> $patrolIds
     *
     * @return array<int, array{lastAt: \DateTimeImmutable, lastPoint: string, line: string|null}> patrol id => its trail; a patrol with no points is absent
     */
    public function trailsForPatrols(array $patrolIds): array
    {
        if ([] === $patrolIds) {
            return [];
        }

        $point = $this->getClassMetadata();
        $connection = $this->getEntityManager()->getConnection();

        $sql = \sprintf(
            <<<'SQL'
                SELECT tp.%1$s AS patrol_id,
                       MAX(tp.%2$s) AS last_at,
                       ST_AsGeoJSON((ARRAY_AGG(tp.%3$s ORDER BY tp.%2$s DESC, tp.%4$s DESC))[1]) AS last_point,
                       CASE WHEN COUNT(*) > 1
                            THEN ST_AsGeoJSON(ST_MakeLine(ARRAY_AGG(tp.%3$s ORDER BY tp.%2$s ASC, tp.%4$s ASC)))
                       END AS line
                FROM %5$s tp
                WHERE tp.%1$s IN (:ids)
                GROUP BY tp.%1$s
                SQL,
            $point->getSingleAssociationJoinColumnName('patrol'),
            $point->getColumnName('recordedAt'),
            $point->getColumnName('position'),
            $point->getSingleIdentifierColumnName(),
            $point->getTableName(),
        );

        /** @var list<array{patrol_id: int|string, last_at: string, last_point: string, line: string|null}> $rows */
        $rows = $connection->fetchAllAssociative($sql, ['ids' => $patrolIds], ['ids' => ArrayParameterType::INTEGER]);

        $trails = [];
        foreach ($rows as $row) {
            $trails[(int) $row['patrol_id']] = [
                'lastAt' => new \DateTimeImmutable($row['last_at']),
                'lastPoint' => $row['last_point'],
                'line' => $row['line'],
            ];
        }

        return $trails;
    }
}
