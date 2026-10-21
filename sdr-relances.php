<?php
/**
 * Plugin Name: SDR — Relances utilisateurs
 * Description: Filtres de profils, emails personnalisés et prévisualisation dans Utilisateurs → Relances utilisateurs.
 * Version: 1.0.0
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Text Domain: sdr-relances
 */
defined('ABSPATH') || exit;

require_once __DIR__ . '/relances/class-sdr-relances.php';
SDR_Relances::boot();
