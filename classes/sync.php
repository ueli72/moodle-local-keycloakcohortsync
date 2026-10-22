<?php
namespace local_keycloakcohortsync;

defined('MOODLE_INTERNAL') || die();

class sync {
    private string $baseurl;
    private string $realm;
    private string $clientid;
    private string $clientsecret;
    private string $matchfield;
    private bool $dryrun;
    private array $managedids;
    private array $cohorts = [];
    private string $debuguser;
    private int $skippedambiguous = 0;
    private int $skippednomatch = 0;

    public function __construct(?bool $dryrunoverride = null, string $debuguser = '') {
        $this->baseurl = rtrim((string)get_config('local_keycloakcohortsync', 'baseurl'), '/');
        $this->realm = (string)get_config('local_keycloakcohortsync', 'realm');
        $this->clientid = (string)get_config('local_keycloakcohortsync', 'clientid');
        $this->clientsecret = (string)get_config('local_keycloakcohortsync', 'clientsecret');
        $this->matchfield = (string)(get_config('local_keycloakcohortsync', 'matchfield') ?: 'email');
        $configured = (bool)get_config('local_keycloakcohortsync', 'dryrun');
        $this->dryrun = $dryrunoverride ?? $configured;
        $this->debuguser = trim($debuguser);
        $raw = (string)get_config('local_keycloakcohortsync', 'managedcohorts');
        $this->managedids = array_values(array_unique(array_filter(array_map('trim', preg_split('/\\R/', $raw)))));
    }

    public function run(): void {
        global $DB, $CFG;
        require_once($CFG->libdir . '/filelib.php');
        require_once($CFG->dirroot . '/cohort/lib.php');

        if (!$this->baseurl || !$this->realm || !$this->clientid || !$this->clientsecret) {
            throw new \moodle_exception('missingconfig', 'local_keycloakcohortsync');
        }
        if (!$this->managedids) {
            mtrace('[keycloakcohortsync] Keine verwalteten Cohort-IDs konfiguriert; nichts zu tun.');
            return;
        }

        $this->load_cohorts();
        $token = $this->get_access_token();
        $first = 0;
        $max = 100;
        $seen = 0;
        $matched = 0;
        $addcount = 0;
        $removecount = 0;
        $unchanged = 0;

        do {
            $users = $this->api_get('/admin/realms/' . rawurlencode($this->realm) . '/users?briefRepresentation=true&first=' . $first . '&max=' . $max, $token);
            if (!is_array($users)) {
                throw new \moodle_exception('invalidresponse', 'local_keycloakcohortsync');
            }
            foreach ($users as $kcuser) {
                $seen++;
                $moodleuser = $this->find_moodle_user($kcuser);
                if (!$moodleuser) {
                    continue;
                }
                $matched++;
                $wanted = [];
                $allgroupnames = [];
                if (!array_key_exists('enabled', $kcuser) || $kcuser['enabled']) {
                    $groups = $this->api_get('/admin/realms/' . rawurlencode($this->realm) . '/users/' . rawurlencode($kcuser['id']) . '/groups?briefRepresentation=true', $token);
                    foreach ($groups as $group) {
                        if (!empty($group['name'])) {
                            $allgroupnames[] = $group['name'];
                            if (isset($this->cohorts[$group['name']])) {
                                $wanted[$group['name']] = true;
                            }
                        }
                    }
                }
                $debug = $this->is_debug_target($kcuser, $moodleuser);
                $result = $this->sync_user($moodleuser, array_keys($wanted), $debug, $allgroupnames, $kcuser);
                $addcount += $result['adds'];
                $removecount += $result['removes'];
                if ($result['adds'] === 0 && $result['removes'] === 0) {
                    $unchanged++;
                }
            }
            $count = count($users);
            $first += $count;
        } while ($count === $max);

        $mode = $this->dryrun ? 'DRY-RUN' : 'APPLY';
        mtrace('[keycloakcohortsync] --------------------------------------------------');
        mtrace('[keycloakcohortsync] Synchronisation abgeschlossen');
        mtrace(sprintf('[keycloakcohortsync] Keycloak-Benutzer geprüft:       %d', $seen));
        mtrace(sprintf('[keycloakcohortsync] Moodle-Benutzer zugeordnet:      %d', $matched));
        mtrace(sprintf('[keycloakcohortsync] Hinzufügen%s:                     %d', $this->dryrun ? ' geplant' : '', $addcount));
        mtrace(sprintf('[keycloakcohortsync] Entfernen%s:                      %d', $this->dryrun ? ' geplant' : '', $removecount));
        mtrace(sprintf('[keycloakcohortsync] Unverändert:                     %d', $unchanged));
        mtrace(sprintf('[keycloakcohortsync] Übersprungen (mehrdeutige E-Mail): %d', $this->skippedambiguous));
        mtrace(sprintf('[keycloakcohortsync] Übersprungen (kein Moodle-Konto): %d', $this->skippednomatch));
        mtrace(sprintf('[keycloakcohortsync] Modus:                           %s', $mode));
        mtrace('[keycloakcohortsync] --------------------------------------------------');
    }

    private function load_cohorts(): void {
        global $DB;
        [$insql, $params] = $DB->get_in_or_equal($this->managedids, SQL_PARAMS_NAMED, 'cid');
        $records = $DB->get_records_select('cohort', "idnumber $insql", $params, '', 'id,idnumber,name');
        foreach ($records as $cohort) {
            $this->cohorts[$cohort->idnumber] = $cohort;
        }
        $missing = array_diff($this->managedids, array_keys($this->cohorts));
        foreach ($missing as $idnumber) {
            mtrace('[keycloakcohortsync] WARNUNG: Moodle-Cohort mit idnumber "' . $idnumber . '" existiert nicht.');
        }
    }

    private function find_moodle_user(array $kcuser): ?\stdClass {
        global $DB, $CFG;
        if ($this->matchfield === 'username') {
            $value = $kcuser['username'] ?? '';
            if ($value === '') {
                $this->skippednomatch++;
                return null;
            }
            $record = $DB->get_record('user', ['username' => $value, 'mnethostid' => $CFG->mnet_localhost_id, 'deleted' => 0], 'id,username,email', IGNORE_MISSING) ?: null;
            if (!$record) {
                $this->skippednomatch++;
            }
            return $record;
        }
        $value = $kcuser['email'] ?? '';
        if ($value === '') {
            $this->skippednomatch++;
            return null;
        }
        $matches = $DB->get_records('user', ['email' => $value, 'mnethostid' => $CFG->mnet_localhost_id, 'deleted' => 0], '', 'id,username,email', 0, 2);
        if (count($matches) !== 1) {
            if (count($matches) > 1) {
                $this->skippedambiguous++;
                mtrace('[keycloakcohortsync] WARNUNG: E-Mail nicht eindeutig in Moodle: ' . $value);
            } else {
                $this->skippednomatch++;
            }
            return null;
        }
        return reset($matches);
    }

    private function sync_user(\stdClass $user, array $wantedids, bool $debug = false, array $keycloakgroups = [], array $kcuser = []): array {
        global $DB;
        $managedcohortids = array_map(fn($c) => (int)$c->id, $this->cohorts);
        if (!$managedcohortids) { return ['adds' => 0, 'removes' => 0]; }
        [$insql, $params] = $DB->get_in_or_equal($managedcohortids, SQL_PARAMS_NAMED, 'co');
        $params['userid'] = $user->id;
        $memberships = $DB->get_records_sql("SELECT cm.id, cm.cohortid, c.idnumber FROM {cohort_members} cm JOIN {cohort} c ON c.id = cm.cohortid WHERE cm.userid = :userid AND cm.cohortid $insql", $params);
        $current = [];
        foreach ($memberships as $m) { $current[$m->idnumber] = true; }
        $wanted = array_fill_keys(array_intersect($wantedids, array_keys($this->cohorts)), true);
        $adds = array_diff_key($wanted, $current);
        $removes = array_diff_key($current, $wanted);
        if ($debug) {
            $fmt = static function(array $values): string {
                return $values ? implode(', ', $values) : '(keine)';
            };
            $kcusername = (string)($kcuser['username'] ?? '');
            $kcemail = (string)($kcuser['email'] ?? '');
            mtrace('[keycloakcohortsync] DEBUG --------------------------------------------------');
            mtrace('[keycloakcohortsync] Keycloak user: ' . $kcusername . ($kcemail !== '' ? ' <' . $kcemail . '>' : ''));
            mtrace('[keycloakcohortsync] Moodle user:   ' . $user->username . ($user->email !== '' ? ' <' . $user->email . '>' : ''));
            mtrace('[keycloakcohortsync] Keycloak groups:        ' . $fmt($keycloakgroups));
            mtrace('[keycloakcohortsync] Matched managed cohorts: ' . $fmt(array_keys($wanted)));
            mtrace('[keycloakcohortsync] Current Moodle cohorts:  ' . $fmt(array_keys($current)));
            mtrace('[keycloakcohortsync] Planned ADD:             ' . $fmt(array_keys($adds)));
            mtrace('[keycloakcohortsync] Planned REMOVE:          ' . $fmt(array_keys($removes)));
            mtrace('[keycloakcohortsync] DEBUG --------------------------------------------------');
        }

        foreach (array_keys($adds) as $idnumber) {
            mtrace(sprintf('[keycloakcohortsync] ADD user=%s cohort=%s', $user->username, $idnumber));
            if (!$this->dryrun) { cohort_add_member($this->cohorts[$idnumber]->id, $user->id); }
        }
        foreach (array_keys($removes) as $idnumber) {
            mtrace(sprintf('[keycloakcohortsync] REMOVE user=%s cohort=%s', $user->username, $idnumber));
            if (!$this->dryrun) { cohort_remove_member($this->cohorts[$idnumber]->id, $user->id); }
        }
        return ['adds' => count($adds), 'removes' => count($removes)];
    }


    private function is_debug_target(array $kcuser, \stdClass $moodleuser): bool {
        if ($this->debuguser === '') {
            return false;
        }
        $needle = \core_text::strtolower($this->debuguser);
        $values = [
            (string)($kcuser['username'] ?? ''),
            (string)($kcuser['email'] ?? ''),
            (string)($moodleuser->username ?? ''),
            (string)($moodleuser->email ?? ''),
        ];
        foreach ($values as $value) {
            if ($value !== '' && \core_text::strtolower($value) === $needle) {
                return true;
            }
        }
        return false;
    }

    private function get_access_token(): string {
        require_once(__DIR__ . '/../../../lib/filelib.php');
        $url = $this->baseurl . '/realms/' . rawurlencode($this->realm) . '/protocol/openid-connect/token';
        $curl = new \curl();
        $curl->setHeader(['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json']);
        $postdata = http_build_query([
            'grant_type' => 'client_credentials',
            'client_id' => $this->clientid,
            'client_secret' => $this->clientsecret,
        ], '', '&', PHP_QUERY_RFC3986);
        $response = $curl->post($url, $postdata);
        $info = $curl->get_info();
        $httpcode = (int)($info['http_code'] ?? 0);
        $data = json_decode($response, true);
        if ($httpcode < 200 || $httpcode >= 300 || empty($data['access_token'])) {
            $detail = 'HTTP ' . $httpcode;
            if (is_array($data)) {
                $error = $data['error'] ?? '';
                $description = $data['error_description'] ?? '';
                if ($error !== '') {
                    $detail .= ' ' . $error;
                }
                if ($description !== '') {
                    $detail .= ': ' . $description;
                }
            }
            throw new \moodle_exception('tokenerror', 'local_keycloakcohortsync', '', null, $detail);
        }
        return $data['access_token'];
    }

    private function api_get(string $path, string $token): array {
        $curl = new \curl();
        $curl->setHeader(['Authorization: Bearer ' . $token, 'Accept: application/json']);
        $response = $curl->get($this->baseurl . $path);
        $info = $curl->get_info();
        if (($info['http_code'] ?? 0) < 200 || ($info['http_code'] ?? 0) >= 300) {
            throw new \moodle_exception('apierror', 'local_keycloakcohortsync', '', null, 'HTTP ' . ($info['http_code'] ?? 0) . ' ' . $path);
        }
        $data = json_decode($response, true);
        if (!is_array($data)) {
            throw new \moodle_exception('invalidresponse', 'local_keycloakcohortsync');
        }
        return $data;
    }
}
