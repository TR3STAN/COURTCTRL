<?php
echo "PHP version: " . PHP_VERSION . "<br>";
echo "Loaded php.ini: " . php_ini_loaded_file() . "<br>";
echo "ZIP extension loaded: " . (extension_loaded('zip') ? 'YES' : 'NO') . "<br>";
echo "ZipArchive class exists: " . (class_exists('ZipArchive') ? 'YES' : 'NO') . "<br>";