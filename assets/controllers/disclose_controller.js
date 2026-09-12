/*
 * This file is part of the UhifadhiLabs Patrol Module.
 *
 * (c) Ezekiel Mjema <https://github.com/eemjema>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

import { Controller } from '@hotwired/stimulus';

/*
 * ONE ENTRY, OPENED AND SHUT.
 *
 * A configure section draws a list whose rows each hide a small form — the
 * tunables under a patrol type — and the design closes them with one class on the
 * row's container (`shut`) and a chevron that turns with it. The stylesheet does
 * all of that; the only thing missing is the toggle.
 *
 * WHY NOT `<details>`, WHICH WOULD NEED NO SCRIPT. Every other disclosure on this
 * module's configure page IS one, and this one cannot be: what opens it sits in a
 * row that also carries a rename field, a retire button and two pickers, and
 * inside a `<summary>` each of those would toggle the disclosure instead of doing
 * its own job. Summary's content model is phrasing content, so the row cannot be
 * split around it either.
 *
 * IT TOGGLES A CLASS AND NOTHING ELSE — no state, no fetch, no markup — because
 * the sheet already knows what an open entry and a shut one look like.
 *
 * @see https://stimulus.hotwired.dev/reference/actions
 */
export default class extends Controller {
    /** The class the stylesheet hides an entry's form with. */
    static classes = ['shut'];

    toggle() {
        this.element.classList.toggle(this.hasShutClass ? this.shutClass : 'shut');
    }
}
