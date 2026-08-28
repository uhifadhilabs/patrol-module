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
use Uhifadhi\Entity\WidgetCustomPreset;

/**
 * @extends ServiceEntityRepository<WidgetCustomPreset>
 */
final class WidgetCustomPresetRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WidgetCustomPreset::class);
    }

    /**
     * This person's saved layouts for this surface, oldest first — the order they
     * built them in, which is the order they remember them in.
     *
     * @return list<WidgetCustomPreset>
     */
    public function findForUser(string $surface, int $userId, ?Uuid $areaUuid = null): array
    {
        return $this->findBy(
            ['surface' => $surface, 'userId' => $userId, 'areaUuid' => $areaUuid],
            ['id' => 'ASC'],
        );
    }

    /** At most one, by the table's two partial unique indexes. */
    public function findOneNamed(string $surface, int $userId, ?Uuid $areaUuid, string $name): ?WidgetCustomPreset
    {
        return $this->findOneBy(['surface' => $surface, 'userId' => $userId, 'areaUuid' => $areaUuid, 'name' => $name]);
    }

    /**
     * ONE query for "this preset, if it is yours": the ownership and the scope are
     * part of the lookup, so a route can never reach another person's preset and
     * a missing one and a foreign one are indistinguishable from outside.
     */
    public function findOwned(string $surface, int $userId, ?Uuid $areaUuid, Uuid $uuid): ?WidgetCustomPreset
    {
        return $this->findOneBy(['surface' => $surface, 'userId' => $userId, 'areaUuid' => $areaUuid, 'uuid' => $uuid]);
    }
}
