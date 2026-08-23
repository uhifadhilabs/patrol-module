<?php

declare(strict_types=1);

namespace UhifadhiLabs\PatrolBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use UhifadhiLabs\PatrolBundle\Entity\Observation;

/**
 * @extends ServiceEntityRepository<Observation>
 */
final class ObservationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Observation::class);
    }
}
