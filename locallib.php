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
 * @package    mod
 * @subpackage adaquiz
 * @copyright  2014 Maths for More S.L.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/adaquiz/lib.php');
require_once($CFG->dirroot . '/mod/adaquiz/accessmanager.php');
require_once($CFG->dirroot . '/mod/adaquiz/accessmanager_form.php');
require_once($CFG->dirroot . '/mod/adaquiz/renderer.php');
require_once($CFG->dirroot . '/mod/adaquiz/attemptlib.php');
require_once($CFG->dirroot . '/question/editlib.php');
require_once($CFG->libdir  . '/eventslib.php');
require_once($CFG->libdir . '/filelib.php');


/**
 * @var int We show the countdown timer if there is less than this amount of time left before the
 * the quiz close date. (1 hour)
 */
define('QUIZ_SHOW_TIME_BEFORE_DEADLINE', '3600');

/**
 * @var int If there are fewer than this many seconds left when the student submits
 * a page of the quiz, then do not take them to the next page of the quiz. Instead
 * close the quiz immediately.
 */
define('QUIZ_MIN_TIME_TO_CONTINUE', '2');


// Functions related to attempts ///////////////////////////////////////////////

/**
 * Creates an object to represent a new attempt at a quiz
 *
 * Creates an attempt object to represent an attempt at the quiz by the current
 * user starting at the current time. The ->id field is not set. The object is
 * NOT written to the database.
 *
 * @param object $quiz the quiz to create an attempt for.
 * @param int $attemptnumber the sequence number for the attempt.
 * @param object $lastattempt the previous attempt by this user, if any. Only needed
 *         if $attemptnumber > 1 and $quiz->attemptonlast is true.
 * @param int $timenow the time the attempt was started at.
 * @param bool $ispreview whether this new attempt is a preview.
 *
 * @return object the newly created attempt object.
 */



function quiz_create_attempt($quiz, $attemptnumber, $lastattempt, $timenow, $ispreview = false) {
    global $USER;

    /*if ($quiz->sumgrades < 0.000005 && $quiz->grade > 0.000005) {
        throw new moodle_exception('cannotstartgradesmismatch', 'quiz',
                new moodle_url('/mod/quiz/view.php', array('q' => $quiz->id)),
                    array('grade' => quiz_format_grade($quiz, $quiz->grade)));
    }*/

    if ($attemptnumber == 1 || !$quiz->attemptonlast) {
        // We are not building on last attempt so create a new attempt.
        $attempt = new stdClass();
        $attempt->quiz = $quiz->id;
        $attempt->userid = $USER->id;
        $attempt->preview = 0;
        $attempt->layout = quiz_clean_layout($quiz->questions, true);
        if ($quiz->shufflequestions) {
            $attempt->layout = quiz_repaginate($attempt->layout, $quiz->questionsperpage, true);
        }
    } else {
        // Build on last attempt.
        if (empty($lastattempt)) {
            print_error('cannotfindprevattempt', 'quiz');
        }
        $attempt = $lastattempt;
    }

    $attempt->attempt = $attemptnumber;
    $attempt->timestart = $timenow;
    $attempt->timefinish = 0;
    $attempt->timemodified = $timenow;
    $attempt->state = quiz_attempt::IN_PROGRESS;

    // If this is a preview, mark it as such.
    if ($ispreview) {
        $attempt->preview = 1;
    }

    return $attempt;
}

/**
 * Returns an unfinished attempt (if there is one) for the given
 * user on the given quiz. This function does not return preview attempts.
 *
 * @param int $quizid the id of the quiz.
 * @param int $userid the id of the user.
 *
 * @return mixed the unfinished attempt if there is one, false if not.
 */
function quiz_get_user_attempt_unfinished($quizid, $userid) {
    $attempts = quiz_get_user_attempts($quizid, $userid, 'unfinished', true);
    if ($attempts) {
        return array_shift($attempts);
    } else {
        return false;
    }
}

/**
 * Delete a quiz attempt.
 * @param mixed $attempt an integer attempt id or an attempt object
 *      (row of the quiz_attempts table).
 * @param object $quiz the quiz object.
 */
function adaquiz_delete_attempt($attempt, $quiz) {
    global $DB;
    if (is_numeric($attempt)) {
        if (!$attempt = $DB->get_record('quiz_attempts', array('id' => $attempt))) {
            return;
        }
    }

    if ($attempt->adaquiz != $quiz->id) {
        debugging("Trying to delete attempt $attempt->id which belongs to quiz $attempt->quiz " .
                "but was passed quiz $quiz->id.");
        return;
    }

    question_engine::delete_questions_usage_by_activity($attempt->uniqueid);
}

/**
 * Delete all the preview attempts at a quiz, or possibly all the attempts belonging
 * to one user.
 * @param object $quiz the quiz object.
 * @param int $userid (optional) if given, only delete the previews belonging to this user.
 */
function adaquiz_delete_previews($quiz, $userid = null) {
    global $DB;
    $conditions = array('adaquiz' => $quiz->id, 'preview' => 1);
    if (!empty($userid)) {
        $conditions['userid'] = $userid;
    }
    $previewattempts = $DB->get_records('adaquiz_attempt', $conditions);
    foreach ($previewattempts as $attempt) {
        adaquiz_delete_attempt($attempt, $quiz);
    }
}

/**
 * @param int $quizid The quiz id.
 * @return bool whether this quiz has any (non-preview) attempts.
 */
function adaquiz_has_attempts($quizid) {
    global $DB;
    return $DB->record_exists('adaquiz_attempt', array('adaquiz' => $quizid, 'preview' => 0));
}

// Functions to do with quiz layout and pages //////////////////////////////////

/**
 * Returns a comma separated list of question ids for the quiz
 *
 * @param string $layout The string representing the quiz layout. Each page is
 *      represented as a comma separated list of question ids and 0 indicating
 *      page breaks. So 5,2,0,3,0 means questions 5 and 2 on page 1 and question
 *      3 on page 2
 * @return string comma separated list of question ids, without page breaks.
 */
function adaquiz_questions_in_quiz($layout) {
    $questions = str_replace(',0', '', quiz_clean_layout($layout, true));
    if ($questions === '0') {
        return '';
    } else {
        return $questions;
    }
}

/**
 * Returns the number of pages in a quiz layout
 *
 * @param string $layout The string representing the quiz layout. Always ends in ,0
 * @return int The number of pages in the quiz.
 */
function adaquiz_number_of_pages($layout) {
    return substr_count(',' . $layout, ',0');
}

/**
 * Returns the number of questions in the quiz layout
 *
 * @param string $layout the string representing the quiz layout.
 * @return int The number of questions in the quiz.
 */
function adaquiz_number_of_questions_in_quiz($layout) {
    $layout = adaquiz_questions_in_quiz(quiz_clean_layout($layout));
    $count = substr_count($layout, ',');
    if ($layout !== '') {
        $count++;
    }
    return $count;
}

/**
 * Re-paginates the quiz layout
 *
 * @param string $layout  The string representing the quiz layout. If there is
 *      if there is any doubt about the quality of the input data, call
 *      quiz_clean_layout before you call this function.
 * @param int $perpage The number of questions per page
 * @param bool $shuffle Should the questions be reordered randomly?
 * @return string the new layout string
 */
function adaquiz_repaginate($layout, $perpage, $shuffle = false) {
    $questions = quiz_questions_in_quiz($layout);
    if (!$questions) {
        return '0';
    }

    $questions = explode(',', quiz_questions_in_quiz($layout));
    if ($shuffle) {
        shuffle($questions);
    }

    $onthispage = 0;
    $layout = array();
    foreach ($questions as $question) {
        if ($perpage and $onthispage >= $perpage) {
            $layout[] = 0;
            $onthispage = 0;
        }
        $layout[] = $question;
        $onthispage += 1;
    }

    $layout[] = 0;
    return implode(',', $layout);
}

// Functions to do with quiz grades ////////////////////////////////////////////

/**
 * Creates an array of maximum grades for a quiz
 *
 * The grades are extracted from the quiz_question_instances table.
 * @param object $quiz The quiz settings.
 * @return array of grades indexed by question id. These are the maximum
 *      possible grades that students can achieve for each of the questions.
 */
function quiz_get_all_question_grades($quiz) {
    global $CFG, $DB;

    $questionlist = quiz_questions_in_quiz($quiz->questions);
    if (empty($questionlist)) {
        return array();
    }

    $params = array($quiz->id);
    $wheresql = '';
    if (!is_null($questionlist)) {
        list($usql, $question_params) = $DB->get_in_or_equal(explode(',', $questionlist));
        $wheresql = " AND question $usql ";
        $params = array_merge($params, $question_params);
    }

    $instances = $DB->get_records_sql("SELECT question, grade, id
                                    FROM {quiz_question_instances}
                                    WHERE quiz = ? $wheresql", $params);

    $list = explode(",", $questionlist);
    $grades = array();

    foreach ($list as $qid) {
        if (isset($instances[$qid])) {
            $grades[$qid] = $instances[$qid]->grade;
        } else {
            $grades[$qid] = 1;
        }
    }
    return $grades;
}

/**
 * Convert the raw grade stored in $attempt into a grade out of the maximum
 * grade for this quiz.
 *
 * @param float $rawgrade the unadjusted grade, fof example $attempt->sumgrades
 * @param object $quiz the quiz object. Only the fields grade, sumgrades and decimalpoints are used.
 * @param bool|string $format whether to format the results for display
 *      or 'question' to format a question grade (different number of decimal places.
 * @return float|string the rescaled grade, or null/the lang string 'notyetgraded'
 *      if the $grade is null.
 */
function adaquiz_rescale_grade($rawgrade, $quiz, $format = true) {
    if (is_null($rawgrade)) {
        $grade = null;
    } else if (isset($quiz->sumgrades) && $quiz->sumgrades >= 0.000005) {
        $grade = $rawgrade * $quiz->grade / $quiz->sumgrades;
    } else {
        $grade = 0;
    }
    if ($format === 'question') {
        $grade = adaquiz_format_question_grade($quiz, $grade);
    } else if ($format) {
        $grade = adaquiz_format_grade($quiz, $grade);
    }
    return $grade;
}


/**
 * Convert the raw grade stored in $attempt into a grade out of the maximum
 * grade for this quiz.
 * 
 * @param type $attemptobj
 * @return type 
 */
function adaquiz_rescale_grade_more_attempts($attemptobj){
    $route = adaquiz_get_full_route($attemptobj->get_attemptid());
    $quiz = $attemptobj->get_quiz();
    $sumgrades = adaquiz_get_real_sumgrades($attemptobj->get_attemptid(), $quiz->id, $route);
    if ($sumgrades != 0) {
    	$rescaledgrade = $attemptobj->get_attempt()->sumgrades * $quiz->grade / $sumgrades;
    }
    else {
    	$rescaledgrade = 0;
    }
    return $rescaledgrade;
}

/**
 *
 * Get the total possible mark for this attempt 
 *
 * @param type $attemptobj 
 */
function adaquiz_get_max_mark($attemptobj){
    $route = adaquiz_get_full_route($attemptobj->get_attemptid());
    $quiz = $attemptobj->get_quiz();
    $sumgrades = adaquiz_get_real_sumgrades($attemptobj->get_attemptid(), $quiz->id, $route);
    return $sumgrades;
}

/**
 * Get the feedback text that should be show to a student who
 * got this grade on this quiz. The feedback is processed ready for diplay.
 *
 * @param float $grade a grade on this quiz.
 * @param object $quiz the quiz settings.
 * @param object $context the quiz context.
 * @return string the comment that corresponds to this grade (empty string if there is not one.
 */
function adaquiz_feedback_for_grade($grade, $quiz, $context) {
    global $DB;

    if (is_null($grade)) {
        return '';
    }

    // With CBM etc, it is possible to get -ve grades, which would then not match
    // any feedback. Therefore, we replace -ve grades with 0.
    $grade = max($grade, 0);

    $feedback = $DB->get_record_select('quiz_feedback',
            'quizid = ? AND mingrade <= ? AND ? < maxgrade', array($quiz->id, $grade, $grade));

    if (empty($feedback->feedbacktext)) {
        return '';
    }

    // Clean the text, ready for display.
    $formatoptions = new stdClass();
    $formatoptions->noclean = true;
    $feedbacktext = file_rewrite_pluginfile_urls($feedback->feedbacktext, 'pluginfile.php',
            $context->id, 'mod_quiz', 'feedback', $feedback->id);
    $feedbacktext = format_text($feedbacktext, $feedback->feedbacktextformat, $formatoptions);

    return $feedbacktext;
}

/**
 * @param object $quiz the quiz database row.
 * @return bool Whether this quiz has any non-blank feedback text.
 */
function quiz_has_feedback($quiz) {
    global $DB;
    static $cache = array();
    if (!array_key_exists($quiz->id, $cache)) {
        $cache[$quiz->id] = quiz_has_grades($quiz) &&
                $DB->record_exists_select('quiz_feedback', "quizid = ? AND " .
                    $DB->sql_isnotempty('quiz_feedback', 'feedbacktext', false, true),
                array($quiz->id));
    }
    return $cache[$quiz->id];
}

/**
 * Update the sumgrades field of the quiz. This needs to be called whenever
 * the grading structure of the quiz is changed. For example if a question is
 * added or removed, or a question weight is changed.
 *
 * You should call {@link quiz_delete_previews()} before you call this function.
 *
 * @param object $quiz a quiz.
 */
function quiz_update_sumgrades($quiz) {
    global $DB;

    $sql = 'UPDATE {quiz}
            SET sumgrades = COALESCE((
                SELECT SUM(grade)
                FROM {quiz_question_instances}
                WHERE quiz = {quiz}.id
            ), 0)
            WHERE id = ?';
    $DB->execute($sql, array($quiz->id));
    $quiz->sumgrades = $DB->get_field('quiz', 'sumgrades', array('id' => $quiz->id));

    if ($quiz->sumgrades < 0.000005 && quiz_has_attempts($quiz->id)) {
        // If the quiz has been attempted, and the sumgrades has been
        // set to 0, then we must also set the maximum possible grade to 0, or
        // we will get a divide by zero error.
        quiz_set_grade(0, $quiz);
    }
}

/**
 * Update the sumgrades field of the attempts at a quiz.
 *
 * @param object $quiz a quiz.
 */
function quiz_update_all_attempt_sumgrades($quiz) {
    global $DB;
    $dm = new question_engine_data_mapper();
    $timenow = time();

    $sql = "UPDATE {quiz_attempts}
            SET
                timemodified = :timenow,
                sumgrades = (
                    {$dm->sum_usage_marks_subquery('uniqueid')}
                )
            WHERE quiz = :quizid AND state = :finishedstate";
    $DB->execute($sql, array('timenow' => $timenow, 'quizid' => $quiz->id,
            'finishedstate' => quiz_attempt::FINISHED));
}

/**
 * The quiz grade is the maximum that student's results are marked out of. When it
 * changes, the corresponding data in quiz_grades and quiz_feedback needs to be
 * rescaled. After calling this function, you probably need to call
 * quiz_update_all_attempt_sumgrades, quiz_update_all_final_grades and
 * quiz_update_grades.
 *
 * @param float $newgrade the new maximum grade for the quiz.
 * @param object $quiz the quiz we are updating. Passed by reference so its
 *      grade field can be updated too.
 * @return bool indicating success or failure.
 */
function quiz_set_grade($newgrade, $quiz) {
    global $DB;
    // This is potentially expensive, so only do it if necessary.
    if (abs($quiz->grade - $newgrade) < 1e-7) {
        // Nothing to do.
        return true;
    }

    $oldgrade = $quiz->grade;
    $quiz->grade = $newgrade;

    // Use a transaction, so that on those databases that support it, this is safer.
    $transaction = $DB->start_delegated_transaction();

    // Update the quiz table.
    $DB->set_field('quiz', 'grade', $newgrade, array('id' => $quiz->instance));

    if ($oldgrade < 1) {
        // If the old grade was zero, we cannot rescale, we have to recompute.
        // We also recompute if the old grade was too small to avoid underflow problems.
        quiz_update_all_final_grades($quiz);

    } else {
        // We can rescale the grades efficiently.
        $timemodified = time();
        $DB->execute("
                UPDATE {quiz_grades}
                SET grade = ? * grade, timemodified = ?
                WHERE quiz = ?
        ", array($newgrade/$oldgrade, $timemodified, $quiz->id));
    }

    if ($oldgrade > 1e-7) {
        // Update the quiz_feedback table.
        $factor = $newgrade/$oldgrade;
        $DB->execute("
                UPDATE {quiz_feedback}
                SET mingrade = ? * mingrade, maxgrade = ? * maxgrade
                WHERE quizid = ?
        ", array($factor, $factor, $quiz->id));
    }

    // Update grade item and send all grades to gradebook.
    quiz_grade_item_update($quiz);
    quiz_update_grades($quiz);

    $transaction->allow_commit();
    return true;
}

/**
 * Save the overall grade for a user at a quiz in the quiz_grades table
 *
 * @param object $quiz The quiz for which the best grade is to be calculated and then saved.
 * @param int $userid The userid to calculate the grade for. Defaults to the current user.
 * @param array $attempts The attempts of this user. Useful if you are
 * looping through many users. Attempts can be fetched in one master query to
 * avoid repeated querying.
 * @return bool Indicates success or failure.
 */
function quiz_save_best_grade($quiz, $userid = null, $attempts = array()) {
    global $DB, $OUTPUT, $USER;

    if (empty($userid)) {
        $userid = $USER->id;
    }

    if (!$attempts) {
        // Get all the attempts made by the user.
        $attempts = adaquiz_get_user_attempts($quiz->id, $userid);
    }

    // Calculate the best grade.
    $bestgrade = quiz_calculate_best_grade($quiz, $attempts);
    $bestgrade = quiz_rescale_grade($bestgrade, $quiz, false);

    // Save the best grade in the database.
    if (is_null($bestgrade)) {
        $DB->delete_records('quiz_grades', array('quiz' => $quiz->id, 'userid' => $userid));

    } else if ($grade = $DB->get_record('quiz_grades',
            array('quiz' => $quiz->id, 'userid' => $userid))) {
        $grade->grade = $bestgrade;
        $grade->timemodified = time();
        $DB->update_record('quiz_grades', $grade);

    } else {
        $grade = new stdClass();
        $grade->quiz = $quiz->id;
        $grade->userid = $userid;
        $grade->grade = $bestgrade;
        $grade->timemodified = time();
        $DB->insert_record('quiz_grades', $grade);
    }

    quiz_update_grades($quiz, $userid);
}

/**
 * Calculate the overall grade for a quiz given a number of attempts by a particular user.
 *
 * @param object $quiz    the quiz settings object.
 * @param array $attempts an array of all the user's attempts at this quiz in order.
 * @return float          the overall grade
 */
function quiz_calculate_best_grade($quiz, $attempts) {

    switch ($quiz->grademethod) {

        case QUIZ_ATTEMPTFIRST:
            $firstattempt = reset($attempts);
            return $firstattempt->sumgrades;

        case QUIZ_ATTEMPTLAST:
            $lastattempt = end($attempts);
            return $lastattempt->sumgrades;

        case QUIZ_GRADEAVERAGE:
            $sum = 0;
            $count = 0;
            foreach ($attempts as $attempt) {
                if (!is_null($attempt->sumgrades)) {
                    $sum += $attempt->sumgrades;
                    $count++;
                }
            }
            if ($count == 0) {
                return null;
            }
            return $sum / $count;

        case QUIZ_GRADEHIGHEST:
        default:
            $max = null;
            foreach ($attempts as $attempt) {
                if ($attempt->sumgrades > $max) {
                    $max = $attempt->sumgrades;
                }
            }
            return $max;
    }
}

/**
 * Update the final grade at this quiz for all students.
 *
 * This function is equivalent to calling quiz_save_best_grade for all
 * users, but much more efficient.
 *
 * @param object $quiz the quiz settings.
 */
function quiz_update_all_final_grades($quiz) {
    global $DB;

    if (!$quiz->sumgrades) {
        return;
    }

    $param = array('iquizid' => $quiz->id, 'istatefinished' => quiz_attempt::FINISHED);
    $firstlastattemptjoin = "JOIN (
            SELECT
                iquiza.userid,
                MIN(attempt) AS firstattempt,
                MAX(attempt) AS lastattempt

            FROM {quiz_attempts} iquiza

            WHERE
                iquiza.state = :istatefinished AND
                iquiza.preview = 0 AND
                iquiza.quiz = :iquizid

            GROUP BY iquiza.userid
        ) first_last_attempts ON first_last_attempts.userid = quiza.userid";

    switch ($quiz->grademethod) {
        case QUIZ_ATTEMPTFIRST:
            // Because of the where clause, there will only be one row, but we
            // must still use an aggregate function.
            $select = 'MAX(quiza.sumgrades)';
            $join = $firstlastattemptjoin;
            $where = 'quiza.attempt = first_last_attempts.firstattempt AND';
            break;

        case QUIZ_ATTEMPTLAST:
            // Because of the where clause, there will only be one row, but we
            // must still use an aggregate function.
            $select = 'MAX(quiza.sumgrades)';
            $join = $firstlastattemptjoin;
            $where = 'quiza.attempt = first_last_attempts.lastattempt AND';
            break;

        case QUIZ_GRADEAVERAGE:
            $select = 'AVG(quiza.sumgrades)';
            $join = '';
            $where = '';
            break;

        default:
        case QUIZ_GRADEHIGHEST:
            $select = 'MAX(quiza.sumgrades)';
            $join = '';
            $where = '';
            break;
    }

    if ($quiz->sumgrades >= 0.000005) {
        $finalgrade = $select . ' * ' . ($quiz->grade / $quiz->sumgrades);
    } else {
        $finalgrade = '0';
    }
    $param['quizid'] = $quiz->id;
    $param['quizid2'] = $quiz->id;
    $param['quizid3'] = $quiz->id;
    $param['quizid4'] = $quiz->id;
    $param['statefinished'] = quiz_attempt::FINISHED;
    $param['statefinished2'] = quiz_attempt::FINISHED;
    $finalgradesubquery = "
            SELECT quiza.userid, $finalgrade AS newgrade
            FROM {quiz_attempts} quiza
            $join
            WHERE
                $where
                quiza.state = :statefinished AND
                quiza.preview = 0 AND
                quiza.quiz = :quizid3
            GROUP BY quiza.userid";

    $changedgrades = $DB->get_records_sql("
            SELECT users.userid, qg.id, qg.grade, newgrades.newgrade

            FROM (
                SELECT userid
                FROM {quiz_grades} qg
                WHERE quiz = :quizid
            UNION
                SELECT DISTINCT userid
                FROM {quiz_attempts} quiza2
                WHERE
                    quiza2.state = :statefinished2 AND
                    quiza2.preview = 0 AND
                    quiza2.quiz = :quizid2
            ) users

            LEFT JOIN {quiz_grades} qg ON qg.userid = users.userid AND qg.quiz = :quizid4

            LEFT JOIN (
                $finalgradesubquery
            ) newgrades ON newgrades.userid = users.userid

            WHERE
                ABS(newgrades.newgrade - qg.grade) > 0.000005 OR
                ((newgrades.newgrade IS NULL OR qg.grade IS NULL) AND NOT
                          (newgrades.newgrade IS NULL AND qg.grade IS NULL))",
                // The mess on the previous line is detecting where the value is
                // NULL in one column, and NOT NULL in the other, but SQL does
                // not have an XOR operator, and MS SQL server can't cope with
                // (newgrades.newgrade IS NULL) <> (qg.grade IS NULL).
            $param);

    $timenow = time();
    $todelete = array();
    foreach ($changedgrades as $changedgrade) {

        if (is_null($changedgrade->newgrade)) {
            $todelete[] = $changedgrade->userid;

        } else if (is_null($changedgrade->grade)) {
            $toinsert = new stdClass();
            $toinsert->quiz = $quiz->id;
            $toinsert->userid = $changedgrade->userid;
            $toinsert->timemodified = $timenow;
            $toinsert->grade = $changedgrade->newgrade;
            $DB->insert_record('quiz_grades', $toinsert);

        } else {
            $toupdate = new stdClass();
            $toupdate->id = $changedgrade->id;
            $toupdate->grade = $changedgrade->newgrade;
            $toupdate->timemodified = $timenow;
            $DB->update_record('quiz_grades', $toupdate);
        }
    }

    if (!empty($todelete)) {
        list($test, $params) = $DB->get_in_or_equal($todelete);
        $DB->delete_records_select('quiz_grades', 'quiz = ? AND userid ' . $test,
                array_merge(array($quiz->id), $params));
    }
}

/**
 * Return the attempt with the best grade for a quiz
 *
 * Which attempt is the best depends on $quiz->grademethod. If the grade
 * method is GRADEAVERAGE then this function simply returns the last attempt.
 * @return object         The attempt with the best grade
 * @param object $quiz    The quiz for which the best grade is to be calculated
 * @param array $attempts An array of all the attempts of the user at the quiz
 */
function quiz_calculate_best_attempt($quiz, $attempts) {

    switch ($quiz->grademethod) {

        case QUIZ_ATTEMPTFIRST:
            foreach ($attempts as $attempt) {
                return $attempt;
            }
            break;

        case QUIZ_GRADEAVERAGE: // We need to do something with it.
        case QUIZ_ATTEMPTLAST:
            foreach ($attempts as $attempt) {
                $final = $attempt;
            }
            return $final;

        default:
        case QUIZ_GRADEHIGHEST:
            $max = -1;
            foreach ($attempts as $attempt) {
                if ($attempt->sumgrades > $max) {
                    $max = $attempt->sumgrades;
                    $maxattempt = $attempt;
                }
            }
            return $maxattempt;
    }
}

/**
 * @return array int => lang string the options for calculating the quiz grade
 *      from the individual attempt grades.
 */
function quiz_get_grading_options() {
    return array(
        QUIZ_GRADEHIGHEST => get_string('gradehighest', 'adaquiz'),
        QUIZ_GRADEAVERAGE => get_string('gradeaverage', 'adaquiz'),
        QUIZ_ATTEMPTFIRST => get_string('attemptfirst', 'adaquiz'),
        QUIZ_ATTEMPTLAST  => get_string('attemptlast', 'adaquiz')
    );
}

/**
 * @param int $option one of the values QUIZ_GRADEHIGHEST, QUIZ_GRADEAVERAGE,
 *      QUIZ_ATTEMPTFIRST or QUIZ_ATTEMPTLAST.
 * @return the lang string for that option.
 */
function adaquiz_get_grading_option_name($option) {
    $strings = quiz_get_grading_options();
    return $strings[$option];
}

/**
 * @return array string => lang string the options for handling overdue quiz
 *      attempts.
 */
function quiz_get_overdue_handling_options() {
    return array(
        'autosubmit'  => get_string('overduehandlingautosubmit', 'adaquiz'),
        'graceperiod' => get_string('overduehandlinggraceperiod', 'adaquiz'),
        'autoabandon' => get_string('overduehandlingautoabandon', 'adaquiz'),
    );
}

/**
 * @param string $state one of the state constants like IN_PROGRESS.
 * @return string the human-readable state name.
 */
function quiz_attempt_state_name($state) {
    switch ($state) {
        case Attempt::STATE_ANSWERING:
            return get_string('stateinprogress', 'adaquiz');
        case Attempt::STATE_FINISHED:
            return get_string('statefinished', 'adaquiz');
        default:
            throw new coding_exception('Unknown quiz attempt state.');
    }
}

// Other quiz functions ////////////////////////////////////////////////////////

/**
 * @param object $quiz the quiz.
 * @param int $cmid the course_module object for this quiz.
 * @param object $question the question.
 * @param string $returnurl url to return to after action is done.
 * @return string html for a number of icons linked to action pages for a
 * question - preview and edit / view icons depending on user capabilities.
 */
function quiz_question_action_icons($quiz, $cmid, $question, $returnurl) {
    $html = quiz_question_preview_button($quiz, $question) . ' ' .
            quiz_question_edit_button($cmid, $question, $returnurl);
    return $html;
}

/**
 * @param int $cmid the course_module.id for this quiz.
 * @param object $question the question.
 * @param string $returnurl url to return to after action is done.
 * @param string $contentbeforeicon some HTML content to be added inside the link, before the icon.
 * @return the HTML for an edit icon, view icon, or nothing for a question
 *      (depending on permissions).
 */
function quiz_question_edit_button($cmid, $question, $returnurl, $contentaftericon = '') {
    global $CFG, $OUTPUT;

    // Minor efficiency saving. Only get strings once, even if there are a lot of icons on one page.
    static $stredit = null;
    static $strview = null;
    if ($stredit === null) {
        $stredit = get_string('edit');
        $strview = get_string('view');
    }

    // What sort of icon should we show?
    $action = '';
    if (!empty($question->id) &&
            (question_has_capability_on($question, 'edit', $question->category) ||
                    question_has_capability_on($question, 'move', $question->category))) {
        $action = $stredit;
        $icon = '/t/edit';
    } else if (!empty($question->id) &&
            question_has_capability_on($question, 'view', $question->category)) {
        $action = $strview;
        $icon = '/i/info';
    }

    // Build the icon.
    if ($action) {
        if ($returnurl instanceof moodle_url) {
            $returnurl = $returnurl->out_as_local_url(false);
        }
        $questionparams = array('returnurl' => $returnurl, 'cmid' => $cmid, 'id' => $question->id);
        $questionurl = new moodle_url("$CFG->wwwroot/question/question.php", $questionparams);
        return '<a title="' . $action . '" href="' . $questionurl->out() . '" class="questioneditbutton"><img src="' .
                $OUTPUT->pix_url($icon) . '" alt="' . $action . '" />' . $contentaftericon .
                '</a>';
    } else if ($contentaftericon) {
        return '<span class="questioneditbutton">' . $contentaftericon . '</span>';
    } else {
        return '';
    }
}

/**
 * @param object $quiz the quiz settings
 * @param object $question the question
 * @return moodle_url to preview this question with the options from this quiz.
 */
function quiz_question_preview_url($quiz, $question) {
    // Get the appropriate display options.
    $displayoptions = mod_quiz_display_options::make_from_quiz($quiz,
            'question');

    $maxmark = null;
    if (isset($question->maxmark)) {
        $maxmark = $question->maxmark;
    }

    // Work out the correcte preview URL.
    return question_preview_url($question->id, $quiz->preferredbehaviour,
            $maxmark, $displayoptions);
}

/**
 * @param object $quiz the quiz settings
 * @param object $question the question
 * @param bool $label if true, show the preview question label after the icon
 * @return the HTML for a preview question icon.
 */
function quiz_question_preview_button($quiz, $question, $label = false) {
    global $CFG, $OUTPUT;
    if (!question_has_capability_on($question, 'use', $question->category)) {
        return '';
    }

    $url = quiz_question_preview_url($quiz, $question);

    // Do we want a label?
    $strpreviewlabel = '';
    if ($label) {
        $strpreviewlabel = get_string('preview', 'adaquiz');
    }

    // Build the icon.
    $strpreviewquestion = get_string('previewquestion', 'adaquiz');
    $image = $OUTPUT->pix_icon('t/preview', $strpreviewquestion);

    $action = new popup_action('click', $url, 'questionpreview',
            question_preview_popup_params());

    return $OUTPUT->action_link($url, $image, $action, array('title' => $strpreviewquestion));
}

function adaquiz_question_modify_button($quiz, $question) {
    global $CFG, $OUTPUT;
    $nid = ($quiz->getNodesFromQuestions(array($question->id)));
    $nid = array_shift($nid);
    
    $url = $CFG->wwwroot . '/mod/adaquiz/editnode.php?nid=' . $nid->id . '&aq=' . $quiz->id;

    // Build the icon.
    $adaquizalttext = get_string('editjump', 'adaquiz');
    $image = $OUTPUT->pix_icon('icon', $adaquizalttext, 'mod_adaquiz');

    $action = new popup_action('click', $url, 'adaquiz',
        array('height' => 520, 'width' => 600));

    return $OUTPUT->action_link($url, $image, $action, array('title' => $adaquizalttext));
}


/**
 * @param object $attempt the attempt.
 * @param object $context the quiz context.
 * @return int whether flags should be shown/editable to the current user for this attempt.
 */
function quiz_get_flag_option($attempt, $context) {
    global $USER;
    if (!has_capability('moodle/question:flag', $context)) {
        return question_display_options::HIDDEN;
    } else if ($attempt->userid == $USER->id) {
        return question_display_options::EDITABLE;
    } else {
        return question_display_options::VISIBLE;
    }
}

/**
 * Work out what state this quiz attempt is in.
 * @param object $quiz the quiz settings
 * @param object $attempt the quiz_attempt database row.
 * @return int one of question, adaquiz
 */
function quiz_attempt_state($quiz, $attempt) {
    if ($attempt->state == Attempt::STATE_ANSWERING) {
        return 'question';
    }else{
        return 'adaquiz';
    }
}

/**
 * The the appropraite mod_quiz_display_options object for this attempt at this
 * quiz right now.
 *
 * @param object $quiz the quiz instance.
 * @param object $attempt the attempt in question.
 * @param $context the quiz context.
 *
 * @return mod_quiz_display_options
 */
function quiz_get_review_options($quiz, $attempt, $context) {
    $options = mod_quiz_display_options::make_from_quiz($quiz, quiz_attempt_state($quiz, $attempt));

    $options->readonly = true;
    $options->flags = quiz_get_flag_option($attempt, $context);
    if (!empty($attempt->id)) {
        $options->questionreviewlink = new moodle_url('/mod/quiz/reviewquestion.php',
                array('attempt' => $attempt->id));
    }

    // Show a link to the comment box only for closed attempts.
    if (!empty($attempt->id) && $attempt->state == adaquiz_attempt::FINISHED && !$attempt->preview &&
            !is_null($context) && has_capability('mod/quiz:grade', $context)) {
        $options->manualcomment = question_display_options::VISIBLE;
        $options->manualcommentlink = new moodle_url('/mod/quiz/comment.php',
                array('attempt' => $attempt->id));
    }

    if (!is_null($context) && !$attempt->preview &&
            has_capability('mod/quiz:viewreports', $context) &&
            has_capability('moodle/grade:viewhidden', $context)) {
        // People who can see reports and hidden grades should be shown everything,
        // except during preview when teachers want to see what students see.
        $options->attempt = question_display_options::VISIBLE;
        $options->correctness = question_display_options::VISIBLE;
        $options->marks = question_display_options::MARK_AND_MAX;
        $options->feedback = question_display_options::VISIBLE;
        $options->numpartscorrect = question_display_options::VISIBLE;
        $options->generalfeedback = question_display_options::VISIBLE;
        $options->rightanswer = question_display_options::VISIBLE;
        $options->overallfeedback = question_display_options::VISIBLE;
        $options->history = question_display_options::VISIBLE;

    }

    return $options;
}

/**
 * Combines the review options from a number of different quiz attempts.
 * Returns an array of two ojects, so the suggested way of calling this
 * funciton is:
 * list($someoptions, $alloptions) = quiz_get_combined_reviewoptions(...)
 *
 * @param object $quiz the quiz instance.
 * @param array $attempts an array of attempt objects.
 * @param $context the roles and permissions context,
 *          normally the context for the quiz module instance.
 *
 * @return array of two options objects, one showing which options are true for
 *          at least one of the attempts, the other showing which options are true
 *          for all attempts.
 */
function quiz_get_combined_reviewoptions($quiz, $attempts) {
    $fields = array('feedback', 'generalfeedback', 'rightanswer', 'overallfeedback');
    $someoptions = new stdClass();
    $alloptions = new stdClass();
    foreach ($fields as $field) {
        $someoptions->$field = false;
        $alloptions->$field = true;
    }
    $someoptions->marks = question_display_options::HIDDEN;
    $alloptions->marks = question_display_options::MARK_AND_MAX;

    foreach ($attempts as $attempt) {
        $attemptoptions = mod_quiz_display_options::make_from_quiz($quiz,
                quiz_attempt_state($quiz, $attempt));
        foreach ($fields as $field) {
            $someoptions->$field = $someoptions->$field || $attemptoptions->$field;
            $alloptions->$field = $alloptions->$field && $attemptoptions->$field;
        }
        $someoptions->marks = max($someoptions->marks, $attemptoptions->marks);
        $alloptions->marks = min($alloptions->marks, $attemptoptions->marks);
    }
    return array($someoptions, $alloptions);
}

/**
 * Clean the question layout from various possible anomalies:
 * - Remove consecutive ","'s
 * - Remove duplicate question id's
 * - Remove extra "," from beginning and end
 * - Finally, add a ",0" in the end if there is none
 *
 * @param $string $layout the quiz layout to clean up, usually from $quiz->questions.
 * @param bool $removeemptypages If true, remove empty pages from the quiz. False by default.
 * @return $string the cleaned-up layout
 */
function quiz_clean_layout($layout, $removeemptypages = false) {
    // Remove repeated ','s. This can happen when a restore fails to find the right
    // id to relink to.
    $layout = preg_replace('/,{2,}/', ',', trim($layout, ','));

    // Remove duplicate question ids.
    $layout = explode(',', $layout);
    $cleanerlayout = array();
    $seen = array();
    foreach ($layout as $item) {
        if ($item == 0) {
            $cleanerlayout[] = '0';
        } else if (!in_array($item, $seen)) {
            $cleanerlayout[] = $item;
            $seen[] = $item;
        }
    }

    if ($removeemptypages) {
        // Avoid duplicate page breaks.
        $layout = $cleanerlayout;
        $cleanerlayout = array();
        $stripfollowingbreaks = true; // Ensure breaks are stripped from the start.
        foreach ($layout as $item) {
            if ($stripfollowingbreaks && $item == 0) {
                continue;
            }
            $cleanerlayout[] = $item;
            $stripfollowingbreaks = $item == 0;
        }
    }

    // Add a page break at the end if there is none.
    if (end($cleanerlayout) !== '0') {
        $cleanerlayout[] = '0';
    }

    return implode(',', $cleanerlayout);
}

/**
 * Get the slot for a question with a particular id.
 * @param object $quiz the quiz settings.
 * @param int $questionid the of a question in the quiz.
 * @return int the corresponding slot. Null if the question is not in the quiz.
 */
function quiz_get_slot_for_question($quiz, $questionid) {
    $questionids = quiz_questions_in_quiz($quiz->questions);
    foreach (explode(',', $questionids) as $key => $id) {
        if ($id == $questionid) {
            return $key + 1;
        }
    }
    return null;
}

// Functions for sending notification messages /////////////////////////////////

/**
 * Sends a confirmation message to the student confirming that the attempt was processed.
 *
 * @param object $a lots of useful information that can be used in the message
 *      subject and body.
 *
 * @return int|false as for {@link message_send()}.
 */
function quiz_send_confirmation($recipient, $a) {

    // Add information about the recipient to $a.
    // Don't do idnumber. we want idnumber to be the submitter's idnumber.
    $a->username     = fullname($recipient);
    $a->userusername = $recipient->username;

    // Prepare the message.
    $eventdata = new stdClass();
    $eventdata->component         = 'mod_quiz';
    $eventdata->name              = 'confirmation';
    $eventdata->notification      = 1;

    $eventdata->userfrom          = get_admin();
    $eventdata->userto            = $recipient;
    $eventdata->subject           = get_string('emailconfirmsubject', 'adaquiz', $a);
    $eventdata->fullmessage       = get_string('emailconfirmbody', 'adaquiz', $a);
    $eventdata->fullmessageformat = FORMAT_PLAIN;
    $eventdata->fullmessagehtml   = '';

    $eventdata->smallmessage      = get_string('emailconfirmsmall', 'adaquiz', $a);
    $eventdata->contexturl        = $a->quizurl;
    $eventdata->contexturlname    = $a->quizname;

    // ... and send it.
    return message_send($eventdata);
}

/**
 * Sends notification messages to the interested parties that assign the role capability
 *
 * @param object $recipient user object of the intended recipient
 * @param object $a associative array of replaceable fields for the templates
 *
 * @return int|false as for {@link message_send()}.
 */
function quiz_send_notification($recipient, $submitter, $a) {

    // Recipient info for template.
    $a->useridnumber = $recipient->idnumber;
    $a->username     = fullname($recipient);
    $a->userusername = $recipient->username;

    // Prepare the message.
    $eventdata = new stdClass();
    $eventdata->component         = 'mod_quiz';
    $eventdata->name              = 'submission';
    $eventdata->notification      = 1;

    $eventdata->userfrom          = $submitter;
    $eventdata->userto            = $recipient;
    $eventdata->subject           = get_string('emailnotifysubject', 'adaquiz', $a);
    $eventdata->fullmessage       = get_string('emailnotifybody', 'adaquiz', $a);
    $eventdata->fullmessageformat = FORMAT_PLAIN;
    $eventdata->fullmessagehtml   = '';

    $eventdata->smallmessage      = get_string('emailnotifysmall', 'adaquiz', $a);
    $eventdata->contexturl        = $a->quizreviewurl;
    $eventdata->contexturlname    = $a->quizname;

    // ... and send it.
    return message_send($eventdata);
}

/**
 * Send all the requried messages when a quiz attempt is submitted.
 *
 * @param object $course the course
 * @param object $quiz the quiz
 * @param object $attempt this attempt just finished
 * @param object $context the quiz context
 * @param object $cm the coursemodule for this quiz
 *
 * @return bool true if all necessary messages were sent successfully, else false.
 */
function quiz_send_notification_messages($course, $quiz, $attempt, $context, $cm) {
    global $CFG, $DB;

    // Do nothing if required objects not present.
    if (empty($course) or empty($quiz) or empty($attempt) or empty($context)) {
        throw new coding_exception('$course, $quiz, $attempt, $context and $cm must all be set.');
    }

    $submitter = $DB->get_record('user', array('id' => $attempt->userid), '*', MUST_EXIST);

    // Check for confirmation required.
    $sendconfirm = false;
    $notifyexcludeusers = '';
    if (has_capability('mod/quiz:emailconfirmsubmission', $context, $submitter, false)) {
        $notifyexcludeusers = $submitter->id;
        $sendconfirm = true;
    }

    // Check for notifications required.
    $notifyfields = 'u.id, u.username, u.firstname, u.lastname, u.idnumber, u.email, u.emailstop, ' .
            'u.lang, u.timezone, u.mailformat, u.maildisplay';
    $groups = groups_get_all_groups($course->id, $submitter->id);
    if (is_array($groups) && count($groups) > 0) {
        $groups = array_keys($groups);
    } else if (groups_get_activity_groupmode($cm, $course) != NOGROUPS) {
        // If the user is not in a group, and the quiz is set to group mode,
        // then set $groups to a non-existant id so that only users with
        // 'moodle/site:accessallgroups' get notified.
        $groups = -1;
    } else {
        $groups = '';
    }
    $userstonotify = get_users_by_capability($context, 'mod/quiz:emailnotifysubmission',
            $notifyfields, '', '', '', $groups, $notifyexcludeusers, false, false, true);

    if (empty($userstonotify) && !$sendconfirm) {
        return true; // Nothing to do.
    }

    $a = new stdClass();
    // Course info.
    $a->coursename      = $course->fullname;
    $a->courseshortname = $course->shortname;
    // Quiz info.
    $a->quizname        = $quiz->name;
    $a->quizreporturl   = $CFG->wwwroot . '/mod/quiz/report.php?id=' . $cm->id;
    $a->quizreportlink  = '<a href="' . $a->quizreporturl . '">' .
            format_string($quiz->name) . ' report</a>';
    $a->quizurl         = $CFG->wwwroot . '/mod/quiz/view.php?id=' . $cm->id;
    $a->quizlink        = '<a href="' . $a->quizurl . '">' . format_string($quiz->name) . '</a>';
    // Attempt info.
    $a->submissiontime  = userdate($attempt->timefinish);
    $a->timetaken       = format_time($attempt->timefinish - $attempt->timestart);
    $a->quizreviewurl   = $CFG->wwwroot . '/mod/quiz/review.php?attempt=' . $attempt->id;
    $a->quizreviewlink  = '<a href="' . $a->quizreviewurl . '">' .
            format_string($quiz->name) . ' review</a>';
    // Student who sat the quiz info.
    $a->studentidnumber = $submitter->idnumber;
    $a->studentname     = fullname($submitter);
    $a->studentusername = $submitter->username;

    $allok = true;

    // Send notifications if required.
    if (!empty($userstonotify)) {
        foreach ($userstonotify as $recipient) {
            $allok = $allok && quiz_send_notification($recipient, $submitter, $a);
        }
    }

    // Send confirmation if required. We send the student confirmation last, so
    // that if message sending is being intermittently buggy, which means we send
    // some but not all messages, and then try again later, then teachers may get
    // duplicate messages, but the student will always get exactly one.
    if ($sendconfirm) {
        $allok = $allok && quiz_send_confirmation($submitter, $a);
    }

    return $allok;
}

/**
 * Send the notification message when a quiz attempt becomes overdue.
 *
 * @param object $course the course
 * @param object $quiz the quiz
 * @param object $attempt this attempt just finished
 * @param object $context the quiz context
 * @param object $cm the coursemodule for this quiz
 */
function quiz_send_overdue_message($course, $quiz, $attempt, $context, $cm) {
    global $CFG, $DB;

    // Do nothing if required objects not present.
    if (empty($course) or empty($quiz) or empty($attempt) or empty($context)) {
        throw new coding_exception('$course, $quiz, $attempt, $context and $cm must all be set.');
    }

    $submitter = $DB->get_record('user', array('id' => $attempt->userid), '*', MUST_EXIST);

    if (!has_capability('mod/quiz:emailwarnoverdue', $context, $submitter, false)) {
        return; // Message not required.
    }

    // Prepare lots of useful information that admins might want to include in
    // the email message.
    $quizname = format_string($quiz->name);

    $deadlines = array();
    if ($quiz->timelimit) {
        $deadlines[] = $attempt->timestart + $quiz->timelimit;
    }
    if ($quiz->timeclose) {
        $deadlines[] = $quiz->timeclose;
    }
    $duedate = min($deadlines);
    $graceend = $duedate + $quiz->graceperiod;

    $a = new stdClass();
    // Course info.
    $a->coursename         = $course->fullname;
    $a->courseshortname    = $course->shortname;
    // Quiz info.
    $a->quizname           = $quizname;
    $a->quizurl            = $CFG->wwwroot . '/mod/quiz/view.php?id=' . $cm->id;
    $a->quizlink           = '<a href="' . $a->quizurl . '">' . $quizname . '</a>';
    // Attempt info.
    $a->attemptduedate    = userdate($duedate);
    $a->attemptgraceend    = userdate($graceend);
    $a->attemptsummaryurl  = $CFG->wwwroot . '/mod/quiz/summary.php?attempt=' . $attempt->id;
    $a->attemptsummarylink = '<a href="' . $a->attemptsummaryurl . '">' . $quizname . ' review</a>';
    // Student's info.
    $a->studentidnumber    = $submitter->idnumber;
    $a->studentname        = fullname($submitter);
    $a->studentusername    = $submitter->username;

    // Prepare the message.
    $eventdata = new stdClass();
    $eventdata->component         = 'mod_quiz';
    $eventdata->name              = 'attempt_overdue';
    $eventdata->notification      = 1;

    $eventdata->userfrom          = get_admin();
    $eventdata->userto            = $submitter;
    $eventdata->subject           = get_string('emailoverduesubject', 'adaquiz', $a);
    $eventdata->fullmessage       = get_string('emailoverduebody', 'adaquiz', $a);
    $eventdata->fullmessageformat = FORMAT_PLAIN;
    $eventdata->fullmessagehtml   = '';

    $eventdata->smallmessage      = get_string('emailoverduesmall', 'adaquiz', $a);
    $eventdata->contexturl        = $a->quizurl;
    $eventdata->contexturlname    = $a->quizname;

    // Send the message.
    return message_send($eventdata);
}

/**
 * Handle the quiz_attempt_submitted event.
 *
 * This sends the confirmation and notification messages, if required.
 *
 * @param object $event the event object.
 */
function quiz_attempt_submitted_handler($event) {
    global $DB;

    $course  = $DB->get_record('course', array('id' => $event->courseid));
    $quiz    = $DB->get_record('quiz', array('id' => $event->quizid));
    $cm      = get_coursemodule_from_id('quiz', $event->cmid, $event->courseid);
    $attempt = $DB->get_record('quiz_attempts', array('id' => $event->attemptid));

    if (!($course && $quiz && $cm && $attempt)) {
        // Something has been deleted since the event was raised. Therefore, the
        // event is no longer relevant.
        return true;
    }

    return quiz_send_notification_messages($course, $quiz, $attempt,
            context_module::instance($cm->id), $cm);
}

/**
 * Handle the quiz_attempt_overdue event.
 *
 * For quizzes with applicable settings, this sends a message to the user, reminding
 * them that they forgot to submit, and that they have another chance to do so.
 *
 * @param object $event the event object.
 */
function quiz_attempt_overdue_handler($event) {
    global $DB;

    $course  = $DB->get_record('course', array('id' => $event->courseid));
    $quiz    = $DB->get_record('quiz', array('id' => $event->quizid));
    $cm      = get_coursemodule_from_id('quiz', $event->cmid, $event->courseid);
    $attempt = $DB->get_record('quiz_attempts', array('id' => $event->attemptid));

    if (!($course && $quiz && $cm && $attempt)) {
        // Something has been deleted since the event was raised. Therefore, the
        // event is no longer relevant.
        return true;
    }

    return quiz_send_overdue_message($course, $quiz, $attempt,
            context_module::instance($cm->id), $cm);
}

/**
 * Get the information about the standard quiz JavaScript module.
 * @return array a standard jsmodule structure.
 */
function adaquiz_get_js_module() {
    global $PAGE;

    return array(
        'name' => 'mod_adaquiz',
        'fullpath' => '/mod/adaquiz/module.js',
        'requires' => array('base', 'node', 'dom', 'event-delegate', 'event-key',
                'core_question_engine', 'moodle-core-formchangechecker'),
        'strings' => array(
            array('cancel', 'moodle'),
            array('flagged', 'question'),
            array('functiondisabledbysecuremode', 'quiz'),
            array('startattempt', 'quiz'),
            array('timesup', 'quiz'),
            array('changesmadereallygoaway', 'moodle'),
        ),
    );
}



/**
 * 
 * Get next jump
 * 
 */
function adaquiz_get_next_jump($node, $fraction) {
    global $DB;
    
    $records = $DB->get_records(Jump::TABLE, array('nodefrom' => $node->node));
    
    foreach($records as $key => $j){
        if ($j->type == 'unconditional'){
            return $j;
        }else if ($j->type == 'last_grade'){
            $options = unserialize($j->options);
            if ($options['cmp'] == '<'){
                if ($fraction < $options['value']){
                    return $j;
                }
            }else if ($options['cmp'] == '>'){
                if ($fraction > $options['value']){
                    return $j;
                }
            }
        }else if ($j->type == 'finish_adaquiz'){
            return 0;
        }
    }
    return 0;
}


function adaquiz_get_full_route($attemptid){
    global $DB;

    $nodeattempts = $DB->get_records(NodeAttempt::TABLE, array('attempt' => $attemptid), '', 'position');
    $position = array_keys($nodeattempts);
    
    foreach ($position as $key => $value){
        $slots[$key] = $value+1;
    }
    
    /*$nodeIds = $DB->get_records(NodeAttempt::TABLE, array('attempt' => $attemptid), '', 'node,position');
    $nodes = array_keys($nodeIds);
    $nodes = implode($nodes, ','); 
    $nodes = '(' . $nodes . ')';
    $select = 'id in ' . $nodes;

    $preslots = $DB->get_records_select(Node::TABLE, $select, null, '', 'id,position');

    $slots = array();
    foreach($nodeIds as $key => $value){
        $slots[$value->position] = $preslots[$key]->position;
    }
    
    foreach($slots as $key => $value){
        $slots[$key] = $value += 1;
    }*/

    return $slots;
}


function adaquiz_get_real_sumgrades($aid, $qid, $route){
    global $DB;
    $grades=0;
    $noderecs = $DB->get_records(Node::TABLE, array('adaquiz' => $qid), '', 'id, grade');

    $recs = $DB->get_records(NodeAttempt::TABLE, array('attempt' => $aid));
    foreach($recs as $key => $value){
        $grades += $noderecs[$value->node]->grade;
    }
    
    return $grades;
}

//Used when a student leave the quiz before finishing it
//To continue from the same point
function adaquiz_get_last_attempted_page_data($aid){
    global $DB;
    $recs = $DB->get_records(NodeAttempt::TABLE, array('attempt' => $aid), 'id DESC', '*');
    $recs = array_shift($recs);
    if (is_null($recs->jump)){
        return array($recs->position, null);
    }else if ($recs->jump == 0){
        return array($recs->position, -1);
    }else{
        $nextnode = $DB->get_records_sql("SELECT j.id, n.position
            FROM {" . Node::TABLE . "} n JOIN {" . Jump::TABLE . 
            "} j ON n.id = j.nodeto WHERE j.id = ?" , array($recs->jump));
        return array($recs->position, $nextnode[$recs->jump]->position);
    }
}


/**
 * An extension of question_display_options that includes the extra options used
 * by the quiz.
 *
 * @copyright  2010 The Open University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_quiz_display_options extends question_display_options {
    /**#@+
     * @var integer bits used to indicate various times in relation to a
     * quiz attempt.
     */
    const DURING =            0x10000;
    const IMMEDIATELY_AFTER = 0x01000;
    const LATER_WHILE_OPEN =  0x00100;
    const AFTER_CLOSE =       0x00010;
    /**#@-*/

    /**
     * @var boolean if this is false, then the student is not allowed to review
     * anything about the attempt.
     */
    public $attempt = true;

    /**
     * @var boolean if this is false, then the student is not allowed to review
     * anything about the attempt.
     */
    public $overallfeedback = self::VISIBLE;

    /**
     * Set up the various options from the quiz settings, and a time constant.
     * @param object $quiz the quiz settings.
     * @param int $one of the {@link DURING}, {@link IMMEDIATELY_AFTER},
     * {@link LATER_WHILE_OPEN} or {@link AFTER_CLOSE} constants.
     * @return mod_quiz_display_options set up appropriately.
     */
    public static function make_from_quiz($quiz, $when) {
        $options = new self();
        if (is_string($quiz->options)){
            $op = unserialize($quiz->options);    
        }else{
            $op = $quiz->options;    
        }
        
        /*$options->attempt = self::extract($quiz->reviewattempt, $when, true, false);
        $options->correctness = self::extract($quiz->reviewcorrectness, $when);
        $options->marks = self::extract($quiz->reviewmarks, $when,
                self::MARK_AND_MAX, self::MAX_ONLY);
        $options->feedback = self::extract($quiz->reviewspecificfeedback, $when);
        $options->generalfeedback = self::extract($quiz->reviewgeneralfeedback, $when);
        $options->rightanswer = self::extract($quiz->reviewrightanswer, $when);
        $options->overallfeedback = self::extract($quiz->reviewoverallfeedback, $when);

        $options->numpartscorrect = $options->feedback;

        if ($quiz->questiondecimalpoints != -1) {
            $options->markdp = $quiz->questiondecimalpoints;
        } else {
            $options->markdp = $quiz->decimalpoints;
        }*/

        $options->rightanswer = (int)$op['review'][$when]['correctanswer'];
        $options->generalfeedback = (int)$op['review'][$when]['feedback'];
        $options->feedback = (int)$op['review'][$when]['feedback'];
        $options->overallfeedback = (int)$op['review'][$when]['feedback'];
        if ((bool)$op['review'][$when]['score']){
            $options->marks = 2;
        }else{
            $options->marks = 0;
        }
        $options->attempt = (bool)$op['review'][$when]['score'];
        
        return $options;
    }

    protected static function extract($bitmask, $bit,
            $whenset = self::VISIBLE, $whennotset = self::HIDDEN) {
        if ($bitmask & $bit) {
            return $whenset;
        } else {
            return $whennotset;
        }
    }
}


/**
 * A {@link qubaid_condition} for finding all the question usages belonging to
 * a particular quiz.
 *
 * @copyright  2010 The Open University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class qubaids_for_quiz extends qubaid_join {
    public function __construct($quizid, $includepreviews = true, $onlyfinished = false) {
        $where = 'quiza.quiz = :quizaquiz';
        $params = array('quizaquiz' => $quizid);

        if (!$includepreviews) {
            $where .= ' AND preview = 0';
        }

        if ($onlyfinished) {
            $where .= ' AND state == :statefinished';
            $params['statefinished'] = quiz_attempt::FINISHED;
        }

        parent::__construct('{quiz_attempts} quiza', 'quiza.uniqueid', $where, $params);
    }
}



