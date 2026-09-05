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
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Uhifadhi\Area\Entity\AreaOfInterest;
use Uhifadhi\Patrol\Tests\Integration\Fixtures\FixedRecordVoter;
use Uhifadhi\Team\Entity\User;

/**
 * Shared ground for the field-sync tests: a real database, a real area, a user
 * who may record and one who may not, and the small helpers that make a test
 * read like the mobile flow it is checking.
 *
 * Authentication is a test header, not a real bearer token, and deliberately:
 * the TOKEN is the host's concern (it owns api-platform's firewall), while what
 * this module must prove is that its endpoints demand `patrols.record` and
 * behave idempotently. The firewall these requests cross is nonetheless a real
 * STATELESS one — see HeaderUserAuthenticator.
 */
abstract class FieldSyncTestCase extends WebTestCase
{
    use EveryAreaRunsPatrols;

    protected KernelBrowser $client;
    /** The account whose credential every subsequent request carries. */
    private ?User $actingAs = null;
    protected EntityManagerInterface $em;
    protected AreaOfInterest $area;
    protected User $recorder;
    protected User $bystander;

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
            '{"type":"MultiPolygon","coordinates":[[[[35.41,-3.25],[35.52,-3.25],[35.52,-3.15],[35.41,-3.15],[35.41,-3.25]]]]}',
        );
        $this->em->persist($this->area);

        $this->recorder = new User()->setPassword('x')->setEmail(FixedRecordVoter::RECORDER_EMAIL)
            ->setFirstName('Rita')->setLastName('Recorder')->setRangerCode('sl-0142');
        $this->bystander = new User()->setPassword('x')->setEmail('bystander@example.test')
            ->setFirstName('Ben')->setLastName('Bystander')->setRangerCode('nk-0088');
        $this->em->persist($this->recorder);
        $this->em->persist($this->bystander);
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

    /**
     * Whose credential the next requests carry. The stand-in for the phone
     * holding a bearer token — see HeaderUserAuthenticator for why the module's
     * tests do not mint a real one.
     */
    protected function actingAs(User $user): void
    {
        $this->actingAs = $user;
    }

    /**
     * The headers the field app sends on every request (§1), plus the test
     * credential.
     *
     * @return array<string, string>
     */
    protected function apiHeaders(): array
    {
        $headers = [
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_DORIA_DEVICE' => '0f9ca41e-1f2b-4c33-9b5e-8e0a5f7f2c11',
            'HTTP_X_DORIA_VERSION' => '0.1.0',
        ];

        if (null !== $this->actingAs) {
            $headers['HTTP_X_TEST_USER'] = (string) $this->actingAs->getEmail();
        }

        return $headers;
    }

    /** @param array<string, mixed> $body */
    protected function postJson(string $uri, array $body): void
    {
        $this->client->request(
            'POST',
            $uri,
            server: ['CONTENT_TYPE' => 'application/json'] + $this->apiHeaders(),
            content: json_encode($body, \JSON_THROW_ON_ERROR),
        );
    }

    /** The decoded response body. */
    protected function json(): mixed
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    /**
     * The response as a contract payload.
     *
     * @return array<string, mixed>
     */
    protected function payload(): array
    {
        $decoded = $this->json();
        self::assertIsArray($decoded, 'The endpoint did not answer with a JSON object.');

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * Create a patrol the way the app does, returning its clientUuid.
     *
     * @param array<string, mixed> $overrides
     */
    protected function createPatrol(array $overrides = []): string
    {
        $clientUuid = $overrides['clientUuid'] ?? '8f1f4e02-6b1a-4f34-8f8f-1a0f19a1c111';
        self::assertIsString($clientUuid);

        $this->postJson('/api/patrols', array_merge([
            'clientUuid' => $clientUuid,
            'areaId' => $this->area->getUuidString(),
            // A type from THIS deployment's configured vocabulary — see the
            // note in TestKernel on why the tests do not hard-code the app's.
            'type' => 'walk',
            'stationId' => 'north-gate',
            'team' => ['sl-0142', 'nk-0088'],
            'startedAt' => '2026-08-23T06:44:12Z',
            'endedAt' => '2026-08-23T09:54:38Z',
            'droneId' => null,
            'mission' => null,
            'deviceId' => '0f9ca41e-1f2b-4c33-9b5e-8e0a5f7f2c11',
            'appVersion' => '0.1.0',
        ], $overrides));

        return $clientUuid;
    }

    /**
     * A minimal but genuinely valid JPEG. Real bytes, because the upload path
     * detects the type from CONTENT rather than trusting the filename — a test
     * with a text file pretending to be a photo would exercise the wrong branch.
     */
    protected function jpegUpload(string $name = 'e77c0000-0000-4000-8000-000000000001.jpg'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'patrol-photo').'.jpg';

        $image = imagecreatetruecolor(8, 8);
        self::assertNotFalse($image);
        imagejpeg($image, $path);

        // test mode: the file was not really uploaded by PHP, so move() works.
        return new UploadedFile($path, $name, 'image/jpeg', null, true);
    }
}
