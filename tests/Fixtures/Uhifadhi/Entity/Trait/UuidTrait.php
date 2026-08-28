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

namespace Uhifadhi\Entity\Trait;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Gives an entity a public, time-ordered UUIDv7 used for external addressing (URLs,
 * APIs) so the sequential integer primary key is never exposed. Generated on first
 * persist; the entity needs #[ORM\HasLifecycleCallbacks].
 */
trait UuidTrait
{
    #[ORM\Column(type: 'uuid', unique: true)]
    private ?Uuid $uuid = null;

    public function getUuid(): ?Uuid
    {
        return $this->uuid;
    }

    public function setUuid(Uuid $uuid): static
    {
        $this->uuid = $uuid;

        return $this;
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
}
