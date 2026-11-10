/**
 * Alfa Commerce - Tools > Tasks, the progress page of one job (&job=N).
 *
 * While the page is open and the job waits, each call asks the server for a 2-second tick on that job
 * (task=tasks.process) and shows how far it is. The answer paces the page: the next call follows after its
 * retry_after seconds (0 while the job advances, the endpoint's gap while it cannot be worked, with a line saying
 * why), and never sooner than a second after the last one began. Closing the page loses nothing: the job waits for
 * the next run. The script only fills the page's own elements (the bar's width, its value and the texts); it builds
 * no markup.
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        const card = document.querySelector('[data-alfa-job-progress]');

        if (!card) {
            return;
        }

        const bar = card.querySelector('[data-alfa-job-bar]');
        const state = card.querySelector('[data-alfa-job-state]');
        const units = card.querySelector('[data-alfa-job-units]');
        const error = card.querySelector('[data-alfa-job-error]');
        const finished = card.querySelector('[data-alfa-job-finished]');
        const wait = card.querySelector('[data-alfa-job-wait]');
        let started = 0;

        function show(data) {
            bar.style.width = data.percent + '%';
            bar.setAttribute('aria-valuenow', String(data.percent));
            state.textContent = data.stateText;
            units.textContent = data.text;
            error.textContent = data.error || '';
            error.hidden = !data.error;
            wait.textContent = data.waitText || '';
            wait.hidden = !data.waitText;
        }

        // The next call after the answer's retry_after seconds, and never sooner than a second after this one began.
        function later(seconds) {
            const delay = Math.max(1000 * (Number(seconds) || 0), 1000 - (Date.now() - started));
            window.setTimeout(step, Math.max(0, delay));
        }

        function step() {
            started = Date.now();
            const body = new FormData();
            body.append('id', card.getAttribute('data-id'));
            body.append(card.getAttribute('data-token'), '1');

            fetch(card.getAttribute('data-url'), { method: 'POST', body: body, credentials: 'same-origin', headers: { Accept: 'application/json' } })
                .then(function (response) { return response.json(); })
                .then(function (reply) {
                    if (!reply || !reply.success) {
                        error.textContent = (reply && reply.message) || '';
                        error.hidden = false;
                        bar.classList.remove('progress-bar-animated');
                        return;
                    }

                    show(reply.data);

                    if (reply.data.finished) {
                        bar.classList.remove('progress-bar-animated');
                        finished.hidden = false;
                        return;
                    }

                    later(reply.data.retry_after);
                })
                // A call that did not answer (the network, a restart): try again a little later.
                .catch(function () { window.setTimeout(step, 5000); });
        }

        step();
    });
})();
