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

/**
 * Language strings for block_aiagents.
 *
 * @package    block_aiagents
 * @copyright  2026 Riemann Research
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['aiagents:addinstance'] = 'Add a new Free AI and Agents block';
$string['aiagents:config'] = 'Configure Free AI and Agents settings';
$string['aiagents:myaddinstance'] = 'Add a Free AI and Agents block to the Dashboard';
$string['aiagents:view'] = 'Use the Free AI and Agents chat';
$string['apikey'] = 'Bring-your-own API key (optional)';
$string['apikey_desc'] = 'Leave blank to use the shared free pool. Paste a provider key to route requests directly and skip the free allowance.';
$string['baseurl'] = 'Free pool base URL';
$string['baseurl_desc'] = 'OpenAI-compatible base URL of the free AI pool. Default points at api.usefree.space.';
$string['cachedef_models'] = 'Free pool model catalogue';
$string['chatsend'] = 'Send';
$string['chatplaceholder'] = 'Ask anything…';
$string['clear'] = 'New chat';
$string['webon'] = 'Web search enabled for your next messages.';
$string['weboff'] = 'Web search disabled.';
$string['webtooltitle'] = 'Search the web';
$string['websearched'] = 'Searched: {$a}';
$string['webunavailable'] = 'Web search is unavailable right now. Answering from my own knowledge instead.';
$string['voiceunavailable'] = 'Speech recognition is not available in this browser — typing still works.';
$string['microphonedenied'] = 'Microphone permission denied. Allow mic access for this site, then try again.';
$string['micerror'] = 'Mic error: {$a}';
$string['nomicrophone'] = 'No microphone found on this device.';
$string['voicetitle'] = 'Voice input';
$string['copied'] = 'Copied to clipboard.';
$string['copyblocked'] = 'Copy blocked in this environment.';
$string['copycode'] = 'Copy code';
$string['exporttitle'] = 'Export this chat';
$string['exportempty'] = 'Nothing to export yet — this chat is empty.';
$string['exportdone'] = 'Chat exported — {$a} went to your Downloads folder. A plain-text copy was also put on your clipboard as a backup.';
$string['exportblocked'] = 'The file download was blocked here, but the full transcript is on your clipboard. Paste it into any text editor to save it as a .md file.';
$string['retrytitle'] = 'Retry — resend the same prompt for a new answer';
$string['exttitle'] = 'usefree.space';
$string['qpilltitle'] = 'Free tokens left on this device — click for details';
$string['qnote'] = 'Free tokens';
$string['qleft'] = 'Left now';
$string['qlimit'] = 'Limit';
$string['qrefill'] = 'Refill';
$string['qdays'] = 'in {$a}';
$string['qgetmore'] = 'Get more at usefree.space';
$string['usedup'] = 'used up';
$string['tokensleft'] = '{$a} tokens left';
$string['checking'] = '…checking';
$string['whythistitle'] = 'Why am I seeing this?';
$string['whythis'] = 'Runs on the free pool (usefree.space) — separate from your Moodle AI credits.';
$string['newbadge'] = 'NEW';
$string['efforttitle'] = 'Thinking level';
$string['effortinstant'] = 'No extra thinking — fastest.';
$string['gethelptitle'] = 'Get help';
$string['helpemail'] = 'Email us';
$string['helpemailnote'] = 'We always reply in under an hour.';
$string['helpemailsys'] = 'Opening your email app for support@riemannresearch.space — we always reply in under an hour.';
$string['helpdiscord'] = 'Join our Discord';
$string['helptelegram'] = 'Telegram';
$string['helpreddit'] = 'Reddit';
$string['helpredditnote'] = 'r/FreeAIAgentsMoodle — coming soon';
$string['helpsoon'] = 'Coming soon';
$string['thinking'] = 'Thinking…';
$string['stillthinking'] = 'Still thinking…';
$string['stillworking'] = 'Still working — this one is taking a while';
$string['newconversation'] = 'New conversation';
$string['historyempty'] = 'No conversations yet.';
$string['modelnote'] = 'Model';
$string['deletetitle'] = 'Delete this conversation';
$string['historylabel'] = 'Conversation history';
$string['maxtokens'] = 'Max tokens per reply';
$string['maxtokens_desc'] = 'Upper bound on the length of each assistant reply.';
$string['defaultmodel'] = 'Default model';
$string['defaultmodel_desc'] = 'Model id used when the user has not picked one.';
$string['deleteconversation'] = 'Delete this conversation';
$string['history'] = 'History';
$string['model'] = 'Model';
$string['auto'] = 'Auto';
$string['pluginname'] = 'Free AI and Agents';
$string['privacy:metadata:block_aiagents_conversation'] = 'Chat conversations created with the Free AI and Agents block.';
$string['privacy:metadata:block_aiagents_conversation:title'] = 'The conversation title.';
$string['privacy:metadata:block_aiagents_conversation:model'] = 'The model used for the conversation.';
$string['privacy:metadata:block_aiagents_conversation:timecreated'] = 'When the conversation was created.';
$string['privacy:metadata:block_aiagents_conversation:timemodified'] = 'When the conversation was last changed.';
$string['privacy:metadata:block_aiagents_conversation:userid'] = 'The owner of the conversation.';
$string['privacy:metadata:block_aiagents_message'] = 'Individual messages within a conversation.';
$string['privacy:metadata:block_aiagents_message:content'] = 'The message text.';
$string['privacy:metadata:block_aiagents_message:role'] = 'Whether the message is from the user or the assistant.';
$string['privacy:metadata:block_aiagents_message:timecreated'] = 'When the message was created.';
$string['privacy:metadata:core_preferences'] = 'Per-user preferences such as the last selected model.';
$string['quotaused'] = 'Free tokens are used up on this device. Get more at usefree.space — no account, no signup.';
$string['settings'] = 'Free AI and Agents settings';
$string['invalidconversation'] = 'That conversation could not be found or does not belong to you.';
$string['systemprompt'] = 'Assistant system prompt';
$string['systempromptdefault'] = 'You are a helpful assistant embedded in Moodle. Answer clearly and concisely.';
$string['systemprompt_desc'] = 'Persona instructions sent with every request.';
$string['title'] = 'Free AI and Agents';
$string['unknowndomain'] = 'The pool could not be reached. Please try again shortly.';
$string['poolupstream'] = 'The pool is having a problem right now: {$a}. Try again shortly, or pick another model.';
$string['usercontent'] = 'The prompt or reply text stored in the conversation.';
