#!/usr/bin/env bash
# Compose sur la sortie standard le fichier `.env` déposé sur le VPS (#78).
#
# Dans un fichier .env, Compose interpole « $VAR » : un mot de passe contenant
# « $ » serait amputé, et le fragment concerné recopié en clair dans les
# journaux — c'est ce qui s'est produit au premier déploiement réel. Une valeur
# entre apostrophes n'est pas interpolée. (« $$ » ne fonctionne pas ici : c'est
# la convention du fichier compose, pas celle du .env.)
#
# Ce fichier ne porte que des valeurs atomiques. La DSN, qui a son propre
# quoting libpq, est assemblée dans le compose de production : imbriquer les
# deux échappements dans une même valeur n'a pas de forme sûre.
set -euo pipefail

: "${POSTGRES_USER:?POSTGRES_USER est requis}"
: "${POSTGRES_DB:?POSTGRES_DB est requis}"
: "${POSTGRES_PASSWORD:?POSTGRES_PASSWORD est requis}"
: "${DATA_IMAGE_TAG:?DATA_IMAGE_TAG est requis}"

# L'apostrophe et la barre oblique inverse sont les deux caractères que ni le
# format .env ni le format conninfo ne transportent sans ambiguïté. On refuse
# plutôt que de livrer un identifiant faux : la panne serait silencieuse et se
# manifesterait bien plus tard, sur une base déjà initialisée.
for valeur in "$POSTGRES_USER" "$POSTGRES_DB" "$POSTGRES_PASSWORD" "$DATA_IMAGE_TAG"; do
    case "$valeur" in
        *\\* | *\'*)
            echo "Un identifiant contient une apostrophe ou une barre oblique inverse," \
                 "non transmissibles sans ambiguïté au conteneur : en changer." >&2
            exit 1
            ;;
    esac
done

printf "POSTGRES_USER='%s'\n" "$POSTGRES_USER"
printf "POSTGRES_DB='%s'\n" "$POSTGRES_DB"
printf "POSTGRES_PASSWORD='%s'\n" "$POSTGRES_PASSWORD"
printf "DATA_IMAGE_TAG='%s'\n" "$DATA_IMAGE_TAG"
