/*
 * Life at MAM — the Power Play countdown, recounted on the visitor's own clock
 * (handoff §6: "days to the next first Wednesday, computed on the visitor's clock").
 * The server already printed the same count for Lagos, so with no script the panel
 * still says something true.
 */
(function () {
  'use strict';
  var days = document.querySelector('[data-pp="days"]');
  if (!days) return;
  var now = new Date(), today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
  var firstWed = function (y, m) { var d = new Date(y, m, 1); d.setDate(1 + ((3 - d.getDay() + 7) % 7)); return d; };
  var nx = firstWed(today.getFullYear(), today.getMonth());
  if (nx < today) nx = firstWed(today.getFullYear(), today.getMonth() + 1);
  var n = Math.round((nx - today) / 864e5);
  days.textContent = n === 0 ? 'Today' : String(n);
  document.querySelector('[data-pp="unit"]').textContent = n === 0 ? 'Join us this evening' : (n === 1 ? 'day to go' : 'days to go');
  document.querySelector('[data-pp="date"]').textContent = nx.toLocaleDateString('en-GB', { weekday: 'long', day: 'numeric', month: 'long' });
})();
