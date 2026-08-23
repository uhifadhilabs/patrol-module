import { Controller } from '@hotwired/stimulus';

/*
 * The filter chip row — "one filter drives map AND log" (the design's own
 * caption on PL·05).
 *
 * The chips, the coverage maps and the row lists are separate widgets a person
 * can re-order or switch off in the widget library, so they never reach into
 * one another's DOM: a chip publishes a document event and whoever is on the
 * page answers it.
 *
 *   patrol:filter {type, station}  — 'all' or one patrol type key / station name
 *
 * Every chip row on the page listens too, so the row above the map and the row
 * above the log always show the same choice.
 *
 * Counts stay server-rendered: filtering is a way of LOOKING at the month, not
 * a different month.
 * TODO(patrol_period): the "this month ▾" chip still only labels the period —
 * changing it means fetching a different month, not hiding rows.
 */
export default class extends Controller {
    static targets = ['chip', 'station', 'stationLabel', 'stationMenu'];

    connect() {
        this.state = { type: 'all', station: 'all' };
        this.onFilter = (event) => this.mark(event.detail ?? {});
        document.addEventListener('patrol:filter', this.onFilter);
    }

    disconnect() {
        document.removeEventListener('patrol:filter', this.onFilter);
    }

    choose(event) {
        this.publish({ type: event.currentTarget.dataset.patrolType ?? 'all' });
    }

    chooseStation(event) {
        this.publish({ station: event.currentTarget.dataset.patrolStation ?? 'all' });
        if (this.hasStationMenuTarget) {
            this.stationMenuTarget.open = false;
        }
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
        // The station chip names the chosen station, the way a filter chip should.
        if (this.hasStationLabelTarget) {
            this.stationLabelTarget.textContent = this.state.station === 'all' ? 'station ▾' : `${this.state.station} ▾`;
        }
        if (this.hasStationMenuTarget) {
            this.stationMenuTarget.classList.toggle('patrol-menu-chosen', this.state.station !== 'all');
        }
    }
}
