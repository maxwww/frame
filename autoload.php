<?php
function __autoload($class)
{
    $class = str_replace("\\", "/", $class);
    if (preg_match('/^msf\//', $class)) {
        require_once(__DIR__ . "/$class.php");
    }
}