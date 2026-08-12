# Résultat — S24 : indice local (ablation) → à GARDER

Dernier mécanisme de localité : l'**indice local** = médiane du prix/m² des 20
plus proches voisines, en régresseur. Question : maintenant que σ suit la densité
(S23) et que les effets fixes commune sont retirés (S22), l'indice est-il superflu ?

Ablation sur `vv_lab_nobornes`, **config gagnante** (S20 + V2 + σ densité + sans EF
commune), indice on vs off. Juge = erreur médiane CV.

| Commune | avec indice : r² / err | sans indice : r² / err |
|---|---|---|
| Toulouse | 0,858 / 12,3 | 0,677 / **17,5** |
| Colomiers | 0,791 / 12,4 | 0,695 / 14,9 |
| Cazères | 0,353 / 20,9 | 0,354 / 23,3 |
| Foix | 0,795 / 16,8 | 0,794 / 16,8 |
| 65160 | 0,621 / 18,3 | 0,571 / **16,5** |
| 65481 | 0,581 / 21,3 | 0,527 / 21,8 |

## Lecture

1. **Retirer l'indice dégrade l'erreur CV** sur 4 communes sur 6, et **lourdement
   sur le dense** (Toulouse 12,3 → 17,5, +5,2 pt). L'indice fait un vrai travail.
2. **Distinction nette avec S22** : sans l'indice, le r² **et** l'erreur CV
   baissent (Toulouse r² 0,858 → 0,677, err 12,3 → 17,5) → **signal prédictif
   réel**, pas du sur-ajustement. Les effets fixes commune, eux, gonflaient le r²
   en dégradant l'erreur — signature opposée.
3. **σ densité et indice local sont complémentaires**, pas redondants : la
   pondération dit *combien* chaque comparable compte par la distance ; l'indice
   donne le *niveau de prix* du micro-quartier (un décalage de base). Sur le dense,
   où les quartiers voisins ont des niveaux très différents, l'indice est décisif.
4. Seule exception : 65160 (montagne) s'améliore un peu sans l'indice — cas isolé.

## Verdict

**Garder l'indice local** (`indice_local=true`, le défaut). Contrairement aux
effets fixes commune, ce n'est pas du sur-paramétrage : c'est de l'information.

Le bloc paramètres de calcul est clos : **S23** (σ densité), **S22** (retirer EF
commune), **S24** (garder l'indice). Le seul mécanisme de localité retiré est celui
qui sur-ajustait (commune) ; les deux qui prédisent (indice, σ) sont conservés.
