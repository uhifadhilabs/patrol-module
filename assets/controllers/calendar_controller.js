import { Controller } from '@hotwired/stimulus';

/*
 * The patrol calendar's month navigation (PL·11 ‹ ›).
 *
 * A month is a different QUERY, not a different view of data the browser holds:
 * the ‹ › buttons fetch the month fragment from patrol_calendar and swap it in,
 * so every day cell keeps real patrols with real refs, colours and links. The
 * swapped-in fragment carries the same buttons, so the walk is unbounded in both
 * directions with no state kept here beyond "a request is in flight".
 *
 * The hover popovers are pure CSS on server-rendered markup — this controller
 * knows nothing about them, and they work in the fragment the moment it lands.
 */
export default class extends Controller {
    static targets = ['body', 'label'];
    static values = { url: String };

    initialize() {
        this.pending = null;
    }

    disconnect() {
        // A month that lands after the widget is gone must not be applied.
        this.pending?.abort();
        this.pending = null;
    }

    async go(event) {
        event.preventDefault();

        const month = event.currentTarget.dataset.patrolCalGoto;
        if (!month) {
            return;
        }

        // Rapid clicks: the newest month wins, and the one in flight is dropped
        // rather than allowed to overwrite it when it arrives late.
        this.pending?.abort();
        const controller = new AbortController();
        this.pending = controller;
        this.element.classList.add('patrol-cal-busy');

        try {
            const url = new URL(this.urlValue, window.location.href);
            url.searchParams.set('month', month);

            const response = await fetch(url, {
                headers: { Accept: 'text/html' },
                credentials: 'same-origin',
                signal: controller.signal,
            });
            if (!response.ok) {
                return;
            }

            this.bodyTarget.innerHTML = await response.text();
            this.relabel();
        } catch (error) {
            // An aborted fetch is the expected outcome of a newer click, not a
            // failure; anything else leaves the month on screen untouched.
            if (error.name !== 'AbortError') {
                console.error('patrol calendar: could not load', month, error);
            }
        } finally {
            if (this.pending === controller) {
                this.pending = null;
                this.element.classList.remove('patrol-cal-busy');
            }
        }
    }

    /* The card's caption follows the grid. The label is read off the fragment —
       the server already formatted it, so this never formats a date itself. */
    relabel() {
        const month = this.bodyTarget.querySelector('[data-patrol-cal-label]');
        if (month && this.hasLabelTarget) {
            this.labelTarget.textContent = month.dataset.patrolCalLabel;
        }
    }
}
