<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Suspends Moodle users that have left the school.
 *
 * Signal sources:
 *  - Keycloak user list (a user absent or disabled in Keycloak is a candidate).
 *  - LDAP directory (a user still present in the active tree is NOT suspended;
 *    only users that are gone entirely or only found in the leavers OU are).
 *
 * The LDAP double-check is required because Keycloak imports users lazily:
 * an active LDAP user must never be suspended just because Keycloak has not
 * imported them yet.
 *
 * @package   local_keycloakcohortsync
 * @copyright 2026 BWS Uster
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_keycloakcohortsync;

defined('MOODLE_INTERNAL') || die();

class suspensions {

    /** @var string Keycloak base URL, e.g. https://sso.example.org */
    private string $baseurl;

    /** @var string Keycloak realm */
    private string $realm;

    /** @var string Admin API client id */
    private string $clientid;

    /** @var string Admin API client secret */
    private string $clientsecret;

    /** @var array<string,string> Ignore list keyed by lowercase username/email => username */
    private array $ignore = [];

    /** @var bool Dry-run mode */
    private bool $dryrun;

    /** @var array<string,array> Keycloak users keyed by lowercase email */
    private array $kcusers = [];

    /** @var array LDAP config taken from the auth_ldap_syncplus (or auth_ldap) plugin */
    private array $ldapconfig;

    /** @var \LDAP\Connection|resource|null */
    private $ldapconnection = null;

    /** @var array Users to suspend (LDAP leaver confirmed) */
    private array $tosuspend = [];

    /** @var array Users to unsuspend (back from the dead) */
    private array $tounsuspend = [];

    /** @var int Users active in LDAP but not yet imported to Keycloak (skipped) */
    private int $skippedunimported = 0;

    /** @var int Users skipped via ignore list / never logged in / admin */
    private int $skippedirrelevant = 0;

    public function __construct(?bool $dryrunoverride = null) {
        $this->baseurl = rtrim((string)get_config('local_keycloakcohortsync', 'baseurl'), '/');
        $this->realm = (string)get_config('local_keycloakcohortsync', 'realm');
        $this->clientid = (string)get_config('local_keycloakcohortsync', 'clientid');
        $this->clientsecret = (string)get_config('local_keycloakcohortsync', 'clientsecret');
        $configured = (bool)get_config('local_keycloakcohortsync', 'suspenddryrun');
        $this->dryrun = $dryrunoverride ?? $configured;
        $raw = (string)get_config('local_keycloakcohortsync', 'suspendignore');
        foreach (preg_split('/\R/', $raw) as $line) {
            $line = trim(\core_text::strtolower($line));
            if ($line !== '') {
                $this->ignore[$line] = $line;
            }
        }
        $this->ldapconfig = $this->load_ldap_config();
    }

    /**
     * Run the suspension sync.
     */
    public function run(): void {
        global $DB, $CFG;

        if (!$this->baseurl || !$this->realm || !$this->clientid || !$this->clientsecret) {
            throw new \moodle_exception('missingconfig', 'local_keycloakcohortsync');
        }
        if (empty(get_config('local_keycloakcohortsync', 'suspendenable'))) {
            mtrace('[kcsuspensions] Suspendierungs-Sync ist inaktiv (Einstellung suspendenable).');
            return;
        }
        if (empty($this->ldapconfig['host_url']) || empty($this->ldapconfig['bind_dn'])) {
            throw new \moodle_exception('susperrornoldap', 'local_keycloakcohortsync');
        }

        // Connect to LDAP first: if the directory is unreachable we must not
        // suspend anyone (absence in Keycloak alone is not a leaver signal).
        $this->ldap_connect_and_bind();

        $this->load_keycloak_users();

        $params = ['mnethostid' => $CFG->mnet_localhost_id];
        $users = $DB->get_records_select(
            'user',
            "deleted = 0 AND suspended IN (0,1)
                AND auth IN ('ldap', 'ldap_syncplus', 'oauth2')
                AND lastlogin > 0
                AND mnethostid = :mnethostid",
            $params
        );

        $seen = 0;
        foreach ($users as $user) {
            if (is_siteadmin($user)) {
                $this->skippedirrelevant++;
                continue;
            }
            if ($this->is_ignored($user)) {
                $this->skippedirrelevant++;
                continue;
            }
            $seen++;

            $kcuser = $this->kcusers[\core_text::strtolower($user->email)] ?? null;
            $kcpresent = $kcuser !== null && !empty($kcuser['enabled']);

            if ($kcpresent) {
                if (!empty($user->suspended)) {
                    $this->tounsuspend[] = $user;
                }
                continue;
            }

            // Candidate for suspension - check the LDAP directory.
            if ($this->is_leaver_in_ldap($user)) {
                if (empty($user->suspended)) {
                    $this->tosuspend[] = $user;
                }
            } else {
                // Still active in LDAP: Keycloak simply has not imported the user yet.
                $this->skippedunimported++;
            }
        }

        $this->apply();

        $mode = $this->dryrun ? 'DRY-RUN' : 'APPLY';
        mtrace('[kcsuspensions] --------------------------------------------------');
        mtrace('[kcsuspensions] Suspendierungs-Synchronisation abgeschlossen');
        mtrace(sprintf('[kcsuspensions] Moodle-Benutzer geprüft:          %d', $seen));
        mtrace(sprintf('[kcsuspensions] Suspendieren%s:               %d', $this->dryrun ? ' geplant' : '', count($this->tosuspend)));
        mtrace(sprintf('[kcsuspensions] Entsperren%s:                 %d', $this->dryrun ? ' geplant' : '', count($this->tounsuspend)));
        mtrace(sprintf('[kcsuspensions] Übersprungen (in LDAP aktiv, nicht in Keycloak importiert): %d', $this->skippedunimported));
        mtrace(sprintf('[kcsuspensions] Übersprungen (irrelevant/Admin/Ignorierliste): %d', $this->skippedirrelevant));
        mtrace(sprintf('[kcsuspensions] Modus:                            %s', $mode));
        mtrace('[kcsuspensions] --------------------------------------------------');
    }

    /**
     * Apply the planned suspensions / unsuspensions.
     */
    private function apply(): void {
        global $DB;

        foreach ($this->tosuspend as $user) {
            mtrace(sprintf('[kcsuspensions] SUSPEND user=%s <%s>%s', $user->username, $user->email, $this->dryrun ? ' (geplant)' : ''));
        }
        foreach ($this->tounsuspend as $user) {
            mtrace(sprintf('[kcsuspensions] UNSUSPEND user=%s <%s>%s', $user->username, $user->email, $this->dryrun ? ' (geplant)' : ''));
        }

        if ($this->dryrun) {
            return;
        }

        foreach ($this->tosuspend as $user) {
            $DB->set_field('user', 'suspended', 1, ['id' => $user->id]);
        }
        foreach ($this->tounsuspend as $user) {
            $DB->set_field('user', 'suspended', 0, ['id' => $user->id]);
        }
    }

    /**
     * Fetch all Keycloak users of the realm and index them by email.
     */
    private function load_keycloak_users(): void {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');

        $token = $this->get_access_token();
        $first = 0;
        $max = 500;
        do {
            $users = $this->api_get('/admin/realms/' . rawurlencode($this->realm)
                . '/users?briefRepresentation=true&first=' . $first . '&max=' . $max, $token);
            foreach ($users as $u) {
                if (!empty($u['email'])) {
                    $this->kcusers[\core_text::strtolower($u['email'])] = $u;
                }
            }
            $count = count($users);
            $first += $count;
        } while ($count === $max);
    }

    /**
     * Get an admin API access token (client credentials).
     */
    private function get_access_token(): string {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');

        $curl = new \curl();
        $curl->setHeader(['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json']);
        $postdata = http_build_query([
            'grant_type' => 'client_credentials',
            'client_id' => $this->clientid,
            'client_secret' => $this->clientsecret,
        ], '', '&', PHP_QUERY_RFC3986);
        $response = $curl->post($this->baseurl . '/realms/' . rawurlencode($this->realm) . '/protocol/openid-connect/token', $postdata);
        $info = $curl->get_info();
        $data = json_decode($response, true);
        if ((int)($info['http_code'] ?? 0) < 200 || (int)($info['http_code'] ?? 0) >= 300 || empty($data['access_token'])) {
            throw new \moodle_exception('tokenerror', 'local_keycloakcohortsync');
        }
        return $data['access_token'];
    }

    /**
     * Call the Keycloak admin API.
     *
     * @param string $path Path beginning with /admin/realms/...
     * @param string $token Access token
     * @return array
     */
    private function api_get(string $path, string $token): array {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');

        $curl = new \curl();
        $curl->setHeader(['Authorization: Bearer ' . $token, 'Accept: application/json']);
        $response = $curl->get($this->baseurl . $path);
        $info = $curl->get_info();
        if ((int)($info['http_code'] ?? 0) < 200 || (int)($info['http_code'] ?? 0) >= 300) {
            throw new \moodle_exception('apierror', 'local_keycloakcohortsync');
        }
        $data = json_decode($response, true);
        if (!is_array($data)) {
            throw new \moodle_exception('invalidresponse', 'local_keycloakcohortsync');
        }
        return $data;
    }

    /**
     * Load the LDAP connection settings from the auth plugins.
     *
     * @return array
     */
    private function load_ldap_config(): array {
        $config = [];
        foreach (['auth_ldap_syncplus', 'auth_ldap'] as $plugin) {
            foreach (['host_url', 'bind_dn', 'bind_pw', 'contexts', 'search_sub'] as $key) {
                $value = get_config($plugin, $key);
                if ($value !== false && $value !== null && $value !== '') {
                    $config[$key] = $value;
                }
            }
            if (!empty($config['host_url']) && !empty($config['bind_dn'])) {
                break;
            }
        }
        return $config;
    }

    /**
     * Connect and bind to the LDAP directory. Throws on failure.
     */
    private function ldap_connect_and_bind(): void {
        $urlparts = explode(' ', $this->ldapconfig['host_url']);
        $conn = \ldap_connect($urlparts[0]);
        if (!$conn) {
            throw new \moodle_exception('susperrornoldap', 'local_keycloakcohortsync');
        }
        \ldap_set_option($conn, LDAP_OPT_PROTOCOL_VERSION, 3);
        $bound = @\ldap_bind($conn, $this->ldapconfig['bind_dn'], $this->ldapconfig['bind_pw']);
        if (!$bound) {
            throw new \moodle_exception('susperrornoldap', 'local_keycloakcohortsync');
        }
        $this->ldapconnection = $conn;
    }

    /**
     * Decide whether the user has left the school.
     *
     * A user is a leaver when they cannot be found in the active LDAP tree.
     * Users that are only present in the leavers OU (ou=ausgetreten) count as
     * leavers as well.
     *
     * @param \stdClass $user Moodle user
     * @return bool true if the user has left, false if the user is still active in LDAP
     */
    private function is_leaver_in_ldap(\stdClass $user): bool {
        $email = \core_text::strtolower($user->email);
        $filter = '(|(mail=' . \ldap_escape($email, '', LDAP_ESCAPE_FILTER)
            . ')(uid=' . \ldap_escape($user->username, '', LDAP_ESCAPE_FILTER) . '))';

        $contexts = array_map('trim', explode(';', $this->ldapconfig['contexts']));
        foreach ($contexts as $context) {
            if ($context === '') {
                continue;
            }
            $result = @\ldap_search($this->ldapconnection, $context, $filter, ['dn']);
            if ($result === false) {
                continue;
            }
            $entries = \ldap_get_entries($this->ldapconnection, $result);
            if (!empty($entries['count'])) {
                // User found. Leaver if only present in the leavers OU.
                $dn = \core_text::strtolower($entries[0]['dn']);
                return strpos($dn, 'ou=ausgetreten') !== false;
            }
        }
        // Not found anywhere in the configured tree.
        return true;
    }

    /**
     * Is the user explicitly excluded (ignore list, username or email)?
     *
     * @param \stdClass $user Moodle user
     * @return bool
     */
    private function is_ignored(\stdClass $user): bool {
        if (isset($this->ignore[\core_text::strtolower($user->username)])) {
            return true;
        }
        return isset($this->ignore[\core_text::strtolower($user->email)]);
    }
}
