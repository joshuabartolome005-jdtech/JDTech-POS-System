# WrapNatics Shawarma Station - POS System (PHP + MySQL + XAMPP)

A simple Point of Sale system for a shawarma business — login, product/inventory
management, cashier/POS screen with a bold thermal-style printable receipt, and
sales history.

## Features
- Login system (admin / cashier roles)
- Dashboard with sales summary and low-stock alerts
- Product & Category management (CRUD)
- POS/Cashier screen — click-to-add cart, search, category filter, auto stock deduction
- Bold, thermal-printer-style receipt with a Print button (like UTAK POS)
- Sales history with date filter, plus reprint from history
- User management (admin only)

## Setup Instructions (XAMPP)

1. **Install XAMPP** if you don't have it yet: https://www.apachefriends.org/

2. **Extract the files.**
   Copy the entire `pos` folder into:
   ```
   C:\xampp\htdocs\pos
   ```
   (or `/Applications/XAMPP/htdocs/pos` on Mac)

3. **Open the XAMPP Control Panel** and **Start** `Apache` and `MySQL`.

4. **Create the database:**
   - Go to `http://localhost/phpmyadmin`
   - Click the **Import** tab
   - Choose the file `database.sql` (inside the `pos` folder)
   - Click **Go/Import** — this automatically creates the `wrapnatics_pos`
     database, tables, and sample shawarma menu data.

5. **Check the database config** (if needed):
   Open `config/database.php` — default settings are:
   ```php
   $db_host = 'localhost';
   $db_name = 'wrapnatics_pos';
   $db_user = 'root';
   $db_pass = ''; // blank by default on XAMPP
   ```
   Adjust only if you have a custom MySQL password.

6. **Edit your business info (optional):**
   Open `config/settings.php` to update the address and contact number
   shown on the login page and printed receipts:
   ```php
   define('BUSINESS_NAME', 'WrapNatics Shawarma Station');
   define('BUSINESS_TAGLINE', 'Wrap It. Love It.');
   define('BUSINESS_ADDRESS', 'Your Business Address Here');
   define('BUSINESS_CONTACT', '0900-000-0000');
   ```

7. **Open the application in your browser:**
   ```
   http://localhost/pos/
   ```

8. **Default login:**
   - Username: `admin`
   - Password: `admin123`

## Folder Structure
```
pos/
├── assets/           # CSS and JS files
├── config/           # database connection, auth helpers, business settings
├── includes/         # header, sidebar, footer (shared layout)
├── database.sql      # SQL schema + shawarma sample data
├── login.php
├── dashboard.php
├── products.php       (+ product_save.php, product_delete.php)
├── categories.php
├── pos.php             <- main cashier screen (with print receipt)
├── process_sale.php    <- AJAX endpoint for checkout
├── sales_history.php
├── sale_view.php        <- view/reprint a past receipt
└── users.php            <- admin only
```

## How to Use
1. Log in with the default admin account.
2. Go to **Products** to add your menu items (name, price, stock).
3. Go to **POS** to start selling — click a product to add it to the cart,
   enter the customer's payment, then click **Checkout**.
4. After checkout, click **Print Receipt** to print a bold, thermal-style receipt.
5. **Sales History** shows all recorded transactions — click **View** on any
   entry to reprint that receipt later.
6. In **Users** (admin only), you can add cashier accounts.

## Printing the Receipt
The receipt is styled like a thermal POS printer receipt (80mm width, bold
totals, dashed dividers). When you click **Print Receipt**:
- If you have a thermal/receipt printer set up as your default printer, it
  will print directly on receipt paper.
- On a regular printer, your browser's print dialog will open — just make
  sure paper size is set close to "80mm / Receipt" if your printer supports it,
  otherwise it will print on regular paper at a smaller width.

## Possible Future Enhancements
- Barcode scanner integration (the search box already accepts scanner input)
- Product image upload
- Reports/analytics with charts
- Multi-branch support

---
Built with PHP (vanilla, no framework) + MySQL (PDO) + Bootstrap 5.
Compatible with XAMPP, and can be opened/edited with Visual Studio Code.
