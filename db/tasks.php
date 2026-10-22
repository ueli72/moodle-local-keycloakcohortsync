<?php
defined('MOODLE_INTERNAL') || die();

$tasks = [
    [
        'classname' => '\\local_keycloakcohortsync\\task\\sync_cohorts',
        'blocking' => 0,
        'minute' => '*/15',
        'hour' => '*',
        'day' => '*',
        'month' => '*',
        'dayofweek' => '*',
    ],
    [
        'classname' => '\\local_keycloakcohortsync\\task\\sync_suspensions',
        'blocking' => 0,
        'minute' => '5-59/15',
        'hour' => '*',
        'day' => '*',
        'month' => '*',
        'dayofweek' => '*',
    ],
];
