<?php
namespace Webman\Console;

class Util
{
    public static function nameToNamespace($name)
    {
        $namespace = ucfirst($name);
        $namespace = preg_replace_callback(['/-([a-zA-Z])/', '/(\/[a-zA-Z])/'], function ($matches) {
            return strtoupper($matches[1]);
        }, $namespace);
        $namespace = str_replace('/', '\\' ,ucfirst($namespace));
        return $namespace;
    }
}