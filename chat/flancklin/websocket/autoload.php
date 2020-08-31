<?php

function classLoader($class)
{
    $classFile = strrchr($class, DIRECTORY_SEPARATOR);//  \classname
    $file = __DIR__ . DIRECTORY_SEPARATOR.'lib' . $classFile . '.php';

    if (file_exists($file)) {
        require_once $file;
    }
}
spl_autoload_register('classLoader');