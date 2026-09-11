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
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Patrol\Entity\PatrolSettings;

/**
 * ONE ROW PER AREA, OR NONE. An area that has never been configured has no row,
 * and null is the honest answer — the service, not this, decides what an
 * unconfigured area falls back to.
 *
 * @extends ServiceEntityRepository<PatrolSettings>
 */
final class PatrolSettingsRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PatrolSettings::class);
    }

    public function findOneByArea(AreaOfInterest $area): ?PatrolSettings
    {
        return $this->findOneBy(['area' => $area]);
    }
}
