<?php

/**
 * Plugin Name:       Event Manager Extended
 * Description:       Review workflow for Events synced via Municipio External Content
 * Version:           0.1.0
 * Author:            Municipio
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       event-manager-extended
 * Domain Path:       /languages
 */

namespace EventManagerExtended;

if (!defined('WPINC')) {
    die;
}

define('EVENT_MANAGER_EXTENDED_PATH', plugin_dir_path(__FILE__));
define('EVENT_MANAGER_EXTENDED_URL', plugins_url('', __FILE__));
define('EVENT_MANAGER_EXTENDED_VERSION', '0.1.0');

if (!defined('EVENT_MANAGER_EXTENDED_IMPORT_REVIEW')) {
    define('EVENT_MANAGER_EXTENDED_IMPORT_REVIEW', true);
}

spl_autoload_register(static function (string $className): void {
    $prefix = __NAMESPACE__ . '\\';

    if (strpos($className, $prefix) !== 0) {
        return;
    }

    $relativeClass = substr($className, strlen($prefix));
    $file = EVENT_MANAGER_EXTENDED_PATH . 'source/php/' . str_replace('\\', '/', $relativeClass) . '.php';

    if (is_readable($file)) {
        require_once $file;
    }
});

if (file_exists(EVENT_MANAGER_EXTENDED_PATH . 'vendor/autoload.php')) {
    require_once EVENT_MANAGER_EXTENDED_PATH . 'vendor/autoload.php';
}

require_once EVENT_MANAGER_EXTENDED_PATH . 'Public.php';

add_action('init', static function (): void {
    load_plugin_textdomain(
        'event-manager-extended',
        false,
        dirname(plugin_basename(__FILE__)) . '/languages'
    );
});

new App();
