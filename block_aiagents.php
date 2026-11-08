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
 * Block class for block_aiagents.
 *
 * @package    block_aiagents
 * @copyright  2026 Riemann Research
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class block_aiagents extends block_base {

    /**
     * Initialise the block.
     */
    public function init(): void {
        $this->title = get_string('pluginname', 'block_aiagents');
    }

    /**
     * The block has a settings page.
     *
     * @return bool
     */
    public function has_config(): bool {
        return true;
    }

    /**
     * Page formats this block can be added to.
     *
     * @return array
     */
    public function applicable_formats(): array {
        return [
            'all' => true,
        ];
    }

    /**
     * Allow only one chat block per page to avoid duplicate widgets.
     *
     * @return bool
     */
    public function instance_allow_multiple(): bool {
        return false;
    }

    /**
     * Build the block content: a lightweight container that the AMD module hydrates.
     *
     * @return stdClass|null
     */
    public function get_content(): ?stdClass {
        global $USER;

        if ($this->content !== null) {
            return $this->content;
        }

        $this->content = new stdClass();
        $this->content->text = '';
        $this->content->footer = '';

        $context = context_block::instance($this->instance->id);
        if (!isguestuser() && has_capability('block/aiagents:view', $context)) {
            /** @var \block_aiagents\output\renderer $renderer */
            $renderer = $this->page->get_renderer('block_aiagents');
            $this->content->text = $renderer->render_chat_container($this, $USER->id);
        }

        return $this->content;
    }
}
