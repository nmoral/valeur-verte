#!/usr/bin/env bash
# Applique nom / couleur / description de .github/labels.yml aux labels du dépôt.
#
# Prérequis : GitHub CLI authentifié
#   gh auth login            # une fois
#
# Usage (depuis la racine du dépôt) :
#   bash scripts/apply-labels.sh                 # cible nmoral/valeur-verte
#   bash scripts/apply-labels.sh owner/repo      # autre dépôt
#
# Idempotent : `gh label create --force` crée ou met à jour chaque label.
set -euo pipefail

REPO="${1:-nmoral/valeur-verte}"

if ! command -v gh >/dev/null 2>&1; then
  echo "Erreur : GitHub CLI (gh) introuvable. Installer : https://cli.github.com/" >&2
  exit 1
fi

python3 - "$REPO" <<'PY'
import subprocess, sys, re

repo = sys.argv[1]
name = color = desc = None

def flush():
    if name and color:
        subprocess.run(
            ["gh", "label", "create", name, "--repo", repo,
             "--color", color, "--description", desc or "", "--force"],
            check=True,
        )
        print(f"  ok  {name}")

with open(".github/labels.yml", encoding="utf-8") as f:
    for line in f:
        m = re.match(r'\s*-\s*name:\s*"(.*)"\s*$', line)
        if m:
            flush()
            name, color, desc = m.group(1), None, None
            continue
        m = re.match(r'\s*color:\s*"(.*)"\s*$', line)
        if m:
            color = m.group(1)
            continue
        m = re.match(r'\s*description:\s*"(.*)"\s*$', line)
        if m:
            desc = m.group(1)
            continue
flush()
PY

echo "Terminé : couleurs des labels appliquées à $REPO."
