"""Le second facteur ne doit pas voyager dans un dump restauré ailleurs (#312).

Le secret TOTP est stocké **en clair** — il est rejoué, pas comparé : le serveur
recalcule le code qu'affiche le téléphone, et le hacher le rendrait inutilisable.
Conséquence directe : un dump de production restauré sur un poste de
développement y dépose le secret de l'admin de prod. Le porteur du dump n'a pas
le mot de passe, mais il détient la moitié du second facteur — et un second
facteur à moitié connu n'est plus qu'un facteur.

D'où un script de purge, et ces tests qui vérifient qu'il existe, qu'il couvre
les trois tables concernées, et qu'il est documenté là où on restaure.
"""

from __future__ import annotations

from .conftest import INFRA_README, REPO_ROOT

PURGE = REPO_ROOT / "infra" / "purger-second-facteur.sh"

TABLES_A_PURGER = (
    "second_facteur",
    "code_de_secours",
    "jeton_enrolement_second_facteur",
)


def test_le_script_de_purge_existe_et_est_executable() -> None:
    assert PURGE.exists(), "infra/purger-second-facteur.sh est absent."
    assert PURGE.stat().st_mode & 0o111, "Le script de purge doit être exécutable."


def test_la_purge_couvre_les_trois_tables_du_second_facteur() -> None:
    """Oublier une table, c'est laisser passer soit le secret, soit une porte."""
    contenu = PURGE.read_text(encoding="utf-8")
    for table in TABLES_A_PURGER:
        assert table in contenu, (
            f"La purge ne mentionne pas « {table} » : le dump restauré la garderait."
        )


def test_la_purge_refuse_de_tourner_sur_la_base_de_production() -> None:
    """Purger la prod, ce serait verrouiller l'admin dehors — l'inverse du but."""
    contenu = PURGE.read_text(encoding="utf-8")
    assert "VV_PURGE_CONFIRME" in contenu, (
        "Le script doit exiger une confirmation explicite avant d'effacer."
    )


def test_la_purge_est_documentee_ou_l_on_restaure() -> None:
    contenu = INFRA_README.read_text(encoding="utf-8")
    assert "purger-second-facteur.sh" in contenu, (
        "Un script que personne ne connaît ne sera pas joué : documenter la purge "
        "dans la section de restauration."
    )
