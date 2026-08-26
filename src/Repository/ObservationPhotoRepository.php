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
use UhifadhiLabs\Patrol\Entity\ObservationPhoto;

/**
 * @extends ServiceEntityRepository<ObservationPhoto>
 */
final class ObservationPhotoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ObservationPhoto::class);
    }

    /**
     * The row a client UUID already created, if any — the lookup the whole
     * idempotency rule rests on (API-CONTRACT.md §1). A re-sent part with a
     * UUID we already hold is success, never a duplicate row.
     */
    public function findOneByClientUuid(Uuid $clientUuid): ?ObservationPhoto
    {
        return $this->findOneBy(['clientUuid' => $clientUuid]);
    }

    /**
     * The row that owns an evidence key — the lookup the evidence voter rests
     * on (@see \UhifadhiLabs\Patrol\Security\PatrolEvidenceVoter).
     *
     * Answering NULL is what refuses a key this module does not actually hold,
     * so it must never be widened into a LIKE or a prefix match.
     */
    public function findOneByStoragePath(string $storagePath): ?ObservationPhoto
    {
        return $this->findOneBy(['storagePath' => $storagePath]);
    }

    /**
     * Photographs recorded with no preview — what the backfill has left to do.
     *
     * Ordered by id so a run that is interrupted resumes in the same order, and
     * so two runs report the same thing.
     *
     * @return list<ObservationPhoto>
     */
    public function findWithoutThumbKey(): array
    {
        return $this->findBy(['thumbKey' => null], ['id' => 'ASC']);
    }
}
