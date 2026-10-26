# Gestion des commerces — Mairie Côte d’Ivoire — Phase 1

## État
Les cinq archives sont fusionnées dans `backend/` et `frontend/`. **Ce dépôt n’est pas encore une application Symfony autonome** : les archives d’origine ne contiennent pas le squelette Symfony (`composer.json`, `bin/console`, `public/index.php`, etc.). Il faut créer ce squelette et y intégrer le dossier `backend/src`, `backend/config` et `backend/migrations` avant de lancer Docker. Aucun test d'exécution n'a été réalisé.

## Préparation
1. Créer un projet Symfony compatible PHP 8.4 dans un dossier temporaire : `composer create-project symfony/skeleton backend-base` (depuis la racine de ce projet).
2. Copier les fichiers de `backend-base/` vers `backend/` sans écraser les modules déjà fusionnés (en cas de conflit, fusionner manuellement les configurations). Exemple : `cp -an backend-base/. backend/` puis examiner `backend/config`.
3. Depuis `backend/`, installer `symfony/orm-pack symfony/security-bundle symfony/validator symfony/messenger symfony/redis-messenger doctrine/doctrine-migrations-bundle` avec Composer. Installer aussi les dépendances nécessaires à la version Symfony retenue.
4. `cp .env.example .env`, remplacer les secrets, puis `docker compose build`.
5. `docker compose run --rm php composer install`, puis `docker compose run --rm php php bin/console doctrine:migrations:migrate`.
6. `cd frontend && npm install && npm run build && cd ..` (Node.js requis sur WSL).
7. `docker compose up -d`, ouvrir http://localhost:8080.
8. Créer un administrateur : `docker compose exec php php bin/console app:user:create admin@example.ci --admin`.
9. Vérifier `docker compose exec php php bin/console doctrine:schema:validate` et les commandes `app:invoices:generate --dry-run` / `app:invoices:issue --dry-run`.

## Contrôles obligatoires avant production
- Vérifier les dépendances PHP et Symfony, le bon fonctionnement des migrations, les contrôles d'accès et la protection CSRF.
- Ajouter des tests de paiement concurrent et d'idempotence.
- Mettre HTTPS, sauvegardes, supervision, secrets hors dépôt, et restreindre les rôles de régie.
- Définir les règles de comptabilité publique ivoirienne avec la mairie et son comptable.
- Le worker ne doit être démarré qu'une fois la migration appliquée.
