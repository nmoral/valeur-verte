# Onboarding self-service

**Objectif** : un artisan crée son compte, produit son premier rapport et souscrit sans qu'aucun humain n'intervienne.

**Décision structurante** : le self-service n'est pas une optimisation à ajouter plus tard. Il conditionne le parcours d'entrée et doit être posé dès le MVP. Le rattraper ensuite implique de réécrire l'inscription, la configuration et la facturation.

Epic de suivi : [#104](../../../issues/104).

---

## Principe directeur

> **Un rapport avant toute configuration.**

L'artisan doit pouvoir produire un rapport correct et présentable sans avoir rien paramétré. La configuration (coûts, bouquets, branding) intervient à la deuxième visite, pas à la première.

Corollaire : ce qu'un commercial expliquerait, l'interface doit le porter. Valeurs par défaut, libellés, messages d'erreur, indicateur de fiabilité, mode dégradé — tout ce qui relevait du « on lui expliquera » devient du produit.

---

## Parcours cible

**Trois écrans jusqu'au premier rapport.**

| # | Écran | Contenu | Friction acceptable |
|---|---|---|---|
| 1 | Inscription | Email + mot de passe + SIRET | Aucune validation bloquante |
| 2 | Saisie | Adresse, surfaces, DPE avant/après | Autocomplétion BAN |
| 3 | Résultat | Chiffre + PDF téléchargeable | — |

Configuration et souscription arrivent **après**, à l'initiative de l'artisan.

---

## Issues

| Réf. | Issue | Jalon |
|---|---|---|
| ONB-1 | [#105](../../../issues/105) Inscription ouverte | J4 — Admin pro |
| ONB-2 | [#106](../../../issues/106) Remplissage automatique par le SIRET | J4 — Admin pro |
| ONB-3 | [#34](../../../issues/34) Grilles de coûts pré-remplies (+ [#35](../../../issues/35)) | J4 — Admin pro |
| ONB-4 | [#39](../../../issues/39) Premier rapport avant configuration | J4 — Admin pro |
| ONB-5 | [#107](../../../issues/107) Paiement autonome | transverse |
| ONB-6 | [#108](../../../issues/108) Compteur d'essai visible | transverse |
| ONB-7 | [#109](../../../issues/109) Relance conversationnelle | transverse |
| ONB-8 | [#110](../../../issues/110) Support porté par l'interface | transverse |
| — | [#111](../../../issues/111) Métriques d'activation | transverse |

### ONB-1 — Inscription ouverte

Création de compte sans validation manuelle, sans démo obligatoire, sans formulaire de contact commercial.

- Email + mot de passe, compte actif immédiatement
- Vérification d'email **a posteriori** (bandeau de rappel), jamais bloquante avant le premier rapport
- Aucun champ facultatif à ce stade

**Critère d'acceptation** : compte utilisable moins de 60 secondes après l'arrivée sur la page.

---

### ONB-2 — Remplissage automatique par le SIRET

Point de friction principal : on a besoin de qualifier l'entreprise, l'artisan ne veut pas remplir sa fiche d'identité.

**Solution** : un seul champ, 14 chiffres.

1. Saisie du SIRET
2. Appel à l'**API Recherche d'Entreprises** (`recherche-entreprises.api.gouv.fr`) → raison sociale, adresse, code NAF, effectif
3. Appel à l'**API Professionnels RGE** (ADEME, open data) → qualifications et domaines de travaux
4. Pré-remplissage complet de la fiche, affiché pour confirmation

**Effet secondaire recherché** : c'est un moment de démonstration. L'artisan tape 14 chiffres et voit son entreprise apparaître avec ses qualifications. Signal de qualité envoyé avant même le premier calcul.

**Cas limites à traiter** :
- SIRET inconnu ou fermé → message clair, saisie manuelle en repli
- Entreprise non RGE → ne pas bloquer, mais noter le statut (segment à observer)
- Plusieurs établissements → laisser choisir

**Après l'inscription** ([#311](../../../issues/311)) : la fiche n'est plus figée. L'écran
« Entreprise » de l'espace pro la donne à consulter et à corriger — raison sociale et adresse
librement, le SIRET seulement après une **re-vérification** par les mêmes API (c'est lui qui
identifie le redevable, et une facture au mauvais nom se corrige mal). Un pro dont le SIRET
était inconnu à l'inscription y trouve son chemin de rattrapage. Ce que disent les annuaires
— code NAF, effectif, qualifications RGE — s'affiche mais ne se saisit pas : un RGE
auto-déclaré n'aurait aucune valeur. Chaque changement est inscrit au journal d'audit (#50).

**Critère d'acceptation** : depuis un SIRET valide, la fiche entreprise est complète sans autre saisie.

---

### ONB-3 — Grilles de coûts pré-remplies et fonctionnelles

**C'est l'issue qui décide du taux d'activation.** Un artisan devant sept postes à renseigner avant d'obtenir un chiffre abandonne.

- Valeurs par défaut nationales chargées à la création du compte
- Tous les postes renseignés : murs, combles, planchers bas, menuiseries, chauffage, ECS, VMC
- Un bouquet type « rénovation d'ampleur standard » actif par défaut
- Le rapport est produisible immédiatement, sans aucune modification

L'ajustement est proposé **après** le premier rapport, pas avant.

**Critère d'acceptation** : un compte créé il y a 3 minutes produit un rapport chiffré complet.

---

### ONB-4 — Premier rapport avant configuration

Redirection directe de l'inscription vers la saisie d'adresse. Pas d'écran de bienvenue, pas de visite guidée, pas de checklist de configuration.

- Après le premier rapport : invitation à personnaliser (branding, coûts), avec possibilité de reporter
- Pas de mur : le PDF est téléchargeable même sans logo ni configuration

**Critère d'acceptation** : le chemin inscription → PDF ne passe par aucun écran de paramétrage.

---

### ONB-5 — Paiement autonome

Entre dans le périmètre MVP du fait du self-service. Était précédemment hors périmètre.

- Souscription en ligne (Stripe ou équivalent), sans intervention
- Bascule automatique à la fin de l'essai (2 mois **ou** 10 rapports, au premier atteint)
- Résiliation en self-service également — une désinscription qui nécessite un email détruit la confiance
- Facturation électronique conforme via une Plateforme Agréée (obligation d'émission au 1er sept. 2027 pour les micro-entreprises)

**Critère d'acceptation** : souscription et résiliation possibles sans contact humain.

---

### ONB-6 — Compteur d'essai visible

Sans commercial pour rappeler l'échéance, l'artisan doit voir en permanence où il en est.

- Bandeau discret : *« essai — 7 rapports restants »* ou *« 34 jours restants »*
- Notification à 80 % de la consommation
- Écran de bascule qui affiche le bilan d'usage, pas un simple formulaire de paiement :
  > *« 10 rapports générés, 6 contacts capturés »*

Le chiffre d'usage fait la vente à la place du commercial.

**Critère d'acceptation** : à aucun moment l'artisan ne peut être surpris par la fin de l'essai.

---

### ONB-7 — Relance conversationnelle après le premier rapport

**Compense la perte de l'appel de découverte**, qui était la principale source d'apprentissage sur les premiers clients.

- Email personnel automatique, déclenché après la génération du premier rapport
- Ton direct, pas de gabarit marketing :
  > *« Vous venez de faire votre premier rapport. Ça a donné quoi ? Si le chiffre vous a paru faux, dites-le-moi, ça m'aide. »*
- Réponse arrivant dans une vraie boîte, à laquelle on répond réellement
- Non bloquant, une seule relance

**Critère d'acceptation** : envoi déclenché sur événement, pas sur calendrier.

---

### ONB-8 — Support porté par l'interface

Tout ce qui aurait été expliqué de vive voix doit exister dans le produit.

- ✅ Libellés explicites plutôt que jargon (pas de « coefficient hédonique ») — aucun jargon dur n'était exposé ; « médiane » est explicité par une infobulle sur le prix de base
- ✅ Indicateur de fiabilité vert/orange/rouge sur chaque estimation — livré #31, réutilisé
- ✅ Mode dégradé départemental avec bandeau explicite — livré #22, réutilisé
- ✅ Messages d'erreur qui disent quoi faire, pas ce qui a échoué — ajustement nul reformulé (« Saisissez un montant différent de zéro… ») ; les autres messages de saisie étaient déjà actionnables
- ✅ Infobulles sur n, rayon et erreur médiane — composant réutilisable `<twig:Infobulle>` (accessible clavier/lecteur d'écran) sur `n`, `rayon`, `millésime` et la **fourchette** (forme visible de l'erreur médiane, non affichée telle quelle : l'app est en lecture seule sur `data`)
- ✅ Bouton « ce chiffre me semble faux » sur chaque résultat — livré #49, réutilisé

**Critère d'acceptation** : un testeur externe produit un rapport et sait interpréter la fiabilité sans poser de question.

> ⚠️ Ce critère exige une **session de test utilisateur** (validation humaine, hors code) : à planifier avant de clore ONB-8. Le code (infobulles, libellés, messages actionnables) est livré ; reste à le confronter à un testeur externe.

---

## Métriques d'activation à instrumenter

| Métrique | Seuil visé |
|---|---|
| Inscription → premier rapport | > 60 % |
| Délai médian inscription → premier rapport | < 10 min |
| Comptes ayant produit ≥ 3 rapports à J+30 | > 40 % |
| Abandon sur l'écran SIRET | < 15 % |
| Essai → souscription | à observer, pas de cible a priori |

L'abandon sur l'écran SIRET est le signal le plus important : c'est le seul moment où l'on demande quelque chose avant de rien donner.

---

## Ce que le self-service coûte

**Perte de l'appel de découverte.** À dix clients, c'est un vrai coût — c'était la principale source d'apprentissage terrain. ONB-7 le compense partiellement, sans le remplacer.

**Charge déplacée sur le produit.** Les valeurs par défaut, les libellés et les messages d'erreur ne sont plus des détails de finition : ils portent l'explication commerciale.

**Exposition aux abus.** Inscription ouverte = comptes jetables, tests automatisés, scraping des coefficients. Rate limiting par compte et par IP nécessaire dès le départ (cf. D2, [#44](../../../issues/44)).

---

## Impact sur les documents existants

| Document | Section | Modification |
|---|---|---|
| MVP | §3 — Périmètre | Le paiement autonome entre dans le périmètre |
| MVP | §9.4 — Onboarding | Remplacé : passe de semi-manuel à self-service complet |
| MVP | §9.14 — Hors périmètre | La documentation utilisateur n'est plus remplaçable par « un appel de dix minutes » |
| MVP | §10.5 D2 — Rate limiting | Étendu aux inscriptions |
| Roadmap | M6 — Admin pro | Ajouter ONB-1 à ONB-4 |
| Roadmap | M8 — Finitions | Ajouter ONB-5 à ONB-8 |

Le découpage M6/M8 du document d'origine est transposé sur les jalons du dépôt : **ONB-1 à ONB-4 en `jalon: J4 — Admin pro`**, **ONB-5 à ONB-8 en `jalon: transverse`** (le dépôt n'a pas de jalon « Finitions »).
