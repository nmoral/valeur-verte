# Lab — expériences sur les stratégies de calcul

Espace d'expérimentation pour départager les stratégies recensées dans
[`../REVUE-STRATEGIES-CALCUL.md`](../REVUE-STRATEGIES-CALCUL.md). Chaque variante
est calculée **en local** sur un dump figé, sur les mêmes communes-test, et son
tableau de résultats est archivé ici (`resultats-<variante>.md`).

## Principe

- Base locale figée `vv_lab` (restaurée depuis `dumps/valeur_verte_2026-08-11_complet.dump`).
- Le runner calcule **en mémoire** (aucune écriture en base) → pas de dump par
  expérience : l'artefact réutilisable est le **tableau de résultats**.
- La config vient de l'environnement (`VV_MOTEUR_*`) : une variante = mêmes
  communes, config différente. Aucun changement de code pour les constantes.

## Communes-test

| Profil | Commune | INSEE |
|---|---|---|
| Urbain dense | Toulouse | `31555` |
| Périurbain | Colomiers | `31149` |
| Rural 31 | Cazères | `31135` |
| Rural Ariège | Foix | `09122` |
| Petit n / repli | Tarascon-sur-Ariège | `09306` |

## Lancer une variante

```bash
cd data && source .venv/bin/activate
export DATABASE_URL="postgresql://valeur_verte:valeur_verte@localhost:5432/vv_lab"
# Baseline (défauts) :
python ../docs/lab/runner.py
# Variante (ex. S20 — sans restriction bâti ancien) :
VV_MOTEUR_RESTREINDRE_BATI_ANCIEN=false python ../docs/lab/runner.py
```

Métriques comparées : `n`, `mode`, `rayon`, `r²`, `erreur médiane %`, `coef m²
bâti`, `coef m² terrain`, et le **gain E→C en %** (`exp(coef_C − coef_E) − 1`).
En cas de refus, le runner imprime la cause (n éligible local/départemental, mode,
classes publiables).

## Récapitulatif

- [`RECAP.md`](RECAP.md) — **config gagnante consolidée** + toutes les métriques de validation. Rejouable via `recap.py`.

## Résultats archivés

- [`resultats-baseline.md`](resultats-baseline.md) — config par défaut, référence.
- [`resultats-S20.md`](resultats-S20.md) — sans restriction bâti ancien (< 1989).
- [`resultats-S32-v1.md`](resultats-S32-v1.md) — écrêtage prix/m² (V1), sur base S20.
- [`resultats-nobornes.md`](resultats-nobornes.md) — S12 : base ré-ingérée sans bornes, re-validation baseline/S20/S32.
- [`resultats-S32-v2.md`](resultats-S32-v2.md) — écrêtage résidu pondéré (V2), sur base sans bornes.
- [`resultats-S23.md`](resultats-S23.md) — σ adaptatif au rayon retenu ; diviseur 2 sûr, 3 casse le fragile.
- [`resultats-S23-densite.md`](resultats-S23-densite.md) — σ piloté par la densité continue (d80) ; améliore l'erreur CV partout.
- [`resultats-S22.md`](resultats-S22.md) — retirer les effets fixes commune (sur-paramétrage) ; répare le rural fragile.
- [`resultats-S24.md`](resultats-S24.md) — indice local : ablation → à garder (signal réel, pas redondant).
- [`resultats-bornee-vs-nobornes.md`](resultats-bornee-vs-nobornes.md) — config gagnante sur base bornée vs sans bornes ; l'erreur plus basse en bornée est un artefact de censure.
- [`diagnostic-inversion-FG-toulouse.md`](diagnostic-inversion-FG-toulouse.md) — l'inversion F/G de Toulouse est réelle (vieux bâti de caractère).
- [`diagnostic-115-type-et-rural.md`](diagnostic-115-type-et-rural.md) — #115 : le pool 52 (rural) prouve que le modèle **maisons** est monotone hors grandes villes ; l'inversion G est circonscrite aux cœurs urbains anciens. Rappel : la segmentation maisons-seules (SEGMENT_MVP) est déjà en place — ce n'est donc pas le levier du résidu.
- [`resultats-couverture-rurale.md`](resultats-couverture-rurale.md) — couverture rurale bloquée par 2 gates (erreur médiane > 20 %, < 2 classes DPE à ≥ 20). Deux leviers chiffrés : **palier dégradé erreur ≤ 30 %** + **bandes DPE adaptatives** → rural de **1-3 % à 32-49 %** de communes publiées.
- [`distribution-31.md`](distribution-31.md) — **config gagnante sur les 498 communes du 31** : taux calc/err/monotonie/publiable. Le chiffre qui décide.
