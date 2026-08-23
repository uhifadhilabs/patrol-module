/* The widget library's editing behaviour: on/off, width and drag order.
 *
 * Progressive enhancement — the page renders the saved layout and every widget
 * without this file; only the editing needs it. Plain DOM, no framework: a
 * reusable bundle must not require the host to install a Stimulus controller
 * before someone can arrange their own dashboard.
 *
 * The DOM is the state. Every change re-reads the whole library and posts the
 * COMPLETE layout, so a dropped request can never leave a half-applied one.
 * Same-origin JSON, so no CSRF token: the endpoint accepts only a JSON body,
 * which an HTML form cross-site cannot produce. */
(function () {
    'use strict';

    var root = document.querySelector('[data-patrol-widgets]');
    if (!root) {
        return;
    }

    function cards() {
        return Array.prototype.slice.call(root.querySelectorAll('[data-patrol-widget]'));
    }

    /* The whole library as the save endpoint's payload. */
    function layout() {
        var order = [];
        var widgets = {};
        cards().forEach(function (card) {
            var id = card.getAttribute('data-patrol-widget');
            order.push(id);
            widgets[id] = {
                on: '1' === card.getAttribute('data-patrol-on'),
                cols: parseInt(card.getAttribute('data-patrol-cols'), 10)
            };
        });

        return {order: order, widgets: widgets};
    }

    function save() {
        return fetch(root.getAttribute('data-patrol-save-url'), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(layout())
        });
    }

    /* The card's chrome, re-stated from its own data attributes. */
    function paint(card) {
        var on = '1' === card.getAttribute('data-patrol-on');
        var cols = card.getAttribute('data-patrol-cols');

        var state = card.querySelector('[data-patrol-state]');
        if (state) {
            state.textContent = on ? 'on dashboard' : 'not shown';
            state.className = 'chip ' + (on ? 'ok' : 'idle');
        }

        var toggle = card.querySelector('[data-patrol-toggle]');
        if (toggle) {
            toggle.textContent = on ? 'Remove from dashboard' : 'Add to dashboard';
        }

        card.querySelectorAll('[data-patrol-span]').forEach(function (chip) {
            var chosen = chip.getAttribute('data-patrol-span') === cols;
            chip.setAttribute('data-patrol-chosen', chosen ? 'on' : 'off');
            chip.classList.toggle('on', chosen);
        });
    }

    cards().forEach(function (card) {
        card.setAttribute('draggable', 'true');

        var toggle = card.querySelector('[data-patrol-toggle]');
        if (toggle) {
            toggle.addEventListener('click', function () {
                card.setAttribute('data-patrol-on', '1' === card.getAttribute('data-patrol-on') ? '0' : '1');
                paint(card);
                save();
            });
        }

        card.querySelectorAll('[data-patrol-span]').forEach(function (chip) {
            chip.addEventListener('click', function () {
                card.setAttribute('data-patrol-cols', chip.getAttribute('data-patrol-span'));
                paint(card);
                save();
            });
        });
    });

    /* Drag to place. The grip is the handle the design draws, but the whole card
     * is the drag source — a 600px-tall widget you may only grab by a 22px grip
     * is not a handle, it is a target.
     *
     * The card in flight STAYS where it was, dimmed under a dotted outline, and a
     * slot tracks the cursor between the cards. Moving the card itself instead
     * would make the page jump under the pointer and leave nothing on screen
     * saying "this is the one you are moving"; the slot says where it lands. */
    var dragging = null;
    var slot = null;

    function dropSlot() {
        if (!slot) {
            slot = document.createElement('div');
            slot.className = 'patrol-dropslot';
            // Decoration: it names no widget and must not be read out.
            slot.setAttribute('aria-hidden', 'true');
        }

        return slot;
    }

    function clearTargets() {
        root.querySelectorAll('.patrol-droptarget').forEach(function (card) {
            card.classList.remove('patrol-droptarget');
        });
    }

    /* Land the card where the slot stands, then save the whole layout.
     * Idempotent: drop and dragend both call it, and a drag cancelled with Esc
     * fires only the second. */
    function landDrag() {
        if (!dragging) {
            return;
        }
        if (slot && slot.parentNode) {
            root.insertBefore(dragging, slot);
            slot.parentNode.removeChild(slot);
        }
        dragging.classList.remove('patrol-dragging');
        clearTargets();
        dragging = null;
        save();
    }

    root.addEventListener('dragstart', function (event) {
        var card = event.target.closest ? event.target.closest('[data-patrol-widget]') : null;
        if (!card) {
            return;
        }
        dragging = card;
        card.classList.add('patrol-dragging');
        // The slot opens where the card already is, so a drag that goes nowhere
        // puts it back exactly where it was.
        root.insertBefore(dropSlot(), card.nextSibling);
        if (event.dataTransfer) {
            event.dataTransfer.effectAllowed = 'move';
            // Firefox starts no drag at all without some payload.
            event.dataTransfer.setData('text/plain', card.getAttribute('data-patrol-widget'));
        }
    });

    root.addEventListener('dragover', function (event) {
        if (!dragging) {
            return;
        }
        event.preventDefault();
        if (event.dataTransfer) {
            event.dataTransfer.dropEffect = 'move';
        }

        var over = event.target.closest ? event.target.closest('[data-patrol-widget]') : null;
        if (!over || over === dragging) {
            return;
        }

        // The card under the cursor lights up, and the slot opens above or below
        // it — whichever half the pointer is in — so the landing place is stated
        // twice: which neighbour, and which side of it.
        clearTargets();
        over.classList.add('patrol-droptarget');
        var box = over.getBoundingClientRect();
        var after = event.clientY > box.top + box.height / 2;
        root.insertBefore(dropSlot(), after ? over.nextSibling : over);
    });

    root.addEventListener('drop', function (event) {
        if (!dragging) {
            return;
        }
        event.preventDefault();
        landDrag();
    });

    root.addEventListener('dragend', function () {
        landDrag();
    });

    var reset = document.querySelector('[data-patrol-reset]');
    if (reset) {
        reset.addEventListener('click', function () {
            fetch(root.getAttribute('data-patrol-reset-url'), {
                method: 'POST',
                credentials: 'same-origin'
            }).then(function () {
                // The defaults are the server's to state, so re-read the page
                // rather than reconstructing them here.
                window.location.reload();
            });
        });
    }
})();
