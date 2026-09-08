import { Controller } from '@hotwired/stimulus';

/*
 * The filter bar — "one filter drives map AND log" (the design's own caption on
 * PL·05).
 *
 * TWO JOBS, deliberately kept in one controller because they act on the one bar:
 *
 *  1. THE FILTER ITSELF, client-side. The chips, the coverage maps and the row
 *     lists are separate widgets a person can re-order or switch off, so they
 *     never reach into one another's DOM: a chip publishes a document event and
 *     whoever is on the page answers it.
 *
 *         patrol:filter {type, station}  — 'all' or one patrol type key / station
 *
 *     Every chip row on the page listens too, so the row above the map and the
 *     row above the log always show the same choice. Counts stay server-rendered:
 *     filtering is a way of LOOKING at the month, not a different month.
 *
 *  2. THE DROPDOWN CHROME, mirroring the incidents filter bar: the station filter
 *     is an .i-dd panel opened by its .i-ddt trigger, one panel at a time, closed
 *     on an outside click or Escape. Type stays a row of quick TOGGLE chips.
 *
 * TODO(patrol_period): the month is a plain indicator, not a dropdown — patrol's
 * map and log are all-time by design, so there is no month query to drive them
 * the way incidents has one; a real period switch is a separate change.
 */
export default class extends Controller {
    static targets = ['chip', 'station', 'stationLabel', 'stationMenu'];

    connect() {
        this.state = { type: 'all', station: 'all' };
        this.onFilter = (event) => this.mark(event.detail ?? {});
        document.addEventListener('patrol:filter', this.onFilter);

        // Dropdown chrome, the same manners the incidents bar has: close an open
        // panel on an outside click or Escape.
        this.onDocumentClick = (event) => {
            if (!this.element.contains(event.target)) {
                this.closeAll();
            }
        };
        this.onKeydown = (event) => {
            if ('Escape' === event.key) {
                this.closeAll();
            }
        };
        document.addEventListener('click', this.onDocumentClick);
        document.addEventListener('keydown', this.onKeydown);
    }

    disconnect() {
        document.removeEventListener('patrol:filter', this.onFilter);
        document.removeEventListener('click', this.onDocumentClick);
        document.removeEventListener('keydown', this.onKeydown);
    }

    /** Open the clicked dropdown, closing any other — one panel at a time. */
    toggle(event) {
        event.preventDefault();
        const dropdown = event.currentTarget.closest('[data-patrol-dd]');
        if (!dropdown) {
            return;
        }
        const wasOpen = dropdown.classList.contains('open');
        this.closeAll();
        if (!wasOpen) {
            dropdown.classList.add('open');
            event.currentTarget.setAttribute('aria-expanded', 'true');
        }
    }

    closeAll() {
        this.element.querySelectorAll('[data-patrol-dd].open').forEach((dropdown) => {
            dropdown.classList.remove('open');
            const trigger = dropdown.querySelector('[data-patrol-dd-trigger]');
            if (trigger) {
                trigger.setAttribute('aria-expanded', 'false');
            }
        });
    }

    choose(event) {
        this.publish({ type: event.currentTarget.dataset.patrolType ?? 'all' });
    }

    chooseStation(event) {
        this.publish({ station: event.currentTarget.dataset.patrolStation ?? 'all' });
        // Choosing closes the panel, the way the incidents options do on navigation.
        this.closeAll();
    }

    publish(change) {
        const detail = { ...this.state, ...change };
        document.dispatchEvent(new CustomEvent('patrol:filter', { detail }));
    }

    mark(detail) {
        this.state = { type: detail.type ?? 'all', station: detail.station ?? 'all' };

        this.chipTargets.forEach((chip) => {
            const on = (chip.dataset.patrolType ?? 'all') === this.state.type;
            chip.classList.toggle('on', on);
            chip.setAttribute('aria-pressed', on ? 'true' : 'false');
        });
        this.stationTargets.forEach((item) => {
            item.classList.toggle('on', (item.dataset.patrolStation ?? 'all') === this.state.station);
        });
        // The trigger names the chosen station (its caret sits beside it in its own
        // element); the whole dropdown reads as active when one is chosen.
        if (this.hasStationLabelTarget) {
            this.stationLabelTarget.textContent = this.state.station === 'all' ? 'station' : this.state.station;
        }
        if (this.hasStationMenuTarget) {
            this.stationMenuTarget.classList.toggle('patrol-dd-chosen', this.state.station !== 'all');
        }
    }
}
