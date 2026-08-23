<?php

declare(strict_types=1);

namespace UhifadhiLabs\Patrol\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Uhifadhi\Spatial\Entity\AreaOfInterest;
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
}
