# Valeur verte — one-pager (trame)

> Trame de recto/verso pour le pitch (#94). À habiller (charte, logo, un cas
> chiffré réel). Les chiffres entre crochets `[…]` sont des placeholders à
> renseigner après le run live et le cadrage tarifaire (#61).

---

## Accroche

**Chiffrez l'impact énergétique sur la valeur d'un bien — avec un chiffre que
vous pouvez défendre.**

## Le problème

La « valeur verte » est dans toutes les conversations, mais personne ne sait la
**chiffrer** face à un vendeur ou un acheteur. Les estimations existantes sont
des boîtes noires : impossible d'expliquer d'où sort le nombre, ni sa fiabilité.

## La solution

Un moteur qui estime, **par commune**, l'effet de l'étiquette DPE sur le prix —
à partir des **transactions réelles** (DVF) et des **DPE** (ADEME), et qui
affiche toujours sa marge d'erreur. Deux façons de l'utiliser :

- **Mode audit** — en rendez-vous, un **rapport PDF à votre en-tête**, prêt à
  remettre au client.
- **Widget** marque blanche — sur **votre site**, le particulier obtient une
  première estimation puis vous laisse ses coordonnées (capture de lead).

## Comment ça marche (3 temps)

1. **On sélectionne** les ventes comparables autour du bien (rayon adaptatif,
   transactions et non communes → pas d'effet de bord).
2. **On isole l'effet DPE** par une régression qui neutralise surface, commune
   et année, sur le **bâti ancien** (on mesure le gain réel de rénovation).
3. **On mesure la fiabilité** (erreur médiane en %, par validation croisée) et
   on **affiche tout** : n, intervalle de confiance, rayon, millésime.

## Ce qui nous différencie

- **Défendable** : chaque chiffre vient avec sa marge d'erreur mesurée — pas une
  boîte noire.
- **Honnête** : sous un seuil de données, on **prévient** (alerte) ou on
  **refuse**, plutôt que d'inventer.
- **Public & vérifiable** : 100 % de données ouvertes officielles (DVF, DPE,
  BAN), source citée.
- **Non falsifiable** : le coefficient n'est jamais bidouillé ; l'avis du pro est
  une ligne séparée et motivée.
- **Reproductible** : deux rapports identiques donnent le même résultat.

## La preuve

> Erreur médiane des estimations : **[X %]** sur **[zone / n rapports]**
> *(à renseigner après le run live).* Exemple : un bien passé de **[E]** à
> **[C]** après rénovation → valeur verte estimée **[+ Y %]**.

## L'offre

- **Abonnement** (pas de licence one-shot : on vend une donnée **à jour**).
- **Sans quota de volume** : on ne pénalise pas l'usage.
- **Essai gratuit** : **[N rapports]** pour tester en conditions réelles.

*(Grille détaillée → voir #61.)*

## Appel à l'action

**[Réservez une démo de 15 min]** — on chiffre un de vos biens en direct.

---

*Sources : DVF (DGFiP/Etalab), DPE (ADEME), BAN. Méthodologie détaillée sur
demande (voir la FAQ méthodologique).*
