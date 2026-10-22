<?php
namespace local_keycloakcohortsync\task;

defined('MOODLE_INTERNAL') || die();

class sync_cohorts extends \core\task\scheduled_task {
    public function get_name(): string {
        return get_string('task_sync', 'local_keycloakcohortsync');
    }

    public function execute() {
        $sync = new \local_keycloakcohortsync\sync();
        $sync->run();
    }
}
