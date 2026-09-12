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
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Patrol\Entity\Patrol;
use Uhifadhi\Patrol\Entity\Station;

/**
 * The area's stations. Area-scoped throughout, for the reason
 * {@see PatrolTypeRepository} is.
 *
 * @extends ServiceEntityRepository<Station>
 */
final class StationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Station::class);
    }

    /**
     * This area's stations in their own order, retired ones INCLUDED — the Stations section
     * dims a retired row rather than hiding it.
     *
     * @return list<Station>
     */
    public function findByArea(AreaOfInterest $area): array
    {
        return $this->findBy(['area' => $area], ['position' => 'ASC', 'id' => 'ASC']);
    }

    /** @return list<Station> */
    public function findByAreaActive(AreaOfInterest $area): array
    {
        return $this->findBy(['area' => $area, 'active' => true], ['position' => 'ASC', 'id' => 'ASC']);
    }

    public function findOneByAreaAndKey(AreaOfInterest $area, string $key): ?Station
    {
        return $this->findOneBy(['area' => $area, 'key' => $key]);
    }

    public function findOneByAreaAndUuid(AreaOfInterest $area, string $uuid): ?Station
    {
        if (!Uuid::isValid($uuid)) {
            return null;
        }

        return $this->findOneBy(['area' => $area, 'uuid' => Uuid::fromString($uuid)]);
    }

    public function maxPositionByArea(AreaOfInterest $area): int
    {
        $max = $this->createQueryBuilder('s')
            ->select('MAX(s.position)')
            ->andWhere('s.area = :area')->setParameter('area', $area)
            ->getQuery()
            ->getSingleScalarResult();

        return null === $max ? -1 : (int) $max;
    }

    /**
     * The "46 patrols" on every Stations row — one query, LEFT so a station
     * nobody has set out from yet answers 0 rather than going missing.
     *
     * @return array<string, int> station key → patrols filed against it
     */
    public function countPatrolsByArea(AreaOfInterest $area): array
    {
        /** @var list<array{k: string, n: int|string}> $rows */
        $rows = $this->createQueryBuilder('s')
            ->select('s.key AS k', 'COUNT(p.id) AS n')
            ->leftJoin(Patrol::class, 'p', 'WITH', 'p.stationRecord = s')
            ->andWhere('s.area = :area')->setParameter('area', $area)
            ->groupBy('s.key')
            ->getQuery()
            ->getResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[$row['k']] = (int) $row['n'];
        }

        return $counts;
    }

    public function labelExistsInArea(AreaOfInterest $area, string $label, ?Station $except = null): bool
    {
        $qb = $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->andWhere('s.area = :area')->setParameter('area', $area)
            ->andWhere('LOWER(s.label) = :label')->setParameter('label', mb_strtolower($label));

        if (null !== $except && null !== $except->getId()) {
            $qb->andWhere('s.id != :except')->setParameter('except', $except->getId());
        }

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }
}
