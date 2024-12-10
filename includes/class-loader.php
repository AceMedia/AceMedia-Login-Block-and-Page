<?php
/**
 * Class Loader
 *
 * @package AceLoginBlock
 */

namespace AceLoginBlock;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class Loader
 * Handles autoloading of plugin classes
 */
class Loader {
    /**
     * The array of class prefixes and their corresponding paths.
     *
     * @var array
     */
    private static $prefixes = array();

    /**
     * Register loader with SPL autoloader stack.
     */
    public static function register() {
        // Register base plugin namespace
        self::add_prefix('AceLoginBlock\\', ACEMEDIA_LOGIN_BLOCK_PATH . 'includes/');

        // Register namespace prefixes for each directory
        self::add_prefix('AceLoginBlock\\Admin\\', ACEMEDIA_LOGIN_BLOCK_PATH . 'includes/admin/');
        self::add_prefix('AceLoginBlock\\Auth\\', ACEMEDIA_LOGIN_BLOCK_PATH . 'includes/auth/');
        self::add_prefix('AceLoginBlock\\Blocks\\', ACEMEDIA_LOGIN_BLOCK_PATH . 'includes/blocks/');
        self::add_prefix('AceLoginBlock\\API\\', ACEMEDIA_LOGIN_BLOCK_PATH . 'includes/api/');
        self::add_prefix('AceLoginBlock\\Utils\\', ACEMEDIA_LOGIN_BLOCK_PATH . 'includes/utils/');
        self::add_prefix('AceLoginBlock\\Frontend\\', ACEMEDIA_LOGIN_BLOCK_PATH . 'includes/frontend/');

        // Register autoloader
        spl_autoload_register(array(__CLASS__, 'autoload'));
    }

    /**
     * Adds a base prefix for loading classes.
     *
     * @param string $prefix The namespace prefix.
     * @param string $base_dir A base directory for class files.
     */
    public static function add_prefix($prefix, $base_dir) {
        self::$prefixes[$prefix] = $base_dir;
    }

    /**
     * Autoload function for loading classes.
     *
     * @param string $class The fully-qualified class name.
     */
    public static function autoload($class) {
        // Check each registered prefix
        foreach (self::$prefixes as $prefix => $base_dir) {
            $len = strlen($prefix);

            // Continue if class doesn't use this prefix
            if (strncmp($prefix, $class, $len) !== 0) {
                continue;
            }

            // Get the relative class name
            $relative_class = substr($class, $len);

            // Convert namespace separators to directory separators
            // Also convert StudlyCaps to lowercase-dashed
            $file = $base_dir . str_replace('\\', '/', $relative_class);
            $file = str_replace('_', '-', strtolower(preg_replace('/(?<!^)[A-Z]/', '-$0', $file)));
            $file = 'class-' . str_replace('\\', '/', strtolower($file)) . '.php';

            // Require the file if it exists
            if (file_exists($file)) {
                require $file;
                return;
            }
        }
    }
}

// Initialize the autoloader
Loader::register();