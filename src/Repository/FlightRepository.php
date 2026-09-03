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
use Uhifadhi\Patrol\Entity\Flight;

/**
 * @extends ServiceEntityRepository<Flight>
 */
final class FlightRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Flight::class);
    }

    /**
     * The row a client UUID already created, if any — the lookup the whole
     * idempotency rule rests on (API-CONTRACT.md §1). A re-sent part with a
     * UUID we already hold is success, never a duplicate row.
     */
    public function findOneByClientUuid(Uuid $clientUuid): ?Flight
    {
        return $this->findOneBy(['clientUuid' => $clientUuid]);
    }
}
