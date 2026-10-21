# CLAUDE.md

@AGENTS.md

The shared project knowledge (architecture, the RabbitMQ link to the scrapper microservice, commands, conventions, gotchas) lives in AGENTS.md above. This file adds Claude Code–specific guidance.

## Scope of work

- This repo is `buyFromUs` (Symfony API). The **scrapper microservice** is at `/Users/nassirou/Desktop/scrapper` (separate git repo). You may read it to understand the message contract, but only edit it when the task clearly involves it, and say so when you do. Commits in one repo never include changes from the other.
- Any change touching `product_exchange` / `add_new_product_queue` / `product.routing`, the published payload, or `Product` serialization groups affects both projects — check the scrapper's `src/Entity/Product.php`, `Product::hydrate()` and `src/Command/NewProductsAddConsumerCommand.php` before finishing.

## Working agreements

- Branch is `dev`; `main` auto-deploys to production. Never push, merge to `main`, or run deploy-like commands unless explicitly asked. Commit only when asked.
- Entity changes go live via `doctrine:schema:update --force` on deploy. When you modify an entity, point out any column drop/rename/type change in your summary.
- Don't read, print, or write values from `.env.local`, GCP key files, or JWT keys. Treat `.env` as a template that must stay secret-free.
- Don't touch the working-tree changes already in progress (Product/Photo/GCS/Gemini publishing work) beyond what the task needs, and ignore the `* 2.php` duplicate files unless asked.
- Prefer editing existing services over adding new ones; keep controllers thin and put logic in `src/Service`.

## Verification before saying "done"

1. `vendor/bin/php-cs-fixer fix --dry-run --diff` on touched files.
2. `vendor/bin/phpstan analyse` (level 5) — no new errors.
3. `vendor/bin/phpunit` when touching auth, roles, or access rules (`tests/Security`).
4. For queue-related changes, `docker compose up -d`, then `php bin/console app:rabbit-mq-test` and confirm the scrapper consumer receives it (or state that you could not run it).

If a check can't be run (missing DB, env, or broker), say so instead of reporting success.
