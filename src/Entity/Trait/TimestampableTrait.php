<?php

declare(strict_types=1);

namespace UhifadhiLabs\Patrol\Entity\Trait;

use Doctrine\ORM\Mapping as ORM;

/**
 * Adds created/updated timestamps, stamped on persist and update via lifecycle
 * callbacks. The entity needs #[ORM\HasLifecycleCallbacks].
 *
 * The bundle's OWN copy of the host's Foundation trait — same semantics,
 * module-owned so bundle entities depend on no host class (and the standalone
 * test kernel needs no stub). No collision: traits are resolved by their full
 * namespace, and the shared column names live on different tables. AuditLog
 * deliberately does not use it — an immutable row has no honest updatedAt.
 */
trait TimestampableTrait
{
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(?\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }

    #[ORM\PrePersist]
    public function initTimestamps(): void
    {
        $now = new \DateTimeImmutable();
        $this->createdAt ??= $now;
        $this->updatedAt ??= $now;
    }

    #[ORM\PreUpdate]
    public function refreshUpdatedAt(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
