"""Garde-fou : aucun secret de déploiement ne doit entrer dans l'historique git.

Contrainte de l'issue #78 (« aucun secret en clair dans le dépôt ni dans les
logs »). Une clé privée committée reste dans l'historique même après suppression :
elle doit être révoquée, pas effacée. Ce test coûte peu et attrape la faute avant
le commit.
"""

from __future__ import annotations

import subprocess
from pathlib import Path

from .conftest import REPO_ROOT

# Marqueurs composés à l'exécution : écrits en toutes lettres, ce fichier se
# dénoncerait lui-même et il faudrait l'exclure du scan — donc créer un angle mort.
MARQUEURS_CLE_PRIVEE = tuple(
    f"BEGIN {format} PRIVATE KEY" for format in ("OPENSSH", "RSA", "EC", "DSA", "PGP")
)

EXTENSIONS_BINAIRES = {".png", ".jpg", ".jpeg", ".gif", ".pdf", ".ico", ".woff", ".woff2"}


def _fichiers_versionnes() -> list[Path]:
    sortie = subprocess.run(
        ["git", "ls-files", "-z"],
        cwd=REPO_ROOT,
        capture_output=True,
        text=True,
        check=True,
    ).stdout
    return [REPO_ROOT / nom for nom in sortie.split("\0") if nom]


def test_aucune_cle_privee_n_est_versionnee() -> None:
    """Une clé privée dans un fichier suivi par git est compromise dès le push."""
    for fichier in _fichiers_versionnes():
        if fichier.suffix.lower() in EXTENSIONS_BINAIRES or not fichier.is_file():
            continue
        contenu = fichier.read_text(encoding="utf-8", errors="ignore")
        for marqueur in MARQUEURS_CLE_PRIVEE:
            assert marqueur not in contenu, (
                f"{fichier.relative_to(REPO_ROOT)} contient une clé privée ({marqueur}). "
                "La révoquer et la régénérer : la retirer du dépôt ne suffit pas."
            )


def test_les_cles_de_deploiement_sont_ignorees_par_git() -> None:
    """La clé du VPS vit dans ~/.ssh : un « git add -A » ne doit jamais pouvoir la happer."""
    verification = subprocess.run(
        ["git", "check-ignore", "id_ed25519_VPS", "id_rsa", "deploy_key.pem"],
        cwd=REPO_ROOT,
        capture_output=True,
        text=True,
        check=False,
    )
    ignores = set(verification.stdout.split())
    manquants = {"id_ed25519_VPS", "id_rsa", "deploy_key.pem"} - ignores
    assert not manquants, (
        f"Ces noms de clés ne sont pas couverts par .gitignore : {sorted(manquants)}."
    )
