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
 * KEEPS RULE THREE: the drop rides a later release than the code that stopped
 * reading the column, and the file says which.
 *
 * Never shipped — this directory is not registered with the migrations bundle.
 *
 * @destructive 1.4 — patrol_patrol.legacy_grid stopped being read in 1.3
 */
final class Version20260201000300 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'A column dropped a release after it stopped being read';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE patrol_patrol DROP legacy_grid');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE patrol_patrol ADD legacy_grid VARCHAR(32) DEFAULT NULL');
    }
}
