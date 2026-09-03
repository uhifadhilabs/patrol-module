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
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;
use Uhifadhi\Patrol\Entity\ObservationPhoto;

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
     * on (@see \Uhifadhi\Patrol\Security\PatrolEvidenceVoter).
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

    /**
     * Every photograph this module holds, each with the chain the Files hub
     * prints on its tile: the observation it belongs to, that observation's
     * patrol, and the patrol's area.
     *
     * The three joins are the whole point. The hub reads the owner and the area
     * off EVERY file it draws, so a lazy association here would be one query per
     * photograph — a deployment with four thousand of them would take four
     * thousand round trips to render one page.
     *
     * @return list<ObservationPhoto>
     */
    public function findForFilesHub(): array
    {
        /** @var list<ObservationPhoto> $photos */
        $photos = $this->createQueryBuilder('p')
            ->addSelect('o', 'pt', 'a')
            ->join('p.observation', 'o')
            ->join('o.patrol', 'pt')
            ->join('pt.area', 'a')
            ->orderBy('p.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $photos;
    }

    /**
     * ONE OBSERVATION'S PHOTOGRAPHS, with the same chain the hub prints.
     *
     * The narrow half of {@see findForFilesHub()}, for the cross-module seam
     * ({@see \Uhifadhi\Storage\Registry\FileSourceInterface::filesForRecord()}):
     * the incidents report flow shows the photographs of the observation it is
     * being filed from, and it must not read every photograph in the deployment
     * to draw two thumbnails.
     *
     * The joins are kept because the entry the seam builds names the observation
     * and its area, exactly as a hub tile does — one query, not one per
     * photograph.
     *
     * In the HANDSET's order: a strip of photographs of one moment reads
     * forwards, because the second photograph is the second thing that happened.
     * A row with no handset clock falls in behind by insertion order.
     *
     * @return list<ObservationPhoto>
     */
    public function findForObservation(Uuid $observation): array
    {
        /** @var list<ObservationPhoto> $photos */
        $photos = $this->createQueryBuilder('p')
            ->addSelect('o', 'pt', 'a')
            ->join('p.observation', 'o')
            ->join('o.patrol', 'pt')
            ->join('pt.area', 'a')
            ->andWhere('o.uuid = :observation')
            ->setParameter('observation', $observation, UuidType::NAME)
            ->orderBy('p.takenAt', 'ASC')
            ->addOrderBy('p.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $photos;
    }
}
