(function () {
    'use strict';
    var enabled = Number(window.settings.admin_dashboard_privacy) === 1;
    var listeners = new Set();
    var Control;

    function subscribe(listener) {
        listeners.add(listener);
        return function () { listeners.delete(listener); };
    }

    function apply(value) {
        var next = Number(value) === 1;
        window.settings.admin_dashboard_privacy = next ? 1 : 0;
        if (next === enabled) return;
        enabled = next;
        listeners.forEach(function (listener) { listener(); });
    }

    window.AdminDashboardPrivacy = {
        enabled: function () { return enabled; },
        subscribe: subscribe,
        apply: apply,
        // The protected page never mounts while hidden, so it cannot fetch or flash totals.
        guard: function (React, Page, history) {
            return class DashboardPrivacyGuard extends React.Component {
                componentDidMount() {
                    this.unsubscribe = subscribe(() => this.forceUpdate());
                    this.redirect();
                }
                componentDidUpdate() { this.redirect(); }
                componentWillUnmount() { this.unsubscribe(); }
                redirect() { if (enabled) history.replace('/queue'); }
                render() { return enabled ? null : React.createElement(Page, this.props); }
            };
        },
        control: function (React, Switch, post) {
            if (Control) return Control;
            var h = React.createElement;
            Control = class DashboardPrivacyControl extends React.Component {
                constructor(props) {
                    super(props);
                    this.state = { saving: false, error: '', saved: false };
                    this.pending = false;
                    this.alive = false;
                }
                componentDidMount() {
                    this.alive = true;
                    this.unsubscribe = subscribe(() => this.forceUpdate());
                }
                componentWillUnmount() {
                    this.alive = false;
                    this.unsubscribe();
                }
                save(value) {
                    if (this.pending) return;
                    this.pending = true;
                    this.setState({ saving: true, error: '', saved: false });
                    var next = value ? 1 : 0;
                    return Promise.resolve().then(function () {
                        return post('/' + window.settings.secure_path + '/config/save', {
                            admin_dashboard_privacy: next
                        });
                    }).then(result => {
                        if (!result || result.code !== 200 || result.data !== true) {
                            throw new Error('\u4fdd\u5b58\u5931\u8d25\uff0c\u8bf7\u91cd\u8bd5');
                        }
                        apply(next);
                        if (this.alive) this.setState({ saved: true });
                    }).catch(error => {
                        if (this.alive) this.setState({ error: error.message || '\u4fdd\u5b58\u5931\u8d25' });
                    }).finally(() => {
                        this.pending = false;
                        if (this.alive) this.setState({ saving: false });
                    });
                }
                render() {
                    return h('div', { className: 'dashboard-privacy-control', style: {
                        display: 'flex', alignItems: 'center', justifyContent: 'flex-end', gap: '12px'
                    } },
                        h('span', { role: 'status', 'aria-live': 'polite', title: this.state.error, style: {
                            display: 'inline-block', minHeight: '22px', width: '180px', minWidth: 0,
                            overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap',
                            color: this.state.error ? '#b91c1c' : '#6b7280'
                        } }, this.state.error || (this.state.saving ? '\u4fdd\u5b58\u4e2d...' :
                            this.state.saved ? '\u5df2\u4fdd\u5b58' : '')),
                        h(Switch, {
                            checked: enabled, disabled: this.state.saving, loading: this.state.saving,
                            style: { flexShrink: 0 },
                            'aria-label': '\u4eea\u8868\u76d8\u9690\u79c1\u6a21\u5f0f',
                            onChange: value => this.save(value)
                        }));
                }
            };
            return Control;
        }
    };
}());
