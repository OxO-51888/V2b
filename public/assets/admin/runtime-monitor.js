(function () {
    'use strict';
    var Component;
    var modules = [['core', '核心业务'], ['client_gateway', '客户端网关'], ['telegram', 'Telegram'],
        ['support', '客服中心'], ['security', '安全风控'], ['system', '系统运行']];
    var names = { order_handle: '订单队列', traffic_fetch: '流量消费队列', stat: '统计队列',
        send_email: '邮件队列', send_email_mass: '邮件群发队列', send_telegram: 'Telegram 消息队列', default: '默认队列' };
    var labels = { ok: '正常', configured: '已配置', disabled: '已停用', unconfigured: '未配置',
        unknown: '待确认', warning: '需检查', error: '异常', running: '执行中', idle: '暂无请求', active: '有请求' };
    function number(v) { return typeof v === 'number' && Number.isFinite(v) ? String(v) : '—'; }
    function duration(v) {
        if (typeof v !== 'number' || !Number.isFinite(v) || v < 0) return '—';
        return v >= 1000 ? String(Math.round(v / 10) / 100) + ' 秒' : String(Math.round(v * 10) / 10) + ' ms';
    }
    function time(v) { return v ? new Date(v * 1000).toLocaleTimeString('zh-CN', { hour12: false }) : '—'; }
    function date(v) { return v ? new Date(v * 1000).toLocaleString('zh-CN', { hour12: false }) : '—'; }
    window.RuntimeMonitor = { section: function (React) {
        var h = React.createElement;
        function status(value, text) { return h('span', { className: 'runtime-state runtime-state-' + (labels[value] ? value : 'unknown') }, text || labels[value] || labels.unknown); }
        function table(headings, rows, kind) {
            rows = rows.map(function (row) { return React.cloneElement(row, {}, React.Children.map(row.props.children, function (cell, index) {
                return React.cloneElement(cell, { 'data-label': headings[index] });
            })); });
            return h('div', { className: 'runtime-table-wrap ' + (kind || '') }, h('table', { className: 'table table-vcenter runtime-table' },
                h('thead', null, h('tr', null, headings.map(function (name) { return h('th', { key: name }, name); }))),
                h('tbody', null, rows.length ? rows : h('tr', null, h('td', { colSpan: headings.length }, '暂无数据')))));
        }
        if (!Component) Component = class extends React.Component {
            constructor(props) {
                super(props);
                this.state = { stats: null, queues: null, signals: null, activity: null, errors: {}, times: {}, received: {}, now: Date.now(), tab: 'queues', config: null };
                this.alive = false; this.controller = null; this.configController = null;
                this.visibility = this.onVisibility.bind(this);
            }
            componentDidMount() {
                this.alive = true; document.addEventListener('visibilitychange', this.visibility); this.refresh();
                this.clock = setInterval(() => { if (!document.hidden) this.setState({ now: Date.now() }); }, 1000);
            }
            componentWillUnmount() {
                this.alive = false; clearTimeout(this.timer); clearInterval(this.clock);
                if (this.controller) this.controller.abort();
                if (this.configController) this.configController.abort();
                document.removeEventListener('visibilitychange', this.visibility);
            }
            onVisibility() {
                clearTimeout(this.timer);
                if (document.hidden) { if (this.controller) this.controller.abort(); }
                else this.refresh();
            }
            async read(method, signal) {
                var response = await fetch('/api/v1/' + window.settings.secure_path + '/system/' + method, {
                    credentials: 'same-origin', cache: 'no-store', signal: signal,
                    headers: { Authorization: localStorage.getItem('authorization') || '', Accept: 'application/json' }
                });
                if (!response.ok) throw new Error(response.status === 401 || response.status === 403 ? '登录已失效' : '读取失败');
                var body = await response.json();
                if (!body || !body.data || typeof body.data !== 'object') throw new Error('数据不完整');
                return body.data;
            }
            async refresh() {
                if (!this.alive || this.controller || document.hidden) return;
                clearTimeout(this.timer);
                var controller = new AbortController(), start = Date.now(); this.controller = controller;
                var timeout = setTimeout(() => controller.abort(), 7000), next = {};
                try {
                    var data = await this.read('getRealtimeStatus', controller.signal);
                    if (!Number.isFinite(data.checked_at) || !data.errors || typeof data.errors !== 'object') throw new Error('数据不完整');
                    next = { errors: {}, now: Date.now(), serverAt: data.checked_at, serverReceived: Date.now() };
                    var times = Object.assign({}, this.state.times), received = Object.assign({}, this.state.received);
                    ['stats', 'queues', 'signals', 'activity'].forEach(function (key) {
                        var valid = data[key] && (key === 'queues' ? Array.isArray(data[key]) : typeof data[key] === 'object');
                        if (data.errors[key] || !valid) next.errors[key] = '读取失败';
                        else { next[key] = data[key]; times[key] = data.checked_at; received[key] = Date.now(); }
                    });
                    next.times = times;
                    next.received = received;
                } catch (e) {
                    next = { errors: { stats: '数据已断开', queues: '数据已断开', signals: '数据已断开', activity: '数据已断开' },
                        connectionError: e.message === '登录已失效' ? e.message : '连接中断', now: Date.now() };
                } finally { clearTimeout(timeout); this.controller = null; }
                if (!this.alive) return;
                if (!next.connectionError) next.connectionError = null;
                this.setState(next);
                if (!document.hidden) this.timer = setTimeout(() => this.refresh(), Math.max(500, 3000 - (Date.now() - start)));
            }
            async select(tab) {
                this.setState({ tab: tab });
                if (tab !== 'config' || this.configController) return;
                var controller = new AbortController(); this.configController = controller;
                var timeout = setTimeout(() => controller.abort(), 7000);
                try {
                    var data = await this.read('getSystemStatus', controller.signal);
                    if (this.alive) this.setState({ config: data, configError: null });
                } catch (e) { if (this.alive) this.setState({ configError: '配置检查读取失败' }); }
                finally { clearTimeout(timeout); this.configController = null; }
            }
            stale(key) { return !!this.state.errors[key] || !this.state.received[key] || this.state.now - this.state.received[key] > 10000; }
            sum(key) {
                var rows = this.state.queues;
                return rows && rows.every(row => typeof row[key] === 'number') ? rows.reduce((n, row) => n + row[key], 0) : null;
            }
            metric(label, value, icon, kind, detail) {
                return h('div', { className: 'runtime-metric', key: label, 'data-metric': label },
                    h('div', { className: 'runtime-metric-label' }, label, h('i', { className: icon, 'aria-hidden': true })),
                    h('strong', { className: kind ? 'runtime-state-' + kind : '' }, value), h('small', null, detail || '\u00a0'));
            }
            render() {
                var s = this.state, stats = s.stats || {}, signals = s.signals || {}, activity = s.activity || {},
                    scheduler = signals.scheduler || {}, tg = signals.telegram || {}, queues = s.queues || [];
                var staleStats = this.stale('stats'), staleQueues = this.stale('queues'), staleSignals = this.stale('signals'), staleActivity = this.stale('activity');
                var queueState = staleStats || typeof stats.status !== 'boolean' ? 'unknown' : stats.pausedMasters > 0 ? 'warning' : stats.status ? 'ok' : 'error';
                var queueText = queueState === 'unknown' ? '待确认' : stats.pausedMasters > 0 ? '已暂停' : stats.status ? '运行中' : '未启动';
                var serverNow = s.serverAt ? s.serverAt + (s.now - s.serverReceived) / 1000 : s.now / 1000;
                var heartbeatAge = scheduler.last ? Math.max(0, Math.floor(serverNow - scheduler.last)) : null;
                var disconnected = staleStats || staleQueues || staleSignals || staleActivity;
                var byModule = {}; (activity.modules || []).forEach(row => { byModule[row.id] = row; });
                var taskRecords = activity.tasks || {};
                var rows = modules.map(([id, name]) => {
                    var row = byModule[id] || {}, owned = queues.filter(q => q.module === id), state = 'unknown', hint = '等待首次采集';
                    if (!staleActivity) { state = row.requests ? row.errors ? 'warning' : 'active' : 'idle'; hint = row.last_at ? '最近请求 ' + time(row.last_at) : '暂无请求记录'; }
                    if (id === 'telegram') { state = staleSignals ? 'unknown' : tg.status; hint = staleSignals ? '心跳数据未更新' : tg.last ? '心跳 ' + Math.max(0, Math.floor(serverNow - tg.last)) + ' 秒前' : '尚未收到心跳'; }
                    if (owned.length && !staleQueues) {
                        var pending = owned.reduce((n, q) => n + (q.pending || 0), 0), reserved = owned.reduce((n, q) => n + (q.reserved || 0), 0);
                        state = owned.some(q => q.status === 'unknown') ? 'unknown' : owned.some(q => q.status !== 'ok') ? 'warning' : reserved ? 'running' : pending ? 'warning' : 'ok';
                        hint = '待处理 ' + pending + ' · 执行中 ' + reserved;
                    }
                    if (id === 'system' && !staleSignals && scheduler.status !== 'ok') { state = 'warning'; hint = '调度心跳异常'; }
                    if (owned.length && staleQueues) { state = 'unknown'; hint = '队列数据未更新'; }
                    if (!staleActivity && row.errors > 0) { state = 'warning'; hint += ' · 请求异常'; }
                    if (id === 'client_gateway' && !staleActivity && !row.requests) hint = '等待客户端请求';
                    return h('tr', { key: id, 'data-module': id }, h('td', null, h('strong', null, name)),
                        h('td', null, status(state), h('small', { className: 'runtime-module-hint' }, hint)),
                        h('td', null, staleActivity ? '—' : number(row.requests)),
                        h('td', { className: row.errors > 0 && !staleActivity ? 'text-danger' : '' }, staleActivity ? '—' : number(row.errors)),
                        h('td', null, staleActivity ? '—' : number(row.rejected)),
                        h('td', null, staleActivity ? '—' : duration(row.avg_ms)));
                });
                var detail;
                if (s.tab === 'queues') detail = table(['队列', '状态', '进程', '待处理', '执行中 / 已领取', '延迟', '等待'], queues.map(row =>
                    h('tr', { key: row.key || row.name }, h('td', null, names[row.name] || row.name, h('small', { className: 'runtime-owner' }, row.module_name)),
                        h('td', null, status(staleQueues ? 'unknown' : row.status)), h('td', null, number(row.processes)), h('td', null, number(row.pending)),
                        h('td', null, number(row.reserved)), h('td', null, number(row.delayed)), h('td', null, typeof row.wait === 'number' ? row.wait + ' s' : '—'))), 'runtime-queue-table');
                else if (s.tab === 'tasks') detail = table(['任务', '频率', '最近状态', '开始时间', '完成时间', '耗时'], (signals.tasks || []).map(row => {
                    var record = taskRecords[row.command] || {};
                    return h('tr', { key: row.command }, h('td', null, row.name), h('td', null, row.frequency),
                        h('td', null, status(staleActivity ? 'unknown' : record.state || 'unknown', staleActivity ? null : !record.started_at ? '待首次执行' : record.state === 'ok' ? '已完成' : null)),
                        h('td', null, date(record.started_at)), h('td', null, date(record.finished_at)), h('td', null, duration(record.duration_ms)));
                }), 'runtime-task-table');
                else detail = s.configError ? h('p', { className: 'runtime-error' }, s.configError) : table(['检查项', '结果', '详情'], ((s.config || {}).modules || []).map(row =>
                    h('tr', { key: row.id }, h('td', null, row.name), h('td', null, status(row.status)), h('td', null, row.detail))), 'runtime-config-table');
                return h('div', { className: 'runtime-monitor' },
                    h('div', { className: 'runtime-metrics' },
                        this.metric('运行状态', queueText, 'si si-check', queueState, staleStats ? s.connectionError || '等待数据' : '更新 ' + time(s.times.stats)),
                        this.metric('每分钟处理', staleStats ? '—' : number(stats.jobsPerMinute), 'fa fa-chart-line', null, '任务 / 分钟'),
                        this.metric('近一小时任务', staleStats ? '—' : number(stats.recentJobs), 'fa fa-history', null, 'Horizon 最近任务'),
                        this.metric('7日失败任务', staleStats ? '—' : number(stats.failedJobs), 'fa fa-exclamation-circle', stats.failedJobs > 0 ? 'error' : null, '失败记录'),
                        this.metric('待处理', staleQueues ? '—' : number(this.sum('pending')), 'fa fa-list', this.sum('pending') > 0 ? 'warning' : null, '等待领取'),
                        this.metric('执行中 / 已领取', staleQueues ? '—' : number(this.sum('reserved')), 'fa fa-cog', null, '队列保留任务'),
                        this.metric('工作进程', staleStats ? '—' : number(stats.processes), 'fa fa-server', null, '当前工作进程'),
                        this.metric('调度心跳', staleSignals ? '待确认' : scheduler.status === 'ok' ? '正常' : '异常', 'fa fa-heartbeat', staleSignals ? 'unknown' : scheduler.status,
                            heartbeatAge == null ? '尚未收到心跳' : heartbeatAge + ' 秒前')),
                    h('section', { className: 'runtime-section', 'data-section': 'modules' },
                        h('div', { className: 'runtime-section-heading' }, h('h3', null, '模块实时状态'),
                            h('span', { className: disconnected ? 'text-danger' : 'text-muted', role: 'status' }, disconnected ? '部分数据待确认' : '采样 ' + time(s.times.activity))),
                        h('div', { className: 'runtime-window text-muted' }, activity.window_start ? '请求窗口 ' + time(activity.window_start) + '–' + time(activity.sampled_at) : '\u00a0'),
                        table(['模块', '运行信号', 'HTTP 请求', '服务错误', '请求拒绝', '平均响应'], rows, 'runtime-module-table')),
                    h('section', { className: 'runtime-section', 'data-section': s.tab },
                        h('div', { className: 'runtime-tabs', role: 'tablist', 'aria-label': '监控明细' }, [['queues', '队列明细'], ['tasks', '定时任务'], ['config', '配置检查']].map(([id, name]) =>
                            h('button', { key: id, role: 'tab', type: 'button', 'aria-selected': s.tab === id, onClick: () => this.select(id) }, name))),
                        h('div', { className: 'runtime-data-warning', role: 'status' }, s.tab !== 'config' && (s.tab === 'queues' ? staleQueues : staleActivity) ? '数据未更新，以下保留上次读数' : '\u00a0'), detail));
            }
        };
        return h(Component);
    } };
})();
