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

namespace Uhifadhi\Patrol\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * THE FINER WORD AN OBSERVATION WAS FILED UNDER.
 *
 * The area's observation taxonomy has two levels and the vocabulary endpoint
 * publishes both, so a field client can record a sub-category — and until now
 * there was nowhere on the observation to put it. This is that column.
 *
 * ONE NULLABLE COLUMN ON A POPULATED TABLE, AND IT STAYS NULLABLE. There is no
 * universal value to backfill: an observation whose kind has no sub-categories
 * has none by definition, and writing the kind's own code into it would invent a
 * second level the ranger was never offered. Null means exactly what it says, so
 * the expand → backfill → contract rule has nothing to do here.
 *
 * Sixty characters, because that is the width of a sub-category wire-code
 * (patrol_taxonomy_subcategory.code) and this column holds one.
 */
final class Version20260912090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The sub-category a field client filed an observation under, beside its kind.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE patrol_observation ADD subcategory VARCHAR(60) DEFAULT NULL');
    }

    /**
     * @destructive — going back down forgets the finer word every observation
     *                that carried one was filed under. The kind on `category` is
     *                untouched, so nothing becomes unreadable; the detail is
     *                simply gone, and the handsets that sent it have moved on.
     */
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE patrol_observation DROP subcategory');
    }
}
