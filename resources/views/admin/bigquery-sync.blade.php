<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>BigQuery Content Sync</title>
    <style>
        :root {
            color-scheme: light;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: #f4f5f7;
            color: #1a1f29;
            padding: 32px 20px;
        }
        .wrap {
            max-width: 880px;
            margin: 0 auto;
        }
        h1 {
            font-size: 22px;
            margin: 0 0 4px;
        }
        .sub {
            color: #667085;
            font-size: 14px;
            margin: 0 0 16px;
        }
        .worker-note {
            font-size: 13px;
            background: #fffaeb;
            border: 1px solid #fedf89;
            color: #93370d;
            border-radius: 8px;
            padding: 10px 14px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
        }
        .worker-note.running {
            background: #ecfdf3;
            border-color: #a6f4c5;
            color: #065f46;
        }
        .worker-note code {
            background: #fff;
            padding: 1px 6px;
            border-radius: 4px;
            border: 1px solid #fedf89;
        }
        .worker-note .worker-status {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .worker-note .dot {
            width: 8px;
            height: 8px;
        }
        .worker-note button {
            font-size: 13px;
            padding: 6px 14px;
        }
        .worker-note button.stop {
            background: #b42318;
        }
        .card {
            background: #fff;
            border: 1px solid #e4e7ec;
            border-radius: 10px;
            padding: 18px 20px;
            margin-bottom: 14px;
        }
        .card.primary {
            border-color: #0e6bd8;
            background: #f2f8ff;
        }
        .row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            flex-wrap: wrap;
        }
        .info h2 {
            font-size: 15px;
            margin: 0 0 3px;
        }
        .info p {
            font-size: 13px;
            color: #667085;
            margin: 0;
        }
        button {
            border: none;
            border-radius: 8px;
            padding: 9px 18px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            background: #101828;
            color: #fff;
            white-space: nowrap;
        }
        button:disabled {
            opacity: 0.6;
            cursor: default;
        }
        .primary button {
            background: #0e6bd8;
        }
        .order-hint {
            font-size: 12px;
            color: #98a2b3;
        }

        .steps {
            margin-top: 14px;
            display: none;
        }
        .steps.show { display: block; }
        .step {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px 4px;
            font-size: 13px;
            border-top: 1px solid #eef1f4;
        }
        .step:first-child { border-top: none; }
        .dot {
            width: 9px;
            height: 9px;
            border-radius: 50%;
            flex-shrink: 0;
            background: #d0d5dd;
        }
        .dot.running {
            background: #f79009;
            animation: pulse 1s infinite ease-in-out;
        }
        .dot.success { background: #12b76a; }
        .dot.failed { background: #f04438; }
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.35; }
        }
        .step-label { flex: 1; }
        .step-meta {
            font-size: 12px;
            color: #667085;
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
        }
        .step-meta.err { color: #b42318; }
        .step-detail {
            margin: 4px 0 0 19px;
            font-size: 12px;
            color: #667085;
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
            white-space: pre-wrap;
        }
        .step-detail.err { color: #b42318; }
    </style>
</head>
<body>
    <div class="wrap">
        <h1>BigQuery Content Sync</h1>
        <p class="sub">Pulls the clear-cutoff-435016.content.* tables into this app's database. Safe to re-run — every sync upserts by slug and never deletes.</p>
        <div id="worker-note" class="worker-note">
            <div class="worker-status">
                <span id="worker-dot" class="dot"></span>
                <span id="worker-label">Checking queue worker…</span>
            </div>
            <button id="worker-toggle" type="button">…</button>
        </div>

        <div class="card primary">
            <div class="row">
                <div class="info">
                    <h2>Sync everything</h2>
                    <p>Runs all 5 syncs below in the correct dependency order.</p>
                </div>
                <button data-kind="all" data-target="steps-all">Sync All</button>
            </div>
            <div id="steps-all" class="steps"></div>
        </div>

        <div class="card">
            <div class="row">
                <div class="info">
                    <h2>Exams</h2>
                    <p>content.exams &rarr; exams / exam_translations. <span class="order-hint">No dependency.</span></p>
                </div>
                <button data-kind="exams" data-target="steps-exams">Sync</button>
            </div>
            <div id="steps-exams" class="steps"></div>
        </div>

        <div class="card">
            <div class="row">
                <div class="info">
                    <h2>Tools</h2>
                    <p>content.tools &rarr; tools / tool_translations. <span class="order-hint">No dependency.</span></p>
                </div>
                <button data-kind="tools" data-target="steps-tools">Sync</button>
            </div>
            <div id="steps-tools" class="steps"></div>
        </div>

        <div class="card">
            <div class="row">
                <div class="info">
                    <h2>Tool Categories</h2>
                    <p>content.tool_categories &rarr; tool_categories / translations. <span class="order-hint">Needs Tools synced first.</span></p>
                </div>
                <button data-kind="tool_categories" data-target="steps-categories">Sync</button>
            </div>
            <div id="steps-categories" class="steps"></div>
        </div>

        <div class="card">
            <div class="row">
                <div class="info">
                    <h2>Tool &harr; Exam Mapping</h2>
                    <p>content.tool_exam_mapping &rarr; tool_exams. <span class="order-hint">Needs Tools + Exams + Categories synced first.</span></p>
                </div>
                <button data-kind="tool_exam_mapping" data-target="steps-mapping">Sync</button>
            </div>
            <div id="steps-mapping" class="steps"></div>
        </div>

        <div class="card">
            <div class="row">
                <div class="info">
                    <h2>Tool Exam Content (SEO)</h2>
                    <p>content.tool_exam_content &rarr; tool_exam_data / SEO translations. <span class="order-hint">Needs Mapping synced first.</span></p>
                </div>
                <button data-kind="tool_exam_content" data-target="steps-content">Sync</button>
            </div>
            <div id="steps-content" class="steps"></div>
        </div>
    </div>

    <script>
        const csrfToken = document.querySelector('meta[name="csrf-token"]').content;
        const startUrl = @json(route('admin.bigquery-sync.runs.start'));
        const statusUrlBase = @json(route('admin.bigquery-sync.runs.show', ['syncRun' => '__ID__']));
        const workerStatusUrl = @json(route('admin.bigquery-sync.worker.status'));
        const workerStartUrl = @json(route('admin.bigquery-sync.worker.start'));
        const workerStopUrl = @json(route('admin.bigquery-sync.worker.stop'));

        function postJson(url) {
            return fetch(url, {
                method: 'POST',
                headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrfToken },
            }).then((r) => r.json());
        }

        function renderWorker(state) {
            const note = document.getElementById('worker-note');
            const dot = document.getElementById('worker-dot');
            const label = document.getElementById('worker-label');
            const toggle = document.getElementById('worker-toggle');

            note.className = 'worker-note' + (state.running ? ' running' : '');
            dot.className = 'dot ' + (state.running ? 'success' : 'failed');
            label.textContent = state.running
                ? `Queue worker running (pid ${state.pid})`
                : 'Queue worker not running — sync runs will stay "pending".';
            toggle.textContent = state.running ? 'Stop worker' : 'Start worker';
            toggle.className = state.running ? 'stop' : '';
        }

        async function refreshWorkerStatus() {
            const state = await fetch(workerStatusUrl, { headers: { Accept: 'application/json' } }).then((r) => r.json());
            renderWorker(state);
            return state;
        }

        document.getElementById('worker-toggle').addEventListener('click', async (e) => {
            const btn = e.currentTarget;
            btn.disabled = true;

            const isRunning = btn.classList.contains('stop');
            const state = await postJson(isRunning ? workerStopUrl : workerStartUrl);
            renderWorker(state);

            btn.disabled = false;
        });

        refreshWorkerStatus();
        setInterval(refreshWorkerStatus, 4000);

        function renderSteps(container, steps) {
            container.innerHTML = steps.map((step) => {
                const meta = step.status === 'success'
                    ? summarize(step.result)
                    : step.status === 'failed'
                        ? (step.error || 'failed')
                        : step.status === 'running'
                            ? 'running…'
                            : 'queued';

                const detail = step.status === 'failed'
                    ? `<div class="step-detail err">${escapeHtml(step.error || '')}</div>`
                    : '';

                return `
                    <div class="step">
                        <span class="dot ${step.status}"></span>
                        <span class="step-label">${escapeHtml(step.label)}</span>
                        <span class="step-meta ${step.status === 'failed' ? 'err' : ''}">${escapeHtml(meta)}</span>
                    </div>
                    ${detail}
                `;
            }).join('');
        }

        function summarize(result) {
            if (!result || !result.stats) return 'done';
            const { created, updated, skipped } = result.stats;
            const warnings = result.warnings?.length ? `, ${result.warnings.length} warning(s)` : '';
            return `+${created} / ~${updated} / -${skipped}${warnings}`;
        }

        function escapeHtml(str) {
            return String(str).replace(/[&<>"']/g, (c) => ({
                '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
            }[c]));
        }

        function poll(runId, container, btn, originalLabel) {
            const url = statusUrlBase.replace('__ID__', runId);

            const tick = async () => {
                const res = await fetch(url, { headers: { Accept: 'application/json' } });
                const run = await res.json();

                renderSteps(container, run.steps);

                if (run.status === 'completed' || run.status === 'failed') {
                    btn.disabled = false;
                    btn.textContent = originalLabel;
                    return;
                }

                setTimeout(tick, 1000);
            };

            tick();
        }

        document.querySelectorAll('button[data-kind]').forEach((btn) => {
            btn.addEventListener('click', async () => {
                const container = document.getElementById(btn.dataset.target);
                const originalLabel = btn.textContent;

                btn.disabled = true;
                btn.textContent = 'Starting…';
                container.className = 'steps show';
                renderSteps(container, [{ key: '_', label: 'Starting…', status: 'pending' }]);

                try {
                    const res = await fetch(startUrl, {
                        method: 'POST',
                        headers: {
                            Accept: 'application/json',
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': csrfToken,
                        },
                        body: JSON.stringify({ kind: btn.dataset.kind }),
                    });

                    if (!res.ok) {
                        throw new Error('Failed to start (' + res.status + ')');
                    }

                    const run = await res.json();
                    btn.textContent = 'Running…';
                    renderSteps(container, run.steps);
                    poll(run.id, container, btn, originalLabel);
                } catch (e) {
                    renderSteps(container, [{ key: '_', label: e.message, status: 'failed' }]);
                    btn.disabled = false;
                    btn.textContent = originalLabel;
                }
            });
        });
    </script>
</body>
</html>
