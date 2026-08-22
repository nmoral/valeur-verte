# Image de l'application « app » (widget, mode audit, back-office, admin) déployée
# sur le VPS OVH (#78), aux côtés de la base PostGIS partagée avec le pipeline `data`.
#
# Runtime FrankenPHP (serveur + PHP dans un seul binaire) : un seul conteneur à
# déployer. TLS terminé par le proxy de l'hôte — le serveur écoute en clair sur :80
# (SERVER_NAME=:80 désactive l'auto-HTTPS de Caddy).
#
# La construction est reproductible : dépendances installées à partir du
# composer.lock, sans l'extra « dev » (ni PHPUnit ni PHPStan en production).

FROM dunglas/frankenphp:1-php8.4 AS app

ENV APP_ENV=prod \
    APP_DEBUG=0 \
    SERVER_NAME=:80 \
    COMPOSER_ALLOW_SUPERUSER=1

# pdo_pgsql : la base est PostgreSQL/PostGIS. intl/zip/opcache : socle Symfony.
# gd : rendu des images (logo du pro) par mpdf lors de la génération du PDF (#32).
RUN install-php-extensions \
        intl \
        opcache \
        zip \
        gd \
        pdo_pgsql \
        @composer

# Ordonnanceur des tâches planifiées (#300) : le service « cron » du compose de
# production lance cette même image avec supercronic à la place du serveur. Un
# cron classique ne conviendrait pas — il veut être PID 1 et tourner en root, or
# l'image s'exécute sans privilège ; supercronic est prévu pour le conteneur
# (sans root, journal sur la sortie standard, pas de recouvrement d'exécutions).
#
# Version épinglée et somme de contrôle vérifiée : sans elles, l'image dépendrait
# de ce que sert le réseau le jour du build. Au changement de version, recalculer
# la somme avec « sha256sum » sur le binaire téléchargé.
ARG SUPERCRONIC_VERSION=v0.2.49
ARG SUPERCRONIC_SHA256=a53ae236602c7338aba3fbaff40bda6300eae3b9fedb8261eb06cfe3724430c1
RUN curl -fsSLo /usr/local/bin/supercronic \
        "https://github.com/aptible/supercronic/releases/download/${SUPERCRONIC_VERSION}/supercronic-linux-amd64" \
    && echo "${SUPERCRONIC_SHA256}  /usr/local/bin/supercronic" | sha256sum -c - \
    && chmod 0755 /usr/local/bin/supercronic

WORKDIR /app

# Couche de dépendances : ne dépend que des fichiers de verrouillage, donc mise
# en cache tant que composer.lock ne change pas.
COPY composer.json composer.lock symfony.lock ./
RUN composer install \
        --no-dev --no-scripts --no-progress --prefer-dist \
        --optimize-autoloader --no-interaction

# Code applicatif (le .dockerignore exclut vendor/, var/, tests/, secrets locaux).
COPY . ./

# Autoload figé + préparation du cache Symfony en environnement prod.
RUN composer dump-autoload --classmap-authoritative --no-dev --no-interaction \
    && php bin/console cache:clear --no-warmup \
    && php bin/console cache:warmup

# Assets front (AssetMapper, sans Node) : asset-map:compile fige public/assets/
# (dist versionné + manifest) pour le runtime. Les dépendances front vivent dans
# assets/vendor (versionné) — pas de importmap:install, donc pas de réseau requis.
RUN php bin/console asset-map:compile

# Exécution sans privilège. Outre var/ (cache/logs Symfony), FrankenPHP écrit son
# état Caddy dans /data et /config : ces dossiers doivent appartenir à l'utilisateur.
# Le binaire FrankenPHP porte déjà CAP_NET_BIND_SERVICE, d'où le bind sur :80 en rootless.
RUN useradd --uid 10001 --create-home app \
    && chown -R app:app var /data /config
USER app

EXPOSE 80
