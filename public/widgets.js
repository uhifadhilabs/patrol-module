/* The widget library's editing behaviour: on/off, width and drag order.
 *
 * Progressive enhancement — the page renders the saved layout and every widget
 * without this file; only the editing needs it. Plain DOM, no framework: a
 * reusable bundle must not require the host to install a Stimulus controller
 * before someone can arrange their own dashboard.
 *
 * The DOM is the state. Every change re-reads the whole library and posts the
 * COMPLETE layout, so a dropped request can never leave a half-applied one.
 *
 * Both writes carry the CSRF token the page rendered, in the header the
 * controller reads (PatrolWidgetsController::CSRF_HEADER). The three names below
 * — the root attribute, the token attribute and the header — are asserted
 * against the template and the controller by
 * tests/Functional/WidgetLibraryAssetsTest, because a mismatch here fails ONLY
 * in a browser: every server-side test builds its own header and would still
 * pass. That is exactly how a silent 403 shipped once already. */
(function () {
    'use strict';

    var ROOT_SELECTOR = '[data-patrol-widgets]';
    var CSRF_ATTRIBUTE = 'data-patrol-csrf-token';
    var CSRF_HEADER = 'X-CSRF-Token';

    var root = document.querySelector(ROOT_SELECTOR);
    if (!root) {
        return;
    }

    /* The token lives on the same element as the URLs — read it from there, not
     * from a lookup that could drift to another element. */
    function csrfToken() {
        return root.getAttribute(CSRF_ATTRIBUTE) || '';
    }

    function postHeaders(extra) {
        var headers = extra || {};
        headers[CSRF_HEADER] = csrfToken();

        return headers;
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

    /* The last layout the SERVER accepted. Every optimistic change is measured
     * against this, and a refused save rolls the page back to it — a chip that
     * stays lit after a 403 is a lie about what the dashboard will show. */
    var confirmed = null;

    var notice = root.querySelector('[data-patrol-notice]');

    function showNotice(message) {
        if (!notice) {
            return;
        }
        notice.textContent = message;
        notice.removeAttribute('hidden');
    }

    function hideNotice() {
        if (notice) {
            notice.setAttribute('hidden', 'hidden');
        }
    }

    function cardById(id) {
        return cards().filter(function (card) {
            return card.getAttribute('data-patrol-widget') === id;
        })[0] || null;
    }

    /* Put the page back to a known layout: order, on/off and width. */
    function applyLayout(state) {
        state.order.forEach(function (id) {
            var card = cardById(id);
            if (!card) {
                return;
            }
            var widget = state.widgets[id];
            card.setAttribute('data-patrol-on', widget.on ? '1' : '0');
            card.setAttribute('data-patrol-cols', String(widget.cols));
            // Re-appending in order is the reorder: the state lists every card.
            root.appendChild(card);
            paint(card);
        });
    }

    function refuse(message) {
        if (confirmed) {
            applyLayout(confirmed);
        }
        showNotice(message);
    }

    function save() {
        hideNotice();
        var attempted = layout();

        return fetch(root.getAttribute('data-patrol-save-url'), {
            method: 'POST',
            credentials: 'same-origin',
            headers: postHeaders({'Content-Type': 'application/json'}),
            body: JSON.stringify(attempted)
        }).then(function (response) {
            if (!response.ok) {
                refuse(403 === response.status
                    ? 'Your session has expired, so that change was not saved. Reload the page and try again.'
                    : 'That change could not be saved (error ' + response.status + '). Your dashboard is unchanged.');

                return;
            }
            confirmed = attempted;
        }).catch(function () {
            refuse('That change could not be saved — you appear to be offline. Your dashboard is unchanged.');
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
            var label = on ? 'Remove from dashboard' : 'Add to dashboard';
            var labelEl = toggle.querySelector('[data-patrol-toggle-label]');
            if (labelEl) {
                labelEl.textContent = label;
            }
            // The title carries the label at the narrow spans that hide it, and
            // the icon swap is patrol.css reading data-patrol-on.
            toggle.setAttribute('title', label);
        }

        card.querySelectorAll('[data-patrol-span]').forEach(function (chip) {
            var chosen = chip.getAttribute('data-patrol-span') === cols;
            chip.setAttribute('data-patrol-chosen', chosen ? 'on' : 'off');
            chip.classList.toggle('on', chosen);
        });
    }

    cards().forEach(function (card) {
        /* Handle-gated: the card is draggable ONLY while the grip is held. A card
         * that is permanently draggable makes its own text unselectable and its
         * chips awkward to click, so the attribute is armed on the grip's
         * mousedown and disarmed again when the drag (or the click) ends. Once a
         * drag HAS started the whole card is the drag surface, as before. */
        var grip = card.querySelector('[data-patrol-grip]');
        if (grip) {
            grip.addEventListener('mousedown', function () {
                card.setAttribute('draggable', 'true');
            });
        }

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
    var SLIDE_MS = 160;

    var dragging = null;
    var slot = null;
    // Where the card stood before the drag, so a cancelled drag can put it back.
    var origin = null;
    var dropped = false;
    // Held while neighbours are sliding, so a storm of dragover events cannot
    // mutate the DOM out from under an animation that is mid-flight.
    var animating = false;
    var animationTimer = null;

    var reducedMotion = window.matchMedia
        ? window.matchMedia('(prefers-reduced-motion: reduce)')
        : null;

    function prefersReducedMotion() {
        return !!reducedMotion && reducedMotion.matches;
    }

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

    /* FLIP: measure every card First, run the mutation, measure Last, Invert the
     * difference as a transform, then Play it out. Without this the cards jump
     * whenever the slot moves and there is no reading of which way they went.
     *
     * The dragged card is excluded — it is the ghost, held still on purpose.
     * Under prefers-reduced-motion the mutation is applied instantly and the drop
     * slot stays the only affordance, which is the point of that setting. */
    function flip(mutate) {
        if (prefersReducedMotion()) {
            mutate();

            return;
        }

        var moved = cards().filter(function (card) {
            return card !== dragging;
        });
        var before = moved.map(function (card) {
            return card.getBoundingClientRect();
        });

        mutate();

        var deltas = moved.map(function (card, index) {
            var now = card.getBoundingClientRect();

            return {x: before[index].left - now.left, y: before[index].top - now.top};
        });

        var slid = false;
        moved.forEach(function (card, index) {
            var delta = deltas[index];
            if (!delta.x && !delta.y) {
                return;
            }
            slid = true;
            card.classList.remove('patrol-sliding');
            card.style.transform = 'translate(' + delta.x + 'px, ' + delta.y + 'px)';
        });
        if (!slid) {
            return;
        }

        // Force the inverted position to be painted before the transition is
        // armed, or the browser collapses both steps into no movement at all.
        void root.offsetWidth;

        animating = true;
        window.requestAnimationFrame(function () {
            moved.forEach(function (card) {
                card.classList.add('patrol-sliding');
                card.style.transform = '';
            });

            window.clearTimeout(animationTimer);
            animationTimer = window.setTimeout(function () {
                moved.forEach(function (card) {
                    card.classList.remove('patrol-sliding');
                });
                animating = false;
            }, SLIDE_MS + 40);
        });
    }

    /* Put the card back where the drag found it — the slot never became a drop. */
    function revertDrag() {
        flip(function () {
            if (slot && slot.parentNode) {
                slot.parentNode.removeChild(slot);
            }
            if (origin) {
                origin.parent.insertBefore(dragging, origin.next);
            }
        });
    }

    /* Land the card where the slot stands. */
    function placeDrag() {
        flip(function () {
            if (slot && slot.parentNode) {
                root.insertBefore(dragging, slot);
                slot.parentNode.removeChild(slot);
            }
        });
    }

    /* One exit for every drag, dropped or cancelled. A cancelled drag (Esc, or a
     * release outside the library) fires dragend WITHOUT drop: it restores the
     * pre-drag order and posts nothing — the layout on the server never changed,
     * so writing it back would be a lie. */
    function endDrag() {
        if (!dragging) {
            return;
        }
        if (dropped) {
            placeDrag();
        } else {
            revertDrag();
        }

        dragging.classList.remove('patrol-dragging');
        dragging.setAttribute('draggable', 'false');
        clearTargets();
        dragging = null;
        origin = null;

        if (dropped) {
            dropped = false;
            save();
        }
    }

    root.addEventListener('dragstart', function (event) {
        var card = event.target.closest ? event.target.closest('[data-patrol-widget]') : null;
        if (!card) {
            return;
        }
        dragging = card;
        dropped = false;
        // Recorded BEFORE the slot is inserted, so it is the card's own place.
        origin = {parent: card.parentNode, next: card.nextSibling};
        card.classList.add('patrol-dragging');
        // The slot shows the footprint this widget will take, not a generic bar.
        dropSlot().setAttribute('data-patrol-cols', card.getAttribute('data-patrol-cols'));
        // It opens where the card already is, so a drag that goes nowhere puts it
        // back exactly where it was.
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
        // Always accept the drop, even mid-slide — refusing here would cancel the
        // drag outright.
        event.preventDefault();
        if (event.dataTransfer) {
            event.dataTransfer.dropEffect = 'move';
        }
        if (animating) {
            return;
        }

        var over = event.target.closest ? event.target.closest('[data-patrol-widget]') : null;
        if (!over || over === dragging) {
            return;
        }

        // The card under the cursor lights up, and the slot opens before or after
        // it, so the landing place is stated twice: which neighbour, and which
        // side of it.
        //
        // Which axis decides "after" depends on how the card sits. Cards share a
        // row once they are narrower than the grid, and there the meaningful
        // question is left-or-right of its middle; a full-width card is only ever
        // above or below. Semantics stay insert-before/insert-after either way —
        // this is an insertion model, never a swap, so a drop can only ever move
        // one card and never displace another to somewhere it was not dropped.
        clearTargets();
        over.classList.add('patrol-droptarget');
        var box = over.getBoundingClientRect();
        var sharesRow = box.width < root.clientWidth * 0.9;
        var after = sharesRow
            ? event.clientX > box.left + box.width / 2
            : event.clientY > box.top + box.height / 2;
        var reference = after ? over.nextSibling : over;
        if (reference === slot) {
            return;
        }

        flip(function () {
            root.insertBefore(dropSlot(), reference);
        });
    });

    root.addEventListener('drop', function (event) {
        if (!dragging) {
            return;
        }
        event.preventDefault();
        dropped = true;
        endDrag();
    });

    root.addEventListener('dragend', function () {
        endDrag();
    });

    /* A grip pressed but never dragged still armed the attribute; disarm it so
     * the card does not stay draggable for the rest of the session. No mouseup
     * fires during a real drag, so this never races dragend. */
    document.addEventListener('mouseup', function () {
        if (dragging) {
            return;
        }
        cards().forEach(function (card) {
            card.setAttribute('draggable', 'false');
        });
    });

    /* Reset throws the whole layout away, so it asks first — through the HOST's
     * reusable confirm-modal controller (assets/controllers/confirm_modal_
     * controller.js), never the browser's own dialog. The button carries its data
     * attributes and dispatches `confirm-modal:confirmed` when the person agrees;
     * where the host controller is absent the click still resets, because a
     * bundle may not require a host controller to be installed. */
    var reset = document.querySelector('[data-patrol-reset]');
    if (reset) {
        reset.addEventListener('confirm-modal:confirmed', function () {
            hideNotice();
            fetch(root.getAttribute('data-patrol-reset-url'), {
                method: 'POST',
                credentials: 'same-origin',
                headers: postHeaders({})
            }).then(function (response) {
                if (!response.ok) {
                    showNotice(403 === response.status
                        ? 'Your session has expired, so nothing was reset. Reload the page and try again.'
                        : 'The layout could not be reset (error ' + response.status + ').');

                    return;
                }
                // The defaults are the server's to state, so re-read the page
                // rather than reconstructing them here.
                window.location.reload();
            }).catch(function () {
                showNotice('The layout could not be reset — you appear to be offline.');
            });
        });

        // No host controller on the page: fall back to asking nothing rather
        // than to a button that does nothing.
        if (!reset.hasAttribute('data-controller')) {
            reset.addEventListener('click', function () {
                reset.dispatchEvent(new CustomEvent('confirm-modal:confirmed'));
            });
        }
    }

    // Whatever the page rendered IS the server's state, and the baseline every
    // refused save rolls back to.
    confirmed = layout();
})();
