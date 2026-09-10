import { Controller } from '@hotwired/stimulus';

/*
 * THE FILTER BAR'S CHROME, and nothing else.
 *
 * The filter itself is a QUERY: every chip and option in the bar is a real link
 * driving ?type / ?station / ?zone / ?month, read once by the controller into a
 * PatrolFilter, so the map, the log and the charts are three readings of one
 * answer. Nothing in this file narrows anything.
 *
 * What is left is the manners a dropdown needs and a link cannot express — the
 * same manners the incidents bar has: a panel opens on its trigger, only one is
 * open at a time, and an outside click or Escape closes it.
 */
export default class extends Controller {
    connect() {
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
}
