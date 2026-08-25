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

namespace UhifadhiLabs\Patrol\Entity;

use Doctrine\ORM\Mapping as ORM;
use UhifadhiLabs\Patrol\Repository\TrackBatchRepository;

/**
 * One accepted upload of fixes — API-CONTRACT.md §5.
 *
 * This row exists for exactly one reason: so a re-sent batch can be recognised
 * and ignored. The phone batches 500 fixes at a time and retries forever on a
 * dropped connection, so without a record of which batches already landed, one
 * bad signal would double a patrol's track — and a doubled track is a false
 * claim about where rangers went.
 *
 * The batch key is the phone's (`<patrol-uuid>:track:3`), not a UUID: it is
 * derived from the patrol and the batch index so a retry is byte-for-byte the
 * request it was the first time.
 */
#[ORM\Entity(repositoryClass: TrackBatchRepository::class)]
#[ORM\Table(name: 'patrol_track_batch')]
#[ORM\UniqueConstraint(name: 'uniq_patrol_track_batch_key', fields: ['batchKey'])]
#[ORM\HasLifecycleCallbacks]
class TrackBatch
{
    use Trait\TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null; // @phpstan-ignore property.unusedType (assigned by Doctrine via reflection)

    #[ORM\ManyToOne(targetEntity: Patrol::class, inversedBy: 'trackBatches')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Patrol $patrol;

    /**
     * The phone's idempotency key. A string, not a uuid column, because the
     * contract's key is a composite (`8f1f…:track:3`) and storing it verbatim
     * is the only way a retry matches exactly what was stored.
     */
    #[ORM\Column(length: 120)]
    private string $batchKey;

    /** How many fixes this batch actually contributed — for the complete check. */
    #[ORM\Column(options: ['default' => 0])]
    private int $pointCount = 0;

    public function __construct(Patrol $patrol, string $batchKey)
    {
        $this->patrol = $patrol;
        $this->batchKey = $batchKey;
        $patrol->addTrackBatch($this);
        $this->initTimestamps();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPatrol(): Patrol
    {
        return $this->patrol;
    }

    public function getBatchKey(): string
    {
        return $this->batchKey;
    }

    public function getPointCount(): int
    {
        return $this->pointCount;
    }

    public function setPointCount(int $pointCount): static
    {
        $this->pointCount = $pointCount;

        return $this;
    }
}
