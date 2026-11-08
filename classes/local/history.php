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

namespace block_aiagents\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Reads and writes chat conversations and their messages.
 *
 * @package    block_aiagents
 * @copyright  2026 Riemann Research
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class history {

    /** @var int Maximum number of past messages kept per conversation. */
    const WINDOW = 20;

    /**
     * Return the most recent conversations for a user, newest first.
     *
     * @param int $userid
     * @param int $limit
     * @return array
     */
    /**
     * A conversation's title, blank when it has none.
     *
     * @param int $conversationid
     * @param int $userid
     * @return string
     */
    public static function title_of(int $conversationid, int $userid): string {
        global $DB;
        $conv = $DB->get_record('block_aiagents_conversation', ['id' => $conversationid, 'userid' => $userid], 'title');
        return $conv ? (string) $conv->title : '';
    }

    /**
     * Drop the trailing assistant answer and the user message that produced
     * it. Used for family-style retry: the prompt is resend fresh after the
     * old answer (and anything after it) is gone.
     *
     * @param int $conversationid
     * @param int $userid
     * @return void
     */
    public static function drop_last_pair(int $conversationid, int $userid): void {
        global $DB;
        $conv = $DB->get_record('block_aiagents_conversation', ['id' => $conversationid, 'userid' => $userid]);
        if (!$conv) {
            return;
        }
        $sql = 'SELECT id, role FROM {block_aiagents_message} WHERE conversationid = ? ORDER BY id DESC';
        $recent = $DB->get_records_sql($sql, [$conversationid], 0, 2);
        if (!$recent) {
            return;
        }
        $recent = array_values($recent); // newest (highest id) first.
        if ($recent[0]->role !== 'assistant') {
            return; // Nothing to regenerate.
        }
        $DB->delete_records('block_aiagents_message', ['id' => $recent[0]->id]);
        if (isset($recent[1]) && $recent[1]->role === 'user') {
            $DB->delete_records('block_aiagents_message', ['id' => $recent[1]->id]);
        }
        $DB->set_field('block_aiagents_conversation', 'timemodified', time(), ['id' => $conversationid]);
    }

    public static function recent(int $userid, int $limit = 20): array {
        global $DB;
        $records = $DB->get_records(
            'block_aiagents_conversation',
            ['userid' => $userid],
            'timemodified DESC',
            'id, title, model, timemodified',
            0,
            $limit
        );
        $db = $DB;
        return array_values(array_map(static function ($r) use ($db) {
            return [
                'id' => (int) $r->id,
                'title' => $r->title,
                'model' => $r->model,
                'timemodified' => (int) $r->timemodified,
                'msgcount' => (int) $db->count_records('block_aiagents_message', ['conversationid' => $r->id]),
            ];
        }, $records));
    }

    /**
     * Return the ordered messages of a conversation owned by the given user.
     *
     * @param int $conversationid
     * @param int $userid
     * @return array
     * @throws \moodle_exception
     */
    public static function messages(int $conversationid, int $userid): array {
        global $DB;
        self::require_owned($conversationid, $userid);
        $records = $DB->get_records('block_aiagents_message', ['conversationid' => $conversationid], 'id ASC');
        return array_values(array_map(static function ($m) {
            return ['role' => $m->role, 'content' => $m->content];
        }, $records));
    }

    /**
     * Append a user message and an assistant reply, creating the conversation when needed.
     *
     * @param int $conversationid 0 to start a new conversation
     * @param int $userid
     * @param int $contextid
     * @param string $model
     * @param string $userprompt
     * @param string $assistantreply
     * @return int the conversation id
     */
    public static function append(int $conversationid, int $userid, int $contextid, string $model, string $userprompt, string $assistantreply): int {
        global $DB;
        $now = time();

        if ($conversationid <= 0) {
            $conversationid = $DB->insert_record('block_aiagents_conversation', (object) [
                'userid' => $userid,
                'contextid' => $contextid,
                'title' => self::derive_title($userprompt),
                'model' => $model,
                'timecreated' => $now,
                'timemodified' => $now,
            ]);
        } else {
            self::require_owned($conversationid, $userid);
            $DB->update_record('block_aiagents_conversation', (object) [
                'id' => $conversationid,
                'model' => $model,
                'timemodified' => $now,
            ]);
        }

        self::add_message($conversationid, 'user', $userprompt, $now);
        self::add_message($conversationid, 'assistant', $assistantreply, $now);

        self::trim($conversationid);

        return $conversationid;
    }

    /**
     * Delete a conversation and its messages if owned by the user.
     *
     * @param int $conversationid
     * @param int $userid
     * @return void
     */
    public static function delete(int $conversationid, int $userid): void {
        global $DB;
        self::require_owned($conversationid, $userid);
        $DB->delete_records('block_aiagents_message', ['conversationid' => $conversationid]);
        $DB->delete_records('block_aiagents_conversation', ['id' => $conversationid]);
    }

    /**
     * Load a conversation record and ensure it belongs to the user.
     *
     * @param int $conversationid
     * @param int $userid
     * @return object
     * @throws \moodle_exception
     */
    protected static function require_owned(int $conversationid, int $userid): object {
        global $DB;
        $conv = $DB->get_record('block_aiagents_conversation', ['id' => $conversationid, 'userid' => $userid]);
        if (!$conv) {
            throw new \moodle_exception('invalidconversation', 'block_aiagents');
        }
        return $conv;
    }

    /**
     * Insert a single message row.
     *
     * @param int $conversationid
     * @param string $role
     * @param string $content
     * @param int $now
     * @return void
     */
    protected static function add_message(int $conversationid, string $role, string $content, int $now): void {
        global $DB;
        $DB->insert_record('block_aiagents_message', (object) [
            'conversationid' => $conversationid,
            'role' => $role,
            'content' => $content,
            'timecreated' => $now,
        ]);
    }

    /**
     * Keep only the most recent WINDOW messages in a conversation.
     *
     * @param int $conversationid
     * @return void
     */
    protected static function trim(int $conversationid): void {
        global $DB;
        $count = $DB->count_records('block_aiagents_message', ['conversationid' => $conversationid]);
        if ($count <= self::WINDOW) {
            return;
        }
        $excess = $count - self::WINDOW;
        $old = $DB->get_records_select(
            'block_aiagents_message',
            'conversationid = :cid',
            ['cid' => $conversationid],
            'id ASC',
            'id',
            0,
            $excess
        );
        foreach ($old as $o) {
            $DB->delete_records('block_aiagents_message', ['id' => $o->id]);
        }
    }

    /**
     * A short conversation title from the first user prompt, using the
     * family title brain: greetings and non-topical openers stay
     * nameless ("New conversation" in the UI), topical prompts get their
     * first ~6 meaningful words, capitalised, max ~42 chars.
     *
     * @param string $prompt
     * @return string
     */
    protected static function derive_title(string $prompt): string {
        if (!self::looks_like_topic($prompt)) {
            return '';
        }
        return self::make_title($prompt);
    }

    /**
     * Does the prompt actually state a topic worth naming the chat after?
     *
     * @param string $text
     * @return bool
     */
    protected static function looks_like_topic(string $text): bool {
        $t = trim(preg_replace('/\s+/', ' ', $text));
        if (\core_text::strlen($t) < 6) {
            return false;
        }
        if (!preg_match('/[A-Za-z]{3,}/u', $t)) {
            return false;
        }
        if (preg_match('/(.)\1{3,}/u', $t)) {
            return false;
        }
        if (self::is_greeting($t)) {
            return false;
        }
        return true;
    }

    /**
     * Family greeting filter.
     *
     * @param string $text
     * @return bool
     */
    protected static function is_greeting(string $text): bool {
        $t = mb_strtolower($text);
        return (bool) preg_match(
            '/^(hi|hey|hello|yo|sup|greetings|good (morning|afternoon|evening|night)|how are you|how.?s it going|what.?s up|thanks|thank you|ok|okay|cool|nice|great)'
            . '( (there|everyone|all|friend|guys|good|sir|madam|bro|dude|again|thanks|thank you))?( (how are you|how.?s it going|what.?s up))?$/u',
            $t
        );
    }

    /**
     * Family title builder: first ~6 non-stopword words, capitalised.
     *
     * @param string $text
     * @return string
     */
    protected static function make_title(string $text): string {
        $t = trim(preg_replace('/[\r\n]+/', ' ', $text));
        $t = preg_replace('/\s+/', ' ', $t);
        $t = preg_replace('/^[\s\'"`*_#>\-]+/u', '', $t);
        $t = preg_replace('/[\s\'"`*_#]+$/u', '', $t);
        if ($t === '') {
            return '';
        }
        $stop =
            'a|an|the|and|or|but|if|then|so|to|of|in|on|at|for|with|about|from|by|as|is|are|was|were|be|been|being|'
            . 'do|does|did|can|could|would|should|will|shall|may|might|must|i|me|my|mine|we|us|our|you|your|he|she|it|they|them|their|'
            . 'this|that|these|those|there|here|just|really|very|please|help|want|need|like|get|make|let|okay|ok|hey|hi|hello|dear|thanks|thank|'
            . 'what|why|how|when|where|who|which';
        $words = explode(' ', $t);
        $kept = [];
        foreach ($words as $w) {
            $clean = preg_replace('/^[^\p{L}\p{N}]+|[^\p{L}\p{N}]+$/u', '', $w);
            if ($clean === '' || $clean === null) {
                continue;
            }
            if (mb_strlen($clean) < 2 && !ctype_digit($clean)) {
                continue;
            }
            if (preg_match('/^(?:' . $stop . ')$/u', mb_strtolower($clean))) {
                continue;
            }
            $kept[] = $clean;
            if (count($kept) >= 6) {
                break;
            }
        }
        $out = $kept ? implode(' ', $kept) : implode(' ', array_slice($words, 0, 6));
        if (\core_text::strlen($out) > 42) {
            $cut = \core_text::substr($out, 0, 42);
            $sp = mb_strrpos($cut, ' ');
            $out = trim($sp > 12 ? \core_text::substr($cut, 0, $sp) : $cut, " \t,;:.!?-") . '…';
        }
        // Capitalise the first letter without breaking multibyte.
        $first = mb_strtoupper(\core_text::substr($out, 0, 1));
        return $first . \core_text::substr($out, 1);
    }
}
