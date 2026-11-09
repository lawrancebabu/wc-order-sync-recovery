<?php
/**
 * Runs when the plugin is deleted from the Plugins screen.
 * Data is only removed if the admin ticked "Delete the job tables and settings" first.
 *
 * @package WCOSR
 */

defined('WP_UNINSTALL_PLUGIN') || exit;

require_once __DIR__ . '/src/Wp/Settings.php';
require_once __DIR__ . '/src/Wp/Installer.php';

\WCOSR\Wp\Installer::uninstall();
