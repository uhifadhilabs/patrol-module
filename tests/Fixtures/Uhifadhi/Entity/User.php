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

namespace Uhifadhi\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * DEV/TEST-ONLY stub of uhifadhi's Uhifadhi\Entity\User — carried in the bundle's
 * autoload-dev so the Patrol→User mapping resolves when the bundle is tested and
 * phpstan'd in isolation. NOT shipped (autoload-dev is dropped on install), so
 * inside uhifadhi the REAL User is loaded instead. It mirrors the real class for
 * the fields the module uses (id + email + first/last name), verbatim; the real
 * User's uuid/password/roles/position are omitted because the module never reads
 * them.
 */
#[ORM\Entity]
#[ORM\Table(name: '`user`')] // quoted — "user" is a reserved word (matches uhifadhi)
class User implements UserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null; // @phpstan-ignore property.unusedType (assigned by Doctrine via reflection)

    #[ORM\Column(length: 180, unique: true)]
    private ?string $email = null;

    #[ORM\Column(length: 100)]
    private ?string $firstName = null;

    #[ORM\Column(length: 100)]
    private ?string $lastName = null;

    /**
     * The service number the field app signs in with and names team members
     * by — mirrored from the real User because the sync endpoints resolve a
     * patrol's `team` through it.
     */
    #[ORM\Column(length: 32, unique: true, nullable: true)]
    private ?string $rangerCode = null;

    #[ORM\ManyToOne(targetEntity: Position::class)]
    private ?Position $position = null;

    public function getId(): ?int
    {
        return $this->id;
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

    /** @var list<string> */
    #[ORM\Column]
    private array $roles = [];

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
