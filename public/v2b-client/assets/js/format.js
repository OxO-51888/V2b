export const periodLabels = {
  month_price: '月付',
  quarter_price: '季付',
  half_year_price: '半年付',
  year_price: '年付',
  two_year_price: '两年付',
  three_year_price: '三年付',
  onetime_price: '一次性',
  reset_price: '重置流量'
};

export const orderStatus = {
  0: '待支付',
  1: '开通中',
  2: '已取消',
  3: '已完成',
  4: '已折抵'
};

export const platformLabels = {
  all: '全部',
  windows: 'Windows',
  macos: 'macOS',
  android: 'Android',
  ios: 'iOS'
};

export function money(value, symbol = '¥') {
  const amount = Number(value || 0) / 100;
  return `${symbol || '¥'}${amount.toFixed(2)}`;
}

export function bytes(value) {
  const size = Number(value || 0);
  if (size <= 0) return '0 B';
  const units = ['B', 'KB', 'MB', 'GB', 'TB'];
  const index = Math.min(Math.floor(Math.log(size) / Math.log(1024)), units.length - 1);
  return `${(size / Math.pow(1024, index)).toFixed(index < 3 ? 0 : 2)} ${units[index]}`;
}

export function dateText(timestamp) {
  if (!timestamp) return '长期有效';
  return new Date(Number(timestamp) * 1000).toLocaleDateString('zh-CN');
}

export function escapeHtml(value) {
  return String(value ?? '').replace(/[&<>"']/g, (char) => ({
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    "'": '&#039;'
  }[char]));
}

export function subscribeWithFlag(subscribeUrl, flag) {
  if (!subscribeUrl) return '';
  const url = new URL(subscribeUrl, location.href);
  if (flag) url.searchParams.set('flag', flag);
  return url.toString();
}
