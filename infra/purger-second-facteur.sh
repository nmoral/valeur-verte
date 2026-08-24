#!/usr/bin/env bash
# Purge le second facteur (#312) d'une base restaurée HORS PRODUCTION.
#
# Pourquoi : le secret TOTP est stocké **en clair**, et il ne peut pas en être
# autrement — il est rejoué, pas comparé : le serveur recalcule le code qu'affiche
# le téléphone de l'admin. Un dump de production restauré sur un poste de
# développement y dépose donc ce secret. Celui qui met la main sur le dump n'a pas
# le mot de passe, mais il détient la moitié du second facteur — et un second
# facteur à moitié connu n'est plus qu'un facteur.
#
# Après purge, l'admin local se réenrôle sur SA base en dix secondes :
#   php bin/console app:admin:second-facteur:enroler <email>
#
# Usage (variables PostgreSQL standard) :
#   VV_PURGE_CONFIRME=oui infra/purger-second-facteur.sh
#
set -euo pipefail

: "${PGHOST:=127.0.0.1}"
: "${PGPORT:=5432}"
: "${PGUSER:?PGUSER requis}"
: "${PGDATABASE:=valeur_verte}"

# Garde-fou : jouée sur la production, cette purge enfermerait l'admin dehors —
# l'exact inverse du but. On exige donc un geste explicite, jamais un défaut.
if [ "${VV_PURGE_CONFIRME:-}" != "oui" ]; then
    cat >&2 <<'AIDE'
Refus : cette purge efface le second facteur de TOUS les comptes de la base visée.

Elle est destinée à une base restaurée hors production (poste de développement,
environnement de test). Jouée sur la production, elle verrouillerait l'admin
dehors — il n'y a personne au-dessus pour le rouvrir.

Pour confirmer :  VV_PURGE_CONFIRME=oui infra/purger-second-facteur.sh
AIDE
    exit 2
fi

echo "→ Purge du second facteur sur $PGDATABASE ($PGHOST:$PGPORT)"

# Les trois tables vont ensemble : le secret, les codes de secours qui l'ouvrent
# autrement, et les liens d'enrôlement encore vivants. En laisser une, c'est
# laisser passer soit le secret, soit une porte.
psql -v ON_ERROR_STOP=1 -d "$PGDATABASE" <<'SQL'
TRUNCATE TABLE app.jeton_enrolement_second_facteur;
TRUNCATE TABLE app.code_de_secours;
TRUNCATE TABLE app.second_facteur;
SQL

echo "→ Fait. Les comptes d'administration n'ont plus de second facteur sur cette base."
echo "  Réenrôler : php bin/console app:admin:second-facteur:enroler <email>"
