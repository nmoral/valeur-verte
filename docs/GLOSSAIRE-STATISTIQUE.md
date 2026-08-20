# Glossaire statistique du moteur

Définitions des grandeurs manipulées par le moteur de coefficients : ce qu'elles
mesurent, ce qu'elles valent aujourd'hui, et **ce qu'elles ne disent pas**.

Ce dernier point est le plus utile. Une grandeur mal interprétée conduit à une
décision produit fausse sans qu'aucun test ne s'en aperçoive — c'est déjà arrivé
deux fois : en confondant l'erreur de prédiction d'un bien avec l'incertitude du
coefficient publié, puis en lisant une bonne stabilité là où le chiffre ne
décrivait pas la commune.

Valeurs de référence : département 31, millésime 2024, maisons — relevé du
11 août 2026. Voir [`QUALITE-DONNEES.md`](QUALITE-DONNEES.md) pour les invariants
qui s'appuient sur ces grandeurs.

---

## 1. Ce que le produit publie

### Coefficient DPE

Écart de valeur associé à une classe énergétique, **relatif à la classe D**
(référence). Exprimé en pourcentage depuis le passage du modèle en logarithme.

> Toulouse : `E = −6 %`, `F = −15 %`. Une maison classée F se vend environ 15 %
> moins cher qu'une maison comparable classée D, à surface, terrain, période de
> construction et quartier équivalents.

**Ne dit pas** : le prix d'un bien. C'est un écart *toutes choses égales par
ailleurs*, pas une estimation.

**Pourquoi en pourcentage** : en euros, une même perte de valeur s'exprimait par
−2 114 à Toulouse et −65 820 à Muret. Deux nombres incomparables, que le widget
aurait pourtant affichés de la même façon.

### Gain C vs E

Différence entre deux coefficients — l'écart qu'un artisan met en avant après
rénovation. C'est **le seul chiffre réellement affiché au client**, donc celui
dont la stabilité importe le plus.

> ~15 % sur toute la Haute-Garonne, quelle que soit la commune observée.

### IC 95 % (intervalle de confiance à 95 %)

Fourchette d'incertitude du coefficient. **Calculée analytiquement** à partir de
l'écart-type d'échantillonnage de l'effet (`se_dpe`, fourchette `coef ± IC_Z·SE`, #260) —
**pas** par [bootstrap](#bootstrap). Sous pooling partiel, le SE est **postérieur**,
resserré par le shrinkage. `IC_Z = 1` par défaut, soit ±1 écart-type (~68 %) ; à défaut
de SE, repli sur l'heuristique `coef ± |coef|·(erreur_mediane/100)` (S29).

> Toulouse, gain C vs E : `[+8,5 % ; +17,9 %]` autour d'une médiane de +13,5 %.

**Ne dit pas** que la vraie valeur est au milieu. Une fourchette large signifie
que les données ne permettent pas de trancher plus finement — pas que le résultat
est faux.

---

## 2. Mesures de dispersion

### n (effectif)

Nombre de ventes de l'échantillon. Deux effectifs coexistent, à ne pas confondre :

- **n brut** — toutes les ventes retenues par le rayon ;
- **n éligible** — celles que le moteur exploite réellement : DPE renseigné,
  bâti antérieur à 1989, prix et surface valides.

> Environ **40 %** du brut est éligible (2 680 sur 6 713). Un seuil fixé sur le
> brut est donc deux fois et demie moins exigeant qu'il n'en a l'air.

### sd (écart-type, *standard deviation*)

Dispersion d'une grandeur autour de sa moyenne, dans **son unité**. Ici : l'écart-type
d'échantillonnage du coefficient (`SE`), calculé analytiquement (`√diag(σ̂²·(XᵀWX)⁻¹)`),
**postérieur** sous pooling partiel — pas par rééchantillonnage (voir [Bootstrap](#bootstrap)).

> Toulouse : `sd = 2,4 points` sur un gain C vs E de 13,5 %.

Règle utile : environ deux tiers des valeurs tombent à moins d'un écart-type de
la moyenne, 95 % à moins de deux.

### cv (coefficient de variation)

Écart-type rapporté à la valeur mesurée : `cv = sd / |médiane|`. **Sans unité**,
donc comparable entre mailles — contrairement à `sd`.

> Toulouse `0,18`, Colomiers `0,19`, maille en repli départemental `0,10`.

| `cv` | Lecture |
|---|---|
| < 0,20 | stable |
| 0,20 – 0,35 | exploitable, fourchette large |
| > 0,35 | trop instable pour être publié |

**Piège majeur** : `cv` mesure la **variance**, jamais le **biais**. Une maille
en repli départemental s'appuie sur tout le département : beaucoup de données,
donc un excellent `cv` — pour un chiffre qui ne décrit pas sa propre commune.
Le `cv` seul ne peut donc pas servir de critère de publication : il faut lui
adjoindre une condition de validité territoriale.

### Percentiles (p5, p50, p95)

Valeur en dessous de laquelle tombe X % des observations. `p50` est la médiane.

Préférés à la moyenne sur des données de prix, où quelques valeurs extrêmes
déplacent fortement la moyenne sans rien dire de la tendance.

---

## 3. Mesures de qualité du modèle

### R² (coefficient de détermination)

Part de la variance des prix expliquée par le modèle, entre 0 et 1.

> Moyenne par maille : **0,58**.

**Ne dit pas** que le modèle est juste. Un R² élevé peut accompagner un
coefficient DPE faux : l'essentiel de la variance est absorbé par la surface, le
terrain et la commune, et le coefficient DPE ne porte qu'une part résiduelle. Il
peut donc varier fortement sans que le R² bronche.

### Erreur médiane relative (MAPE médiane)

Écart médian, en pourcentage, entre prix prédit et prix réel :
`médiane(|prédit − réel| / réel)`.

Médiane plutôt que moyenne : une poignée de ventes atypiques suffirait à rendre
la moyenne ininterprétable.

> **23,1 %** par maille en production ; **17,5 %** en validation croisée à
> l'échelle du département.

Repères sectoriels : ~11 % sur un marché dense et homogène disposant de données
riches ; **12 à 18 % est un bon résultat en périurbain hétérogène**.

**Signal d'alarme** : nettement sous 10 % en périurbain, chercher une fuite avant
de se réjouir — voisinage réparti des deux côtés du pli, doublons de mutations,
ou variable dérivée du prix entrée dans le modèle.

**Ne dit pas** l'incertitude du coefficient publié : c'est l'erreur sur la
prédiction d'un bien individuel, une autre grandeur.

---

## 4. Méthodes de validation

### Validation croisée

L'échantillon est découpé en plis ; le modèle apprend sur les uns et se mesure
sur celui qu'il n'a pas vu. Le découpage détermine ce qui est mesuré.

| Découpage | Ce qu'il simule | Observé |
|---|---|---|
| **Aléatoire** | rien de réel — borne optimiste | 17,5 % |
| **Out-of-time** | prédire des ventes postérieures aux données | 17,2 % |
| **Out-of-area** | ouvrir une commune non couverte | 22,3 % |

L'**out-of-time** correspond au cas d'usage : chaque rapport prédit une valeur
postérieure à l'apprentissage.

L'**out-of-area** est le plus sévère et le plus instructif : retirer une commune
fait **doubler** l'erreur sur les grandes villes (Toulouse 32,4 %). Il mesure ce
qui attend une zone nouvellement ouverte — et ce que vaut réellement une maille
en repli départemental.

### Bootstrap

Rééchantillonnage **avec remise** : on tire au sort, dans l'échantillon, autant
de ventes qu'il en contient — certaines plusieurs fois, d'autres pas — et l'on
réestime. Répété 200 fois, cela donne la distribution du coefficient, donc son
écart-type et sa fourchette.

L'idée : à défaut de pouvoir observer d'autres marchés, on simule d'autres
échantillons du même marché.

> ⚠️ **Non utilisé par le moteur.** Un temps envisagé pour produire l'`IC`, il a été
> **remplacé** par le calcul **analytique** de l'écart-type de l'effet
> (`se_dpe = √diag(σ̂²·(XᵀWX)⁻¹)`, #249) et sa **variance postérieure** après pooling
> partiel (#260) — moins coûteux que 200 réestimations par maille et cohérent avec le
> shrinkage. L'entrée reste ici comme repère de vocabulaire, pas comme description du
> pipeline (voir [IC 95 %](#ic-95--intervalle-de-confiance-à-95-)).

### Leave-one-out

Calculer une grandeur pour une observation **en l'excluant** du calcul. Utilisé
pour l'indice de quartier : sans cette exclusion, l'indice contiendrait le prix
qu'il sert à expliquer, et le modèle s'auto-prédirait.

---

## 5. Termes du modèle

### Régression pondérée

Estimation par moindres carrés où chaque vente reçoit un poids : ici une
pondération gaussienne décroissant avec la distance au centre de la commune. Les
ventes proches pèsent davantage.

**σ (sigma)** — distance à laquelle le poids tombe à ~60 %. `10 km` en mode
local, `1 000 km` en repli départemental (toutes les ventes comptent alors
presque également).

### Élasticité

Coefficient d'une variable exprimée en logarithme, dans un modèle lui-même en
logarithme : **variation en % de la réponse pour +1 % de la variable**.

> Bâti `0,42`, terrain `0,051`. Doubler la surface bâtie augmente le prix
> d'environ 34 % (2^0,42), pas de 100 %.

Une élasticité inférieure à 1 traduit des **rendements décroissants** : une
maison de 200 m² ne vaut pas le double d'une de 100 m². C'est le régime normal en
immobilier — une valeur de 0,4 à 0,7 n'a rien d'anormal.

### Effet fixe

Variable indicatrice absorbant le niveau propre d'un groupe — commune, période de
construction, millésime. Elle évite d'attribuer à une autre variable ce qui
revient au groupe.

> Sans effet fixe de période, la classe DPE captait l'effet de l'ancienneté :
> 44 % des logements classés G datent d'avant 1948, contre 2 % des A.

### Variable omise

Variable absente du modèle mais corrélée à la fois à la réponse et à une variable
présente. Son effet est alors attribué à tort à cette dernière.

> Cas rencontré : sans contrôle de l'emplacement fin, les passoires thermiques
> toulousaines — concentrées dans l'hypercentre, le secteur le plus cher —
> ressortaient comme **plus** chères que les logements moyens. Le modèle mesurait
> le quartier en croyant mesurer l'étiquette.

C'est la principale menace sur la validité d'un coefficient, et elle ne produit
aucun symptôme technique : ni exception, ni test rouge, ni R² dégradé.

### Biais et variance

Deux erreurs de nature différente, qu'aucune mesure unique ne résume :

- **biais** — le modèle se trompe systématiquement, dans la même direction ;
- **variance** — le modèle est instable d'un échantillon à l'autre.

Élargir le rayon réduit la variance et peut augmenter le biais (on mélange des
marchés distincts). C'est l'arbitrage central du rayon adaptatif.

> Mesuré sur le 31 : élargir de 5 à 55 km **ne modifie pas** le coefficient
> (13,9 % → 15,4 %) mais divise l'écart-type par 1,5. Sur ce département, le
> compromis penche donc franchement du côté des grands rayons. Rien ne garantit
> qu'il en aille de même ailleurs : un département contrasté — littoral,
> montagne, frontière de marché — pourrait présenter un biais réel.

### Hétéroscédasticité

Erreur dont l'ampleur croît avec la valeur prédite : on se trompe de plus d'euros
sur une maison à 800 000 € que sur une à 150 000 €. Le passage au logarithme la
corrige, l'erreur devenant proportionnelle.

### Pooling partiel (*shrinkage*)

Tirer l'estimation d'un petit échantillon vers une estimation plus large, d'autant
plus fort que l'effectif local est faible. Une commune à 200 ventes garde son
estimation propre ; une commune à 18 ventes hérite largement du département.

Alternative au seuil binaire « publie / ne publie pas », qui perd toute
l'information des petites mailles. Non implémenté à ce jour.

---

## 6. Termes du produit

### Maille

Unité de calcul et de publication : une commune, identifiée par son code INSEE.
Les coefficients sont pré-calculés par maille, jamais à la volée.

### Rayon adaptatif

Le plus petit rayon autour du centre de la commune atteignant l'effectif minimal.
Paliers actuels : `10, 15, 20, 30, 50 km`.

> Le rayon **déborde largement la commune** : Toulouse retient 2 054 ventes à
> 10 km, bien au-delà de ses limites. Le coefficient d'une commune est donc
> largement partagé avec ses voisines.

### Mode local / repli départemental

- **local** — la commune dispose d'assez de ventes à proximité ;
- **repli départemental** — sinon, le coefficient est estimé sur tout le
  département, avec un bandeau d'alerte.

> 57 mailles sur 383 sont en repli. Leur `cv` est excellent, leur validité
> territoriale faible : c'est la configuration où l'out-of-area mesure ~30 %
> d'erreur.

### Millésime

Année du fichier DVF ingéré, non l'année de la transaction. Sert de clé de
rafraîchissement et d'effet fixe temporel.

### Refus

Une maille non publiée : trop peu de ventes, ou aucune classe DPE assez observée.
Mieux vaut ne rien afficher qu'un chiffre faux — le professionnel ne peut pas
amender un coefficient.

---

## 7. Ce que ces grandeurs ne remplacent pas

Aucune ne détecte une **variable omise**. Un modèle peut afficher un R² correct,
une erreur médiane conforme, un `cv` excellent, et publier un coefficient de
signe faux. C'est ce qui s'est produit avant l'introduction du contrôle
d'emplacement.

Seuls les [invariants métier](QUALITE-DONNEES.md) — monotonie de l'échelle DPE,
signe et ordre de grandeur — attrapent ce genre de défaut, parce qu'ils
confrontent le résultat à ce que la physique du bâtiment impose.
