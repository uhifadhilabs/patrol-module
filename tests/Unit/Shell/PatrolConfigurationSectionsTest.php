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

namespace Uhifadhi\Patrol\Tests\Unit\Shell;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Repository\AreaOfInterestRepository;
use Uhifadhi\Contracts\Shell\ConfigurationSection;
use Uhifadhi\Patrol\Module\PatrolModuleProvider;
use Uhifadhi\Patrol\Repository\PatrolRepository;
use Uhifadhi\Patrol\Repository\PatrolSettingsRepository;
use Uhifadhi\Patrol\Repository\TaxonomyKindRepository;
use Uhifadhi\Patrol\Service\PatrolSettingsService;
use Uhifadhi\Patrol\Shell\PatrolConfigurationSections;

/**
 * WHAT IS ON THE PATROLS CONFIGURE PAGE, as a declaration — without the page,
 * the shell or a database behind it.
 */
final class PatrolConfigurationSectionsTest extends TestCase
{
    private function declaration(?Request $request = null, ?AreaOfInterest $area = null): PatrolConfigurationSections
    {
        $requests = new RequestStack();
        if (null !== $request) {
            $requests->push($request);
        }

        $areas = $this->createStub(AreaOfInterestRepository::class);
        $areas->method('findOneBy')->willReturn($area);

        // REAL COLLABORATORS OVER AN EMPTY REGISTRY. None of them is reached on
        // the pages this test drives — the declaration answers before it queries
        // — and a repository over a registry that was never asked for a manager
        // is cheaper to build than a double of a final class.
        $registry = $this->createStub(ManagerRegistry::class);

        return new PatrolConfigurationSections(
            $requests,
            $areas,
            new PatrolSettingsService(
                $this->createStub(EntityManagerInterface::class),
                new PatrolSettingsRepository($registry),
                5.0,
                90,
            ),
            new PatrolRepository($registry),
            new TaxonomyKindRepository($registry),
            null,
            ['walk' => ['label' => 'Walking round']],
        );
    }

    public function testItConfiguresItsOwnModule(): void
    {
        self::assertSame(PatrolModuleProvider::SLUG, $this->declaration()->slug());
    }

    /**
     * THE THREE SECTIONS, in the words this module chose — and two of them keep
     * an address of their own, exactly as the settled design draws them.
     */
    public function testItDeclaresTheLibraryTheKindsAndTheSettings(): void
    {
        $sections = $this->declaration()->sections();

        self::assertSame(
            [ConfigurationSection::WIDGETS, 'kinds', ConfigurationSection::SETTINGS],
            array_map(static fn (ConfigurationSection $s): string => $s->id, $sections),
        );
        self::assertSame(
            ['Widget library', 'Observation kinds', 'Settings'],
            array_map(static fn (ConfigurationSection $s): string => $s->label, $sections),
        );
        self::assertFalse($sections[0]->isRendered());
        self::assertFalse($sections[1]->isRendered());
        self::assertTrue($sections[2]->isRendered());
    }

    /**
     * A DECLARATION IS CONSULTED ON EVERY PAGE OF THE MODULE, so the one body
     * the shell renders is gathered only where it is drawn.
     */
    public function testTheSettingsBodyIsNotBuiltAwayFromTheConfigurePage(): void
    {
        $request = new Request();
        $request->attributes->set('_route', 'patrol_dashboard');

        $sections = $this->declaration($request)->sections();

        self::assertSame([], $sections[2]->variables);
    }

    /** A request that names no area gets an empty heading rather than a throw. */
    public function testAHeadingWithoutAnAreaIsEmpty(): void
    {
        self::assertSame('', $this->declaration()->heading());
    }

    /** Nothing this module calls a section says "register". */
    public function testNoSectionSaysRegister(): void
    {
        foreach ($this->declaration()->sections() as $section) {
            self::assertStringNotContainsStringIgnoringCase('register', $section->label);
            self::assertStringNotContainsStringIgnoringCase('register', $section->id);
        }
    }
}
