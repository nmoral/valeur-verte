# Distribution — config gagnante sur tout le département 31

Six communes disent *si* une config marche ; **498 disent à quelle fréquence elle
échoue**. C'est ce chiffre qui décide de ce qu'on peut publier. Config gagnante
(S20 + V2 + σ densité + sans EF commune + indice + `min_class_sample=20`),
`vv_lab_nobornes`, millésime 2024. Script : [`distribution_31.py`](distribution_31.py).

## Résultats — 498 communes du 31

```
calculées : 357 (72 %)      REFUS : 141 (28 %)
parmi les calculées :
  errCV < 20 %              : 253 (71 %)   → 29 % dépassent la barre d'erreur
  avec violation monotonie  : 154 (43 %)
  sans gain E→C (C ou E abs): 130 (36 %)
PUBLIABLES strict  (calc & err<20 & monotone) : 154 (31 % du total)
PUBLIABLES gain E→C (calc & err<20 & C>E)     : 171 (34 % du total)
errCV : médiane 16,5   p25 14,1   p75 21,0   p90 25,2   max 33,3
```

## Lecture

1. **La porte de publication EST la décision produit.** Selon le critère, le taux
   va de **31 %** (monotonie parfaite exigée) à **51 %** (erreur seule) du total.
   Une porte « monotonie parfaite » est intenable (elle tue 43 % des calculées).
2. **La stratégie E→C paie** : 34 % (E→C, C>E) > 31 % (monotone strict). En
   n'exigeant que le C>E du cœur de gamme au lieu de toute l'échelle, on récupère
   les mailles propres au milieu mais à inversion de queue (F/G de caractère,
   cf. [diagnostic F/G](diagnostic-inversion-FG-toulouse.md)).
3. **Monotonie 43 % = majorant.** Compte les inversions **tolérées** par INV-1
   (adjacentes, classe rare sous 30 ventes). On ne peut pas appliquer la tolérance
   aujourd'hui : **les effectifs par classe ne sont pas persistés** — prérequis à
   débloquer.
4. **Le 34 % est par NOMBRE de communes, pas par marché.** Les 28 % de REFUS et la
   plupart des err>20 sont de **petites communes rurales** peu peuplées. Les
   communes denses (où sont la population et les transactions) publient proprement.
   Pondéré par les transactions, le taux de couverture serait **bien plus haut** —
   à mesurer. Le reste relève du **mode dégradé départemental** (#22).

## Ce que ça impose pour publier

- **INV-1 avec tolérance** (pas une porte binaire) → **persister les effectifs par
  classe** (bloqué aujourd'hui).
- **Publication centrée E→C / cœur de gamme**, pas l'échelle DPE complète.
- **Mode dégradé départemental** pour la queue rurale (REFUS + err>20).
- Barre INV-4 (err < 20 %) et non-régression : à câbler dans le batch (#118).
