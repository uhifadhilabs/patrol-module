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

namespace Uhifadhi\Patrol\Tests\Integration\Devkit;

use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Contracts\Devkit\ContentProviderInterface;
use Uhifadhi\Patrol\Devkit\PatrolContentProvider;
use Uhifadhi\Patrol\Devkit\PatrolDemoMonth;
use Uhifadhi\Patrol\Entity\Observation;
use Uhifadhi\Patrol\Entity\ObservationPhoto;
use Uhifadhi\Patrol\Entity\Patrol;
use Uhifadhi\Patrol\Entity\TaxonomyKind;
use Uhifadhi\Patrol\Entity\TaxonomySubcategory;
use Uhifadhi\Patrol\Enum\PatrolSourceEnum;
use Uhifadhi\Patrol\Tests\Integration\IntegrationTestCase;

/**
 * A DEMO MONTH, SEEDED THROUGH THE MODULE'S OWN DOORS — and then read back off
 * the database.
 *
 * That last part is what this test is for. A seeder writing straight to the
 * tables can produce a patrol no screen could ever create — a hand-written shift
 * carrying a track, an observation on a patrol that refuses field uploads — and
 * every such row is a bug report about a screen that is working correctly. So
 * what is asserted here is not only that rows appeared, but that they are shaped
 * the way the product shapes them.
 */
final class PatrolContentProviderTest extends IntegrationTestCase
{
    /**
     * IT IS DECLARED, AND IT IS THE TAG DEVKIT READS. The tag is a literal in
     * this bundle's wiring because devkit is absent in production; a module that
     * mistyped it would simply seed nothing, which looks exactly like a module
     * nobody installed.
     */
    public function testItIsRegisteredAsADevkitContentProvider(): void
    {
        $provider = $this->provider();

        self::assertInstanceOf(PatrolContentProvider::class, $provider);
        self::assertSame('patrol', $provider->key());
        self::assertSame(['team'], $provider->dependsOn(), 'It is seeded after the people its patrols are led by.');
        self::assertNotSame('', $provider->description());
    }

    public function testItSeedsAMonthOfPatrolsIntoTheAreaTheInstallationHas(): void
    {
        $this->anArea();
        $this->aPerson();

        $this->provider()->load();
        $this->em->clear();

        self::assertSame(PatrolDemoMonth::PATROLS, $this->em->getRepository(Patrol::class)->count([]));
    }

    /**
     * THE TWO DOORS, BOTH USED. A roster that was all GPS would be a lie about
     * how patrolling is recorded, and a demo that only ever exercised one write
     * path would leave the other untested by the thing developers look at first.
     */
    public function testItUsesBothWritePathsAndEachRowSaysWhichOne(): void
    {
        $this->anArea();
        $this->aPerson();

        $this->provider()->load();
        $this->em->clear();

        $recorded = $this->em->getRepository(Patrol::class)->findBy(['source' => PatrolSourceEnum::Gpx]);
        $written = $this->em->getRepository(Patrol::class)->findBy(['source' => PatrolSourceEnum::Manual]);

        self::assertNotSame([], $recorded);
        self::assertNotSame([], $written);
        self::assertCount(PatrolDemoMonth::PATROLS, [...$recorded, ...$written]);

        foreach ($recorded as $patrol) {
            self::assertNotNull($patrol->getTrack(), 'A recorded patrol was ingested from a document, so it has a route.');
            self::assertNotNull($patrol->getStartedAt());
            self::assertNotNull($patrol->getEndedAt());
            self::assertNotNull($patrol->getDistanceKm());
        }

        foreach ($written as $patrol) {
            // The distinction the whole module's coverage figures rest on.
            self::assertNull($patrol->getTrack(), 'A hand-written patrol carries no geometry.');
            self::assertNull($patrol->getPointCount());
        }
    }

    /**
     * THE TIMES AND THE DISTANCE ARE THE DOCUMENT'S. Ingest reads them off the
     * file, so a seeded track's span is the span its own points describe rather
     * than a figure this module asserted about itself.
     */
    public function testARecordedPatrolsFactsWereReadOffItsOwnTrack(): void
    {
        $this->anArea();
        $this->aPerson();

        $this->provider()->load();
        $this->em->clear();

        foreach ($this->em->getRepository(Patrol::class)->findBy(['source' => PatrolSourceEnum::Gpx]) as $patrol) {
            $startedAt = $patrol->getStartedAt();
            $endedAt = $patrol->getEndedAt();
            self::assertNotNull($startedAt);
            self::assertNotNull($endedAt);
            self::assertGreaterThan($startedAt, $endedAt);
            self::assertGreaterThan(1, (int) $patrol->getPointCount());
        }
    }

    /** The notes a shift came back with, filed against the patrol that logged them. */
    public function testTheRecordedPatrolsCarryObservations(): void
    {
        $this->anArea();
        $this->aPerson();

        $this->provider()->load();
        $this->em->clear();

        $observations = $this->em->getRepository(Observation::class)->findAll();
        self::assertNotSame([], $observations);

        foreach ($observations as $observation) {
            self::assertSame(PatrolSourceEnum::Gpx, $observation->getPatrol()->getSource());
            self::assertNotNull($observation->getLoggedAt());
            self::assertNotNull($observation->getPosition());
        }
    }

    /**
     * PHOTOGRAPHS GO THROUGH THE EVIDENCE PATH, so the files hub lists them and
     * the detail screen can show them — the same way a synced handset photo is
     * stored.
     */
    public function testObservationPhotographsAreRealStoredEvidence(): void
    {
        $this->anArea();
        $this->aPerson();

        $this->provider()->load();
        $this->em->clear();

        $photos = $this->em->getRepository(ObservationPhoto::class)->findAll();
        self::assertNotSame([], $photos);

        foreach ($photos as $photo) {
            self::assertSame('image/jpeg', $photo->getMimeType());
            self::assertGreaterThan(0, (int) $photo->getByteSize());
            self::assertFalse($photo->isAmendmentAttachment());
        }
    }

    /** The area's own observation taxonomy, written through the admin service the screen uses. */
    public function testItGivesTheAreaAnObservationTaxonomyToStartFrom(): void
    {
        $this->anArea();
        $this->aPerson();

        $this->provider()->load();
        $this->em->clear();

        self::assertSame(
            \count(PatrolDemoMonth::TAXONOMY),
            $this->em->getRepository(TaxonomyKind::class)->count([]),
        );
        self::assertSame(
            array_sum(array_map(\count(...), PatrolDemoMonth::TAXONOMY)),
            $this->em->getRepository(TaxonomySubcategory::class)->count([]),
        );
    }

    /**
     * AN INSTALLATION WITH NO AREA HAS NOWHERE TO PATROL, and that is a state
     * rather than a failure — devkit seeds every module in one run, and one with
     * nothing to hang its records on must not stop the others.
     */
    public function testWithNoAreaItSeedsNothingAndDoesNotThrow(): void
    {
        $this->provider()->load();

        self::assertSame(0, $this->em->getRepository(Patrol::class)->count([]));
    }

    /** Seeding twice is a developer re-running a demo command, not a reason to double the month. */
    public function testItLeavesAnAreaThatAlreadyHasPatrolsAlone(): void
    {
        $this->anArea();
        $this->aPerson();

        $this->provider()->load();
        $this->provider()->load();
        $this->em->clear();

        self::assertSame(PatrolDemoMonth::PATROLS, $this->em->getRepository(Patrol::class)->count([]));
    }

    private function provider(): ContentProviderInterface
    {
        $provider = $this->service(PatrolContentProvider::class);
        \assert($provider instanceof ContentProviderInterface);

        return $provider;
    }

    private function anArea(): AreaOfInterest
    {
        $area = new AreaOfInterest()->setSource('test fixture');
        $area->setName('Sample Area')
            ->setGeom('{"type":"MultiPolygon","coordinates":[[[[-30.0,-3.6],[-29.0,-3.6],[-29.0,-2.8],[-30.0,-2.8],[-30.0,-3.6]]]]}');
        $this->em->persist($area);
        $this->em->flush();

        return $area;
    }

    private function aPerson(): User
    {
        $user = new User()->setPassword('x')->setEmail('ranger@example.test')->setFirstName('Neema')->setLastName('Example');
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
