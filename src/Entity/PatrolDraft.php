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

namespace Uhifadhi\Patrol\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Contracts\Entity\UserInterface;
use Uhifadhi\Patrol\Entity\Trait\TimestampableTrait;
use Uhifadhi\Patrol\Repository\PatrolDraftRepository;

/**
 * A PATROL BEING WRITTEN — the row that exists only so a file can arrive before
 * the patrol does.
 *
 * The entry flow takes the track and the photographs on the way IN, through the
 * platform's upload component, and the component needs something to file them
 * against. A patrol is not that thing: nothing has been confirmed yet, and a
 * half-written patrol in the register would be counted, mapped and exported like
 * a real one. So the page opens a draft instead, and the two upload targets file
 * against it.
 *
 * WHY A ROW RATHER THAN A KEYED PREFIX WITH NOTHING BEHIND IT. A bare
 * "patrol-track:<some uuid>" would be cheaper and would answer neither of the
 * two questions the seam has to answer:
 *
 *   - WHO MAY. The permission is "patrols.record" ON AN AREA, and an id with no
 *     row behind it names no area — the target would have to trust the browser
 *     for the one fact the decision rests on, or fall back to a global check
 *     that lets a recorder in one area file evidence against another's.
 *   - WHAT IS ABANDONED. Most drafts are never saved: a page opened and closed
 *     is a normal event. Without a row there is nothing to date, so nothing can
 *     say which bytes are leftovers — and the storage publishes no listing to
 *     sweep instead. With a row, `patrol:purge-discarded` measures a draft's age
 *     the same way it measures a discarded patrol's and uses the same
 *     `discard_retention_days` window.
 *
 * THIN, AND SHORT-LIVED BY DESIGN. It holds who, where, when, and the files
 * received so far; it carries no patrol detail at all, because everything a
 * person types is in the form until they press save and is worth nothing before
 * they do. The moment the patrol is written the draft's files are re-homed under
 * the patrol's own prefix and the draft is deleted.
 */
#[ORM\Entity(repositoryClass: PatrolDraftRepository::class)]
#[ORM\Table(name: 'patrol_draft')]
// The retention sweep asks for drafts older than a cutoff and nothing else.
#[ORM\Index(name: 'idx_patrol_draft_created', columns: ['created_at'])]
#[ORM\HasLifecycleCallbacks]
class PatrolDraft
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null; // @phpstan-ignore property.unusedType (assigned by Doctrine via reflection)

    /**
     * Minted server-side when the page renders, and the half of every upload
     * target after the colon. A v7 so a sweep reads in the order drafts were
     * opened.
     */
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $uuid;

    #[ORM\ManyToOne(targetEntity: AreaOfInterest::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private AreaOfInterest $area;

    /**
     * The person filling the form in. Nullable because an account may be deleted
     * while a draft of theirs is still waiting to be swept — a draft nobody owns
     * is a draft nobody may add to, which is exactly right.
     */
    #[ORM\ManyToOne(targetEntity: UserInterface::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?UserInterface $owner = null;

    /** @var Collection<int, PatrolDraftFile> */
    #[ORM\OneToMany(targetEntity: PatrolDraftFile::class, mappedBy: 'draft', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $files;

    public function __construct(AreaOfInterest $area, ?UserInterface $owner = null)
    {
        $this->uuid = Uuid::v7();
        $this->area = $area;
        $this->owner = $owner;
        $this->files = new ArrayCollection();
        $this->initTimestamps();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUuid(): Uuid
    {
        return $this->uuid;
    }

    public function getArea(): AreaOfInterest
    {
        return $this->area;
    }

    public function getOwner(): ?UserInterface
    {
        return $this->owner;
    }

    /** @return Collection<int, PatrolDraftFile> */
    public function getFiles(): Collection
    {
        return $this->files;
    }

    public function addFile(PatrolDraftFile $file): static
    {
        if (!$this->files->contains($file)) {
            $this->files->add($file);
        }

        return $this;
    }

    public function removeFile(PatrolDraftFile $file): static
    {
        $this->files->removeElement($file);

        return $this;
    }
}
