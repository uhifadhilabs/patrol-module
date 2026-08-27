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
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;
use UhifadhiLabs\Patrol\Entity\PatrolEvent;

/**
 * @extends ServiceEntityRepository<PatrolEvent>
 */
final class PatrolEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PatrolEvent::class);
    }

    /**
     * The event a device already sent, if any — API-CONTRACT.md §9A's
     * idempotency key. A re-sent event is acknowledged and applied nowhere a
     * second time, which is what stops a retried rename from writing three rows
     * telling the same story.
     */
    public function findOneByClientUuid(Uuid $clientUuid): ?PatrolEvent
    {
        return $this->findOneBy(['clientUuid' => $clientUuid]);
    }
}
