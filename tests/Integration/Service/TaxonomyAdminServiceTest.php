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

namespace Uhifadhi\Patrol\Tests\Integration\Service;

use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Patrol\Entity\TaxonomyKind;
use Uhifadhi\Patrol\Exception\TaxonomyConflictException;
use Uhifadhi\Patrol\Repository\TaxonomyKindRepository;
use Uhifadhi\Patrol\Service\TaxonomyAdminService;
use Uhifadhi\Patrol\Tests\Integration\IntegrationTestCase;

/**
 * THE AREA-SCOPED PATROL TAXONOMY, PROVEN AGAINST THE REAL DATABASE. The schema
 * for the two tables is built by IntegrationTestCase from the entity metadata,
 * so every assertion below is also proof the columns persist. That the SHIPPED
 * migration creates the same two tables is the drift lock's business
 * (tests/Integration/Migrations), not this test's.
 *
 * SHALLOW: there is no behaviour-block test here, because a patrol sub-category
 * has none. That absence is the model, and the functional suite pins that the
 * screen ships none either.
 */
final class TaxonomyAdminServiceTest extends IntegrationTestCase
{
    private function admin(): TaxonomyAdminService
    {
        /** @var TaxonomyAdminService $admin */
        $admin = $this->service(TaxonomyAdminService::class);

        return $admin;
    }

    private function kinds(): TaxonomyKindRepository
    {
        $repository = $this->em->getRepository(TaxonomyKind::class);
        self::assertInstanceOf(TaxonomyKindRepository::class, $repository);

        return $repository;
    }

    private function anArea(string $name = 'Sample Area'): AreaOfInterest
    {
        $area = new AreaOfInterest();
        $area->setName($name);
        // NOT NULL in AreaBundle: an area is always something an
        // installation got from somewhere.
        $area->setSource('test fixture');
        $area->setGeom('{"type":"MultiPolygon","coordinates":[[[[-30.0,-3.6],[-29.0,-3.6],[-29.0,-2.8],[-30.0,-2.8],[-30.0,-3.6]]]]}');
        $this->em->persist($area);
        $this->em->flush();

        return $area;
    }

    // ── area scope ─────────────────────────────────────────────────────────────

    /** Each area owns its own list; the two never merge, even with the same words. */
    public function testKindsAreScopedToTheirArea(): void
    {
        $northern = $this->anArea('Northern Reserve');
        $southern = $this->anArea('Southern Reserve');

        $this->admin()->createKind($northern, 'Wildlife');
        $this->admin()->createKind($southern, 'Carcass');

        $here = $this->kinds()->forArea($northern);
        $there = $this->kinds()->forArea($southern);

        self::assertCount(1, $here);
        self::assertCount(1, $there);
        self::assertSame('Wildlife', $here[0]->getLabel());
        self::assertSame('Carcass', $there[0]->getLabel());
    }

    /** The same label is allowed in two different areas — it is unique per area only. */
    public function testTheSameLabelMayLiveInTwoAreas(): void
    {
        $a = $this->anArea('Area A');
        $b = $this->anArea('Area B');

        $this->admin()->createKind($a, 'Wildlife');
        $this->admin()->createKind($b, 'Wildlife');

        self::assertCount(1, $this->kinds()->forArea($a));
        self::assertCount(1, $this->kinds()->forArea($b));
    }

    /** An area starts empty — no seed, no starter pack. */
    public function testAnAreaStartsWithNoKinds(): void
    {
        $area = $this->anArea();

        self::assertFalse($this->kinds()->areaHasAny($area));
        self::assertSame([], $this->kinds()->forArea($area));
    }

    // ── wire-codes ─────────────────────────────────────────────────────────────

    /** A wire-code is derived from the label when none is given, and it is a slug. */
    public function testAWireCodeIsSluggedFromTheLabel(): void
    {
        $area = $this->anArea();
        $kind = $this->admin()->createKind($area, 'Snare / Trap');

        self::assertSame('snare-trap', $kind->getCode());
    }

    /** Two labels that slug the same get distinct, area-unique codes. */
    public function testWireCodesAreMadeUniqueWithinTheArea(): void
    {
        $area = $this->anArea();
        $first = $this->admin()->createKind($area, 'Wildlife');
        $second = $this->admin()->createKind($area, 'WILDLIFE!');

        self::assertSame('wildlife', $first->getCode());
        self::assertSame('wildlife-2', $second->getCode());
    }

    /** Renaming a kind never touches its wire-code — the whole point of a code. */
    public function testRenamingAKindKeepsItsWireCode(): void
    {
        $area = $this->anArea();
        $kind = $this->admin()->createKind($area, 'Carcass');
        $code = $kind->getCode();

        $this->admin()->renameKind($kind, 'Carcass / dead animal');

        self::assertSame($code, $kind->getCode());
        self::assertSame('Carcass / dead animal', $kind->getLabel());
    }

    /** A sub-category's wire-code is area-unique and independent per area. */
    public function testSubWireCodesAreAreaUniqueAndPerAreaIndependent(): void
    {
        $northern = $this->anArea('Northern Reserve');
        $southern = $this->anArea('Southern Reserve');
        $here = $this->admin()->createKind($northern, 'Wildlife');
        $there = $this->admin()->createKind($southern, 'Wildlife');

        $a = $this->admin()->createSubcategory($here, 'Sighting');
        $b = $this->admin()->createSubcategory($there, 'Sighting');

        // Independent areas may both hold the same code.
        self::assertSame('sighting', $a->getCode());
        self::assertSame('sighting', $b->getCode());
    }

    // ── uniqueness ─────────────────────────────────────────────────────────────

    public function testADuplicateKindLabelInTheSameAreaIsRefused(): void
    {
        $area = $this->anArea();
        $this->admin()->createKind($area, 'Wildlife');

        $this->expectException(TaxonomyConflictException::class);
        $this->admin()->createKind($area, 'wildlife');
    }

    public function testADuplicateSubLabelUnderTheSameKindIsRefused(): void
    {
        $area = $this->anArea();
        $kind = $this->admin()->createKind($area, 'Wildlife');
        $this->admin()->createSubcategory($kind, 'Sighting');

        $this->expectException(TaxonomyConflictException::class);
        $this->admin()->createSubcategory($kind, 'sighting');
    }

    /** A sub label unique WITHIN ITS PARENT may repeat under a different kind. */
    public function testTheSameSubLabelMayLiveUnderTwoKinds(): void
    {
        $area = $this->anArea();
        $wildlife = $this->admin()->createKind($area, 'Wildlife');
        $carcass = $this->admin()->createKind($area, 'Carcass');

        $this->admin()->createSubcategory($wildlife, 'Spoor');
        $second = $this->admin()->createSubcategory($carcass, 'Spoor');

        // Same label, different parent — allowed — but the wire-code is area-unique.
        $firstSub = $wildlife->getSubcategories()->first();
        self::assertNotFalse($firstSub);
        self::assertSame('spoor', $firstSub->getCode());
        self::assertSame('spoor-2', $second->getCode());
    }

    // ── deactivate hides but keeps ───────────────────────────────────────────────

    public function testDeactivatingAKindDimsItButKeepsItAndItsSubs(): void
    {
        $area = $this->anArea();
        $kind = $this->admin()->createKind($area, 'Fire Scar');
        $sub = $this->admin()->createSubcategory($kind, 'Recent burn');

        $this->admin()->deactivateKind($kind);
        $this->admin()->deactivateSubcategory($sub);

        // Still returned by the area read — dimmed, not hidden.
        $kinds = $this->kinds()->forArea($area);
        self::assertCount(1, $kinds);
        self::assertFalse($kinds[0]->isActive());
        $storedSub = $kinds[0]->getSubcategories()->first();
        self::assertNotFalse($storedSub);
        self::assertFalse($storedSub->isActive());

        // …and reactivation returns it exactly as it was.
        $this->admin()->reactivateKind($kind);
        self::assertTrue($this->kinds()->forArea($area)[0]->isActive());
    }

    // ── words arriving from the field ────────────────────────────────────────────

    /**
     * The wire-code is what a handset holds, so it is tried first; the label is
     * the fallback, because a client that only ever saw the printed word must not
     * mint a second kind meaning the same thing.
     */
    public function testAnArrivedWordMatchesAKindByCodeThenByLabel(): void
    {
        $area = $this->anArea();
        $kind = $this->admin()->createKind($area, 'Fire Scar');

        self::assertSame($kind, $this->admin()->resolveKind($area, $kind->getCode()));
        self::assertSame($kind, $this->admin()->resolveKind($area, 'fire scar'));
        self::assertCount(1, $this->kinds()->forArea($area));
    }

    /**
     * A word nobody configured is kept, retired, and given a FREE wire-code —
     * the label may collide with one an administrator retired earlier, and two
     * rows sharing a code is the one thing a saved filter could never separate.
     */
    public function testAWordNobodyConfiguredArrivesRetiredUnderAFreeCode(): void
    {
        $area = $this->anArea();
        $configured = $this->admin()->createKind($area, 'Snare', 'snare-line');

        $arrived = $this->admin()->resolveKind($area, 'Snare line');

        self::assertNotSame($configured, $arrived);
        self::assertFalse($arrived->isActive());
        self::assertSame('Snare line', $arrived->getLabel());
        self::assertNotSame('snare-line', $arrived->getCode());
        self::assertCount(2, $this->kinds()->forArea($area));
    }

    /** A sub-category is resolved under its kind and nowhere else. */
    public function testAnArrivedSubcategoryIsResolvedOnlyUnderItsOwnKind(): void
    {
        $area = $this->anArea();
        $wildlife = $this->admin()->createKind($area, 'Wildlife');
        $fire = $this->admin()->createKind($area, 'Fire Scar');
        $spoor = $this->admin()->createSubcategory($wildlife, 'Spoor');

        self::assertSame($spoor, $this->admin()->resolveSubcategory($wildlife, 'spoor'));

        // The same word under a different kind is a different sub-category.
        $arrived = $this->admin()->resolveSubcategory($fire, 'Spoor');
        self::assertNotSame($spoor, $arrived);
        self::assertSame($fire, $arrived->getKind());
        self::assertFalse($arrived->isActive());
        self::assertNotSame($spoor->getCode(), $arrived->getCode(), 'A wire-code is unique within the whole area.');
    }

    /** A retired sub-category keeps its wire-code — nothing is ever deleted. */
    public function testRenamingASubKeepsItsWireCode(): void
    {
        $area = $this->anArea();
        $kind = $this->admin()->createKind($area, 'Wildlife');
        $sub = $this->admin()->createSubcategory($kind, 'Spoor');
        $code = $sub->getCode();

        $this->admin()->renameSubcategory($sub, 'Spoor / Tracks');

        self::assertSame($code, $sub->getCode());
        self::assertSame('Spoor / Tracks', $sub->getLabel());
    }
}
