import { Controller } from '@hotwired/stimulus';

/*
 * DEPRECATED SINCE 0.7, AND EMPTY — REMOVED IN 0.8.
 *
 * The patrols month was this module's own grid once, and stepping it was a
 * fetch: ‹ › asked patrol_calendar for a bare month fragment and this swapped
 * it into the card, keeping the card's caption in step. The month is the
 * atlas's `atlas_calendar()` now — one component, drawn the same way on every
 * calendar in the product — and its stepper draws real links, so stepping a
 * month is a navigation like every other and there is nothing left to fetch.
 *
 * THE FILE STAYS FOR ONE RELEASE ANYWAY, and that is the rule rather than a
 * courtesy. `assets/controllers.json` is the INSTALLATION's file: Flex seeded
 * it from this package and the installation keeps it under its own version
 * control, so an entry naming this controller survives whatever this package
 * does next. StimulusBundle resolves each entry against the package directory
 * when it builds the controllers map, and a name with no file behind it throws
 * `Controller "calendar" does not exist in the "@uhifadhi/patrol-module"
 * package.` — at render, on every page, which is a 500 an installation gets
 * for doing nothing but updating.
 *
 * So: emptied here, disabled by default in `assets/package.json` for fresh
 * installations, and named in the upgrade notes (`docs/development.md`) so an
 * installation can drop its own entry. The file goes in 0.8, by which time an
 * installation has had a release in which to do it.
 */
export default class extends Controller {
    connect() {
        // Nothing: the month is the atlas's, and its stepper is links.
    }
}
