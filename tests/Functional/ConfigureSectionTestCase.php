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

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Patrol\Entity\Patrol;
use Uhifadhi\Patrol\Entity\PatrolType;
use Uhifadhi\Patrol\Entity\Station;
use Uhifadhi\Patrol\Repository\PatrolTypeRepository;
use Uhifadhi\Patrol\Repository\StationRepository;
use Uhifadhi\Patrol\Tests\Fixtures\Vocabulary;
use Uhifadhi\Patrol\Tests\Integration\Fixtures\FixedRecordVoter;

/**
 * ONE AREA WITH ONE PATROL IN IT, AND A WAY TO POST TO A SECTION OF THE CONFIGURE
 * PAGE — shared by the sections that are a form rather than a screen.
 *
 * THE TOKEN IS READ OFF THE SECTION BEING TESTED, never hardcoded: every form on
 * every section of this module's configure page carries the same id, and a test
 * that spelled it out would still pass the day the page stopped rendering one.
 */
abstract class ConfigureSectionTestCase extends WebTestCase
{
    use EveryAreaRunsPatrols;

    protected KernelBrowser $client;
    protected EntityManagerInterface $em;
    protected AreaOfInterest $area;

    /** The section this case drives, as it is addressed in the URL. */
    abstract protected function section(): string;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $this->em = $em;

        $schemaTool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);

        $this->area = new AreaOfInterest()->setSource('test fixture')->setName('demo reserve')->setGeom(
            '{"type":"MultiPolygon","coordinates":[[[[12.2,-5.8],[12.5,-5.8],[12.5,-5.5],[12.2,-5.5],[12.2,-5.8]]]]}',
        );
        $this->em->persist($this->area);

        $this->em->persist(new Patrol($this->area, Vocabulary::type($this->em, $this->area, 'walk'))
            ->setStationRecord(Vocabulary::station($this->em, $this->area, 'North post'))
            ->setStartedAt(new \DateTimeImmutable('today 06:10'))
            ->setEndedAt(new \DateTimeImmutable('today 09:10')));

        $this->everyAreaRunsPatrols($this->em);
    }

    protected function configureUrl(?string $section = null): string
    {
        return '/areas/'.$this->area->getUuidString().'/modules/patrols/configure'
            .(null === $section ? '' : '/'.$section);
    }

    protected function signInAsManager(): void
    {
        $manager = new User()->setPassword('x')->setEmail(FixedRecordVoter::MANAGER_EMAIL)
            ->setFirstName('Mara')->setLastName('Manager');
        $this->em->persist($manager);
        $this->em->flush();
        $this->client->loginUser($manager);
    }

    protected function signInAsRecorder(): void
    {
        $recorder = new User()->setPassword('x')->setEmail(FixedRecordVoter::RECORDER_EMAIL)
            ->setFirstName('Rita')->setLastName('Recorder');
        $this->em->persist($recorder);
        $this->em->flush();
        $this->client->loginUser($recorder);
    }

    /** The token this section's forms carry, read off the section itself. */
    protected function token(): string
    {
        $crawler = $this->client->request('GET', $this->configureUrl($this->section()));

        return (string) $crawler->filter('input[name="_token"]')->attr('value');
    }

    /**
     * @param array<string, mixed> $fields
     */
    protected function post(string $url, array $fields): void
    {
        $this->client->request('POST', $url, ['_token' => $this->token(), ...$fields]);
    }

    protected function types(): PatrolTypeRepository
    {
        $repository = $this->em->getRepository(PatrolType::class);
        self::assertInstanceOf(PatrolTypeRepository::class, $repository);

        return $repository;
    }

    protected function stations(): StationRepository
    {
        $repository = $this->em->getRepository(Station::class);
        self::assertInstanceOf(StationRepository::class, $repository);

        return $repository;
    }

    protected function firstType(): PatrolType
    {
        $type = $this->types()->findOneByAreaAndKey($this->area, 'walk');
        self::assertInstanceOf(PatrolType::class, $type);

        return $type;
    }
}
