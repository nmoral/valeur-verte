"""Garde-fou du script de provisionnement du VPS (`infra/provision-vps.sh`).

Le script reconstruit l'hôte : mises à jour, Docker, compte de déploiement,
durcissement SSH. Son premier geste est de refuser une version d'Ubuntu non
supportée — c'est ce qui empêche le retour de la situation décrite en #99 (un
serveur resté 19 mois sans correctif de sécurité).

Seul ce refus est exercé ici : il précède toute modification du système, donc
il est sûr à jouer en test. Le reste du script installe des paquets en root et
ne s'éprouve que sur une machine réelle.
"""

from __future__ import annotations

import subprocess
from pathlib import Path

from .conftest import REPO_ROOT

PROVISION = REPO_ROOT / "infra" / "provision-vps.sh"


def _provisionner(fichier_os: Path) -> subprocess.CompletedProcess[str]:
    """Lance le script avec une description d'OS injectée, sans toucher au système."""
    return subprocess.run(
        ["bash", str(PROVISION)],
        capture_output=True,
        text=True,
        check=False,
        env={"PATH": "/usr/bin:/bin", "FICHIER_OS": str(fichier_os)},
    )


def _decrire_os(repertoire: Path, version: str, nom: str = "essai") -> Path:
    fichier = repertoire / "os-release"
    fichier.write_text(
        f'VERSION_ID="{version}"\nVERSION_CODENAME={nom}\nPRETTY_NAME="Ubuntu {version}"\n',
        encoding="utf-8",
    )
    return fichier


def test_une_version_en_fin_de_vie_est_refusee(tmp_path: Path) -> None:
    """Provisionner une 23.04 reconstruirait exactement le problème de #99."""
    execution = _provisionner(_decrire_os(tmp_path, "23.04", "lunar"))

    assert execution.returncode != 0, "Le script doit refuser une version en fin de vie."
    assert "23.04" in execution.stderr


def test_une_version_intermediaire_est_refusee(tmp_path: Path) -> None:
    """Une version non-LTS n'est supportée que neuf mois : trop court pour un serveur."""
    execution = _provisionner(_decrire_os(tmp_path, "25.10"))

    assert execution.returncode != 0, "Seules les LTS sont acceptées."
    # Sans ce contrôle, un script absent suffirait à faire passer le test.
    assert "25.10" in execution.stderr, (
        f"L'échec doit être un refus motivé, pas une panne : {execution.stderr!r}"
    )


def test_le_refus_precede_toute_modification_du_systeme(tmp_path: Path) -> None:
    """Un refus tardif laisserait la machine à moitié provisionnée."""
    execution = _provisionner(_decrire_os(tmp_path, "23.04", "lunar"))

    assert "23.04" in execution.stderr, (
        f"L'échec doit être un refus motivé, pas une panne : {execution.stderr!r}"
    )
    assert "Mise à jour du système" not in execution.stdout, (
        "Le script a commencé à modifier le système avant de vérifier la version."
    )


def test_le_script_est_syntaxiquement_valide() -> None:
    """Une erreur de syntaxe ne se découvrirait qu'en pleine reconstruction du serveur."""
    execution = subprocess.run(
        ["bash", "-n", str(PROVISION)], capture_output=True, text=True, check=False
    )

    assert execution.returncode == 0, execution.stderr
