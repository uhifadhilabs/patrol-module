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

use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;
use Uhifadhi\Patrol\Entity\Observation;
use Uhifadhi\Patrol\Entity\TaxonomyKind;
use Uhifadhi\Patrol\Repository\TaxonomyKindRepository;
use Uhifadhi\Patrol\Tests\Fixtures\Vocabulary;

/**
 * THE WORDS AN OBSERVATION MAY ARRIVE UNDER — the write side of exactly what
 * `GET /api/patrols/vocabulary` publishes.
 *
 * A handset files against the words that endpoint handed it: this area's
 * observation kinds and their sub-categories, by key. So the whole subject here
 * is that the two sides agree — and that where they cannot, the patrol still
 * lands. A ranger's day is not a payload to be argued with.
 */
final class FieldSyncObservationVocabularyTest extends FieldSyncTestCase
{
    #[Test]
    public function anObservationIsFiledUnderThisAreasOwnKind(): void
    {
        $this->aKind('Carcass');
        $this->actingAs($this->recorder);
        $patrolUuid = $this->createPatrol();

        $this->postObservation($patrolUuid, 'e1000000-0000-4000-8000-000000000001', ['category' => 'carcass']);

        self::assertResponseIsSuccessful();
        self::assertSame('carcass', $this->observation('e1000000-0000-4000-8000-000000000001')->getCategory());
    }

    /**
     * The flat `patrol.observation_categories` list is a PARALLEL model that has
     * not gone anywhere, and a handset built against it must keep working.
     */
    #[Test]
    public function aDeploymentWideCategoryWordIsStillAccepted(): void
    {
        $this->aKind('Carcass');
        $this->actingAs($this->recorder);
        $patrolUuid = $this->createPatrol();

        // TestKernel configures exactly one: 'maintenance'.
        $this->postObservation($patrolUuid, 'e1000000-0000-4000-8000-000000000002', ['category' => 'maintenance']);

        self::assertResponseIsSuccessful();
        self::assertSame('maintenance', $this->observation('e1000000-0000-4000-8000-000000000002')->getCategory());
        self::assertNull(
            $this->kinds()->findOneByAreaAndCode($this->area, 'maintenance'),
            'A word the deployment configures is not copied into the area taxonomy.',
        );
    }

    /**
     * The same rule an unknown station gets: kept and made visible, never
     * refused. A settings screen and an app build disagreeing about a word must
     * not cost a patrol.
     */
    #[Test]
    public function aKindThisAreaHasNeverHeardOfArrivesRetired(): void
    {
        $this->actingAs($this->recorder);
        $patrolUuid = $this->createPatrol();

        $this->postObservation($patrolUuid, 'e1000000-0000-4000-8000-000000000003', ['category' => 'snare-line']);

        self::assertResponseIsSuccessful();
        self::assertSame('snare-line', $this->observation('e1000000-0000-4000-8000-000000000003')->getCategory());

        $kind = $this->kinds()->findOneByAreaAndCode($this->area, 'snare-line');
        self::assertInstanceOf(TaxonomyKind::class, $kind);
        self::assertFalse($kind->isActive(), 'A word that turned up from the field must arrive retired.');
        self::assertSame('snare-line', $kind->getLabel());
    }

    #[Test]
    public function aSubcategoryFromTheVocabularyIsStored(): void
    {
        $this->aKind('Carcass', ['Poached carcass']);
        $this->actingAs($this->recorder);
        $patrolUuid = $this->createPatrol();

        $this->postObservation($patrolUuid, 'e1000000-0000-4000-8000-000000000004', [
            'category' => 'carcass',
            'subcategory' => 'poached-carcass',
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame('poached-carcass', $this->observation('e1000000-0000-4000-8000-000000000004')->getSubcategory());
    }

    #[Test]
    public function aSubcategoryThisKindHasNeverHeardOfArrivesRetiredUnderIt(): void
    {
        $this->aKind('Carcass', ['Poached carcass']);
        $this->actingAs($this->recorder);
        $patrolUuid = $this->createPatrol();

        $this->postObservation($patrolUuid, 'e1000000-0000-4000-8000-000000000005', [
            'category' => 'carcass',
            'subcategory' => 'drowned',
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame('drowned', $this->observation('e1000000-0000-4000-8000-000000000005')->getSubcategory());

        $kind = $this->kinds()->findOneByAreaAndCode($this->area, 'carcass');
        self::assertInstanceOf(TaxonomyKind::class, $kind);
        self::assertTrue($kind->isActive(), 'A stray sub-category must not retire the kind it came under.');

        $arrived = null;
        foreach ($kind->getSubcategories() as $subcategory) {
            if ('drowned' === $subcategory->getCode()) {
                $arrived = $subcategory;
            }
        }

        self::assertNotNull($arrived, 'The sub-category was neither stored nor made visible.');
        self::assertFalse($arrived->isActive());
    }

    /** Absent is a real answer, and it is not backfilled with anything. */
    #[Test]
    public function anObservationWithNoSubcategoryHasNone(): void
    {
        $this->aKind('Carcass', ['Poached carcass']);
        $this->actingAs($this->recorder);
        $patrolUuid = $this->createPatrol();

        $this->postObservation($patrolUuid, 'e1000000-0000-4000-8000-000000000006', ['category' => 'carcass']);

        self::assertResponseIsSuccessful();
        self::assertNull($this->observation('e1000000-0000-4000-8000-000000000006')->getSubcategory());
    }

    /**
     * The clientUuid rule (§1) covers the new field too: a re-sent part adds
     * nothing and changes nothing, whatever the second copy happens to say.
     */
    #[Test]
    public function aReSentObservationKeepsTheWordsItLandedWith(): void
    {
        $this->aKind('Carcass', ['Poached carcass']);
        $this->actingAs($this->recorder);
        $patrolUuid = $this->createPatrol();
        $clientUuid = 'e1000000-0000-4000-8000-000000000007';

        $this->postObservation($patrolUuid, $clientUuid, [
            'category' => 'carcass',
            'subcategory' => 'poached-carcass',
        ]);
        self::assertResponseIsSuccessful();

        $this->postObservation($patrolUuid, $clientUuid, [
            'category' => 'maintenance',
            'subcategory' => 'drowned',
        ]);
        self::assertResponseIsSuccessful();
        self::assertTrue($this->payload()['duplicate']);

        $observation = $this->observation($clientUuid);
        self::assertSame('carcass', $observation->getCategory());
        self::assertSame('poached-carcass', $observation->getSubcategory());
        self::assertCount(1, $this->em->getRepository(Observation::class)->findAll());
    }

    /** @param array<string, mixed> $row */
    private function postObservation(string $patrolUuid, string $clientUuid, array $row): void
    {
        $this->postJson("/api/patrols/{$patrolUuid}/observations", [
            'observations' => [array_merge([
                'clientUuid' => $clientUuid,
                'note' => 'open water, no landmark',
                'loggedAt' => '2026-08-23T07:02:00Z',
                'photoCount' => 0,
            ], $row)],
        ]);
    }

    /** @param list<string> $subcategories */
    private function aKind(string $label, array $subcategories = []): TaxonomyKind
    {
        $kind = Vocabulary::kind($this->em, $this->area, $label, $subcategories);
        $this->em->flush();

        return $kind;
    }

    private function observation(string $clientUuid): Observation
    {
        $this->em->clear();

        $observation = $this->em->getRepository(Observation::class)
            ->findOneBy(['clientUuid' => Uuid::fromString($clientUuid)]);

        self::assertInstanceOf(Observation::class, $observation);

        return $observation;
    }

    private function kinds(): TaxonomyKindRepository
    {
        $repository = $this->em->getRepository(TaxonomyKind::class);
        self::assertInstanceOf(TaxonomyKindRepository::class, $repository);

        return $repository;
    }
}
