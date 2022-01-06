<?php
namespace Webman\Console;

class Install
{
    const WEBMAN_PLUGIN = true;

    /**
     * Install
     * @return void
     */
    public static function install()
    {
        symlink(__DIR__ . "/webman", base_path()."/webman");
    }

    /**
     * Uninstall
     * @return void
     */
    public static function uninstall()
    {
        unlink(base_path()."/webman");
    }
    
}