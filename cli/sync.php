<?php
#define CLI_SCRIPT before config.php.
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

[$options, $unrecognized] = cli_get_params([
    'help' => false,
    'apply' => false,
    'debug-user' => '',
], [
    'h' => 'help',
]);
if ($options['help']) {
    echo "Keycloak -> Moodle Cohort Sync\n\n";
    echo "Standardmäßig wird die Plugin-Einstellung dryrun verwendet.\n";
    echo "--apply              Dry-run für diesen Lauf ausschalten.\n";
    echo "--debug-user=VALUE   Debugdetails für genau einen Benutzer (E-Mail oder Benutzername).\n";
    exit(0);
}
if ($options['apply']) {
    echo "WARNUNG: Änderungen werden in diesem Lauf tatsächlich ausgeführt.\n";
}
$sync = new \local_keycloakcohortsync\sync($options['apply'] ? false : null, (string)$options['debug-user']);
$sync->run();
