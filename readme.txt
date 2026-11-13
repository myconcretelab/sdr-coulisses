=== Coulisse — Gestion de troupe ===
Contributors: myconcretelab
Tags: theatre, events, members, availability
Requires at least: 6.5
Requires PHP: 8.1
Stable tag: 1.16.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Spectacles, disponibilités, équipes, lieux et emails pour les troupes. Conserve le thème du site et propose un espace membres personnalisable.

== Description ==

Coulisse s’installe sur le WordPress de chaque troupe. Il fournit un agenda public,
un espace membres, la composition des équipes, les invitations et relances ciblées,
des messages libres et une documentation intégrée.

Le nom, le logo, la couleur d’accent et la page des Coulisses se configurent dans
Réglages → Coulisse. Les styles de l’espace privé sont limités à sa page dédiée.
Les blocs publics reprennent les polices et couleurs du site.

Les relances utilisateurs sont intégrées ; un seul plugin est à activer.
Les comptes, réponses et contacts sont stockés dans ce WordPress.

== Installation ==

1. Téléverser le ZIP depuis Extensions → Ajouter une extension, puis l’activer.
2. Ouvrir Réglages → Coulisse, personnaliser l’identité et enregistrer.
3. Créer la page des Coulisses ou choisir une page contenant son bloc.
4. Configurer les comptes Membre de la troupe et Organisation.
5. Vérifier les emails avec l’aperçu adressé à son propre compte administrateur.

== Frequently Asked Questions ==

= Faut-il changer de thème ? =
Non. L’espace privé utilise un gabarit fourni par le plugin. Les pages publiques
conservent le thème choisi. L’habillage SDR reste dans son thème spécifique.

= Le plugin envoie-t-il les emails par un service externe ? =
Il utilise le service d’envoi de WordPress, éventuellement configuré par une extension
SMTP. Aucune donnée n’est transmise à un service central de Coulisse.

= Les mises à jour sont-elles automatiques ? =
La recherche des versions stables sur GitHub peut être activée dans Réglages →
Coulisse. WordPress propose ensuite « Mettre à jour maintenant ». Ce choix ne
déclenche aucune installation et n’active pas les mises à jour automatiques.
L’installation par ZIP reste disponible. Les installations gérées avec Git
conservent leur déploiement habituel.

= Que deviennent les données à la désactivation ? =
Elles sont conservées. Aucun effacement automatique n’est exécuté.

== Changelog ==

= 1.16.0 =
* Ajout à une équipe d’un membre sans réponse, avec son accord et mention de l’organisation.
* Contrôle des rôles du profil et des places, sans email automatique.
* Les membres ajoutés manuellement reçoivent aussi la confirmation avec « Sauvegarder et informer les joueurs ».

= 1.15.7 =
* Réponses et composition de l’équipe réunies dans un cadre commun, avec deux parties distinctes.
* Compteurs, noms au survol, relance et accès à la composition conservés.

= 1.15.6 =
* Compteur des sans réponse et action de relance réunis dans un seul bouton.
* Libellé « Relancer les sans réponse » dans la composition et la fenêtre de relance.
* Noms des personnes au survol des catégories et des segments du graphique de participation, au clavier ou au toucher.

= 1.15.5 =
* Bouton « Relancer les non-inscrits » directement dans le bilan des réponses du planning.
* Cadre de participation et bouton réservés à l’organisation et à l’administration.

= 1.15.4 =
* Compteur des sans réponse limité aux profils « OK pour la scène ».
* Conservation de toutes les réponses déjà reçues dans le bilan de participation.

= 1.15.3 =
* Participation au sondage : réponses sur le total de la troupe et personnes sans réponse.
* Barre plus épaisse à quatre couleurs contrastées, avec les sans réponse en gris hachuré.

= 1.15.2 =
* Candidatures affichées en fraction sur les places prévues dans le planning.
* Total des personnes ayant répondu, avec le détail Partant·e, Si besoin et Pas dispo.

= 1.15.1 =
* Sélection compacte de plusieurs troupes avec recherche par nom ou ville.
* Étiquettes retirables, navigation au clavier et conservation des sélections lors de l’ajout d’une troupe.

= 1.15.0 =
* Recherche facultative des versions stables sur GitHub depuis les réglages.
* Mises à jour proposées dans WordPress, avec vérification de l’empreinte du ZIP.
* Publication du ZIP, de son empreinte et de son manifeste pour chaque version balisée.
* Procédures de mise à jour et de dépannage dans la documentation intégrée.

= 1.14.0 =
* Champ neutre « Troupes participantes » pour les rencontres et spectacles partagés.
* Choix « Avec » ou « VS » pour le titre automatique ; le titre saisi reste prioritaire.
* Les spectacles existants conservent leur présentation jusqu’à modification explicite.

= 1.13.2 =
* Compteurs de candidatures légèrement plus gras dans le planning.

= 1.13.1 =
* Places restant à pourvoir mises en évidence dans le planning.
* Rôles complets en vert et rôles sans besoin masqués ; candidatures et sélections restent visibles.

= 1.13.0 =
* Bouton « Mes disponibilités » dans le planning.
* Création de troupes depuis le formulaire de spectacle, avec sélection de plusieurs troupes.
* Titre saisi prioritaire ; titre automatique si le champ reste vide, dans le planning, les fiches publiques et les emails.

= 1.12.1 =
* Candidatures regroupées par rôle, avec des cartes compactes entièrement cliquables.
* Seuls les rôles prévus apparaissent dans les colonnes de composition.
* Repères et liens graphiques entre les candidatures d’une même personne dans plusieurs rôles.
* Confirmation « Joueurs informés » conservée tant que la composition et les informations pratiques correspondent aux emails transmis.

= 1.12.0 =
* Parcours Bien démarrer et contrôles de configuration dans Réglages → Coulisse.
* Rapport technique sans données de membres, à copier pour un signalement.
* Guides de premier essai, de mise à jour et de retour des troupes pilotes.

= 1.11.0 =
* Identité de troupe et page des Coulisses configurables.
* Styles privés isolés, gabarit indépendant du thème et blocs publics dans le plugin.
* Liens et emails adaptés à chaque troupe, relances intégrées dans une seule installation.
* Compatibilité avec les contenus et données historiques SDR.
