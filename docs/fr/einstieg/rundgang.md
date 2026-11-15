# Visite du backend

Le backend a trois **modes** : « Contenus » pour le travail rédactionnel quotidien, « Configuration » pour la forme et
la structure du site et « Administration » pour les sites, les utilisateurs et l’exploitation. Cette visite montre où
se trouve quoi et comment vous orienter.

![L’en-tête du backend au-dessus de l’aperçu général](bilder/rundgang-kopf.webp)

## L’en-tête

L’en-tête est le même dans tous les modes :

1. **Le site** — si vous travaillez dans plusieurs sites, vous changez de site ici.
2. **Contenus** — pages, blocs, médias, traduction et validation.
3. **Configuration** — apparence, structure, menus et types de contenu du site.
4. **Administration** — sites, utilisateurs, rôles, paramètres, mises à jour et licence.
5. **Rechercher** — trouve des pages, des contenus, des médias et des utilisateurs ; plus rapide avec **⌘K** ou
   **Ctrl+K**.
6. **La cloche** — vos notifications, par exemple lorsqu’une version attend votre vérification.
7. **Votre nom** — le menu avec le profil, les paramètres, le mode sombre, l’aide et « Se déconnecter ».

Vous ne voyez que les modes dont votre rôle a besoin : qui ne fait qu’écrire voit « Contenus », mais ni
« Configuration » ni « Administration ».

## Mode « Contenus »

Sous l’en-tête figurent les **stations** du mode :

| Station | À quoi elle sert |
| --- | --- |
| **Aperçu général** | Ce qui vous attend : versions à vérifier, éléments refusés, traductions manquantes, avis. |
| **Pages** | L’arborescence du site web — créer, ordonner et modifier des pages. |
| **Contenus** | Les entrées sans place fixe dans l’arborescence, par exemple les articles de blog. |
| **Validation** | Tout ce qui doit être vérifié ou publié. |
| **Traduction** | Ce qui manque ou est obsolète dans d’autres langues. |
| **Formulaires** | Les formulaires et leurs envois. |
| **Médias** | Images et fichiers. |
| **Commentaires**, **Membres** | Uniquement si le module est activé pour le site. |

Un nombre couleur ambre à côté d’une station signifie : quelque chose vous y attend. Un nombre gris sert uniquement
d’information.

La barre affiche les stations dont vous avez souvent besoin — par défaut Vue d’ensemble, Pages, Contenus, Validation,
Traduction et Médias. **« Tous »** à la fin ouvre toutes les stations par groupe (« Rédaction », « Processus »,
« Affaires », « Extensions ») ; un nombre sur « Tous » additionne ce qui attend dans les autres. L’**épingle** à côté
d’une station détermine si elle figure dans la barre — pour vous, sur tous les appareils. La page sur laquelle vous vous
trouvez figure toujours dans la barre.

## Mode « Configuration »

![Le parcours de configuration avec le rapport de vérification en dessous](bilder/rundgang-einrichtung.webp)

La configuration vous guide à travers le site avec un **parcours** — neuf stations, chacune avec une question :
« Qu’est-ce que le site ? », « À quoi ressemble-t-il ? », « Comment est-il construit ? » jusqu’à « Prêt pour la mise
en ligne ? ». Un anneau foncé signifie « en ordre », un ✗ rouge « il manque quelque chose ici ». En dessous figure le
**rapport de vérification** avec dix vérifications. Pour l’instant, vous n’avez pas besoin de la configuration : le
thème Starter est entièrement configuré. Plus d’informations sous
[Configuration](../einrichtung/README.md).

## Mode « Administration »

![L’administration avec la liste des sites](bilder/rundgang-verwaltung.webp)

Ici, vous gérez l’installation : **Sites** avec leurs langues et domaines, **Modules**, **Utilisateurs** et **Rôles**,
**Paramètres**, **Mises à jour**, **Licence**, **Exploitation** et le **Journal** de toutes les modifications. Par
défaut, la barre affiche Sites, Utilisateurs, Paramètres et Mises à jour ; **« Tous »** montre le reste par groupe
(« Site », « Personnes », « Système », « Surveillance », « Extensions »), l’épinglage fonctionne comme en mode
« Contenus ». Plus d’informations sous [Administration](../verwaltung/README.md).

## Enregistrer

Ce que vous modifiez s’enregistre de lui-même — il n’y a pas de bouton « Enregistrer ». En bas à droite s’affiche
brièvement « … enregistré », avec une coche au champ ; une erreur reste affichée jusqu’à ce qu’elle soit corrigée. Un
champ refusé ne retient pas les autres.

- **Immédiatement :** interrupteurs, sélections, options et dates.
- **Après une courte pause :** texte et nombres, au plus tard en quittant le champ.
- **En quittant le champ ou avec Entrée :** identifiants, adresses, adresses e-mail, clés et prix — une valeur à moitié saisie
  s’appliquerait aussitôt. Tant qu’il attend, le champ indique « Entrée ou quitter le champ enregistre » ; **Échap**
  annule la modification.

**Nouvelles entrées et paramètres vides :** tant que des champs obligatoires manquent, le formulaire n’enregistre pas
encore. Sous le formulaire figure ce qui manque, en bas à droite « Pas encore enregistré ». Seul un champ que vous avez
déjà modifié est marqué en rouge. Dès que tous les champs obligatoires sont remplis, il s’enregistre de lui-même.
Basalto vérifie le format habituel nom@entreprise.ch des **adresses e-mail** en quittant le champ.

Un bouton reste là où quelque chose de nouveau est créé (**« Créer »**) ou une étape est délibérée : soumettre,
valider, envoyer, payer, supprimer. Le texte de ces formulaires est conservé comme brouillon dans le navigateur
jusqu’à son envoi.

## L’aide

![L’aide sous forme de barre sur le bord droit, à côté de l’arborescence](bilder/rundgang-hilfe.webp)

- Le **« ? »** rond en haut à droite ouvre l’aide qui correspond à la page actuelle — sous forme de barre sur le bord
  droit. La page derrière reste utilisable.
- C’est plus rapide avec la touche **?** ou **F1**.
- En haut de la barre, vous cherchez dans toute l’aide ; **« Ouvrir comme page »** affiche l’aide en grand,
  **« Raccourcis clavier »** liste tous les raccourcis.

## En cas de problème

| Vous voyez | Cause | Solution |
| --- | --- | --- |
| « Configuration » ou « Administration » manque | Votre rôle n’autorise pas ce mode. | Demander à la personne qui administre votre site. |
| Un bouton est gris, avec une raison à côté | Votre rôle ne peut pas faire cette étape, par exemple « Votre rôle ne valide pas ». | Laisser l’étape à la personne responsable ou faire modifier le rôle. |
| La connexion demande un code à chaque fois | Votre compte n’a pas encore de passkey. | Dans l’aperçu général, choisir « Configurer une passkey ». |

## Pour continuer

- [La première page](erste-seite.md)
- [Aide pour la rédaction](../redaktion/index.md)
