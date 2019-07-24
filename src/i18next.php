<?php

/*
 * ----------------------------------------------------------------------------
 * "THE BEER-WARE LICENSE" (Revision 42):
 * <github.com/Mika-> wrote this file. As long as you retain this notice you
 * can do whatever you want with this stuff. If we meet some day, and you think
 * this stuff is worth it, you can buy me a beer in return
 * ----------------------------------------------------------------------------
 */
/**
 * francis.crossen@888holdings.com - added support for nested translations,
 * changed preg_replace() to str_replace()
 */

namespace Aiken\i18next;

class i18next {

    /**
     * Path for the translation files
     * @var string Path
     */
    private static $_path = null;

    /**
     * Primary language to use
     * @var string Code for the current language
     */
    private static $_language = null;

    /**
     * Fallback language for translations not found in current language
     * @var string Fallback language
     */
    private static $_fallbackLanguage = 'dev';

    /**
     * Array to store the translations
     * @var array Translations
     */
    private static $_translation = array();

    /**
     * Logs keys for missing translations
     * @var array Missing keys
     */
    private static $_missingTranslation = array();

    /**
     * Used for nested tranlations to prevent infinite loops
     * @var int
     */
    const DEFAULT_RECURSION_LIMIT = 10;

    /**
     * Overide the recursion limit
     * @var int
     */
    private static $_recursionLimit;

    /**
     * Inits i18next static class
     * Path may include __lng___ and __ns__ placeholders so all languages and namespaces are loaded
     *
     * @param string $language Locale language code
     * @param string $path Path to locale json files
     */
    public static function init($language = 'en', $path = null) {

        self::$_language = $language;
        self::$_path = $path;
        self::$_recursionLimit = self::DEFAULT_RECURSION_LIMIT;

        self::loadTranslation();

    }

    /**
     * Change default language and fallback language
     * If fallback is not set it is left unchanged
     *
     * @param string $language New default language
     * @param string $fallback Fallback language
     */
    public static function setLanguage($language, $fallback = null) {

        self::$_language = $language;

        if (!empty($fallback))
            self::$_fallbackLanguage = $fallback;

    }

    /**
     * Get list of missing translations
     *
     * @return array Missing translations
     */
    public static function getMissingTranslations() {

        return self::$_missingTranslation;

    }

    /**
     * Check if translated string is available
     *
     * @param string $key Key for translation
     * @return boolean Stating the result
     */
    public static function existTranslation($key) {

        $return = self::_getKey($key);

        if ($return)
            $return = true;

        return $return;

    }

    /**
     * Override the recursion limit for nesting
     * @param int
     * @return void
     */
    public static function setNestingRecursionLimit($count) {
        self::$_recursionLimit = (int)$count;
    }

    /**
     * Get translation for given key
     *
     * @param string $key Key for the translation
     * @param array $variables Variables
     * @return mixed Translated string or array
     */
    public static function getTranslation($key, $variables = array()) {

        $return = self::_getKey($key, $variables);

        // check for nested keys
        static $recursionCount = 0;
        if (strpos($return, '$t(') !== false && $recursionCount < self::$_recursionLimit) {
            $recursionCount++;
            /**
             * Regular expression pattern /\$t\((.*)\)/Ug
             * 
             * Match $t(something) multiple times in a string. Note 'g' modifier is not needed with
             * PHP preg_match_all()
             */
            $pattern = '/\\$t\\((.*)\\)/U';
            $found = preg_match_all($pattern, $return, $matches);
            if ($found) {
                $replacements = $matches[0];
                $keys = $matches[1];
                $replacement_count = count($replacements);
                for ($index=0; $index<$replacement_count; $index++) {
                    $return = str_replace($replacements[$index], self::getTranslation($keys[$index], $variables), $return);
                }
            }
        }

        // Log missing translation
        if (!$return && array_key_exists('lng', $variables))
            array_push(self::$_missingTranslation, array('language' => $variables['lng'], 'key' => $key));

        else if (!$return)
            array_push(self::$_missingTranslation, array('language' => self::$_language, 'key' => $key));

        // fallback language check
        if (!$return && !isset($variables['lng']) && !empty(self::$_fallbackLanguage))
            $return = self::_getKey($key, array_merge($variables, array('lng'=>  self::$_fallbackLanguage)));

        if (!$return && array_key_exists('defaultValue', $variables))
            $return = $variables['defaultValue'];

        if ($return && isset($variables['postProcess']) && $variables['postProcess'] === 'sprintf' && isset($variables['sprintf'])) {

            if (is_array($variables['sprintf']))
                $return = vsprintf($return, $variables['sprintf']);

            else
                $return = sprintf($return, $variables['sprintf']);

        }

        if (!$return)
            $return = $key;

        foreach ($variables as $variable => $value) {

            if (is_string($value) || is_numeric($value)) {
                $return = str_replace('__' . $variable . '__', $value, $return);
                $return = str_replace('{{' . $variable . '}}', $value, $return);
            }

        }

        return $return;

    }

    /**
     * Loads translation(s)
     * @throws \Exception
     */
    private static function loadTranslation() {

        $path = preg_replace('/__(.+?)__/', '*', self::$_path, 2, $hasNs);

        if (!preg_match('/\.json$/', $path)) {

            $path = $path . 'translation.json';

            self::$_path = self::$_path . 'translation.json';

        }

        $dir = glob($path);

        if (count($dir) === 0)
            throw new \Exception('Translation file not found');

        foreach ($dir as $file) {

            $translation = file_get_contents($file);

            $translation = json_decode($translation, true);

            if ($translation === null)
                throw new \Exception('Invalid json ' . $file);

            if ($hasNs) {

                $regexp = preg_replace('/__(.+?)__/', '(?<$1>.+)?', preg_quote(self::$_path, '/'));

                preg_match('/^' . $regexp . '$/', $file, $ns);

                if (!array_key_exists('lng', $ns))
                    $ns['lng'] = self::$_language;

                if (array_key_exists('ns', $ns)) {

                    if (array_key_exists($ns['lng'], self::$_translation) && array_key_exists($ns['ns'], self::$_translation[$ns['lng']]))
                        self::$_translation[$ns['lng']][$ns['ns']] = array_merge(self::$_translation[$ns['lng']][$ns['ns']], array($ns['ns'] => $translation));

                    else if (array_key_exists($ns['lng'], self::$_translation))
                        self::$_translation[$ns['lng']] = array_merge(self::$_translation[$ns['lng']], array($ns['ns'] => $translation));

                    else
                        self::$_translation[$ns['lng']] = array($ns['ns'] => $translation);

                }
                else {

                    if (array_key_exists($ns['lng'], self::$_translation))
                        self::$_translation[$ns['lng']] = array_merge(self::$_translation[$ns['lng']], $translation);

                    else
                        self::$_translation[$ns['lng']] = $translation;

                }

            }
            else {

                if (array_key_exists(self::$_language, $translation))
                    self::$_translation = $translation;

                else
                    self::$_translation = array_merge(self::$_translation, $translation);

            }

        }

    }

    /**
     * Get translation for given key
     *
     * Translation is looked up in language specified in $variables['lng'], current language or Fallback language - in this order.
     * Fallback language is used only if defined and no explicit language was specified in $variables
     *
     * @param string $key Key for translation
     * @param array $variables Variables
     * @return mixed Translated string or array if requested. False if translation doesn't exist
     */
    private static function _getKey($key, $variables = array()) {
        $return = false;

        if (array_key_exists('lng', $variables) && array_key_exists($variables['lng'], self::$_translation))
            $translation = self::$_translation[$variables['lng']];

        else if (array_key_exists(self::$_language, self::$_translation))
            $translation = self::$_translation[self::$_language];

        else
            $translation = array();

        // path traversal - last array will be response
        $paths_arr = explode('.', $key);

        while ($path = array_shift($paths_arr)) {

            if (array_key_exists($path, $translation) && is_array($translation[$path]) && count($paths_arr) > 0) {

                $translation = $translation[$path];

            }
            else if (array_key_exists($path, $translation)) {

                // Request has context
                if (array_key_exists('context', $variables)) {

                    if (array_key_exists($path . '_' . $variables['context'], $translation))
                        $path = $path . '_' . $variables['context'];

                }

                // Request is plural form
                // TODO: implement more complex i18next handling
                if (array_key_exists('count', $variables)) {

                    if ($variables['count'] != 1 && array_key_exists($path . '_plural_' . $variables['count'], $translation))
                        $path = $path . '_plural' . $variables['count'];

                    if ($variables['count'] == 0 && array_key_exists($path . '_0', $translation))
                        $path = $path . '_0';

                    else if ($variables['count'] != 1 && array_key_exists($path . '_plural', $translation))
                        $path = $path . '_plural';

                }

                $return = $translation[$path];

                break;

            }
            else {

                return false;

            }

        }

        if (is_array($return) && isset($variables['returnObjectTrees']) && $variables['returnObjectTrees'] === true)
            $return = $return;

        else if (is_array($return) && array_keys($return) === range(0, count($return) - 1))
            $return = implode("\n", $return);

        else if (is_array($return))
            return false;

        return $return;

    }

}
