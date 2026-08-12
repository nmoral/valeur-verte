// Échelle DPE de la carte « preuve » : positionne les étiquettes A→G et l'écart
// mesuré (E → C) proportionnellement aux coefficients. Purement décoratif.
const COEF = { A: 68298, B: 46275, C: 25032, D: 0, E: -27182, F: -45474, G: -85656 };
const COLOR = { A: '--dpe-a', B: '--dpe-b', C: '--dpe-c', D: '--dpe-d', E: '--dpe-e', F: '--dpe-f', G: '--dpe-g' };
const FROM = 'E', TO = 'C';
const euro = (n) => (n > 0 ? '+' : '') + n.toLocaleString('fr-FR') + ' €';

function drawRuler() {
  const box = document.getElementById('ruler');
  if (!box) return;
  const H = box.clientHeight;
  const vals = Object.values(COEF), max = Math.max(...vals), min = Math.min(...vals), pad = 13;
  const y = (v) => pad + (max - v) / (max - min) * (H - pad * 2);
  const yt = y(COEF[TO]), yb = y(COEF[FROM]), yc = (yt + yb) / 2;
  let h = '<div class="ruler-axis"></div>';
  h += `<div class="span-bar" style="top:${yt}px;height:${yb - yt}px"></div>`;
  for (const [k, v] of Object.entries(COEF)) {
    const on = (k === FROM || k === TO);
    const hide = Math.abs(y(v) - yc) < 13;
    h += `<div class="rung${on ? ' on' : ''}" style="top:${y(v)}px">
      <span class="mark" style="background:var(${COLOR[k]});${'CD'.includes(k) ? 'color:#2A2A18' : ''}">${k}</span>
      ${hide ? '' : `<span class="val">${euro(v)}</span>`}</div>`;
  }
  h += `<div class="span-cap" style="top:${yc}px">${euro(COEF[TO] - COEF[FROM])}</div>`;
  box.innerHTML = h;
}
window.addEventListener('resize', drawRuler);
drawRuler();
