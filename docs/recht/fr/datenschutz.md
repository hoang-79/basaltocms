# Déclaration de protection des données

> **Projet — examen juridique en attente.** Version 0.3 · État au 25.10.2026 · pas encore en vigueur.
> Seule la version allemande fait foi ; les traductions servent à la compréhension.

La présente déclaration décrit ce que nous faisons des données personnelles : sur basaltocms.com et ses sous-domaines
(site web, documentation, démo, communauté, Marketplace), dans le compte client, lors de l'achat de licences, dans le
SaaS et lors de la vérification des mises à jour par le logiciel. Elle se fonde sur la loi fédérale sur la protection
des données (LPD) et, pour les personnes dans l'UE, sur le règlement général sur la protection des données (RGPD).

Les sites web exploités avec Basalto relèvent de la responsabilité de la personne qui les exploite. Si nous exploitons
un site pour vous dans le SaaS, nous traitons ses données pour votre compte conformément au [Contrat de traitement des
données en sous-traitance](avv.md).

## 1. Qui est responsable

Basalto — un produit de nguyen-development.ch, Hoang Nguyen, entreprise individuelle, Hohfurristrasse 24,
8408 Winterthour, Suisse.
Contact pour la protection des données : email@basaltocms.com.

Représentation dans l'UE (art. 27 RGPD) : [[Platzhalter: désigner une représentation dans l'UE ou justifier pourquoi
aucune n'est nécessaire]]

## 2. Ce que le logiciel nous envoie

Une installation Basalto vérifie sa clé de licence hors ligne et ne transmet rien de ce qui se passe sur un site — aucun
contenu, aucun visiteur, aucun utilisateur. Elle ne se connecte à basaltocms.com que dans les cas suivants :

- **Vérification des mises à jour** — une fois par jour et lorsque quelqu'un choisit « Vérifier maintenant » dans
  l'administration. Nous voyons alors l'adresse IP du serveur et la version de Basalto. Si le paramètre **« Données
  d'utilisation pour l'éditeur »** est activé (il l'est par défaut), l'installation envoie en outre un identifiant
  aléatoire, la version de PHP et le type de base de données. L'identifiant est créé lors de la première vérification et
  n'est rattaché à rien ; nous n'en enregistrons qu'une empreinte, au maximum une fois par jour, avec la version, PHP et
  la base de données, et comptons ainsi les installations actives. Nous n'enregistrons pas l'adresse IP à cette
  occasion. Désactivation sous Administration › Paramètres › Exploitation.
- **Mises à jour et téléchargements** — lors du téléchargement de l'installateur web, d'une archive de version ou d'une
  mise à jour. Nous comptons le téléchargement avec le type, la version et le canal, sans adresse IP.
- **Marketplace** — lorsque quelqu'un installe un Theme ou un Plugin, l'installation adresse une requête avec la clé
  d'accès personnelle du compte client (chiffre 9).
- **Connexion au compte client** — quiconque connecte l'installation à son compte client pour voir ses licences et ses
  factures dans son propre `/admin` déclenche une requête avec une clé d'accès (chiffre 5).

Si vous téléchargez Basalto via GitHub ou Packagist, leurs déclarations de protection des données s'appliquent.

## 3. Visite du site web

Lors de chaque consultation, notre hébergeur cyon traite votre adresse IP, l'adresse consultée, l'heure, l'identifiant
du navigateur et la réponse, afin de délivrer la page, de trouver des erreurs et de repousser les attaques. Nous
limitons par adresse IP les tentatives de connexion, les formulaires et les requêtes ; ces compteurs expirent au plus
tard après une heure. Nous vérifions les formulaires et les connexions au moyen d'un petit calcul effectué dans votre
navigateur plutôt qu'au moyen d'une énigme visuelle ; rien n'est transmis à des tiers à cette occasion.

## 4. Statistiques et paramètres des cookies

Nous comptons nous-mêmes l'utilisation de basaltocms.com, sans services d'analyse ou de publicité de tiers, sans profils
et sans transmission.

- **Sans consentement**, nous comptons chaque page vue de manière anonyme : page consultée (sans paramètres), provenance
  (uniquement le nom du site web de renvoi et les indications de campagne figurant dans l'adresse), navigateur, système
  d'exploitation et type d'appareil, langue, temps de réponse et si vous êtes connecté. Aucune adresse IP, aucun cookie,
  aucun identifiant — une consultation ne peut être rattachée à aucune personne.
- **Avec votre consentement** à la catégorie « Statistiques », votre navigateur place en outre deux cookies contenant
  des identifiants aléatoires (`basalto_besucher` pour un an, `basalto_sitzung` pour 30 minutes). Nous reconnaissons
  ainsi les visites récurrentes et les sessions cohérentes — par exemple le nombre de pages que comprend une visite. Les
  identifiants ne révèlent pas qui vous êtes.
- Si votre navigateur envoie le signal « Global Privacy Control », nous le traitons comme un refus.
- Nous supprimons les pages vues après 400 jours.

Nous enregistrons votre choix dans le cookie `basalto_consent` pour un an. Vous le modifiez à tout moment via le lien
« Paramètres des cookies » au bas de chaque page ; un retrait vaut pour l'avenir.

### Cookies et stockage dans le navigateur

| Nom | Finalité | Durée | Catégorie |
| --- | --- | --- | --- |
| `basalto-session` | session, connexion | 2 heures après la dernière consultation | nécessaire |
| `XSRF-TOKEN` | protection des formulaires contre les abus | comme la session | nécessaire |
| `basalto_consent` | votre choix dans les paramètres des cookies | 1 an | nécessaire |
| `basalto_community_terms` | votre accord aux conditions de la communauté en tant qu'invité (site et version) | 1 an | nécessaire |
| `basalto_besucher` | identifiant aléatoire pour les visites récurrentes | 1 an | statistiques, uniquement avec consentement |
| `basalto_sitzung` | identifiant aléatoire d'une session | 30 minutes | statistiques, uniquement avec consentement |

Dans le stockage de votre navigateur (pas sous forme de cookie, jamais transmis à nous), nous mémorisons à votre
demande : le mode clair ou sombre, les avis masqués, les brouillons de messages dans le forum, les messages mémorisés
pour être cités et si vous souhaitez recevoir des notifications de bureau. Vous supprimez ces indications avec les
données de sites web de votre navigateur.

Les contenus intégrés de tiers (par exemple des vidéos) ne se chargent qu'après votre consentement à la catégorie
« Contenus externes » ; les déclarations de protection des données de leurs fournisseurs s'appliquent alors.

## 5. Compte client et connexion

**Compte client :** adresse e-mail, nom, langue et — facultativement — entreprise et adresse. Nous consignons qui a
accepté quelle version du contrat de licence, des CG et des conditions d'utilisation, et à quel moment.

**Connexion avec un code :** nous envoyons un code à votre adresse e-mail. Nous n'enregistrons le code que sous forme
d'empreinte, et l'adresse IP de la requête sous forme d'empreinte calculée avec notre clé ; nous supprimons les codes
expirés après un jour.

**Passkey :** votre appareil génère une paire de clés. Nous n'enregistrons que la clé publique, un identifiant, le nom
que vous donnez à la passkey et la date de sa dernière utilisation. Votre empreinte digitale ou l'image de votre visage
ne quittent jamais votre appareil.

**Connexion via un autre compte :** si vous choisissez Google, Microsoft, GitHub ou LinkedIn, votre navigateur vous
redirige vers le fournisseur. Nous recevons de celui-ci un identifiant, votre nom et — uniquement si le fournisseur l'a
confirmée — votre adresse e-mail, et pour Microsoft en outre l'identifiant de l'organisation. Nous enregistrons le
fournisseur, l'identifiant, l'adresse e-mail et l'heure de la dernière connexion, mais ni votre mot de passe auprès du
fournisseur ni d'autres données de votre compte chez celui-ci. Les fournisseurs sont eux-mêmes responsables de leur
part : [Google](https://policies.google.com/privacy), [Microsoft](https://privacy.microsoft.com/),
[GitHub](https://docs.github.com/site-policy/privacy-policies/github-general-privacy-statement),
[LinkedIn](https://www.linkedin.com/legal/privacy-policy). Vous supprimez les liaisons dans le compte client sous
« Connexion ».

**Session :** votre session contient l'adresse IP et l'identifiant du navigateur et prend fin deux heures après la
dernière consultation.

**Installations connectées :** si vous connectez une installation à votre compte client, nous enregistrons son adresse
(hôte), un nom librement choisi, une empreinte de la clé d'accès et la date de sa dernière requête. Vous supprimez la
connexion dans le compte client ou dans l'installation.

## 6. Achat de licences et paiement

**Licences :** adresse de facturation, preneur de licence, domaines de production, niveau, clés émises, factures et
paiements. Le preneur de licence et les domaines figurent dans la clé elle-même et sont lisibles par toute personne qui
voit la clé. Pour une licence Agence, vous saisissez le nom et le domaine de vos clients pour leur sous-licence.

**Le paiement par carte et par d'autres moyens** passe par Stripe. Les données de carte sont transmises directement à
Stripe ; nous ne les voyons ni ne les enregistrons jamais. Nous recevons l'état du paiement, le montant, le type et les
derniers chiffres du moyen de paiement. **Pour la QR-facture**, notre banque nous communique le nom, le compte et le
montant du paiement.

## 7. SaaS

Pour un compte dans le SaaS, nous traitons en tant que responsable du traitement : le nom et les adresses e-mail des
personnes du compte avec leurs rôles, l'entreprise, l'adresse de facturation, la formule, l'abonnement, les factures et
paiements, les domaines, la date du consentement aux CG et à la déclaration de protection des données lors de
l'inscription ainsi que la résiliation, l'export et la suppression.

Nous ne traitons les contenus de vos sites — pages, médias, utilisateurs, envois de formulaires, commentaires et comptes
clients de vos visiteurs — que pour votre compte, conformément au [Contrat de traitement des données en
sous-traitance](avv.md). Pour vous aider, notre équipe peut ouvrir un site **en lecture seule** pendant quatre heures au
maximum ; le motif figure dans un bandeau de votre backend et dans le journal de votre site.

## 8. Communauté

**Profil :** nom affiché et, si vous le souhaitez, photo de profil, photo de couverture, signature et autres indications
que vous saisissez vous-même. Le profil, vos messages, vos réactions et votre rang sont visibles publiquement et peuvent
être trouvés par les moteurs de recherche. Le profil indique également combien de messages vous avez écrits et quand
vous avez été actif pour la dernière fois.

**Messages :** texte, pièces jointes, modifications avec leur historique, votes aux sondages, réactions et réponses
marquées comme utiles. Pour chaque message, nous enregistrons une empreinte de votre adresse IP afin de lutter contre le
spam et les abus.

**Ce que vous seul voyez :** les sujets lus, les abonnements, les messages mémorisés, les personnes que vous suivez et
celles que vous ignorez ou bloquez. **Les conversations privées** ne sont visibles que par les personnes participantes ;
les modératrices et modérateurs ne voient un message que si quelqu'un le signale.

**Fonctions en direct :** pendant que vous écrivez un message, les autres personnes consultant le même sujet voient
pendant quelques secondes l'indication « … écrit … » avec votre nom affiché. Les nouveaux messages et les notifications
apparaissent sans rechargement de la page.

**Conditions :** avant d'utiliser la communauté, vous acceptez les conditions d'utilisation et les règles de la
communauté. Avec un compte, nous enregistrons la version, la date et l'heure de votre accord et d'un éventuel retrait ;
vous les voyez dans le compte client et vous y retirez votre accord. En tant qu'invité, votre navigateur mémorise votre
accord dans le cookie `basalto_community_terms`.

**Modération :** nous consignons, avec leur motivation, les signalements, les avertissements, les blocages et chaque
mesure de modération. Nous pouvons comparer les nouveaux comptes avec la liste publique de Stop Forum Spam ; l'adresse
IP et une empreinte de l'adresse e-mail sont alors transmises à l'exploitant de cette liste. Sur basaltocms.com, cette
comparaison est désactivée ; si nous l'activons, nous complétons au préalable la présente déclaration.

**Fermeture du compte :** nous supprimons le profil, les images et les pièces jointes ; les messages subsistent sans nom
et sans lien avec vous, et l'empreinte de l'adresse IP disparaît (voir les [Conditions
d'utilisation](nutzungsbedingungen.md)).

## 9. Marketplace

**Autrices et auteurs :** le profil d'auteur public (nom ou raison sociale, sur demande lieu, site web et logo), les
paquets soumis avec les résultats de l'examen automatique et de l'examen dans une installation de test isolée, la
communication relative à la revue ainsi que les ventes et les versements. Pour les entrées payantes, vous ouvrez un
compte chez Stripe (Stripe Connect) ; Stripe vérifie votre identité et votre compte bancaire sous sa propre
responsabilité.

**Acheteuses et acheteurs :** les achats, les factures, la clé d'accès personnelle au dépôt Composer (chiffrée, afin que
vous puissiez la revoir dans le compte client) et, pour chaque entrée, le fait que vous l'avez téléchargée et dans
quelle version — base de la mention « installé » dans un avis.

**Avis :** les étoiles, le titre, le texte et votre nom affiché sont publics, de même que la mention « acheté » ou
« installé ». Nous enregistrons les signalements d'avis avec leur motif ; pour les signalements sans compte, uniquement
une empreinte qui empêche les signalements multiples.

## 10. E-mails, notifications et contact

Nous envoyons les e-mails liés au compte : codes de connexion, confirmations, factures, rappels et avis de sécurité.
Vous recevez les notifications de la communauté — réponses, mentions, résumés — dans la cloche et, selon vos réglages
dans le profil, par e-mail. Il n'existe pas de newsletter.

Nous traitons ce que vous nous écrivez par e-mail ou au moyen du formulaire de contact, avec votre nom et votre adresse
e-mail, afin de vous répondre. Nous supprimons les envois du formulaire de contact après 90 jours.

Si vous résiliez via « Résilier des contrats ici », nous traitons votre nom, votre adresse e-mail, les indications sur le
contrat et le moment de la réception afin d'exécuter, de confirmer et de documenter la résiliation (contrat et obligation
légale).

## 11. Journal et sécurité

Nous consignons les modifications dans le compte client, dans la modération et dans l'administration de basaltocms.com
avec l'heure, le compte et une empreinte de l'adresse IP, à des fins de traçabilité et de sécurité ; nous supprimons les
entrées après 365 jours.

Nous transmettons tout sous forme chiffrée (TLS), utilisons une connexion sans mot de passe, enregistrons les codes et
les adresses IP uniquement sous forme d'empreintes et les clés d'accès sous forme chiffrée ou d'empreinte, effectuons
des sauvegardes quotidiennes et journalisons les accès. Personne ne peut garantir une sécurité absolue. Si nous
constatons une violation de la sécurité des données qui vous concerne, nous vous en informons, ainsi que — lorsque la
loi l'exige — le PFPDT ou l'autorité de surveillance compétente.

## 12. Bases juridiques

- **Contrat** (art. 31, al. 2, let. a, LPD, art. 6, par. 1, let. b, RGPD) : compte client, connexion, achat, clé,
  factures, SaaS, Marketplace, communauté, support.
- **Obligation légale** (art. 6, par. 1, let. c, RGPD) : conservation des factures et des pièces comptables.
- **Intérêt légitime** (art. 31, al. 1, LPD, art. 6, par. 1, let. f, RGPD) : exploitation et sécurité du site web, lutte
  contre les abus et le spam, statistiques anonymes, comptage des installations actives, examen des extensions soumises,
  modération, preuve des conditions acceptées, réponses à vos demandes.
- **Consentement** (art. 6, par. 1, let. a, RGPD) : cookies de statistiques, contenus intégrés de tiers.

Nous ne vendons aucune donnée, ne les utilisons pas à des fins publicitaires et ne prenons aucune décision individuelle
automatisée produisant des effets juridiques. Les mesures de protection automatiques de la communauté (pause entre les
messages, filtre de mots, vérification dans le navigateur) et l'examen automatique dans la Marketplace ne tranchent pas
de manière définitive ; c'est un être humain qui décide.

## 13. Destinataires et prestataires

Nous faisons appel à des prestataires qui traitent des données pour notre compte :

- **cyon AG, Bâle (Suisse) :** exploitation du site web, du SaaS, de la base de données, des fichiers et des
  sauvegardes, ainsi que l'envoi de nos e-mails, dans des centres de calcul en Suisse. Les fonctions en direct de la
  communauté et des notifications passent, sans autre service, par des requêtes adressées à notre serveur.

Les entités suivantes traitent des données sous leur propre responsabilité :

- **Stripe Payments Europe Ltd. (Irlande)** pour les paiements et Stripe Connect ; Stripe transmet des données à Stripe,
  Inc. (États-Unis) pour la lutte contre la fraude et le respect de ses obligations légales.
- **[[Platzhalter: banque]]** pour l'encaissement des QR-factures.
- **Google, Microsoft, GitHub et LinkedIn**, uniquement si vous vous connectez par leur intermédiaire (chiffre 5).

Au-delà, nous ne transmettons des données que si la loi ou une autorité l'exige ou si cela est nécessaire pour faire
valoir nos droits.

## 14. Communication à l'étranger

Nos données se trouvent en Suisse. Des données ne sont transmises à l'étranger que par l'intermédiaire de Stripe et — si
vous l'utilisez — de la connexion via Google, Microsoft, GitHub ou LinkedIn ; ces destinataires ont leur siège, ou celui
de leur groupe, aux États-Unis. Ces transferts reposent sur la certification des destinataires selon l'EU-US Data
Privacy Framework et son volet Swiss-US ; à défaut, sur les clauses contractuelles types de la Commission européenne
dans la version reconnue par le PFPDT. [[Platzhalter: vérifier la certification de chaque destinataire dans le registre
DPF]] Pour les personnes dans l'EEE : l'UE reconnaît à la Suisse un niveau de protection des données adéquat.

## 15. Combien de temps

| Données | Conservation |
| --- | --- |
| Compte client, profil, liaisons, passkeys | jusqu'à ce que vous fermiez le compte |
| Accords aux conditions de la communauté | jusqu'à ce que vous fermiez le compte |
| Codes de connexion | un jour après leur expiration |
| Factures et pièces comptables | dix ans (art. 958f CO) |
| Clés émises et conditions acceptées | jusqu'à dix ans après la fin du contrat (art. 127 CO) |
| Compte SaaS après la résiliation | bloqué pendant 30 jours, puis supprimé ; factures comme ci-dessus |
| Pages vues des statistiques, vérifications des mises à jour | 400 jours |
| Téléchargements | sans lien avec une personne, sans délai |
| Messages dans le forum | aussi longtemps qu'ils existent ; après la fermeture du compte, sans lien avec une personne |
| Envois du formulaire de contact | 90 jours |
| Résiliations via « Résilier des contrats ici » | cinq ans après la réception (art. 128 CO) |
| Demandes relatives à la protection des données | trois ans après la réponse |
| E-mails qui nous sont adressés | deux ans après le dernier message, sauf si vous êtes cliente ou client |
| Journal | 365 jours |
| Sauvegardes | nos propres sauvegardes quotidiennes, dont les sept dernières sont conservées ; en outre, celles de cyon ([[Platzhalter: durée de conservation des sauvegardes chez cyon]]) |

## 16. Vos droits

Vous pouvez demander des renseignements sur vos données, les faire rectifier, effacer ou en faire limiter le traitement
et les recevoir dans un format électronique courant (art. 25, 28 et 32 LPD, art. 15 à 20 RGPD). Lorsque nous nous
fondons sur un intérêt légitime, vous pouvez vous opposer au traitement (art. 21 RGPD) ; vous pouvez retirer un
consentement à tout moment pour l'avenir. Un message à email@basaltocms.com suffit ; nous répondons dans un délai de 30
jours. Ce que nous devons conserver (chiffre 15) est conservé jusqu'à l'expiration du délai.

Avec un compte client, vous faites l'essentiel vous-même sous **Compte client › Protection des données** : télécharger
vos données sous forme de fichier, rectifier votre nom et votre adresse, consulter et retirer vos consentements,
demander la limitation ou vous opposer (réponse dans un délai d'un mois) et supprimer le compte.

Si vous ne parvenez pas à trouver une solution avec nous, vous pouvez vous adresser au Préposé fédéral à la protection
des données et à la transparence (edoeb.admin.ch) ou, dans l'EEE, à l'autorité de surveillance de votre pays.

## 17. Enfants et adolescents

basaltocms.com s'adresse aux adultes et aux entreprises. La communauté est ouverte aux personnes âgées de 16 ans et
plus. Si nous apprenons qu'un compte appartient à une personne plus jeune, nous le supprimons.

## 18. Modifications

Nous adaptons la présente déclaration lorsque basaltocms.com, le logiciel ou la situation juridique changent. L'état
figure en haut de la page. Nous communiquons les modifications importantes aux clientes et clients par e-mail.
