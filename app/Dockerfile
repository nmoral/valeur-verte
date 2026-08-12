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
RUN install-php-extensions \
        intl \
        opcache \
        zip \
        pdo_pgsql \
        @composer

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

# Exécution sans privilège. Outre var/ (cache/logs Symfony), FrankenPHP écrit son
# état Caddy dans /data et /config : ces dossiers doivent appartenir à l'utilisateur.
# Le binaire FrankenPHP porte déjà CAP_NET_BIND_SERVICE, d'où le bind sur :80 en rootless.
RUN useradd --uid 10001 --create-home app \
    && chown -R app:app var /data /config
USER app

EXPOSE 80
