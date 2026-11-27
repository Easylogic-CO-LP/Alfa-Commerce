/**
 * Alfa Commerce - the discount form's Reach card, counted on demand.
 *
 * The card renders with what was last counted, or with one button. A click POSTs to the rule's own action (data-url,
 * the form token in the body). The answer is either done, with the counts' markup rendered on the server, put in the
 * card's body, or working: the rule's job counts in the background, the card says how far it is and asks again after
 * the answer's retry_after seconds (never sooner than a second), until done. The button takes no second click while an
 * answer is awaited; a failure leaves one sentence in the body. The script fills the card's own elements and builds no
 * markup.
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        const card = document.querySelector('[data-discount-reach]');

        if (!card) {
            return;
        }

        const body = card.querySelector('[data-reach-body]');
        const working = card.querySelector('[data-reach-working]');
        const button = card.querySelector('[data-reach-ask]');

        if (!body || !working || !button) {
            return;
        }

        const label = button.textContent;
        let started = 0;

        function fail(text) {
            body.textContent = text || card.getAttribute('data-failed') || '';
            working.hidden = true;
            button.disabled = false;
            button.textContent = label;
        }

        function ask() {
            started = Date.now();
            const form = new FormData();
            form.append('id', card.getAttribute('data-id'));
            form.append(card.getAttribute('data-token'), '1');

            fetch(card.getAttribute('data-url'), { method: 'POST', body: form, credentials: 'same-origin', headers: { Accept: 'application/json' } })
                .then(function (response) { return response.json(); })
                .then(function (reply) {
                    if (!reply || !reply.success || !reply.data) {
                        fail(reply && reply.message);
                        return;
                    }

                    if (reply.data.state === 'working') {
                        working.textContent = (card.getAttribute('data-working') || '').replace('%d', String(Number(reply.data.percent) || 0));
                        working.hidden = false;
                        const delay = Math.max(1000 * (Number(reply.data.retry_after) || 0), 1000 - (Date.now() - started));
                        window.setTimeout(ask, Math.max(0, delay));
                        return;
                    }

                    if (typeof reply.data.html !== 'string') {
                        fail('');
                        return;
                    }

                    body.innerHTML = reply.data.html;
                    working.hidden = true;
                    button.disabled = false;
                    button.textContent = label;
                })
                .catch(function () { fail(''); });
        }

        button.addEventListener('click', function () {
            button.disabled = true;
            button.textContent = card.getAttribute('data-counting') || '';
            ask();
        });
    });
})();
