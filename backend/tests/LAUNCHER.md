# Lanceur d'intégration — mission 9A

Point d'entrée : `php backend/tests/bin/integration <commande>` depuis la racine,
ou `php tests/bin/integration <commande>` depuis backend/.
Le script est exécuté avec PHP, aucun chmod ni shell intermédiaire n'est requis.
Il est déjà inclus dans le montage tests/ du runner de compose.test.yaml.

## Commandes

- `check-offline` : valide la configuration stricte avec TestEnvironmentGuard,
  sans connexion, DNS, noyau Symfony ou chargement de .env.
- `check-identity` : commande **en ligne**, à exécuter uniquement lors d'une
  mission ultérieure autorisant une connexion au PostgreSQL de test isolé.
  Elle revalide la destination, ouvre une connexion PDO explicitement dédiée,
  puis lit les catalogues et le marqueur dans une transaction READ ONLY,
  systématiquement annulée. Délais de connexion et de requête limités à 3 s.
- `prepare` et `reset` : **toujours refusées**, même après un check réussi.
  Aucun SQL de migration/nettoyage ni transfert vers bin/console n'est implémenté.
- Toute autre commande ou argument supplémentaire est refusé.

Extension mission 10B étape 1 : `check-migration-offline` et
`check-migration-identity` utilisent un profil migrateur distinct et un bootstrap
séparé. Les exigences du profil runner ci-dessous ne sont pas assouplies.
Voir [MIGRATIONS.md](MIGRATIONS.md) pour le provisionnement futur, la connexion
DBAL partagée avec Doctrine et les contrôles préparés. Aucune écriture autorisée.

Le processus exige les quatre variables de test exactes de compose.test.yaml :
APP_ENV=test, DATABASE_URL dédiée, MESSENGER_TRANSPORT_DSN=in-memory:// et
TEST_TOKEN vide. Les valeurs héritées contradictoires sont rejetées, jamais
remplacées silencieusement. Ne pas charger les .env réels pour les fournir.

## Vérification d'identité préparée, non exécutée en mission 9A

La sonde contrôle current_database(), current_user et session_user, PostgreSQL 16,
les attributs privilégiés du rôle et des rôles dont il est membre (y compris
BYPASSRLS), le marqueur gc-attinguie-isolated-test-v1, les propriétaires admin du
marqueur et du schéma, l'absence de droits d'écriture sur le marqueur, de droit
CREATE sur son schéma et d'appartenance au rôle propriétaire.
Un marqueur absent, illisible ou modifiable entraîne un refus.
Les erreurs du pilote ne sont pas affichées pour éviter d'exposer des secrets.

La sonde ne démarre pas Doctrine : ses paramètres sont explicitement fixés à la
même destination que le garde-fou. Avant de réaliser de futures écritures via
Doctrine, il faudra vérifier aussi sa connexion effective après fusion de la
configuration et utiliser la même connexion vérifiée pour l'opération.
Le succès de check-identity n'est pas une permission persistante : aucun fichier,
jeton, variable d'environnement ou drapeau ne mémorise une autorisation.
L'implémentation future de prepare/reset devra refaire tous les contrôles dans
son propre processus, sans changement de connexion après vérification.

## Prérequis et limites

Préserver les prérequis et contrôles réseau de INTEGRATION.md : pile autonome,
réseau interne, aucun port publié, aucun secret réel, compte runner dédié et
initialisation du marqueur par init-test-db.sql sur un volume vide.
Le nom DNS db-test et le marqueur ne constituent pas une authentification
cryptographique du serveur. La sonde est réservée au réseau Docker de test,
pas à l'hôte ni à un réseau non fiable. Ne pas lancer check-identity maintenant.

Le lanceur ne peut pas empêcher une personne d'invoquer directement les commandes
Symfony avec d'autres identifiants. Il ne remplace pas l'isolation réseau, les
droits PostgreSQL et la revue des configurations. Les contrôles de privilèges
ne prétendent pas auditer toutes les fonctions SECURITY DEFINER du serveur.

Les tests unitaires injectent exclusivement une sonde simulée : ils valident
l'ordre des contrôles et les refus sans créer de PDO ni ouvrir de socket.
La syntaxe SQL, les privilèges PostgreSQL effectifs et la gestion des erreurs
réseau restent à valider en environnement isolé lors d'une mission ultérieure.
