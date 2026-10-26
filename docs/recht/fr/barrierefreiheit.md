# Déclaration d'accessibilité

> **Projet — examen juridique en attente.** Version 0.2 · État au 25.10.2026 · pas encore en vigueur.
> Seule la version allemande fait foi ; les traductions servent à la compréhension.

Basalto doit pouvoir être utilisé par toutes et tous — sur basaltocms.com et dans le logiciel avec lequel d'autres
construisent leurs sites web. La présente déclaration indique où nous en sommes, ce qui manque encore et comment nous
joindre lorsque quelque chose ne fonctionne pas.

## 1. Notre objectif

Nous nous conformons aux Règles pour l'accessibilité des contenus Web (WCAG) 2.2, niveau AA, et à la norme européenne EN
301 549. Nous concevons dès le départ les nouvelles fonctions de manière à ce qu'elles fonctionnent avec le clavier, un
lecteur d'écran, l'agrandissement et des réglages de couleurs personnalisés.

## 2. Champ d'application de la présente déclaration

- **basaltocms.com**, avec la documentation, la démo, la communauté, la Marketplace, le compte client et l'achat de
  licences ;
- **le logiciel Basalto** : le backend, l'éditeur, les paramètres des cookies, les comptes clients dans le frontend et
  le Theme fourni « starter ».

Les sites web exploités avec Basalto relèvent de la responsabilité de la personne qui les exploite. Basalto y contribue
— par exemple avec l'obligation de texte alternatif pour les images, qui peut être activée —, mais ne peut garantir ni
les contenus ni les Themes propres de tiers.

## 3. État de conformité

basaltocms.com et Basalto sont **partiellement conformes** aux WCAG 2.2, niveau AA. Les dérogations figurent au
chiffre 5. Cette évaluation repose sur une autoévaluation du 25.10.2026 (chiffre 8).

## 4. Ce que nous faisons à cet effet

- Des pages clairement structurées : titres dans le bon ordre, repères de navigation (landmarks), langue définie pour
  chaque page et chaque section, un lien « Aller au contenu ».
- Tout est utilisable au clavier, avec un focus visible ; les dialogues comme les paramètres des cookies retiennent le
  focus et se ferment avec la touche Échap.
- Des contrastes conformes au niveau AA en mode clair et en mode sombre ; aucune information transmise uniquement par la
  couleur.
- Des animations uniquement là où elles expliquent quelque chose ; un affichage calme lorsque « réduire les animations »
  est activé dans le système d'exploitation.
- Des formulaires avec des libellés visibles et des messages d'erreur à côté du champ concerné.
- Une connexion sans mot de passe et sans énigme visuelle : code par e-mail ou passkey ; la protection anti-spam
  effectue un calcul en arrière-plan au lieu d'un CAPTCHA.
- Des pages lisibles avec un agrandissement jusqu'à 200 %, sans défilement horizontal sur le téléphone.

## 5. Limitations connues

- **Les diagrammes des statistiques** résument leurs valeurs dans un libellé textuel ; un tableau avec toutes les
  valeurs journalières fait encore défaut.
- **Les documents PDF** — factures et justificatifs — ne sont pas encore balisés comme PDF accessibles. Sur demande,
  nous vous envoyons les indications sous forme de texte.
- **Le glisser-déposer** dans l'arborescence des pages et dans l'éditeur de blocs : là où il n'existe pas de commande
  équivalente par menu ou au clavier, nous y travaillons.
- **Les contenus des utilisatrices et utilisateurs** — messages, images, entrées dans la Marketplace — peuvent contenir
  des images sans description ou des structures peu claires. Nous le signalons dans les éditeurs.
- **Les contenus intégrés de tiers** (vidéos) dépendent de leurs fournisseurs ; ils ne se chargent qu'après votre
  consentement.

## 6. Retour d'information et contact

Vous rencontrez un obstacle ou avez besoin de contenus sous une autre forme ? Écrivez à email@basaltocms.com en
indiquant l'adresse de la page et une brève description. Nous répondons dans un délai de dix jours ouvrables et vous
indiquons d'ici quand nous corrigerons le problème ou comment obtenir l'information autrement.

## 7. Procédure de mise en œuvre

En Suisse, la loi sur l'égalité pour les handicapés (LHand) interdit aux prestataires privés de discriminer les
personnes handicapées. Pour les services dans l'UE, l'acte européen sur l'accessibilité (European Accessibility Act,
directive (UE) 2019/882) s'applique ; en tant que microentreprise, nous sommes exemptés de ses exigences relatives aux
services et nous y conformons volontairement. Si notre réponse ne vous satisfait pas, vous pouvez vous adresser à
l'autorité de surveillance du marché de votre pays ou à une organisation de personnes handicapées.

## 8. Méthode d'évaluation

- Des contrôles automatiques avec axe-core lors de chaque modification du logiciel et avec Lighthouse sur les
  principales pages de basaltocms.com.
- Des contrôles manuels avec le clavier, l'agrandissement, le mode sombre et un écran étroit pour chaque nouvelle
  fonction.
- Des contrôles par sondage avec un lecteur d'écran : [[Platzhalter: effectuer le contrôle avec VoiceOver et NVDA et
  inscrire la date]]

Technique : HTML, CSS et JavaScript avec WAI-ARIA, là où le HTML seul ne suffit pas. Les pages de contenu fonctionnent
aussi sans JavaScript ; les paramètres des cookies, les fonctions en direct et le backend en ont besoin. Les versions
actuelles de Chrome, Edge, Firefox et Safari sont prises en charge.

Nous réexaminons la présente déclaration au moins une fois par an et lors de modifications importantes.
