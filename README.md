# buyFromUs API

A Symfony 8.1 + API Platform backend for the buyFromUs e-commerce application. It exposes a JSON API for browsing products, managing baskets and wishlists, placing orders, applying promo codes, and handling user authentication.

## Tech stack

- **PHP 8.2+**, **Symfony 8.1**, **API Platform 4.3** (Doctrine ORM)
- **MySQL** (via Doctrine, containerized with Docker Compose)
- **Lexik JWT** for authentication + **Gesdinet JWT Refresh Token** for token refresh
- **Scheb 2FA** (TOTP) for two-factor authentication
- **Google API Client** for Google OAuth login
- **Brevo** for transactional email, with a queued email pipeline (`EmailQueue` entity + `ProcessEmailQueueCommand`)
- **Mercure** for real-time updates
- **Google Cloud Storage** for media/file uploads
- **Doctrine Encryption Bundle** for encrypting sensitive fields at rest

## Domain overview

- **Catalog**: `Product`, `Category`, `Subcategory`, `Photo`
- **Shopping**: `Basket`, `BasketItem`, `Wishlist`, `ShoppingRequest` (with a guest basket flow via `GuestBasketService`)
- **Checkout**: `Order`, `OrderItem`, `Payment`, `PaymentMethod`, `PromoCode`, `PromoCodeUsage`, driven by `OrderService`, `PriceCalculator`, `PromoCodeService`
- **Users & auth**: `User`, `TrustedDevice`, `RefreshToken`, Google OAuth (`GoogleAuthenticator`), 2FA
- **Currency**: `CurrencyConverter` / `ExchangeRateApiService` with a scheduled `CurrencyRateUpdateCommand`

Custom API Platform operations live under `src/Controller` (e.g. `PlaceOrderController`, `CheckPromoCodeController`, `CancelOrderController`), with corresponding state processors/providers in `src/State` and `src/DataProvider`.

## Getting started

### Prerequisites

- PHP 8.2+ with the extensions required by Symfony/Doctrine
- Composer
- Docker (for PostgreSQL and Mercure via `compose.yaml`)
- Symfony CLI (recommended)

### Setup

```bash
# Install PHP dependencies
composer install

# Copy env files and fill in secrets (DB credentials, JWT keys, API keys, etc.)
cp .env .env.local   # if not already present, then edit .env.local

# Start local services (MySQL, Mercure)
docker compose up -d

# Run database migrations
php bin/console doctrine:migrations:migrate

# Start the dev server
symfony server:start
# or
php -S 127.0.0.1:8000 -t public
```

### Key environment variables

Configured in `.env` / `.env.local` (never commit real secrets):

- `DATABASE_URL` — MySQL connection string
- `JWT_SECRET_KEY`, `JWT_PUBLIC_KEY`, `JWT_PASSPHRASE` — Lexik JWT keypair
- `MERCURE_URL`, `MERCURE_PUBLIC_URL`, `MERCURE_JWT_SECRET` — Mercure hub
- `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_API_KEY` — Google OAuth
- `BREVO_API_KEY`, `MAILER_DSN` — transactional email
- `EXCHANGE_RATE_API_KEY`, `TARGET_CURRENCY`, `TAXE_RATE` — currency/pricing
- `GCP_KEY_FILE_PATH`, `GCS_BUCKET_NAME` — Google Cloud Storage uploads
- `DOCTRINE_ENCRYPTION_ENCRYPT_KEY` — field-level encryption key

## Useful commands

```bash
# Run pending Doctrine migrations
php bin/console doctrine:migrations:migrate

# Process the email queue (also run periodically, e.g. via cron)
php bin/console app:process-email-queue

# Update currency exchange rates
php bin/console app:currency-rate-update

# Static analysis
vendor/bin/phpstan analyse

# Code style check / fix
vendor/bin/php-cs-fixer fix --dry-run --diff
vendor/bin/php-cs-fixer fix
```

## Deployment

Pushes to `main` trigger `.github/workflows/deploy.yml`, which SSHes into the Hostinger production server, pulls the latest code, installs dependencies, dumps the prod env, runs migrations, and warms the cache.

## API documentation

API Platform automatically serves interactive API docs (Swagger UI / ReDoc) at `/api/docs` once the app is running.
