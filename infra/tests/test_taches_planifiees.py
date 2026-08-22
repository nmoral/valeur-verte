"""Invariants des tâches planifiées (`infra/cron/crontab` + service « cron »).

Quatre commandes de rétention et de relance sont codées et déployées ; c'est
l'ordonnanceur qui décide si elles tournent vraiment. Chaque test ci-dessous
encode une panne silencieuse : une tâche qui n'existe plus côté `app/`, un
horaire à six champs qui décale l'exécution d'un facteur soixante, un
ordonnanceur qui ne repart pas après un reboot, un crontab que personne ne
monte. Aucune de ces pannes ne se voit — elle se constate des mois plus tard,
sur des données qui auraient dû être effacées.
"""

from __future__ import annotations

import re
from typing import Any

from .conftest import APP_DOCKERFILE, APP_SOURCES, CRONTAB, INFRA_README, services

# Une entrée de crontab : cinq champs d'horaire puis la commande.
CHAMPS_HORAIRE = 5

# La commande console appelée par une tâche : « php bin/console <nom> … ».
COMMANDE_CONSOLE = re.compile(r"bin/console\s+(?P<nom>[\w:.-]+)")

# Nom déclaré par un attribut #[AsCommand(name: '…')] côté app/.
NOM_DECLARE = re.compile(r"AsCommand\(\s*\n?\s*name:\s*'(?P<nom>[^']+)'")


def _entrees(crontab: str) -> list[tuple[str, ...]]:
    """Lignes utiles du crontab, découpées en champs (commentaires et vides écartés)."""
    entrees = []
    for ligne in crontab.splitlines():
        nettoyee = ligne.strip()
        if not nettoyee or nettoyee.startswith("#"):
            continue
        entrees.append(tuple(nettoyee.split()))
    return entrees


def _commandes_planifiees(crontab: str) -> list[str]:
    """Noms des commandes console appelées par le crontab."""
    noms = []
    for entree in _entrees(crontab):
        trouvee = COMMANDE_CONSOLE.search(" ".join(entree))
        assert trouvee, f"L'entrée « {' '.join(entree)} » n'appelle aucune commande console."
        noms.append(trouvee.group("nom"))
    return noms


def _commandes_declarees() -> set[str]:
    """Noms de commandes déclarés par les attributs #[AsCommand] de `app/src`."""
    declarees = set()
    for source in APP_SOURCES.rglob("*.php"):
        for trouvee in NOM_DECLARE.finditer(source.read_text(encoding="utf-8")):
            declarees.add(trouvee.group("nom"))
    return declarees


def _service_cron(compose_prod: dict[str, Any]) -> dict[str, Any]:
    declares = services(compose_prod)
    assert "cron" in declares, (
        "Le compose de production doit déclarer un service « cron » : sans ordonnanceur, "
        "les commandes de rétention et de relance ne tournent jamais."
    )
    return declares["cron"]


def test_le_crontab_declare_au_moins_une_tache(crontab: str) -> None:
    """Un crontab vide démarre sans broncher et ne fait rien : le dire tout de suite."""
    assert _entrees(crontab), "Le crontab ne planifie aucune tâche."


def test_chaque_tache_planifiee_appelle_une_commande_qui_existe(crontab: str) -> None:
    """Une commande renommée côté `app/` laisse le crontab pointer dans le vide : la
    tâche échoue chaque nuit dans les journaux d'un conteneur que personne ne lit."""
    declarees = _commandes_declarees()
    for nom in _commandes_planifiees(crontab):
        assert nom in declarees, (
            f"La tâche planifiée « {nom} » ne correspond à aucune commande déclarée "
            "dans app/src (#[AsCommand]) : elle échouerait à chaque exécution."
        )


def test_chaque_tache_planifiee_tourne_sans_interaction(crontab: str) -> None:
    """Une commande qui pose une question sans terminal attend indéfiniment : le
    conteneur reste occupé et l'exécution suivante est refusée (non-recouvrement)."""
    for entree in _entrees(crontab):
        commande = " ".join(entree)
        assert "--no-interaction" in commande, (
            f"La tâche « {commande} » doit passer --no-interaction : sans terminal, "
            "une question resterait sans réponse."
        )


def test_les_horaires_tiennent_en_cinq_champs(crontab: str) -> None:
    """Supercronic accepte aussi les expressions à six champs (secondes en tête) :
    un champ de trop et « 0 3 * * * » devient une exécution toutes les minutes."""
    for entree in _entrees(crontab):
        horaire = entree[:CHAMPS_HORAIRE]
        assert len(entree) > CHAMPS_HORAIRE, (
            f"L'entrée « {' '.join(entree)} » n'a pas de commande après ses champs d'horaire."
        )
        assert entree[CHAMPS_HORAIRE].startswith("php"), (
            f"L'entrée « {' '.join(entree)} » n'a pas exactement cinq champs d'horaire "
            f"(lus : {' '.join(horaire)}) ; six champs décaleraient l'exécution d'un facteur 60."
        )


def test_les_taches_ne_demarrent_pas_toutes_a_la_meme_heure(crontab: str) -> None:
    """Le VPS est modeste et la base est partagée : quatre balayages simultanés se
    disputent le même CPU et brouillent la lecture des journaux."""
    horaires = [entree[:CHAMPS_HORAIRE] for entree in _entrees(crontab)]
    doublons = {horaire for horaire in horaires if horaires.count(horaire) > 1}
    assert not doublons, (
        f"Plusieurs tâches partagent le même horaire : {sorted(' '.join(h) for h in doublons)}. "
        "Les décaler de quelques minutes."
    )


def test_chaque_tache_planifiee_est_documentee(crontab: str) -> None:
    """Le registre (#190) et la doc d'exploitation ne valent que s'ils sont à jour :
    une tâche ajoutée en silence est une tâche que personne ne saura relancer."""
    documentation = INFRA_README.read_text(encoding="utf-8")
    for nom in _commandes_planifiees(crontab):
        assert nom in documentation, (
            f"La tâche « {nom} » n'est pas documentée dans infra/README.md."
        )


def test_l_ordonnanceur_tourne_l_image_de_l_application(compose_prod: dict[str, Any]) -> None:
    """Les tâches sont des commandes Symfony : elles n'existent que dans l'image `app`."""
    cron = _service_cron(compose_prod)
    app = services(compose_prod)["app"]
    assert cron.get("image") == app.get("image"), (
        "Le service « cron » doit tourner exactement l'image de l'app (même tag), "
        "sinon il exécute les commandes d'une autre version du code."
    )


def test_l_ordonnanceur_partage_l_environnement_de_l_application(
    compose_prod: dict[str, Any],
) -> None:
    """Sans DATABASE_URL la purge ne joint pas la base ; sans BREVO_API_KEY la relance
    d'essai part en 401 ; sans DEFAULT_URI ses liens pointent http://localhost."""
    cron = _service_cron(compose_prod)
    app = services(compose_prod)["app"]
    environnement: object = cron.get("environment", {})
    assert isinstance(environnement, dict), "« environment » de cron doit être un dictionnaire."
    environnement_app: object = app.get("environment", {})
    assert isinstance(environnement_app, dict)
    for variable in ("DATABASE_URL", "APP_SECRET", "BREVO_API_KEY", "DEFAULT_URI", "APP_ENV"):
        assert environnement.get(variable) == environnement_app.get(variable), (
            f"Le service « cron » doit porter la même valeur que l'app pour {variable}."
        )


def test_l_ordonnanceur_repart_apres_un_redemarrage_du_vps(compose_prod: dict[str, Any]) -> None:
    """Un reboot OVH qui laisse l'ordonnanceur à l'arrêt suspend la rétention (D10)
    sans qu'aucune page ne tombe : la panne est invisible jusqu'à l'audit."""
    cron = _service_cron(compose_prod)
    assert cron.get("restart") in {"unless-stopped", "always"}, (
        "Le service « cron » doit redémarrer automatiquement après un reboot."
    )


def test_l_ordonnanceur_attend_une_base_prete(compose_prod: dict[str, Any]) -> None:
    """Démarré avant la base, il enchaînerait ses premières tâches sur une connexion morte."""
    cron = _service_cron(compose_prod)
    dependances: object = cron.get("depends_on", {})
    assert isinstance(dependances, dict) and "db" in dependances, (
        "Le service « cron » doit dépendre de « db »."
    )
    assert dependances["db"].get("condition") == "service_healthy", (
        "Le service « cron » doit attendre que la base soit saine (service_healthy)."
    )


def test_l_ordonnanceur_lit_le_crontab_du_depot(compose_prod: dict[str, Any]) -> None:
    """Le crontab monté et celui passé à l'ordonnanceur doivent être le même fichier :
    une divergence de chemin le fait démarrer sur un crontab vide, sans erreur."""
    cron = _service_cron(compose_prod)
    montages = [str(volume) for volume in cron.get("volumes", [])]
    source = f"./{CRONTAB.parent.name}/{CRONTAB.name}"
    monte = [montage for montage in montages if montage.startswith(f"{source}:")]
    assert monte, (
        f"Le service « cron » doit monter le crontab versionné ({source}), "
        f"reçu : {montages or 'aucun montage'}."
    )
    cible = monte[0].split(":")[1]
    commande = cron.get("command", [])
    assert isinstance(commande, list), "La commande du service « cron » doit être une liste."
    assert cible in commande, (
        f"L'ordonnanceur doit lire le crontab monté ({cible}), commande reçue : {commande}."
    )
    assert monte[0].endswith(":ro"), "Le crontab est monté en lecture seule."


def test_l_ordonnanceur_ne_se_publie_sur_aucun_port(compose_prod: dict[str, Any]) -> None:
    """Il ne sert rien : tout port publié serait une surface d'attaque gratuite."""
    cron = _service_cron(compose_prod)
    assert not cron.get("ports"), "Le service « cron » ne publie aucun port."


def test_l_ordonnanceur_est_epingle_et_verifie_dans_l_image() -> None:
    """Un binaire tiré du réseau sans version épinglée ni somme de contrôle fait
    dépendre l'image de ce que sert GitHub le jour du build : une image de
    production n'est reproductible qu'à ce prix."""
    dockerfile = APP_DOCKERFILE.read_text(encoding="utf-8")
    assert "supercronic" in dockerfile, (
        "L'image app doit embarquer l'ordonnanceur : c'est elle que le service « cron » lance."
    )
    assert "supercronic/releases/download/" in dockerfile, (
        "L'ordonnanceur doit être tiré d'une release publiée, pas d'une source mouvante."
    )
    assert re.search(r"\bv\d+\.\d+\.\d+\b", dockerfile), (
        "La version de l'ordonnanceur doit être épinglée (v0.0.0), jamais « latest »."
    )
    assert re.search(r"sha256sum\s+(-c|--check)", dockerfile), (
        "Le binaire téléchargé doit être vérifié par une somme de contrôle."
    )
