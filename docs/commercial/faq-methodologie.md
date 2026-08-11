# FAQ méthodologique — répondre en RDV

> Antisèche pour le pitch : réponses **courtes, exactes et défendables** aux
> questions techniques d'un prospect. Chaque réponse tient en quelques phrases.
> Ton : commercial mais honnête — assumer les limites renforce la confiance.
>
> Adossée au moteur réellement implémenté (§5.1–§5.4, §7, §10.6). Le niveau
> d'exposition publique de la méthodologie reste tranché par la décision #67.

---

## 1. Pourquoi une régression, et pas une simple moyenne de biens comparables ?

Une moyenne de « biens comparables » mélange des logements qui diffèrent par la
taille, la commune, l'année de vente **et** l'étiquette énergétique : on ne sait
plus ce qui explique l'écart de prix. La régression fait le tri — elle isole
l'effet **propre** du DPE en tenant constants la surface, la commune et le
millésime. On mesure le surcoût ou la décote réellement attribuable à l'énergie,
pas au hasard de l'échantillon.

## 2. Pourquoi un rayon adaptatif (10 → 50 km) ?

Il faut assez de ventes pour un chiffre fiable, tout en restant local pour rester
pertinent. On part sur **10 km** et on élargit (15, 20, 30, 50) **seulement** si
l'échantillon est trop mince. Surtout : on filtre les **transactions**, pas les
communes — une maison en limite de commune « voit » les ventes d'à côté, sans
l'artefact de bord d'un découpage administratif.

## 3. Pourquoi ne garder que le bâti ancien (avant 1989) ?

C'est le cœur de la méthode. Sur du neuf, l'étiquette est corrélée à mille autres
choses (normes, prestations) → on **surestime** l'effet énergie. Sur de l'ancien,
une bonne étiquette signale une **rénovation réelle** : on mesure le gain de la
rénovation, pas un artefact. C'est ce qui rend le chiffre honnête.

## 4. Pourquoi pondérer par la distance ?

Une vente à 2 km est plus informative qu'une vente à 40 km. On applique un poids
qui **décroît avec la distance** (pondération gaussienne) : les ventes proches
comptent davantage, les lointaines complètent sans dominer.

## 5. Pourquoi publier une erreur médiane en %, et pas un R² ?

Un R² est un indicateur d'ajustement que personne ne sait interpréter devant un
client. **« L'erreur médiane de nos estimations est de 12 % »** se comprend
immédiatement. Et ce n'est pas un chiffre théorique : on le mesure par
**validation croisée** — on cache une partie des ventes, on prédit, on compare au
prix réel. L'erreur annoncée est donc l'erreur **réellement observée**.

## 6. Que se passe-t-il quand les données locales manquent ?

On ne bluffe pas. Sous un seuil de fiabilité, on bascule sur une estimation
**départementale**, avec un **bandeau d'alerte non masquable** (repris dans le
PDF) : « données locales insuffisantes, fiabilité réduite ». Et si même le
département est trop mince, on **refuse** d'afficher un chiffre plutôt que d'en
inventer un.

## 7. D'où viennent les données ?

Uniquement des sources **publiques et officielles** : **DVF** (transactions,
DGFiP/Etalab), **DPE** (ADEME), **BAN** (adresses). Aucune donnée achetée ni
opaque, source citée. C'est vérifiable et pérenne.

## 8. Peut-on « arranger » le chiffre ?

Non. Le **coefficient statistique n'est jamais modifiable**. En mode audit, le
professionnel peut ajouter un **ajustement**, mais c'est une **ligne séparée,
explicitement motivée**, jamais une surcharge du chiffre de base. La donnée
statistique et l'avis du pro restent distincts et traçables.

## 9. Deux rapports sur le même bien donnent-ils le même résultat ?

Oui. Chaque rapport est **figé dans un instantané** : mêmes données d'entrée →
même sortie, à l'identique, rejouable. Pas de dérive silencieuse d'une exécution
à l'autre.

## 10. Que veulent dire les chiffres affichés (n, IC, rayon, millésime) ?

On affiche toujours le **contexte** du résultat :

- **n** — nombre de ventes utilisées pour l'estimation ;
- **IC** — intervalle de confiance, la fourchette du chiffre ;
- **rayon** — la portée géographique retenue ;
- **millésime** — l'année des données.

C'est cette transparence qui fait la crédibilité : on montre **sur quoi** le
chiffre repose, au lieu d'un nombre sorti d'une boîte noire.

---

## En une phrase (à ressortir en clôture)

> *« On ne vend pas un chiffre magique : on vend un chiffre **défendable** —
> calculé sur des données publiques, avec une méthode assumée, une marge d'erreur
> mesurée et affichée, et qui refuse de répondre quand il ne sait pas. »*

### Assumer les limites (ça rassure, ça ne dessert pas)

- Le modèle actuel exprime l'effet DPE en **montant €** ; une variante en **%**
  (standard des Notaires) est à l'étude (#92) — à valider sur données réelles.
- La fiabilité **dépend de la densité de ventes** locale : d'où le mode dégradé
  et l'affichage systématique de `n` et de l'erreur.
