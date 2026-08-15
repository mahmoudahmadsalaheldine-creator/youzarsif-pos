# Youzarsif Sweets Management System

A PHP-based POS (Point of Sale) and business management platform built for Youzarsif Sweets, covering factory production, stock, sales, and multi-store operations.

## Overview

Youzarsif Sweets Management System handles the full operational flow of a sweets manufacturing and retail business, from raw ingredients and production batches, to finished products, multi-location stock transfers, point-of-sale checkout, and financial reporting. It was built to replace manual/spreadsheet-based tracking with a single system that factory staff, accountants, and store cashiers all use day-to-day.

## Tech Stack

- **Backend:** PHP 8 (plain, no framework) with PDO for MySQL access
- **Frontend:** Custom HTML/CSS/JS — a bespoke chocolate/caramel/cream design system built specifically for this brand
- **PDF Generation:** [dompdf](https://github.com/dompdf/dompdf) for exportable reports
- **Email:** SMTP via Gmail (`includes/SmtpMailer.php`) for password reset delivery
- **Database:** MySQL, schema in `DB.sql`
- **Server:** Apache (XAMPP for local development)

## Key Features

### Access & Security
- **Role-based access control** — three distinct roles (Admin, Factory User/Accountant, Cashier), each scoped to what they should see and do
- **Dual login modes** — Admins/staff log in with email or username; cashiers log in with username only and are routed straight to their assigned store's POS
- **Login throttling** — brute-force protection on the login form (`includes/login_throttle.php`)
- **Protected endpoints** — all 17 admin pages and 12 action/POST endpoints require authenticated sessions, preventing direct unauthenticated access
- **Password reset via email** — token-based flow with expiry, delivered through Gmail SMTP

### Inventory & Production
- **Items, categories & units** — full CRUD for raw ingredients, supporting items, and finished products
- **Multi-location stock** — separate stock tracking per factory/store location
- **Stock movements** — auditable log of every stock change
- **Production batches** — track manufacturing runs
- **Product recipes** — link finished products to the raw ingredients/quantities they consume
- **Factory-to-store transfers** — move stock between locations with a dedicated workflow
- **Low-stock alerts** — automatic flagging when items drop below threshold

### Sales & Finance
- **Point of Sale (POS)** — dedicated checkout interfaces for both factory and store contexts
- **Exchange rates** — multi-currency support for pricing/reporting
- **Expense tracking**
- **Break-even analysis**
- **PDF report exports** — generated via dompdf for sharing/printing

### UX
- **Foldable sidebar** — collapses to icon-only, state persisted in `localStorage`
- **Smart filters** — combinable Type/Category/Location filters with live search and one-click reset on Items, Stock Movements, and Finished Products pages

## Roles & Access

| Role | Login Method | Access |
|---|---|---|
| Admin | Email | Full admin panel |
| Factory User / Accountant | Username | Full admin panel |
| Cashier | Username | Scoped to one assigned store — POS only |

## Database Schema (high level)

Defined in `DB.sql`. Core tables include:
- `categories`, `units`, `locations` — reference/lookup data
- `items` — raw ingredients, supporting items, finished products
- `stock_movements` — audit trail of stock changes per location
- `production_batches`, `product_recipes` — manufacturing tracking
- `users` — staff accounts with role + optional store assignment (`location_id`)
- Plus tables for expenses, exchange rates, and sales/POS transactions

## Project Structure

```
Youzarsif/
├── actions/         # Form processors / POST endpoints (12 total, all auth-protected)
├── admin/           # Admin panel pages (17 total)
├── auth/            # Login, logout, forgot/reset password
├── assets/          # CSS, JS, images (design system source: assets/css/admin.css)
├── config/          # App configuration (see config/*.example.php)
├── dompdf/          # PDF generation library (vendored)
├── includes/        # Shared PHP helpers — auth, mailer, layout partials, login throttling
├── storage/         # Runtime data (gitignored)
├── screenshots/      # App screenshots used in this README
└── DB.sql           # Full database schema
```

## Setup

1. Clone the repo into your local server's web root (e.g. `htdocs` for XAMPP)
2. Import `DB.sql` into MySQL to create the schema
3. Copy `config/database.example.php` → `config/database.php` and fill in your DB credentials
4. Copy `config/mail.example.php` → `config/mail.php` and fill in your SMTP credentials
5. Visit `index.php` in your browser to reach the login page

## Screenshots

_Coming soon — see `screenshots/` folder._

## Security Notes

Real database and SMTP credentials are excluded from version control via `.gitignore`. Only example/template config files (`config/*.example.php`) are committed. Runtime/storage files are also excluded to avoid leaking operational data.

## Status

Actively used in production by Youzarsif Sweets for day-to-day factory and store operations.
