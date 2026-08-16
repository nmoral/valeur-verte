#!/usr/bin/env bash
# Compose sur la sortie standard le fichier `.env` déposé sur le VPS (#78).
#
# Dans un fichier .env, Compose interpole « $VAR » : un mot de passe contenant
# « $ » serait amputé, et le fragment concerné recopié en clair dans les
# journaux — c'est ce qui s'est produit au premier déploiement réel. Une valeur
# entre apostrophes n'est pas interpolée. (« $$ » ne fonctionne pas ici : c'est
# la convention du fichier compose, pas celle du .env.)
#
# Ce fichier ne porte que des valeurs atomiques. Les DSN, qui ont leur propre
# quoting (conninfo pour le pipeline data, URL pour l'app), sont assemblées dans
# le compose de production : imbriquer les deux échappements dans une même valeur
# n'a pas de forme sûre.
set -euo pipefail

: "${POSTGRES_USER:?POSTGRES_USER est requis}"
: "${POSTGRES_DB:?POSTGRES_DB est requis}"
: "${POSTGRES_PASSWORD:?POSTGRES_PASSWORD est requis}"
: "${DATA_IMAGE_TAG:?DATA_IMAGE_TAG est requis}"
: "${APP_IMAGE_TAG:?APP_IMAGE_TAG est requis}"
# Configuration applicative (#105) : secret Symfony (jetons signés, CSRF), mot de
# passe du rôle applicatif « app » (assemblé en DSN dans le compose) et clé Brevo.
: "${APP_SECRET:?APP_SECRET est requis}"
: "${APP_DB_PASSWORD:?APP_DB_PASSWORD est requis}"
: "${BREVO_API_KEY:?BREVO_API_KEY est requis}"

# L'apostrophe et la barre oblique inverse sont les deux caractères que ni le
# format .env ni le format conninfo ne transportent sans ambiguïté. On refuse
# plutôt que de livrer un identifiant faux : la panne serait silencieuse et se
# manifesterait bien plus tard, sur une base déjà initialisée.
for valeur in "$POSTGRES_USER" "$POSTGRES_DB" "$POSTGRES_PASSWORD" \
    "$DATA_IMAGE_TAG" "$APP_IMAGE_TAG" "$APP_SECRET" "$BREVO_API_KEY"; do
    case "$valeur" in
        *\\* | *\'*)
            echo "Un identifiant contient une apostrophe ou une barre oblique inverse," \
                 "non transmissibles sans ambiguïté au conteneur : en changer." >&2
            exit 1
            ;;
    esac
done

# Le mot de passe du rôle « app » est injecté tel quel dans une DSN
# « postgresql://app:<mot de passe>@… » (compose) : un caractère réservé d'URL
# (@, :, /, #, ?, &, %…) casserait le parsing côté Doctrine. On exige donc un mot
# de passe strictement URL-safe (caractères non réservés RFC 3986) plutôt que de
# livrer une DSN silencieusement tronquée.
case "$APP_DB_PASSWORD" in
    *[!A-Za-z0-9_.~-]*)
        echo "APP_DB_PASSWORD doit être URL-safe (A-Z a-z 0-9 _ . ~ -) : il est injecté" \
             "dans une DSN postgresql://app:<mot de passe>@…. Générer par ex." \
             "« openssl rand -hex 24 »." >&2
        exit 1
        ;;
esac

printf "POSTGRES_USER='%s'\n" "$POSTGRES_USER"
printf "POSTGRES_DB='%s'\n" "$POSTGRES_DB"
printf "POSTGRES_PASSWORD='%s'\n" "$POSTGRES_PASSWORD"
printf "DATA_IMAGE_TAG='%s'\n" "$DATA_IMAGE_TAG"
printf "APP_IMAGE_TAG='%s'\n" "$APP_IMAGE_TAG"
printf "APP_SECRET='%s'\n" "$APP_SECRET"
printf "APP_DB_PASSWORD='%s'\n" "$APP_DB_PASSWORD"
printf "BREVO_API_KEY='%s'\n" "$BREVO_API_KEY"

# Bascule maintenance (#197) : absente = désactivée. On l'écrit explicitement à 0
# pour que Compose n'avertisse pas d'une variable non définie ; l'exploitant la
# passe à 1 sur le VPS (dans ce .env) pour couper proprement en 503, sans
# redéploiement ni reconstruction d'image.
printf "APP_MAINTENANCE='%s'\n" "${APP_MAINTENANCE:-0}"
