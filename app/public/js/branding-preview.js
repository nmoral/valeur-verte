// Prévisualisation du PDF en direct (#36) : reflète le formulaire de marque
// dans l'aperçu, sans recharger la page.
(function () {
    'use strict';

    var couleur = document.getElementById('champ-couleur');
    var rge = document.getElementById('champ-rge');
    var tel = document.getElementById('champ-tel');
    var mentions = document.getElementById('champ-mentions');
    var logoUrl = document.getElementById('champ-logo-url');

    var bandeau = document.getElementById('apercu-bandeau');
    var apercuRge = document.getElementById('apercu-rge');
    var apercuTel = document.getElementById('apercu-tel');
    var apercuMentions = document.getElementById('apercu-mentions');
    var apercuLogo = document.getElementById('apercu-logo');

    if (!bandeau) {
        return;
    }

    var HEX = /^#[0-9A-Fa-f]{6}$/;

    function texte(cible, valeur) {
        cible.textContent = valeur.trim() === '' ? '—' : valeur;
    }

    function majCouleur() {
        if (HEX.test(couleur.value)) {
            bandeau.style.background = couleur.value;
        }
    }

    couleur.addEventListener('input', majCouleur);
    rge.addEventListener('input', function () { texte(apercuRge, rge.value); });
    tel.addEventListener('input', function () { texte(apercuTel, tel.value); });
    mentions.addEventListener('input', function () { texte(apercuMentions, mentions.value); });
    logoUrl.addEventListener('input', function () {
        var url = logoUrl.value.trim();
        apercuLogo.innerHTML = url === '' ? 'LOGO' : '<img src="' + url + '" alt="logo">';
    });

    // Sélection d'une pastille : renseigne le code couleur et met à jour l'aperçu.
    Array.prototype.forEach.call(document.querySelectorAll('#swatches i'), function (pastille) {
        pastille.addEventListener('click', function () {
            couleur.value = pastille.getAttribute('data-couleur');
            majCouleur();
            Array.prototype.forEach.call(document.querySelectorAll('#swatches i'), function (autre) {
                autre.classList.remove('sel');
            });
            pastille.classList.add('sel');
        });
    });
})();
