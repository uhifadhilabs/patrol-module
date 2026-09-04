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

namespace Uhifadhi\Patrol\Tests\Fixtures\Account;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\UserInterface as SecurityUserInterface;
use Symfony\Component\Uid\Uuid;
use Uhifadhi\Entity\Position;
use Uhifadhi\ModuleContracts\Entity\UserInterface;

/**
 * THE TEST INSTALLATION'S OWN ACCOUNT CLASS — the thing an installation resolves
 * `Uhifadhi\ModuleContracts\Entity\UserInterface` to, played here by a class the
 * test suite owns.
 *
 * IT IS NOT A STUB OF SOMEBODY ELSE'S CLASS, and that is the change. This file
 * replaces a dev-only copy of a host class the module used to type-hint by name;
 * the module now names the CONTRACT, so what stands behind it is nobody's
 * business but the installation's — which is exactly the freedom the contract
 * exists to give. A real installation gets this class from whichever module
 * provides its team; this suite writes its own, so the tests prove the
 * resolution rather than a shared implementation.
 *
 * IT IMPLEMENTS TWO INTERFACES WITH THE SAME SHORT NAME, deliberately. The
 * contract answers "who is this record about" and Symfony's answers "who is
 * signed in"; a firewall needs the second, so the class an installation resolves
 * the first to is normally also the second. One of them is imported aliased,
 * as the contract's own documentation says to.
 *
 * IT STILL CARRIES A POSITION, which the contract does not describe. The org
 * chart — position, and the department a position is filed under — has no
 * published contract yet, so the module reaches it through the mapping rather
 * than through a type (see PatrolRepository::coverageFractionForDepartment())
 * and this class provides one for those queries to walk.
 */
#[ORM\Entity]
#[ORM\Table(name: '`user`')] // quoted — "user" is a reserved word in PostgreSQL
#[ORM\HasLifecycleCallbacks]
class User implements SecurityUserInterface, UserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null; // @phpstan-ignore property.unusedType (assigned by Doctrine via reflection)

    /** The public address: what crosses a module boundary, never the integer key. */
    #[ORM\Column(type: 'uuid', unique: true)]
    private ?Uuid $uuid = null;

    #[ORM\Column(length: 180, unique: true)]
    private ?string $email = null;

    #[ORM\Column(length: 100)]
    private ?string $firstName = null;

    #[ORM\Column(length: 100)]
    private ?string $lastName = null;

    /**
     * The service number the field app signs in with and names team members
     * by — the sync endpoints resolve a patrol's `team` through it.
     */
    #[ORM\Column(length: 32, unique: true, nullable: true)]
    private ?string $rangerCode = null;

    #[ORM\ManyToOne(targetEntity: Position::class)]
    private ?Position $position = null;

    /** @var list<string> */
    #[ORM\Column]
    private array $roles = [];

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUuidString(): ?string
    {
        return $this->uuid?->toRfc4122();
    }

    #[ORM\PrePersist]
    public function generateUuid(): void
    {
        $this->uuid ??= Uuid::v7();
    }

    public function getPosition(): ?Position
    {
        return $this->position;
    }

    public function setPosition(?Position $position): static
    {
        $this->position = $position;

        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = strtolower($email);

        return $this;
    }

    public function getFirstName(): ?string
    {
        return $this->firstName;
    }

    public function setFirstName(string $firstName): static
    {
        $this->firstName = $firstName;

        return $this;
    }

    public function getLastName(): ?string
    {
        return $this->lastName;
    }

    public function setLastName(string $lastName): static
    {
        $this->lastName = $lastName;

        return $this;
    }

    public function getRangerCode(): ?string
    {
        return $this->rangerCode;
    }

    public function setRangerCode(?string $rangerCode): static
    {
        $rangerCode = null === $rangerCode ? null : strtolower(trim($rangerCode));
        $this->rangerCode = '' === $rangerCode ? null : $rangerCode;

        return $this;
    }

    public function getFullName(): string
    {
        return trim(($this->firstName ?? '').' '.($this->lastName ?? ''));
    }

    public function getUserIdentifier(): string
    {
        $email = (string) $this->email;

        return '' !== $email
            ? $email
            : throw new \LogicException('User has no email identifier.');
    }

    /** @return list<string> */
    public function getRoles(): array
    {
        return array_values(array_unique([...$this->roles, 'ROLE_USER']));
    }

    /** @param list<string> $roles */
    public function setRoles(array $roles): static
    {
        $this->roles = $roles;

        return $this;
    }

    public function eraseCredentials(): void
    {
    }
}
