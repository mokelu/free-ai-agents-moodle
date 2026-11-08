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

namespace block_aiagents\privacy;

defined('MOODLE_INTERNAL') || die();

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\writer;

/**
 * Privacy API implementation: stored chats and the per-user API key preference.
 *
 * @package    block_aiagents
 * @copyright  2026 Riemann Research
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {

    /**
     * Describe the data this plugin stores.
     *
     * @param collection $collection
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('block_aiagents_conversation', [
            'userid' => 'privacy:metadata:block_aiagents_conversation:userid',
            'title' => 'privacy:metadata:block_aiagents_conversation:title',
            'model' => 'privacy:metadata:block_aiagents_conversation:model',
            'timecreated' => 'privacy:metadata:block_aiagents_conversation:timecreated',
            'timemodified' => 'privacy:metadata:block_aiagents_conversation:timemodified',
        ], 'privacy:metadata:block_aiagents_conversation');

        $collection->add_database_table('block_aiagents_message', [
            'role' => 'privacy:metadata:block_aiagents_message:role',
            'content' => 'privacy:metadata:block_aiagents_message:content',
            'timecreated' => 'privacy:metadata:block_aiagents_message:timecreated',
        ], 'privacy:metadata:block_aiagents_message');

        $collection->add_user_preference('block_aiagents_apikey', 'privacy:metadata:core_preferences');

        return $collection;
    }

    /**
     * Contexts that hold data for the given user.
     *
     * @param int $userid
     * @return \core_context\contextlist
     */
    public static function get_contexts_for_userid(int $userid): \core_context\contextlist {
        global $DB;
        $list = new \core_context\contextlist();
        $rows = $DB->get_fieldset_sql(
            'SELECT DISTINCT contextid FROM {block_aiagents_conversation} WHERE userid = ?',
            [$userid]
        );
        $list->add($rows);
        return $list;
    }

    /**
     * All users who have data in a context.
     *
     * @param \core_context\context $context
     * @return \core_privacy\local\request\userlist
     */
    public static function get_users_in_context(\context $context): \core_privacy\local\request\userlist {
        global $DB;
        $list = new \core_privacy\local\request\userlist($context, 'block_aiagents');
        if ($context->contextlevel !== CONTEXT_BLOCK) {
            return $list;
        }
        $users = $DB->get_fieldset_sql(
            'SELECT DISTINCT userid FROM {block_aiagents_conversation} WHERE contextid = ?',
            [$context->id]
        );
        $list->add_users($users);
        return $list;
    }

    /**
     * Export a user's data.
     *
     * @param approved_contextlist $contextlist
     * @throws \dml_exception
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;
        $userid = $contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel !== CONTEXT_BLOCK) {
                continue;
            }
            $convs = $DB->get_records_select(
                'block_aiagents_conversation',
                'userid = ? AND contextid = ?',
                [$userid, $context->id],
                'timecreated ASC'
            );
            $path = ['block_aiagents'];
            foreach ($convs as $conv) {
                $messages = $DB->get_records(
                    'block_aiagents_message',
                    ['conversationid' => $conv->id],
                    'id ASC',
                    'role, content'
                );
                writer::with_context($context)->export_data(
                    array_merge($path, ['conversation ' . $conv->id]),
                    (object) [
                        'title' => $conv->title,
                        'model' => $conv->model,
                        'messages' => array_values((array) $messages),
                    ]
                );
            }

            $apikey = get_user_preference('block_aiagents_apikey', '', $userid);
            if ($apikey !== '') {
                writer::with_context($context)->export_metadata($path, 'apikey', 'A bring-your-own API key is set.');
            }
        }
    }

    /**
     * Delete every record for a context.
     *
     * @param \context $context
     * @throws \dml_exception
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;
        if ($context->contextlevel !== CONTEXT_BLOCK) {
            return;
        }
        $convids = $DB->get_fieldset_sql('SELECT id FROM {block_aiagents_conversation} WHERE contextid = ?', [$context->id]);
        foreach ($convids as $id) {
            $DB->delete_records('block_aiagents_message', ['conversationid' => $id]);
        }
        $DB->delete_records('block_aiagents_conversation', ['contextid' => $context->id]);
    }

    /**
     * Delete a user's data in the approved contexts.
     *
     * @param approved_contextlist $contextlist
     * @throws \dml_exception
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;
        $userid = $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel !== CONTEXT_BLOCK) {
                continue;
            }
            $ids = $DB->get_fieldset_sql(
                'SELECT id FROM {block_aiagents_conversation} WHERE userid = ? AND contextid = ?',
                [$userid, $context->id]
            );
            foreach ($ids as $id) {
                $DB->delete_records('block_aiagents_message', ['conversationid' => $id]);
            }
            $DB->delete_records_select(
                'block_aiagents_conversation',
                'userid = ? AND contextid = ?',
                [$userid, $context->id]
            );
            delete_user_preference('block_aiagents_apikey', $userid);
        }
    }
}
