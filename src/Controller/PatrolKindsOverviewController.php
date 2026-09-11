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
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Twig\Environment;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\RegistryBundle\RegistryBundle;
use Uhifadhi\Patrol\Module\PatrolModuleProvider;
use Uhifadhi\Patrol\Service\PatrolKindsOverviewService;

/**
 * THE THIRD PLACE PATROL DATA LIVES — every observation kind this area files
 * under, and what has been filed against each word.
 *
 * READ-ONLY, and that is the point of it being a tab rather than a section of
 * the configure page: the words are data, so an operator with no authority over
 * them still has an address where the counts are. Nothing here writes, and the
 * one way to the writing screen is a link.
 *
 * A KIND IS AN ADDRESS. The design switches panes in the browser because every
 * matrix is already on the page; the app gives each kind its own url instead,
 * so a kind can be linked to, opened in a tab and reached by the back button.
 * With no kind in the url the first one opens.
 *
 * A plain class, not a Symfony AbstractController subclass — see
 * PatrolController and config/services.php for the reusable-bundle rule.
 *
 * @phpstan-import-type KindOverviewRow from PatrolKindsOverviewService
 */
#[Route(defaults: [RegistryBundle::MODULE_ROUTE_DEFAULT => PatrolModuleProvider::SLUG])]
final class PatrolKindsOverviewController
{
    public function __construct(
        private readonly Environment $twig,
        private readonly PatrolKindsOverviewService $overview,
    ) {
    }

    #[Route(
        '/areas/{uuid}/modules/patrols/observation-kinds/{kind}',
        name: 'patrol_kinds_overview',
        requirements: ['uuid' => Requirement::UUID, 'kind' => Requirement::UUID],
        defaults: ['kind' => null],
        methods: ['GET'],
        priority: 2,
    )]
    public function show(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        ?string $kind = null,
    ): Response {
        $kinds = $this->overview->forArea($area, new \DateTimeImmutable());

        return new Response($this->twig->render('@UhifadhiPatrol/kinds/overview.html.twig', [
            'area' => $area,
            'kinds' => $kinds,
            'selected' => $this->selected($kinds, $kind),
        ]));
    }

    /**
     * Which kind is open: the one in the url, or the first. A kind that is not
     * this area's is a 404 — an address for somebody else's word never renders
     * a page about it.
     *
     * @param list<KindOverviewRow> $kinds
     *
     * @return KindOverviewRow|null
     */
    private function selected(array $kinds, ?string $uuid): ?array
    {
        if ([] === $kinds) {
            return null;
        }

        if (null === $uuid) {
            return $kinds[0];
        }

        foreach ($kinds as $row) {
            if ($row['kind']->getUuid()->toRfc4122() === $uuid) {
                return $row;
            }
        }

        throw new NotFoundHttpException('No such observation kind in this area.');
    }
}
