"""Départements limitrophes, pour que le pool ne s'arrête pas à la frontière (#18, #91).

Une commune du bord du 31 n'a de voisines que d'un côté : son rayon adaptatif
balaie un demi-disque, et le moteur calcule sur un échantillon amputé sans
qu'aucun indicateur ne le signale — n, rayon et IC restent d'apparence normale.
Charger les départements limitrophes rend le disque entier.

La table est une **vérité géographique** : elle décrit les frontières réelles,
pas ce qui se trouve ingéré. Un voisin absent de la base ne contribue à rien et
ne gêne pas ; le jour où il est ingéré, il compte sans changement de code.

Depuis l'import France entière (#223), la table couvre **toute la métropole**
(96 départements, Corse 2A/2B comprise) : une commune de bord déborde sur son
vrai voisinage où qu'elle soit, plus seulement dans les départements pilotes.
"""

from __future__ import annotations

from valeur_verte_data.limitrophes import LIMITROPHES, limitrophes


def _metropole() -> frozenset[str]:
    """Les 96 départements métropolitains : 01–95 sauf le 20, Corse en 2A/2B."""
    numeriques = {f"{n:02d}" for n in range(1, 96)} - {"20"}
    return frozenset(numeriques | {"2A", "2B"})


def test_toute_la_metropole_deborde_sur_ses_voisins() -> None:
    """#223 — chaque département métropolitain a au moins un voisin décrit.

    Avant l'import France, seuls 14 départements pilotes figuraient ; les ~82
    autres renvoyaient vide et calculaient sans débordement au bord.
    """
    for departement in _metropole():
        assert limitrophes(departement), f"{departement} n'a aucun voisin décrit"


def test_la_table_ne_couvre_que_la_metropole() -> None:
    """Pas de clé parasite : la table décrit exactement les 96 départements."""
    assert set(LIMITROPHES) == _metropole()


def test_la_haute_garonne_connait_ses_six_voisins() -> None:
    """Le 31 est le département pilote : ses frontières conditionnent le MVP."""
    assert limitrophes("31") == ("09", "11", "32", "65", "81", "82")


def test_la_haute_marne_connait_ses_six_voisins() -> None:
    """Extension hors Occitanie (#71) : le 52 et sa région, rural dense/creux."""
    assert limitrophes("52") == ("10", "21", "51", "55", "70", "88")


def test_paris_borde_la_petite_couronne() -> None:
    """Cas urbain dense : Paris ne touche que les trois de la petite couronne."""
    assert limitrophes("75") == ("92", "93", "94")


def test_le_finistere_borde_ses_deux_voisins_bretons() -> None:
    """Cas de façade maritime : un cul-de-sac atlantique, deux voisins terrestres."""
    assert limitrophes("29") == ("22", "56")


def test_la_corse_est_une_ile_a_deux_departements() -> None:
    """Insularité : 2A et 2B ne se bordent que l'un l'autre, jamais le continent."""
    assert limitrophes("2A") == ("2B",)
    assert limitrophes("2B") == ("2A",)


def test_l_adjacence_est_symetrique() -> None:
    """Une frontière déclarée d'un seul côté est une faute de frappe.

    La table étant désormais complète, tout voisin cité est lui-même une clé et
    doit déclarer la réciproque : plus aucune moitié de frontière n'est tolérée.
    """
    for departement, voisins in LIMITROPHES.items():
        for voisin in voisins:
            assert voisin in LIMITROPHES, (
                f"{departement} cite {voisin}, absent de la table"
            )
            assert departement in LIMITROPHES[voisin], (
                f"{departement} déclare {voisin} comme voisin, "
                f"mais {voisin} ne déclare pas {departement}"
            )


def test_un_departement_n_est_jamais_son_propre_voisin() -> None:
    """Il serait sinon chargé deux fois, et ses ventes compteraient double."""
    for departement, voisins in LIMITROPHES.items():
        assert departement not in voisins


def test_un_departement_hors_metropole_n_a_pas_de_voisin_connu() -> None:
    """Hors périmètre (DROM, code inconnu) : on calcule sans limitrophes, pas d'exception."""
    assert limitrophes("971") == ()
    assert limitrophes("99") == ()
