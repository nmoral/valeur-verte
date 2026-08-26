# Pièges historiques du mutation testing dans l'image

Détails que `scripts/run-mutation.sh` neutralise déjà, consignés ici pour
diagnostiquer un run qui déraille ou pour reproduire la procédure à la main.

## Un seul conteneur pour tout

Chaque `docker compose run` est un **conteneur neuf**. pcov installé dans l'un
n'existe pas dans le suivant : installer pcov, générer la couverture **et** lancer
Infection doivent tenir dans le **même** `run`. Symptômes d'un découpage fautif :
« No code coverage driver available », puis Infection « Could not find index.xml ».

## Mémoire : `-d memory_limit=-1`

La **génération de couverture** OOM à 128 Mo (`Allowed memory size … exhausted`
dans `php-code-coverage/.../Xml.php`, ou dans `mpdf/…/TTFontFile.php`). phpunit
**et** Infection tournent avec `-d memory_limit=-1`. (Le `--memory-limit=1G` de
PHPStan est un autre réglage, sans effet ici.)

## Réseau et base du worktree

Un `docker compose run` **sans** sourcer `scripts/worktree-env.sh --export` lance la
db sur le **port 5432 par défaut** (→ « address already in use » face à un autre
worktree) et le conteneur ne résout pas l'hôte `db` (« could not translate host name
"db" ») : sous couverture, des dizaines de tests fonctionnels tombent en erreur et le
MSI est faussé. Reproduire `app-dev.sh` : `eval "$(scripts/worktree-env.sh --export)"`
puis `docker compose -p "$COMPOSE_PROJECT_NAME" … up -d --wait db` **avant** le `run`
root, mêmes `-p`/env, dans le **même** appel shell.

## MSI flatteur au timeout par défaut

Au `timeout` par défaut d'Infection (10 s), la suite complète sous pcov est tuée
(SIGTERM, exit 143) et plus de la moitié des mutants sont écartés — comptés comme
« tués », d'où un « 100 % » qui ne prouve rien. `infection.json5` porte
`"timeout": 120`. Si un run affiche « N mutants required more time than configured »,
le chiffre est faux, pas bon.

## `chown` de retour obligatoire

Le conteneur tourne en `-u 0:0` : les fichiers créés (`var/cov`, `var/cache`,
`.phpunit.cache`) appartiennent à root. Sans `chown` de retour vers l'uid hôte, les
runs suivants ne peuvent plus écrire le cache **et `git worktree remove` échoue en
« Permission non accordée »** (il supprime alors le lien administratif *avant*
d'échouer, laissant un dossier orphelin à effacer à la main puis `git worktree
prune`). Réparer sans sudo : rejouer un conteneur `-u 0:0` montant le worktree et y
faire le `chown -R`.

## vendor hardlinké partagé

Si le `vendor/` a été créé par hardlinks depuis un autre worktree
(`cp -al …/app/vendor app/vendor`), il reste sûr **tant qu'on ne touche pas à
Composer**. Un `composer require`/`install` réécrit `vendor/composer/installed.json`
et l'autoload **sur place**, donc dans TOUS les worktrees partageant l'inode. Dès
qu'une tâche modifie les dépendances : `rm -rf app/vendor && scripts/app-dev.sh
composer install` pour repartir d'un vendor non partagé (vérifier
`stat -c %h app/vendor/composer/installed.json` = 1).
