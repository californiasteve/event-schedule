<?php
/*
Plugin Name: Convention Schedule
Description: Manage and display an event workshop schedule with dates, times, rooms, categories, and multiple layouts.
Version: 1.9.0
Author: California Steve Web Design
Text Domain: convention-schedule
*/

if (!defined('ABSPATH')) exit;

define('CSCHED_VER', '1.9.0');
define('CSCHED_PATH', plugin_dir_path(__FILE__));
define('CSCHED_URL', plugin_dir_url(__FILE__));

require_once CSCHED_PATH . 'includes/class-cs-plugin.php';
require_once CSCHED_PATH . 'includes/class-cs-admin.php';
require_once CSCHED_PATH . 'includes/class-cs-shortcodes.php';

register_activation_hook(__FILE__, function () {
    CS_Plugin::activate();
});

add_action('plugins_loaded', function () {
    CS_Plugin::init();
});
