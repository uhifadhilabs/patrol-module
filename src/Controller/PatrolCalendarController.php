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
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Twig\Environment;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\RegistryBundle\RegistryBundle;
use Uhifadhi\Patrol\Module\PatrolModuleProvider;
use Uhifadhi\Patrol\Repository\PatrolTypeRepository;
use Uhifadhi\Patrol\Service\PatrolCalendar;

/**
 * THE PATROLS MONTH AS ITS OWN PAGE (PL·11).
 *
 * ONE ADDRESS AND ONE SHAPE. The month used to be served twice — a bare
 * fragment for the widget's ‹ › to swap in over XHR, and a framed page for
 * anybody who navigated to the URL — because the stepper was JavaScript. It
 * is not any more: the atlas's month draws its arrows as LINKS, so stepping a
 * month is a navigation like every other and there is nothing left to fetch.
 *
 * WHAT TRAVELS IN THE QUERY. `?month=YYYY-MM` is the month, and anything else
 * is a 400 rather than a guess; `?type=` is the surface's own control, the
 * patrol type the chip in the stepper's row has narrowed to. Both are
 * unbounded in the sense that matters: a month with no patrols renders as a
 * full grid of empty days, never an error.
 *
 * Same gating as the dashboard this page belongs to: area-nested, the uuid
 * resolved by MapEntity (a wrong or unknown area is a 404), and no further
 * permission — it shows exactly what the dashboard already shows the same
 * caller, one month at a time. It is registered unconditionally
 * (config/services.php) for the same reason the dashboard controller is: the
 * widget renders in hosts without SecurityBundle too.
 *
 * A plain class, not a Symfony AbstractController subclass — see PatrolController
 * and config/services.php for the reusable-bundle rule.
 */
// EVERY ROUTE BELOW BELONGS TO THIS MODULE, and says so: where an area has
// parked Patrols, the registry closes these routes before the controller runs.
#[Route(defaults: [RegistryBundle::MODULE_ROUTE_DEFAULT => PatrolModuleProvider::SLUG])]
final class PatrolCalendarController
{
    /** The only month shape accepted: four-digit year, two-digit month. */
    private const string MONTH_PATTERN = '/^\d{4}-(0[1-9]|1[0-2])$/';

    public function __construct(
        private readonly Environment $twig,
        private readonly PatrolCalendar $calendar,
        private readonly PatrolTypeRepository $types,
    ) {
    }

    /**
     * Static path segment under the module, so it cannot collide with the patrol
     * detail route (/areas/{uuid}/modules/patrols/{patrol}) — that one requires a
     * UUID, and "calendar" is not one.
     */
    #[Route(
        '/areas/{uuid}/modules/patrols/calendar',
        name: 'patrol_calendar',
        requirements: ['uuid' => Requirement::UUID],
        methods: ['GET'],
    )]
    #[IsGranted('patrols.read', subject: 'area')]
    public function calendar(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        Request $request,
    ): Response {
        $now = new \DateTimeImmutable();
        $month = $this->month($request, $now);

        // The AREA's own words: what the chip may narrow to, and what it prints.
        $types = $this->types->findVocabularyByArea($area);
        $type = $this->type($request);

        return new Response($this->twig->render('@UhifadhiPatrol/calendar/show.html.twig', [
            'area' => $area,
            'types' => $types,
            'type' => $type,
            'month' => $month,
            'now' => $now,
            // The three months the stepper and the chip link to, spelled once
            // here so no template does date arithmetic to build a url.
            'monthKey' => $month->format('Y-m'),
            'previousKey' => $month->modify('-1 month')->format('Y-m'),
            'nextKey' => $month->modify('+1 month')->format('Y-m'),
            'feed' => $this->calendar,
            'scope' => PatrolCalendar::scopeFor((string) $area->getUuidString(), $type),
        ]));
    }

    /**
     * The requested month as its first instant. Absent means "the month the
     * calendar opens on", so the endpoint is also a plain reload of the current
     * month; anything that is not YYYY-MM is rejected rather than guessed at.
     */
    private function month(Request $request, \DateTimeImmutable $now): \DateTimeImmutable
    {
        // getString() is deliberate: a query bag holding an ARRAY for "month"
        // is already a bad request, and this raises it as one rather than
        // letting an array reach the pattern check.
        $requested = $request->query->getString('month');
        if ('' === $requested) {
            return $now->modify('first day of this month')->setTime(0, 0);
        }

        if (1 !== preg_match(self::MONTH_PATTERN, $requested)) {
            throw new BadRequestHttpException('The month must be given as YYYY-MM.');
        }

        $month = \DateTimeImmutable::createFromFormat('!Y-m-d', $requested.'-01');
        if (false === $month) {
            throw new BadRequestHttpException('The month must be given as YYYY-MM.');
        }

        return $month;
    }

    /**
     * The patrol type the chip has narrowed to, or null for all of them. Not
     * checked against the area's vocabulary, exactly as the module's filter row
     * does not check it: a key nothing matches draws an empty month, which is
     * the honest answer to a question about patrols that do not exist.
     */
    private function type(Request $request): ?string
    {
        $type = trim($request->query->getString('type'));

        return '' === $type ? null : $type;
    }
}
