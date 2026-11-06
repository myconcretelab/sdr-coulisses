# Documentation des Coulisses

Pour toute modification du code de ce plugin, relire les guides de
`documentation/guides.json` qui décrivent le comportement touché. Ajouter ou corriger
les procédures, les libellés des boutons, les droits d’accès et les cas d’erreur si
nécessaire. Écrire en français concret, sans métaphores. Une correction interne
peut ne nécessiter aucun changement de texte, mais sa relecture reste explicite.

Les listes des champs de spectacle et de lieu sont générées depuis
`SDR_Coulisses::fields()` : ne pas en créer une seconde liste figée dans les guides.

Après la relecture et les vérifications du code :

1. Exécuter `php tools/check-documentation.php --review` pour enregistrer la
   correspondance entre les guides relus et les sources.
2. Exécuter `php tools/check-documentation.php --check` avant de commiter.
3. Commiter `documentation/review.json` avec les modifications du code et des guides.

Ne pas régénérer le témoin de relecture uniquement pour faire passer un contrôle :
il atteste qu’une vérification du contenu a réellement eu lieu. Le contrôle ne
rédige pas les nouvelles procédures. La mise en ligne reste régie par le fichier
AGENTS.md du projet parent et doit passer par `~/bin/update sdr` après le push.
