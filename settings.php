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
 * Admin settings for block_aiagents.
 *
 * @package    block_aiagents
 * @copyright  2026 Riemann Research
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($ADMIN->fulltree) {
    if (!has_capability('block/aiagents:config', context_system::instance())) {
        return;
    }

    $settings->add(new admin_setting_heading(
        'block_aiagents/settings',
        get_string('settings', 'block_aiagents'),
        ''
    ));

    $settings->add(new admin_setting_configtext(
        'block_aiagents/baseurl',
        get_string('baseurl', 'block_aiagents'),
        get_string('baseurl_desc', 'block_aiagents'),
        'https://api.usefree.space/v1',
        PARAM_URL
    ));

    $settings->add(new admin_setting_configtext(
        'block_aiagents/defaultmodel',
        get_string('defaultmodel', 'block_aiagents'),
        get_string('defaultmodel_desc', 'block_aiagents'),
        '',
        PARAM_TEXT
    ));

    $settings->add(new admin_setting_configtext(
        'block_aiagents/maxtokens',
        get_string('maxtokens', 'block_aiagents'),
        get_string('maxtokens_desc', 'block_aiagents'),
        1024,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtextarea(
        'block_aiagents/systemprompt',
        get_string('systemprompt', 'block_aiagents'),
        get_string('systemprompt_desc', 'block_aiagents'),
        '',
        PARAM_TEXT
    ));
}
