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
 * Defines the adaptive quiz module settings form.
 *
 * @package    mod_adaquiz
 * @copyright  2015 Maths for More S.L.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/course/moodleform_mod.php');
require_once($CFG->dirroot . '/mod/adaquiz/locallib.php');



/**
 * Settings form for the adaptive quiz module.
 *
 * @copyright  2015 Maths for More S.L.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_adaquiz_mod_form extends moodleform_mod {
    /** @var array options to be used with date_time_selector fields in the adaptive quiz. */
    public static $datefieldoptions = array('optional' => true, 'step' => 1);

    protected $_feedbacks;
    protected static $reviewfields = array(); // Initialised in the constructor.

    /** @var int the max number of attempts allowed in any user or group override on this adaptive quiz. */
    protected $maxattemptsanyoverride = null;

    public function __construct($current, $section, $cm, $course) {
        self::$reviewfields = array(
            'attempt'          => array('theattempt', 'adaquiz'),
            'correctness'      => array('whethercorrect', 'question'),
            'marks'            => array('marks', 'adaquiz'),
            'specificfeedback' => array('specificfeedback', 'question'),
            'generalfeedback'  => array('generalfeedback', 'question'),
            'rightanswer'      => array('rightanswer', 'question'),
            'overallfeedback'  => array('reviewoverallfeedback', 'adaquiz'),
        );
        parent::__construct($current, $section, $cm, $course);
    }

    protected function definition() {
        global $COURSE, $CFG, $DB, $PAGE;
        $adaquizconfig = get_config('adaquiz');
        $mform = $this->_form;

        // -------------------------------------------------------------------------------
        $mform->addElement('header', 'general', get_string('general', 'form'));

        // Name.
        $mform->addElement('text', 'name', get_string('name'), array('size'=>'64'));
        if (!empty($CFG->formatstringstriptags)) {
            $mform->setType('name', PARAM_TEXT);
        } else {
            $mform->setType('name', PARAM_CLEANHTML);
        }
        $mform->addRule('name', null, 'required', null, 'client');
        $mform->addRule('name', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');

        // Introduction.
        $this->standard_intro_elements(get_string('introduction', 'adaquiz'));

        // -------------------------------------------------------------------------------
        // $mform->addElement('header', 'timing', get_string('timing', 'adaquiz'));

        // // Open and close dates.
        // $mform->addElement('date_time_selector', 'timeopen', get_string('quizopen', 'adaquiz'),
        //         self::$datefieldoptions);
        // $mform->addHelpButton('timeopen', 'quizopenclose', 'adaquiz');

        // $mform->addElement('date_time_selector', 'timeclose', get_string('quizclose', 'adaquiz'),
        //         self::$datefieldoptions);

        // // Time limit.
        // $mform->addElement('duration', 'timelimit', get_string('timelimit', 'adaquiz'),
        //         array('optional' => true));
        // $mform->addHelpButton('timelimit', 'timelimit', 'adaquiz');
        // $mform->setAdvanced('timelimit', $adaquizconfig->timelimit_adv);
        // $mform->setDefault('timelimit', $adaquizconfig->timelimit);

        // // What to do with overdue attempts.
        // $mform->addElement('select', 'overduehandling', get_string('overduehandling', 'adaquiz'),
        //         adaquiz_get_overdue_handling_options());
        // $mform->addHelpButton('overduehandling', 'overduehandling', 'adaquiz');
        // $mform->setAdvanced('overduehandling', $adaquizconfig->overduehandling_adv);
        // $mform->setDefault('overduehandling', $adaquizconfig->overduehandling);
        // // TODO Formslib does OR logic on disableif, and we need AND logic here.
        // // $mform->disabledIf('overduehandling', 'timelimit', 'eq', 0);
        // // $mform->disabledIf('overduehandling', 'timeclose', 'eq', 0);

        // // Grace period time.
        // $mform->addElement('duration', 'graceperiod', get_string('graceperiod', 'adaquiz'),
        //         array('optional' => true));
        // $mform->addHelpButton('graceperiod', 'graceperiod', 'adaquiz');
        // $mform->setAdvanced('graceperiod', $adaquizconfig->graceperiod_adv);
        // $mform->setDefault('graceperiod', $adaquizconfig->graceperiod);
        // $mform->disabledIf('graceperiod', 'overduehandling', 'neq', 'graceperiod');

        // -------------------------------------------------------------------------------
        // Grade settings.
        $this->standard_grading_coursemodule_elements();
        $mform->removeElement('grade');
        if (property_exists($this->current, 'grade')) {
            $currentgrade = $this->current->grade;
        } else {
            $currentgrade = $adaquizconfig->maximumgrade;
        }
        $mform->addElement('hidden', 'grade', $currentgrade);
        $mform->setType('grade', PARAM_FLOAT);

        // AdaptiveQuiz: One attempt, maximum grade method.
        // // Number of attempts.
        // $attemptoptions = array('0' => get_string('unlimited'));
        // for ($i = 1; $i <= ADAQUIZ_MAX_ATTEMPT_OPTION; $i++) {
        //     $attemptoptions[$i] = $i;
        // }
        // $mform->addElement('select', 'attempts', get_string('attemptsallowed', 'adaquiz'),
        //         $attemptoptions);
        // $mform->setAdvanced('attempts', $adaquizconfig->attempts_adv);
        // $mform->setDefault('attempts', $adaquizconfig->attempts);

        // // Grading method.
        // $mform->addElement('select', 'grademethod', get_string('grademethod', 'adaquiz'),
        //         adaquiz_get_grading_options());
        // $mform->addHelpButton('grademethod', 'grademethod', 'adaquiz');
        // $mform->setAdvanced('grademethod', $adaquizconfig->grademethod_adv);
        // $mform->setDefault('grademethod', $adaquizconfig->grademethod);
        $mform->addElement('hidden', 'grademethod', ADAQUIZ_GRADEHIGHEST);
        $mform->setType('grademethod', PARAM_INT);
        // AdaptiveQuiz: Not Overrides.
        // if ($this->get_max_attempts_for_any_override() < 2) {
        //     $mform->disabledIf('grademethod', 'attempts', 'eq', 1);
        // }

        // -------------------------------------------------------------------------------
        // AdaptiveQuiz: Layout 1 question per page. TODO: Customize questions per page.
        // questionperpage = 1 by default on DB definition.

        // $mform->addElement('header', 'layouthdr', get_string('layout', 'adaquiz'));

        // // Shuffle questions.
        // $shuffleoptions = array(
        //     0 => get_string('asshownoneditscreen', 'adaquiz'),
        //     1 => get_string('shuffledrandomly', 'adaquiz')
        // );
        // $mform->addElement('select', 'shufflequestions', get_string('questionorder', 'adaquiz'),
        //         $shuffleoptions, array('id' => 'id_shufflequestions'));
        // $mform->setAdvanced('shufflequestions', $adaquizconfig->shufflequestions_adv);
        // $mform->setDefault('shufflequestions', $adaquizconfig->shufflequestions);

        // $pagegroup = array();
        // $pagegroup[] = $mform->createElement('select', 'questionsperpage',
        //         get_string('newpage', 'adaquiz'), adaquiz_questions_per_page_options(), array('id' => 'id_questionsperpage'));
        // $mform->setDefault('questionsperpage', $adaquizconfig->questionsperpage);

        // if (!empty($this->_cm)) {
        //     $pagegroup[] = $mform->createElement('checkbox', 'repaginatenow', '',
        //             get_string('repaginatenow', 'adaquiz'), array('id' => 'id_repaginatenow'));
        //     $mform->disabledIf('repaginatenow', 'shufflequestions', 'eq', 1);
        // }

        // $mform->addGroup($pagegroup, 'questionsperpagegrp',
        //         get_string('newpage', 'adaquiz'), null, false);
        // $mform->addHelpButton('questionsperpagegrp', 'newpage', 'adaquiz');
        // $mform->setAdvanced('questionsperpagegrp', $adaquizconfig->questionsperpage_adv);

        // // Navigation method.
        // $mform->addElement('select', 'navmethod', get_string('navmethod', 'adaquiz'),
        //         adaquiz_get_navigation_options());
        // $mform->addHelpButton('navmethod', 'navmethod', 'adaquiz');
        // $mform->setAdvanced('navmethod', $adaquizconfig->navmethod_adv);
        // $mform->setDefault('navmethod', $adaquizconfig->navmethod);

        // -------------------------------------------------------------------------------
        $mform->addElement('header', 'interactionhdr', get_string('questionbehaviour', 'adaquiz'));

        // Shuffle within questions.
        $mform->addElement('selectyesno', 'shuffleanswers', get_string('shufflewithin', 'adaquiz'));
        $mform->addHelpButton('shuffleanswers', 'shufflewithin', 'adaquiz');
        $mform->setAdvanced('shuffleanswers', $adaquizconfig->shuffleanswers_adv);
        $mform->setDefault('shuffleanswers', $adaquizconfig->shuffleanswers);
        // How questions behave (question behaviour).
        if (!empty($this->current->preferredbehaviour)) {
            $currentbehaviour = $this->current->preferredbehaviour;
        } else {
            $currentbehaviour = '';
        }
        $behaviours = question_engine::get_behaviour_options($currentbehaviour);
        // AdaptiveQuiz: Leave only two behaviours: immediatefeedback and interactive
        $notsupportedbehaviours = array('adaptive', 'adaptivenopenalty', 'deferredfeedback', 'deferredcbm', 'immediatecbm');
        foreach($notsupportedbehaviours as $key) {
            unset($behaviours[$key]);
        }
        $mform->addElement('select', 'preferredbehaviour',
                get_string('howquestionsbehave', 'question'), $behaviours);
        $mform->addHelpButton('preferredbehaviour', 'howquestionsbehave', 'question');
        $mform->setDefault('preferredbehaviour', $adaquizconfig->preferredbehaviour);

        // Each attempt builds on last.
        // AdaptiveQuiz: TODO
        // $mform->addElement('selectyesno', 'attemptonlast',
        //         get_string('eachattemptbuildsonthelast', 'adaquiz'));
        // $mform->addHelpButton('attemptonlast', 'eachattemptbuildsonthelast', 'adaquiz');
        // $mform->setAdvanced('attemptonlast', $adaquizconfig->attemptonlast_adv);
        // $mform->setDefault('attemptonlast', $adaquizconfig->attemptonlast);
        // AdaptiveQuiz: Not overrides.
        // if ($this->get_max_attempts_for_any_override() < 2) {
        //     $mform->disabledIf('attemptonlast', 'attempts', 'eq', 1);
        // }

        // -------------------------------------------------------------------------------
        $mform->addElement('header', 'reviewoptionshdr',
                get_string('reviewoptionsheading', 'adaquiz'));
        $mform->addHelpButton('reviewoptionshdr', 'reviewoptionsheading', 'adaquiz');

        // Review options.
        $this->add_review_options_group($mform, $adaquizconfig, 'during',
                mod_adaquiz_display_options::DURING, true);
        $this->add_review_options_group($mform, $adaquizconfig, 'immediately',
                mod_adaquiz_display_options::IMMEDIATELY_AFTER);
        $this->add_review_options_group($mform, $adaquizconfig, 'open',
                mod_adaquiz_display_options::LATER_WHILE_OPEN);
        $this->add_review_options_group($mform, $adaquizconfig, 'closed',
                mod_adaquiz_display_options::AFTER_CLOSE);

        foreach ($behaviours as $behaviour => $notused) {
            $unusedoptions = question_engine::get_behaviour_unused_display_options($behaviour);
            foreach ($unusedoptions as $unusedoption) {
                $mform->disabledIf($unusedoption . 'during', 'preferredbehaviour',
                        'eq', $behaviour);
            }
        }
        $mform->disabledIf('attemptduring', 'preferredbehaviour',
                'neq', 'wontmatch');
        $mform->disabledIf('overallfeedbackduring', 'preferredbehaviour',
                'neq', 'wontmatch');

        // -------------------------------------------------------------------------------
        $mform->addElement('header', 'display', get_string('appearance'));

        // Show user picture.
        $mform->addElement('select', 'showuserpicture', get_string('showuserpicture', 'adaquiz'),
                adaquiz_get_user_image_options());
        $mform->addHelpButton('showuserpicture', 'showuserpicture', 'adaquiz');
        $mform->setAdvanced('showuserpicture', $adaquizconfig->showuserpicture_adv);
        $mform->setDefault('showuserpicture', $adaquizconfig->showuserpicture);

        // Overall decimal points.
        $options = array();
        for ($i = 0; $i <= ADAQUIZ_MAX_DECIMAL_OPTION; $i++) {
            $options[$i] = $i;
        }
        $mform->addElement('select', 'decimalpoints', get_string('decimalplaces', 'adaquiz'),
                $options);
        $mform->addHelpButton('decimalpoints', 'decimalplaces', 'adaquiz');
        $mform->setAdvanced('decimalpoints', $adaquizconfig->decimalpoints_adv);
        $mform->setDefault('decimalpoints', $adaquizconfig->decimalpoints);

        // Question decimal points.
        $options = array(-1 => get_string('sameasoverall', 'adaquiz'));
        for ($i = 0; $i <= ADAQUIZ_MAX_Q_DECIMAL_OPTION; $i++) {
            $options[$i] = $i;
        }
        $mform->addElement('select', 'questiondecimalpoints',
                get_string('decimalplacesquestion', 'adaquiz'), $options);
        $mform->addHelpButton('questiondecimalpoints', 'decimalplacesquestion', 'adaquiz');
        $mform->setAdvanced('questiondecimalpoints', $adaquizconfig->questiondecimalpoints_adv);
        $mform->setDefault('questiondecimalpoints', $adaquizconfig->questiondecimalpoints);

        // Show blocks during adaptive quiz attempt.
        $mform->addElement('selectyesno', 'showblocks', get_string('showblocks', 'adaquiz'));
        $mform->addHelpButton('showblocks', 'showblocks', 'adaquiz');
        $mform->setAdvanced('showblocks', $adaquizconfig->showblocks_adv);
        $mform->setDefault('showblocks', $adaquizconfig->showblocks);

        // -------------------------------------------------------------------------------
        // AdaptiveQuiz: TODO Access manager plugins.
        // $mform->addElement('header', 'security', get_string('extraattemptrestrictions', 'adaquiz'));

        // // Require password to begin adaptive quiz attempt.
        // $mform->addElement('passwordunmask', 'adaquizpassword', get_string('requirepassword', 'adaquiz'));
        // $mform->setType('adaquizpassword', PARAM_TEXT);
        // $mform->addHelpButton('adaquizpassword', 'requirepassword', 'adaquiz');
        // $mform->setAdvanced('adaquizpassword', $adaquizconfig->password_adv);
        // $mform->setDefault('adaquizpassword', $adaquizconfig->password);

        // // IP address.
        // $mform->addElement('text', 'subnet', get_string('requiresubnet', 'adaquiz'));
        // $mform->setType('subnet', PARAM_TEXT);
        // $mform->addHelpButton('subnet', 'requiresubnet', 'adaquiz');
        // $mform->setAdvanced('subnet', $adaquizconfig->subnet_adv);
        // $mform->setDefault('subnet', $adaquizconfig->subnet);

        // // Enforced time delay between adaptive quiz attempts.
        // $mform->addElement('duration', 'delay1', get_string('delay1st2nd', 'adaquiz'),
        //         array('optional' => true));
        // $mform->addHelpButton('delay1', 'delay1st2nd', 'adaquiz');
        // $mform->setAdvanced('delay1', $adaquizconfig->delay1_adv);
        // $mform->setDefault('delay1', $adaquizconfig->delay1);
        // // AdaptiveQuiz: Not overrides
        // // if ($this->get_max_attempts_for_any_override() < 2) {
        // //     $mform->disabledIf('delay1', 'attempts', 'eq', 1);
        // // }

        // $mform->addElement('duration', 'delay2', get_string('delaylater', 'adaquiz'),
        //         array('optional' => true));
        // $mform->addHelpButton('delay2', 'delaylater', 'adaquiz');
        // $mform->setAdvanced('delay2', $adaquizconfig->delay2_adv);
        // $mform->setDefault('delay2', $adaquizconfig->delay2);
        // // AdaptiveQuiz: Not overrides.
        // // if ($this->get_max_attempts_for_any_override() < 3) {
        // //     $mform->disabledIf('delay2', 'attempts', 'eq', 1);
        // //     $mform->disabledIf('delay2', 'attempts', 'eq', 2);
        // // }

        // // Browser security choices.
        // $mform->addElement('select', 'browsersecurity', get_string('browsersecurity', 'adaquiz'),
        //         adaquiz_access_manager::get_browser_security_choices());
        // $mform->addHelpButton('browsersecurity', 'browsersecurity', 'adaquiz');
        // $mform->setAdvanced('browsersecurity', $adaquizconfig->browsersecurity_adv);
        // $mform->setDefault('browsersecurity', $adaquizconfig->browsersecurity);

        // // Any other rule plugins.
        // adaquiz_access_manager::add_settings_form_fields($this, $mform);

        // -------------------------------------------------------------------------------
        // AdaptiveQuiz: TODO Overall feedback.
        // $mform->addElement('header', 'overallfeedbackhdr', get_string('overallfeedback', 'adaquiz'));
        // $mform->addHelpButton('overallfeedbackhdr', 'overallfeedback', 'adaquiz');

        // if (isset($this->current->grade)) {
        //     $needwarning = $this->current->grade === 0;
        // } else {
        //     $needwarning = $adaquizconfig->maximumgrade == 0;
        // }
        // if ($needwarning) {
        //     $mform->addElement('static', 'nogradewarning', '',
        //             get_string('nogradewarning', 'adaquiz'));
        // }

        // $mform->addElement('static', 'gradeboundarystatic1',
        //         get_string('gradeboundary', 'adaquiz'), '100%');

        // Adaptive Quiz: Not feedback.
        // $repeatarray = array();
        // $repeatedoptions = array();
        // $repeatarray[] = $mform->createElement('editor', 'feedbacktext',
        //         get_string('feedback', 'adaquiz'), array('rows' => 3), array('maxfiles' => EDITOR_UNLIMITED_FILES,
        //                 'noclean' => true, 'context' => $this->context));
        // $repeatarray[] = $mform->createElement('text', 'feedbackboundaries',
        //         get_string('gradeboundary', 'adaquiz'), array('size' => 10));
        // $repeatedoptions['feedbacktext']['type'] = PARAM_RAW;
        // $repeatedoptions['feedbackboundaries']['type'] = PARAM_RAW;

        // if (!empty($this->_instance)) {
        //     $this->_feedbacks = $DB->get_records('adaquiz_feedback',
        //             array('adaquizid' => $this->_instance), 'mingrade DESC');
        // } else {
        //     $this->_feedbacks = array();
        // }
        // $numfeedbacks = max(count($this->_feedbacks) * 1.5, 5);

        // $nextel = $this->repeat_elements($repeatarray, $numfeedbacks - 1,
        //         $repeatedoptions, 'boundary_repeats', 'boundary_add_fields', 3,
        //         get_string('addmoreoverallfeedbacks', 'adaquiz'), true);

        // // Put some extra elements in before the button.
        // $mform->insertElementBefore($mform->createElement('editor',
        //         "feedbacktext[$nextel]", get_string('feedback', 'adaquiz'), array('rows' => 3),
        //         array('maxfiles' => EDITOR_UNLIMITED_FILES, 'noclean' => true,
        //               'context' => $this->context)),
        //         'boundary_add_fields');
        // $mform->insertElementBefore($mform->createElement('static',
        //         'gradeboundarystatic2', get_string('gradeboundary', 'adaquiz'), '0%'),
        //         'boundary_add_fields');

        // // Add the disabledif rules. We cannot do this using the $repeatoptions parameter to
        // // repeat_elements because we don't want to dissable the first feedbacktext.
        // for ($i = 0; $i < $nextel; $i++) {
        //     $mform->disabledIf('feedbackboundaries[' . $i . ']', 'grade', 'eq', 0);
        //     $mform->disabledIf('feedbacktext[' . ($i + 1) . ']', 'grade', 'eq', 0);
        // }

        // -------------------------------------------------------------------------------
        $this->standard_coursemodule_elements();

        // Check and act on whether setting outcomes is considered an advanced setting.
        $mform->setAdvanced('modoutcomes', !empty($adaquizconfig->outcomes_adv));

        // The standard_coursemodule_elements method sets this to 100, but the
        // adaptive quiz has its own setting, so use that.
        $mform->setDefault('grade', $adaquizconfig->maximumgrade);

        // -------------------------------------------------------------------------------
        $this->add_action_buttons();

        $PAGE->requires->yui_module('moodle-mod_adaquiz-modform', 'M.mod_adaquiz.modform.init');
    }

    protected function add_review_options_group($mform, $adaquizconfig, $whenname,
            $when, $withhelp = false) {
        global $OUTPUT;

        $group = array();
        foreach (self::$reviewfields as $field => $string) {
            list($identifier, $component) = $string;

            $label = get_string($identifier, $component);
            if ($withhelp) {
                $label .= ' ' . $OUTPUT->help_icon($identifier, $component);
            }

            $group[] = $mform->createElement('checkbox', $field . $whenname, '', $label);
        }
        $mform->addGroup($group, $whenname . 'optionsgrp',
                get_string('review' . $whenname, 'adaquiz'), null, false);

        foreach (self::$reviewfields as $field => $notused) {
            $cfgfield = 'review' . $field;
            if ($adaquizconfig->$cfgfield & $when) {
                $mform->setDefault($field . $whenname, 1);
            } else {
                $mform->setDefault($field . $whenname, 0);
            }
        }

        if ($whenname != 'during') {
            $mform->disabledIf('correctness' . $whenname, 'attempt' . $whenname);
            $mform->disabledIf('specificfeedback' . $whenname, 'attempt' . $whenname);
            $mform->disabledIf('generalfeedback' . $whenname, 'attempt' . $whenname);
            $mform->disabledIf('rightanswer' . $whenname, 'attempt' . $whenname);
        }
    }

    protected function preprocessing_review_settings(&$toform, $whenname, $when) {
        foreach (self::$reviewfields as $field => $notused) {
            $fieldname = 'review' . $field;
            if (array_key_exists($fieldname, $toform)) {
                $toform[$field . $whenname] = $toform[$fieldname] & $when;
            }
        }
    }

    public function data_preprocessing(&$toform) {
        if (isset($toform['grade'])) {
            // Convert to a real number, so we don't get 0.0000.
            $toform['grade'] = $toform['grade'] + 0;
        }

        if (count($this->_feedbacks)) {
            $key = 0;
            foreach ($this->_feedbacks as $feedback) {
                $draftid = file_get_submitted_draft_itemid('feedbacktext['.$key.']');
                $toform['feedbacktext['.$key.']']['text'] = file_prepare_draft_area(
                    $draftid,               // Draftid.
                    $this->context->id,     // Context.
                    'mod_adaquiz',             // Component.
                    'feedback',             // Filarea.
                    !empty($feedback->id) ? (int) $feedback->id : null, // Itemid.
                    null,
                    $feedback->feedbacktext // Text.
                );
                $toform['feedbacktext['.$key.']']['format'] = $feedback->feedbacktextformat;
                $toform['feedbacktext['.$key.']']['itemid'] = $draftid;

                if ($toform['grade'] == 0) {
                    // When an adaptive quiz is un-graded, there can only be one lot of
                    // feedback. If the adaptive quiz previously had a maximum grade and
                    // several lots of feedback, we must now avoid putting text
                    // into input boxes that are disabled, but which the
                    // validation will insist are blank.
                    break;
                }

                if ($feedback->mingrade > 0) {
                    $toform['feedbackboundaries['.$key.']'] =
                            round(100.0 * $feedback->mingrade / $toform['grade'], 6) . '%';
                }
                $key++;
            }
        }

        if (isset($toform['timelimit'])) {
            $toform['timelimitenable'] = $toform['timelimit'] > 0;
        }

        $this->preprocessing_review_settings($toform, 'during',
                mod_adaquiz_display_options::DURING);
        $this->preprocessing_review_settings($toform, 'immediately',
                mod_adaquiz_display_options::IMMEDIATELY_AFTER);
        $this->preprocessing_review_settings($toform, 'open',
                mod_adaquiz_display_options::LATER_WHILE_OPEN);
        $this->preprocessing_review_settings($toform, 'closed',
                mod_adaquiz_display_options::AFTER_CLOSE);
        $toform['attemptduring'] = true;
        $toform['overallfeedbackduring'] = false;

        // Password field - different in form to stop browsers that remember
        // passwords from getting confused.
        if (isset($toform['password'])) {
            $toform['adaquizpassword'] = $toform['password'];
            unset($toform['password']);
        }

        // Load any settings belonging to the access rules.
        if (!empty($toform['instance'])) {
            $accesssettings = adaquiz_access_manager::load_settings($toform['instance']);
            foreach ($accesssettings as $name => $value) {
                $toform[$name] = $value;
            }
        }
    }

    public function validation($data, $files) {
        $errors = parent::validation($data, $files);

        // Check open and close times are consistent.
        // Adaptivequiz: TODO Timing
        // if ($data['timeopen'] != 0 && $data['timeclose'] != 0 &&
        //         $data['timeclose'] < $data['timeopen']) {
        //     $errors['timeclose'] = get_string('closebeforeopen', 'adaquiz');
        // }

        // Adaptivequiz: TODO Timing
        // Check that the grace period is not too short.
        // if ($data['overduehandling'] == 'graceperiod') {
        //     $graceperiodmin = get_config('adaquiz', 'graceperiodmin');
        //     if ($data['graceperiod'] <= $graceperiodmin) {
        //         $errors['graceperiod'] = get_string('graceperiodtoosmall', 'adaquiz', format_time($graceperiodmin));
        //     }
        // }

        // Check the boundary value is a number or a percentage, and in range.
        $i = 0;
        while (!empty($data['feedbackboundaries'][$i] )) {
            $boundary = trim($data['feedbackboundaries'][$i]);
            if (strlen($boundary) > 0) {
                if ($boundary[strlen($boundary) - 1] == '%') {
                    $boundary = trim(substr($boundary, 0, -1));
                    if (is_numeric($boundary)) {
                        $boundary = $boundary * $data['grade'] / 100.0;
                    } else {
                        $errors["feedbackboundaries[$i]"] =
                                get_string('feedbackerrorboundaryformat', 'adaquiz', $i + 1);
                    }
                } else if (!is_numeric($boundary)) {
                    $errors["feedbackboundaries[$i]"] =
                            get_string('feedbackerrorboundaryformat', 'adaquiz', $i + 1);
                }
            }
            if (is_numeric($boundary) && $boundary <= 0 || $boundary >= $data['grade'] ) {
                $errors["feedbackboundaries[$i]"] =
                        get_string('feedbackerrorboundaryoutofrange', 'adaquiz', $i + 1);
            }
            if (is_numeric($boundary) && $i > 0 &&
                    $boundary >= $data['feedbackboundaries'][$i - 1]) {
                $errors["feedbackboundaries[$i]"] =
                        get_string('feedbackerrororder', 'adaquiz', $i + 1);
            }
            $data['feedbackboundaries'][$i] = $boundary;
            $i += 1;
        }
        $numboundaries = $i;

        // Check there is nothing in the remaining unused fields.
        // AdaptiveQuiz: No feedback.
        // if (!empty($data['feedbackboundaries'])) {
        //     for ($i = $numboundaries; $i < count($data['feedbackboundaries']); $i += 1) {
        //         if (!empty($data['feedbackboundaries'][$i] ) &&
        //                 trim($data['feedbackboundaries'][$i] ) != '') {
        //             $errors["feedbackboundaries[$i]"] =
        //                     get_string('feedbackerrorjunkinboundary', 'adaquiz', $i + 1);
        //         }
        //     }
        // }
        // for ($i = $numboundaries + 1; $i < count($data['feedbacktext']); $i += 1) {
        //     if (!empty($data['feedbacktext'][$i]['text']) &&
        //             trim($data['feedbacktext'][$i]['text'] ) != '') {
        //         $errors["feedbacktext[$i]"] =
        //                 get_string('feedbackerrorjunkinfeedback', 'adaquiz', $i + 1);
        //     }
        // }

        // Any other rule plugins.
        $errors = adaquiz_access_manager::validate_settings_form_fields($errors, $data, $files, $this);

        return $errors;
    }

    /**
     * Display module-specific activity completion rules.
     * Part of the API defined by moodleform_mod
     * @return array Array of string IDs of added items, empty array if none
     */
    public function add_completion_rules() {
        $mform = $this->_form;
        $items = array();

        $group = array();
        $group[] = $mform->createElement('advcheckbox', 'completionpass', null, get_string('completionpass', 'adaquiz'),
                array('group' => 'cpass'));

        $group[] = $mform->createElement('advcheckbox', 'completionattemptsexhausted', null,
                get_string('completionattemptsexhausted', 'adaquiz'),
                array('group' => 'cattempts'));
        $mform->disabledIf('completionattemptsexhausted', 'completionpass', 'notchecked');
        $mform->addGroup($group, 'completionpassgroup', get_string('completionpass', 'adaquiz'), ' &nbsp; ', false);
        $mform->addHelpButton('completionpassgroup', 'completionpass', 'adaquiz');
        $items[] = 'completionpassgroup';
        return $items;
    }

    /**
     * Called during validation. Indicates whether a module-specific completion rule is selected.
     *
     * @param array $data Input data (not yet validated)
     * @return bool True if one or more rules is enabled, false if none are.
     */
    public function completion_rule_enabled($data) {
        return !empty($data['completionattemptsexhausted']) || !empty($data['completionpass']);
    }

    /**
     * Get the maximum number of attempts that anyone might have due to a user
     * or group override. Used to decide whether disabledIf rules should be applied.
     * @return int the number of attempts allowed. For the purpose of this method,
     * unlimited is returned as 1000, not 0.
     */
    public function get_max_attempts_for_any_override() {
        global $DB;

        if (empty($this->_instance)) {
            // Adaptive quiz not created yet, so no overrides.
            return 1;
        }

        if ($this->maxattemptsanyoverride === null) {
            $this->maxattemptsanyoverride = $DB->get_field_sql("
                    SELECT MAX(CASE WHEN attempts = 0 THEN 1000 ELSE attempts END)
                      FROM {adaquiz_overrides}
                     WHERE quiz = ?",
                    array($this->_instance));
            if ($this->maxattemptsanyoverride < 1) {
                // This happens when no override alters the number of attempts.
                $this->maxattemptsanyoverride = 1;
            }
        }

        return $this->maxattemptsanyoverride;
    }
}
