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
        copy_dir(__DIR__ . "/webman", base_path()."/webman");
        chmod(base_path()."/webman", 0755);
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