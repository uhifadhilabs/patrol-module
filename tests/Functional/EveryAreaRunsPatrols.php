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

namespace Uhifadhi\Patrol\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\RegistryBundle\Service\AreaModuleService;
use Uhifadhi\Bundle\RegistryBundle\Service\RegistrySyncService;
use Uhifadhi\Patrol\Module\PatrolModuleProvider;

/**
 * THE AREAS IN THIS SUITE ARE RUNNING PATROLS — said once, out loud, because
 * it is not true by default.
 *
 * The registry closes a module's routes in an area that has parked it, or never
 * took it, and it answers 404 for both. A fixture area created straight into
 * the database has no row in the per-area ledger at all, so every page in this
 * bundle would answer 404 there — correctly, and uselessly.
 *
 * So the fixture does what an installation does: reconcile the catalogue, then
 * switch the module on for the area. That is two lines of somebody's real setup
 * and it is the honest way to test a page — the alternative, quietly exempting
 * the suite from the gate, would test a product nobody runs.
 *
 * THE RECONCILIATION IS CALLED RATHER THAN WARMED. In an installation it is a
 * cache warmer, so `cache:clear` is the whole of it; here the schema is dropped
 * and recreated after the kernel booted, which takes the warm-up's rows with
 * it. Calling the same service is the same act, at the only moment there is a
 * table to write into.
 *
 * EVERY area, including the "other area" a test uses to prove a patrol cannot
 * be read from next door. Those tests assert 404, and the 404 has to keep
 * meaning "that patrol is not in this area" rather than "this area has no
 * patrols module".
 */
trait EveryAreaRunsPatrols
{
    protected function everyAreaRunsPatrols(EntityManagerInterface $em): void
    {
        $em->flush();

        $sync = static::getContainer()->get('test_public.'.RegistrySyncService::class);
        \assert($sync instanceof RegistrySyncService);
        $sync->sync();

        $areaModules = static::getContainer()->get('test_public.'.AreaModuleService::class);
        \assert($areaModules instanceof AreaModuleService);

        foreach ($em->getRepository(AreaOfInterest::class)->findAll() as $area) {
            $areaModules->install($area, PatrolModuleProvider::SLUG);
        }
    }
}
