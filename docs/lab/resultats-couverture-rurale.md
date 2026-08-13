# Couverture rurale — deux gates, deux leviers (pool 52 + voisins)

Sur les départements très peu denses du pool Grand Est, la couverture est
catastrophique : **52 → 2 %, 70 → 1 %, 88 → 3 %** des communes publiées, contre
44 % pour le 31 et 31 % pour le 51 (Marne, même région mais avec Reims). Objectif :
comprendre et corriger, sans dégrader le reste.

Base : dump `valeur_verte_2026-08-13_complet.dump` restauré en dev, millésime 2024,
segment maisons. Diagnostic rejoué commune par commune avec le moteur réel
(lecture seule, `scratchpad/diag_couverture.py` / `diag_variantes.py`).

## Ce que ce n'est PAS

- **Pas** un problème de pool/voisins : toutes les communes passent en mode
  **LOCAL** (le rayon 50 km atteint toujours ≥ 30 maisons en poolant les voisins).
  Le repli départemental `_meme_departement` (qui exclut les voisins) n'est jamais
  atteint.
- **Pas** un problème d'ingestion ni de timing : les 14 départements sont calculés
  dans le même batch, pool complet.

## Les deux gates qui bloquent

Motifs de refus, communes du 52 (426) :

| Motif | Communes | Part |
|---|---|---|
| Publiée | 7 | 2 % |
| **< 2 classes DPE à ≥ 20 maisons** (S21) | 222 | 52 % |
| **Erreur médiane CV > 20 %** (INV-4) | 197 | 46 % |

Les deux gates sont des seuils de **qualité de publication**, pas des manques de
donnée. Et les rejets « erreur » ratent **de peu** : 81 % des communes rejetées du
52 ont une erreur entre 20 et 30 %.

## Levier B — palier « estimation dégradée » (erreur ≤ 30 %)

Publier les mailles à erreur 20-30 % dans un tier distinct, signalé par un bandeau
+ l'IC déjà affiché. `degraded.py` modélise déjà un mode dégradé (l'alerte du mode
départemental) : l'ossature existe. Les mailles récupérées passent déjà signe et
monotonie — directionnellement saines, juste imprécises.

## Levier C — bandes DPE adaptatives

Le gate des classes tombe quand aucune paire de classes n'atteint 20 dans
l'échantillon local. On **fusionne gloutonnement les classes adjacentes
sous-effectif** jusqu'à ce que chaque bande atteigne 20, en préservant la
granularité maximale : une maille avec assez de G la garde seule ; là où E et F
manquent, on forme `{E,F}` ; là où F et G manquent, `{F,G}` ; etc. Le représentant
de chaque bande est le membre le plus proche de D, pour garder D comme référence.

C'est **adaptatif par maille** — pas un regroupement fixe qui écraserait la finesse
là où elle existe.

## Résultat (communes publiées)

| Dept | Communes | A. baseline | B. erreur 30 % | C. B + bandes adaptatives |
|---|---|---|---|---|
| 52 | 426 | 7 (2 %) | 133 (31 %) | **207 (49 %)** |
| 70 | 536 | 3 (1 %) | 160 (30 %) | **244 (46 %)** |
| 88 | 506 | 16 (3 %) | 130 (26 %) | **161 (32 %)** |
| 51 | 610 | 189 (31 %) | 320 (52 %) | **432 (71 %)** |

Le rural passe de **1-3 % à 32-49 %**, au niveau du 31. Les bandes ajoutent 15-19
points par-dessus le seuil dégradé. Toutes les mailles publiées passent signe +
monotonie.

## Généralisation Occitanie + qualité du signal (garde-fou n°3)

Rejoué sur l'Occitanie rurale (couverture @30 % + bandes, vs baseline) :

| Dept | Région | Baseline | @25 % | @28 % | @30 % |
|---|---|---|---|---|---|
| 52 | Grand Est | 2 % | 19 % | 38 % | 49 % |
| 70 | Grand Est | 1 % | 26 % | 40 % | 46 % |
| 88 | Grand Est | 3 % | 21 % | 28 % | 32 % |
| 09 | Occitanie | 15 % | 24 % | 27 % | 30 % |
| 32 | Occitanie | 9 % | 24 % | 35 % | 39 % |
| 65 | Occitanie | 31 % | 57 % | 64 % | 68 % |

Les leviers généralisent — pas un artefact régional. Mais l'**amplitude du signal
DPE** (écart de prix meilleure ↔ pire bande) varie fortement :

| Dept | Amplitude médiane | p10 |
|---|---|---|
| 52 / 88 | ~57 % / ~54 % | 33 % / 29 % |
| 70 / 65 | ~46 % | 21 % / 15 % |
| 32 | ~39 % | 14 % |
| **09** (Ariège) | **~27 %** | **12 %** |

Le Grand Est a un signal plus fort que l'Occitanie (plausible : climat plus froid).
Mais le **09 est faible** — p10 à 12 % : un dixième des mailles publiées ont un écart
de prix quasi nul sur toute l'échelle DPE. Monotones et de bon signe, mais
**économiquement plates**. D'où un **3ᵉ garde-fou : une amplitude DPE minimale** en
tier dégradé, sinon on publie du vide en zone à signal faible.

Note seuil : entre 28 % et 30 %, le gain de couverture est modeste (32 : 35→39 %)
mais l'erreur est nettement plus serrée à 28 %. Le **28 %** est un compromis
précision/couverture défendable ; 30 % maximise la couverture.

## Cascade géographique — le filet départemental

Constat de conception : `Restitution` (app) sait afficher un **repli départemental**
(rayon = null → Fragile, « au niveau départemental »), mais le pipeline ne le
**déclenche jamais** depuis un échec local (`n_local ≥ 30` → mode LOCAL, et un échec
LOCAL est un refus sec, sans cascade). Prototype : quand le local échoue, retomber
sur une régression **départementale** (sigma 1000 → une régression pour tout le
département), avec bandes + erreur ≤ 30 %.

Cascade à **trois crans** — LOCAL → EPCI → DÉPARTEMENT — chacun servant
l'estimation la plus fine que la donnée permet, avec deux ajouts validés :

- **Cran EPCI** : entre local et départemental (appartenance `codeEpci` via
  geo.api.gouv.fr). Une régression par EPCI, granularité intermédiaire.
- **Bandes anti-inversion** : après fit, on fusionne toute paire de bandes
  adjacentes dont le coef inverse (meilleure classe moins chère) et on re-fitte
  jusqu'à monotonie. Débloque le repli départemental de 70 et 09 (inversion B<C).

Couverture par cran (répartition indicative) :

| Dept | LOCAL* | EPCI | DÉPT | Hors zone | Couverture |
|---|---|---|---|---|---|
| 52 | 133 | 89 | 204 | 0 | **100 %** |
| 70 | 160 | 138 | 238 | 0 | **100 %** |
| 88 | 130 | 99 | 277 | 0 | **100 %** |
| 09 | 58 | 29 | 238 | 0 | **100 %** |
| 32 | 121 | 50 | 287 | 0 | **100 %** |
| 65 | 213 | 153 | 103 | 0 | **100 %** |

<sub>*LOCAL en **classes pleines** (choix de conception : pas de bandes au local). Les
bandes au local doubleraient presque le LOCAL (52 : 133 → 207) mais à 2 bandes pour
~90 % des communes gagnées — géographie fine au prix d'un DPE binaire. Écarté :
mieux vaut descendre à l'EPCI, qui garde un DPE plus fin grâce à un pool plus grand.</sub>

La cascade **ferme le trou à 100 %** partout. Les bandes anti-inversion rendent le
filet départemental **toujours valide** (70 et 09 passent désormais). Le cran EPCI
apporte une granularité proportionnelle à la densité : 65 (dense) → 33 % des communes
en EPCI ; 09 (très rural) → 9 %, le reste au département. Chacun reçoit **quelque
chose** (Fragile, bandeau), au niveau le plus fin que sa donnée autorise.

## Compromis à assumer (honnêteté produit)

- **Précision** : le tier dégradé sert des estimations à ±30 %. À signaler
  clairement (bandeau + IC), jamais confondu avec le tier standard ≤ 20 %.
- **Granularité DPE** : une bande `{E,F}` donne un coefficient commun à E et F — le
  pro ne voit pas de gain E↔F *à l'intérieur* d'une bande fusionnée. La restitution
  doit l'exprimer (afficher la bande, pas deux classes identiques).
- Ces deux dégradations ne concernent **que** les mailles qui, sinon, ne
  publieraient **rien**. Le tier standard est inchangé.

## Décision

Solution consolidée (couverture rurale → 100 %), quatre briques :

1. **Palier dégradé** : erreur ≤ 30 % (28 % = variante plus sûre), servi dans un
   tier distinct avec bandeau + IC. Ouvre le local (1-3 % → 30-68 %).
2. **Bandes DPE adaptatives — uniquement au cran EPCI et au-dessus** : fusion des
   classes adjacentes sous-effectif, puis fusion **anti-inversion** jusqu'à
   monotonie. **Le local reste en classes pleines** (granularité DPE maximale) : le
   banding est le prix d'une géographie plus grossière, jamais de la fine. Si le
   local manque de données par classe, on descend au cran EPCI (plus de données,
   DPE encore fin) plutôt que de publier un local à 2 bandes. Les bandes rendent
   aussi le filet départemental toujours monotone (débloquent 70/09).
3. **Cascade géographique** LOCAL (classes pleines) → EPCI (bandes) → DÉPARTEMENT
   (bandes) : chaque commune reçoit l'estimation la plus fine que sa donnée permet ;
   le départemental garantit **toujours quelque chose** (Fragile, « au niveau
   départemental »).
4. **Amplitude DPE = signal de fiabilité affiché, PAS un refus** : une amplitude
   faible (09) est une info réelle (« le DPE pèse peu ici »), à signaler via
   l'indicateur de fiabilité, pas à cacher.

Toutes ces dégradations ne concernent que les mailles hors tier standard ≤ 20 %,
qui reste inchangé et prioritaire dans la cascade.

Pistes d'implémentation (à cadrer, TDD) :

- `config` : seuil d'erreur du tier dégradé + drapeau porté par la maille.
- `batch.compute_maille` : quand le gate standard échoue, tenter bandes adaptatives
  et/ou tier dégradé, en marquant la maille.
- `coefficient_maille` : porter l'info « dégradé » + les bandes utilisées (contrat
  `data`→`app`).
- Restitution `app` : bandeau de confiance + rendu par bande.
