# Tests d'invariants et qualité des données

Complète la CI/CD, le TDD et le mutation testing déjà en place (voir [`CLAUDE.md`](../CLAUDE.md) §3).

**Le problème que ce document adresse** : sur un pipeline statistique, une régression est *silencieuse*. Un coefficient qui dérive de 15 % ne lève aucune exception — il produit un chiffre faux dans un PDF remis à un client. Le TDD classique ne l'attrape pas, la couverture de ligne encore moins.

Aggravant : le batch ne tourne que deux fois par an. Entre deux exécutions, le contexte est oublié et une anomalie passe sans que personne la remarque avant qu'un artisan l'affiche.

Epic de suivi : [#118](../../../issues/118).

---

## 1. Tester des propriétés, pas des valeurs

**À proscrire** : `assert coefficient_C_vs_E == 0.1543`

La valeur bouge à chaque ingestion. Un test comme celui-là est rouge à chaque millésime, on finit par le mettre à jour mécaniquement — et il ne garantit plus rien.

**À écrire** : des invariants qui restent vrais après n'importe quel batch.

### INV-1 — Monotonie de l'échelle DPE — [#119](../../../issues/119)

À surface, terrain et commune contrôlés, l'ordre des coefficients doit être respecté :

```
coef(A) > coef(B) > coef(C) > coef(D) > coef(E) > coef(F) > coef(G)
```

**Tolérance** : une inversion est admise entre deux classes adjacentes **si** l'effectif de la classe la moins fournie est sous 30. Au-delà, c'est une anomalie à remonter.

> Observé en pratique : F et G s'inversent régulièrement sur petits échantillons. C'est un signal de faiblesse statistique, pas un bug — mais il doit être détecté et tracé.

### INV-2 — Signe et ordre de grandeur — [#120](../../../issues/120)

> Le modèle est **log-prix** depuis [#115](../../../issues/115) : les coefficients sont
> des **effets en log** (gain % = `exp(coef) − 1`), pas des montants €. Les bornes
> ci-dessous sont exprimées en log, comme dans le code (`config.gain_dpe_ec_max`,
> `invariants.evaluer_signe_ordre_grandeur`) et le référentiel moteur §11.

- Le gain E→C (`coef_C − coef_E`) est strictement positif
- Le gain E→C reste dans une plage plausible : **> 0 et ≤ `gain_dpe_ec_max` (défaut 0,70 en log)**
- Le gain E→D est **inférieur** au gain E→C (effet de seuil)
- L'élasticité du bâti (`coef_m2_bati`, log-log) est comprise entre **0,30 et 1,50**
- L'élasticité du terrain (`coef_m2_terrain`) est positive et **inférieure à celle du bâti**

### INV-3 — Cohérence géographique — [#121](../../../issues/121)

- Le rayon retenu ne dépasse jamais 50 km (hors repli départemental explicite)
- Le rayon retenu est croissant quand l'effectif communal décroît
- Deux communes limitrophes ne présentent pas d'écart de coefficient supérieur à un facteur 2 (sinon : artefact de bord probable, cf. franchissement de frontières §3.2 du MVP)

### INV-4 — Qualité du modèle — [#122](../../../issues/122)

- Erreur médiane de validation croisée **< 20 %** par maille
- R² > 0,30 sur toute maille publiée
- Aucune maille publiée avec n < 30

### INV-5 — Reproductibilité — [#123](../../../issues/123)

Deux exécutions du batch sur le même jeu de données figé produisent **exactement** les mêmes coefficients. Toute source d'aléa (échantillonnage de la validation croisée) doit être seedée.

---

## 2. Assertions sur les données ingérées

Le pipeline lit des fichiers publics qui **changent de format sans préavis**. Un test sur le code ne détecte pas une colonne DVF renommée ni un taux d'appariement qui s'effondre.

Ces assertions tournent en fin d'ingestion, **avant** le calcul des coefficients, et bloquent la publication.

### DAT-1 — Schéma — [#124](../../../issues/124)

- Toutes les colonnes attendues sont présentes et typées
- Échec explicite avec le nom de la colonne manquante, pas un `KeyError` à 200 lignes de là
- Les valeurs du champ `periode_construction` appartiennent au vocabulaire connu ; toute valeur nouvelle est signalée

**Livré.** Le schéma attendu de chaque source est déclaré **versionné en un seul
endroit** — `schema_source.py` : `COLONNES_DVF`, `COLONNES_DPE`, `COLONNES_COMMUNE`
(les colonnes que les parseurs lisent par `.get()`, autrement dispersées et
silencieuses) et `VOCABULAIRE_PERIODE_CONSTRUCTION` (les tranches ADEME connues,
plutôt qu'inférées du jeu courant). La porte s'exécute **au bord de l'ingestion**,
au moment où la source est lue :

- **DVF** (`dvf_source.fetch_mutations`) : l'en-tête du CSV geo-dvf fait foi ;
- **DPE ADEME** (`dpe_source.fetch_dpe_records`) : les noms de champs bruts, jugés
  **avant** la normalisation qui masquerait un renommage ;
- **Référentiel communes** (`decoupage_source.fetch_communes_by_departement`) : jugé
  sur l'**union des clés** de toutes les communes — l'API omet les champs nuls (une
  commune sans EPCI), un champ n'est « manquant » que s'il l'est de **toutes**.

Une colonne renommée lève une `SchemaSourceError` **nommant la source et la colonne**,
au point de lecture. L'erreur est **systémique** (le format a changé pour tout le
département) : `orchestration.ingest_departement` ne l'avale **pas** dans son
`try/except` par-commune — elle interrompt l'ingestion, donc le calcul ne tourne pas
sur des données muettes (l'ancien millésime reste servi). Le vocabulaire
`periode_construction` est **signalé** (log, non bloquant) : une tranche nouvelle est
un simple niveau catégoriel de plus dans la régression (§4 du référentiel moteur), pas
une corruption du schéma. Les **contours** communaux (auxiliaires, INV-3, chargés en
mode résilient) n'ont pas de porte propre : le référentiel communes — même API — porte
déjà celle sur les champs du modèle.

Détail : [`MOTEUR-REGLES-CALCUL.md`](MOTEUR-REGLES-CALCUL.md) §3 (ingestion).

### DAT-2 — Volumétrie — [#125](../../../issues/125)

- Nombre de lignes par département dans une fourchette attendue (± 30 % par rapport au millésime précédent)
- Une variation au-delà déclenche une alerte, pas un échec — DVF peut légitimement bouger

### DAT-3 — Appariement DVF ↔ DPE — [#126](../../../issues/126)

- **Taux d'appariement ≥ 60 %** — seuil du J1 de la roadmap
- Chute de plus de 10 points par rapport au millésime précédent → blocage
- Taux journalisé par département

### DAT-4 — Distributions — [#127](../../../issues/127)

- Prix médian par département dans une fourchette plausible
- Surface bâtie médiane entre 60 et 160 m²
- Part de valeurs nulles ou aberrantes sur `surface_terrain` sous un seuil
- Répartition des étiquettes DPE cohérente avec la précédente ingestion

> Une réforme du DPE (celle de 2026 est en cours) déplacera cette distribution. C'est exactement le genre de changement que DAT-4 doit rendre visible plutôt que de laisser passer.

### DAT-5 — Fraîcheur — [#128](../../../issues/128)

- Date de mutation la plus récente < 12 mois
- Millésime des sources journalisé et affiché dans l'admin

---

## 3. Tests de non-régression sur coefficients

Complète le §9.13 du MVP (jeu de recette figé), en le formulant en écarts plutôt qu'en valeurs.

### REG-1 — Écart entre deux millésimes — [#129](../../../issues/129)

Après chaque batch, comparer les coefficients aux précédents :

| Écart constaté | Traitement |
|---|---|
| < 10 % | Normal |
| 10 – 25 % | Alerte, revue manuelle |
| > 25 % | **Blocage de publication** |

Le batch produit un rapport d'écarts, maille par maille, consultable dans l'admin avant validation.

### REG-2 — Publication manuelle — [#130](../../../issues/130)

Un nouveau millésime n'est **jamais** publié automatiquement. Il est calculé, comparé, puis activé explicitement depuis l'admin interne.

Cohérent avec §9.1 : les rapports déjà émis restent servis depuis leur instantané, sans être affectés.

**Livré.** Le batch écrit uniquement `data.coefficient_maille_historique` ; il ne touche plus la table vive, ni pour écrire une maille retenue, ni pour effacer une maille refusée — sans quoi un millésime calculé puis abandonné aurait déjà modifié ce que voient les clients. Les refus sont différés dans `data.maille_refusee` et ne prennent effet qu'à la publication.

Publier projette l'historique du millésime vers la table vive, **sauf** les mailles que REG-1 classe en blocage : elles gardent leur valeur précédente jusqu'à un déblocage unitaire, décidé après examen de la fiche de diagnostic de la maille. Abandonner n'a **aucun effet de bord** — l'archive reste, la table vive n'est pas touchée, seule la décision est enregistrée pour que le millésime cesse d'être proposé.

Débloquer une maille est refusé tant que le millésime lui-même n'est pas publié : une commune seule en N au milieu d'un parc en N-1 serait précisément l'état mixte que REG-2 évite.

La logique vit dans des fonctions SQL `SECURITY DEFINER` (migration 012) et non dans l'app, qui est en lecture seule sur le schéma `data` (décision D6) et ne reçoit que le droit de les invoquer. Écrans : `/millesimes`, `/millesimes/{n}/bloquees`, `/millesimes/{n}/mailles/{insee}`. Chaque décision est tracée au journal d'audit (#50).

Détail des règles moteur : [`MOTEUR-REGLES-CALCUL.md`](MOTEUR-REGLES-CALCUL.md) § REG-2.

### REG-3 — Cas de référence — [#56](../../../issues/56)

10 à 15 adresses figées réparties sur des mailles de qualité différente (solide, correcte, fragile, repli départemental), avec valeurs attendues **en fourchette** et non en valeur exacte.

---

## 4. Ce que le mutation testing ne couvrira pas

Le mutation testing est bien choisi sur ce projet : sur du calcul numérique, la couverture de ligne ment. Un test qui appelle la régression et vérifie que le résultat n'est pas nul passe au vert sans rien garantir — les mutants révèlent si les assertions mordent.

Mais il reste aveugle à trois choses :

| Angle mort | Couvert par |
|---|---|
| Données d'entrée modifiées à la source | DAT-1 à DAT-5 |
| Modèle statistiquement valide mais faux | INV-1 à INV-4 |
| Dérive progressive entre millésimes | REG-1 |

Ces trois familles ne sont pas des tests unitaires : elles s'exécutent **sur les données réelles, en fin de pipeline**, et leur sortie est un rapport, pas un binaire vert/rouge.

---

## 5. Où ça s'exécute

| Famille | Déclenchement | Effet en cas d'échec |
|---|---|---|
| TDD / mutation | CI, à chaque commit | Build rouge |
| DAT-* | Fin d'ingestion | Blocage du calcul |
| INV-* | Fin de calcul, par maille | Maille non publiée + alerte |
| REG-* | Avant publication | Blocage de la publication |

**Principe** : une maille qui échoue à un invariant n'est pas publiée, mais elle ne bloque pas les autres. Le mode dégradé départemental (§5.3 du MVP, [#22](../../../issues/22)) prend le relais sur la zone concernée.

---

## 6. Journalisation — [#131](../../../issues/131)

Chaque batch produit un rapport archivé, consultable dans l'admin :

- Millésime des sources, date d'exécution, durée
- Volumétrie et taux d'appariement par département
- Nombre de mailles calculées / publiées / rejetées, avec motif
- Écarts au millésime précédent (REG-1)
- Liste des invariants en échec

Ce rapport est la première chose à regarder six mois plus tard, quand le contexte est oublié.

**Implémentation (journalisation seule).** Une ligne par **exécution** de commande
est archivée dans `data.rapport_batch` (jamais écrasée : la comparaison entre
millésimes suppose de garder le précédent). Le batch tournant par département et en
deux temps (`ingest` puis `compute`, [CLAUDE.md §5](../CLAUDE.md)), la colonne `phase`
(`ingestion` | `calcul`) discrimine les deux jeux de métriques ; celles de l'autre
phase restent `NULL`. La **CLI possède l'horloge** : elle borne l'exécution
(`debut`/`fin`) autour de l'orchestration puis confie l'archivage au repository
(`db/rapport_batch_repository.py`). Le **taux d'appariement** (DAT-3) est dérivé des
ventes réellement insérées (`RapportIngestion.taux_appariement`). Les **mailles
publiées** ne sont pas ici : elles relèvent de la publication manuelle
(REG-2, `publication_millesime`), jointe à l'affichage. Ce rapport archive les
**métriques observées** ; les portes d'assertion **DAT-1 à DAT-5** — non encore
implémentées — l'**enrichiront** de leurs verdicts (seuils, blocages) sans que #131
en dépende. L'app lit `data.rapport_batch` en **SELECT seul** (contrat A/B).

---

## Ordre de traitement

**INV-5 ([#123](../../../issues/123)) est à traiter en premier.** Sans batch déterministe, REG-1 et REG-3 sont inexploitables : l'écart mesuré entre deux millésimes mélange dérive réelle et aléa de tirage, et les paliers 10 % / 25 % ne veulent rien dire.

## Points ouverts

- ~~**Borne haute du gain E→C** : #45 fixe le flag d'aberration à `> 100 000 €`, INV-2 retient `120 000 €`.~~ **Résolu ([#235](../../../issues/235))** : la borne € n'existe plus depuis la migration log ([#115](../../../issues/115)). Une **seule valeur, `0,70` en log**, fait foi, déclarée à sa juste place de part et d'autre du contrat `data`/`app` (lecture seule) : côté moteur `config.gain_dpe_ec_max` (**garde-fou dur** de publication, INV-2, surchargeable par `GAIN_DPE_EC_MAX`) ; côté admin `SeuilsSupervision::GAIN_EC_MAX_LOG_DEFAUT` (**seuil d'alerte éditable** de la supervision #45, même défaut par construction). Les deux ne sont volontairement pas la même constante — un invariant dur ne se règle pas depuis l'admin — mais partent de la même valeur.
- **Seuil `n = 30`** : défini par [#22](../../../issues/22) (bascule en mode dégradé) et repris par INV-4 (critère de publication). Même constante, une seule source.
