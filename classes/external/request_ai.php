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

// Moodle 4.1 keeps the external API classes in the global namespace while
// Moodle 4.2+ moved them to core_external; alias the missing set once.
if (!class_exists('\\core_external\\external_api')) {
    class_alias('\\external_api', 'core_external\\external_api');
    class_alias('\\external_function_parameters', 'core_external\\external_function_parameters');
    class_alias('\\external_value', 'core_external\\external_value');
    class_alias('\\external_single_structure', 'core_external\\external_single_structure');
}

defined('MOODLE_INTERNAL') || die();

/**
 * Sends one chat turn to the free pool and stores the exchange.
 *
 * @package    block_aiagents
 * @copyright  2026 Riemann Research
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class request_ai extends external_api {

    /**
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'contextid' => new external_value(PARAM_INT, 'Block context id'),
            'conversationid' => new external_value(PARAM_INT, 'Existing conversation id, or 0 to start one'),
            'model' => new external_value(PARAM_RAW, 'Model id to use'),
            'prompt' => new external_value(PARAM_RAW, 'The user message'),
            'web' => new external_value(PARAM_INT, '1 when the user has web search enabled', VALUE_DEFAULT, 0),
            'pagetitle' => new external_value(PARAM_TEXT, 'Title of the page the user is viewing', VALUE_DEFAULT, ''),
            'pageurl' => new external_value(PARAM_URL, 'URL of the page the user is viewing', VALUE_DEFAULT, ''),
            'retry' => new external_value(PARAM_INT, '1 when regenerating the last answer (drops the old pair first)', VALUE_DEFAULT, 0),
            'effort' => new external_value(PARAM_ALPHANUMEXT, 'Thinking level for the model (instant/low/medium/high), empty when none', VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * @param int $contextid
     * @param int $conversationid
     * @param string $model
     * @param string $prompt
     * @param int $web
     * @param string $pagetitle
     * @param string $pageurl
     * @param int $retry
     * @param string $effort
     * @return array
     */
    public static function execute(int $contextid, int $conversationid, string $model, string $prompt,
            int $web = 0, string $pagetitle = '', string $pageurl = '', int $retry = 0, string $effort = ''): array {
        global $USER, $CFG;

        $params = external_api::validate_parameters(self::execute_parameters(), [
            'contextid' => $contextid,
            'conversationid' => $conversationid,
            'model' => $model,
            'prompt' => $prompt,
            'web' => $web,
            'pagetitle' => $pagetitle,
            'pageurl' => $pageurl,
            'retry' => $retry,
            'effort' => $effort,
        ]);

        $context = \context::instance_by_id($params['contextid']);
        self::validate_context($context);
        require_capability('block/aiagents:view', $context);

        $prompt = trim($params['prompt']);
        if ($prompt === '') {
            return [
                'conversationid' => $params['conversationid'],
                'reply' => '',
                'error' => '',
                'webnote' => '',
                'webquery' => '',
                'model' => '',
                'title' => '',
                'quota' => ['left' => -1, 'limit' => -1, 'reset' => -1],
            ];
        }

        // Family retry: drop the stored user/assistant pair before re-asking
        // the same prompt, so the stored history never diverges from the log.
        if ($params['retry'] && $params['conversationid'] > 0) {
            history::drop_last_pair($params['conversationid'], (int) $USER->id);
        }

        $apikey = get_user_preferences('block_aiagents_apikey', '', $USER->id);
        $pool = new pool(
            $CFG->block_aiagents_baseurl ?? 'https://api.usefree.space/v1',
            (string) $apikey,
            pool::device_id_for((int) $USER->id)
        );

        // Rebuild history, then add the new user turn on top.
        $messages = [];
        if ($params['conversationid'] > 0) {
            $messages = history::messages($params['conversationid'], (int) $USER->id);
        }

        $system = get_config('block_aiagents', 'systemprompt') ?: get_string('systempromptdefault', 'block_aiagents');
        $webresult = self::web_results($system, (int) $params['web'], $prompt, $pool);
        $system = $webresult['system'];
        $webnote = $webresult['webnote'];
        $webquery = $webresult['webquery'];

        $payloadmessages = array_merge(
            [['role' => 'system', 'content' => $system]],
            $messages,
            [['role' => 'user', 'content' => $prompt . "\n\n" . self::page_context($context, $params)]]
        );

        // Keep the effort choice honest: only levels the catalog lists for
        // this model travel to the pool.
        $effort = $params['effort'];
        if ($effort !== '') {
            $allowed = [];
            foreach ($pool->catalog()['models'] as $cm) {
                if ($cm['id'] === $params['model']) {
                    $allowed = $cm['effort'];
                    break;
                }
            }
            if ($allowed && !in_array($effort, $allowed, true)) {
                $effort = '';
            }
        }

        $maxtokens = (int) (get_config('block_aiagents', 'maxtokens') ?: 1024);

        $error = '';
        $reply = '';
        $usedmodel = '';
        $newconv = $params['conversationid'];
        try {
            $answer = $pool->complete($params['model'], $payloadmessages, $maxtokens, $effort);
            $reply = $answer['text'];
            $usedmodel = $answer['model'];
            $newconv = history::append(
                $params['conversationid'],
                (int) $USER->id,
                $context->id,
                $params['model'],
                $prompt,
                $reply
            );
        } catch (\moodle_exception $e) {
            $error = $e->getMessage();
        }

        $title = '';
        if ($newconv > 0) {
            $title = history::title_of((int) $newconv, (int) $USER->id);
        }

        return [
            'conversationid' => (int) $newconv,
            'reply' => $reply,
            'error' => $error,
            'webnote' => $webnote,
            'webquery' => $webquery,
            'model' => $usedmodel,
            'title' => $title,
            'quota' => $pool->quota(),
        ];
    }

    /**
     * Build the [PAGE CONTEXT] block the model sees with every turn
     * (family pattern: the Figma port injects document context here).
     *
     * @param \context $context
     * @param array $params validated execute params
     * @return string
     */
    protected static function page_context(\context $context, array $params): string {
        global $SITE, $USER, $DB;

        $lines = ['[PAGE CONTEXT]'];

        $coursename = '';
        $coursecontext = $context->get_course_context(false);
        if ($coursecontext) {
            $course = $DB->get_record('course', ['id' => $coursecontext->instanceid], 'fullname');
            if ($course) {
                $coursename = format_string($course->fullname, true, ['context' => $coursecontext]);
            }
        }

        $lines[] = 'site: ' . format_string($SITE->fullname);
        if ($coursename !== '') {
            $lines[] = 'course: ' . $coursename;
        }
        if ($params['pagetitle'] !== '') {
            $lines[] = 'page: ' . $params['pagetitle'];
        }
        if ($params['pageurl'] !== '') {
            $lines[] = 'url: ' . $params['pageurl'];
        }
        $lines[] = 'user first name: ' . $USER->firstname;
        $block = $DB->get_record('block_instances', ['id' => $context->instanceid], 'configdata');
        $blocktitle = '';
        if ($block && !empty($block->configdata)) {
            $config = unserialize_block_instance_config($block->configdata, true);
            $blocktitle = $config['title'] ?? '';
        }
        if ($blocktitle !== '') {
            $lines[] = 'widget: ' . $blocktitle;
        }

        $lines[] = 'Use this context when it helps the answer; ignore it when it does not.';

        return implode("\n", $lines);
    }

    /**
     * Run the pool web-search turn and produce the [WEB SEARCH RESULTS]
     * system-prompt addition, handled family-style (results with inline
     * citations are encouraged; an unavailable service is declared, never faked).
     *
     * @param string $system the system prompt so far
     * @param int $web 1 when web search is enabled
     * @param string $prompt current user prompt
     * @param pool $pool
     * @return array array{system: string, webnote: string, webquery: string}
     */
    protected static function web_results(string $system, int $web, string $prompt, pool $pool): array {
        if (!$web) {
            return ['system' => $system, 'webnote' => '', 'webquery' => ''];
        }

        $query = \core_text::substr($prompt, 0, 300);
        $results = [];
        try {
            $results = $pool->web_search($query);
        } catch (\Exception $e) {
            $results = [];
        }

        if (!$results) {
            // Family behavior: admit it instead of inventing results.
            return [
                'system' => $system . "\n\nThe user enabled web search for this request, but the web service is unavailable. Say so plainly instead of inventing results.",
                'webnote' => '',
                'webquery' => $query,
            ];
        }

        $lines = [];
        foreach ($results as $r) {
            $lines[] = '- [' . ($r['title'] ?: $r['url']) . '](' . $r['url'] . ')' . (!empty($r['snippet']) ? ' - ' . $r['snippet'] : '');
        }

        $system .= "\n\nReal web search results for the user's request are included in [WEB SEARCH RESULTS]. "
            . "Use them and cite sources inline as [title](url). Don't invent links.\n[WEB SEARCH RESULTS]\n"
            . implode("\n", $lines);

        return [
            'system' => $system,
            'webnote' => implode("\n", $lines),
            'webquery' => $query,
        ];
    }

    /**
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'conversationid' => new external_value(PARAM_INT, 'Conversation id after this turn'),
            'reply' => new external_value(PARAM_RAW, 'Assistant reply text'),
            'error' => new external_value(PARAM_RAW, 'Error message, empty on success'),
            'webnote' => new external_value(PARAM_RAW, 'Rendered web search result lines, empty when no search ran'),
            'webquery' => new external_value(PARAM_RAW, 'Query sent to the web search, empty when none'),
            'model' => new external_value(PARAM_RAW, 'Model id that answered this turn'),
            'title' => new external_value(PARAM_TEXT, 'Conversation title after this turn, empty when untitled'),
            'quota' => new external_single_structure([
                'left' => new external_value(PARAM_INT, 'Free tokens left on this device, -1 when unknown'),
                'limit' => new external_value(PARAM_INT, 'Device token limit, -1 when unknown'),
                'reset' => new external_value(PARAM_INT, 'Days until the next refill, -1 when unknown'),
            ]),
        ]);
    }
}
