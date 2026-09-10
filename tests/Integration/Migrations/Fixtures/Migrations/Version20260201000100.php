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

namespace Uhifadhi\Patrol\Tests\Integration\Migrations\Fixtures\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * KEEPS RULE ONE: expand, backfill, contract, in one version. The lint has to
 * pass this or it is only testing that migrations exist.
 *
 * Never shipped — this directory is not registered with the migrations bundle.
 */
final class Version20260201000100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'A required column, filled before it is required';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE patrol_patrol ADD sector VARCHAR(64) DEFAULT NULL');
        $this->addSql("UPDATE patrol_patrol SET sector = 'unassigned' WHERE sector IS NULL");
        $this->addSql('ALTER TABLE patrol_patrol ALTER sector SET NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE patrol_patrol DROP sector');
    }
}
