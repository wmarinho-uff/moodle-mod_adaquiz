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
 * Common functions for the adaptivequiz statistics report.
 *
 * @package    adaquiz_statistics
 * @copyright  2015 Maths for More S.L.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * SQL to fetch relevant 'adaquiz_attempts' records.
 *
 * @param int    $adaquizid        adaptive quiz id to get attempts for
 * @param array  $groupstudents empty array if not using groups or array of students in current group.
 * @param string $whichattempts which attempts to use, represented internally as one of the constants as used in
 *                                   $adaquiz->grademethod ie.
 *                                   ADAQUIZ_GRADEAVERAGE, ADAQUIZ_GRADEHIGHEST, ADAQUIZ_ATTEMPTLAST or ADAQUIZ_ATTEMPTFIRST
 *                                   we calculate stats based on which attempts would affect the grade for each student.
 * @param bool   $includeungraded whether to fetch ungraded attempts too
 * @return array FROM and WHERE sql fragments and sql params
 */
function adaquiz_statistics_attempts_sql($adaquizid, $groupstudents, $whichattempts = ADAQUIZ_GRADEAVERAGE, $includeungraded = false) {
    global $DB;

    $fromqa = '{adaquiz_attempts} quiza ';

    $whereqa = 'quiza.quiz = :adaquizid AND quiza.preview = 0 AND quiza.state = :adaquizstatefinished';
    $qaparams = array('adaquizid' => (int)$adaquizid, 'adaquizstatefinished' => adaquiz_attempt::FINISHED);

    if ($groupstudents) {
        ksort($groupstudents);
        list($grpsql, $grpparams) = $DB->get_in_or_equal(array_keys($groupstudents),
                SQL_PARAMS_NAMED, 'statsuser');
        list($grpsql, $grpparams) = adaquiz_statistics_renumber_placeholders(
                $grpsql, $grpparams, 'statsuser');
        $whereqa .= " AND quiza.userid $grpsql";
        $qaparams += $grpparams;
    }

    $whichattemptsql = adaquiz_report_grade_method_sql($whichattempts);
    if ($whichattemptsql) {
        $whereqa .= ' AND '.$whichattemptsql;
    }

    if (!$includeungraded) {
        $whereqa .= ' AND quiza.sumgrades IS NOT NULL';
    }

    return array($fromqa, $whereqa, $qaparams);
}

/**
 * Re-number all the params beginning with $paramprefix in a fragment of SQL.
 *
 * @param string $sql the SQL.
 * @param array $params the params.
 * @param string $paramprefix the parameter prefix.
 * @return array with two elements, the modified SQL, and the modified params.
 */
function adaquiz_statistics_renumber_placeholders($sql, $params, $paramprefix) {
    $basenumber = null;
    $newparams = array();
    $newsql = preg_replace_callback('~:' . preg_quote($paramprefix, '~') . '(\d+)\b~',
            function($match) use ($paramprefix, $params, &$newparams, &$basenumber) {
                if ($basenumber === null) {
                    $basenumber = $match[1] - 1;
                }
                $oldname = $paramprefix . $match[1];
                $newname = $paramprefix . ($match[1] - $basenumber);
                $newparams[$newname] = $params[$oldname];
                return ':' . $newname;
            }, $sql);

    return array($newsql, $newparams);
}

/**
 * Return a {@link qubaid_condition} from the values returned by {@link adaquiz_statistics_attempts_sql}.
 *
 * @param int     $adaquizid
 * @param array   $groupstudents
 * @param string $whichattempts which attempts to use, represented internally as one of the constants as used in
 *                                   $adaquiz->grademethod ie.
 *                                   ADAQUIZ_GRADEAVERAGE, ADAQUIZ_GRADEHIGHEST, ADAQUIZ_ATTEMPTLAST or ADAQUIZ_ATTEMPTFIRST
 *                                   we calculate stats based on which attempts would affect the grade for each student.
 * @param bool    $includeungraded
 * @return        \qubaid_join
 */
function adaquiz_statistics_qubaids_condition($adaquizid, $groupstudents, $whichattempts = ADAQUIZ_GRADEAVERAGE, $includeungraded = false) {
    list($fromqa, $whereqa, $qaparams) = adaquiz_statistics_attempts_sql($adaquizid, $groupstudents, $whichattempts, $includeungraded);
    return new qubaid_join($fromqa, 'quiza.uniqueid', $whereqa, $qaparams);
}

/**
 * This helper function returns a sequence of colours each time it is called.
 * Used for choosing colours for graph data series.
 * @return string colour name.
 */
function adaquiz_statistics_graph_get_new_colour() {
    static $colourindex = -1;
    $colours = array('red', 'green', 'yellow', 'orange', 'purple', 'black',
        'maroon', 'blue', 'ltgreen', 'navy', 'ltred', 'ltltgreen', 'ltltorange',
        'olive', 'gray', 'ltltred', 'ltorange', 'lime', 'ltblue', 'ltltblue');

    $colourindex = ($colourindex + 1) % count($colours);

    return $colours[$colourindex];
}