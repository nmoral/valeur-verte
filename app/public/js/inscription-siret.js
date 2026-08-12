// ONB-2 (#106) : pré-remplissage de la fiche entreprise par le SIRET.
// Progressive enhancement : sans JS, l'inscription reste possible. Toute
// défaillance de l'API dégrade vers la saisie manuelle, jamais de blocage.
(function () {
    'use strict';

    var champ = document.getElementById('siret');
    var apercu = document.getElementById('entreprise-apercu');
    var repli = document.getElementById('entreprise-repli');
    if (!champ || !apercu) {
        return;
    }

    var dernierSiret = null;
    var minuteur = null;

    function remplir(nom, valeur) {
        var cible = apercu.querySelector('[data-champ="' + nom + '"]');
        if (cible) {
            cible.textContent = valeur || '';
        }
    }

    function afficherFiche(fiche) {
        remplir('raisonSociale', fiche.raisonSociale);
        remplir('adresse', fiche.adresse);
        remplir('codeNaf', fiche.codeNaf);
        remplir('effectif', fiche.effectif);
        remplir('statutRgeLibelle', fiche.statutRgeLibelle);
        remplir('domainesRge', (fiche.domainesRge || []).join(', '));
        repli.hidden = true;
        apercu.hidden = false;
    }

    function degrader() {
        apercu.hidden = true;
        repli.hidden = false;
    }

    function rechercher() {
        var valeur = champ.value.replace(/\s+/g, '');
        if (!/^\d{14}$/.test(valeur)) {
            apercu.hidden = true;
            repli.hidden = true;
            return;
        }
        if (valeur === dernierSiret) {
            return;
        }
        dernierSiret = valeur;

        fetch('/inscription/entreprise/' + valeur, { headers: { Accept: 'application/json' } })
            .then(function (reponse) {
                return reponse.ok ? reponse.json() : Promise.reject(reponse.status);
            })
            .then(afficherFiche)
            .catch(degrader);
    }

    champ.addEventListener('input', function () {
        if (minuteur) {
            window.clearTimeout(minuteur);
        }
        minuteur = window.setTimeout(rechercher, 400);
    });
    champ.addEventListener('blur', rechercher);
})();
