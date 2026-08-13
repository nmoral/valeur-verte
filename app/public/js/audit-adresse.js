// Autocomplétion d'adresse en mode audit (#28) via la Base Adresse Nationale
// (api-adresse.data.gouv.fr, publique). Progressive enhancement : sans JS, le
// champ reste une saisie libre. Toute défaillance masque simplement la liste.
(function () {
    'use strict';

    var champ = document.getElementById('adresse');
    var liste = document.getElementById('adresse-suggestions');
    if (!champ || !liste) {
        return;
    }

    var boutonGeoloc = document.getElementById('adresse-geoloc');
    var erreurGeoloc = document.getElementById('adresse-geoloc-erreur');
    var spinner = document.getElementById('adresse-spin');

    function chargement(actif) {
        if (spinner) {
            spinner.hidden = !actif;
        }
    }

    var minuteur = null;
    var derniere = '';
    var indexActif = -1;

    function masquer() {
        liste.classList.remove('show');
        liste.innerHTML = '';
        indexActif = -1;
    }

    function choisir(label) {
        champ.value = label;
        masquer();
        champ.focus();
    }

    function surligner(items) {
        items.forEach(function (item, i) {
            item.classList.toggle('actif', i === indexActif);
        });
    }

    function afficher(features) {
        liste.innerHTML = '';
        features.forEach(function (feature) {
            var label = feature.properties && feature.properties.label ? feature.properties.label : '';
            if ('' === label) {
                return;
            }
            var item = document.createElement('li');
            item.setAttribute('role', 'option');
            item.textContent = label;
            item.addEventListener('mousedown', function (evenement) {
                evenement.preventDefault();
                choisir(label);
            });
            liste.appendChild(item);
        });
        if (liste.children.length > 0) {
            liste.classList.add('show');
        } else {
            masquer();
        }
    }

    function rechercher() {
        var requete = champ.value.trim();
        if (requete.length < 3) {
            masquer();
            return;
        }
        if (requete === derniere) {
            return;
        }
        derniere = requete;
        chargement(true);

        fetch('https://api-adresse.data.gouv.fr/search/?limit=5&q=' + encodeURIComponent(requete))
            .then(function (reponse) {
                return reponse.ok ? reponse.json() : Promise.reject(reponse.status);
            })
            .then(function (donnees) {
                afficher(donnees.features || []);
            })
            .catch(masquer)
            .finally(function () {
                chargement(false);
            });
    }

    champ.addEventListener('input', function () {
        if (minuteur) {
            window.clearTimeout(minuteur);
        }
        minuteur = window.setTimeout(rechercher, 250);
    });

    champ.addEventListener('keydown', function (evenement) {
        var items = Array.prototype.slice.call(liste.children);
        if (0 === items.length) {
            return;
        }
        if ('ArrowDown' === evenement.key) {
            evenement.preventDefault();
            indexActif = Math.min(indexActif + 1, items.length - 1);
            surligner(items);
        } else if ('ArrowUp' === evenement.key) {
            evenement.preventDefault();
            indexActif = Math.max(indexActif - 1, 0);
            surligner(items);
        } else if ('Enter' === evenement.key && indexActif >= 0) {
            evenement.preventDefault();
            choisir(items[indexActif].textContent || '');
        } else if ('Escape' === evenement.key) {
            masquer();
        }
    });

    champ.addEventListener('blur', function () {
        window.setTimeout(masquer, 150);
    });

    function messageGeoloc(texte) {
        if (!erreurGeoloc) {
            return;
        }
        erreurGeoloc.textContent = texte;
        erreurGeoloc.hidden = '' === texte;
    }

    function finGeoloc() {
        boutonGeoloc.disabled = false;
        boutonGeoloc.classList.remove('charge');
        chargement(false);
    }

    function depuisPosition(position) {
        var url = 'https://api-adresse.data.gouv.fr/reverse/?lon=' + position.coords.longitude + '&lat=' + position.coords.latitude;
        fetch(url)
            .then(function (reponse) {
                return reponse.ok ? reponse.json() : Promise.reject(reponse.status);
            })
            .then(function (donnees) {
                var features = donnees.features || [];
                if (0 === features.length) {
                    messageGeoloc('Aucune adresse trouvée autour de votre position.');
                    return;
                }
                champ.value = features[0].properties.label || '';
                derniere = champ.value;
                afficher(features);
                champ.focus();
            })
            .catch(function () {
                messageGeoloc('Impossible de retrouver l\'adresse. Saisissez-la à la main.');
            })
            .finally(finGeoloc);
    }

    function geolocaliser() {
        if (!navigator.geolocation) {
            messageGeoloc('La géolocalisation n\'est pas disponible sur cet appareil.');
            return;
        }
        messageGeoloc('');
        boutonGeoloc.disabled = true;
        boutonGeoloc.classList.add('charge');
        chargement(true);

        navigator.geolocation.getCurrentPosition(
            depuisPosition,
            function () {
                messageGeoloc('Position refusée ou indisponible. Saisissez l\'adresse à la main.');
                finGeoloc();
            },
            { enableHighAccuracy: true, timeout: 8000, maximumAge: 60000 },
        );
    }

    if (boutonGeoloc) {
        boutonGeoloc.addEventListener('click', geolocaliser);
    }
})();
