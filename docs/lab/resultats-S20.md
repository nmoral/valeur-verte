# Résultat — S20 : sans restriction bâti ancien (< 1989)

`VV_MOTEUR_RESTREINDRE_BATI_ANCIEN=false`. Millésime 2024. Reste = défauts.
À comparer à [`resultats-baseline.md`](resultats-baseline.md).

| Commune | n (base → S20) | r² (base → S20) | err % (base → S20) | gain E→C (base → S20) |
|---|---|---|---|---|
| Toulouse (urbain) | 1162 → **1577** | 0,527 → **0,548** | 13,8 → 13,8 | +14,6 % → **+14,2 %** |
| Colomiers (périurbain) | 760 → **1223** | 0,477 → **0,521** | 14,7 → **13,7** | +18,8 % → **+17,5 %** |
| Cazères (rural 31) | REFUS → **71** | — → 0,558 | — → 24,8 | — → n/a¹ |
| Foix (rural 09) | 103 → **133** | 0,602 → 0,595 | 22,8 → 23,7 | +8,2 % → **+9,8 %** |
| Tarascon (petit n) | REFUS → **REFUS** | — | — | — |

¹ Cazères calcule mais ne publie pas *à la fois* C et E (l'une des deux reste < 15
ventes) → gain E→C indisponible pour cette maille.

## Lecture

1. **S20 débloque Cazères** (REFUS → n=71) : inclure le bâti récent densifie les
   classes DPE. C'était l'hypothèse, confirmée.
2. **+29 % à +61 % de ventes** partout : plus de données.
3. **r² en hausse** sur urbain/périurbain (Toulouse 0,527→0,548, Colomiers
   0,477→0,521), stable sur Foix. Erreur : Colomiers s'améliore (14,7→13,7),
   Toulouse stable, Foix et Cazères restent au-dessus de 20 % (mailles fragiles).
4. **Les gains E→C bougent peu** (±1,5 pt) : retirer la restriction **ne distord
   pas le signal** DPE — le modèle contrôle déjà l'époque via la période (S25).
   C'est l'argument clé en faveur de S20.
5. **Tarascon reste refusé** : même avec le bâti récent (n_local 37→43),
   **aucune classe DPE n'atteint 15**. Cause = faible appariement DPE sur le 09
   (DAT-3) + rareté rurale + seuil `MIN_CLASS_SAMPLE=15` (S21). S20 seul ne suffit
   pas ici.

## Verdict

**S20 = adopter (retirer la restriction).** Amélioration nette : plus de
couverture (une commune débloquée), plus de données, r² égal ou meilleur, et
surtout **gains E→C quasi inchangés** — aucune distorsion du signal.

## Leviers suivants identifiés

- **S21 / DAT-3** pour les communes rurales encore refusées (seuil par classe,
  appariement DPE sur le 09).
- Fragilité rurale (Foix, Cazères > 20 % d'erreur) : cible pour **S32**
  (écrêtage) et **S23** (σ adaptatif).
