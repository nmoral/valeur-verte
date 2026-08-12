# Récapitulatif — configuration gagnante du moteur

Consolidation du bloc pool + bloc paramètres. Toutes les mesures sont faites en
local sur `vv_lab_nobornes` (7 départements ré-ingérés **sans bornes**, millésime
2024). Chaque option a été validée expérience par expérience (voir les
`resultats-*.md`).

## 1. La configuration gagnante

| Variable d'env | Valeur | Stratégie | Pourquoi | Détail |
|---|---|---|---|---|
| *(données ré-ingérées sans bornes)* | — | **S12** | Le plafond fixe (bâti 250 m², prix 900 k) censurait le haut de gamme et **sous-estimait le gain E→C de +3 à +6 pts** | [nobornes](resultats-nobornes.md) |
| `VV_MOTEUR_RESTREINDRE_BATI_ANCIEN` | `false` | **S20** | Un logement récent en E existe et doit compter ; retirer la restriction n'altère pas le signal | [S20](resultats-S20.md) |
| `VV_MOTEUR_ECRETAGE` | `residu` | **S32 V2** | Retire les biens au résidu pondéré aberrant (fort levier) ; aide dense **et** rural fragile | [S32-v2](resultats-S32-v2.md) |
| `VV_MOTEUR_SIGMA_MODE` | `densite` | **S23** | σ = distance à la 80ᵉ vente / diviseur : densité **continue**, distingue Paris de Toulouse là où le rayon plafonne | [S23-densite](resultats-S23-densite.md) |
| `VV_MOTEUR_SIGMA_DIVISEUR` | `1.0` | S23 | | |
| `VV_MOTEUR_SIGMA_MIN` | `3.0` | S23 | Plancher : protège le fragile d'un σ trop serré | |
| `VV_MOTEUR_EFFETS_FIXES_COMMUNE` | `false` | **S22** | Les indicatrices commune sur-ajustaient (r² gonflé, erreur CV pire) ; l'indice local + σ portent déjà la localité | [S22](resultats-S22.md) |

## 2. Comment je valide une option (les critères)

Ordre de priorité des métriques, et ce qu'elles disent :

1. **Erreur médiane de validation croisée (`errCV`) — LE juge.** Justesse
   **prédictive hors échantillon**, en %, défendable devant un client. C'est le
   seul critère de qualité fiable. Cible : **< 20 %** (INV-4).
2. **⚠️ r² — diagnostic, jamais juge.** Mesuré **dans** l'échantillon : un σ serré
   ou des indicatrices en trop le font monter par **sur-ajustement** sans améliorer
   la prédiction. Un r² qui monte pendant que `errCV` stagne = signal de
   sur-paramétrage, pas de progrès.
3. **Gain E→C (%) — la valeur produit.** Doit rester **positif et plausible**
   (~+5 à +30 %). Un gain négatif ou aberrant (ex. σ trop serré cassant Cazères à
   −6,5 %) disqualifie l'option, même si `errCV` est bon.
4. **Couverture** — nombre de mailles **calculées vs REFUS**, et **nombre de
   classes DPE publiées**. Une option qui débloque une commune ou publie une classe
   de plus élargit le service.
5. **Signes des coefficients** — `m²bâti` > 0, `m²terrain` > 0 et < m²bâti,
   **monotonie DPE** (A > B > … > G). Les violations tolérées sur classes rares /
   petits effectifs, remontées sinon (INV-1/2).
6. **d80 (densité)** — contexte : distingue une maille dense d'une maille rurale,
   explique le σ retenu et la fragilité attendue.

## 3. Récapitulatif complet — config gagnante (base sans bornes, 2024)

Par commune : densité, effectif, mode/rayon, **σ effectif**, r² (diagnostic),
**errCV** (juge), coefs €/m² (élasticités), **coef DPE par classe en %**
(`exp(coef)−1`), gain E→C, nb classes.

### Communes denses
```
Toulouse   d80=1.79km  n=1441 local/10km σ=3.0  r2=0.858  errCV=12.3%  m2bati=0.63 m2terr=0.05  gainE→C=+18.5%  7 classes
           DPE: A:+21% B:+10% C:+9% D:0% E:-8% F:-18% G:-12%
Colomiers  d80=1.26km  n=1165 local/10km σ=3.0  r2=0.791  errCV=12.4%  m2bati=0.61 m2terr=0.04  gainE→C=+20.8%  7 classes
           DPE: A:+19% B:+23% C:+10% D:0% E:-9% F:-10% G:-9%
```

### Communes rurales
```
Foix       d80=2.44km  n=142  local/10km σ=3.0  r2=0.795  errCV=16.8%  m2bati=0.26 m2terr=0.26  gainE→C=+7.4%   4 classes
           DPE: C:-5% D:0% E:-12% F:-9%
Cazères    d80=4.83km  n=94   local/10km σ=4.8  r2=0.353  errCV=20.9%  m2bati=0.35 m2terr=0.18  gainE→C=+16.8%  3 classes
           DPE: C:+21% D:0% E:+4%            ⚠️ E>D : monotonie cassée (maille fragile)
```

### Communes de montagne (grand rayon)
```
65160      d80=11.4km  n=162  local/15km σ=11.4 r2=0.621  errCV=18.3%  m2bati=0.37 m2terr=0.10  gainE→C=+32.7%  4 classes
           DPE: C:+14% D:0% E:-14% F:-19%
65481      d80=15.3km  n=93   local/20km σ=15.3 r2=0.581  errCV=21.3%  m2bati=0.31 m2terr=0.06  gainE→C=+23.0%  4 classes
           DPE: C:+27% D:0% E:+3% F:0%       ⚠️ E>D, F>E : monotonie cassée (maille isolée)
```

### Refus
```
Tarascon   d80=4.83km  REFUS — aucune classe DPE n'atteint 15 ventes (appariement DPE 09 trop faible, DAT-3)
```

## 4. Défauts → gagnante (à données constantes, sans bornes)

L'`errCV` (le juge) **baisse sur toutes les communes calculées** ; la couverture
augmente (Cazères passe de 2 à 3 classes, 65481 publie enfin un gain E→C) :

| Commune | errCV défauts → **gagnante** | gain E→C défauts → gagnante | classes |
|---|---|---|---|
| Toulouse | 14,6 → **12,3** | +18,9 → +18,5 % | 6 → 7 |
| Colomiers | 16,1 → **12,4** | +21,3 → +20,8 % | 6 → 7 |
| Cazères | 35,4 → **20,9** | n/a → +16,8 % | 2 → 3 |
| Foix | 22,1 → **16,8** | +14,1 → +7,4 % | 4 → 4 |
| 65160 | 18,7 → **18,3** | +29,9 → +32,7 % | 4 → 4 |
| 65481 | 22,5 → **21,3** | n/a → +23,0 % | 3 → 4 |

Le r² **baisse** sur le rural (Cazères 0,824 → 0,353) alors que l'`errCV`
s'améliore : c'est la suppression du sur-ajustement (S22), pas une perte — d'où
l'importance de juger sur l'`errCV`.

## 5. Le chemin complet (origine bornée → gagnante)

Point de départ = base **bornée** `vv_lab`, config par défaut (voir
[baseline](resultats-baseline.md)).

| Commune | errCV | gain E→C | r² | couverture |
|---|---|---|---|---|
| Toulouse | 13,8 → **12,3** | +14,6 → **+18,5 %** | 0,527 → 0,858 | — |
| Colomiers | 14,7 → **12,4** | +18,8 → **+20,8 %** | 0,477 → 0,791 | — |
| Cazères | **REFUS → calcule** | — → +16,8 % | — | débloquée |
| Foix | 22,8 → **16,8** | +8,2 → +7,4 % | 0,602 → 0,795 | — |

Le gain E→C monte de ~+4 pts (correction du biais des bornes, S12) et l'erreur
CV baisse partout ; deux communes-test refusées au départ calculent désormais.

## 6. Points ouverts (non clos par cette config)

- **Tarascon / DAT-3** : appariement DPE trop faible sur le 09 → aucune classe à
  15 ventes. Levier hors moteur (qualité de l'appariement), pas réglable ici.
- **Monotonie DPE sur mailles fragiles** (Cazères E>D, 65481 F>E) : les invariants
  INV-1 les signaleront ; leur `errCV` élevée est déjà le drapeau.
- **65481** : la seule maille où retirer les EF commune dégrade un peu — mailles
  ultra-isolées à traiter à part.
- **S24 (indice local)** : ablation non testée — reste à voir si σ densité le rend
  superflu (décale la position des coefs DPE, extraction à adapter).
- **Rappels de fond** : coefficients en log/% et non en € (unité des invariants),
  IC empirique heuristique (S29), pas d'historique des coefficients (S30 → REG-1),
  et le `log(exp)` du R² à simplifier (tâche de suivi).

> ⚠️ Cette config gagnante est documentée et pilotable par env, mais **les défauts
> du code restent inchangés**. La basculer en défaut est une décision à prendre une
> fois S24 tranché et les invariants (#118) en place.
