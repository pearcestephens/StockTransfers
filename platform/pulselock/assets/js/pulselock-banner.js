(function () {
  'use strict';
  var banner;

  function update(payload) {
    if (!banner) return;
    var status = (payload && payload.status) || 'green';
    var msg = 'All systems operational.';
    if (status === 'amber') msg = 'Systems degraded. Some services may be slow.';
    if (status === 'red')   msg = 'Critical incident in progress. Automation is locked.';

    banner.classList.remove('pulselock-banner--green', 'pulselock-banner--amber', 'pulselock-banner--red');
    banner.classList.add('pulselock-banner--' + status, 'pulselock-banner--active');

    var m = banner.querySelector('.pulselock-banner__message');
    if (m) m.textContent = msg;
  }

  function init() {
    banner = document.querySelector('[data-pulselock-banner]');
    if (!banner) return;
    if (window.PulseLockBootstrap) update(window.PulseLockBootstrap);
    document.addEventListener('pulselock:updated', function (ev) {
      if (ev && ev.detail) update(ev.detail);
    });
  }

  document.addEventListener('DOMContentLoaded', init);
})();
