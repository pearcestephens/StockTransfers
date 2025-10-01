/**
 * File: modules/platform/pulselock/assets/js/pulselock.js
 * Purpose: Client-side refresh logic for PulseLock dashboard page and badge widgets.
 * Author: GitHub Copilot
 * Last Modified: 2025-09-25
 */

(function () {
    'use strict';

    var apiUrl = 'https://staff.vapeshed.co.nz/modules/platform/pulselock/api/status.json.php';
    var state = window.PulseLockBootstrap || null;
    var refreshInterval = 30000;

    function init() {
        if (state) {
            applyState(state);
        }
        scheduleRefresh();
        document.addEventListener('pulselock:refresh', triggerRefresh);
    }

    function scheduleRefresh() {
        window.setTimeout(triggerRefresh, refreshInterval);
    }

    function triggerRefresh() {
        fetch(apiUrl, {cache: 'no-store'}).then(function (res) {
            if (!res.ok) {
                throw new Error('HTTP ' + res.status);
            }
            return res.json();
        }).then(function (payload) {
            if (!payload || payload.success === false) {
                throw new Error('Invalid payload');
            }
            state = payload;
            applyState(payload);
            scheduleRefresh();
        }).catch(function (err) {
            console.warn('[PulseLock] refresh failed', err);
            scheduleRefresh();
        });
    }

    function applyState(payload) {
        updateBadge(payload);
        updatePipelines(payload);
        updateChecks(payload);
        document.dispatchEvent(new CustomEvent('pulselock:updated', {detail: payload}));
    }

    function updateBadge(payload) {
        var badge = document.querySelector('.pulselock__badge');
        var score = document.querySelector('.pulselock__score strong');
        var status = payload.status || 'unknown';
        if (!badge) {
            return;
        }
        badge.classList.remove('pulselock__badge--green', 'pulselock__badge--amber', 'pulselock__badge--red');
        badge.classList.add('pulselock__badge--' + status);
        badge.textContent = String(status).toUpperCase();
        if (score) {
            score.textContent = Number(payload.score || 0).toFixed(2);
        }
        var stamp = document.querySelector('.pulselock__timestamp');
        if (stamp && payload.executed_at) {
            stamp.textContent = 'Updated ' + payload.executed_at + ' (' + (payload.took_ms || 0) + ' ms)';
        }
    }

    function updatePipelines(payload) {
        var pipelines = payload.pipelines || {};
        var elements = document.querySelectorAll('.pulselock__pipeline');
        if (!elements.length) {
            return;
        }
        elements.forEach(function (el) {
            var label = el.querySelector('.pulselock__pipeline-label');
            if (!label) {
                return;
            }
            var key = label.textContent.trim();
            var status = pipelines[key];
            el.classList.remove('pulselock__pipeline--green', 'pulselock__pipeline--amber', 'pulselock__pipeline--red');
            if (status) {
                el.classList.add('pulselock__pipeline--' + status);
                var statusEl = el.querySelector('.pulselock__pipeline-status');
                if (statusEl) {
                    statusEl.textContent = String(status).toUpperCase();
                }
            }
        });
    }

    function updateChecks(payload) {
        var table = document.querySelector('.table tbody');
        if (!table) {
            return;
        }
        var rows = table.querySelectorAll('tr');
        rows.forEach(function (row) {
            var labelCell = row.querySelector('th');
            if (!labelCell) {
                return;
            }
            var label = labelCell.textContent.trim();
            var check = (payload.checks || []).find(function (item) {
                return item.label === label;
            });
            if (!check) {
                return;
            }
            var badge = row.querySelector('.badge-status');
            if (badge) {
                badge.classList.remove('badge-status--green', 'badge-status--amber', 'badge-status--red');
                badge.classList.add('badge-status--' + check.status);
                badge.textContent = String(check.status).toUpperCase();
            }
            var scoreCell = row.children[2];
            if (scoreCell) {
                scoreCell.textContent = Number(check.score || 0).toFixed(1);
            }
            var tookCell = row.children[3];
            if (tookCell) {
                tookCell.textContent = (check.took_ms || 0) + ' ms';
            }
        });
    }

    document.addEventListener('DOMContentLoaded', init);
})();
