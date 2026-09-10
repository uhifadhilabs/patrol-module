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
use Uhifadhi\Patrol\Entity\Observation;
use Uhifadhi\Patrol\Entity\Patrol;
use Uhifadhi\Patrol\Enum\PatrolSourceEnum;

/**
 * THE FILE-AS-INCIDENT LINK CARRIES THE REAL PLACE.
 *
 * The button hands the incidents module a prefill query string, and the
 * incidents module reads `lat`/`lng` and builds a GeoJSON Point in `[lon, lat]`
 * order from them. So the two keys must arrive as their names promise: `lat` the
 * latitude, `lng` the longitude. Getting them the wrong way round does not fail
 * loudly — it silently relocates the incident, and a −3.16/35.67 observation in
 * Ngorongoro lands as 35.67/−3.16, in the sea off Gibraltar.
 *
 * This suite boots the one environment where a stubbed `incident_new` route
 * exists (see {@see \Uhifadhi\Patrol\Tests\Integration\TestKernel}), so the
 * button renders and its href can be read.
 */
final class ObservationFileAsIncidentTest extends WebTestCase
{
    use EveryAreaRunsPatrols;

    /** Ngorongoro, roughly — the coordinate the product owner reported lost. */
    private const float NGORONGORO_LAT = -3.1620;
    private const float NGORONGORO_LNG = 35.6735;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private AreaOfInterest $area;
    private Patrol $patrol;
    private Observation $observation;

    protected function setUp(): void
    {
        // The environment whose kernel registers a stub `incident_new`, so the
        // File-as-incident button is present and its href can be inspected.
        $this->client = self::createClient(['environment' => 'incident_contract']);
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $this->em = $em;

        $schemaTool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);

        $this->area = new AreaOfInterest()->setSource('test fixture')->setName('ngorongoro')->setGeom(
            '{"type":"MultiPolygon","coordinates":[[[[35.4,-3.4],[35.9,-3.4],[35.9,-2.9],[35.4,-2.9],[35.4,-3.4]]]]}',
        );
        $this->em->persist($this->area);

        $this->patrol = new Patrol($this->area, 'walk')
            ->setStation('Crater rim')
            ->setStartedAt(new \DateTimeImmutable('today 06:10'))
            ->setEndedAt(new \DateTimeImmutable('today 12:30'))
            ->setSource(PatrolSourceEnum::Manual);
        $this->em->persist($this->patrol);

        // GeoJSON is [lon, lat] — the order the geometry column stores.
        $this->observation = new Observation($this->patrol, 'maintenance')
            ->setNote('Snare line found on the crater floor.')
            ->setPosition(\sprintf(
                '{"type":"Point","coordinates":[%F,%F]}',
                self::NGORONGORO_LNG,
                self::NGORONGORO_LAT,
            ))
            ->setLoggedAt(new \DateTimeImmutable('today 08:15'));
        $this->em->persist($this->observation);

        $this->em->flush();

        $this->everyAreaRunsPatrols($this->em);
    }

    protected function tearDown(): void
    {
        $this->em->close();
        parent::tearDown();

        while (true) {
            $previous = set_exception_handler(static fn () => null);
            restore_exception_handler();
            if (null === $previous) {
                break;
            }
            restore_exception_handler();
        }
    }

    public function testTheLinkCarriesLatitudeAndLongitudeUnswapped(): void
    {
        $crawler = $this->client->request('GET', \sprintf(
            '/areas/%s/modules/patrols/%s/observations/%s',
            $this->area->getUuidString(),
            $this->patrol->getUuid()->toRfc4122(),
            $this->observation->getUuid()->toRfc4122(),
        ));

        self::assertResponseIsSuccessful();

        $button = $crawler->filter('a.cta:contains("File as incident")');
        self::assertCount(1, $button, 'The File-as-incident button must render when incident_new exists.');

        $href = (string) $button->attr('href');
        $query = [];
        parse_str((string) parse_url($href, \PHP_URL_QUERY), $query);

        self::assertArrayHasKey('lat', $query);
        self::assertArrayHasKey('lng', $query);

        // The keys must mean what they say — latitude in lat, longitude in lng —
        // so the incidents module rebuilds the SAME Ngorongoro point and not its
        // mirror in the sea off Gibraltar.
        self::assertSame(self::NGORONGORO_LAT, (float) $query['lat'], 'lat must carry the latitude, not the longitude.');
        self::assertSame(self::NGORONGORO_LNG, (float) $query['lng'], 'lng must carry the longitude, not the latitude.');
    }
}
