<?php
// This file is part of the Free AI and Agents block for Moodle.
//
// This program is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// This program is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with this program.  If not, see <http://www.gnu.org/licenses/>.

namespace block_aiagents\external;

use block_aiagents\local\history;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use core_external\external_multiple_structure;

// Moodle 4.1 keeps the external API classes in the global namespace while
// Moodle 4.2+ moved them to core_external; alias the missing set once.
if (!class_exists('\\core_external\\external_api')) {
    class_alias('\\external_api', 'core_external\\external_api');
    class_alias('\\external_function_parameters', 'core_external\\external_function_parameters');
    class_alias('\\external_value', 'core_external\\external_value');
    class_alias('\\external_single_structure', 'core_external\\external_single_structure');
    class_alias('\\external_multiple_structure', 'core_external\\external_multiple_structure');
}

defined('MOODLE_INTERNAL') || die();

/**
 * Loads the messages of one stored conversation.
 *
 * @package    block_aiagents
 * @copyright  2026 Riemann Research
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_messages extends external_api {

    /**
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'contextid' => new external_value(PARAM_INT, 'Block context id'),
            'conversationid' => new external_value(PARAM_INT, 'Conversation id'),
        ]);
    }

    /**
     * @param int $contextid
     * @param int $conversationid
     * @return array
     */
    public static function execute(int $contextid, int $conversationid): array {
        global $USER;

        $params = external_api::validate_parameters(self::execute_parameters(), [
            'contextid' => $contextid,
            'conversationid' => $conversationid,
        ]);
        $context = \context::instance_by_id($params['contextid']);
        self::validate_context($context);
        require_capability('block/aiagents:view', $context);

        return [
            'messages' => history::messages($params['conversationid'], (int) $USER->id),
            'title' => history::title_of($params['conversationid'], (int) $USER->id),
            'model' => (function () use ($params, $USER): string {
                global $DB;
                $conv = $DB->get_record('block_aiagents_conversation',
                    ['id' => $params['conversationid'], 'userid' => (int) $USER->id], 'model');
                return $conv ? (string) $conv->model : '';
            })(),
        ];
    }

    /**
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'messages' => new external_multiple_structure(
                new external_single_structure([
                    'role' => new external_value(PARAM_ALPHA, 'user or assistant'),
                    'content' => new external_value(PARAM_RAW, 'Message text'),
                ])
            ),
            'title' => new external_value(PARAM_TEXT, 'Conversation title, empty when untitled'),
            'model' => new external_value(PARAM_RAW, 'Model id of this conversation'),
        ]);
    }
}
