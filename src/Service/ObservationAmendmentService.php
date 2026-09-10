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

namespace Uhifadhi\Patrol\Service;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;
use Uhifadhi\Contracts\Entity\UserInterface;
use Uhifadhi\Patrol\Entity\Observation;
use Uhifadhi\Patrol\Entity\ObservationAmendment;
use Uhifadhi\Patrol\Entity\ObservationPhoto;
use Uhifadhi\Patrol\Enum\ObservationAmendmentKindEnum;
use Uhifadhi\Storage\Service\EvidenceStorage;

/**
 * APPENDING ONE CORRECTION to an observation — the write behind PL·06–PL·09.
 *
 * ONE VERB, AND IT IS AN INSERT. There is deliberately no edit and no delete
 * here for the same reason there is no route for either: the original is never
 * changed, an amendment is never removed, and a wrong amendment is corrected by
 * another amendment. A method that does not exist cannot be called by a bug.
 *
 * THE AUTHOR'S NAME IS COPIED, NOT REFERENCED. The relation is kept as well, but
 * the name is written down at the moment of the correction so the trail can
 * still be read after the person has left the service.
 *
 * A PHOTOGRAPH GOES THROUGH THE SAME EVIDENCE PATH THE FIELD UPLOADS USE — one
 * way in for every photograph this module holds, so the private storage, the
 * detected type and the preview are identical whether a handset or a browser
 * sent it — and is MARKED as an amendment attachment, which is what keeps it out
 * of the observation's own evidence strip and out of its completeness count.
 *
 * IT DECIDES NOTHING ABOUT WHO IS ASKING. Whether the caller may amend, and
 * whether the token on the form is valid, are questions about the request; the
 * screen settles them and hands this an author.
 */
final readonly class ObservationAmendmentService
{
    /**
     * What one correction may say, in characters — long enough for a paragraph.
     * A fact about the record rather than about the form, so it is stated here
     * and the screen that refuses a longer one reads it from here.
     */
    public const int BODY_MAX = 4000;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private EvidenceStorage $evidence,
    ) {
    }

    /**
     * @param ?string       $supersededValue what this correction replaces, quoted on the page
     *                                       under "as it was recorded"; absent is ordinary — an
     *                                       amendment that ADDS supersedes nothing
     * @param ?\SplFileInfo $photo           the optional photograph; the bytes go to the
     *                                       platform's evidence storage
     *
     * @throws \Uhifadhi\Storage\Exception\EvidenceRejectedException      when the file is not storable evidence
     * @throws \Uhifadhi\Storage\Exception\EvidenceStorageFailedException when storing the bytes failed
     */
    public function append(
        Observation $observation,
        ObservationAmendmentKindEnum $kind,
        string $body,
        UserInterface $author,
        ?string $supersededValue = null,
        ?\SplFileInfo $photo = null,
    ): ObservationAmendment {
        $amendment = new ObservationAmendment($observation, $kind, $body)
            ->withAuthor($author, $author->getFullName())
            ->withSupersededValue($supersededValue);

        if (null !== $photo) {
            $stored = $this->storePhotograph($observation, $photo);
            $amendment->withPhoto($stored);
            $this->entityManager->persist($stored);
        }

        $this->entityManager->persist($amendment);
        $this->entityManager->flush();

        return $amendment;
    }

    private function storePhotograph(Observation $observation, \SplFileInfo $file): ObservationPhoto
    {
        $clientUuid = Uuid::v7();
        $stored = $this->evidence->store(
            $file,
            PhotoEvidenceKey::prefixFor($observation),
            $clientUuid->toRfc4122(),
        );

        return new ObservationPhoto($observation, $clientUuid, $stored->key)
            ->setMimeType($stored->mimeType)
            ->setByteSize($stored->byteSize)
            ->setThumbKey($stored->thumbKey)
            ->markAsAmendmentAttachment();
    }
}
