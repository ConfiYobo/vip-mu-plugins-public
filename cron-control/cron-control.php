<?php
/*
 Plugin Name: Cron Control
 Plugin URI:
 Description: Execute WordPress cron events in parallel, using a custom post type for event storage.
 Author: Erick Hitter, Automattic
 Version: 1.5
 Text Domain: automattic-cron-control
 */

namespace Automattic\WP\Cron_Control;

// Load basics needed to instantiate plugin
require __DIR__ . '/includes/abstract-class-singleton.php';

// Instantiate main plugin class, which checks environment and loads remaining classes when appropriate
require __DIR__ . '/includes/class-main.php';
Main::instance();
