<?php
defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_keycloakcohortsync', get_string('pluginname', 'local_keycloakcohortsync'));
    $ADMIN->add('localplugins', $settings);

    $settings->add(new admin_setting_configtext(
        'local_keycloakcohortsync/baseurl',
        get_string('baseurl', 'local_keycloakcohortsync'),
        get_string('baseurl_desc', 'local_keycloakcohortsync'),
        'https://sso.bws-uster.ch',
        PARAM_URL
    ));
    $settings->add(new admin_setting_configtext(
        'local_keycloakcohortsync/realm',
        get_string('realm', 'local_keycloakcohortsync'),
        '',
        'simsysbwsu',
        PARAM_ALPHANUMEXT
    ));
    $settings->add(new admin_setting_configtext(
        'local_keycloakcohortsync/clientid',
        get_string('clientid', 'local_keycloakcohortsync'),
        get_string('clientid_desc', 'local_keycloakcohortsync'),
        '',
        PARAM_TEXT
    ));
    $settings->add(new admin_setting_configpasswordunmask(
        'local_keycloakcohortsync/clientsecret',
        get_string('clientsecret', 'local_keycloakcohortsync'),
        get_string('clientsecret_desc', 'local_keycloakcohortsync'),
        ''
    ));
    $settings->add(new admin_setting_configselect(
        'local_keycloakcohortsync/matchfield',
        get_string('matchfield', 'local_keycloakcohortsync'),
        get_string('matchfield_desc', 'local_keycloakcohortsync'),
        'email',
        ['email' => get_string('email'), 'username' => get_string('username')]
    ));
    $settings->add(new admin_setting_configtextarea(
        'local_keycloakcohortsync/managedcohorts',
        get_string('managedcohorts', 'local_keycloakcohortsync'),
        get_string('managedcohorts_desc', 'local_keycloakcohortsync'),
        '',
        PARAM_RAW_TRIMMED
    ));
    $settings->add(new admin_setting_configcheckbox(
        'local_keycloakcohortsync/dryrun',
        get_string('dryrun', 'local_keycloakcohortsync'),
        get_string('dryrun_desc', 'local_keycloakcohortsync'),
        1
    ));

    // Suspension sync (leaver deprovisioning).
    $settings->add(new admin_setting_configcheckbox(
        'local_keycloakcohortsync/suspendenable',
        get_string('suspendenable', 'local_keycloakcohortsync'),
        get_string('suspendenable_desc', 'local_keycloakcohortsync'),
        0
    ));
    $settings->add(new admin_setting_configcheckbox(
        'local_keycloakcohortsync/suspenddryrun',
        get_string('suspenddryrun', 'local_keycloakcohortsync'),
        get_string('suspenddryrun_desc', 'local_keycloakcohortsync'),
        1
    ));
    $settings->add(new admin_setting_configtextarea(
        'local_keycloakcohortsync/suspendignore',
        get_string('suspendignore', 'local_keycloakcohortsync'),
        get_string('suspendignore_desc', 'local_keycloakcohortsync'),
        '',
        PARAM_RAW_TRIMMED
    ));
}
