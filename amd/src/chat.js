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
 * Chat widget front end for block_aiagents.
 *
 * Family layout: header (conversation title, quota pill, export, usefree
 * space), log, history view, composer card with icon row, model chip below,
 * thinking indicator, markdown replies, per-message copy/retry actions.
 *
 * @module     block_aiagents/chat
 * @copyright  2026 Riemann Research
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['core/ajax', 'core/notification'], function(Ajax, Notification) {

    var state = {
        contextid: 0,
        strings: {},
        conversationid: 0,
        busy: false,
        webOn: false,
        models: [],
        defaultmodel: '',
        chosenmodel: '',
        efforts: {},
        conversationstale: 0,
        turns: [],
        title: '',
        quota: {left: -1, limit: -1, reset: -1}
    };

    /* ---------- tiny helpers ---------- */

    function mk(tag, cls, text) {
        var d = document.createElement(tag);
        if (cls) {
            d.className = cls;
        }
        if (text != null) {
            d.textContent = text;
        }
        return d;
    }

    /**
     * Find the widget root for a given context id.
     *
     * @param {Number} contextid
     * @returns {Element|null}
     */
    function root(contextid) {
        return document.querySelector('.aiagents[data-contextid="' + contextid + '"]');
    }

    /**
     * Return a child element by its data-region name.
     *
     * @param {Element} el
     * @param {String} name
     * @returns {Element|null}
     */
    function region(el, name) {
        return el.querySelector('[data-region="' + name + '"]');
    }

    /**
     * A language string for this widget, with {$a} replacement when a value
     * is passed.
     *
     * @param {String} key
     * @param {String|Number} [a]
     * @returns {String}
     */
    function s(key, a) {
        var str = state.strings[key] || '';
        if (typeof a !== 'undefined') {
            str = String(str).replace('{$a}', String(a));
        }
        return str;
    }

    function fmtNum(n) {
        return Math.max(0, Math.round(n)).toLocaleString();
    }

    function appName() {
        return s('pluginname') || 'Free AI and Agents';
    }

    /* ---------- scroll policy: never teleport ---------- */

    function logEl(el) {
        return region(el, 'log');
    }

    function nearBottom(log) {
        return log.scrollHeight - log.scrollTop - log.clientHeight < 160;
    }

    function scrollToBottom(log, smooth) {
        try {
            log.scrollTo({top: log.scrollHeight, behavior: smooth ? 'smooth' : 'auto'});
        } catch (e) {
            log.scrollTop = log.scrollHeight;
        }
    }

    function revealNode(log, t) {
        if (!t || !t.isConnected) {
            scrollToBottom(log, false);
            return;
        }
        if (t.offsetHeight <= log.clientHeight - 48) {
            scrollToBottom(log, true);
            return;
        }
        try {
            log.scrollTo({top: Math.max(0, t.offsetTop - 12), behavior: 'smooth'});
        } catch (e) {
            log.scrollTop = Math.max(0, t.offsetTop - 12);
        }
    }

    function sysMsg(el, text) {
        var log = logEl(el);
        var near = nearBottom(log);
        log.appendChild(mk('div', 'aiagents-sys', text));
        if (near) {
            scrollToBottom(log, false);
        }
    }

    /* ---------- markdown renderer (GFM subset, family code, no deps) ---------- */

    function mdEscapeText(str) {
        return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function mdSafeUrl(u) {
        var t = String(u || '').replace(/&amp;/g, '&').trim();
        return /^(https?:\/\/|data:image\/)/i.test(t) ? t : null;
    }

    function mdInline(str) {
        str = mdEscapeText(str);
        str = str.replace(/`([^`]+)`/g, function(m, c) {
            return '<code>' + c + '</code>';
        });
        str = str.replace(/!\[([^\]]*)\]\(([^)\s]+)\)/g, function(m, alt, url) {
            var u = mdSafeUrl(url);
            return u ? '<img src="' + u + '" alt="' + alt + '" loading="lazy">' : m;
        });
        str = str.replace(/\[([^\]]+)\]\(([^)\s]+)\)/g, function(m, t, url) {
            var u = mdSafeUrl(url);
            return u ? '<a href="' + u + '" target="_blank" rel="noreferrer noopener">' + t + '</a>' : m;
        });
        str = str.replace(/(^|[\s(])(https?:\/\/[^\s<)]+[^\s<).,;])/g, function(m, pre, url) {
            var u = mdSafeUrl(url);
            return u ? pre + '<a href="' + u + '" target="_blank" rel="noreferrer noopener">' + url + '</a>' : m;
        });
        str = str.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
        str = str.replace(/(^|[\s(])\*([^*\n]+)\*(?=[\s.,;:!?)]|$)/g, '$1<em>$2</em>');
        str = str.replace(/~~([^~\n]+)~~/g, '<del>$1</del>');
        return str;
    }

    function mdCells(row) {
        var cells = row.split('|');
        if (cells.length && cells[0].trim() === '') {
            cells.shift();
        }
        if (cells.length && cells[cells.length - 1].trim() === '') {
            cells.pop();
        }
        return cells.map(function(c) {
            return c.trim();
        });
    }

    function mdTable(lines, i) {
        var head = mdCells(lines[i]);
        var seps = mdCells(lines[i + 1]);
        var align = seps.map(function(c) {
            if (/^:-+:$/.test(c)) {
                return ' style="text-align:center"';
            }
            if (/^:-+$/.test(c)) {
                return ' style="text-align:right"';
            }
            return '';
        });
        var html = '<table><thead><tr>';
        head.forEach(function(c, k) {
            html += '<th' + (align[k] || '') + '>' + mdInline(c) + '</th>';
        });
        html += '</tr></thead><tbody>';
        i += 2;
        while (i < lines.length) {
            var l = lines[i];
            if (!l.trim() || l.indexOf('|') < 0 || /^\s*```/.test(l)) {
                break;
            }
            html += '<tr>';
            mdCells(l).forEach(function(c, k) {
                html += '<td' + (align[k] || '') + '>' + mdInline(c) + '</td>';
            });
            html += '</tr>';
            i++;
        }
        html += '</tbody></table>';
        return {html: html, next: i};
    }

    function mdRender(src) {
        var lines = String(src || '').replace(/\r\n?/g, '\n').split('\n');
        var out = [];
        var para = [];
        function flush() {
            if (para.length) {
                out.push('<p>' + para.map(mdInline).join('<br>') + '</p>');
                para = [];
            }
        }
        var i = 0;
        while (i < lines.length) {
            var line = lines[i];
            var fence = /^\s*```(.*)$/.exec(line);
            if (fence) {
                flush();
                var buf = [];
                i++;
                while (i < lines.length && !/^\s*```/.test(lines[i])) {
                    buf.push(lines[i++]);
                }
                i++;
                var code = buf.join('\n');
                out.push('<pre><code>' + mdEscapeText(code) + '</code></pre>');
                continue;
            }
            var next = lines[i + 1] || '';
            if (line.indexOf('|') >= 0 && /^\s*\|?[\s:|-]*-[\s:|-]*$/.test(next) && next.indexOf('|') >= 0) {
                flush();
                var t = mdTable(lines, i);
                out.push(t.html);
                i = t.next;
                continue;
            }
            var h = /^(#{1,6})\s+(.*)$/.exec(line);
            if (h) {
                flush();
                var lv = Math.min(4, h[1].length);
                out.push('<h' + lv + '>' + mdInline(h[2]) + '</h' + lv + '>');
                i++;
                continue;
            }
            if (/^\s*[-*]{3,}\s*$/.test(line) || /^\s*_{3,}\s*$/.test(line)) {
                flush();
                out.push('<hr>');
                i++;
                continue;
            }
            if (/^\s*>/.test(line)) {
                flush();
                var bq = [];
                while (i < lines.length && /^\s*>/.test(lines[i])) {
                    bq.push(lines[i].replace(/^\s*>\s?/, ''));
                    i++;
                }
                out.push('<blockquote><p>' + bq.map(mdInline).join('<br>') + '</p></blockquote>');
                continue;
            }
            var lm = /^(\s*)([-+*]|\d+[.)])\s+(.*)$/.exec(line);
            if (lm) {
                flush();
                var html = '';
                var stack = [];
                function closeLi() {
                    if (stack.length && stack[stack.length - 1].open) {
                        html += '</li>';
                        stack[stack.length - 1].open = false;
                    }
                }
                function closeTo(n) {
                    while (stack.length > n) {
                        var t2 = stack.pop();
                        if (t2.open) {
                            html += '</li>';
                        }
                        html += t2.ord ? '</ol>' : '</ul>';
                    }
                }
                while (i < lines.length) {
                    var m2 = /^(\s*)([-+*]|\d+[.)])\s+(.*)$/.exec(lines[i]);
                    if (!m2) {
                        break;
                    }
                    var dep = Math.min(2, Math.floor(m2[1].replace(/\t/g, '  ').length / 2));
                    var ord = /^\d/.test(m2[2]);
                    if (dep + 1 <= stack.length) {
                        closeTo(dep + 1);
                    }
                    if (stack.length === dep + 1 && stack[stack.length - 1].ord !== ord) {
                        closeTo(dep);
                    }
                    var txt = m2[3];
                    var task = null;
                    var tm = /^\[([ xX])\]\s+(.*)$/.exec(txt);
                    if (tm) {
                        task = tm[1].toLowerCase() === 'x';
                        txt = tm[2];
                    }
                    while (stack.length < dep + 1) {
                        stack.push({ord: ord, open: false});
                        html += ord ? '<ol>' : '<ul>';
                    }
                    closeLi();
                    html += '<li>' + (task === null ? '' : '<input type="checkbox" disabled' + (task ? ' checked' : '') + '> ') + mdInline(txt);
                    stack[stack.length - 1].open = true;
                    i++;
                }
                closeTo(0);
                out.push(html);
                continue;
            }
            if (!line.trim()) {
                flush();
                i++;
                continue;
            }
            para.push(line);
            i++;
        }
        flush();
        return out.join('');
    }

    /* ---------- clipboard (family) ---------- */

    function fallbackCopy(text, done) {
        var ta = document.createElement('textarea');
        ta.value = text;
        ta.style.position = 'fixed';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.select();
        try {
            document.execCommand('copy');
            done();
        } catch (e) {
            done(s('copyblocked'));
        }
        ta.remove();
    }

    function copyText(el, text) {
        var done = function(msg) {
            sysMsg(el, msg || s('copied'));
        };
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(function() {
                done();
            }, function() {
                fallbackCopy(text, done);
            });
        } else {
            fallbackCopy(text, done);
        }
    }

    /* ---------- per-message icon buttons ---------- */

    var ICONS = {
        copy: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V5a1 1 0 0 1 1-1h9"/></svg>',
        retry: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M20 11a8 8 0 1 0-2.3 6.3"/><path d="M20 5v6h-6"/></svg>',
        info: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="9"/><path d="M12 11v5" stroke-linecap="round"/><circle cx="12" cy="8" r=".8" fill="currentColor"/></svg>'
    };

    function mact(el, name, title, onclick) {
        var b = mk('button', 'aiagents-mact');
        b.type = 'button';
        b.innerHTML = ICONS[name];
        b.title = title;
        if (onclick) {
            b.addEventListener('click', onclick);
        }
        return b;
    }

    /* ---------- message rendering ---------- */

    function addUserTurn(el, text) {
        var log = logEl(el);
        log.appendChild(mk('div', 'aiagents-msg', text));

        var uacts = mk('div', 'aiagents-msgactions aiagents-uacts');
        uacts.appendChild(mact(el, 'copy', 'Copy', function() {
            copyText(el, text);
        }));
        log.appendChild(uacts);

        state.turns.push({who: 'u', text: text});
        scrollToBottom(log, true);
    }

    function addAiTurn(el, text, modelName) {
        var log = logEl(el);
        var near = nearBottom(log);
        var t = mk('div', 'aiagents-msg aiagents-msg-ai');
        t.innerHTML = mdRender(text);
        log.appendChild(t);

        var acts = mk('div', 'aiagents-msgactions');
        acts.appendChild(mact(el, 'copy', 'Copy', function() {
            copyText(el, text);
        }));
        var rt = mact(el, 'retry', s('retrytitle') || 'Retry', function() {
            retryTurn(el, acts);
        });
        acts.appendChild(rt);
        var sub = modelName ? mk('span', 'aiagents-msub', modelName) : null;
        if (sub) {
            acts.appendChild(sub);
        }
        acts.appendChild(mk('span', 'aiagents-spacer'));
        var info = mact(el, 'info', s('whythistitle') || 'Why am I seeing this?', function() {
            sysMsg(el, s('whythis') || 'Runs on the free pool (usefree.space).');
        });
        acts.appendChild(info);
        log.appendChild(acts);

        state.turns.push({who: 'a', text: text, model: modelName});
        if (near) {
            revealNode(log, t);
        } else {
            scrollToBottom(log, false);
        }
    }

    function addToolCard(el, title, text) {
        var card = mk('div', 'aiagents-toolcard');
        card.appendChild(mk('div', 'aiagents-toolhead', title));
        if (text) {
            var b = mk('div', 'aiagents-toolout');
            b.innerHTML = mdRender(text);
            card.appendChild(b);
        }
        var log = logEl(el);
        var near = nearBottom(log);
        log.appendChild(card);
        if (near) {
            scrollToBottom(log, true);
        }
    }

    /* ---------- header title + quota pill ---------- */

    function setHeaderTitle(el, t) {
        // Moodle already prints the app name as the block title; this header
        // is the conversation's own header only. Untitled = "New conversation".
        var label = t || (s('newconversation') || 'New conversation');
        region(el, 'headtitle').textContent = label;
        region(el, 'headtitle').title = label;
    }

    function updateQuota(el, quota) {
        if (quota) {
            state.quota = quota;
        }
        var q = state.quota;
        var txt = region(el, 'qpilltxt');
        var pill = region(el, 'qpill');
        if (!q || typeof q.left !== 'number' || q.left < 0) {
            txt.textContent = s('qnote') || 'Free tokens';
            pill.classList.remove('out');
            return;
        }
        txt.textContent = q.left <= 0
            ? (s('usedup') || 'used up')
            : (s('tokensleft', fmtNum(q.left)) || (fmtNum(q.left) + ' tokens left'));
        pill.classList.toggle('out', q.left <= 0);
    }

    function qSummary() {
        var q = state.quota;
        var refill = q && typeof q.reset === 'number' && q.reset >= 0
            ? (s('qdays', q.reset) || ('in ' + q.reset + ' days'))
            : '—';
        return [
            [s('qleft') || 'Left now', q && typeof q.left === 'number' && q.left >= 0 ? fmtNum(q.left) : (s('checking') || '…checking')],
            [s('qlimit') || 'Limit', q && typeof q.limit === 'number' && q.limit >= 0 ? fmtNum(q.limit) : '—'],
            [s('qrefill') || 'Refill', refill]
        ];
    }

    function toggleQpop(el, force) {
        var pop = region(el, 'qpop');
        var open = typeof force === 'boolean' ? force : pop.hidden;
        if (open) {
            pop.innerHTML = '';
            pop.appendChild(mk('div', 'qnote', s('qnote') || 'Free tokens'));
            qSummary().forEach(function(r) {
                var row = mk('div', 'qrow');
                row.appendChild(mk('span', null, r[0]));
                row.appendChild(mk('span', 'qv', r[1]));
                pop.appendChild(row);
            });
            var go = mk('button', 'qgo', s('qgetmore') || 'Get more at usefree.space');
            go.type = 'button';
            go.addEventListener('click', function() {
                toggleQpop(el, false);
                window.open('https://usefree.space', '_blank');
            });
            pop.appendChild(go);
        }
        pop.hidden = !open;
    }

    /* ---------- export this chat (family) ---------- */

    function exportChat(el) {
        var turns = state.turns;
        if (!turns.length) {
            sysMsg(el, s('exportempty') || 'Nothing to export yet.');
            return;
        }
        var title = state.title || appName();
        var md = '# ' + title + '\n\n' +
            'Exported from ' + appName() + ' - ' + new Date().toLocaleString() + '\n\n';
        turns.forEach(function(t) {
            md += (t.who === 'u' ? '**You:** ' : '**AI:** ') + String(t.text || '') + '\n\n';
        });
        md += '---\nGenerated by ' + appName() + ' (free tokens via usefree.space)\n';
        var name = String(title).replace(/[^\w\- ]+/g, '').trim().replace(/\s+/g, '-').toLowerCase().slice(0, 48) || 'conversation';

        // 1) clipboard copy (always works)
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(md).catch(function() {});
        } else {
            fallbackCopy(md, function() {});
        }
        // 2) real .md file download
        try {
            var url = URL.createObjectURL(new Blob([md], {type: 'text/markdown'}));
            var a = document.createElement('a');
            a.href = url;
            a.download = name + '.md';
            document.body.appendChild(a);
            a.click();
            a.remove();
            setTimeout(function() {
                URL.revokeObjectURL(url);
            }, 4000);
            sysMsg(el, s('exportdone', name + '.md'));
        } catch (e) {
            sysMsg(el, s('exportblocked'));
        }
    }

    /* thinking indicator: spinner + elapsed transcript (family pattern) */

    var typingEl = null;
    var typingTick = null;

    function thinkingText(sec) {
        var lab = sec < 8 ? state.strings.thinking : sec < 25 ? state.strings.stillthinking : state.strings.stillworking;
        lab = lab || '…';
        return sec >= 3 ? lab + ' ' + sec + 's' : lab;
    }

    function showTyping(el) {
        clearTyping();
        var log = logEl(el);
        var near = nearBottom(log);
        typingEl = mk('div', 'aiagents-thinking');
        typingEl.appendChild(mk('span', 'aiagents-spin'));
        var lab = mk('span', 'aiagents-thlab', thinkingText(0));
        typingEl.appendChild(lab);
        var t0 = Date.now();
        typingTick = setInterval(function() {
            lab.textContent = thinkingText(Math.floor((Date.now() - t0) / 1000));
        }, 1000);
        log.appendChild(typingEl);
        if (near) {
            scrollToBottom(log, false);
        }
    }

    function clearTyping() {
        if (typingTick) {
            clearInterval(typingTick);
            typingTick = null;
        }
        if (typingEl) {
            typingEl.remove();
            typingEl = null;
        }
    }

    /* ---------- composer behaviour ---------- */

    function autosize(text) {
        text.style.height = '21px';
        text.style.height = Math.min(text.scrollHeight, 99) + 'px';
        refreshSend(text.closest('.aiagents'));
    }

    function refreshSend(el) {
        var text = region(el, 'text');
        var send = region(el, 'send');
        send.disabled = !(text.value || '').trim() || state.busy;
    }

    /* ---------- send / reply / retry ---------- */

    function currentPageContext() {
        var title = typeof document !== 'undefined' && document.title ? document.title.replace(/\s+/g, ' ').trim().slice(0, 120) : '';
        var url = typeof location !== 'undefined' ? String(location.href).split('#')[0].slice(0, 512) : '';
        return {pagetitle: title, pageurl: url};
    }

    function doSend(el, prompt, opts) {
        opts = opts || {};
        state.busy = true;
        refreshSend(el);

        var log = logEl(el);
        // On a fresh send the user's bubble is already in the log; on retry
        // the family flow keeps the old user bubble visible as-is.
        if (!opts.retry) {
            addUserTurn(el, prompt);
        } else {
            state.turns.push({who: 'u', text: prompt});
        }
        scrollToBottom(log, true);
        showTyping(el);

        var ctx = currentPageContext();
        Ajax.call([{
            methodname: 'block_aiagents_request_ai',
            args: {
                contextid: state.contextid,
                conversationid: state.conversationid,
                model: state.chosenmodel,
                prompt: prompt,
                web: state.webOn ? 1 : 0,
                pagetitle: ctx.pagetitle,
                pageurl: ctx.pageurl,
                retry: opts.retry ? 1 : 0,
                effort: currentEffort()
            }
        }])[0].then(function(result) {
            clearTyping();
            state.busy = false;
            refreshSend(el);
            if (result.webnote) {
                addToolCard(el, s('webtooltitle'), s('websearched', result.webquery || '') + '\n\n' + result.webnote);
            } else if (state.webOn && !opts.retry) {
                sysMsg(el, s('webunavailable'));
            }
            if (result.error) {
                sysMsg(el, result.error);
            } else {
                addAiTurn(el, result.reply, modelName(result.model || ''));
            }
            if (result.conversationid) {
                state.conversationid = result.conversationid;
                refreshHistoryTile(el, result.conversationid);
            }
            if (typeof result.title === 'string') {
                state.title = result.title;
                setHeaderTitle(el, result.title);
            }
            if (result.quota) {
                updateQuota(el, result.quota);
            }
            return;
        }).catch(function(error) {
            clearTyping();
            state.busy = false;
            refreshSend(el);
            Notification.exception(error);
        });
    }

    function refreshHistoryTile(el, conversationid) {
        var c = (state.conversations || []).filter(function(x) {
            return x.id === conversationid;
        })[0];
        if (c) {
            c.msgcount = (c.msgcount || 0) + 2;
            c.timemodified = Math.floor(Date.now() / 1000);
            if (!c.title && state.title) {
                c.title = state.title;
            }
            fillHistory(el, state.conversations);
        }
    }

    function send(el) {
        var text = region(el, 'text');
        var prompt = (text.value || '').trim();
        if (!prompt || state.busy) {
            return;
        }
        text.value = '';
        autosize(text);
        doSend(el, prompt, {});
    }

    /* retry: drop the old answer (and anything after it), re-ask the same
       prompt. The stored conversation drops the old pair server-side. */
    function retryTurn(el, acts) {
        if (state.busy) {
            return;
        }
        var log = logEl(el);
        var ai = acts.previousElementSibling;
        if (!ai || !ai.classList.contains('aiagents-msg-ai')) {
            return;
        }
        var u = ai.previousElementSibling;
        while (u && !u.classList.contains('aiagents-msg')) {
            u = u.previousElementSibling;
        }
        if (!u) {
            return;
        }
        var prompt = (u.textContent || '').trim();
        if (!prompt) {
            return;
        }

        // Truncate the visible log back to (and keeping) the user's bubble.
        while (log.lastChild && log.lastChild !== u) {
            log.removeChild(log.lastChild);
        }
        // Keep state.turns aligned: cut everything after that user turn.
        var idx = -1;
        var usernum = 0;
        for (var k = 0; k < state.turns.length; k++) {
            if (state.turns[k].who === 'u') {
                usernum++;
                if (usernum === countUserBubbles(log, u)) {
                    idx = k;
                    break;
                }
            }
        }
        if (idx >= 0) {
            state.turns = state.turns.slice(0, idx + 1);
        } else {
            state.turns.push({who: 'u', text: prompt});
        }
        doSend(el, prompt, {retry: true});
    }

    function countUserBubbles(log, u) {
        var n = 0;
        var kids = log.children;
        for (var i = 0; i < kids.length; i++) {
            if (kids[i].classList.contains('aiagents-msg') && !kids[i].classList.contains('aiagents-msg-ai')) {
                n++;
                if (kids[i] === u) {
                    return n;
                }
            }
        }
        return n;
    }

    /* ---------- model chip + searchable menu ---------- */

    function humanize(id) {
        if (typeof id !== 'string') {
            return String(id);
        }
        return id.replace(/[-_]+/g, ' ').replace(/(^|\s)\S/g, function(c) {
            return c.toUpperCase();
        });
    }

    function modelName(id) {
        var i;
        if (!id) {
            return '';
        }
        for (i = 0; i < state.models.length; i++) {
            if (state.models[i].id === id) {
                return state.models[i].name || humanize(id);
            }
        }
        return humanize(id);
    }

    function setChipLabel(el) {
        var label = region(el, 'modellabel');
        label.textContent = state.chosenmodel === '' ? (s('auto') || 'Auto') : (modelName(state.chosenmodel) || state.chosenmodel);
    }

    function fillModels(el, models, defaultmodel) {
        state.models = (models || []).map(function(m) {
            return {
                id: m.id,
                name: m.name || m.id,
                isnew: m.isnew ? 1 : 0,
                effort: Array.isArray(m.effort) ? m.effort : [],
                effortdefault: m.effortdefault || ''
            };
        });
        state.defaultmodel = defaultmodel || '';
        state.chosenmodel = state.chosenmodel || state.defaultmodel;
        buildModelRows(el, '');
        setChipLabel(el);
    }

    function findModel(id) {
        for (var i = 0; i < state.models.length; i++) {
            if (state.models[i].id === id) {
                return state.models[i];
            }
        }
        return null;
    }

    /* Family: the chosen model's thinking level travels as reasoning_effort.
       "instant" means no extra thinking. Returns '' when the model has none. */
    function currentEffort() {
        var m = findModel(state.chosenmodel);
        if (!m || !m.effort.length) {
            return '';
        }
        var cur = state.efforts[m.id];
        if (!cur || m.effort.indexOf(cur) < 0) {
            cur = m.effortdefault || '';
        }
        return cur;
    }

    function effortLabel(lv) {
        return String(lv).charAt(0).toUpperCase() + String(lv).slice(1);
    }

    function buildModelRows(el, query) {
        var list = region(el, 'modellist');
        list.innerHTML = '';
        var q = (query || '').toLowerCase();
        var shown = 0;
        state.models.forEach(function(m) {
            if (q && String(m.id + ' ' + m.name).toLowerCase().indexOf(q) < 0) {
                return;
            }
            if (shown++ > 400) {
                return;
            }

            // one group per model: name row + thinking strip underneath
            var g = mk('div', 'aiagents-mgroup');
            var row = mk('button', 'aiagents-mrow', m.name || m.id);
            row.type = 'button';
            row.title = m.id;
            if (m.isnew) {
                row.appendChild(mk('span', 'aiagents-newb', s('newbadge') || 'NEW'));
            }
            if (m.id === state.chosenmodel) {
                row.classList.add('on');
            }
            row.addEventListener('click', function(ev) {
                ev.stopPropagation();
                state.chosenmodel = m.id;
                var rows = list.querySelectorAll('.aiagents-mrow');
                for (var i = 0; i < rows.length; i++) {
                    rows[i].classList.remove('on');
                }
                row.classList.add('on');
                setChipLabel(el);
            });
            g.appendChild(row);

            if (m.effort.length) {
                var cur = state.efforts[m.id];
                if (!cur || m.effort.indexOf(cur) < 0) {
                    cur = m.effortdefault || '';
                }
                var strip = mk('div', 'aiagents-effort');
                m.effort.forEach(function(lv) {
                    var eb = mk('button', cur === lv ? 'on' : '', effortLabel(lv));
                    eb.type = 'button';
                    eb.title = lv === 'instant' ? (s('effortinstant') || 'No extra thinking. Fastest.') : (s('efforttitle') || 'Thinking level');
                    eb.addEventListener('click', function(ev) {
                        ev.stopPropagation();
                        state.efforts[m.id] = lv;
                        var all = strip.querySelectorAll('button');
                        for (var i = 0; i < all.length; i++) {
                            all[i].classList.remove('on');
                        }
                        eb.classList.add('on');
                        sysMsg(el, (m.name || m.id) + ' thinking: ' + (lv === 'instant' ? 'off · fastest' : effortLabel(lv)));
                    });
                    strip.appendChild(eb);
                });
                g.appendChild(strip);
            }

            list.appendChild(g);
        });
        if (!shown) {
            list.appendChild(mk('div', 'aiagents-mempty', '—'));
        }
    }

    function toggleModelMenu(el, force) {
        var menu = region(el, 'modelmenu');
        var open = typeof force === 'boolean' ? force : menu.hidden;
        menu.hidden = !open;
        if (open) {
            toggleHelpMenu(el, false);
        }
    }

    /* ---------- get help menu (email / discord / telegram / reddit) ---------- */

    function toggleHelpMenu(el, force) {
        var menu = region(el, 'helpmenu');
        var open = typeof force === 'boolean' ? force : menu.hidden;
        menu.hidden = !open;
        if (open) {
            toggleModelMenu(el, false);
            toggleQpop(el, false);
        }
    }

    function fillHelpMenu(el) {
        var menu = region(el, 'helpmenu');
        Array.prototype.forEach.call(menu.querySelectorAll('.aiagents-hrow'), function(row) {
            var ht = row.querySelector('.ht');
            if (!ht || !ht.dataset.label) {
                return;
            }
            var hl = ht.querySelector('[data-region=hl]');
            var hs = ht.querySelector('[data-region=hs]');
            if (hl) {
                hl.textContent = s(ht.dataset.label) || ht.dataset.label;
            }
            if (hs) {
                hs.textContent = s(ht.dataset.sub) || '';
            }
        });
    }

    /* ---------- history view ---------- */

    function fmtDate(ts) {
        var d = new Date(ts * 1000);
        try {
            return d.toLocaleString(undefined, {year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit'});
        } catch (e) {
            return String(ts);
        }
    }

    function fillHistory(el, conversations) {
        state.conversations = conversations || [];
        var box = region(el, 'historylist');
        box.innerHTML = '';
        var convs = state.conversations.slice().sort(function(a, b) {
            return b.timemodified - a.timemodified;
        });
        if (!convs.length) {
            box.appendChild(mk('div', 'aiagents-hempty', s('historyempty') || 'No conversations yet.'));
            return;
        }
        convs.forEach(function(c) {
            var item = mk('button', 'aiagents-history-item');
            item.type = 'button';
            var ht = mk('div', 'ht', c.title || (s('newconversation') || 'New conversation'));
            var hd = mk('div', 'hd', fmtDate(c.timemodified) + ' · ' + (c.msgcount || 0) + ' msg');
            item.appendChild(ht);
            item.appendChild(hd);
            item.addEventListener('click', function() {
                loadConversation(el, c);
            });

            var del = mk('button', 'hx', '×');
            del.type = 'button';
            del.title = s('deletetitle');
            del.addEventListener('click', function(ev) {
                ev.stopPropagation();
                removeConversation(el, c.id);
            });
            item.appendChild(del);

            box.appendChild(item);
        });
    }

    function removeConversation(el, conversationid) {
        Ajax.call([{
            methodname: 'block_aiagents_delete_conversation',
            args: {contextid: state.contextid, conversationid: conversationid}
        }])[0].then(function() {
            state.conversations = state.conversations.filter(function(c) {
                return c.id !== conversationid;
            });
            fillHistory(el, state.conversations);
            if (state.conversationid === conversationid) {
                state.conversationid = 0;
                state.turns = [];
                state.title = '';
                setHeaderTitle(el, state.title);
                logEl(el).innerHTML = '';
            }
            return;
        }).catch(function(error) {
            Notification.exception(error);
        });
    }

    function loadConversation(el, c) {
        state.conversationid = c.id;
        state.chosenmodel = c.model || state.chosenmodel;
        state.turns = [];
        setChipLabel(el);
        closeHistory(el);
        var log = logEl(el);
        log.innerHTML = '';
        Ajax.call([{
            methodname: 'block_aiagents_get_messages',
            args: {contextid: state.contextid, conversationid: c.id}
        }])[0].then(function(result) {
            state.title = result.title || '';
            setHeaderTitle(el, state.title);
            (result.messages || []).forEach(function(m) {
                if (m.role === 'assistant') {
                    addAiTurn(el, m.content, modelName(m.model || ''));
                } else {
                    state.turns.push({who: 'u', text: m.content});
                    var log2 = logEl(el);
                    log2.appendChild(mk('div', 'aiagents-msg', m.content));
                    var uacts = mk('div', 'aiagents-msgactions aiagents-uacts');
                    uacts.appendChild(mact(el, 'copy', 'Copy', function() {
                        copyText(el, m.content);
                    }));
                    log2.appendChild(uacts);
                }
            });
            scrollToBottom(log, false);
            return;
        }).catch(function(error) {
            Notification.exception(error);
        });
    }

    function openHistory(el) {
        var box = region(el, 'history');
        box.hidden = false;
        el.classList.add('aiagents-history-open');
        var log = logEl(el);
        log.style.display = 'none';
        Ajax.call([{
            methodname: 'block_aiagents_get_state',
            args: {contextid: state.contextid}
        }])[0].then(function(result) {
            if (result.models) {
                fillModels(el, result.models, result.defaultmodel || state.defaultmodel);
            }
            fillHistory(el, result.conversations || state.conversations || []);
            if (result.quota) {
                updateQuota(el, result.quota);
            }
            return;
        }).catch(function(error) {
            Notification.exception(error);
        });
    }

    function closeHistory(el) {
        var box = region(el, 'history');
        box.hidden = true;
        el.classList.remove('aiagents-history-open');
        logEl(el).style.display = '';
    }

    function newConversation(el) {
        state.conversationid = 0;
        state.turns = [];
        state.title = '';
        setHeaderTitle(el, state.title);
        closeHistory(el);
        logEl(el).innerHTML = '';
    }

    /* ---------- voice input (Web Speech API, device microphone) ---------- */

    function startVoice(el) {
        var mic = region(el, 'mic');
        var text = region(el, 'text');
        var SR = window.SpeechRecognition || window.webkitSpeechRecognition;
        if (!SR) {
            sysMsg(el, s('voiceunavailable'));
            return;
        }
        if (mic.runningRecog) {
            // Second click while listening: stop the current session.
            try {
                mic.runningRecog.stop();
            } catch (e) {
                /* onend does the cleanup */
            }
            return;
        }
        var recog;
        try {
            recog = new SR();
        } catch (e) {
            sysMsg(el, s('voiceunavailable'));
            return;
        }
        recog.lang = (navigator.language || 'en-US');
        recog.interimResults = true;
        recog.continuous = false;
        var base = text.value;
        var stop = function() {
            mic.classList.remove('listening');
            mic.runningRecog = null;
        };
        recog.onstart = function() {
            mic.classList.add('listening');
        };
        recog.onresult = function(e) {
            var t = '';
            for (var i = 0; i < e.results.length; i++) {
                t += e.results[i][0].transcript;
            }
            text.value = base + (base && t ? ' ' : '') + t;
            autosize(text);
            refreshSend(el);
        };
        recog.onerror = function(e) {
            if (e.error === 'not-allowed' || e.error === 'service-not-allowed') {
                sysMsg(el, s('microphonedenied'));
            } else if (e.error !== 'no-speech') {
                sysMsg(el, s('micerror', e.error));
            }
        };
        recog.onend = stop;
        mic.runningRecog = recog;
        try {
            recog.start();
        } catch (e) {
            stop();
            sysMsg(el, s('voiceunavailable'));
        }
    }

    /* ---------- wire up ---------- */

    function bind(el) {
        var text = region(el, 'text');
        var sendBtn = region(el, 'send');

        region(el, 'togglehistory').title = s('historylabel');
        region(el, 'web').title = s('webon');
        region(el, 'mic').title = s('voicetitle') || 'Voice input';
        sendBtn.title = s('send');
        region(el, 'export').title = s('exporttitle') || 'Export this chat';
        region(el, 'ext').title = s('exttitle') || 'usefree.space';
        region(el, 'qpill').title = s('qpilltitle') || 'Free tokens left on this device';
        text.placeholder = s('placeholder');
        region(el, 'newchat').textContent = s('newconversation');
        el.querySelector('[data-string="history"]').textContent = s('historylabel');
        region(el, 'modelsearch').placeholder = s('modelnote') || 'Model';
        setHeaderTitle(el, '');
        updateQuota(el, null);
        text.focus();

        text.addEventListener('input', function() {
            autosize(text);
            refreshSend(el);
        });
        refreshSend(el);

        var formlessSubmit = function(e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                send(el);
            }
        };
        text.addEventListener('keydown', formlessSubmit);

        sendBtn.addEventListener('click', function(e) {
            e.preventDefault();
            send(el);
        });

        region(el, 'web').addEventListener('click', function() {
            state.webOn = !state.webOn;
            var on = state.webOn;
            this.classList.toggle('on', on);
            this.setAttribute('aria-pressed', on ? 'true' : 'false');
            this.title = on ? (s('weboff') || 'Web search (toggle) — ON') : (s('webon') || 'Web search (toggle)');
            sysMsg(el, on ? s('webon') : s('weboff'));
        });

        region(el, 'mic').addEventListener('click', function() {
            startVoice(el);
        });

        region(el, 'togglehistory').addEventListener('click', function() {
            var box = region(el, 'history');
            if (box.hidden) {
                openHistory(el);
            } else {
                closeHistory(el);
            }
        });

        region(el, 'newchat').addEventListener('click', function() {
            newConversation(el);
        });

        region(el, 'export').addEventListener('click', function() {
            exportChat(el);
        });

        region(el, 'ext').addEventListener('click', function() {
            window.open('https://usefree.space', '_blank');
        });

        region(el, 'qpill').addEventListener('click', function(ev) {
            ev.stopPropagation();
            toggleQpop(el, region(el, 'qpop').hidden);
        });

        document.addEventListener('click', function(ev) {
            var qpop = region(el, 'qpop');
            if (qpop && !qpop.hidden && !qpop.contains(ev.target) && !region(el, 'qpill').contains(ev.target)) {
                toggleQpop(el, false);
            }
            var menu = region(el, 'modelmenu');
            if (!el.contains(ev.target)) {
                toggleModelMenu(el, false);
                toggleHelpMenu(el, false);
            } else if (menu && !menu.hidden && !region(el, 'modelchip').contains(ev.target) && !menu.contains(ev.target)) {
                toggleModelMenu(el, false);
            }
            var hmenu = region(el, 'helpmenu');
            if (hmenu && !hmenu.hidden && !region(el, 'help').contains(ev.target) && !hmenu.contains(ev.target)) {
                toggleHelpMenu(el, false);
            }
        });

        region(el, 'modelchip').addEventListener('click', function(ev) {
            ev.stopPropagation();
            var menu = region(el, 'modelmenu');
            toggleModelMenu(el, menu.hidden);
            if (!menu.hidden) {
                var search = region(el, 'modelsearch');
                search.value = '';
                buildModelRows(el, '');
                search.focus();
            }
        });

        region(el, 'modelsearch').addEventListener('input', function() {
            buildModelRows(el, this.value);
        });

        region(el, 'modelmenu').addEventListener('click', function(ev) {
            ev.stopPropagation();
            toggleQpop(el, false);
        });

        /* get help: labels from strings; the email row opens the mail app */
        region(el, 'helplabel').textContent = s('gethelptitle') || 'Get help';
        region(el, 'help').title = s('gethelptitle') || 'Get help';
        fillHelpMenu(el);
        region(el, 'help').addEventListener('click', function(ev) {
            ev.stopPropagation();
            toggleHelpMenu(el, region(el, 'helpmenu').hidden);
        });
        region(el, 'helpmenu').addEventListener('click', function() {
            // any row click closes the menu; the mailto row opens the mail app itself
            toggleHelpMenu(el, false);
        });
    }

    /* ---------- init ---------- */

    return {
        /**
         * Initialise the widget for a block instance.
         *
         * Moodle's js_call_amd passes each top-level element of the params
         * array as a separate positional argument, so this must be
         * init(contextid, strings) rather than init({contextid, strings}).
         *
         * @param {Number} contextid
         * @param {Object} strings
         */
        init: function(contextid, strings) {
            state.contextid = contextid;
            state.strings = strings || {};

            var el = root(contextid);
            if (!el) {
                return;
            }

            bind(el);

            Ajax.call([{
                methodname: 'block_aiagents_get_state',
                args: {contextid: contextid}
            }])[0].then(function(result) {
                fillModels(el, result.models || [], result.defaultmodel || '');
                state.conversations = result.conversations || [];
                fillHistory(el, state.conversations);
                if (result.quota) {
                    updateQuota(el, result.quota);
                }
                return;
            }).catch(function(error) {
                Notification.exception(error);
            });
        }
    };
});
