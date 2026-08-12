// Simulateur de démonstration de la landing : l'artisan choisit l'état actuel du
// logement et la classe visée sur l'échelle DPE, ajuste la surface, et la valeur
// créée / le reste à charge / le nombre de ventes comparables se recalculent en
// direct. Chiffres illustratifs (démo) — pas les coefficients statistiques réels.
(function () {
  const sim = document.getElementById('simulateur');
  if (!sim) return;

  const ORDER = ['A', 'B', 'C', 'D', 'E', 'F', 'G'];
  const COEF = { A: 68298, B: 46275, C: 25032, D: 0, E: -27182, F: -45474, G: -85656 };
  const nf = new Intl.NumberFormat('fr-FR');
  const gEuro = (n, sign) => {
    const v = Math.round(n / 10) * 10;
    const s = nf.format(Math.abs(v)) + ' €';
    return (sign && v > 0 ? '+ ' : v < 0 ? '− ' : '') + s;
  };

  const vals = Object.values(COEF);
  const MAX = Math.max.apply(null, vals);
  const MIN = Math.min.apply(null, vals);

  const state = { cur: 'E', tgt: 'C', s: 94, mode: 'cur' };

  const q = (r) => sim.querySelector('[data-role="' + r + '"]');
  const els = {
    scale: q('scale'), bar: q('bar'),
    rungs: Array.prototype.slice.call(sim.querySelectorAll('.rung')),
    modeCur: q('mode-cur'), modeTgt: q('mode-tgt'),
    surface: q('surface'), surfaceVal: q('surface-val'),
    valeur: q('valeur'), frange: q('frange'), cout: q('cout'),
    aides: q('aides'), reste: q('reste'), nventes: q('nventes'),
    pair: q('pair'), resume: q('resume'),
  };

  function geom() {
    return window.matchMedia('(max-width: 760px)').matches
      ? { H: 356, RH: 44, even: true, pad: 22 }
      : { H: 268, RH: 30, even: false, pad: 15 };
  }
  function rungY(c, g) {
    return g.even
      ? ORDER.indexOf(c) * (g.RH + 8) + g.RH / 2
      : g.pad + (MAX - COEF[c]) / (MAX - MIN) * (g.H - g.pad * 2);
  }

  function calc() {
    const iC = ORDER.indexOf(state.cur), iT = ORDER.indexOf(state.tgt);
    const gap = iC - iT, scale = state.s / 94;
    const valeur = (COEF[state.tgt] - COEF[state.cur]) * scale;
    const coutN = state.s * (150 + 120 * Math.max(1, gap));
    const aidesN = coutN * 0.55;
    return {
      valeurTxt: nf.format(Math.round(valeur / 10) * 10),
      basse: gEuro(valeur * 0.783), haute: gEuro(valeur * 1.215),
      cout: gEuro(coutN), aides: '− ' + gEuro(aidesN), reste: gEuro(coutN - aidesN),
      pair: state.cur + ' → ' + state.tgt,
      n: Math.max(96, 476 - 42 * (gap - 2) - Math.round((state.s - 94) * 0.6)),
    };
  }

  function render() {
    const g = geom(), c = calc();
    const iC = ORDER.indexOf(state.cur), iT = ORDER.indexOf(state.tgt);

    els.scale.style.height = g.H + 'px';
    els.bar.style.top = rungY(state.tgt, g) + 'px';
    els.bar.style.height = (rungY(state.cur, g) - rungY(state.tgt, g)) + 'px';

    els.rungs.forEach((btn) => {
      const cc = btn.dataset.c, i = ORDER.indexOf(cc);
      const isCur = cc === state.cur, isTgt = cc === state.tgt;
      const strong = isCur || isTgt;
      btn.style.top = (rungY(cc, g) - g.RH / 2) + 'px';
      const badge = btn.querySelector('.badge');
      badge.style.opacity = (i <= iC && i >= iT) ? '1' : '0.4';
      badge.style.outline = strong ? '2px solid #111820' : '2px solid transparent';
      const lab = btn.querySelector('.lab');
      lab.style.color = strong ? '#111820' : '#4A5560';
      lab.style.fontWeight = strong ? '600' : '400';
      btn.querySelector('.tag').textContent = isCur ? 'état actuel' : (isTgt ? 'classe visée' : '');
      btn.setAttribute('aria-pressed', String(state.mode === 'cur' ? isCur : isTgt));
      btn.disabled = state.mode === 'tgt' && i >= iC;
      btn.setAttribute('aria-label', state.mode === 'cur'
        ? 'Classe ' + cc + ' comme état actuel du logement'
        : 'Classe ' + cc + ' comme classe visée après travaux' + (i >= iC ? ' — indisponible, la classe visée doit être meilleure que l’état actuel' : ''));
    });

    els.modeCur.setAttribute('aria-pressed', String(state.mode === 'cur'));
    els.modeTgt.setAttribute('aria-pressed', String(state.mode === 'tgt'));
    els.surfaceVal.textContent = state.s + ' m²';
    els.valeur.textContent = c.valeurTxt;
    els.frange.textContent = c.basse + ' – ' + c.haute;
    els.cout.textContent = c.cout;
    els.aides.textContent = c.aides;
    els.reste.textContent = c.reste;
    els.nventes.textContent = c.n;
    els.pair.textContent = c.pair;
    els.resume.textContent = 'Passage de la classe ' + state.cur + ' à la classe ' + state.tgt
      + ' pour une maison de ' + state.s + ' m² : valeur créée estimée ' + c.valeurTxt
      + ' euros, reste à charge ' + c.reste + ', sur ' + c.n + ' ventes comparables.';
  }

  els.rungs.forEach((btn) => btn.addEventListener('click', () => {
    const i = ORDER.indexOf(btn.dataset.c);
    const iC = ORDER.indexOf(state.cur), iT = ORDER.indexOf(state.tgt);
    if (state.mode === 'cur') {
      state.cur = btn.dataset.c;
      state.tgt = i <= iT ? ORDER[Math.max(0, i - 2)] : state.tgt;
      state.mode = 'tgt';
    } else if (i < iC) {
      state.tgt = btn.dataset.c;
    } else {
      return;
    }
    render();
  }));
  els.modeCur.addEventListener('click', () => { state.mode = 'cur'; render(); });
  els.modeTgt.addEventListener('click', () => { state.mode = 'tgt'; render(); });
  els.surface.addEventListener('input', (e) => { state.s = +e.target.value; render(); });
  window.addEventListener('resize', render);

  render();
})();
