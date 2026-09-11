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
use Uhifadhi\Patrol\Entity\PatrolDraft;
use Uhifadhi\Patrol\Entity\PatrolDraftFile;

/**
 * The files a draft has received so far.
 *
 * @extends ServiceEntityRepository<PatrolDraftFile>
 */
class PatrolDraftFileRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PatrolDraftFile::class);
    }

    /**
     * The row a stored key belongs to — how a removal, which holds nothing but
     * the key, finds its way back to the draft that may authorise it.
     */
    public function findOneByStorageKey(string $key): ?PatrolDraftFile
    {
        return $this->findOneBy(['storageKey' => $key]);
    }

    /** @return list<PatrolDraftFile> */
    public function findByDraftAndSlot(PatrolDraft $draft, string $slot): array
    {
        return $this->findBy(['draft' => $draft, 'slot' => $slot], ['id' => 'ASC']);
    }
}
