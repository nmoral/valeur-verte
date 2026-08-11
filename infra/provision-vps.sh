#!/usr/bin/env bash
# Provisionnement du VPS pour le déploiement du pipeline « data » (#78, #99).
#
# Idempotent, et c'est la procédure de reconstruction de l'hôte : reconstruire
# doit rester bon marché, pour qu'une machine dérivée soit refaite plutôt que
# rafistolée (leçon de #99).
#
#   scp infra/provision-vps.sh vps:/tmp/
#   ssh vps 'sudo bash /tmp/provision-vps.sh'
set -euo pipefail

REPERTOIRE_DEPLOIEMENT=/opt/valeur-verte
COMPTE="${SUDO_USER:-ubuntu}"
# Injectable pour que le garde-fou ci-dessous soit exerçable par les tests sans
# modifier la machine qui les exécute.
FICHIER_OS="${FICHIER_OS:-/etc/os-release}"

echo "== Vérification de la version d'Ubuntu =="
# shellcheck source=/dev/null
. "$FICHIER_OS"
case "$VERSION_ID" in
    24.04 | 26.04)
        echo "Ubuntu $VERSION_ID LTS — OK"
        ;;
    *)
        echo "Ubuntu $VERSION_ID n'est pas une LTS supportée : provisionnement refusé." >&2
        echo "Une version intermédiaire n'est maintenue que neuf mois ; voir #99." >&2
        exit 1
        ;;
esac

echo "== Mise à jour du système =="
export DEBIAN_FRONTEND=noninteractive
apt-get update
apt-get -y upgrade

echo "== Correctifs de sécurité automatiques =="
# Sans cela, l'hôte redérive lentement vers l'état qui a motivé #99.
apt-get install -y unattended-upgrades
dpkg-reconfigure -f noninteractive unattended-upgrades
systemctl enable --now unattended-upgrades

echo "== Docker depuis le dépôt officiel =="
# Le dépôt Ubuntu retarde souvent de plusieurs versions sur un moteur qui fait
# tourner des conteneurs en production.
if ! command -v docker >/dev/null 2>&1; then
    apt-get install -y ca-certificates curl gnupg
    install -m 0755 -d /etc/apt/keyrings
    curl -fsSL https://download.docker.com/linux/ubuntu/gpg \
        | gpg --dearmor -o /etc/apt/keyrings/docker.gpg
    chmod a+r /etc/apt/keyrings/docker.gpg
    printf 'deb [arch=%s signed-by=/etc/apt/keyrings/docker.gpg] https://download.docker.com/linux/ubuntu %s stable\n' \
        "$(dpkg --print-architecture)" "$VERSION_CODENAME" \
        > /etc/apt/sources.list.d/docker.list
    apt-get update
fi
apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
systemctl enable --now docker

echo "== Compte de déploiement =="
usermod -aG docker "$COMPTE"

echo "== Répertoire de déploiement =="
# /opt appartient à root : le workflow ne peut pas créer ce répertoire lui-même.
install -d -o "$COMPTE" -g "$COMPTE" -m 755 "$REPERTOIRE_DEPLOIEMENT"

echo "== Durcissement SSH =="
# Déploiement comme administration passent par clé : le mot de passe n'est
# qu'une surface d'attaque supplémentaire.
printf 'PasswordAuthentication no\nPermitRootLogin no\n' \
    > /etc/ssh/sshd_config.d/99-valeur-verte.conf
sshd -t && systemctl reload ssh

echo
echo "Provisionnement terminé."
docker --version
docker compose version
echo "Empreinte de l'hôte, à épingler dans le secret SSH_KNOWN_HOSTS :"
ssh-keyscan -t ed25519 localhost 2>/dev/null | sed 's/^localhost/<hôte>/'
