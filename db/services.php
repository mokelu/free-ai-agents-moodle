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
 * External functions (AJAX) declaration for block_aiagents.
 *
 * @package    block_aiagents
 * @category   webservice
 * @copyright  2026 Riemann Research
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [

    'block_aiagents_get_state' => [
        'classname' => 'block_aiagents\external\get_state',
        'methodname' => 'execute',
        'description' => 'Return models, history and quota for the chat widget.',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'block/aiagents:view',
    ],

    'block_aiagents_request_ai' => [
        'classname' => 'block_aiagents\external\request_ai',
        'methodname' => 'execute',
        'description' => 'Send a chat turn to the free AI pool and store the exchange.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'block/aiagents:view',
    ],

    'block_aiagents_get_messages' => [
        'classname' => 'block_aiagents\external\get_messages',
        'methodname' => 'execute',
        'description' => 'Load the messages of a stored conversation.',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'block/aiagents:view',
    ],

    'block_aiagents_delete_conversation' => [
        'classname' => 'block_aiagents\external\delete_conversation',
        'methodname' => 'execute',
        'description' => 'Delete a stored conversation.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'block/aiagents:view',
    ],
];
