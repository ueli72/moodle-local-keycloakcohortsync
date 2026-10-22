<?php
namespace local_keycloakcohortsync\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Suspend Moodle users that have left the school (Keycloak + LDAP double check).
 */
class sync_suspensions extends \core\task\scheduled_task {
    public function get_name(): string {
        return get_string('task_suspensions', 'local_keycloakcohortsync');
    }

    public function execute() {
        $sync = new \local_keycloakcohortsync\suspensions();
        $sync->run();
    }
}
