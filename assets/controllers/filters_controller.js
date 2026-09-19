import { Controller } from '@hotwired/stimulus';

/*
 * DEPRECATED SINCE 0.6, AND EMPTY — REMOVED IN 0.7.
 *
 * The filter bar's dropdowns were this module's own chrome once: a panel shown
 * by an `.open` class this controller toggled, with an outside click and
 * Escape closing it. They are the shell's grouped dropdown now — a `<details>`
 * whose `<summary>` is the chip — so the browser does all of that and there is
 * nothing left for a script to do.
 *
 * THE FILE STAYS FOR ONE RELEASE ANYWAY, and that is the rule rather than a
 * courtesy. `assets/controllers.json` is the INSTALLATION's file: Flex seeded
 * it from this package and the installation keeps it under its own version
 * control, so an entry naming this controller survives whatever this package
 * does next. StimulusBundle resolves each entry against the package directory
 * when it builds the controllers map, and a name with no file behind it throws
 * `Controller "filters" does not exist in the "@uhifadhi/patrol-module"
 * package.` — at render, on every page, which is a 500 an installation gets
 * for doing nothing but updating.
 *
 * So: emptied here, disabled by default in `assets/package.json` for fresh
 * installations, and named in the upgrade notes (`docs/development.md`) so an
 * installation can drop its own entry. The file goes in 0.7, by which time an
 * installation has had a release in which to do it.
 */
export default class extends Controller {
    connect() {
        // Nothing: the dropdowns are the shell's <details> and need no script.
    }
}
