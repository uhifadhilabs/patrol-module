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
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Patrol\Tests\Integration\Fixtures\FixedRecordVoter;

/**
 * SOMEBODY IS SIGNED IN, AND THEY MAY READ PATROLS — said once, out loud,
 * because it is not true by default and it used not to matter.
 *
 * Every page of this module now names the pair it enforces, reading
 * included: `patrols.read` on the area the page is drawn under. That is the
 * model the core moved to — nothing is held unless a position says so, a
 * person with no position holds nothing, and reading is not the exception.
 * So a suite that requested a dashboard as nobody used to get the page and
 * now gets 403, correctly.
 *
 * The bystander is the DEFAULT because it is the weakest honest caller: it
 * proves a screen renders for somebody who may only read it, and leaves
 * every test about recording, managing, exporting or configuring to say so
 * by signing in as the tier that may.
 *
 * @see FixedRecordVoter for the three tiers and what each holds
 */
trait SomebodyIsSignedIn
{
    protected function signIn(
        KernelBrowser $client,
        EntityManagerInterface $em,
        string $email = FixedRecordVoter::BYSTANDER_EMAIL,
    ): User {
        $user = $em->getRepository(User::class)->findOneBy(['email' => $email]);

        if (!$user instanceof User) {
            $user = new User()->setPassword('x')->setEmail($email)
                ->setFirstName('Vera')->setLastName('Viewer');
            $em->persist($user);
            $em->flush();
        }

        $client->loginUser($user);

        return $user;
    }

    /**
     * NOBODY IS SIGNED IN — for the tests whose whole subject is what an
     * anonymous request gets. Clearing the jar drops the session cookie the
     * sign-in above set, which is what a browser with no session is.
     */
    protected function signOut(KernelBrowser $client): void
    {
        $client->getCookieJar()->clear();
    }
}
