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
use Uhifadhi\Patrol\Entity\Observation;
use Uhifadhi\Patrol\Entity\ObservationAmendment;

/**
 * @extends ServiceEntityRepository<ObservationAmendment>
 */
final class ObservationAmendmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ObservationAmendment::class);
    }

    /**
     * One observation's corrections, OLDEST FIRST — the order a trail is read
     * in, under the record it corrects.
     *
     * There is deliberately no "latest" or "most recent" query beside it: an
     * amendment trail is only meaningful whole, and a caller that took the last
     * one alone would be reading a correction without the correction that may
     * have superseded it.
     *
     * @return list<ObservationAmendment>
     */
    public function findForObservation(Observation $observation): array
    {
        /** @var list<ObservationAmendment> $amendments */
        $amendments = $this->createQueryBuilder('a')
            ->andWhere('a.observation = :observation')
            ->setParameter('observation', $observation)
            ->orderBy('a.writtenAt', 'ASC')
            ->addOrderBy('a.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $amendments;
    }
}
