/**
 * Alfa Commerce - the discount form's Reach card, counted on demand.
 *
 * The card renders with one button. A click POSTs to the rule's own action (data-url, the form token in the body) and
 * puts the answer's markup, rendered on the server, in the card's body. While the answer is awaited the button says so
 * and takes no second click; a failure leaves one sentence in the body. The script fills the card's own elements and
 * builds no markup.
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        const card = document.querySelector('[data-discount-reach]');

        if (!card) {
            return;
        }

        const body = card.querySelector('[data-reach-body]');
        const button = card.querySelector('[data-reach-ask]');

        if (!body || !button) {
            return;
        }

        button.addEventListener('click', function () {
            button.disabled = true;
            button.textContent = card.getAttribute('data-counting') || '';
            const form = new FormData();
            form.append('id', card.getAttribute('data-id'));
            form.append(card.getAttribute('data-token'), '1');

            fetch(card.getAttribute('data-url'), { method: 'POST', body: form, credentials: 'same-origin', headers: { Accept: 'application/json' } })
                .then(function (response) { return response.json(); })
                .then(function (reply) {
                    if (!reply || !reply.success || !reply.data || typeof reply.data.html !== 'string') {
                        body.textContent = (reply && reply.message) || card.getAttribute('data-failed') || '';
                        return;
                    }

                    body.innerHTML = reply.data.html;
                })
                .catch(function () {
                    body.textContent = card.getAttribute('data-failed') || '';
                });
        });
    });
})();
