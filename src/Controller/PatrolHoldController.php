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

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Uhifadhi\Entity\AreaOfInterest;
use Uhifadhi\ModuleContracts\Entity\UserInterface;
use Uhifadhi\Patrol\Entity\Patrol;

/**
 * Hold a discarded patrol for review, and let it go again.
 *
 * The one write the detail screen has. A discarded patrol is on its way to real
 * deletion (`patrol:purge-discarded`), and a hold is the brake somebody pulls
 * when that patrol turns out to matter after all — an investigation, a disputed
 * shift, a discard that looks like a mistake. It stops the retention clock and
 * has no expiry: only a person clears it.
 *
 * SCOPE, deliberately narrow. It writes two columns and nothing else. It is not
 * an edit screen, it does not un-discard a patrol, and it never touches
 * `webEditedAt` — that flag means "a human corrected this record" and answers
 * the phone with `patrol_immutable` (§10). A hold corrects nothing; a patrol
 * held while its handset is still syncing must keep accepting the parts it was
 * promised, or holding a patrol would silently destroy the evidence somebody
 * held it to look at.
 *
 * Gated by `patrols.record`, the one permission this module declares. Holding is
 * management chrome over field records, and the module deliberately does not
 * invent a second permission for it: a deployment that has decided who may write
 * patrol records has already answered this question, and a permission catalogue
 * that grows a line per button is one nobody administers correctly.
 *
 * Registered only where the host runs SecurityBundle, exactly like the recording
 * screens and for the same reason — see PatrolRecordController's docblock and
 * UhifadhiPatrolBundle::loadExtension().
 *
 * A plain class, not a Symfony AbstractController subclass — see PatrolController
 * and config/services.php for the reusable-bundle rule.
 */
final class PatrolHoldController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UrlGeneratorInterface $urls,
        private readonly AuthorizationCheckerInterface $authorizationChecker,
        private readonly TokenStorageInterface $tokenStorage,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
    ) {
    }

    /**
     * One route for both directions. The form posts `hold=0` or `hold=1` and the
     * page re-renders in the other state — a toggle is one decision, and giving
     * it two URLs invites the pair to drift.
     */
    #[Route(
        '/areas/{uuid}/modules/patrols/{patrol}/hold',
        name: 'patrol_hold',
        requirements: ['uuid' => Requirement::UUID, 'patrol' => Requirement::UUID],
        methods: ['POST'],
    )]
    public function toggle(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        #[MapEntity(mapping: ['patrol' => 'uuid'])] Patrol $patrol,
        Request $request,
    ): Response {
        // Area nesting is the access rule on every patrol screen: a patrol
        // reached through the wrong parent is a 404, never a write on somebody
        // else's data.
        if ($patrol->getArea()->getId() !== $area->getId()) {
            throw new NotFoundHttpException('That patrol belongs to another area.');
        }

        $this->denyUnlessRecorder();
        $this->denyUnlessCsrfValid($patrol, $request);

        // Only a discarded patrol has a clock to stop. Holding anything else
        // would be a control with no effect, and a 404 says that plainly rather
        // than storing a flag nothing reads.
        if (!$patrol->isDiscarded()) {
            throw new NotFoundHttpException('Only a discarded patrol can be held for review.');
        }

        if ($request->request->getBoolean('hold')) {
            $patrol->hold($this->currentUser());
        } else {
            $patrol->release();
        }

        $this->entityManager->flush();

        return new RedirectResponse($this->urls->generate('patrol_show', [
            'uuid' => $area->getUuid(),
            'patrol' => $patrol->getUuid(),
        ]));
    }

    /** The CSRF token id the detail page mints, scoped to the one patrol. */
    public static function csrfTokenId(Patrol $patrol): string
    {
        return 'patrol_hold_'.$patrol->getUuid()->toRfc4122();
    }

    /**
     * Checked in code rather than with #[IsGranted]: that attribute is honoured
     * by a listener in symfony/security-http, which this bundle does not
     * require — see PatrolRecordController for the full reasoning.
     */
    private function denyUnlessRecorder(): void
    {
        if (!$this->authorizationChecker->isGranted(PatrolRecordController::RECORD_PERMISSION)) {
            throw new AccessDeniedException('Holding a patrol for review requires "'.PatrolRecordController::RECORD_PERMISSION.'".');
        }
    }

    private function denyUnlessCsrfValid(Patrol $patrol, Request $request): void
    {
        $submitted = $request->request->getString('_token');

        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken(self::csrfTokenId($patrol), $submitted))) {
            throw new AccessDeniedException('Invalid CSRF token for the patrol hold.');
        }
    }

    /** Who pulled the brake, so the page can name them. */
    private function currentUser(): ?UserInterface
    {
        $user = $this->tokenStorage->getToken()?->getUser();

        return $user instanceof UserInterface ? $user : null;
    }
}
