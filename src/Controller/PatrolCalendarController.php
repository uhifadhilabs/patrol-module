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
use Twig\Environment;
use Uhifadhi\Area\Entity\AreaOfInterest;
use Uhifadhi\Patrol\Repository\PatrolRepository;
use Uhifadhi\Patrol\Service\PatrolDashboardService;

/**
 * One month of the patrol calendar (PL·11) as an HTML FRAGMENT — what the
 * widget's ‹ › controls fetch.
 *
 * Server-rendered per month on purpose: a day cell holds real patrols with real
 * refs, colours and links, so stepping to another month is a different QUERY,
 * not a client-side redraw of data the browser happens to be holding. The
 * fragment is the same partial the dashboard renders inline, so the two can
 * never drift.
 *
 * The month travels as ?month=YYYY-MM (an unbounded walk in either direction —
 * a month with no patrols renders as a full grid of empty days, never an error).
 * Anything else is a 400: this endpoint answers the widget, and a month it
 * cannot read is a malformed request, not an empty month.
 *
 * Same gating as the dashboard this fragment belongs to: area-nested, the uuid
 * resolved by MapEntity (a wrong or unknown area is a 404), and no further
 * permission — it shows exactly what the dashboard already shows the same
 * caller, one month at a time. It is registered unconditionally (config/services.php)
 * for the same reason the dashboard controller is: the widget renders in hosts
 * without SecurityBundle too, and a nav control that 404s there would be worse
 * than no nav at all.
 *
 * A plain class, not a Symfony AbstractController subclass — see PatrolController
 * and config/services.php for the reusable-bundle rule.
 */
final class PatrolCalendarController
{
    /** The only month shape accepted: four-digit year, two-digit month. */
    private const string MONTH_PATTERN = '/^\d{4}-(0[1-9]|1[0-2])$/';

    /**
     * @param array<string, array{label: string}> $types the deployment's patrol.types vocabulary
     */
    public function __construct(
        private readonly Environment $twig,
        private readonly PatrolRepository $patrols,
        private readonly PatrolDashboardService $dashboard,
        private readonly array $types,
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
    public function calendar(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        Request $request,
    ): Response {
        $now = new \DateTimeImmutable();
        $month = $this->month($request, $now);

        // Exactly the window the grid can draw — the month plus the dimmed
        // leading/trailing days, which carry pills too.
        [$from, $until] = PatrolDashboardService::calendarRange($month);

        return new Response($this->twig->render('@UhifadhiPatrol/dashboard/_cal_body.html.twig', [
            'area' => $area,
            'types' => $this->types,
            'typeColor' => PatrolDashboardService::typeColors($this->types),
            'month' => $month,
            'cells' => $this->dashboard->calendarFor(
                $this->patrols->findByAreaStartedBetween($area, $from, $until),
                $month,
                $now,
            ),
        ]));
    }

    /**
     * The requested month as its first instant. Absent means "the month the
     * widget opens on", so the endpoint is also a plain reload of the current
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
}
