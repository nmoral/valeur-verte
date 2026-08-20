/*
 * Émetteur des étapes de parcours qui n'existent que dans le navigateur (#90).
 *
 * Le parcours du widget est rendu et posté par le serveur : chargement, premier
 * chiffre, mur de contact et lead s'y constatent seuls. Restent les étapes sans
 * requête serveur — l'adresse est saisie, le détail est déplié — que ce module
 * déclare à l'endpoint de collecte.
 *
 * Amélioration progressive : sans JavaScript le parcours fonctionne à
 * l'identique, seules ces mesures manquent. Le module ne lit rien d'autre que
 * ses propres marqueurs, n'envoie aucune donnée saisie (jamais l'adresse
 * elle-même), et n'échoue jamais bruyamment — une mesure perdue ne doit pas
 * abîmer un parcours.
 */
const formulaire = document.querySelector('[data-parcours-url]');

if (formulaire) {
    const url = formulaire.dataset.parcoursUrl;
    const session = formulaire.querySelector('input[name="session_parcours"]')?.value ?? '';
    const dejaDeclarees = new Set();

    const declarer = (type) => {
        if (dejaDeclarees.has(type) || '' === session) {
            return;
        }
        dejaDeclarees.add(type);

        const corps = new FormData();
        corps.append('type', type);
        corps.append('session_parcours', session);
        // `keepalive` : la mesure part même si la page navigue dans la foulée.
        fetch(url, { method: 'POST', body: corps, keepalive: true }).catch(() => {});
    };

    // L'adresse est réputée saisie quand le champ est renseigné et quitté : on
    // ne mesure pas la frappe, seulement l'étape franchie.
    const adresse = formulaire.querySelector('[data-parcours="adresse"]');
    adresse?.addEventListener('change', () => {
        if ('' !== adresse.value.trim()) {
            declarer('adresse_saisie');
        }
    });

    // Le détail dépliable n'existe pas encore dans la page ; dès qu'il existera,
    // il lui suffira de porter ce marqueur pour être mesuré.
    document
        .querySelector('[data-parcours="detail"]')
        ?.addEventListener('toggle', (evenement) => {
            if (evenement.target.open) {
                declarer('detail_deplie');
            }
        });
}
