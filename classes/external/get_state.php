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
use block_aiagents\local\pool;
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
 * Returns the initial state (models, default model, recent conversations) for the widget.
 *
 * @package    block_aiagents
 * @copyright  2026 Riemann Research
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_state extends external_api {

    /**
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'contextid' => new external_value(PARAM_INT, 'Block context id'),
        ]);
    }

    /**
     * @param int $contextid
     * @return array
     */
    public static function execute(int $contextid): array {
        global $USER, $CFG;

        $params = external_api::validate_parameters(self::execute_parameters(), ['contextid' => $contextid]);
        $context = \context::instance_by_id($params['contextid']);
        self::validate_context($context);
        require_capability('block/aiagents:view', $context);

        $apikey = get_user_preferences('block_aiagents_apikey', '', $USER->id);
        $pool = new pool(
            $CFG->block_aiagents_baseurl ?? 'https://api.usefree.space/v1',
            (string) $apikey,
            pool::device_id_for((int) $USER->id)
        );

        $defaultmodel = get_config('block_aiagents', 'defaultmodel') ?: '';
        $catalog = $pool->catalog();
        $models = $catalog['models'];
        if ($defaultmodel === '' && !empty($catalog['auto'])) {
            $defaultmodel = $catalog['auto'];
        }
        if ($defaultmodel === '' && $models) {
            $defaultmodel = $models[0]['id'];
        }

        return [
            'models' => $models,
            'defaultmodel' => $defaultmodel,
            'conversations' => history::recent((int) $USER->id),
            'quota' => $pool->quota(),
            'byok' => $apikey !== '' ? 1 : 0,
        ];
    }

    /**
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'models' => new external_multiple_structure(
                new external_single_structure([
                    'id' => new external_value(PARAM_RAW, 'Model id'),
                    'name' => new external_value(PARAM_RAW, 'Model display name'),
                    'isnew' => new external_value(PARAM_INT, '1 when the pool flags this model as new'),
                    'effort' => new external_multiple_structure(
                        new external_value(PARAM_ALPHANUMEXT, 'Thinking level id (instant/low/medium/high)')
                    ),
                    'effortdefault' => new external_value(PARAM_ALPHANUMEXT, 'Default thinking level, empty when none'),
                ])
            ),
            'defaultmodel' => new external_value(PARAM_RAW, 'Default model id'),
            'conversations' => new external_multiple_structure(
                new external_single_structure([
                    'id' => new external_value(PARAM_INT, 'Conversation id'),
                    'title' => new external_value(PARAM_TEXT, 'Conversation title'),
                    'model' => new external_value(PARAM_RAW, 'Model id'),
                    'timemodified' => new external_value(PARAM_INT, 'Last modified'),
                    'msgcount' => new external_value(PARAM_INT, 'Number of stored messages'),
                ])
            ),
            'quota' => new external_single_structure([
                'left' => new external_value(PARAM_INT, 'Free tokens left on this device, -1 when unknown'),
                'limit' => new external_value(PARAM_INT, 'Device token limit, -1 when unknown'),
                'reset' => new external_value(PARAM_INT, 'Days until the next refill, -1 when unknown'),
            ]),
            'byok' => new external_value(PARAM_INT, 'Whether the user has set their own key'),
        ]);
    }
}
