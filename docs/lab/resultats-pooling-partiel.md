# Résultats — Pooling partiel hiérarchique (#249, lot 4 : calibration VPS)

> **Statut : EN COURS DE REMPLISSAGE.** Squelette + résultats déjà acquis (N=2025,
> France entière) ; la série multi-millésime 2022→2025 est en cours de calcul sur le
> VPS (job `pooling-millesimes.sh`, ~6 h). Les tableaux marqués ⏳ attendent ces chiffres.
> Livrable du **lot 4** de la note de conception
> [`conception-pooling-partiel.md`](conception-pooling-partiel.md) (§9 protocole, §10 lot 4) :
> décider **défaut vs opt-in** de `pooling_hierarchique`.

## 0. Question tranchée par ce document

Le pooling hiérarchique (empirical-Bayes entre communes, §4ter du référentiel) est
aujourd'hui **opt-in** (`pooling_hierarchique=False`, `VV_MOTEUR_POOLING_HIERARCHIQUE`).
On bascule le **défaut à `on`** **si et seulement si** les gains attendus sont nets
**sans** régression sur les garde-fous :

| Critère (protocole §9) | Sens attendu | Seuil de décision |
|---|---|---|
| (1) Stabilité rurale — variance / queue p90 du premium E→C | ↓ | net |
| (2) Biais / atténuation — premium des mailles **précises** | ~ inchangé | garde-fou (ne pas moyenniser une commune vraiment plus verte) |
| (3) Taux de publication au LOCAL | ↑ | net (gain principal) |
| (4) Erreur médiane CV | ne pas se dégrader | garde-fou |
| (5) Monotonie INV-1 | ↑ | net |
| (6) Diagnostic — distribution des `τ̂²` / poids `w` | cohérence métier | qualitatif |

**Bascule si (1)+(3)+(5) nets ET (2)+(4) non dégradés ; sinon garder opt-in** (comme τ #208).

---

## 1. Méthode

- **Données** : base France métropole 2021-2025 en prod (import France entière, #223),
  exclusion multi-communes #252 active partout.
- **Moteur** : fenêtre **W2** (défaut), pooling **off** (baseline) vs **on**. Config
  identique par ailleurs. Compute-only, archive `coefficient_maille_historique` (REG-2).
- **Comparaison off vs on** : baseline figée `data.hist_baseline_mc` (état AVANT :
  pré-#252 / pré-pooling) vs archive avec pooling. ⚠️ La baseline cumule **deux**
  changements (#252 + pooling) ; l'effet **propre** du pooling est mesuré sur le
  **sous-ensemble de communes communes** aux deux états (voir §2).
- **Série multi-millésime** : millésimes **2022, 2023, 2024, 2025** recalculés **tous en
  W2 + pooling** (`pooling_m_<N>.csv`), pour la dérive inter-année et la robustesse de la
  décision. 2021 écarté (W2 dégénère en W1 faute de 2020).

---

## 2. Effet propre du pooling — N=2025, France entière (ACQUIS)

Mesuré sur les **communes communes** baseline↔pooling (isole le pooling du filtre
multi-communes).

**Granularité — taux de publication au LOCAL (critère 3)** :

| Métrique | Sans pooling | Avec pooling | Δ |
|---|---|---|---|
| Mailles promues EPCI/DÉPT → **LOCAL** | — | **+3 043** | ~+9,3 % du parc |
| Mailles rétrogradées | — | 50 | négligeable |
| Part servie au cran **LOCAL** | ~50 % | **~60 %** | **+10 pts** |

→ **Critère (3) : NET.** Le shrinkage régularise assez de petites mailles bruitées pour
qu'elles passent les invariants au cran LOCAL au lieu de basculer EPCI/DÉPT.

**Réduction de variance — gain E→C log (critère 1)**, sur 26 697 mailles E&C communes :

| Métrique | Sans pooling | Avec pooling | Δ |
|---|---|---|---|
| Écart-type du gain E→C (log) | 0,0835 | 0,0664 | **−20 %** |
| Étendue p10–p90 du gain E→C (%) | [12,2 ; 38,3] | [13,3 ; 32,7] | **−26 %** |
| Médiane du gain E→C (%) | 23,2 | 22,6 | **stable** (pas de biais) |
| Gains E→C négatifs (INV-2) | 0 | 0 | tenu |

→ **Critère (1) : NET** (variance et queue ↓), **et critère (2) partiellement rassuré**
(médiane stable = pas d'atténuation moyenne du signal). Reste à confirmer (2) **sur les
mailles à forte précision** spécifiquement (w→1 doit les laisser ~inchangées).

**Critères encore à mesurer sur 2025** :
- (2) atténuation ciblée sur les mailles précises (w proche de 1) — ⏳
- (4) erreur médiane CV off vs on — ⏳
- (5) taux de monotonie INV-1 off vs on — ⏳
- (6) distribution des `τ̂²` / `w` — ⏳ (dépend de `poids_pooling`, voir §5)

---

## 3. Série multi-millésime 2022→2025 (⏳ job VPS en cours)

Tous les millésimes en **W2 + pooling**. Objectif : la décision ne doit pas dépendre d'un
millésime particulier, et la **dérive inter-année** du premium doit rester lisible sous
pooling (le pooling régularise entre communes, pas dans le temps — cf. caveat §11 conception).

**3.1 Premium & stabilité par millésime** (mailles B/C/E fiables, eff≥20) :

| Millésime | n mailles | E→C médian | C→B médian | E→B médian | écart-type E→C | part LOCAL |
|---|---|---|---|---|---|---|
| 2022 | ⏳ | ⏳ | ⏳ | ⏳ | ⏳ | ⏳ |
| 2023 | ⏳ | ⏳ | ⏳ | ⏳ | ⏳ | ⏳ |
| 2024 | ⏳ | ⏳ | ⏳ | ⏳ | ⏳ | ⏳ |
| 2025 | ⏳ | ⏳ | ⏳ | ⏳ | ⏳ | ⏳ |

**3.2 Dérive inter-année sur panel stable** (mêmes mailles fiables sur les 4 millésimes) :

- Dérive E→C 2022→2025 : ⏳ (attendu ~+15 %/an hors pooling, cf. #177)
- Dérive C→B 2022→2025 : ⏳
- Le pooling **aplatit-il** la dérive réelle du marché ? (à surveiller — double
  régularisation, §11 conception) : ⏳

---

## 4. Orientation « C ou B ? » — solidité du B sous pooling (⏳)

Contexte : la vérification C/B pré-pooling (millésime 2025, sans pooling) a établi que
**le gros du gain est atteint à C, le dernier cran vers B rapporte ~4× moins, partout**
(E→C médian +21,4 %, C→B +5,8 %, 86/86 dépts). Ce lot **rejoue ce constat sous pooling
et sur plusieurs millésimes** — le message publiable doit tenir sur la donnée régularisée.

**4.1 Solidité du B (couverture / précision)** :

| Millésime | % mailles B publiable | dépts avec B | effectif B médian | SE.B médian | SE.C médian |
|---|---|---|---|---|---|
| 2022 | ⏳ | ⏳ | ⏳ | ⏳ | ⏳ |
| 2023 | ⏳ | ⏳ | ⏳ | ⏳ | ⏳ |
| 2024 | ⏳ | ⏳ | ⏳ | ⏳ | ⏳ |
| 2025 | ⏳ | ⏳ | ⏳ | ⏳ | ⏳ |

**4.2 Universalité de l'écart C/B** (dépts avec ≥20 mailles B/C/E) :

| Millésime | dépts | C→B < E→C | C→B > 0 |
|---|---|---|---|
| 2022 | ⏳ | ⏳ | ⏳ |
| 2023 | ⏳ | ⏳ | ⏳ |
| 2024 | ⏳ | ⏳ | ⏳ |
| 2025 | ⏳ | ⏳ | ⏳ |

→ **Le message « C ou B ? » tient si** le ratio E→C / C→B reste > 1 et C→B < E→C sur
~tous les dépts, **sur tous les millésimes**. Confirmation ⏳.

---

## 5. Calibration du seuil du badge #261 (⏳ — dépend du déploiement)

Le badge « partiellement rapprochée du département » s'affiche quand
`poids_pooling < SEUIL_RAPPROCHEMENT` (constante **0,5** en dur, jamais calibrée). Le seuil
doit se caler sur la **distribution réelle** des `w` (min des poids par maille) : combien de
mailles porteraient le badge à 0,3 / 0,5 / 0,7 ?

⚠️ **Bloqué par un déploiement** : l'image data en prod (`395da292`) est **antérieure** au
lot data #261 (PR #274) — elle **ne produit pas `poids_pooling`**, et la migration 019
(colonne) n'est pas appliquée. Le job multi-millésime en cours tourne avec cette image :
il **ne fournit donc pas** la distribution des `w`. Cette calibration attend un recompute
avec l'image à jour (post-job, tâche « B » de déploiement #261).

| Seuil candidat | % mailles badgées (2025) | Interprétation |
|---|---|---|
| 0,3 | ⏳ | badge réservé au shrinkage très fort |
| 0,5 (actuel) | ⏳ | — |
| 0,7 | ⏳ | badge large |

---

## 6. Décision (à trancher une fois §3–§5 remplis)

- [ ] (1) stabilité rurale nette — **acquis 2025** (var −20 %, p90 −26 %), à confirmer sur la série
- [ ] (2) pas d'atténuation des mailles précises — à mesurer
- [ ] (3) publication LOCAL nette — **acquis 2025** (+10 pts, +3 043 mailles)
- [ ] (4) erreur CV non dégradée — à mesurer
- [ ] (5) monotonie INV-1 ↑ — à mesurer
- [ ] (6) diagnostic `τ̂²`/`w` cohérent — à mesurer

**Verdict provisoire** : les deux gains principaux (1) et (3) sont **acquis et nets** sur
2025 ; la bascule du défaut dépend de (2)+(4) (garde-fous) et de la robustesse
multi-millésime. À compléter.

---

*Provenance des chiffres 2025 : run `recompute-pooling.sh` du 2026-08-18, analyse
baseline `hist_baseline_mc` vs archive pooling (recompute multi-communes #252).*
