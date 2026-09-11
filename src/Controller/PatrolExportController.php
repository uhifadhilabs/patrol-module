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

namespace Uhifadhi\Patrol\Controller;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\RegistryBundle\RegistryBundle;
use Uhifadhi\Patrol\Entity\Patrol;
use Uhifadhi\Patrol\Model\PatrolFilter;
use Uhifadhi\Patrol\Module\PatrolModuleProvider;
use Uhifadhi\Patrol\Repository\PatrolRepository;
use Uhifadhi\Patrol\Service\GpxWriter;
use Uhifadhi\Patrol\Service\PatrolListService;

/**
 * THE FILE ALWAYS CARRIES THE FILTER ON SCREEN — the design's `Export` action,
 * which has no screen of its own because there is nothing to choose: whatever
 * question the log is answering is the question the file answers.
 *
 * ONE ROUTE, TWO FORMATS, exactly as the design's comment writes them —
 * `export.csv` is the log as its own columns in its own order, `export.gpx` the
 * same patrols' recorded routes as one `<trk>` each.
 *
 * IT NARROWS THROUGH {@see PatrolListService::filtered()}, which is the same
 * predicate the log page is built from. A file that answered a slightly
 * different question from the table above it is the bug nobody finds until a
 * report has been filed on it.
 *
 * NOT EVERY PATROL HAS A ROUTE. A hand-logged one carries no geometry
 * (docs/design-decisions.md §4) and a sketch is never handed out as a recording,
 * so the GPX holds only the patrols that actually recorded one — and a month
 * with none of those is an empty document, not a 404: the question was asked and
 * the honest answer is "no recorded tracks".
 *
 * A plain class, wired explicitly, like every other controller here.
 */
#[Route(defaults: [RegistryBundle::MODULE_ROUTE_DEFAULT => PatrolModuleProvider::SLUG])]
final readonly class PatrolExportController
{
    /** The log's own columns, in the log's own order. */
    private const array COLUMNS = [
        'ref', 'name', 'type', 'type_label', 'station', 'station_label', 'zone',
        'lead', 'team', 'started_at', 'ended_at', 'distance_km', 'observations',
        'source', 'status', 'note',
    ];

    public function __construct(
        private PatrolRepository $patrols,
        private PatrolListService $list,
        private GpxWriter $gpx,
    ) {
    }

    #[Route(
        '/areas/{uuid}/modules/patrols/export.{_format}',
        name: 'patrol_export',
        requirements: ['uuid' => Requirement::UUID, '_format' => 'csv|gpx'],
        methods: ['GET'],
    )]
    public function export(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        Request $request,
        string $_format,
    ): Response {
        $now = new \DateTimeImmutable();
        $filter = PatrolFilter::fromRequest($request, $now);
        [$monthStart, $nextMonth] = $filter->window();

        $zones = $this->patrols->zonesForPatrols($area, $monthStart, $nextMonth);
        $rows = $this->list->filtered(
            $this->patrols->findByAreaStartedBetweenLatestFirst($area, $monthStart, $nextMonth),
            $zones,
            $filter,
        );

        $stem = 'patrols-'.$monthStart->format('Y-m');

        return 'gpx' === $_format
            ? $this->gpx($rows, $stem, $monthStart)
            : $this->csv($rows, $zones, $stem);
    }

    /**
     * @param list<Patrol>          $rows
     * @param array<string, string> $zones
     */
    private function csv(array $rows, array $zones, string $stem): Response
    {
        $response = new StreamedResponse(static function () use ($rows, $zones): void {
            $handle = fopen('php://output', 'w');
            \assert(false !== $handle);
            fputcsv($handle, self::COLUMNS, escape: '');
            foreach ($rows as $patrol) {
                $lead = $patrol->getLead();
                fputcsv($handle, [
                    $patrol->getRef(),
                    $patrol->getName() ?? '',
                    $patrol->getType(),
                    $patrol->getTypeLabel(),
                    $patrol->getStationKey() ?? '',
                    $patrol->getStation() ?? '',
                    $zones[$patrol->getUuid()->toRfc4122()] ?? '',
                    null === $lead ? '' : trim($lead->getFirstName().' '.$lead->getLastName()),
                    $patrol->getTeam() ?? '',
                    $patrol->getStartedAt()?->format(\DATE_ATOM) ?? '',
                    $patrol->getEndedAt()?->format(\DATE_ATOM) ?? '',
                    // Never a formatted, locale-dependent number: a file is read
                    // by a spreadsheet before it is read by a person.
                    null === $patrol->getDistanceKm() ? '' : (string) $patrol->getDistanceKm(),
                    (string) \count($patrol->getObservations()),
                    $patrol->getSource()->value,
                    $patrol->getStatus()->value,
                    $patrol->getNote() ?? '',
                ], escape: '');
            }
            fclose($handle);
        });

        return self::attach($response, 'text/csv; charset=UTF-8', $stem.'.csv');
    }

    /** @param list<Patrol> $rows */
    private function gpx(array $rows, string $stem, \DateTimeImmutable $monthStart): Response
    {
        $tracks = [];
        foreach ($rows as $patrol) {
            $track = $patrol->getTrack();
            if (null === $track || !$patrol->hasRecordedTrack()) {
                continue;
            }
            $tracks[] = [
                'name' => 'Patrol '.$patrol->getRef(),
                'lineString' => $track,
                'description' => mb_strtolower($patrol->getTypeLabel()).' patrol'
                    .(null !== $patrol->getStation() ? ' · '.$patrol->getStation() : ''),
            ];
        }

        $document = $this->gpx->writeTracks($stem, $tracks, $monthStart);

        $response = new StreamedResponse(static function () use ($document): void {
            echo $document;
        });

        return self::attach($response, 'application/gpx+xml; charset=UTF-8', $stem.'.gpx');
    }

    private static function attach(Response $response, string $contentType, string $filename): Response
    {
        $response->headers->set('Content-Type', $contentType);
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(
            HeaderUtils::DISPOSITION_ATTACHMENT,
            $filename,
        ));

        return $response;
    }
}
