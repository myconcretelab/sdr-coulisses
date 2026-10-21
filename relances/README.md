# SDR — Relances utilisateurs

Plugin WordPress indépendant, activable dans Extensions, livré dans le dépôt
`sdr-coulisses` afin de bénéficier du déploiement existant `~/bin/update sdr`.
Son point d’entrée est `sdr-relances.php` ; son activation est distincte de celle
des Coulisses. WordPress reconnaît les deux fichiers de plugin dans ce dossier.

## Utilisation

Dans les **Coulisses**, ouvrir **Côté organisation → Relances utilisateurs**.
L’outil s’ouvre dans une fenêtre réservée aux organisateurs et administrateurs.
La page **Comptes → Relances utilisateurs** (ou **Utilisateurs** selon la traduction
WordPress) reste disponible pour les administrateurs pouvant lister les comptes.
L’organisation n’obtient aucun droit d’administration des comptes WordPress.

Les deux accès utilisent la même vue PHP, le même éditeur, les mêmes fichiers
JavaScript et la même API. La fenêtre charge cette vue dans un cadre de même
origine, avec une mise en page adaptée aux Coulisses. Aucun filtre, variable ou
traitement d’envoi n’est dupliqué. Le plugin de relances doit rester activé ;
sinon son entrée n’apparaît pas dans les Coulisses.

1. Choisir le rôle, rechercher un nom ou un email et cocher les filtres. Choisir
   « tous » pour cumuler les conditions ou « au moins un » pour les réunir.
2. Choisir éventuellement un spectacle. Le filtre « Sans réponse » inclut
   uniquement les comptes n’ayant aucune réponse ; une indisponibilité est une réponse.
3. Sélectionner quelques utilisateurs, la page courante ou tous les résultats.
   Tout changement des critères vide la sélection pour éviter des destinataires masqués.
4. Composer l’objet et le texte enrichi. Le menu des variables les insère dans
   le dernier champ utilisé ; on peut aussi les saisir sous la forme `{{prenom}}`.
   Les variables fonctionnent aussi dans les liens, par exemple `{{lien_profil}}`.
5. Choisir une personne sélectionnée dans l’aperçu. Les variables inconnues ou
   liées à un spectacle manquant bloquent l’envoi. Le test est adressé uniquement
   à la personne connectée, avec les valeurs de la personne prévisualisée.
6. Confirmer l’envoi. Chaque destinataire reçoit un email séparé via `wp_mail`.
   Garder l’onglet ouvert : les messages sont transmis par lots de cinq. Si l’onglet
   est fermé ou la connexion interrompue, rouvrir la page et cliquer sur Reprendre.
   Annuler ne supprime pas les messages déjà transmis. Fermer la fenêtre des
   relances conserve sa sélection, son texte et un éventuel envoi en cours tant
   que la page des Coulisses reste ouverte. La touche Échap et le bouton de
   fermeture permettent de revenir aux Coulisses.

L’objet, le texte et le spectacle du brouillon sont enregistrés automatiquement
pour chaque organisateur ou administrateur. Les dix dernières campagnes sont conservées pour la
reprise et le bilan ; la dernière est affichée dans l’interface. Les campagnes
n’ont pas d’envoi automatique programmé. Une réponse positive de `wp_mail` indique
l’acceptation par WordPress, pas la réception effective par le destinataire.

Les états sont sauvegardés avant la transmission. Un message dont la transmission
a été interrompue n’est jamais renvoyé automatiquement ; le bilan demande de
vérifier sa réception. Un verrou MySQL empêche deux onglets de traiter le même lot.
Les utilisateurs supprimés et les adresses modifiées après confirmation sont
signalés en échec. Les envois et annulations sont réservés à l’auteur de la campagne.

Le filtre **OK pour la scène** reprend le choix de contact renseigné dans le profil
WordPress ou dans **Mon profil** des Coulisses. Il ne se déduit pas des rôles artistiques.

## Connexions et profils

Le plugin suit les nouvelles connexions et les comptes créés après son activation.
Il reprend aussi les sessions WordPress et les métadonnées
`_iu_premiere_connexion` / `_iu_derniere_connexion` de l’extension d’invitations.
L’absence d’historique sur un ancien compte ne prouve pas l’absence de connexion :
le filtre « Aucune connexion connue » reste donc distinct de « Jamais connecté
(comptes suivis) ». La biographie WordPress et l’ancienne biographie SDR sont
reconnues ; la photo est celle choisie dans `sdr_profile_photo`.
Les comptes marqués `sdr_demo` sont exclus.

Les variables de spectacle utilisent la date `_sdr_date` et le fuseau WordPress.
`{{jours_avant_spectacle}}` compte les jours calendaires : 0 aujourd’hui, valeurs
négatives après le spectacle, y compris lors des changements d’heure.
`{{prenom}}` utilise le nom affiché si le prénom est absent.

## Ajouter un filtre

Un filtre ne nécessite aucune modification de l’interface. La liste et ses compteurs
sont générés depuis le registre serveur. Le callback doit seulement lire les données.

```php
add_filter('sdr_relances_filters', function ($filters) {
    $filters['no_experience'] = [
        'label' => 'Expérience non renseignée',
        'matches' => static fn(WP_User $user, ?WP_Post $event) =>
            get_user_meta($user->ID, 'sdr_experience', true) === '',
    ];
    return $filters;
});
```

Ajouter `'event' => true` si le callback nécessite un spectacle de référence.
La liste est chargée de manière asynchrone et paginée dans le navigateur, par
20 profils. Le registre évalue les profils côté serveur : adapté à la taille de
la troupe. Pour plusieurs dizaines de milliers de comptes, prévoir une pagination
et des filtres SQL côté serveur.

## Ajouter une variable

```php
add_filter('sdr_relances_variables', function ($variables) {
    $variables['ville'] = [
        'label' => 'Ville du membre',
        'resolve' => static fn(WP_User $user, ?WP_Post $event) =>
            get_user_meta($user->ID, 'ville', true),
    ];
    return $variables;
});
```

Le callback renvoie du texte brut : le moteur échappe les valeurs, conserve le
texte enrichi autorisé par WordPress et utilise le même rendu pour l’aperçu et
l’email. Ajouter `'event' => true` pour les variables exigeant un spectacle.

## Vérifications

Sur une installation **locale .local uniquement**, activer les deux plugins puis :

```sh
wp eval-file wp-content/plugins/sdr-coulisses/tests/relances.php
```

Ces tests créent des comptes et un spectacle temporaires, interceptent tous les
emails et nettoient leurs données. Ils vérifient les permissions REST, filtres,
variables, reprises, échecs, annulations et absence de double envoi. Ils couvrent
aussi les accès organisateur sans droits de gestion des comptes, les brouillons
personnels et l’isolation des campagnes entre organisateurs et administrateurs.
