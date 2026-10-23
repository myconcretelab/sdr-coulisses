# Modèles d’emails dans Gutenberg

Dans l’administration WordPress, ouvrir **Modèles d’emails**. Quatre modèles de départ
sont créés automatiquement, sans envoyer de message : annonce d’un spectacle,
relance ciblée, confirmation d’équipe, enveloppe des messages libres.

## Modifier et choisir un modèle

1. Ouvrir le modèle souhaité dans Gutenberg.
2. Modifier les blocs, puis l’objet et l’aperçu de boîte de réception dans
   **Email · Contenu et usage**. Le titre WordPress sert uniquement à retrouver le modèle.
3. Dans **Email · Aperçu et essais**, choisir une situation fictive ou un spectacle
   et une personne. Vérifier les versions ordinateur, mobile et texte.
4. **M’envoyer cet aperçu** l’envoie exclusivement à l’adresse personnelle du compte
   administrateur connecté. Les boutons de réponse restent inactifs. Un aperçu ne
   crée, remplace ou consomme aucun lien personnel et ne modifie aucune inscription.
5. Enregistrer le modèle. Pour un nouveau modèle, le publier puis cliquer sur
   **Utiliser pour les nouveaux envois**, ou le sélectionner dans **Modèles utilisés**.

Enregistrer un modèle déjà utilisé applique ses changements aux prochains envois.
Les campagnes commencées gardent leur copie complète du modèle. Publier ou activer
un modèle n’envoie aucun email à la troupe. Les brouillons incomplets sont autorisés ;
la publication et l’activation demandent un modèle valide. Les révisions WordPress
conservent aussi l’objet, le type et les réglages de présentation.

Pour créer une variante, choisir son type puis **Charger le modèle de départ**.
Le remplacement est annulable avec l’historique de l’éditeur. Les modèles sont des
fiches privées, réservées aux administrateurs. Les organisateurs les utilisent
lors de leurs envois habituels, sans pouvoir changer ces modèles globaux.

## Blocs disponibles

Textes enrichis, titres, images de la médiathèque ou distantes, boutons, sections
avec fond/bordure/espacement, une à trois colonnes, séparateurs, espaces,
informations du spectacle, besoins encore recherchés, sections conditionnelles,
message libre et note fonctionnelle des liens de réponse.

Les blocs **Bouton email** proposent des destinations adaptées : répondre partant,
répondre indisponible, Coulisses du spectacle, profil, fiche publique, billetterie
ou adresse personnalisée. Les liens personnels sont fabriqués uniquement lors de
l’envoi réel ; aucune clé ne doit être copiée dans un modèle. Les réponses restent
soumises à la confirmation explicite sur la petite fiche sans connexion.

Les textes, couleurs, tailles, marges, bordures et images se règlent dans la colonne
**Bloc**. La présentation générale se règle dans **Email · Présentation**.
Le rendu emploie des tableaux et des styles intégrés pour les messageries. Certaines
messageries peuvent ignorer les arrondis ou l’empilement des colonnes. Une version
texte avec les liens accompagne tous les envois. Une image bloquée par la messagerie
ne remplace jamais les boutons de réponse, qui restent du texte cliquable.

## Variables et conditions

Écrire `{{prenom}}`, `{{spectacle}}`, `{{date}}`, `{{role_retenu}}`,
`{{besoins_cibles}}`, etc. La liste complète apparaît dans **Email · Variables disponibles**.
`{{lieu|Lieu à préciser}}` fournit un secours si la valeur est absente. Le zéro est
conservé comme valeur. Les données sont échappées, sans interprétation récursive.
`{{jours_avant_spectacle}}` est négatif après la date et vaut zéro le jour même.
Les alias des variables déjà connues des relances utilisateurs sont disponibles.

Une **Section conditionnelle** contient d’autres blocs et se règle avec **Toutes
les conditions (ET)** ou **Au moins une condition (OU)** ; **Inverser le résultat**
exprime « sauf si ». Imbriquer des sections permet de combiner ces groupes.
Les conditions portent notamment sur les rôles du profil, ceux proposés par l’email,
le rôle retenu, les places manquantes, la réponse existante, le consentement,
l’ouverture des réponses et les informations pratiques disponibles.

Exemple : un texte particulier pour un MC est placé dans une section avec
**Cet email propose MC**. Une autre section peut montrer les informations de troupe
uniquement si **Une troupe est invitée**. Pour les confirmations d’équipe, utiliser
**La personne est retenue comme MC**, qui distingue la sélection des rôles du profil.

Les boutons de réponse doivent rester disponibles pour toute personne pouvant
répondre : utiliser **Les boutons de réponse sont disponibles** pour leur groupe.
Ils sont automatiquement masqués pour un profil non admissible, une personne ayant
répondu ou un spectacle fermé/passé. La note fonctionnelle est ajoutée si nécessaire.
Les modèles de messages libres doivent conserver le bloc **Message libre**, où
s’insère le contenu personnalisé composé dans **Relances utilisateurs**.
Les filtres, variables et brouillons propres à cette interface restent disponibles.

## Cas pris en charge

- Besoins calculés sur les sélections, profil à plusieurs rôles, ciblage des seuls
  rôles manquants, équipe complète et besoins non définis.
- Annonces générales aux volontaires, y compris avant de définir les effectifs.
- Réponses fermées, date passée, réponse déjà enregistrée, profil sans rôle,
  consentement retiré, compte supprimé ou adresse modifiée avant l’envoi.
- Date, lieu, adresse, horaires, format, troupe ou billetterie absents ; image supprimée.
- Accents, apostrophes, caractères HTML et valeurs de secours ; objet sans retour à la ligne.
- Modèle supprimé, dépublié, corrompu ou dont les conditions cachent un bouton
  indispensable : modèle de secours intégré au moment d’envoyer. L’aperçu indique
  les erreurs pour permettre de les corriger ; il ne les masque pas par un secours.
- Reprise de campagne avec la même mise en page malgré une modification ultérieure.
  Les annonces automatiques et confirmations d’une même composition gardent aussi
  leur modèle. Une transmission interrompue n’est pas renvoyée automatiquement.

Seuls les blocs Email sont compilés : aucun shortcode, PHP, script, bloc HTML libre
ou callback de bloc externe. Les conditions n’exécutent pas d’expression libre.
Le modèle est limité à 100 Ko de source, 300 blocs et dix niveaux d’imbrication ;
un email rendu est limité à 90 Ko. Les tests sont dédoublonnés et limités à dix par heure
et par administrateur. La sélection d’aperçu liste les 100 dernières dates par date
et jusqu’à 1 000 comptes, adaptée à la taille de la troupe.

Le plugin indépendant Relances utilisateurs reste utilisable si les Coulisses sont
désactivées : il conserve alors son rendu autonome. Les campagnes antérieures sans
copie de modèle utilisent le modèle intégré à leur reprise.

## Vérifications

Sur une installation `.local` uniquement, tous les emails interceptés :

```sh
wp eval-file wp-content/plugins/sdr-coulisses/tests/email-templates.php
wp eval-file wp-content/plugins/sdr-coulisses/tests/event-reminders.php
wp eval-file wp-content/plugins/sdr-coulisses/tests/event-reminder-needs.php
wp eval-file wp-content/plugins/sdr-coulisses/tests/event-announcements.php
wp eval-file wp-content/plugins/sdr-coulisses/tests/relances.php
wp eval-file wp-content/plugins/sdr-coulisses/tests/stage-bulk.php
```

Les tests temporaires nettoient leurs comptes, modèles, réponses et campagnes.
Ils ne doivent jamais être lancés sur le site distant.
