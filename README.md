# SME MiniMart Management System

A browser-based store management system for a small neighborhood MiniMart. It manages cashier sales, barcode scanning, inventory and expiration dates, customer credit (`utang`), supplier purchase orders, reports, users, receipts, and security activity.

## Main features

- Role-based accounts for Owner, Cashier, and Inventory Assistant
- Cash, GCash, and Customer Credit sales
- Product-name and barcode search with keyboard controls
- Camera barcode scanning with sound and vibration feedback
- Printable sales receipts
- Customer debt balances, due dates, overdue days, and payment history
- Inventory quantities, batches, expiration alerts, and low-stock alerts
- FEFO batch deduction (earliest-expiring stock is sold first)
- Controlled stock adjustments with required reasons
- Supplier purchase orders, email delivery, and partial/full receiving
- Sales, inventory, expiration, low-stock, and debt reports
- Sale voiding with automatic inventory restoration
- REST API with session authentication and role permissions
- Owner-only audit log and remembered-device revocation

## Technologies

- PHP 8.2+
- MySQL/MariaDB
- PDO with native prepared statements
- HTML5, CSS, and vanilla JavaScript
- PHPMailer for supplier email
- SweetAlert2 (stored locally)
- Browser `BarcodeDetector` and `MediaDevices` APIs for camera scanning
- Apache/Laragon for local development
- Cloudflared can be used to provide HTTPS access from a phone

## Project structure

```text
SME_MiniMart_/
├── .env                     Application, database, and mail configuration
├── backend/                 Authentication, actions, services, DB, and mail
├── database/                Migration and schema-inspection utilities
├── frontend/                Public pages, modules, API, CSS, and JavaScript
│   ├── api/                 REST API entry point
│   └── assets/              Styles, scripts, and local frontend libraries
├── storage/sessions/        Server-side PHP session files
├── vendor/                  PHPMailer dependency
└── README.md
```

The web server document root should point to `frontend`. The `.env`, `backend`, `database`, `storage`, and `vendor` directories must not be publicly accessible.

## Requirements

- Laragon with Apache and MySQL enabled
- PHP 8.2 or newer
- MySQL or MariaDB
- PHP extensions: `pdo_mysql`, `mbstring`, `openssl`, and `json`
- Chrome or Edge for camera barcode scanning
- HTTPS when accessing the camera from a phone or non-localhost address

## Local setup with Laragon

1. Put or link the project into a Laragon-accessible directory.
2. Start **Apache** and **MySQL** from Laragon.
3. Ensure the MiniMart database and its original tables have been imported.
4. Configure the root `.env` file.
5. Open PowerShell in the project directory and run:

   ```powershell
   php database/migrate.php
   ```

6. Open the application. Depending on the Laragon configuration, use one of these URLs:

   ```text
   http://localhost/SME_MiniMart_/frontend/login.php
   http://sme_minimart.test/login.php
   ```

`database/migrate.php` installs security, audit, batch-movement, adjustment, void, remembered-device, and supplier-receiving upgrades. It does not replace the original complete MiniMart schema import.

## Environment configuration

The application reads configuration from the root `.env`:

```env
APP_ENV=development
APP_URL=
TRUSTED_PROXIES=127.0.0.1,::1

DB_HOST=localhost
DB_PORT=3306
DB_USER=root
DB_PASSWORD=
DB_NAME=minimart.db

MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=smeminimartstore@gmail.com
MAIL_PASSWORD=
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=smeminimartstore@gmail.com
MAIL_FROM_NAME=SME MiniMart Store
```

Do not upload `.env` to Git or send it to another person. It is excluded by `.gitignore` and blocked by Apache rules.

### Gmail supplier email

To send purchase orders through Gmail:

1. Enable two-step verification on the Gmail account.
2. Create a Gmail App Password.
3. Put the App Password in `MAIL_PASSWORD` without spaces.
4. Create a test supplier order and verify that its status changes from `Pending Email` to `Emailed`.

Do not use the normal Gmail account password.

## User roles

### Owner

- Full dashboard access
- Inventory, sales, customers, suppliers, and reports
- User display-name management
- Sale voiding
- Security activity and remembered-device management

### Cashier

- New sales and receipts
- Cash, GCash, and credit transactions
- Customer registration and debt payments
- Product selling prices and stock availability
- No access to product cost prices or supplier management

### Inventory Assistant

- Products and inventory
- Stock receiving and adjustments
- Expiration and low-stock monitoring
- Suppliers, purchase orders, and delivery receiving
- No access to sales reports, customer debts, users, or security logs

Account passwords are deliberately not documented in this README. Use the assigned credentials and change them after the first sign-in.

## Sales keyboard workflow

1. Open **New sale**.
2. Type a product name or barcode.
3. Use `↑` and `↓` to highlight a matching product.
4. Press `Enter` to select it and move to Quantity.
5. Enter the quantity and press `Enter` again to add it.
6. Review the cart, continue to Payment, and complete the sale.

A USB barcode scanner can type into the search field and submit with `Enter`. Camera scanning supports continuous scanning, confirmation sounds, and vibration on supported devices.

## Camera access from a phone

Browsers require HTTPS for camera access except on `localhost`. A temporary Cloudflare tunnel can be started with:

```powershell
cloudflared tunnel --url http://127.0.0.1:80
```

If Laragon serves the project on another port, replace `80` with that port. Open the generated `https://*.trycloudflare.com` address on the phone and allow camera access.

Only expose the system temporarily during development. Use a real domain, HTTPS certificate, authentication monitoring, and production configuration for deployment.

## REST API

The API base path is:

```text
/api/index.php
```

With Apache rewriting enabled, routes include:

| Method | Route | Access |
| --- | --- | --- |
| POST | `/auth/login` | Public with rate limiting |
| GET | `/auth/me` | Authenticated |
| POST | `/auth/logout` | Authenticated |
| GET | `/products` | Authenticated; fields filtered by role |
| GET | `/products/barcode/{barcode}` | Authenticated |
| GET | `/sales` | Owner, Cashier |
| GET | `/sales/{id}` | Owner, Cashier |
| POST | `/sales` | Owner, Cashier |
| GET | `/customers` | Owner, Cashier |
| GET | `/customers/{id}/debts` | Owner, Cashier |
| POST | `/customers/{id}/payments` | Owner, Cashier |
| GET | `/inventory` | Owner, Assistant |
| POST | `/stock-in` | Owner, Assistant |

Mutating requests require the authenticated session cookie and the CSRF token returned by `/auth/login` or `/auth/me`. Send it using the `X-CSRF-Token` header.

The API uses JSON; it does not create an XML file when a sale is recorded. Sales are stored directly in MySQL.

## Security features

- `password_hash` and `password_verify`
- PDO prepared statements with emulated prepares disabled
- CSRF protection for forms and API writes
- Server-side role and permission checks
- Login rate limiting
- Session ID renewal after authentication and password changes
- `HttpOnly`, `SameSite=Lax`, and HTTPS `Secure` cookies
- Hashed, rotating Remember Me tokens
- Content Security Policy and Permissions Policy
- HTTPS enforcement outside local development
- Generic production error responses
- Audit logs for sensitive operations
- Local pinned SweetAlert dependency
- Protected configuration and storage directories

## Production deployment checklist

Before uploading the system:

1. Use a dedicated database account instead of MySQL `root`.
2. Give that account access only to the MiniMart database.
3. Update `.env`:

   ```env
   APP_ENV=production
   APP_URL=https://your-domain.com
   DB_USER=minimart_app
   DB_PASSWORD=use-a-strong-unique-password
   ```

4. Point the domain document root to `frontend`.
5. Force HTTPS and configure only trusted reverse-proxy addresses.
6. Keep `.env`, `backend`, `database`, `storage`, and `vendor` outside the public document root where possible.
7. Make `storage/sessions` writable only by the PHP/web-server account.
8. Configure SMTP and test supplier email delivery.
9. Disable PHP error display and confirm production error logging.
10. Create automatic encrypted database backups and test restoring one.
11. Change all initial passwords and revoke unrecognized remembered devices.

## Useful checks

Run the database migration:

```powershell
php database/migrate.php
```

Inspect the current database schema:

```powershell
php database/inspect_schema.php
```

Check PHP syntax:

```powershell
Get-ChildItem backend,frontend,database -Recurse -Filter *.php | ForEach-Object { php -l $_.FullName }
```

Check JavaScript syntax when Node.js is installed:

```powershell
node --check frontend/assets/app.js
```

## Troubleshooting

### Database connection refused

- Start MySQL in Laragon.
- Confirm `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, and `DB_PASSWORD` in `.env`.
- Confirm the database name becomes `minimart_db`; the application converts the configured dot in `minimart.db` to an underscore.

### Camera does not open

- Use Chrome or Edge.
- Allow camera permission.
- Use HTTPS on a phone; plain HTTP works only on `localhost`.
- Type the product name or barcode if the browser does not support `BarcodeDetector`.

### Supplier email is not delivered

- Set a Gmail App Password in `MAIL_PASSWORD`.
- Confirm port `587` and `MAIL_ENCRYPTION=tls`.
- Check the supplier email address and spam folder.
- Confirm the purchase order status in the Suppliers module.

### Session folder error

Make sure `storage/sessions` exists and is writable by PHP. Do not configure PHP to use an old XAMPP session directory.

## License and usage

This project is intended for the SME MiniMart store-management deployment. Add an explicit license before distributing or publishing the source code publicly.
