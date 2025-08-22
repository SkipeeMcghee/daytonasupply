# Daytona Supply Website

Diagnostic helpers
------------------

tools/run_catalogue_test.php — A small CLI helper that simulates a GET request to render `catalogue.php` from the command line for debugging server-side rendering and search. Usage: run `php tools/run_catalogue_test.php` from the project root. The script is intentionally non-destructive and writes its rendered output to STDOUT; you can redirect it to a file if you want a snapshot, e.g. `php tools/run_catalogue_test.php > tools/catalogue_output.html`.

Cleanup action performed: removed the previously generated `tools/catalogue_output.html` snapshot to keep the repo tidy while retaining `tools/run_catalogue_test.php` for future debugging needs.

This repository contains a simple e‑commerce style website for Daytona Supply built with PHP and SQLite.  It provides the following pages and features:

* **index.php** – Landing page introducing the company.
* **signup.php** – Customer registration form.  Collects name, business name, phone, email, billing and shipping addresses and password.
* **login.php** – Customer login form.
* **account.php** – Displays the logged‑in customer’s profile information and a list of their past orders.  Customers can update their details and password.
* **catalogue.php** – Lists all available products with descriptions and prices.  Customers can add items to a cart.
* **cart.php** – Shows the contents of the current customer’s cart and allows quantities to be updated or items removed.
* **checkout.php** – Creates a purchase order from the items in the cart.  Orders are marked as “Pending” and must be approved by an office manager via the manager portal.  Upon submission an e‑mail notification is sent to the company e‑mail address configured via the `COMPANY_EMAIL` environment variable.
* **managerportal.php** – Password protected portal for office managers.  Managers can view, approve or reject pending orders; view and edit customer details; view, add, edit and delete products.  A default manager password of `admin` is seeded into the database on first run.
* **admin/update_inventory.php** – Reloads product data from `data/inventory.json`.  Accessible only to logged‑in managers.

## Data storage

All data is stored in a SQLite database located at `data/database.sqlite`.  The database and tables are created automatically when the site is first run.  Products are seeded from `data/inventory.json` if the products table is empty.

## Email notifications

To enable order notification e‑mails set the `COMPANY_EMAIL` environment variable to the address that should receive new purchase orders.  The `sendEmail()` function uses PHP’s built‑in `mail()` to send messages; ensure your PHP configuration permits outgoing mail or replace this function with a suitable SMTP library.

## Security considerations

This application is intended as a demonstration and has not been hardened for production.  Passwords are hashed using PHP’s `password_hash` but the login and session logic is basic.  If you plan to deploy this site publicly you should enforce HTTPS, implement CSRF protection, and handle input validation and sanitisation more rigorously.