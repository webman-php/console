<?php
namespace Webman\Console;

use Doctrine\Inflector\InflectorFactory;

class Util
{
    /**
     * Get current locale for CLI messages. Default is en.
     * All commands can use this to get the current language for prompts.
     *
     * @return string
     */
    public static function getLocale(): string
    {
        $locale = 'en';
        if (function_exists('config')) {
            $value = config('translation.locale', 'en');
            $value = is_string($value) ? trim($value) : '';
            if ($value !== '') {
                $locale = $value;
            }
        }
        return $locale;
    }

    /**
     * Select message map by current locale. Fallback: exact -> language prefix -> en -> zh_CN -> first.
     *
     * @param array<string, array<string, string>> $localeToMessages e.g. ['zh_CN' => ['key' => '...'], 'en' => [...]]
     * @return array<string, string>
     */
    public static function selectLocaleMessages(array $localeToMessages): array
    {
        $locale = self::getLocale();
        if (isset($localeToMessages[$locale])) {
            return $localeToMessages[$locale];
        }
        $lang = explode('_', $locale)[0] ?? '';
        if ($lang !== '' && isset($localeToMessages[$lang])) {
            return $localeToMessages[$lang];
        }
        // Use configured fallback locales if available, otherwise default to ['en'].
        $fallbacks = ['en'];
        if (function_exists('config')) {
            $cfg = config('translation.fallback_locale', $fallbacks);
            if (is_string($cfg)) {
                $cfg = [$cfg];
            }
            if (is_array($cfg) && !empty($cfg)) {
                $fallbacks = $cfg;
            }
        }
        foreach ($fallbacks as $fb) {
            if (isset($localeToMessages[$fb])) {
                return $localeToMessages[$fb];
            }
        }
        $first = reset($localeToMessages);
        return is_array($first) ? $first : [];
    }

    /**
     * Select one value by current locale. Fallback: exact -> language prefix -> en -> zh_CN -> first.
     *
     * @param array<string, string> $localeToValue e.g. ['zh_CN' => '简体', 'en' => 'English']
     * @return string
     */
    public static function selectByLocale(array $localeToValue): string
    {
        $locale = self::getLocale();
        if (isset($localeToValue[$locale])) {
            return $localeToValue[$locale];
        }
        $lang = explode('_', $locale)[0] ?? '';
        if ($lang !== '' && isset($localeToValue[$lang])) {
            return $localeToValue[$lang];
        }
        // Use configured fallback locales if available, otherwise default to ['en'].
        $fallbacks = ['en'];
        if (function_exists('config')) {
            $cfg = config('translation.fallback_locale', $fallbacks);
            if (is_string($cfg)) {
                $cfg = [$cfg];
            }
            if (is_array($cfg) && !empty($cfg)) {
                $fallbacks = $cfg;
            }
        }
        foreach ($fallbacks as $fb) {
            if (isset($localeToValue[$fb])) {
                return $localeToValue[$fb];
            }
        }
        $first = reset($localeToValue);
        return is_string($first) ? $first : '';
    }

    /**
     * Select an array by current locale (e.g. table headers). Fallback: exact -> language prefix -> en -> zh_CN -> first.
     *
     * @param array<string, array> $localeToArray e.g. ['zh_CN' => ['A','B'], 'en' => ['A','B']]
     * @return array
     */
    public static function selectLocaleArray(array $localeToArray): array
    {
        $locale = self::getLocale();
        if (isset($localeToArray[$locale])) {
            return $localeToArray[$locale];
        }
        $lang = explode('_', $locale)[0] ?? '';
        if ($lang !== '' && isset($localeToArray[$lang])) {
            return $localeToArray[$lang];
        }
        // Use configured fallback locales if available, otherwise default to ['en'].
        $fallbacks = ['en'];
        if (function_exists('config')) {
            $cfg = config('translation.fallback_locale', $fallbacks);
            if (is_string($cfg)) {
                $cfg = [$cfg];
            }
            if (is_array($cfg) && !empty($cfg)) {
                $fallbacks = $cfg;
            }
        }
        foreach ($fallbacks as $fb) {
            if (isset($localeToArray[$fb])) {
                return $localeToArray[$fb];
            }
        }
        $first = reset($localeToArray);
        return is_array($first) ? $first : [];
    }

    public static function nameToNamespace($name)
    {
        $namespace = ucfirst($name);
        $namespace = preg_replace_callback(['/-([a-zA-Z])/', '/(\/[a-zA-Z])/'], function ($matches) {
            return strtoupper($matches[1]);
        }, $namespace);
        return str_replace('/', '\\' ,ucfirst($namespace));
    }

    public static function classToName($class)
    {
        $class = lcfirst($class);
        return preg_replace_callback(['/([A-Z])/'], function ($matches) {
            return '_' . strtolower($matches[1]);
        }, $class);
    }

    public static function nameToClass($class)
    {
        $class = preg_replace_callback(['/-([a-zA-Z])/', '/_([a-zA-Z])/'], function ($matches) {
            return strtoupper($matches[1]);
        }, $class);

        if (!($pos = strrpos($class, '/'))) {
            $class = ucfirst($class);
        } else {
            $path = substr($class, 0, $pos);
            $class = ucfirst(substr($class, $pos + 1));
            $class = "$path/$class";
        }
        return $class;
    }

    public static function guessPath($base_path, $name, $return_full_path = false)
    {
        if (!is_dir($base_path)) {
            return false;
        }
        $names = explode('/', trim(strtolower($name), '/'));
        $realname = [];
        $path = $base_path;
        foreach ($names as $name) {
            $finded = false;
            foreach (scandir($path) ?: [] as $tmp_name) {
                if (strtolower($tmp_name) === $name && is_dir("$path/$tmp_name")) {
                    $path = "$path/$tmp_name";
                    $realname[] = $tmp_name;
                    $finded = true;
                    break;
                }
            }
            if (!$finded) {
                return false;
            }
        }
        $realname = implode(DIRECTORY_SEPARATOR, $realname);
        return $return_full_path ? get_realpath($base_path . DIRECTORY_SEPARATOR . $realname) : $realname;
    }
}
