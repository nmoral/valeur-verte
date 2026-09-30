// Autocomplétion d'adresse du simulateur public (#385). Interroge l'endpoint
// first-party `/simulateur/adresses` (qui relaie la BAN), remplit la liste de
// suggestions et pose le `code_insee` caché à partir de l'adresse choisie — sans
// lui, la commune n'est pas résolue et le serveur refuse l'estimation.
(function () {
  var form = document.querySelector('[data-simulateur="estimation"]');
  if (!form) return;

  var champ = form.querySelector('[data-simulateur="adresse"]');
  var liste = form.querySelector('[data-simulateur="suggestions"]');
  var codeInsee = form.querySelector('[data-simulateur="code-insee"]');
  var url = form.getAttribute('data-adresse-url');
  if (!champ || !liste || !codeInsee || !url) return;

  var minimum = 3;
  var timer = null;
  var dernier = '';

  function vider() {
    liste.innerHTML = '';
    liste.hidden = true;
    champ.setAttribute('aria-expanded', 'false');
  }

  function choisir(libelle, insee) {
    champ.value = libelle;
    codeInsee.value = insee;
    vider();
  }

  function rendre(suggestions) {
    liste.innerHTML = '';
    if (!suggestions.length) { vider(); return; }
    suggestions.forEach(function (s) {
      var li = document.createElement('li');
      li.setAttribute('role', 'option');
      li.className = 'suggestion';
      li.textContent = s.libelle;
      li.addEventListener('mousedown', function (e) {
        e.preventDefault();
        choisir(s.libelle, s.code_insee);
      });
      liste.appendChild(li);
    });
    liste.hidden = false;
    champ.setAttribute('aria-expanded', 'true');
  }

  function chercher(q) {
    fetch(url + '?q=' + encodeURIComponent(q), { headers: { Accept: 'application/json' } })
      .then(function (r) { return r.ok ? r.json() : []; })
      .then(function (data) { if (Array.isArray(data)) rendre(data); })
      .catch(function () { vider(); });
  }

  champ.addEventListener('input', function () {
    // Toute frappe invalide le code INSEE déjà résolu : il faut re-choisir dans
    // la liste, sinon le serveur redemandera de choisir une adresse.
    codeInsee.value = '';
    var q = champ.value.trim();
    if (q === dernier) return;
    dernier = q;
    if (timer) clearTimeout(timer);
    if (q.length < minimum) { vider(); return; }
    timer = setTimeout(function () { chercher(q); }, 180);
  });

  champ.addEventListener('blur', function () { setTimeout(vider, 150); });
})();
