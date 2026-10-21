# Relancer les non-inscrits d’un spectacle

Dans les Coulisses, ouvrir **Modifier** sur une date puis **Relancer les non-inscrits**,
à droite de **Qui monte sur scène ?**. Enregistrer les modifications du spectacle
avant de relancer. Le bouton reste désactivé sur une nouvelle date non enregistrée
ou lorsque les réponses sont fermées.

La fenêtre affiche les personnes sans aucune réponse, ayant coché **OK pour la scène**
et au moins un rôle artistique. Les cases sont toutes cochées au départ ; décocher
les personnes à ne pas contacter puis confirmer avec **Envoyer à N personnes**.
Les rôles visibles correspondent au profil, sans restriction aux besoins du spectacle.
Une indisponibilité ou une réponse « si besoin » est déjà une réponse : pas de relance.
Les comptes de démonstration, les adresses `.invalid` / `.test` et les adresses de
remplacement `sdr@sdr.fr` / `sdr@sdr.com` sont exclus.

**OK pour la scène** est un choix distinct des rôles. Il est décoché par défaut et
modifiable dans **Mon profil** ou dans le profil WordPress, juste après les rôles.
Aucun compte existant n’est inscrit automatiquement. Le même attribut est disponible
comme filtre dans les relances utilisateurs générales ; leur interface WordPress
et leur fenêtre dans les Coulisses restent inchangées.

## Email et réponse

Chaque destinataire reçoit un email HTML personnel au style du site, accompagné
d’une version texte. Il rappelle le titre, la date, le lieu, les horaires et les rôles.

- **OK, je me lance !** ouvre une petite fiche sans connexion, avec uniquement les
  rôles actuels du profil. Plusieurs rôles peuvent être proposés avant validation.
- **Je ne suis pas dispo** ouvre une confirmation d’indisponibilité sans connexion.

Une simple consultation du lien, même répétée ou par un outil de sécurité des emails,
ne modifie aucune réponse. Seule une validation explicite par POST l’enregistre.
Le même service de sauvegarde est utilisé par cette fiche et les Coulisses.
Aucune session WordPress n’est créée. Pour modifier une réponse déjà enregistrée,
le membre retrouve le spectacle dans les Coulisses avec sa connexion habituelle.

Le secret aléatoire de 256 bits est lié à une personne et à un spectacle ; seule son
empreinte SHA-256 est conservée avec l’adresse et l’échéance. Il expire au plus tard
après 14 jours ou à la fin du jour du spectacle. Une nouvelle invitation remplace
le secret précédent. Le changement d’adresse, le retrait des rôles ou de l’accès
membre, la fermeture des réponses ou le passage de la date empêchent son utilisation.
Après validation, le lien est marqué utilisé. Il ne peut écraser ni une réponse ni
une sélection existante, même après le retrait ultérieur d’une réponse dans les Coulisses.
Le lien est personnel et peut permettre à son détenteur de répondre : ne pas le transférer.

La fiche ne charge aucun outil de suivi ou script. Elle interdit son intégration dans
un autre site et n’envoie pas le lien dans l’en-tête de provenance des requêtes.

## Fiabilité des envois

L’envoi est réservé aux organisateurs et administrateurs des Coulisses. Un identifiant
de demande rend les répétitions de création idempotentes. Les destinataires confirmés
sont conservés et revérifiés avant chaque email : une nouvelle réponse, un retrait
« OK pour la scène » ou un changement de profil/adresse évite une relance devenue inutile.
Un verrou partagé avec les réponses protège les opérations d’un même spectacle.
Les envois sont traités par lots de cinq ; garder la page ouverte. Une interruption
permet de reprendre les destinataires encore en attente, avec le même auteur.
Une transmission interrompue à l’intérieur d’un email est signalée comme incertaine
et n’est pas renvoyée automatiquement. Le bilan distingue acceptés par WordPress,
échecs, ignorés, incertains et annulés. L’acceptation ne garantit pas la réception.
Les dix dernières campagnes par spectacle et auteur sont conservées. Aucun envoi
n’est planifié en arrière-plan. Les réponses publiques sont limitées à leur lien ;
elles n’autorisent aucune autre opération sur le compte ou sur le spectacle.

## Vérifications locales

Sur une installation `.local`, avec tous les emails interceptés et les données
temporaires nettoyées par les tests :

```sh
wp eval-file wp-content/plugins/sdr-coulisses/tests/event-reminders.php
wp eval-file wp-content/plugins/sdr-coulisses/tests/relances.php
```

Les contrôles couvrent consentement, permissions, sélection, lots et reprises,
réponses avant envoi, liens falsifiés ou expirés, rôles, indisponibilités, rejeux,
fermetures, réponses et sélections existantes, et maintien des réponses habituelles.
