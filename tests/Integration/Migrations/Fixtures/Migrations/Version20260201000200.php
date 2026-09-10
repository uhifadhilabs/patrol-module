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
 * BREAKS RULE THREE, deliberately: it drops a column in up() and says nothing
 * about which release stopped reading it. No down() brings the data back.
 *
 * Never shipped — this directory is not registered with the migrations bundle.
 */
final class Version20260201000200 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'A column dropped without notice';
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
