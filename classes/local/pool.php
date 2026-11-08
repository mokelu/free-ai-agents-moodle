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
 * Thin OpenAI-compatible client for the free AI pool, used server-side.
 *
 * @package    block_aiagents
 * @copyright  2026 Riemann Research
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class pool {

    /** @var int Network timeout in seconds for a completion request. */
    const COMPLETION_TIMEOUT = 60;

    /** @var int Network timeout in seconds for a model-list request. */
    const MODELS_TIMEOUT = 15;

    /** @var int Network timeout in seconds for a web-search request. */
    const WEB_TIMEOUT = 30;

    /** @var int Network timeout in seconds for a lightweight status request. */
    const STATUS_TIMEOUT = 12;

    /** @var string Base URL, without trailing slash. */
    protected $baseurl;

    /** @var string Optional bring-your-own key. Blank means use the shared free pool. */
    protected $apikey;

    /** @var string Stable per-user device identifier sent as X-Device-Id. */
    protected $deviceid;

    /**
     * @param string $baseurl
     * @param string $apikey
     * @param string $deviceid
     */
    public function __construct(string $baseurl, string $apikey, string $deviceid) {
        $this->baseurl = rtrim($baseurl, '/');
        $this->apikey = trim($apikey);
        $this->deviceid = $deviceid;
    }

    /**
     * Build a stable device id for a Moodle user, independent of any real device data.
     *
     * @param int $userid
     * @return string
     */
    public static function device_id_for(int $userid): string {
        global $CFG;
        return 'moodle-' . sha1($CFG->wwwroot . '|' . $userid);
    }

    /**
     * Product identity sent with every pool request (family standard).
     *
     * @param array $extra Optional per-call enrichments (intent, tool).
     * @return array
     */
    protected function client_meta(array $extra = []): array {
        $meta = [
            'app' => 'free-ai-agents-moodle',
            'product' => 'Free AI and Agents',
            'platform' => 'moodle',
            'version' => '1.5.5',
        ];
        return array_merge($meta, $extra);
    }

    /**
     * The pool site root, e.g. https://api.usefree.space (baseurl minus /v1).
     *
     * @return string
     */
    protected function site_url(): string {
        return preg_replace('#/v1/?$#', '', $this->baseurl);
    }

    /**
     * The family catalog: the pool's curated model list and its declared
     * default model (catalog.pool.auto). This is the list of models that
     * actually survives on the pool - the raw /models dump is only the
     * fallback when the catalog is unreachable.
     *
     * @return array array{models: array[], auto: string}
     */
    public function catalog(): array {
        $cached = \cache::make('block_aiagents', 'models');
        if (($data = $cached->get('catalog')) !== false && is_array($data) && !empty($data['models'])) {
            return $data;
        }

        $out = ['models' => [], 'auto' => ''];
        $raw = $this->request($this->site_url() . '/api/catalog.json', [], self::MODELS_TIMEOUT);
        if ($raw !== null) {
            $json = json_decode($raw, true);
            $poolcat = is_array($json['pool'] ?? null) ? $json['pool'] : [];
            $auto = '';
            if (isset($poolcat['auto']) && is_string($poolcat['auto']) && $poolcat['auto'] !== '') {
                $auto = $poolcat['auto'];
            } else if (!empty($poolcat['models']) && is_array($poolcat['models']) && !empty($poolcat['models'][0]['id'])) {
                $auto = (string) $poolcat['models'][0]['id'];
            }
            $models = [];
            foreach ((array) ($poolcat['models'] ?? []) as $m) {
                if (empty($m['id']) || !empty($m['discontinued'])) {
                    continue;
                }
                $effort = [];
                if (isset($m['effort']) && is_array($m['effort'])) {
                    foreach ($m['effort'] as $lvl) {
                        if (is_string($lvl) && $lvl !== '') {
                            $effort[] = $lvl;
                        }
                    }
                }
                $models[] = [
                    'id' => (string) $m['id'],
                    'name' => is_string($m['name'] ?? null) && $m['name'] !== '' ? $m['name'] : (string) $m['id'],
                    'isnew' => !empty($m['new']) ? 1 : 0,
                    'effort' => $effort,
                    'effortdefault' => is_string($m['effortDefault'] ?? null) && in_array($m['effortDefault'], $effort, true)
                        ? $m['effortDefault'] : (string) ($m['effortDefault'] ?? ''),
                ];
            }
            if ($models) {
                $out = ['models' => $models, 'auto' => $auto];
                $cached->set('catalog', $out);
            }
        }

        if (empty($out['models'])) {
            // Fallback: the plain OpenAI-style model list.
            $out = ['models' => $this->list_models(), 'auto' => ''];
        }
        return $out;
    }

    /**
     * Free-token quota left on this device (family quota/status endpoint).
     *
     * Unknown values come back as -1.
     *
     * @return array array{left:int, limit:int, reset:int}
     */
    public function quota(): array {
        $meta = $this->client_meta();
        $url = $this->baseurl . '/quota/status'
            . '?app=' . rawurlencode($meta['app'])
            . '&platform=' . rawurlencode($meta['platform'])
            . '&v=' . rawurlencode($meta['version']);

        $raw = $this->request($url, [], self::STATUS_TIMEOUT);
        if ($raw === null) {
            return ['left' => -1, 'limit' => -1, 'reset' => -1];
        }
        $json = json_decode($raw, true);

        $num = static function ($value): int {
            return (isset($value) && is_numeric($value)) ? (int) $value : -1;
        };
        return [
            'left' => $num($json['device_tokens_left'] ?? null),
            'limit' => $num($json['device_limit'] ?? null),
            'reset' => $num($json['device_reset_in_days'] ?? null),
        ];
    }

    /**
     * Fetch the model catalogue. Returns a list of ['id' => ..., 'name' => ...].
     *
     * @return array
     */
    public function list_models(): array {
        $cached = \cache::make('block_aiagents', 'models');
        if (($data = $cached->get('models')) !== false) {
            return $data;
        }

        $raw = $this->request($this->baseurl . '/models', [], self::MODELS_TIMEOUT);
        if ($raw === null) {
            return [];
        }
        $json = json_decode($raw, true);
        $models = [];
        foreach ($json['data'] ?? [] as $m) {
            if (!empty($m['id'])) {
                $models[] = ['id' => $m['id'], 'name' => $m['name'] ?? $m['id']];
            }
        }
        if ($models) {
            $cached->set('models', $models);
        }
        return $models;
    }

    /**
     * Send a chat completion. Returns the assistant text and the model that answered.
     *
     * @param string $model
     * @param array $messages OpenAI-style message list.
     * @param int $maxtokens
     * @return array array{text: string, model: string}
     * @throws \moodle_exception
     */
    public function complete(string $model, array $messages, int $maxtokens, string $effort = ''): array {
        $payload = [
            'model' => $model,
            'messages' => $messages,
            'max_tokens' => $maxtokens,
            'stream' => false,
            'client' => $this->client_meta(['tool' => 'chat']),
        ];
        // Family pattern: the model's own thinking level travels as
        // reasoning_effort ("instant" = no extra thinking).
        if ($effort !== '') {
            $payload['reasoning_effort'] = $effort;
        }

        $headers = ['Content-Type: application/json'];
        if ($this->apikey !== '') {
            $headers[] = 'Authorization: Bearer ' . $this->apikey;
        }

        $raw = $this->request($this->baseurl . '/chat/completions', $headers, self::COMPLETION_TIMEOUT, json_encode($payload));
        if ($raw === null) {
            throw new \moodle_exception('unknowndomain', 'block_aiagents');
        }

        $json = json_decode($raw, true);
        if (isset($json['error'])) {
            // A 429 / exhausted device is surfaced with the friendly free-pool message.
            $code = $json['error']['code'] ?? '';
            if ($code === 'device_exhausted') {
                throw new \moodle_exception('quotaused', 'block_aiagents');
            }
            // Upstream answered but failed: pass its own message through so
            // the user sees the real reason instead of a blanket one.
            $upstream = trim((string) ($json['error']['message'] ?? ''));
            if ($upstream !== '') {
                throw new \moodle_exception('poolupstream', 'block_aiagents', '', $upstream);
            }
            throw new \moodle_exception('unknowndomain', 'block_aiagents');
        }

        $text = $json['choices'][0]['message']['content'] ?? '';
        $used = (string) ($json['model'] ?? $model);
        return ['text' => $text, 'model' => $used];
    }

    /**
     * Real web search through the pool's family web endpoint.
     *
     * @param string $query
     * @return array[] list of ['title' => ..., 'url' => ..., 'snippet' => ...]
     */
    public function web_search(string $query): array {
        $payload = [
            'query' => $query,
            'client' => $this->client_meta(['tool' => 'web.search']),
        ];
        $headers = ['Content-Type: application/json'];
        if ($this->apikey !== '') {
            $headers[] = 'Authorization: Bearer ' . $this->apikey;
        }

        $raw = $this->request($this->baseurl . '/web/search', $headers, self::WEB_TIMEOUT, json_encode($payload));
        if ($raw === null) {
            return [];
        }
        $json = json_decode($raw, true);
        $results = [];
        foreach ((array) ($json['results'] ?? []) as $r) {
            $results[] = [
                'title' => (string) ($r['title'] ?? ''),
                'url' => (string) ($r['url'] ?? ''),
                'snippet' => \core_text::substr((string) ($r['description'] ?? $r['content'] ?? ''), 0, 400),
            ];
        }
        return array_slice($results, 0, 8);
    }

    /**
     * Perform an HTTP request with cURL. Returns the body string, or null on transport error.
     *
     * @param string $url
     * @param array $headers
     * @param int $timeout
     * @param string|null $postbody
     * @return string|null
     */
    protected function request(string $url, array $headers, int $timeout, ?string $postbody = null): ?string {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $this->with_device($headers));
        if ($postbody !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $postbody);
        }
        $body = curl_exec($ch);
        $err = curl_errno($ch);
        curl_close($ch);

        if ($err !== 0 || !is_string($body)) {
            return null;
        }
        return $body;
    }

    /**
     * Append the X-Device-Id header so the pool can track the free allowance.
     *
     * @param array $headers
     * @return array
     */
    protected function with_device(array $headers): array {
        $headers[] = 'X-Device-Id: ' . $this->deviceid;
        return $headers;
    }
}
