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
use Uhifadhi\Patrol\Entity\PatrolDraft;

/**
 * The patrols being written, and the ones nobody came back to.
 *
 * @extends ServiceEntityRepository<PatrolDraft>
 */
class PatrolDraftRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PatrolDraft::class);
    }

    /**
     * The draft an upload target was handed, or null.
     *
     * The id is attacker-controlled text off a form field, so anything that is
     * not a uuid is the same fact as "no such draft" — the contract's own
     * reading, and never "you may not".
     */
    public function findOneByUuid(string $uuid): ?PatrolDraft
    {
        return Uuid::isValid($uuid) ? $this->findOneBy(['uuid' => Uuid::fromString($uuid)]) : null;
    }

    /**
     * Drafts opened before a moment and never saved — what the retention sweep
     * deletes.
     *
     * @return list<PatrolDraft>
     */
    public function findByCreatedBefore(\DateTimeImmutable $cutoff): array
    {
        /** @var list<PatrolDraft> $drafts */
        $drafts = $this->createQueryBuilder('d')
            ->andWhere('d.createdAt < :cutoff')
            ->setParameter('cutoff', $cutoff)
            ->orderBy('d.createdAt', 'ASC')
            ->getQuery()
            ->getResult();

        return $drafts;
    }
}
