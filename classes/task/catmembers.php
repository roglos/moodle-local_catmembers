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
 * A scheduled task for scripted database integrations.
 *
 * @package    local_catmembers
 * @copyright  2016 ROelmann
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catmembers\task;
use stdClass;

defined('MOODLE_INTERNAL') || die;

/**
 * A scheduled task for scripted external database integrations.
 *
 * @copyright  2016 ROelmann
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class catmembers extends \core\task\scheduled_task {

    /**
     * Get a descriptive name for this task (shown to admins).
     *
     * @return string
     */
    public function get_name() {
        return get_string('pluginname', 'local_catmembers');
    }

    /**
     * Run sync.
     */
    public function execute() {

        global $CFG, $DB;

        // Check connection and label Db/Table in cron output for debugging if required.
        if (!$this->get_config('dbtype')) {
            echo 'Database not defined.<br>';
            return 0;
        } else {
            echo 'Database: ' . $this->get_config('dbtype') . '<br>';
        }
        if (!$this->get_config('remotetable')) {
            echo 'Table not defined.<br>';
            return 0;
        } else {
            echo 'Table: ' . $this->get_config('remotetable') . '<br>';
        }
        echo 'Starting connection...<br>';

        // Report connection error if occurs.
        if (!$extdb = $this->db_init()) {
            echo 'Error while communicating with external database <br>';
            return 1;
        }

        /* Get array of Role IDs.
         * ---------------------- */
        $roleslist = array();
        $roleslist = $DB->get_records('role');
        $roles = array();
        foreach ($roleslist as $r) {
            $roles[$r->shortname] = $r->id;
        }

        /* Get array of Category IDs.
         * -------------------------- */
        $categories = array();
        $categories = $DB->get_records('course_categories');
        $catid = array();
        $subcommcats = array();
        $subcommidnum = array();
        foreach ($categories as $category) {
            $catid[$category->idnumber] = $category->id;  // Get ALL categories.
            if (strpos($category->idnumber, 'SUB-') === false || strpos($category->idnumber, 'SUB-') > 0) {
                continue;
            }
            $subcommcats[$category->idnumber] = $category->id;  // Get Subject Communities ids.
            $subcommidnum[$category->id] = $category->idnumber;  // Get Subject Community idnumbers.
        }

        /* Get array of contexts for categories.
         * ------------------------------------- */
        $contexts = array();
        $sql = 'SELECT * FROM {context} WHERE contextlevel = ' . CONTEXT_COURSECAT;
        $contexts = $DB->get_records_sql($sql);
        $catcontext = array();
        $subjcommcontext = array();
        foreach ($contexts as $context) {
            $catcontext[$context->instanceid] = $context->id; // Get context for ALL categories.
            if (in_array($context->instanceid, $subcommcats)) { // Get context for Subject Communities.
                $subjcommcontext[$context->instanceid] = $context->id;
            }
        }

        /*
         * Get Academic Leads from usr_data_categorymembers.
         */
        // Get external table name.
        $table = $this->get_config('remotetable');

        // Read data from table.
        $sql = $this->db_get_sql($table, array(), array(), true);
        if ($rs = $extdb->Execute($sql)) {
            if (!$rs->EOF) {
                while ($fields = $rs->FetchRow()) {
                    $fields = array_change_key_case($fields, CASE_LOWER);
                    $fields = $this->db_decode($fields);
                    $aclead = $fields; // Switch to use readable variable name from template name.

                    // Get user details primarily user->id.
                    $useridnumber = $aclead['staffnumber'];
                    $sqluser = "SELECT * FROM {user} WHERE username = '".$useridnumber."'";
                    $userid = '';
                    if ($DB->get_record_sql($sqluser)) {
                        $userid = $DB->get_record_sql($sqluser);  // User ID.

                        // Check that user is not marked as deleted.
                        if ($userid->deleted == 1) {
                            continue;
                        }
                    }

                    // Get role details primarily role->id.
                    $role = $aclead['role'];
                    $roleid = '';
                    if ($roles[$role]) {
                        $roleid = $roles[$role];  // Role ID.
                    }

                    // Get category details primarily category->context->id.
                    $catidnumber = $aclead['category_idnumber'];
                    $categoryid = '';
                    $catcontextid = '';
                    if ($catid[$catidnumber]) {
                        $categoryid = $catid[$catidnumber];
                        $catcontextid = $catcontext[$categoryid];  // Context ID.
                    }

                    // Set role assignment for user->id on category->context->id with role->id.
                    if ($userid !== '' && $roleid !== '' && $catcontextid !== '') {
                        role_assign($roleid, $userid->id, $catcontextid);
                    }

                }
            }
            $rs->Close();
        } else {
            // Report error if required.
            $extdb->Close();
            echo 'Error reading data from the external course table<br>';
            return 4;
        }

        // Free memory.
        $extdb->Close();

    }

    /* Db functions cloned from enrol/db plugin.
     * ========================================= */

    /**
     * Tries to make connection to the external database.
     *
     * @return null|ADONewConnection
     */
    public function db_init() {
        global $CFG;

        require_once($CFG->libdir.'/adodb/adodb.inc.php');

        // Connect to the external database (forcing new connection).
        $extdb = ADONewConnection($this->get_config('dbtype'));
        if ($this->get_config('debugdb')) {
            $extdb->debug = true;
            ob_start(); // Start output buffer to allow later use of the page headers.
        }

        // The dbtype my contain the new connection URL, so make sure we are not connected yet.
        if (!$extdb->IsConnected()) {
            $result = $extdb->Connect($this->get_config('dbhost'),
                $this->get_config('dbuser'),
                $this->get_config('dbpass'),
                $this->get_config('dbname'), true);
            if (!$result) {
                return null;
            }
        }

        $extdb->SetFetchMode(ADODB_FETCH_ASSOC);
        if ($this->get_config('dbsetupsql')) {
            $extdb->Execute($this->get_config('dbsetupsql'));
        }
        return $extdb;
    }

    public function db_addslashes($text) {
        // Use custom made function for now - it is better to not rely on adodb or php defaults.
        if ($this->get_config('dbsybasequoting')) {
            $text = str_replace('\\', '\\\\', $text);
            $text = str_replace(array('\'', '"', "\0"), array('\\\'', '\\"', '\\0'), $text);
        } else {
            $text = str_replace("'", "''", $text);
        }
        return $text;
    }

    public function db_encode($text) {
        $dbenc = $this->get_config('dbencoding');
        if (empty($dbenc) or $dbenc == 'utf-8') {
            return $text;
        }
        if (is_array($text)) {
            foreach ($text as $k => $value) {
                $text[$k] = $this->db_encode($value);
            }
            return $text;
        } else {
            return core_text::convert($text, 'utf-8', $dbenc);
        }
    }

    public function db_decode($text) {
        $dbenc = $this->get_config('dbencoding');
        if (empty($dbenc) or $dbenc == 'utf-8') {
            return $text;
        }
        if (is_array($text)) {
            foreach ($text as $k => $value) {
                $text[$k] = $this->db_decode($value);
            }
            return $text;
        } else {
            return core_text::convert($text, $dbenc, 'utf-8');
        }
    }

    public function db_get_sql($table, array $conditions, array $fields, $distinct = false, $sort = "") {
        $fields = $fields ? implode(',', $fields) : "*";
        $where = array();
        if ($conditions) {
            foreach ($conditions as $key => $value) {
                $value = $this->db_encode($this->db_addslashes($value));

                $where[] = "$key = '$value'";
            }
        }
        $where = $where ? "WHERE ".implode(" AND ", $where) : "";
        $sort = $sort ? "ORDER BY $sort" : "";
        $distinct = $distinct ? "DISTINCT" : "";
        $sql = "SELECT $distinct $fields
                  FROM $table
                 $where
                  $sort";
        return $sql;
    }

    public function db_get_sql_like($table2, array $conditions, array $fields, $distinct = false, $sort = "") {
        $fields = $fields ? implode(',', $fields) : "*";
        $where = array();
        if ($conditions) {
            foreach ($conditions as $key => $value) {
                $value = $this->db_encode($this->db_addslashes($value));

                $where[] = "$key LIKE '%$value%'";
            }
        }
        $where = $where ? "WHERE ".implode(" AND ", $where) : "";
        $sort = $sort ? "ORDER BY $sort" : "";
        $distinct = $distinct ? "DISTINCT" : "";
        $sql2 = "SELECT $distinct $fields
                  FROM $table2
                 $where
                  $sort";
        return $sql2;
    }


    /**
     * Returns plugin config value
     * @param  string $name
     * @param  string $default value if config does not exist yet
     * @return string value or default
     */
    public function get_config($name, $default = null) {
        $this->load_config();
        return isset($this->config->$name) ? $this->config->$name : $default;
    }

    /**
     * Sets plugin config value
     * @param  string $name name of config
     * @param  string $value string config value, null means delete
     * @return string value
     */
    public function set_config($name, $value) {
        $pluginname = $this->get_name();
        $this->load_config();
        if ($value === null) {
            unset($this->config->$name);
        } else {
            $this->config->$name = $value;
        }
        set_config($name, $value, "local_$pluginname");
    }

    /**
     * Makes sure config is loaded and cached.
     * @return void
     */
    public function load_config() {
        if (!isset($this->config)) {
            $name = $this->get_name();
            $this->config = get_config("local_$name");
        }
    }
}
