#!/usr/bin/env bash
# Test de restauration réel (#55, §9.11) : « une perte de base n'est pas un incident
# technique, c'est la fin du produit ». Un dump qu'on n'a jamais restauré n'est pas
# une sauvegarde, c'est une hypothèse. À jouer **avant la première mise en production,
# puis tous les six mois** (registre #190).
#
# Restaure un dump dans une base **jetable**, y vérifie que les tables clés portent
# des lignes, puis la supprime. N'écrit jamais dans la base de production.
#
# Connexion : variables PostgreSQL standard (rôle **privilégié**, celui qui a produit
# le dump). Sur le VPS, via l'image app (elle porte pg_restore/psql depuis #55) :
#
#   docker compose -f infra/docker-compose.prod.yml run --rm --no-deps \
#     -v ./infra:/infra:ro -v db_backups:/sauvegardes:ro \
#     -e PGHOST=db -e PGUSER="$POSTGRES_USER" -e PGPASSWORD="$POSTGRES_PASSWORD" \
#     --entrypoint bash cron /infra/restauration-test.sh /sauvegardes/<dump>
#
set -euo pipefail

dump="${1:?usage: restauration-test.sh <chemin-du-dump>}"
[ -r "$dump" ] || { echo "Dump introuvable ou illisible : $dump" >&2; exit 2; }

: "${PGHOST:=127.0.0.1}"
: "${PGPORT:=5432}"
: "${PGUSER:?PGUSER requis (rôle privilégié)}"
: "${PGDATABASE:=valeur_verte}"

base_test="${PGDATABASE}_restore_test_$$"

nettoyer() {
    psql -d postgres -v ON_ERROR_STOP=1 -c "DROP DATABASE IF EXISTS \"$base_test\"" >/dev/null 2>&1 || true
}
trap nettoyer EXIT

echo "→ Restauration de $(basename "$dump") dans la base jetable $base_test"
psql -d postgres -v ON_ERROR_STOP=1 -c "CREATE DATABASE \"$base_test\"" >/dev/null

# --no-owner/--no-privileges : la base jetable n'a pas forcément les rôles de prod ;
# on éprouve la restauration des données et du schéma, pas la reconstitution des ACL.
pg_restore --no-owner --no-privileges --dbname="$base_test" "$dump"

echo "→ Vérification : les tables clés portent des lignes"
lignes=$(psql -d "$base_test" -tAc "
    SELECT format('%s=%s', t, n) FROM (
        SELECT 'app.rapport'              AS t, (SELECT count(*) FROM app.rapport)              AS n
        UNION ALL SELECT 'app.utilisateur',       (SELECT count(*) FROM app.utilisateur)
        UNION ALL SELECT 'data.vente',            (SELECT count(*) FROM data.vente)
        UNION ALL SELECT 'data.commune',          (SELECT count(*) FROM data.commune)
        UNION ALL SELECT 'data.coefficient_maille',(SELECT count(*) FROM data.coefficient_maille)
    ) s")

echo "$lignes"

# data.vente est le cœur : une restauration qui rend zéro vente est une restauration
# vide, pas une réussite. (app.rapport peut légitimement être à zéro sur une base neuve.)
ventes=$(printf '%s\n' "$lignes" | sed -n 's/^data\.vente=//p')
if [ -z "$ventes" ] || [ "$ventes" -eq 0 ]; then
    echo "ÉCHEC : data.vente est vide après restauration." >&2
    exit 1
fi

echo "✓ Restauration vérifiée ($ventes ventes). La base jetable est supprimée."
