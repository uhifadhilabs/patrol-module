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
 * WHAT A PATROL TYPE RECORDS, AND THE FOUR NUMBERS THAT FOLLOW FROM IT.
 *
 * A type's name is unlimited; its BASE is one of two fixed keys — `surface` or
 * `aerial` — and it is what a handset resolves its screen from instead of
 * guessing at the name. The base prefills the pace band the patrol is expected to
 * keep, how wide its track counts as covered, and where an observation is put;
 * each is then the type's own to change.
 *
 * EVERY COLUMN IS NULLABLE AND STAYS NULLABLE, and `base` is why: a type carried
 * over from before bases existed HAS none, and that is a state the Patrol types
 * section draws — a row asking for a base, with nothing blocked while it has
 * none. There is no universal value to write in either; guessing `surface` for
 * every existing type would silently tell every handset that a drone sortie
 * records the operator's own position as its track. So the choice is asked for on
 * the screen, once, by somebody who knows the answer.
 *
 * THE BACKFILL IS THEREFORE CONDITIONED ON THE BASE rather than skipped: where a
 * base IS set, the tunables are seeded from it. Nothing in this release has one
 * yet, so on this upgrade the four statements match no row; they are what makes
 * the version idempotent, and what makes "a tunable is never left empty under a
 * base" a fact of the schema's history rather than of one screen's code path.
 *
 * NOTHING IS CONTRACTED and nothing is dropped, so there is no `@destructive`
 * step here — going back down forgets the bases and the tunings, which is the
 * ordinary cost of undoing a column, and no patrol loses a word.
 */
final class Version20260912140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'What a patrol type records — its base — and the pace, buffer, placement and glyph that follow from it.';
    }

    public function up(Schema $schema): void
    {
        // ── expand ────────────────────────────────────────────────────────────
        $this->addSql('ALTER TABLE patrol_type ADD base VARCHAR(12) DEFAULT NULL');
        $this->addSql('ALTER TABLE patrol_type ADD pace_min_kmh INT DEFAULT NULL');
        $this->addSql('ALTER TABLE patrol_type ADD pace_max_kmh INT DEFAULT NULL');
        $this->addSql('ALTER TABLE patrol_type ADD coverage_buffer_m INT DEFAULT NULL');
        $this->addSql('ALTER TABLE patrol_type ADD observation_placement VARCHAR(16) DEFAULT NULL');
        $this->addSql('ALTER TABLE patrol_type ADD glyph VARCHAR(20) DEFAULT NULL');

        // ── backfill, where a base says what to fill with ──────────────────────
        $this->addSql(<<<'SQL'
            UPDATE patrol_type
            SET pace_min_kmh = COALESCE(pace_min_kmh, 2),
                pace_max_kmh = COALESCE(pace_max_kmh, 45),
                coverage_buffer_m = COALESCE(coverage_buffer_m, 150),
                observation_placement = COALESCE(observation_placement, 'at_position')
            WHERE base = 'surface'
            SQL);

        $this->addSql(<<<'SQL'
            UPDATE patrol_type
            SET pace_min_kmh = COALESCE(pace_min_kmh, 15),
                pace_max_kmh = COALESCE(pace_max_kmh, 70),
                coverage_buffer_m = COALESCE(coverage_buffer_m, 400),
                observation_placement = COALESCE(observation_placement, 'on_map')
            WHERE base = 'aerial'
            SQL);
    }

    /**
     * @destructive — going back down forgets which base each type records and
     *                every number tuned under it. No PATROL loses a word: the
     *                type, its key and its label are untouched, and the handsets
     *                fall back to reading the type by name as they did before.
     */
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE patrol_type DROP base');
        $this->addSql('ALTER TABLE patrol_type DROP pace_min_kmh');
        $this->addSql('ALTER TABLE patrol_type DROP pace_max_kmh');
        $this->addSql('ALTER TABLE patrol_type DROP coverage_buffer_m');
        $this->addSql('ALTER TABLE patrol_type DROP observation_placement');
        $this->addSql('ALTER TABLE patrol_type DROP glyph');
    }
}
