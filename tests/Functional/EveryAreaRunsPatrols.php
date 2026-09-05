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
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Uhifadhi\Area\Entity\AreaOfInterest;
use Uhifadhi\Patrol\Module\PatrolModuleProvider;
use Uhifadhi\Seam\Service\AreaModuleService;

/**
 * THE AREAS IN THIS SUITE ARE RUNNING PATROLS — said once, out loud, because
 * since seam 0.2 it is no longer true by default.
 *
 * The seam closes a module's routes in an area that has parked it, or never
 * took it, and it answers 404 for both. A fixture area created straight into
 * the database has no row in the per-area ledger at all, so every page in this
 * bundle would answer 404 there — correctly, and uselessly.
 *
 * So the fixture does what an installation does: run the catalogue seed, then
 * switch the module on for the area. That is two lines of somebody's real setup
 * and it is the honest way to test a page — the alternative, quietly exempting
 * the suite from the gate, would test a product nobody runs.
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

        $kernel = self::$kernel;
        \assert(null !== $kernel);

        // The catalogue row is written by the seed, exactly as `composer
        // require` tells an operator; nothing here invents one.
        new CommandTester(new Application($kernel)->find('seam:catalogue:seed'))->execute([]);

        $areaModules = static::getContainer()->get('test_public.'.AreaModuleService::class);
        \assert($areaModules instanceof AreaModuleService);

        foreach ($em->getRepository(AreaOfInterest::class)->findAll() as $area) {
            $areaModules->install($area, PatrolModuleProvider::SLUG);
        }
    }
}
