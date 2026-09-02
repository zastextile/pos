ZAS SALES RECORDER - CLOUDWAYS DIRECT PUBLIC_HTML VERSION
========================================================

This version does NOT require a custom Webroot.

UPLOAD
------
Upload all files and folders from this package directly inside the selected
Cloudways application's public_html folder.

The final structure must be:

public_html/
  index.php
  .htaccess
  .env
  app/
  api/
  assets/
  bin/
  icons/
  vendor/
  manifest.json
  sw.js

Do not put these files inside another zas-sales-recorder folder.

CONFIGURATION
-------------
1. Copy .env.example as .env.
2. Enter the database name, username, and password from the selected
   Cloudways application's Access Details > MySQL Access.

INSTALL DATABASE AND ADMIN
--------------------------
From public_html, run:

php bin/install_database.php
php bin/create_admin.php "Administrator" "admin" "YOUR_STRONG_PASSWORD"

DOMAIN
------
Keep the Cloudways Webroot at its normal/default public_html setting.
Open: https://pos.thqlinen.com

SECURITY
--------
The included .htaccess blocks browser access to internal folders, environment
files, SQL files, logs, documentation, and licenses. The CLI scripts also reject
web requests. Do not set any files or folders to permission 777.
