#!/usr/bin/php
<?php
    define('MANAGER', dirname(__FILE__) . '/manage.php');

    echo "Enter the params: ";
    $h = fopen('php://stdin', 'r');
    $params = fgets($h);
    fclose($h);

    chmod(MANAGER, 0777);
    pcntl_exec(MANAGER, explode(' ', $params), array('TERM' => 'xterm'));
?>