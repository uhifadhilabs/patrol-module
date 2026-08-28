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

namespace Uhifadhi\Model;

/**
 * A headed section of the widget library: a handful of widgets that answer the
 * same kind of question, under a heading and ONE line saying what the section is
 * for. The description is deliberately a single line — a library whose headings
 * need a paragraph is a library whose grouping is wrong.
 *
 * Groups are a library-side reading only. They do not scope a layout: the
 * dashboard grid is one ordered list, and dragging moves a widget across the
 * whole surface, not within its group.
 */
final readonly class WidgetGroup
{
    public function __construct(
        public string $id,
        public string $label,
        public string $description,
    ) {
        if ('' === $id || '' === $label) {
            throw new \InvalidArgumentException('A widget group needs an id and a label.');
        }
    }
}
