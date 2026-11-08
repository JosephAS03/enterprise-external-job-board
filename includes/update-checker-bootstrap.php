<?php
if (!defined('ABSPATH')) exit;

function eejb_register_plugin_update_checker(): void {
  $config = require EEJB_DIR . 'includes/update-checker-config.php';
  if (!is_array($config)) return;
  $config = apply_filters('eejb_update_checker_config', $config, EEJB_FILE);
  $repository = trim((string)($config['repository'] ?? ''));
  if ($repository === '') return;

  $factory = 'YahnisElsts\\PluginUpdateChecker\\v6\\PucFactory';
  if (!class_exists($factory, false)) {
    require_once EEJB_DIR . 'vendor/plugin-update-checker/plugin-update-checker.php';
  }
  if (!class_exists($factory, false)) return;

  $checker = $factory::buildUpdateChecker($repository, EEJB_FILE, 'enterprise-external-job-board');
  $branch = trim((string)($config['branch'] ?? ''));
  if ($branch !== '') $checker->setBranch($branch);

  $token_constant = (string)($config['token_constant'] ?? '');
  $token = $token_constant !== '' && defined($token_constant) ? (string)constant($token_constant) : '';
  $token = (string)apply_filters('eejb_update_checker_github_token', $token, EEJB_FILE);
  if ($token !== '') $checker->setAuthentication($token);
}

add_action('plugins_loaded', 'eejb_register_plugin_update_checker', 1);
