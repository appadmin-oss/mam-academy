/* MAM Academy fee model — single source of truth (mirrors production config.js / apply.js). All USD per term. */
const LEVELS = [
  { id: 'pre-certificate', name: 'Pre-Certificate', grades: '1–2', base: 120 },
  { id: 'certificate', name: 'Certificate', grades: '3–4', base: 130 },
  { id: 'pre-diploma', name: 'Pre-Diploma', grades: '5–6', base: 160 },
  { id: 'diploma', name: 'Diploma', grades: '7–8', base: 180 }
];
const FLAT = { crash: 70, meta: 60 };          // Crash Programme (6 weeks), The MetaMusic Order (12 weeks)
const REGISTRATION = 5;                          // one-off, the only payment at application
const ONLINE_SURCHARGE = 0.2;                    // Standard, Certified, Grand only
const GRAND_DISCOUNT = 0.13;                     // Grand = (3B + 0.5B·x) × 0.87
const RATES = { USD: [1, '$'], NGN: [1500, '₦'], GBP: [0.79, '£'] }; // indicative

function quote({ programme, level = 0, mode = 'onsite', extras = 0 }) {
  if (programme in FLAT) return { total: FLAT[programme], lines: [{ k: programme === 'crash' ? 'Crash Programme (flat)' : 'The MetaMusic Order (flat)', v: FLAT[programme] }], per: 'flat' };
  const B = LEVELS[level].base, x = (programme === 'standard' || programme === 'grand') ? Math.max(0, Math.min(4, extras)) : 0, half = 0.5 * B;
  let sub, lines;
  if (programme === 'standard') { sub = B + half * x; lines = [{ k: 'Level base fee', v: B }]; if (x) lines.push({ k: x + ' additional course(s)', v: half * x }); }
  else if (programme === 'certified') { sub = B + 120; lines = [{ k: 'Level base fee', v: B }, { k: 'Four compulsory courses', v: 120 }]; }
  else if (programme === 'grand') { const pre = 3 * B + half * x; sub = pre * (1 - GRAND_DISCOUNT); lines = [{ k: 'All three expressions (3 × base)', v: 3 * B }]; if (x) lines.push({ k: x + ' additional course(s)', v: half * x }); lines.push({ k: 'Grand saving (13%)', v: -(pre - sub) }); }
  else throw new Error('Unknown programme ' + programme);
  if (mode === 'online') { lines.push({ k: 'Online (+20%)', v: sub * ONLINE_SURCHARGE }); sub *= 1 + ONLINE_SURCHARGE; }
  return { total: sub, lines, per: 'term' };
}
function format(usd, cur = 'USD') { const [r, s] = RATES[cur]; const v = Math.round(Math.abs(usd) * r); return (usd < 0 ? '−' : '') + s + v.toLocaleString('en-US'); }
module.exports = { LEVELS, FLAT, REGISTRATION, ONLINE_SURCHARGE, GRAND_DISCOUNT, RATES, quote, format };
