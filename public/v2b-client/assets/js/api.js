export class V2BoardApi {
  constructor(baseUrl = '/api/v1', storageKey = 'xiaov2b.auth') {
    this.baseUrl = baseUrl.replace(/\/$/, '');
    this.storageKey = storageKey;
    this.auth = localStorage.getItem(storageKey) || '';
  }

  setAuth(auth) {
    this.auth = auth || '';
    if (this.auth) {
      localStorage.setItem(this.storageKey, this.auth);
    } else {
      localStorage.removeItem(this.storageKey);
    }
  }

  async request(path, options = {}) {
    const headers = new Headers(options.headers || {});
    if (this.auth) headers.set('Authorization', this.auth);
    if (options.body && !(options.body instanceof FormData)) {
      headers.set('Content-Type', 'application/json');
    }

    const response = await fetch(`${this.baseUrl}${path}`, {
      ...options,
      headers,
      body: options.body && !(options.body instanceof FormData)
        ? JSON.stringify(options.body)
        : options.body
    });

    const text = await response.text();
    const payload = parsePayload(text);

    if (!response.ok) {
      const message = payload?.message || payload?.error || text || `请求失败：${response.status}`;
      throw new Error(message);
    }

    return payload;
  }

  get(path, params = {}) {
    const query = new URLSearchParams();
    Object.entries(params).forEach(([key, value]) => {
      if (value !== undefined && value !== null && value !== '') query.set(key, value);
    });
    return this.request(`${path}${query.toString() ? `?${query}` : ''}`);
  }

  post(path, body = {}) {
    return this.request(path, { method: 'POST', body });
  }

  login(email, password) {
    return this.post('/passport/auth/login', { email, password });
  }

  register(data) {
    return this.post('/passport/auth/register', data);
  }

  tokenLogin(verify) {
    return this.get('/passport/auth/token2Login', { verify });
  }

  userInfo() {
    return this.get('/user/info');
  }

  subscribeInfo() {
    return this.get('/user/getSubscribe');
  }

  userStat() {
    return this.get('/user/getStat');
  }

  userConfig() {
    return this.get('/user/comm/config');
  }

  plans() {
    return this.get('/user/plan/fetch');
  }

  orders(status = '') {
    return this.get('/user/order/fetch', { status });
  }

  orderDetail(tradeNo) {
    return this.get('/user/order/detail', { trade_no: tradeNo });
  }

  paymentMethods() {
    return this.get('/user/order/getPaymentMethod');
  }

  createOrder(planId, period, couponCode = '') {
    return this.post('/user/order/save', {
      plan_id: Number(planId),
      period,
      coupon_code: couponCode || undefined
    });
  }

  checkout(tradeNo, method, token = '') {
    return this.post('/user/order/checkout', {
      trade_no: tradeNo,
      method: Number(method),
      token
    });
  }

  cancelOrder(tradeNo) {
    return this.post('/user/order/cancel', { trade_no: tradeNo });
  }

  servers() {
    return this.get('/user/server/fetch');
  }

  updateUser(data) {
    return this.post('/user/update', data);
  }

  resetSecurity() {
    return this.get('/user/resetSecurity');
  }

  redeemGiftcard(giftcard) {
    return this.post('/user/redeemgiftcard', { giftcard });
  }
}

function parsePayload(text) {
  try {
    return text ? JSON.parse(text) : null;
  } catch {
    return text;
  }
}
