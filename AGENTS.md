# AGENTS.md — buyFromUs API

Shared project guide for AI coding agents (Claude Code, Codex, Cursor, etc.). Facts here were verified against the repo; if something drifts, fix this file.

## What this project is

**buyFromUs** is an e-commerce backend: a Symfony 8.1 + API Platform 4.3 JSON API (PHP >= 8.2). The storefront/admin frontend is a **separate repository** (reached through `FRONTEND_URL` / CORS); this repo is API only.

Business model: an admin picks products from third-party retailers (Amazon, Walmart, Best Buy, ...), publishes them in the catalog with a margin, and customers buy through buyFromUs. Prices are held in USD (`usdPrice`) and converted to `TARGET_CURRENCY`.

## Two projects, one system

| | **buyFromUs** (this repo) | **scrapper** (microservice) |
|---|---|---|
| Path | `~/Desktop/buyFromUs` | `~/Desktop/scrapper` (separate git repo, outside this one) |
| Role | Public API, catalog, orders, auth | Scrapes retailer product pages, normalizes them, stores results |
| Stack | Symfony 8.1, API Platform, MySQL (prod on Hostinger) | Symfony 8.1, PHP >= 8.4, Doctrine, PostgreSQL (`compose.yaml`) |
| Deploy | GitHub Actions on push to `main` | Run locally / not in this repo's pipeline |

They communicate **only through RabbitMQ** (php-amqplib, *not* Symfony Messenger) and share no database.

### RabbitMQ contract (must match on both sides)

- Exchange `product_exchange` (type `direct`, durable), queue `add_new_product_queue` (durable), routing key `product.routing`.
- Payload: a JSON-encoded normalized `Product`, persistent delivery (`delivery_mode` 2).
- Connection env vars on both sides: `RABBITMQ_HOST`, `RABBITMQ_PORT`, `RABBITMQ_USER`, `RABBITMQ_PASSWORD`, `RABBITMQ_VHOST`.
- Local broker: `docker compose up -d` in this repo starts RabbitMQ 3.13 (AMQP `5672`, management UI `http://localhost:15672`).

Flow when an admin publishes a product that has a `scrappingUrl`:

1. `POST /api/product/publish-product` ([ProductController](src/Controller/ProductController.php)) — `ROLE_ADMIN` only. Saves the `Product`, uploads images to Google Cloud Storage, creates `Photo`s.
2. It dispatches `NewProductAddedMessage(uid)` on Symfony Messenger (queue `async_priority_high`).
3. [NewProductAddedMessageHandler](src/MessageHandler/NewProductAddedMessageHandler.php) normalizes the product (groups `product:read`, `product:read:details`, `product:read:admin`), publishes it through [RabbitMqMessengerService](src/Service/RabbitMqMessengerService.php) to `product_exchange`, and emails `ADMIN_EMAIL` via Brevo.
4. The scrapper's `app:new-products:consume` reads the queue and calls its `ProductService::addNewProduct()` (`Product::hydrate()` + `isGoodForAdd()`). On any failure it stores a `ProductFailure` row (error + payload) and `nack`s **without requeue**.

The scrapper also exposes on-demand endpoints: `GET /api/scrapper?url=` (single product, 2h cache) and `GET /api/batch?urls[]=`. Scraping goes through Bright Data; `DataSyncService` maps Amazon / Best Buy / Walmart responses to one common `Product` shape. Bright Data dataset IDs are chosen by hostname prefix (`amazon.`, `ebay.`, `walmart.`, `bestbuy.`, `nike.`, `adidas.`, `macys.`).

When changing the exchange/queue/routing key, the payload shape, or the `Product` fields/serialization groups, **update both projects together**. Never rename the queue in one repo only.

## Stack and integrations (this repo)

- **API**: API Platform (Doctrine ORM) for resources on entities; custom operations in `src/Controller`, state processors in `src/State`, providers in `src/DataProvider`.
- **Auth**: Lexik JWT + Gesdinet refresh tokens (cookie via `RefreshTokenCookieFactory`), Scheb TOTP 2FA, Google OAuth (`src/Security/GoogleAuthenticator.php`), trusted devices.
- **Email**: Brevo (`BrevoEmailService`) through Messenger messages (`WelcomeMessage`, `OrderConfirmationMessage`, `ResetEmailMessage`, `TwoFactorCodeMessage`) and a DB-backed `EmailQueue` drained by `app:process-email-queue`. Handlers extend `MessageHandlerParentClass`, which receives shared template/site parameters from `config/services.yaml`.
- **Messenger transports**: `async_priority_high|default|low` (DSN `MESSENGER_TRANSPORT_DSN`, queues `high`/`default`/`low`) and a Doctrine `failed` transport.
- **AI**: Gemini via `symfony/ai-bundle` — `GeminiService` (translate content, generate product content from scraped data or from a title). `GeminiAiController`, `app:gemini-test`.
- **Storage**: Google Cloud Storage (`GcsUploaderService`: upload, `uploadDataUri`, `deleteByUrl`; `PhotoService`). Key file path from `GCP_KEY_FILE_PATH`.
- **Scraping inside this repo**: `BrightDataAmazonScraper` (older in-app scraper; the microservice is the direction of travel).
- **Currency/pricing**: `CurrencyConverter`, `ExchangeRateApiService`, `PriceCalculator`, `ProductService::updateLatestPrice()`; `app:currency-rate-update` writes `public/data/currency_rate.json`.
- **Other**: Mercure (real-time), Doctrine field encryption (`DOCTRINE_ENCRYPTION_ENCRYPT_KEY`), `translator` via Google Translate lib.

Domain map: Catalog (`Product`, `Category`, `Subcategory`, `Photo`, `Banner`) · Shopping (`Basket`, `BasketItem`, `Wishlist`, `ShoppingRequest`) · Checkout (`Order`, `OrderItem`, `Payment`, `PaymentMethod`, `PromoCode`, `PromoCodeUsage`) · Users (`User`, `TrustedDevice`, `RefreshToken`) · Misc (`EmailQueue`, `Setting`).

## Commands

```bash
composer install
docker compose up -d                       # RabbitMQ only (DB is configured via DATABASE_URL)
php bin/console doctrine:migrations:migrate
symfony server:start                       # or: php -S 127.0.0.1:8000 -t public

php bin/console messenger:consume async_priority_high async_default async_priority_low -vv
php bin/console app:process-email-queue
php bin/console app:currency-rate-update
php bin/console app:fill-products-uid
php bin/console app:rabbit-mq-test         # publishes one product to product_exchange

vendor/bin/phpunit                          # tests/Security/AuthorizationRegressionTest.php
vendor/bin/phpstan analyse --memory-limit=1G  # level 5, src/ (or: composer phpstan); the default 128M crashes on a cold cache
vendor/bin/php-cs-fixer fix --dry-run --diff
```

Scrapper side (from `~/Desktop/scrapper`): `php bin/console app:new-products:consume` (the real consumer). `app:rabbitmq:consume` is a debug consumer on the **same queue** — don't run both, they would compete for messages.

## Conventions

- Follow `.editorconfig` (4 spaces, LF) and `.php-cs-fixer.dist.php` (`@Symfony` + risky, strict types, short arrays, trailing commas). New files: `declare(strict_types=1);`. A few older files (RabbitMQ service, some handlers/messages) use 2-space indentation; match the file you are editing, don't reformat unrelated code.
- Services in `src/Service` are autowired and public; bind env values in `config/services.yaml`, never read `$_ENV` inside classes.
- Serialization groups drive both the API and the RabbitMQ payload (`product:read`, `product:read:details`, `product:read:admin`). Adding a field to `Product` means deciding which groups expose it and whether the scrapper's `Product` entity needs it.
- Authorization: `security.yaml` leaves `^/api` as `PUBLIC_ACCESS`, so protection is **per operation** (`security:` on `#[ApiResource]` operations, or explicit `ROLE_ADMIN` checks in controllers). Every new endpoint must state its access rule explicitly. `tests/Security/AuthorizationRegressionTest.php` guards against regressions here — extend it when adding sensitive endpoints.
- Roles: `ROLE_USER` (implicit) and `ROLE_ADMIN`. Registration must ignore client-supplied roles.

## Gotchas

- **Deploy uses `doctrine:schema:update --force`** (`.github/workflows/deploy.yml`), not migrations, and `migrations/` is empty. Schema changes ship straight to production on merge to `main` — review entity changes for destructive diffs (dropped/renamed columns) first.
- Deployment: push to `main` → SSH to Hostinger (`apikariaire.in`) → `git reset --hard origin/main`, `composer install --no-dev`, `dump-env prod`, schema update, cache clear/warmup. Work happens on `dev`; do not push to `main` without being asked.
- `.env` is tracked in git and must contain **no real secrets** (currently all empty). Put real values in `.env.local` (ignored). Never commit GCP key files, JWT `.pem` files, or API keys.
- README mentions PostgreSQL in one place and MySQL elsewhere; production is MySQL. `docker-compose.yml` here starts only RabbitMQ.
- Stray duplicate files (`src/Controller/ProductController 2.php`, `src/Service/OrderService 2.php`, `src/Service/UserService 2.php`) look like accidental Finder copies. Don't edit or depend on them; ask before deleting.
- Messenger handlers run in a separate worker process: after changing handler code, restart `messenger:consume`.
- The scrapper has no tests and no README, and its `.env` defaults are blank — check `RABBITMQ_*` and `DATABASE_URL` before debugging "nothing consumed".
