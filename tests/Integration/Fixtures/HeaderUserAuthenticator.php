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

namespace UhifadhiLabs\Patrol\Tests\Integration\Fixtures;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;
use Uhifadhi\Entity\User;

/**
 * Test stand-in for the HOST's bearer-token authenticator.
 *
 * The host owns api-platform's firewall and mints the tokens; this bundle's
 * tests are about what its OWN endpoints do once someone is authenticated —
 * whether they demand `patrols.record`, and whether they are idempotent. So the
 * credential here is deliberately trivial (an `X-Test-User` header naming an
 * email), while everything around it is real: a STATELESS firewall, the real
 * AuthorizationChecker, the real voters.
 *
 * Stateless matters and is not incidental. api-platform declares its operations
 * stateless, and a session-based login in these tests would both contradict that
 * and hide the fact that the sync endpoints must work with no session at all —
 * which is exactly how the field app talks to them.
 */
final class HeaderUserAuthenticator extends AbstractAuthenticator implements AuthenticationEntryPointInterface
{
    public const string HEADER = 'X-Test-User';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function supports(Request $request): bool
    {
        return $request->headers->has(self::HEADER);
    }

    public function authenticate(Request $request): Passport
    {
        $email = (string) $request->headers->get(self::HEADER);

        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);

        if (!$user instanceof User) {
            throw new CustomUserMessageAuthenticationException('No such test user.');
        }

        return new SelfValidatingPassport(
            new UserBadge($user->getUserIdentifier(), static fn (): UserInterface => $user),
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        return new Response(null, Response::HTTP_UNAUTHORIZED);
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return new Response(null, Response::HTTP_UNAUTHORIZED);
    }
}
