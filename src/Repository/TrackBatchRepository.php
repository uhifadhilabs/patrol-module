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
use UhifadhiLabs\Patrol\Entity\TrackBatch;

/**
 * @extends ServiceEntityRepository<TrackBatch>
 */
final class TrackBatchRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TrackBatch::class);
    }

    /**
     * Whether this exact upload already landed — API-CONTRACT.md §5. The phone
     * retries forever on a dropped connection, so this lookup is the only thing
     * standing between a flaky signal and a doubled track.
     *
     * Keyed globally rather than per patrol: the phone derives the key FROM the
     * patrol uuid (`8f1f…:track:3`), so it is already unique across patrols, and
     * a global unique index is what actually enforces that at the database.
     */
    public function findOneByBatchKey(string $batchKey): ?TrackBatch
    {
        return $this->findOneBy(['batchKey' => $batchKey]);
    }
}
