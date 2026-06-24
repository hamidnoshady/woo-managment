<?php

// Copy this file to includes/config.php and fill in the MySQL database
// you created via cPanel -> MySQL Databases (or phpMyAdmin). includes/config.php
// is gitignored — never commit real credentials.
//
// This file's existence (and includes/config.php's absence from git) is the
// reason database credentials live here instead of in the app's own
// settings table: the app needs a database connection before it can read
// anything out of that table.

return [
    'host'     => '127.0.0.1',
    'port'     => 3306,
    'database' => 'your_db_name',
    'username' => 'your_db_user',
    'password' => 'your_db_password',
];
