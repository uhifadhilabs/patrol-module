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
use Uhifadhi\Area\Entity\AreaOfInterest;
use Uhifadhi\Patrol\Entity\TaxonomyKind;

/**
 * The area-scoped patrol taxonomy's top level. EVERY read here is confined to one
 * area: the taxonomy is this area's own, and a query that forgot the area filter
 * would be the one bug the whole area-scoping rules against.
 *
 * @extends ServiceEntityRepository<TaxonomyKind>
 */
final class TaxonomyKindRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TaxonomyKind::class);
    }

    /**
     * This area's kinds, in their own order, retired ones INCLUDED — the manager
     * dims a retired row, it does not hide it — with sub-categories preloaded.
     *
     * @return list<TaxonomyKind>
     */
    public function forArea(AreaOfInterest $area): array
    {
        /** @var list<TaxonomyKind> $kinds */
        $kinds = $this->createQueryBuilder('k')
            ->leftJoin('k.subcategories', 's')->addSelect('s')
            ->andWhere('k.area = :area')->setParameter('area', $area)
            ->orderBy('k.position', 'ASC')
            ->addOrderBy('k.id', 'ASC')
            ->addOrderBy('s.position', 'ASC')
            ->addOrderBy('s.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $kinds;
    }

    /** Whether this area has any kinds at all — the empty-start test. */
    public function areaHasAny(AreaOfInterest $area): bool
    {
        return null !== $this->findOneBy(['area' => $area]);
    }

    public function findOneByAreaAndUuid(AreaOfInterest $area, string $uuid): ?TaxonomyKind
    {
        if (!Uuid::isValid($uuid)) {
            return null;
        }

        return $this->findOneBy(['area' => $area, 'uuid' => $uuid]);
    }

    /** The largest position among this area's kinds, or -1 when it has none. */
    public function maxPositionForArea(AreaOfInterest $area): int
    {
        $max = $this->createQueryBuilder('k')
            ->select('MAX(k.position)')
            ->andWhere('k.area = :area')->setParameter('area', $area)
            ->getQuery()
            ->getSingleScalarResult();

        return null === $max ? -1 : (int) $max;
    }

    /** A kind with this label already lives in the area (case-insensitive). */
    public function labelExistsInArea(AreaOfInterest $area, string $label, ?TaxonomyKind $except = null): bool
    {
        return $this->matchExists($area, 'LOWER(k.label)', mb_strtolower($label), $except);
    }

    /** A kind with this wire-code already lives in the area. */
    public function codeExistsInArea(AreaOfInterest $area, string $code, ?TaxonomyKind $except = null): bool
    {
        return $this->matchExists($area, 'k.code', $code, $except);
    }

    private function matchExists(AreaOfInterest $area, string $expr, string $value, ?TaxonomyKind $except): bool
    {
        $qb = $this->createQueryBuilder('k')
            ->select('COUNT(k.id)')
            ->andWhere('k.area = :area')->setParameter('area', $area)
            ->andWhere($expr.' = :value')->setParameter('value', $value);

        if (null !== $except && null !== $except->getId()) {
            $qb->andWhere('k.id != :except')->setParameter('except', $except->getId());
        }

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }
}
