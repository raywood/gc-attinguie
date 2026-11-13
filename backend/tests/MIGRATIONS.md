# Migrations isolées — mission 10B, étape 1

## Ce qui est préparé, sans exécution réelle

Le profil migration est indépendant du profil runner. Les contrôles historiques
TestEnvironmentGuard, DatabaseIdentityGuard et PostgresIdentityProbe restent
inchangés : check-identity exige toujours gc_test_runner.

Le service migration-runner est ponctuel, sous le profil Compose `migration`.
Il reprend les montages sélectifs en lecture seule, le réseau interne test, les
tmpfs et les restrictions du runner. Aucun port, socket Docker, fichier .env
réel ou identifiant de développement/production n'est ajouté. Aucun service
dépendant n'est démarré automatiquement. Les identifiants littéraux sont de test.
Le script de provisionnement n'est pas monté dans un service et ne fait pas
partie des scripts d'initialisation automatique.

Deux commandes sont implémentées dans tests/bin/integration :

- check-migration-offline : profil exact et manifeste des 17 fichiers ; aucune
  construction de connexion, aucun appel à up(), aucun noyau ni Dotenv.
- check-migration-identity : commande en ligne réservée à une prochaine mission.
  Le profil est revalidé ; une unique connexion DBAL explicite est fournie à
  Doctrine Migrations via ExistingConnection. Ses paramètres et son identité
  objet sont vérifiés avant accès réseau et après les lectures. Les délais sont
  bornés : connexion PDO 3 s, statement 10 s, lock 3 s, transaction inactive 10 s.
  Une transaction READ ONLY et un verrou consultatif transactionnel non bloquant
  (826043, 1) protègent le contrôle ; rollback obligatoire, même après échec.
  Elle valide aussi le dépôt Doctrine et exige un schéma public entièrement vide
  (relations, fonctions et types). Elle ne crée pas la table de suivi Doctrine.

prepare et reset restent toujours désactivées pour les deux profils. Aucun
exécuteur de migrations ou nettoyage n'est appelé. Les commandes arbitraires,
arguments supplémentaires et configurations contradictoires sont refusés.
Un contrôle réussi n'est pas mémorisé comme autorisation d'écriture.

## Provisionnement futur — autorisation distincte obligatoire

1. Inspecter le vrai conteneur db-test : projet gc-attinguie-test, réseau interne
   unique, volume dédié, aucun port publié, aucun montage de données réelles.
2. Rejouer le contrôle runner et vérifier le marqueur protégé en lecture seule.
   Inspecter public : aucun objet applicatif ne doit déjà exister. Examiner les
   comptes et droits effectifs ; ne jamais prendre des droits attendus pour des
   droits réellement vérifiés.
3. Examiner docker/test/provision-test-migrator.sql. Il exige la base de test,
   current_user=session_user=gc_test_admin, PostgreSQL 16, le marqueur et ses
   propriétaires attendus, l'absence de migrateur et d'objets applicatifs. Il
   refuse les bases inattendues dans le cluster.
4. Après autorisation explicite, appliquer ce seul script depuis le conteneur
   db-test, sans redémarrage et avec arrêt sur erreur. Commande future uniquement :

```bash
COMPOSE_DISABLE_ENV_FILE=1 docker compose --env-file /dev/null -p gc-attinguie-test -f compose.test.yaml exec -T db-test psql -X -v ON_ERROR_STOP=1 -U gc_test_admin -d gc_attinguie_test < docker/test/provision-test-migrator.sql
```

Le script prépare un rôle LOGIN non administrateur, NOINHERIT, sans membership,
CREATEDB, CREATEROLE, REPLICATION ou BYPASSRLS. Il accorde directement CONNECT à la
base de test, USAGE/CREATE dans public, USAGE de plpgsql et lecture seule du marqueur.
Il ne transfère ni la base, ni public, ni isolation_meta au migrateur.
Il ne modifie aucun droit direct, hérité ou effectif de gc_test_runner ou PUBLIC :
aucune révocation, attribution à un rôle existant, modification d'appartenance,
de propriétaire ou de privilèges par défaut. Seuls les paramètres du nouveau
rôle sont configurés ; son existence préalable provoque un refus.
Les futures tables/fonctions applicatives appartiendront au migrateur.
Les privilèges par défaut des futures fonctions ne sont pas modifiés ; PostgreSQL
accorde normalement EXECUTE à PUBLIC. La création des fonctions n'est pas réalisée
ici. Leur politique d'accès devra être revue séparément avant toute migration,
sans supposer que PUBLIC en a été privé par ce provisionnement.
Aucun accès DML global n'est accordé au runner ici.

### Politique ACL bornée — mission 10B.2C

L'audit réel 10B.2B a observé PostgreSQL 16.15, le migrateur absent, le runner
non administrateur sans appartenance à un autre rôle et le marqueur protégé.
Les plafonds PUBLIC suivants sont désormais acceptés sans modifier les ACL :

| Base | datallowconn attendu | Plafond des droits PUBLIC |
| --- | --- | --- |
| gc_attinguie_test | true | TEMPORARY |
| postgres | true | CONNECT, TEMPORARY |
| template0 | false | CONNECT (ACL seulement, connexions désactivées) |
| template1 | true | CONNECT |

Les quatre bases doivent être présentes, appartenir à gc_test_admin et aucune
base supplémentaire n'est admise, même désactivée. Les droits PUBLIC peuvent
être plus restrictifs que ces plafonds, sans réattribution automatique. CREATE
sur une base, une option de délégation ou un grantor inattendu provoquent un refus.
Le seul droit direct du migrateur sur une base est CONNECT sur gc_attinguie_test.
Les droits effectifs CONNECT/TEMPORARY/CREATE doivent correspondre exactement à
l'union de ses ACL directes et de PUBLIC ; les appartenances restent interdites.
Les ACL NULL sont développées avec acldefault, jamais assimilées à une absence
de droits. Aucune connexion aux bases système n'est réalisée pour les inspecter.

Sur les objets inspectés : PUBLIC peut seulement avoir USAGE sur le schéma public
et sur plpgsql, sans délégation. Aucun droit PUBLIC sur isolation_meta, sa table
ou ses colonnes n'est accepté. Les droits directs du migrateur sont exactement
USAGE/CREATE sur public, USAGE sur isolation_meta et plpgsql, SELECT sur le marqueur,
sans délégation. Le schéma public doit appartenir à pg_database_owner ; le marqueur,
son schéma et plpgsql à gc_test_admin. Les ACL proviennent du propriétaire attendu.
Les droits directs inattendus sur relations, fonctions, colonnes, autres schémas
ou langages sont également refusés dans ce précontrôle de schéma initial vide.
Aucun changement de droits de gc_test_runner ou PUBLIC n'est nécessaire ni permis.

La sonde MigrationContext relève sur la même connexion DBAL : inventaire des bases,
propriétaires, datallowconn, droits PUBLIC/directs, grantors, options de délégation
et droits effectifs. Elle relève aussi les ACL des objets autorisés et les protège
avec MigrationAclPolicy. Les contrôles d'identité, d'attributs administrateur,
d'appartenance, de propriété et d'écriture du marqueur restent stricts.
Le script SQL applique les plafonds PUBLIC et la politique de propriétaires avant
CREATE ROLE ; la sonde complète ensuite la vérification des droits du rôle créé.

### Capacité résiduelle et limites

Les permissions publiques tolérées sont des exceptions de compatibilité avec
l'instance observée, pas des permissions supplémentaires nécessaires aux migrations.
Les futurs identifiants migrateur permettront techniquement CONNECT à postgres et
template1, et TEMPORARY sur postgres/la base de test. NOINHERIT ne neutralise pas
PUBLIC. Le lanceur refuse pourtant toute destination autre que db-test:5432/
gc_attinguie_test et n'interroge les ACL système que depuis cette base vérifiée.
Le réseau reste interne, sans port publié ni accès aux environnements réels.

Cela ne garantit pas qu'une personne détenant ces identifiants ne puisse pas
utiliser un autre client pour atteindre une base système de la même instance.
Une exigence absolue d'absence de cette capacité resterait incompatible avec la
conservation des ACL PUBLIC actuelles et demanderait une décision distincte.
prepare/reset restent désactivées ; le succès d'un contrôle n'autorise aucune
écriture et aucun profil runner n'est assoupli.

Les nouveaux relevés SQL et le grantor effectif des futurs droits du migrateur
restent à vérifier en lecture seule lors d'une mission autorisée après provisionnement.
Les fixtures simulent les ACL publiques observées et les attributions directes
prévues ; elles ne prétendent pas représenter un rôle déjà créé.

Le script n'est volontairement pas idempotent : rôle existant, base inattendue
ou schéma non vide imposent une revue manuelle. Ne pas modifier un rôle existant
silencieusement, supprimer le volume ou rejouer init-test-db.sql pour contourner
un refus. Le SQL et ses effets réels ne sont pas encore validés sur PostgreSQL.

## Vérifications futures en ligne

Après provisionnement autorisé, exécuter exclusivement avec le Compose de test :

```bash
COMPOSE_DISABLE_ENV_FILE=1 docker compose --env-file /dev/null -p gc-attinguie-test -f compose.test.yaml run --no-deps -T migration-runner tests/bin/integration check-migration-offline
COMPOSE_DISABLE_ENV_FILE=1 docker compose --env-file /dev/null -p gc-attinguie-test -f compose.test.yaml run --no-deps -T migration-runner tests/bin/integration check-migration-identity
```

La commande en ligne vérifie base, utilisateurs courant/de session, PostgreSQL
16, schéma courant et search_path public ; aucune appartenance à un autre rôle,
aucun attribut élevé ni propriété de base/schéma ; ACL de bases bornées ci-dessus ; droits
CREATE/USAGE applicatifs et langage requis. Le marqueur doit être une table
ordinaire appartenant à gc_test_admin dans un schéma du même propriétaire,
lisible mais sans droit d'écriture, REFERENCES, TRIGGER, création dans le schéma
ou appartenance au propriétaire. Aucun secret du pilote n'est imprimé en erreur.

Cette identité est contrôlée sur la connexion réellement injectée dans Doctrine,
sans démarrer Symfony. La configuration Doctrine Symfony du profil runner reste
fixée au runner ; elle n'est pas utilisée pour les migrations. Les connexions
alternatives, URL héritées, suffixes et résolutions par défaut sont exclus.

## Inspection du plan et résultat attendu

migrations.manifest.json contient versions ordonnées, SHA-256 et suppressions
d'index approuvées. MigrationPlan refuse fichier manquant/supplémentaire, lien
symbolique, changement de contenu/version/ordre ou suppression non prévue :

- 20261026145526 et 20261026150204 : uniq_business_primary_manager, recréé ;
- 20261112221500 : idx_gc_billing_business_date, sous précondition d'index unique.

Le manifeste est une référence de revue, pas une signature cryptographique contre
quelqu'un pouvant modifier le dépôt. Tout changement exige une nouvelle revue.
Le dépôt Doctrine doit découvrir exactement les 17 versions autorisées. Leur
instanciation ne doit jamais être confondue avec l'appel à up().

L'état initial doit être vide, y compris le suivi Doctrine. Un état partiel ou
déjà migré bloque cette première préparation ; aucune réparation ou reprise
automatique n'est implémentée. MigrationPlan::assertCompletedState prépare le
contrôle simulé des 22 tables plus doctrine_migration_versions et des 17 versions
exactes, sans initialisation des métadonnées.

Attention : migrate --dry-run n'est PAS strictement sans écriture. La version
installée initialise potentiellement le stockage de métadonnées et appelle les
méthodes PHP des migrations. Les lectures directes peuvent aussi échouer lors
d'une simulation globale sur base neuve. Ne pas l'utiliser comme contrôle sûr.

## Avant toute ouverture future de prepare

- Vérifier le même profil et la même identité DBAL dans le processus d'exécution.
- Prendre le même verrou transactionnel pour toute la préparation ; pas un
  contrôle isolé dont le rollback libère déjà le verrou. Refuser un autre acteur.
- Exiger le schéma initial vide et le manifeste intact, sélectionner uniquement
  les montées jusqu'à Version20261112234500. Aucune commande/option arbitraire.
- Démarrer explicitement la transaction d'exécution, garder la même connexion et
  le verrou jusqu'aux contrôles finaux. all_or_nothing/transactional sont déjà
  préparés dans la factory, pas encore utilisés pour exécuter des migrations.
  La transaction READ ONLY de check-identity ne devient jamais une transaction
  d'écriture par réutilisation d'un ancien succès.
- Après exécution, avant commit : revalider identité, marqueur, propriétaires et
  ACL ; vérifier 17 versions, 22 tables, métadonnées et absence d'objets inattendus.
- Compléter les contrôles réels des FK/CHECK/index, de la fonction plpgsql
  gc_guard_receipts et du trigger actif gc_receipts_no_update. Vérifier l'index
  unique de remplacement avant/après suppression de l'index redondant.
- Vérifier les 11 activités, les 4 rôles historiques et le singleton branding ;
  aucun seed de permissions, utilisateur ou traitement financier implicite.
- Vérifier les droits DML applicatifs/USAGE des séquences sans accorder propriété
  ou DDL au runner. Ne pas purger via le schema_filter, qui exclut un audit.
- En cas d'échec, rollback et arrêt. Ne pas engager automatiquement de descente,
  désactivation de trigger, reset, purge ou suppression de volume.

reset reste hors de cette étape. Le propriétaire des objets applicatifs possède
intrinsèquement des droits destructifs : le lanceur ne protège pas contre une
personne qui utilise directement d'autres commandes avec ces identifiants.
Le réseau isolé et les droits PostgreSQL restent indispensables ; le marqueur
ne fournit pas d'authentification cryptographique contre un serveur malveillant.

## Validation sans réseau ni conteneur

Exécuter uniquement PHPUnit unitaire, php -l et docker compose config --quiet.
Les tests construisent aussi une connexion DBAL paresseuse, vérifient
isConnected=false et l'identité de l'objet ; les lectures en ligne sont simulées.
Ils ne créent aucun PDO/socket et ne provisionnent aucun rôle.
La connexion réelle, la syntaxe SQL côté serveur, le timeout effectif et les
privilèges après provisionnement sont réservés à une mission ultérieure.
