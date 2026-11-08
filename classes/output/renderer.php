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

namespace block_aiagents\output;

use plugin_renderer_base;

defined('MOODLE_INTERNAL') || die();

/**
 * Renderer for the block_aiagents chat widget.
 *
 * @package    block_aiagents
 * @copyright  2026 Riemann Research
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class renderer extends plugin_renderer_base {

    /**
     * Render the chat container and hook up the AMD front end.
     *
     * @param \block_aiagents $block
     * @param int $userid
     * @return string
     */
    public function render_chat_container(\block_aiagents $block, int $userid): string {
        $contextid = $block->context->id;

        // Hand the context id and a small set of strings to the AMD module.
        $this->page->requires->js_call_amd('block_aiagents/chat', 'init', [
            'contextid' => $contextid,
            'strings' => [
                'send' => get_string('chatsend', 'block_aiagents'),
                'placeholder' => get_string('chatplaceholder', 'block_aiagents'),
                'newconversation' => get_string('newconversation', 'block_aiagents'),
                'historylabel' => get_string('historylabel', 'block_aiagents'),
                'model' => get_string('model', 'block_aiagents'),
                'modelnote' => get_string('modelnote', 'block_aiagents'),
                'auto' => get_string('auto', 'block_aiagents'),
                'deletetitle' => get_string('deletetitle', 'block_aiagents'),
                'voicetitle' => get_string('voicetitle', 'block_aiagents'),
                'webon' => get_string('webon', 'block_aiagents'),
                'weboff' => get_string('weboff', 'block_aiagents'),
                'webtooltitle' => get_string('webtooltitle', 'block_aiagents'),
                'websearched' => get_string('websearched', 'block_aiagents'),
                'webunavailable' => get_string('webunavailable', 'block_aiagents'),
                'voiceunavailable' => get_string('voiceunavailable', 'block_aiagents'),
                'microphonedenied' => get_string('microphonedenied', 'block_aiagents'),
                'micerror' => get_string('micerror', 'block_aiagents'),
                'thinking' => get_string('thinking', 'block_aiagents'),
                'stillthinking' => get_string('stillthinking', 'block_aiagents'),
                'stillworking' => get_string('stillworking', 'block_aiagents'),
                'historyempty' => get_string('historyempty', 'block_aiagents'),
                'pluginname' => get_string('pluginname', 'block_aiagents'),
                'copied' => get_string('copied', 'block_aiagents'),
                'copyblocked' => get_string('copyblocked', 'block_aiagents'),
                'exporttitle' => get_string('exporttitle', 'block_aiagents'),
                'exportempty' => get_string('exportempty', 'block_aiagents'),
                'exportdone' => get_string('exportdone', 'block_aiagents'),
                'exportblocked' => get_string('exportblocked', 'block_aiagents'),
                'retrytitle' => get_string('retrytitle', 'block_aiagents'),
                'exttitle' => get_string('exttitle', 'block_aiagents'),
                'qpilltitle' => get_string('qpilltitle', 'block_aiagents'),
                'qnote' => get_string('qnote', 'block_aiagents'),
                'qleft' => get_string('qleft', 'block_aiagents'),
                'qlimit' => get_string('qlimit', 'block_aiagents'),
                'qrefill' => get_string('qrefill', 'block_aiagents'),
                'qgetmore' => get_string('qgetmore', 'block_aiagents'),
                'usedup' => get_string('usedup', 'block_aiagents'),
                'tokensleft' => get_string('tokensleft', 'block_aiagents'),
                'checking' => get_string('checking', 'block_aiagents'),
                'whythistitle' => get_string('whythistitle', 'block_aiagents'),
                'whythis' => get_string('whythis', 'block_aiagents'),
                'newbadge' => get_string('newbadge', 'block_aiagents'),
                'efforttitle' => get_string('efforttitle', 'block_aiagents'),
                'effortinstant' => get_string('effortinstant', 'block_aiagents'),
                'gethelptitle' => get_string('gethelptitle', 'block_aiagents'),
                'helpemail' => get_string('helpemail', 'block_aiagents'),
                'helpemailnote' => get_string('helpemailnote', 'block_aiagents'),
                'helpemailsys' => get_string('helpemailsys', 'block_aiagents'),
                'helpdiscord' => get_string('helpdiscord', 'block_aiagents'),
                'helptelegram' => get_string('helptelegram', 'block_aiagents'),
                'helpreddit' => get_string('helpreddit', 'block_aiagents'),
                'helpredditnote' => get_string('helpredditnote', 'block_aiagents'),
                'helpsoon' => get_string('helpsoon', 'block_aiagents'),
                'quota' => get_string('quotaused', 'block_aiagents'),
                'unreachable' => get_string('unknowndomain', 'block_aiagents'),
            ],
        ]);

        return $this->render_from_template('block_aiagents/chat', [
            'contextid' => $contextid,
        ]);
    }
}
