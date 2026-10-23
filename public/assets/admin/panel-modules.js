(function () {
    'use strict';
    var components = {};
    function request(path, payload) {
        var base = '/api/v1/' + window.settings.secure_path + '/modules/';
        return fetch(base + path, {
            method: payload ? 'POST' : 'GET', credentials: 'same-origin',
            headers: { 'Authorization': localStorage.getItem('authorization') || '',
                'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: payload ? JSON.stringify(payload) : undefined
        }).then(function (r) { return r.json().catch(function () { return {}; }).then(function (body) {
            if (!r.ok) throw new Error(body.message || '请求失败，请稍后重试');
            return body.data;
        }); });
    }
    window.PanelModules = {
        displayDownloadUrl: function (value) {
            if (typeof value !== 'string') return value;
            // Decode UTF-8 text only; escaped URL delimiters and percent signs must retain their meaning.
            return value.replace(/(?:%[89a-f][0-9a-f])+/gi, function (encoded) {
                try { return decodeURIComponent(encoded); } catch (_) { return encoded; }
            });
        },
        section: function (React, kind) {
            if (!components[kind]) {
                var h = React.createElement;
                class ModuleSection extends React.Component {
                    constructor(props) {
                        super(props);
                        this.state = { data: null, form: null, rows: [], offset: 0, cursors: [], next: null,
                            loading: true, saving: false, dirty: false, error: '', notice: '' };
                        this.alive = false;
                        this.generation = 0;
                    }
                    componentDidMount() { this.alive = true; this.load(); }
                    componentWillUnmount() { this.alive = false; this.generation++; }
                    load() {
                        var self = this, generation = ++this.generation;
                        self.setState({ loading: true, error: '', notice: '' });
                        return request(kind).then(function (data) {
                            if (!self.alive || generation !== self.generation) return;
                            var form = Object.assign({}, kind === 'gateway' ? data.panels[0] : data.group);
                            if (kind === 'telegram') {
                                ['enabled', 'verify_join', 'verify_message'].forEach(function (k) { form[k] = !!form[k]; });
                                form.token = ''; form.chat_id = String(form.chat_id);
                            }
                            self.setState({ data: data, form: form, dirty: false, loading: false, cursors: [], offset: 0 }, function () { if (kind === 'telegram') self.loadRows(0); });
                        }).catch(function (e) { if (self.alive && generation === self.generation) self.setState({ error: e.message, loading: false }); });
                    }
                    loadRows(offset) {
                        if (kind !== 'telegram') return Promise.resolve();
                        var self = this, generation = this.generation;
                        self.setState({ rowsLoading: true, rowError: '' });
                        return request('telegram/events?before=' + offset).then(function (d) {
                            if (!self.alive || generation !== self.generation) return;
                            self.setState({ rows: d.items, offset: offset, next: d.next_cursor, rowsLoading: false });
                        }).catch(function (e) { if (self.alive && generation === self.generation) self.setState({ rowError: e.message, rowsLoading: false }); });
                    }
                    change(key, value) {
                        var form = Object.assign({}, this.state.form); form[key] = value;
                        this.setState({ form: form, dirty: true, notice: '', error: '' });
                    }
                    save(configure) {
                        var self = this, form = this.state.form, payload;
                        var entryDraft = kind === 'gateway' && !configure && (form.entryDomain || '') !== (this.state.data.panels[0].entryDomain || '') ? form.entryDomain : null;
                        if (kind === 'gateway') payload = configure ? { domain: form.entryDomain || '', revision: form.revision }
                            : { name: form.name, clientName: form.clientName, status: form.status, revision: form.revision };
                        else {
                            payload = { enabled: form.enabled, verify_join: form.verify_join, verify_message: form.verify_message, revision: form.revision };
                            if (configure) {
                                if (form.token) payload.token = form.token.trim();
                                payload.chat_id = String(form.chat_id).trim();
                            }
                        }
                        this.setState({ saving: true, configuring: !!configure, error: '', notice: '' });
                        return request(kind + (configure ? '/configure' : ''), payload).then(function (result) {
                            if (!self.alive) return;
                            return self.load().then(function () {
                                if (!self.alive || self.state.error) return;
                                if (!configure) {
                                    if (entryDraft !== null) self.change('entryDomain', entryDraft);
                                    self.setState({ notice: '保存成功' }); return;
                                }
                                if (kind === 'gateway') { self.setState({ notice: '反代域名配置成功，本站入口已生效' }); return; }
                                self.setState({ notice: '校验通过，正在接入本站机器人...' });
                                return self.waitForConnection(self.generation, 40);
                            });
                        }).catch(function (e) { if (self.alive) self.setState({ error: e.message, notice: '' }); })
                            .finally(function () { if (self.alive) self.setState({ saving: false }); });
                    }
                    waitForConnection(generation, remaining) {
                        var self = this;
                        if (!self.alive || generation !== self.generation) return Promise.resolve();
                        return request('telegram').then(function (data) {
                            if (!self.alive || generation !== self.generation) return;
                            if (data.connection_applied) { self.setState({ notice: '自动配置成功', data: data }); return; }
                            if (!remaining) { self.setState({ notice: '', error: '配置已保存，但接收进程尚未确认生效，请稍后刷新检查' }); return; }
                            return new Promise(function (resolve) { setTimeout(resolve, 2000); }).then(function () { return self.waitForConnection(generation, remaining - 1); });
                        });
                    }
                    field(label, key, readonly, value, secret) {
                        var self = this;
                        return h('label', { className: 'pm-field', key: key }, h('span', null, label),
                            h('input', { className: 'form-control', type: secret ? 'password' : 'text', value: value == null ? (self.state.form[key] || '') : value,
                                autoComplete: secret ? 'new-password' : undefined,
                                placeholder: secret ? self.state.data.bot_token_masked : undefined,
                                title: secret ? '留空不更换；只更新本站机器人' : undefined,
                                readOnly: readonly, maxLength: key === 'entryDomain' ? 253 : 120, disabled: self.state.saving,
                                onChange: function (e) { self.change(key, e.target.value); } }));
                    }
                    toggle(label, key, checked, fn) {
                        var self = this;
                        return h('label', { className: 'pm-toggle', key: key }, h('input', { type: 'checkbox', checked: checked,
                            disabled: this.state.saving, onChange: function (e) { fn ? fn(e.target.checked) : self.change(key, e.target.checked); } }), h('span', null, label));
                    }
                    render() {
                        var self = this, s = this.state, f = s.form, d = s.data;
                        var title = kind === 'gateway' ? '客户端授权管理' : '群组与验证管理';
                        var headers = ['时间', 'Telegram 用户', '操作', '详情'];
                        var connectionDirty = f && (kind === 'telegram' ? (f.token || String(f.chat_id) !== String(d.group.chat_id))
                            : (f.entryDomain || '') !== (d.panels[0].entryDomain || ''));
                        var settingsDirty = kind === 'gateway' && f && ['name', 'clientName', 'status'].some(function (key) { return f[key] !== d.panels[0][key]; });
                        return h('section', { className: 'panel-module', 'data-panel-module': kind },
                            h('div', { className: 'pm-heading' }, h('h3', null, title),
                                h('button', { type: 'button', className: 'btn btn-light pm-icon', title: '刷新', 'aria-label': '刷新', disabled: s.loading || s.saving || s.dirty,
                                    onClick: function () { self.load(); } }, h('i', { className: 'fa fa-sync-alt', 'aria-hidden': true }))),
                            h('div', { className: 'pm-feedback', 'aria-live': 'polite', 'aria-atomic': true },
                                (s.error || s.notice) && h('div', { className: 'alert ' + (s.error ? 'alert-danger' : 'alert-success'), role: s.error ? 'alert' : 'status' }, s.error || s.notice)),
                            s.loading && !f ? h('div', { className: 'pm-empty' }, '加载中...') : f && h(React.Fragment, null,
                                h('div', { className: 'pm-form' }, kind === 'gateway' ? [
                                    self.field('网站名称', 'name'), self.field('客户端名称', 'clientName'),
                                    self.field('本站网关地址', 'public_url', true, d.public_url), self.field('本站标识', 'panel_id', true, f.id),
                                    self.field('反代域名', 'entryDomain'), self.field('客户端入口上游', 'entry_url', true, d.panels[0].entryDomain ? 'https://' + d.panels[0].entryDomain : ''),
                                    self.toggle('启用新网关授权', 'status', f.status === 'active', function (v) { self.change('status', v ? 'active' : 'disabled'); })
                                ] : [self.field('Token', 'token', false, null, true), self.field('群组 ID', 'chat_id', false),
                                    h('div', { className: 'pm-toggles', key: 'toggles' }, self.toggle('启用群组管理', 'enabled', f.enabled),
                                        self.toggle('入群验证', 'verify_join', f.verify_join), self.toggle('发言验证', 'verify_message', f.verify_message))]),
                                h('div', { className: 'pm-actions' }, h('button', {
                                    type: 'button', className: 'btn btn-primary', disabled: s.saving || (kind === 'gateway' && (!f.entryDomain || settingsDirty)),
                                    onClick: function () { self.save(true); } }, h('i', { className: 'fa fa-cog', 'aria-hidden': true }), s.saving && s.configuring ? ' 配置中...' : (kind === 'gateway' ? ' 配置' : ' 自动配置')),
                                    h('button', { type: 'button', className: 'btn btn-primary', disabled: !s.dirty || s.saving || (kind === 'telegram' ? !!connectionDirty : !settingsDirty),
                                    onClick: function () { self.save(); } }, h('i', { className: 'fa fa-save', 'aria-hidden': true }), s.saving && !s.configuring ? ' 保存中...' : ' 保存'),
                                    s.dirty && h('button', { type: 'button', className: 'btn btn-light', disabled: s.saving, onClick: function () { self.load(); } }, '取消')),
                                kind === 'telegram' && h(React.Fragment, null,
                                h('h4', { className: 'pm-list-heading' }, '本站操作记录'),
                                s.rowError && h('div', { role: 'alert', className: 'alert alert-danger' }, s.rowError),
                                h('div', { className: 'pm-table-wrap', 'aria-busy': !!s.rowsLoading }, h('table', { className: 'table' },
                                    h('thead', null, h('tr', null, headers.map(function (label) { return h('th', { key: label }, label); }))),
                                    h('tbody', null, s.rows.length ? s.rows.map(function (row) {
                                        var cells = [new Date(row.created_at * 1000).toLocaleString(), row.display_name || row.username || row.telegram_id || '-', row.action, row.reason];
                                        return h('tr', { key: row.id }, cells.map(function (cell, i) { return h('td', { key: i }, cell || '-'); }));
                                    }) : h('tr', null, h('td', { colSpan: 4, className: 'pm-empty' }, s.rowsLoading ? '加载中...' : '暂无记录'))))),
                                h('div', { className: 'pm-pagination' },
                                    h('button', { type: 'button', className: 'btn btn-light pm-icon', title: '上一页', 'aria-label': '上一页', disabled: s.rowsLoading || !s.cursors.length,
                                        onClick: function () { var c = s.cursors.slice(), prev = c.pop(); self.setState({ cursors: c }); self.loadRows(prev); } }, h('i', { className: 'fa fa-angle-left' })),
                                    h('button', { type: 'button', className: 'btn btn-light pm-icon', title: '下一页', 'aria-label': '下一页', disabled: s.rowsLoading || s.next == null,
                                        onClick: function () { self.setState({ cursors: s.cursors.concat([s.offset]) }); self.loadRows(s.next); } }, h('i', { className: 'fa fa-angle-right' }))))));
                    }
                }
                components[kind] = ModuleSection;
            }
            return React.createElement(components[kind], { key: 'panel-module-' + kind });
        }
    };
}());
