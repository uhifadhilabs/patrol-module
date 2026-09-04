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

namespace Uhifadhi\Patrol\Tests\Integration;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\Tools\SchemaTool;
use Uhifadhi\ModuleContracts\Entity\UserInterface;
use Uhifadhi\Patrol\Entity\Observation;
use Uhifadhi\Patrol\Entity\ObservationAmendment;
use Uhifadhi\Patrol\Entity\Patrol;
use Uhifadhi\Patrol\Entity\PatrolEvent;
use Uhifadhi\Team\Entity\User;

/**
 * EVERY PERSON ON A PATROL RECORD IS POINTED AT THROUGH THE CONTRACT.
 *
 * Five columns on four tables name somebody: who led the patrol, who is holding
 * it, who recorded the observation, who acted on the event, who amended the
 * observation. None of them may name an account CLASS. The account belongs to
 * whichever module an installation gets its team from, and a module that
 * type-hinted that class would be a module you cannot install without it — so
 * the association is declared against {@see UserInterface} and the installation
 * resolves it with one line of doctrine config.
 *
 * The test asserts both halves, because either alone proves nothing: the
 * ATTRIBUTE says the module declared the contract, and the METADATA says the
 * resolution the installation wrote actually fired and produced a real table to
 * key against.
 */
final class UserContractTest extends IntegrationTestCase
{
    /** @return iterable<string, array{class-string, string}> */
    public static function personAssociations(): iterable
    {
        yield 'patrol lead' => [Patrol::class, 'lead'];
        yield 'patrol hold' => [Patrol::class, 'heldBy'];
        yield 'observation recorder' => [Observation::class, 'recordedBy'];
        yield 'event actor' => [PatrolEvent::class, 'actor'];
        yield 'amendment author' => [ObservationAmendment::class, 'author'];
    }

    /**
     * @param class-string $entity
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('personAssociations')]
    public function testThePersonIsDeclaredAgainstTheContract(string $entity, string $property): void
    {
        $attributes = new \ReflectionProperty($entity, $property)->getAttributes(ORM\ManyToOne::class);

        self::assertCount(1, $attributes, $entity.'::$'.$property.' is not a ManyToOne.');
        self::assertSame(UserInterface::class, $attributes[0]->newInstance()->targetEntity);
    }

    /**
     * @param class-string $entity
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('personAssociations')]
    public function testTheContractIsResolvedToTheInstallationsAccountClass(string $entity, string $property): void
    {
        $association = $this->em->getClassMetadata($entity)->getAssociationMapping($property);

        self::assertSame(User::class, $association->targetEntity);
        self::assertNotSame(UserInterface::class, $association->targetEntity);
    }

    /**
     * A RECORD OUTLIVES THE ACCOUNT ON IT. Removing somebody from the team does
     * not un-walk the patrol they led, so the foreign key sets the column null
     * and the row stays — the opposite of a saved dashboard, which has no
     * meaning once its owner is gone. The guarantee is the database's, so it
     * holds for a DELETE written by hand as well as one the ORM issues.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('personAssociations')]
    public function testRemovingAnAccountLeavesTheRecordThatNamedIt(string $entity, string $property): void
    {
        $metadata = $this->em->getClassMetadata($entity);

        // THE ACCOUNT TABLE IS READ, NEVER NAMED. What an installation calls its
        // people is its own business — this one resolves the contract to
        // uhifadhi/team-module's class, which stores them in `team_user`, and an
        // installation with its own account class stores them somewhere else
        // again. Asking the mapping is the same reading the module's own SQL
        // does, and it is why this assertion survived that change.
        $account = $this->em->getClassMetadata(User::class)->getTableName();
        $sql = implode("\n", new SchemaTool($this->em)->getCreateSchemaSql([$metadata]));

        self::assertStringContainsString(
            \sprintf('REFERENCES %s (id) ON DELETE SET NULL', $account),
            $sql,
            \sprintf('%s::$%s must survive the account being deleted.', $entity, $property),
        );
    }
}
