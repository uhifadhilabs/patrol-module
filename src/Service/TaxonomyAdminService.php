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

namespace Uhifadhi\Patrol\Service;

use Doctrine\ORM\EntityManagerInterface;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Patrol\Entity\TaxonomyKind;
use Uhifadhi\Patrol\Entity\TaxonomySubcategory;
use Uhifadhi\Patrol\Exception\TaxonomyConflictException;
use Uhifadhi\Patrol\Repository\TaxonomyKindRepository;
use Uhifadhi\Patrol\Repository\TaxonomySubcategoryRepository;

/**
 * THE AREA-SCOPED PATROL TAXONOMY ADMIN, as a service — every write the manager
 * screen makes goes through here, so the rules the design will not bend are
 * enforced in ONE place rather than re-argued per route:
 *
 *  · UNIQUENESS IS TWO-SCOPED. A kind label is unique within the AREA and a
 *    sub-category label within its parent KIND; a wire-code is unique within the
 *    whole AREA at each level. A collision is refused ({@see TaxonomyConflictException})
 *    rather than quietly producing two rows a saved filter could never separate.
 *
 *  · THE WIRE-CODE IS BORN ONCE AND NEVER CHANGES. It is derived from the first
 *    label if the administrator gives none, made unique in the area, and then
 *    frozen — renaming the label never touches it, which is the whole reason a
 *    saved filter and an offline handset can hold it.
 *
 *  · NOTHING IS DELETED. Retirement flips a flag; the row, its code and every
 *    observation filed under it are kept, and one click brings it back.
 *
 *  · A WORD FROM THE FIELD THAT NOBODY CONFIGURED IS CREATED RETIRED, never
 *    refused ({@see self::resolveKind()}, {@see self::resolveSubcategory()}).
 *    The manager screen's writes and the handset sync's writes meet here, and
 *    only the screen's may fail: an observation refused for a word is an
 *    observation lost.
 *
 * SHALLOW BY DESIGN. Unlike the incident taxonomy admin, there is no behaviour
 * block to compose, no colour to clamp and no money direction to carry: a patrol
 * sub-category is a label and nothing more. The service is the poorer for it on
 * purpose — an observation that needs a structured question is filed as an
 * incident instead.
 *
 * It owns the flush: each call is one discrete admin action behind an HTTP POST,
 * so "did it save?" is the whole question and the caller has nothing to batch it
 * with.
 */
final class TaxonomyAdminService
{
    /** The width of both levels' label column. */
    private const int LABEL_LIMIT = 80;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TaxonomyKindRepository $kinds,
        private readonly TaxonomySubcategoryRepository $subcategories,
    ) {
    }

    // ── kinds ────────────────────────────────────────────────────────────────

    /**
     * Write the first — or the next — observation kind for an area. Empty areas
     * start here; there is no seed and no starter pack.
     *
     * @throws TaxonomyConflictException on a duplicate label or wire-code
     */
    public function createKind(AreaOfInterest $area, string $label, string $code = ''): TaxonomyKind
    {
        $label = $this->cleanLabel($label);
        if ('' === $label) {
            throw TaxonomyConflictException::label('A kind needs a name.');
        }
        if ($this->kinds->labelExistsInArea($area, $label)) {
            throw TaxonomyConflictException::label(\sprintf('This area already has a kind called "%s".', $label));
        }

        $code = $this->resolveKindCode($area, '' !== $code ? $code : $label);

        $kind = new TaxonomyKind($area, $code, $label);
        $kind->setPosition($this->kinds->maxPositionForArea($area) + 1);

        $this->em->persist($kind);
        $this->em->flush();

        return $kind;
    }

    /** @throws TaxonomyConflictException on a duplicate label */
    public function renameKind(TaxonomyKind $kind, string $label): TaxonomyKind
    {
        $label = $this->cleanLabel($label);
        if ('' === $label) {
            throw TaxonomyConflictException::label('A kind needs a name.');
        }
        if ($this->kinds->labelExistsInArea($kind->getArea(), $label, $kind)) {
            throw TaxonomyConflictException::label(\sprintf('This area already has a kind called "%s".', $label));
        }

        $kind->setLabel($label); // the wire-code is deliberately untouched
        $this->em->flush();

        return $kind;
    }

    public function deactivateKind(TaxonomyKind $kind): TaxonomyKind
    {
        $kind->deactivate();
        $this->em->flush();

        return $kind;
    }

    public function reactivateKind(TaxonomyKind $kind): TaxonomyKind
    {
        $kind->reactivate();
        $this->em->flush();

        return $kind;
    }

    /**
     * The kind one of this area's words names — its wire-code first, then its
     * label — or null where the area has never heard the word.
     *
     * The read is the same one {@see Api\VocabularySyncService} publishes from,
     * so a handset filing against what it was handed matches here by
     * construction.
     */
    public function findKindByWord(AreaOfInterest $area, string $word): ?TaxonomyKind
    {
        $word = $this->cleanLabel($word);
        if ('' === $word) {
            return null;
        }

        $found = $this->kinds->findOneByAreaAndCode($area, $word);
        if ($found instanceof TaxonomyKind) {
            return $found;
        }

        foreach ($this->kinds->forArea($area) as $kind) {
            if (mb_strtolower($kind->getLabel()) === mb_strtolower($word)) {
                return $kind;
            }
        }

        return null;
    }

    /**
     * The kind behind a word from the field — found, or CREATED RETIRED. Never
     * null and never a refusal, which is the same rule an unknown station gets
     * ({@see PatrolVocabularyService::resolveStation()}) and for the same
     * reason: refusing would throw away a real observation because a settings
     * screen and an app build disagreed about a word, and the observation is
     * gone where the disagreement is merely dimmed on the Settings section.
     *
     * Uniqueness is not re-argued here — the label may well already exist in
     * some form, and the wire-code is made free before the row is written.
     */
    public function resolveKind(AreaOfInterest $area, string $word): TaxonomyKind
    {
        $found = $this->findKindByWord($area, $word);
        if ($found instanceof TaxonomyKind) {
            return $found;
        }

        $label = $this->arrivedLabel($word);
        $kind = new TaxonomyKind($area, $this->resolveKindCode($area, $label), $label);
        $kind->setPosition($this->kinds->maxPositionForArea($area) + 1)->deactivate();

        $this->em->persist($kind);
        $this->em->flush();

        return $kind;
    }

    // ── sub-categories ─────────────────────────────────────────────────────────

    /** @throws TaxonomyConflictException on a duplicate label or wire-code */
    public function createSubcategory(TaxonomyKind $kind, string $label, string $code = ''): TaxonomySubcategory
    {
        $label = $this->cleanLabel($label);
        if ('' === $label) {
            throw TaxonomyConflictException::label('A sub-category needs a name.');
        }
        if ($this->subcategories->labelExistsInKind($kind, $label)) {
            throw TaxonomyConflictException::label(\sprintf('"%s" already has a sub-category called "%s".', $kind->getLabel(), $label));
        }

        $code = $this->resolveSubCode($kind->getArea(), '' !== $code ? $code : $label);

        $subcategory = new TaxonomySubcategory($kind, $code, $label);
        $subcategory->setPosition($this->subcategories->maxPositionForKind($kind) + 1);

        $this->em->persist($subcategory);
        $this->em->flush();

        return $subcategory;
    }

    /** @throws TaxonomyConflictException on a duplicate label */
    public function renameSubcategory(TaxonomySubcategory $subcategory, string $label): TaxonomySubcategory
    {
        $label = $this->cleanLabel($label);
        if ('' === $label) {
            throw TaxonomyConflictException::label('A sub-category needs a name.');
        }
        if ($this->subcategories->labelExistsInKind($subcategory->getKind(), $label, $subcategory)) {
            throw TaxonomyConflictException::label(\sprintf('"%s" already has a sub-category called "%s".', $subcategory->getKind()->getLabel(), $label));
        }

        $subcategory->setLabel($label);
        $this->em->flush();

        return $subcategory;
    }

    /**
     * The sub-category behind a word from the field, UNDER THE KIND IT CAME
     * WITH — found by wire-code then by label, and otherwise created retired,
     * exactly as {@see self::resolveKind()} does one level up. A sub-category
     * means nothing without its kind, so nothing here searches outside it.
     */
    public function resolveSubcategory(TaxonomyKind $kind, string $word): TaxonomySubcategory
    {
        $wanted = $this->cleanLabel($word);

        foreach ($kind->getSubcategories() as $subcategory) {
            if ($subcategory->getCode() === $wanted || mb_strtolower($subcategory->getLabel()) === mb_strtolower($wanted)) {
                return $subcategory;
            }
        }

        $label = $this->arrivedLabel($word);
        $subcategory = new TaxonomySubcategory($kind, $this->resolveSubCode($kind->getArea(), $label), $label);
        $subcategory->setPosition($this->subcategories->maxPositionForKind($kind) + 1)->deactivate();

        $this->em->persist($subcategory);
        $this->em->flush();

        return $subcategory;
    }

    public function deactivateSubcategory(TaxonomySubcategory $subcategory): TaxonomySubcategory
    {
        $subcategory->deactivate();
        $this->em->flush();

        return $subcategory;
    }

    public function reactivateSubcategory(TaxonomySubcategory $subcategory): TaxonomySubcategory
    {
        $subcategory->reactivate();
        $this->em->flush();

        return $subcategory;
    }

    // ── wire-codes ─────────────────────────────────────────────────────────────

    private function resolveKindCode(AreaOfInterest $area, string $source): string
    {
        $base = $this->slug($source, 40);
        $code = $base;
        $n = 2;
        while ($this->kinds->codeExistsInArea($area, $code)) {
            $code = $this->slug($base.'-'.$n, 40);
            ++$n;
        }

        return $code;
    }

    private function resolveSubCode(AreaOfInterest $area, string $source): string
    {
        $base = $this->slug($source, 60);
        $code = $base;
        $n = 2;
        while ($this->subcategories->codeExistsInArea($area, $code)) {
            $code = $this->slug($base.'-'.$n, 60);
            ++$n;
        }

        return $code;
    }

    /** A wire-code from a label: lowercase, hyphen-joined, ascii-safe. */
    private function slug(string $value, int $limit): string
    {
        $value = mb_strtolower(trim($value));
        $value = (string) preg_replace('/[^a-z0-9]+/', '-', $value);
        $value = trim($value, '-');
        $value = mb_substr($value, 0, $limit);
        $value = trim($value, '-');

        return '' !== $value ? $value : 'kind';
    }

    private function cleanLabel(string $label): string
    {
        return trim(preg_replace('/\s+/', ' ', $label) ?? '');
    }

    /**
     * A label for a word that arrived from a handset rather than from the
     * manager screen: cut to the column's width, and never empty, because this
     * path has no refusal to fall back on.
     */
    private function arrivedLabel(string $word): string
    {
        $label = mb_substr($this->cleanLabel($word), 0, self::LABEL_LIMIT);

        return '' !== $label ? $label : 'unspecified';
    }
}
