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
use Uhifadhi\Patrol\Entity\PatrolType;

/**
 * The area's patrol-type vocabulary. EVERY read here is confined to one area:
 * the list is that area's own, and a query that forgot the area filter would be
 * the one bug the whole area-scoping rules against.
 *
 * @extends ServiceEntityRepository<PatrolType>
 */
final class PatrolTypeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PatrolType::class);
    }

    /**
     * This area's types in their own order, retired ones INCLUDED — SET·01 dims
     * a retired row, it does not hide it.
     *
     * @return list<PatrolType>
     */
    public function findByArea(AreaOfInterest $area): array
    {
        return $this->findBy(['area' => $area], ['position' => 'ASC', 'id' => 'ASC']);
    }

    /**
     * The types a ranger may still pick from — the chip row on the log form and
     * the list the handset syncs.
     *
     * @return list<PatrolType>
     */
    public function findByAreaActive(AreaOfInterest $area): array
    {
        return $this->findBy(['area' => $area, 'active' => true], ['position' => 'ASC', 'id' => 'ASC']);
    }

    /**
     * THE AREA'S TYPE VOCABULARY, in the shape every screen already binds:
     * key → {label}. It is what `patrol.types` used to hand the templates, read
     * from the area's own records instead, so a rename shows everywhere at once.
     *
     * RETIRED TYPES ARE INCLUDED. A month may hold patrols of a type the area
     * has since retired, and a filter menu that dropped it would leave those
     * patrols unreachable and its chart bar unlabelled. Offering a retired word
     * for a NEW patrol is the log form's business, and it asks for the active
     * ones ({@see self::findByAreaActive()}).
     *
     * @return array<string, array{label: string}>
     */
    public function findVocabularyByArea(AreaOfInterest $area): array
    {
        $vocabulary = [];
        foreach ($this->findByArea($area) as $type) {
            $vocabulary[$type->getKey()] = ['label' => $type->getLabel()];
        }

        return $vocabulary;
    }

    public function findOneByAreaAndKey(AreaOfInterest $area, string $key): ?PatrolType
    {
        return $this->findOneBy(['area' => $area, 'key' => $key]);
    }

    public function findOneByAreaAndUuid(AreaOfInterest $area, string $uuid): ?PatrolType
    {
        if (!Uuid::isValid($uuid)) {
            return null;
        }

        return $this->findOneBy(['area' => $area, 'uuid' => Uuid::fromString($uuid)]);
    }

    /** The largest position among this area's types, or -1 when it has none. */
    public function maxPositionByArea(AreaOfInterest $area): int
    {
        $max = $this->createQueryBuilder('t')
            ->select('MAX(t.position)')
            ->andWhere('t.area = :area')->setParameter('area', $area)
            ->getQuery()
            ->getSingleScalarResult();

        return null === $max ? -1 : (int) $max;
    }

    /**
     * HOW MANY PATROLS ARE FILED UNDER EACH TYPE — the "78 patrols" the design
     * prints on every SET·01 row, and the reason retiring can never delete.
     *
     * Counted in one query rather than per row, and LEFT so a type nobody has
     * used yet answers 0 instead of being missing from the map.
     *
     * @return array<string, int> type key → patrols filed under it
     */
    public function countPatrolsByArea(AreaOfInterest $area): array
    {
        /** @var list<array{k: string, n: int|string}> $rows */
        $rows = $this->createQueryBuilder('t')
            ->select('t.key AS k', 'COUNT(p.id) AS n')
            ->leftJoin(Patrol::class, 'p', 'WITH', 'p.patrolType = t')
            ->andWhere('t.area = :area')->setParameter('area', $area)
            ->groupBy('t.key')
            ->getQuery()
            ->getResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[$row['k']] = (int) $row['n'];
        }

        return $counts;
    }

    /** A type with this label already lives in the area (case-insensitive). */
    public function labelExistsInArea(AreaOfInterest $area, string $label, ?PatrolType $except = null): bool
    {
        $qb = $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->andWhere('t.area = :area')->setParameter('area', $area)
            ->andWhere('LOWER(t.label) = :label')->setParameter('label', mb_strtolower($label));

        if (null !== $except && null !== $except->getId()) {
            $qb->andWhere('t.id != :except')->setParameter('except', $except->getId());
        }

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }
}
