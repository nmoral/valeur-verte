"""Marqueurs territoriaux (#341, SEO Vague 2b) — modèle de données.

Un marqueur qualifie une commune **ou** un département par un attribut lisible
(``nom`` = 'climat', 'marche', 'densite', 'levier_renovation'… ; ``valeur`` =
'froid', 'tendu', 'rural', 'fort'…). Il alimente le bloc « Pourquoi ici ? ».

Deux familles, distinguées par ``type_marqueur`` :

- ``contextuel`` — issu d'un référentiel externe figé (climat, marché, densité…),
  il explique le *pourquoi* et n'est qu'une **hypothèse** côté restitution ;
- ``mesure`` — dérivé de nos données (``coefficient_maille``), c'est un **fait**.

Logique pure, hors-ligne : l'écriture en base vit dans
``db.marqueurs_repository``, l'ingestion des référentiels dans les modules source.
"""

from __future__ import annotations

from dataclasses import dataclass

TYPE_CONTEXTUEL = "contextuel"
TYPE_MESURE = "mesure"


@dataclass(frozen=True)
class Marqueur:
    """Un marqueur territorial, prêt à écrire dans ``data.marqueurs``.

    Portée **exclusive** : soit ``code_insee`` (commune), soit
    ``code_departement`` (département), jamais les deux ni aucun — invariant
    porté en base par un CHECK, et filtré à l'écriture (:func:`est_ecrivable`).
    """

    code_insee: str | None
    code_departement: str | None
    nom: str
    valeur: str
    type_marqueur: str
    source: str

    def est_ecrivable(self) -> bool:
        """Vrai si le marqueur est complet et de portée unique.

        Une entrée bancale (portée absente/ambiguë, ``nom``/``valeur`` vide) est
        ignorée plutôt que de faire échouer tout le lot d'ingestion.
        """
        une_seule_portee = (self.code_insee is None) != (self.code_departement is None)
        return (
            une_seule_portee
            and self.nom.strip() != ""
            and self.valeur.strip() != ""
            and self.type_marqueur.strip() != ""
            and self.source.strip() != ""
        )
