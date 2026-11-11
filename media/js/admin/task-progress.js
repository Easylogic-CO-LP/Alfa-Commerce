/**
 * Alfa Commerce - Tools > Tasks, the progress page of one job (&job=N).
 *
 * While the page is open and the job waits, each call asks the server for a 2-second tick on that job
 * (task=tasks.process) and shows how far it is. The answer paces the page: the next call follows after its
 * retry_after seconds (0 while the job advances, the endpoint's gap while it cannot be worked, with a line saying
 * why), and never sooner than a second after the last one began. Closing the page loses nothing: the job waits for
 * the next run. When the job ends, the sentence of its end shows, coloured by it, the bar turns solid in the same colour,
 * a thumbnails job's report is printed, and the jobs card below is asked for again: the server draws it with its own
 * layout (task=tasks.jobsCard) and the script swaps it in (UX-84, UX-88). The script fills the page's own elements and
 * swaps in the card the server drew; it builds no markup.
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
        const unitsLine = card.querySelector('[data-alfa-job-units-line]');
        const error = card.querySelector('[data-alfa-job-error]');
        const wait = card.querySelector('[data-alfa-job-wait]');
        const report = card.querySelector('[data-alfa-job-report]');
        const solid = { done: 'bg-success', failed: 'bg-danger', resolved: 'bg-secondary' };
        let started = 0;

        function show(data) {
            bar.style.width = data.percent + '%';
            bar.setAttribute('aria-valuenow', String(data.percent));
            state.textContent = data.stateText;
            units.textContent = data.text;
            unitsLine.hidden = !data.text;
            error.textContent = data.error || '';
            error.hidden = !data.error;
            wait.textContent = data.waitText || '';
            wait.hidden = !data.waitText;
        }

        // The job ended: its sentence, its colour, its report, and the jobs card drawn again by the server.
        function end(data) {
            bar.classList.remove('progress-bar-striped', 'progress-bar-animated');

            if (solid[data.state]) {
                bar.classList.add(solid[data.state]);
            }

            card.querySelectorAll('[data-alfa-job-end]').forEach(function (line) {
                line.hidden = line.getAttribute('data-alfa-job-end') !== data.state;
            });
            report.textContent = data.report || '';
            report.hidden = !data.report;
            refreshJobs();
        }

        function refreshJobs() {
            const jobs = document.querySelector('[data-alfa-jobs-card]');

            if (!jobs) {
                return;
            }

            const body = new FormData();
            body.append('id', card.getAttribute('data-id'));
            body.append(card.getAttribute('data-token'), '1');

            fetch(card.getAttribute('data-jobs-url'), { method: 'POST', body: body, credentials: 'same-origin', headers: { Accept: 'application/json' } })
                .then(function (response) { return response.json(); })
                .then(function (reply) {
                    if (!reply || !reply.success || !reply.data || !reply.data.html) {
                        return;
                    }

                    const drawn = document.createElement('template');
                    drawn.innerHTML = reply.data.html.trim();
                    const fresh = drawn.content.querySelector('[data-alfa-jobs-card]');

                    if (fresh) {
                        jobs.replaceWith(fresh);
                    }
                })
                // The card stays as it was; the next page load draws it.
                .catch(function () {});
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
                        end(reply.data);
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
