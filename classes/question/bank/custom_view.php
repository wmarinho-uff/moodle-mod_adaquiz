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
 * Defines the custom question bank view used on the Edit adaptive quiz page.
 *
 * @package    mod_adaquiz
 * @copyright  2015 Maths for More S.L.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_adaquiz\question\bank;
defined('MOODLE_INTERNAL') || die();


/**
 * Subclass to customise the view of the question bank for the adaptive quiz editing screen.
 *
 * @copyright  2009 Tim Hunt
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class custom_view extends \core_question\bank\view {
    /** @var bool whether the quiz this is used by has been attemptd. */
    protected $adaquizhasattempts = false;
    /** @var \stdClass the quiz settings. */
    protected $adaquiz = false;
    /** @var int The maximum displayed length of the category info. */
    const MAX_TEXT_LENGTH = 200;

    /**
     * Constructor
     * @param \question_edit_contexts $contexts
     * @param \moodle_url $pageurl
     * @param \stdClass $course course settings
     * @param \stdClass $cm activity settings.
     * @param \stdClass $quiz quiz settings.
     */
    public function __construct($contexts, $pageurl, $course, $cm, $adaquiz) {
        parent::__construct($contexts, $pageurl, $course, $cm);
        $this->adaquiz = $adaquiz;
    }

    protected function wanted_columns() {
        global $CFG;

        if (empty($CFG->adaquizquestionbankcolumns)) {
            $adaquizquestionbankcolumns = array(
                'add_action_column',
                'checkbox_column',
                'question_type_column',
                'question_name_text_column',
                'preview_action_column',
            );
        } else {
            $adaquizquestionbankcolumns = explode(',', $CFG->adaquizquestionbankcolumns);
        }

        foreach ($adaquizquestionbankcolumns as $fullname) {
            if (!class_exists($fullname)) {
                if (class_exists('mod_adaquiz\\question\\bank\\' . $fullname)) {
                    $fullname = 'mod_adaquiz\\question\\bank\\' . $fullname;
                } else if (class_exists('core_question\\bank\\' . $fullname)) {
                    $fullname = 'core_question\\bank\\' . $fullname;
                } else if (class_exists('question_bank_' . $fullname)) {
                    debugging('Legacy question bank column class question_bank_' .
                            $fullname . ' should be renamed to mod_adaquiz\\question\\bank\\' .
                            $fullname, DEBUG_DEVELOPER);
                    $fullname = 'question_bank_' . $fullname;
                } else {
                    throw new coding_exception("No such class exists: $fullname");
                }
            }
            $this->requiredcolumns[$fullname] = new $fullname($this);
        }
        return $this->requiredcolumns;
    }

    /**
     * Specify the column heading
     *
     * @return string Column name for the heading
     */
    protected function heading_column() {
        return 'mod_adaquiz\\question\\bank\\question_name_text_column';
    }

    protected function default_sort() {
        return array(
            'core_question\\bank\\question_type_column' => 1,
            'mod_adaquiz\\question\\bank\\question_name_text_column' => 1,
        );
    }

    /**
     * Let the question bank display know whether the adaquiz has been attempted,
     * hence whether some bits of UI, like the add this question to the adaptive quiz icon,
     * should be displayed.
     * @param bool $adaquizhasattempts whether the adaptive quiz has attempts.
     */
    public function set_adaquiz_has_attempts($adaquizhasattempts) {
        $this->adaquizhasattempts = $adaquizhasattempts;
        if ($adaquizhasattempts && isset($this->visiblecolumns['addtoquizaction'])) {
            unset($this->visiblecolumns['addtoquizaction']);
        }
    }

    public function preview_question_url($question) {
        return adaquiz_question_preview_url($this->adaquiz, $question);
    }

    public function add_to_adaquiz_url($questionid) {
        global $CFG;
        $params = $this->baseurl->params();
        $params['addquestion'] = $questionid;
        $params['sesskey'] = sesskey();
        return new \moodle_url('/mod/adaquiz/edit.php', $params);
    }

    /**
     * Renders the html question bank (same as display, but returns the result).
     *
     * Note that you can only output this rendered result once per page, as
     * it contains IDs which must be unique.
     *
     * @return string HTML code for the form
     */
    public function render($tabname, $page, $perpage, $cat, $recurse, $showhidden, $showquestiontext) {
        ob_start();
        $this->display($tabname, $page, $perpage, $cat, $recurse, $showhidden, $showquestiontext);
        $out = ob_get_contents();
        ob_end_clean();
        return $out;
    }

    /**
     * Display the controls at the bottom of the list of questions.
     * @param int       $totalnumber Total number of questions that might be shown (if it was not for paging).
     * @param bool      $recurse     Whether to include subcategories.
     * @param \stdClass $category    The question_category row from the database.
     * @param \context  $catcontext  The context of the category being displayed.
     * @param array     $addcontexts contexts where the user is allowed to add new questions.
     */
    protected function display_bottom_controls($totalnumber, $recurse, $category, \context $catcontext, array $addcontexts) {
        $cmoptions = new \stdClass();
        $cmoptions->hasattempts = !empty($this->adaquizhasattempts);

        $canuseall = has_capability('moodle/question:useall', $catcontext);

        echo '<div class="modulespecificbuttonscontainer">';
        if ($canuseall) {

            // Add selected questions to the adaptive quiz.
            $params = array(
                    'type' => 'submit',
                    'name' => 'add',
                    'value' => get_string('addselectedquestionstoquiz', 'adaquiz'),
            );
            if ($cmoptions->hasattempts) {
                $params['disabled'] = 'disabled';
            }
            echo \html_writer::empty_tag('input', $params);
        }
        echo "</div>\n";
    }

    /**
     * Prints a form to choose categories.
     * @param string $categoryandcontext 'categoryID,contextID'.
     * @deprecated since Moodle 2.6 MDL-40313.
     * @see \core_question\bank\search\category_condition
     * @todo MDL-41978 This will be deleted in Moodle 2.8
     */
    protected function print_choose_category_message($categoryandcontext) {
        global $OUTPUT;
        debugging('print_choose_category_message() is deprecated, ' .
                'please use \core_question\bank\search\category_condition instead.', DEBUG_DEVELOPER);
        echo $OUTPUT->box_start('generalbox questionbank');
        $this->display_category_form($this->contexts->having_one_edit_tab_cap('edit'),
                $this->baseurl, $categoryandcontext);
        echo "<p style=\"text-align:center;\"><b>";
        print_string('selectcategoryabove', 'question');
        echo "</b></p>";
        echo $OUTPUT->box_end();
    }

    protected function display_options_form($showquestiontext, $scriptpath = '/mod/adaquiz/edit.php',
            $showtextoption = false) {
        // Overridden just to change the default values of the arguments.
        parent::display_options_form($showquestiontext, $scriptpath, $showtextoption);
    }

    protected function print_category_info($category) {
        $formatoptions = new stdClass();
        $formatoptions->noclean = true;
        $strcategory = get_string('category', 'adaquiz');
        echo '<div class="categoryinfo"><div class="categorynamefieldcontainer">' .
                $strcategory;
        echo ': <span class="categorynamefield">';
        echo shorten_text(strip_tags(format_string($category->name)), 60);
        echo '</span></div><div class="categoryinfofieldcontainer">' .
                '<span class="categoryinfofield">';
        echo shorten_text(strip_tags(format_text($category->info, $category->infoformat,
                $formatoptions, $this->course->id)), 200);
        echo '</span></div></div>';
    }

    protected function display_options($recurse, $showhidden, $showquestiontext) {
        debugging('display_options() is deprecated, see display_options_form() instead.', DEBUG_DEVELOPER);
        echo '<form method="get" action="edit.php" id="displayoptions">';
        echo "<fieldset class='invisiblefieldset'>";
        echo \html_writer::input_hidden_params($this->baseurl,
                array('recurse', 'showhidden', 'qbshowtext'));
        $this->display_category_form_checkbox('recurse', $recurse,
                get_string('includesubcategories', 'question'));
        $this->display_category_form_checkbox('showhidden', $showhidden,
                get_string('showhidden', 'question'));
        echo '<noscript><div class="centerpara"><input type="submit" value="' .
                get_string('go') . '" />';
        echo '</div></noscript></fieldset></form>';
    }

    protected function create_new_question_form($category, $canadd) {
        // Don't display this.
    }
}
