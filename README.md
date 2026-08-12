# Youzarsif Sweets Management System

A PHP-based POS (Point of Sale) and business management platform built for Youzarsif Sweets, covering factory production, stock, sales, and multi-store operations.

## Overview

Youzarsif Sweets Management System handles the full operational flow of a sweets manufacturing and retail business, from raw ingredients and production batches, to finished products, multi-location stock transfers, point-of-sale checkout, and financial reporting.

## Tech Stack

- **Backend:** PHP (plain, no framework) with PDO/MySQL
- **Frontend:** Custom HTML/CSS/JS (chocolate/caramel/cream design system)
- **PDF Generation:** dompdf
- **Email:** SMTP via Gmail (password reset flow)
- **Database:** MySQL

## Key Features

- **Role-based access control** — Admin, Factory User/Accountant, and Cashier roles, each with scoped permissions
- **Point of Sale (POS)** — dedicated checkout screens for both factory and store locations
- **Inventory management** — items, categories, units, and stock movements across multiple locations
- **Production tracking** — production batches and product recipes linking raw ingredients to finished goods
- **Factory-to-store transfers** — stock movement between factory and retail locations
- **Financial reporting** — break-even analysis, expenses, exchange rates, and PDF report exports
- **Low-stock alerts** — automatic flagging of items running low
- **Secure authentication** — login throttling, password reset via email, and protected admin/action endpoints
- **Responsive admin panel** — foldable sidebar, live search, and combinable smart filters

## Roles & Access

| Role | Login Method | Access |
|---|---|---|
| Admin | Email | Full admin panel |
| Factory User / Accountant | Username | Full admin panel |
| Cashier | Username | Scoped to assigned store (POS only) |

## Project Structure

```
Youzarsif/
├── actions/        # Form processors / POST endpoints
├── admin/          # Admin panel pages
├── auth/           # Login, logout, password reset
├── assets/         # CSS, JS, images
├── config/         # App configuration (see config/*.example.php)
├── dompdf/          # PDF generation library
├── includes/       # Shared PHP helpers (auth, mailer, layout partials)
├── storage/        # Runtime data
└── DB.sql          # Database schema
```

## Setup

1. Clone the repo into your local server (e.g. `htdocs` for XAMPP)
2. Import `DB.sql` into MySQL
3. Copy `config/database.example.php` → `config/database.php` and fill in your DB credentials
4. Copy `config/mail.example.php` → `config/mail.php` and fill in your SMTP credentials
5. Visit `index.php` in your browser

## Security Notes

Real database and SMTP credentials are excluded from version control (`.gitignore`). Only example/template config files are committed.
