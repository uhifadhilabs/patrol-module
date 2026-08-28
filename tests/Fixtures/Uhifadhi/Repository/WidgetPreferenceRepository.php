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

namespace Uhifadhi\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;
use Uhifadhi\Entity\WidgetPreference;

/**
 * @extends ServiceEntityRepository<WidgetPreference>
 */
final class WidgetPreferenceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WidgetPreference::class);
    }

    /**
     * At most one row exists per (surface, user, area) — the table's two partial
     * unique indexes. A null area is the org-wide layout and matches IS NULL,
     * which findOneBy renders for us.
     */
    public function findOneForUser(string $surface, int $userId, ?Uuid $areaUuid = null): ?WidgetPreference
    {
        return $this->findOneBy(['surface' => $surface, 'userId' => $userId, 'areaUuid' => $areaUuid]);
    }
}
