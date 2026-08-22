/*
 * Autocomplétion d'adresse de la page publique (#287).
 *
 * Le moteur travaille par commune : sans code INSEE, aucun chiffre. Le champ
 * adresse interroge donc notre endpoint — jamais la BAN directement, la CSP de la
 * zone gardant `connect-src 'self'` — et reporte dans le champ caché le code INSEE
 * de l'adresse **choisie dans la liste**.
 *
 * Toute frappe invalide le code retenu : une adresse retouchée à la main n'est
 * plus celle qu'on a résolue, et chiffrer sur la commune précédente serait pire
 * que ne rien chiffrer.
 *
 * Amélioration progressive : sans JavaScript, le champ reste une saisie libre et
 * le serveur renvoie le formulaire en invitant à choisir une suggestion. Toute
 * défaillance (réseau, JSON, endpoint plafonné) masque simplement la liste.
 */
const formulaire = document.querySelector('[data-adresse-url]');
const champ = formulaire?.querySelector('[data-adresse="champ"]');
const liste = formulaire?.querySelector('[data-adresse="suggestions"]');
const champInsee = formulaire?.querySelector('input[name="code_insee"]');

if (formulaire && champ && liste && champInsee) {
    const url = formulaire.dataset.adresseUrl;
    // En deçà, la saisie ne discrimine rien — même seuil que l'endpoint.
    const LONGUEUR_MINIMALE = 3;
    const DELAI_FRAPPE = 250;

    let minuteur = null;
    let derniereRequete = '';
    let indexActif = -1;

    const masquer = () => {
        liste.replaceChildren();
        liste.hidden = true;
        champ.setAttribute('aria-expanded', 'false');
        indexActif = -1;
    };

    const choisir = (item) => {
        champ.value = item.textContent ?? '';
        champInsee.value = item.dataset.codeInsee ?? '';
        derniereRequete = champ.value;
        masquer();
        champ.focus();
    };

    const surligner = (items) => {
        items.forEach((item, i) => item.setAttribute('aria-selected', String(i === indexActif)));
    };

    const afficher = (suggestions) => {
        liste.replaceChildren();
        suggestions.forEach((suggestion) => {
            const item = document.createElement('li');
            item.setAttribute('role', 'option');
            item.setAttribute('aria-selected', 'false');
            item.textContent = suggestion.libelle;
            item.dataset.codeInsee = suggestion.code_insee;
            // `mousedown` plutôt que `click` : le blur du champ masque la liste
            // avant qu'un clic ait le temps d'aboutir.
            item.addEventListener('mousedown', (evenement) => {
                evenement.preventDefault();
                choisir(item);
            });
            liste.append(item);
        });

        if (0 === liste.children.length) {
            masquer();

            return;
        }

        liste.hidden = false;
        champ.setAttribute('aria-expanded', 'true');
    };

    const rechercher = async () => {
        const requete = champ.value.trim();
        if (requete.length < LONGUEUR_MINIMALE || requete === derniereRequete) {
            return;
        }
        derniereRequete = requete;

        try {
            const reponse = await fetch(`${url}?q=${encodeURIComponent(requete)}`, {
                headers: { Accept: 'application/json' },
            });
            if (!reponse.ok) {
                masquer();

                return;
            }
            afficher(await reponse.json());
        } catch {
            masquer();
        }
    };

    champ.addEventListener('input', () => {
        // Toute frappe repart sans commune : le code INSEE ne vaut que par une
        // sélection dans la liste.
        champInsee.value = '';
        if (champ.value.trim().length < LONGUEUR_MINIMALE) {
            masquer();
        }
        window.clearTimeout(minuteur);
        minuteur = window.setTimeout(rechercher, DELAI_FRAPPE);
    });

    champ.addEventListener('keydown', (evenement) => {
        const items = Array.from(liste.children);
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
            // Entrée choisit la suggestion surlignée plutôt que de soumettre le
            // formulaire — sans quoi on partirait sans code INSEE.
            evenement.preventDefault();
            choisir(items[indexActif]);
        } else if ('Escape' === evenement.key) {
            masquer();
        }
    });

    // Un court délai laisse le clic sur une suggestion aboutir avant le masquage.
    champ.addEventListener('blur', () => window.setTimeout(masquer, 150));
}
