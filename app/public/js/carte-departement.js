/* Choroplèthe communale du hub départemental (#392). Les données sont injectées
   par le serveur dans un bloc JSON — aucun appel réseau, aucune dépendance.
   Carte illustrative : couleur = gain vert E→C, infobulle au survol, sans lien. */
(function () {
  const bloc = document.getElementById("carte-departement-donnees");
  const svg = document.getElementById("carte-dept");
  const tip = document.getElementById("carte-dept-tip");
  if (!bloc || !svg) return;

  const D = JSON.parse(bloc.textContent);
  const PALE = [232, 241, 240], TEAL = [15, 110, 110], GRIS = "#cfd8da";
  const lo = D.dom[0], etendue = (D.dom[1] - D.dom[0]) || 1;
  const mix = (t) => {
    t = Math.max(0, Math.min(1, t));
    return "rgb(" + PALE.map((c, i) => Math.round(c + (TEAL[i] - c) * t)).join(",") + ")";
  };
  const couleur = (g) => (g == null ? GRIS : mix((g - lo) / etendue));
  const fmt = (g) => (g > 0 ? "+" : "") + g + " %";

  D.feats.forEach((f) => {
    const p = document.createElementNS("http://www.w3.org/2000/svg", "path");
    p.setAttribute("d", f.path);
    p.setAttribute("fill", couleur(f.gain));
    p.addEventListener("mousemove", (e) => montrer(e, f));
    p.addEventListener("mouseenter", () => p.classList.add("hl"));
    p.addEventListener("mouseleave", () => { p.classList.remove("hl"); if (tip) tip.hidden = true; });
    svg.appendChild(p);
  });

  function montrer(e, f) {
    if (!tip) return;
    tip.hidden = false;
    const detail = f.gain == null ? "Pas assez de ventes proches" : "de E à C : " + fmt(f.gain);
    tip.innerHTML = "<b></b><span></span>";
    tip.querySelector("b").textContent = f.nom;
    tip.querySelector("span").textContent = detail;
    const r = svg.getBoundingClientRect();
    let x = e.clientX - r.left + 14, y = e.clientY - r.top + 14;
    if (x > r.width - 160) x = e.clientX - r.left - tip.offsetWidth - 14;
    tip.style.left = x + "px";
    tip.style.top = y + "px";
  }

  const legende = document.getElementById("carte-dept-legend");
  if (legende) {
    legende.innerHTML =
      '<span>' + fmt(D.dom[0]) + '</span>' +
      '<i style="background:linear-gradient(90deg,rgb(' + PALE.join(",") + '),rgb(' + TEAL.join(",") + '))"></i>' +
      '<span>' + fmt(D.dom[1]) + '</span>';
  }
})();
