# HMS — Hospital Management System

A modular, scalable Hospital Management System built on Laravel 12, React +
TypeScript (Inertia), Tailwind CSS, and MySQL.

## Stack

- Laravel 12 / PHP 8.2+
- MySQL 8+
- React + TypeScript + Vite (via Inertia)
- Tailwind CSS
- Laravel Sanctum (authentication)
- Spatie Laravel Permission (roles & permissions)
- Pest (testing)

## Status

**Phase 1 — Database Foundation** is implemented: the core hospital, staff,
practitioner, patient, and insurance schema that every future clinical,
laboratory, pharmacy, billing, and NHIF module will build on. See
[`docs/phase1-database-foundation.md`](docs/phase1-database-foundation.md)
for the full schema documentation, ERD, and design rationale.

## Setup

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
```

Configure your database connection in `.env`, then:

```bash
php artisan migrate:fresh --seed
npm run build   # or `npm run dev` / `composer run dev` during development
```

## Verifying the foundation

```bash
php artisan migrate:status
php artisan db:show
php artisan route:list
php artisan test
```

## Running tests

```bash
php artisan test --compact
# or target a single file/case
php artisan test --filter=PractitionerTest
```
