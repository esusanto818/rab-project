<?php
/**
 * Compatibility wrapper for the tax-rate PDF endpoint.
 * Use the application's shared PDO connection settings; never store database
 * credentials in a web-accessible endpoint directory.
 */
require_once __DIR__ . '/../php-class/crudClass.php';
$pdo = new crud();
