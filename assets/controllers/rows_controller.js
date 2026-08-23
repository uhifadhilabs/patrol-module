import { Controller } from '@hotwired/stimulus';

/*
 * A list of patrols that answers to the filter chips and talks to the maps —
 * the patrol log (PL·06) and the patrol feed (PL·07), which is why the design
 * captions the map beside the feed "hover a row to highlight".
 *
 * Listens for   patrol:filter    {type, station}  — hides the rows that do not match
 * Publishes     patrol:highlight {uuid}  — the row under the cursor, null on leave
 *
 * Rows carry their own identity (data-patrol / data-patrol-type), so this
 * controller works on a table and on a stack of feed rows without knowing which
 * it is on.
 */
export default class extends Controller {
    static targets = ['row', 'empty'];

    connect() {
        this.onFilter = (event) => this.filter(event.detail ?? {});
        document.addEventListener('patrol:filter', this.onFilter);
    }

    disconnect() {
        document.removeEventListener('patrol:filter', this.onFilter);
        // Never leave a map spotlighting a row that no longer exists.
        document.dispatchEvent(new CustomEvent('patrol:highlight', { detail: { uuid: null } }));
    }

    filter({ type = 'all', station = 'all' }) {
        let shown = 0;
        this.rowTargets.forEach((row) => {
            const match = (type === 'all' || row.dataset.patrolType === type)
                && (station === 'all' || row.dataset.patrolStation === station);
            row.classList.toggle('patrol-hidden', !match);
            shown += match ? 1 : 0;
        });
        // The "nothing matches this filter" line, where the list ships one.
        if (this.hasEmptyTarget) {
            this.emptyTarget.classList.toggle('patrol-hidden', shown > 0);
        }
    }

    hover(event) {
        const uuid = event.currentTarget.dataset.patrol ?? null;
        document.dispatchEvent(new CustomEvent('patrol:highlight', { detail: { uuid } }));
    }

    unhover() {
        document.dispatchEvent(new CustomEvent('patrol:highlight', { detail: { uuid: null } }));
    }
}