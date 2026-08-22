# Page publique hébergée — décision D9 et modèle retenu

Ce document **fait autorité** sur la page publique d'estimation : il acte la décision
**D9** ([#266](../../../issues/266)) et décrit le dispositif **tel qu'il tourne**, pas
tel qu'il avait été imaginé au cadrage. Épic porteur :
[#6](../../../issues/6), jalon J6.

> Toute évolution de la page publique — routage, résolution du tenant, contrôle
> anti-abus, seuil, en-tête de sécurité — se reflète ici **dans le même commit** que le
> code. Un dispositif de sécurité décrit de façon périmée est pire qu'un dispositif non
> décrit : on s'y fie.

---

## 1. La décision (D9)

**La page publique hébergée par nous remplace le widget iframe embarqué chez le pro.**
Le professionnel n'intègre plus rien à son site : il **partage un lien** vers une page
que nous servons, à sa marque.

**Conséquence directe : la décision D1 est abandonnée.** D1 sécurisait le widget
embarqué par un token *signé par domaine*, la vérification des en-têtes
`Origin`/`Referer` contre une liste blanche de domaines du pro, et une
`Content-Security-Policy: frame-ancestors <domaines du pro>`.

| | Avant — D1 (widget iframe) | Après — D9 (page hébergée) |
|---|---|---|
| Hébergement | iframe sur le site du pro (`/w/{token}`) | page sur `widget.<domaine>` |
| Intégration | un bout de JS à coller | un lien à partager |
| Identité de la page | token **signé par domaine** | token **opaque**, aléatoire, non énumérable |
| Barrière d'accès | `Origin`/`Referer` + liste blanche de domaines | aucune — l'URL est ouvertement accessible |
| Encadrement | `frame-ancestors` = domaines du pro | `frame-ancestors 'none'` |
| Marque | héritée de la page parente | marque blanche rendue par nous ([#267](../../../issues/267)) |

### Pourquoi D1 ne pouvait pas survivre au pivot

Les trois contrôles de D1 reposaient tous sur l'existence d'un **domaine parent**. Sans
lui, il n'y a plus d'origine à vérifier — un visiteur ouvre l'URL directement, il
n'envoie aucun `Origin` à confronter à quoi que ce soit — et plus de domaine à signer
dans le token. Les garder aurait laissé en place des contrôles qui ne contrôlent rien,
c'est-à-dire une illusion de protection.

### Ce que D9 ne dit pas

La page est **non indexable** (`<meta name="robots" content="noindex">`) et son URL
**non énumérable** (jeton aléatoire de 128 bits). Elle n'est pas pour autant *secrète* :
le modèle de sécurité ne repose **jamais** sur la confidentialité du lien — un lien
partagé fuit, par construction. Tout ce qui suit est dimensionné pour une URL que
n'importe qui peut atteindre et rejouer.

---

## 2. Ce qui sert la page

- **Zone `widget.`** — sous-domaine dédié, firewall `^widget\.` en `security: false`
  (`app/config/packages/security.yaml`). Pas de session, pas d'authentification, et le
  filtre de cloisonnement multi-tenant y est **éteint**.
- **URL `/{token}/{slug}`** — le **token** opaque porte l'unicité et **désigne le
  tenant** (c'est lui qui remplace la session absente) ; le **slug** est **décoratif**,
  libre, **non contraint en unicité**, et ignoré à la résolution. Deux agences homonymes
  peuvent porter le même slug sans collision.
- **Génération du token** ([#38](../../../issues/38)) — tirage aléatoire, retiré tant
  que le jeton est déjà pris, abandon bruyant après 5 échecs
  (`GenerateurJetonPagePublique`).
- **Deux verrous indépendants**, tous deux ouverts pour que la page soit servie :
  `active`, l'interrupteur du **pro** ([#38](../../../issues/38)), et `suspendueLe`, le
  coup d'arrêt de l'**admin interne** ou de l'escalade anti-abus
  ([#268](../../../issues/268)). Les séparer garantit qu'un pro ne lève pas la
  suspension prononcée contre sa page en rebasculant son propre interrupteur.
- **Token inconnu, page désactivée ou suspendue → 404**, sans distinguer les cas.

| Route | Méthode | Rôle |
|---|---|---|
| `/{token}/{slug}` | GET | Page brandée + formulaire d'estimation |
| `/{token}/adresses` | GET | Autocomplétion BAN par relais serveur ([#287](../../../issues/287)) |
| `/{token}/estimation` | POST | Premier chiffre (valeur verte, fourchette, n, rayon) |
| `/{token}/contact` | POST | Mur de contact → demande de lead à confirmer |
| `/{token}/contact/confirmer/{jeton}` | GET | Double opt-in → matérialise le lead |
| `/{token}/lead/{reference}/pdf` | GET | PDF figé du lead confirmé |
| `/{token}/evenement` | POST | Étapes de parcours déclarées par le navigateur ([#90](../../../issues/90)) |

`/{token}/adresses` est déclarée en `priority: 1` : le slug étant libre, `/{token}/{slug}`
happerait sinon `/{token}/adresses` et servirait la page habillée à la place du JSON.

---

## 3. Le modèle de sécurité, reconstruit pour une URL ouverte

Les menaces à couvrir, telles que l'épic les pose : **spam de leads**, **coût de
calcul**, **scraping des coefficients** (notre actif — c'était la crainte historique de
D1, désormais sans barrière d'origine), **abus d'email/PDF** (notre réputation
d'expéditeur), **XSS stockée / SSRF** via le branding que *nous* hébergeons.

### 3.1 Préventif, à chaque requête ([#43](../../../issues/43))

Quatre contrôles, joués **du moins cher au plus cher** par `GardeAntiAbusWidget` sur les
endpoints coûteux (estimation, capture de lead) :

1. **Plafond** — voir §3.2 ; refus en `429`.
2. **Honeypot** — champ leurre `site_web`, invisible et hors du parcours clavier ;
   rempli ⇒ `422`. Défense sans friction, jouée avant tout traitement coûteux.
3. **Jeton de chargement** — HMAC-SHA256 au secret applicatif, **stateless**
   (`"<timestamp>.<signature>"`), recevable dans une fenêtre d'âge : **≥ 2 s** (une
   soumission plus rapide qu'un humain trahit un bot) et **≤ 3600 s** (borne la fenêtre
   de rejeu sans mémoriser les jetons consommés). Comparaison en temps constant.
4. **Turnstile** — défi Cloudflare, invisible en temps normal, vérifié **côté serveur**
   auprès de `siteverify`. **Inerte tant que `TURNSTILE_SECRET` est vide** : le contrôle
   s'active à la configuration des clés, sans changement de code.

**Chaque rejet est journalisé** comme un événement de la page (`plafond_atteint`,
`piege_declenche`, `jeton_refuse`, `turnstile_echoue`). Sans cette trace, un matraquage
intégralement bloqué resterait invisible : les contrôles feraient leur travail sans que
personne n'apprenne que la page est attaquée. C'est cette trace que mesure le réactif.

### 3.2 Plafonds (`config/packages/rate_limiter.yaml`)

Tous en fenêtre glissante (`sliding_window`) ; le dépassement de **l'un** suffit à
refuser (`429`).

| Limiteur | Clé | Volume | Fenêtre | Ce qu'il borne |
|---|---|---|---|---|
| `widget_ip` | IP | 20 | 15 min | L'usage individuel des endpoints coûteux |
| `widget_page` | token | 300 | 1 h | Le trafic d'une page — un pic distribué sur des IP multiples |
| `widget_emission` | IP | 60 | 5 min | Le moissonnage de jetons de chargement frais (GET du formulaire) |
| `widget_evenement` | IP | 120 | 15 min | Le bourrage de faux événements de parcours (endpoint bavard, très bon marché) |
| `widget_adresse` | IP | 60 | 15 min | Le moissonnage de la BAN à travers nous (l'endpoint suit la frappe) |

### 3.3 Réactif : détecter le pic, durcir, couper, alerter ([#268](../../../issues/268))

Le préventif borne **chaque requête** sans jamais voir la **forme du trafic**. Le
réactif observe une fenêtre glissante de **15 min** et se déclenche au-delà de :
**120 estimations**, **30 leads**, ou **60 rejets**. Au-delà du seuil **× 3**, le pic est
dit *critique*.

Trois suites, dans cet ordre :

1. **Durcir** — le Turnstile devient **visible** pendant **120 min** : la friction ne
   survit pas à l'incident qui l'a justifiée.
2. **Couper** — pic critique ⇒ la page est **suspendue d'office** (kill switch
   automatique), sans attendre qu'un humain se réveille. Un kill switch **manuel** est
   également offert à l'admin interne.
3. **Alerter** — l'admin interne (journal d'audit) et **le pro concerné** par email :
   c'est sa page. Fenêtre de silence de **60 min** pour ne pas réémettre l'alerte à
   chaque requête du pic ; **un pic critique parle toujours**. Une page déjà suspendue
   n'escalade plus.

### 3.4 Contenu hébergé : branding et CSP ([#267](../../../issues/267))

Le branding du pro (nom, couleur, logo, RGE, mentions) est rendu **sur notre origine** :
ce qui s'y injecterait jouerait avec notre domaine et notre réputation. Deux barrières :

- **À la saisie** — `UrlLogo` n'accepte qu'un **chemin same-origin** (`/…`, sans `//`)
  ou une **URL `https://` vers un hôte public** ; privé, loopback et link-local sont
  refusés (ce qui écarte le point de métadonnées `169.254.169.254`). Aucune résolution
  DNS : garde de frontière, sans effet réseau. La couleur passe par `Couleur`, bornée.
- **Au rendu** — CSP posée sur **toute réponse** de la zone `widget.` (le résultat
  d'estimation porte le mur de contact : c'est le formulaire le plus intéressant à
  détourner) :

  ```
  default-src 'self'; script-src 'self' https://challenges.cloudflare.com;
  style-src 'self'; style-src-attr 'unsafe-inline'; img-src 'self' https:;
  connect-src 'self' https://challenges.cloudflare.com;
  frame-src https://challenges.cloudflare.com; frame-ancestors 'none';
  form-action 'self'; base-uri 'none'; object-src 'none'
  ```

  `frame-ancestors 'none'` **est** la trace de D9 dans les en-têtes : plus personne n'a
  de raison d'encadrer cette page. `style-src-attr 'unsafe-inline'` ouvre l'inline
  **d'attribut seul** — la couleur de marque voyage par une variable CSS qu'aucun nonce
  ne peut couvrir ; un `<style>` injecté reste interdit. `img-src https:` reste large
  tant que le logo n'est pas proxifié ; `data:` est fermé.

### 3.5 Sorties réseau : le relais BAN ([#287](../../../issues/287))

Le moteur travaille par **maille communale** : sans code INSEE, pas de chiffre.
L'autocomplétion d'adresse interroge la BAN **depuis notre serveur**, jamais depuis le
navigateur. Trois raisons, dans l'ordre : la CSP garde `connect-src 'self'` ; ni l'IP du
visiteur ni l'adresse qu'il tape ne partent chez un tiers, sur une page qui annonce une
collecte anonyme et sans partage ; et l'appel devient **plafonnable** comme les autres
endpoints publics. Toute défaillance dégrade vers une liste vide — la page reste
utilisable. Une adresse tapée sans être **choisie dans la liste** est refusée comme
telle, et surtout pas maquillée en « hors couverture ».

---

## 4. Règles produit que D9 fige

- **L'essai/quota du pro n'est JAMAIS décompté par la page publique.** Seul un **audit**
  réalisé par le pro consomme du quota. Un lead public est gratuit ⇒ **l'épuisement de
  quota n'est pas un vecteur d'abus à couvrir**.
- **Le mur de contact vient après le premier chiffre**, jamais avant. Le visiteur obtient
  valeur verte, fourchette, effectif et rayon **sans donner d'email**.
- **L'ajustement du pro est interdit** sur la page publique (réservé au mode audit), de
  même que le chiffrage économique : la page ne rend que la valeur verte.
- **Aucun chiffre par défaut** : une commune hors couverture reste **sans estimation**.
  Le parcours s'arrête là, et l'absence de l'étape suivante *est* la mesure de l'abandon.
- **Double opt-in avant tout** ([#42](../../../issues/42)) : la soumission du mur de
  contact n'émet qu'une **demande à confirmer** et un email d'opt-in — aucun rapport,
  aucune notification au pro. C'est le **clic de confirmation** (jeton à usage unique,
  valide 24 h) qui matérialise le lead (`Rapport`, `Source::Widget`), le fait apparaître
  dans « Rapports & leads » et notifie le pro. Le chiffre servi vient de **l'instantané
  figé à la saisie**, jamais recalculé. Le consentement est explicite et **non
  pré-coché** ; case décochée ⇒ rien ne part.

---

## 5. Mesure du parcours ([#90](../../../issues/90))

Funnel, dans l'ordre : `widget_charge` → `adresse_saisie` → `estimation_affichee` →
`detail_deplie` → `mur_contact_affiche` → `lead_soumis`. **L'abandon n'a pas
d'événement** : il se lit comme l'absence de l'étape suivante.

- **Aucune donnée personnelle** — ni IP, ni email, ni adresse du bien. L'identifiant de
  visite est anonyme, éphémère, et voyage **par les formulaires**, jamais par un cookie.
- **Seules les deux étapes qui n'existent que dans le navigateur** (`adresse_saisie`,
  `detail_deplie`) sont déclarables par l'endpoint public. Une étape que le serveur
  constate lui-même ne se déclare jamais de l'extérieur — sinon n'importe qui
  fabriquerait un funnel flatteur.
- **Rétention 90 jours**, purge idempotente par cron (registre
  [#190](../../../issues/190)). La purge est le pendant obligatoire de la collecte :
  sans elle, la rétention « courte » annoncée n'existe pas.

---

## 6. Ce que D9 ferme

À ne pas rouvrir sans une **nouvelle décision** écrite ici :

- Pas de **widget JS embarqué**, pas d'iframe chez le pro, pas de bout de code à coller.
- Pas de **liste blanche de domaines** du pro, ni de vérification `Origin`/`Referer`.
- Pas de **token signé par domaine** : le token est opaque et ne signe rien.
- `frame-ancestors` reste `'none'`.
- Le quota du pro reste **hors du chemin** de la page publique.

---

## 7. Traçabilité

| Sujet | Issue | PR |
|---|---|---|
| Décision D9 | [#266](../../../issues/266) | ce document |
| Page hébergée `/{token}/{slug}` + marque blanche | [#40](../../../issues/40) | #271 |
| Parcours particulier (premier chiffre, mur de contact) | [#41](../../../issues/41) | #275 |
| Capture de lead + double opt-in | [#42](../../../issues/42) | #276 |
| Anti-abus préventif (plafonds, honeypot, jeton, Turnstile) | [#43](../../../issues/43) | #280, #281 |
| Branding hébergé : anti-XSS/SSRF puis CSP | [#267](../../../issues/267) | #282, #284 |
| Anti-abus réactif (pic, alerte, kill switch) | [#268](../../../issues/268) | #285 |
| Tracking anonyme du parcours | [#90](../../../issues/90) | #283 |
| Écran pro : configurer et partager la page | [#38](../../../issues/38) | #286 |
| Autocomplétion d'adresse BAN par relais serveur | [#287](../../../issues/287) | #288 |
