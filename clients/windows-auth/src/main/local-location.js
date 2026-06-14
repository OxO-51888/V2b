const http = require('http');
const https = require('https');

const COUNTRY_NAMES = {
  CN: '中国',
  HK: '中国香港',
  MO: '中国澳门',
  TW: '中国台湾',
  SG: '新加坡',
  JP: '日本',
  KR: '韩国',
  US: '美国',
  DE: '德国',
  GB: '英国',
  FR: '法国',
  NL: '荷兰',
  CA: '加拿大',
  AU: '澳大利亚',
  RU: '俄罗斯'
};

const COUNTRY_ALIASES = {
  China: '中国',
  Germany: '德国',
  Singapore: '新加坡',
  Japan: '日本',
  Korea: '韩国',
  'South Korea': '韩国',
  'United States': '美国',
  USA: '美国',
  Canada: '加拿大',
  Australia: '澳大利亚',
  France: '法国',
  Netherlands: '荷兰',
  Russia: '俄罗斯',
  'United Kingdom': '英国',
  Britain: '英国'
};

const CHINA_REGION_SHORT_NAMES = {
  北京市: '北京',
  天津市: '天津',
  上海市: '上海',
  重庆市: '重庆',
  内蒙古自治区: '内蒙古',
  广西壮族自治区: '广西',
  西藏自治区: '西藏',
  宁夏回族自治区: '宁夏',
  新疆维吾尔自治区: '新疆',
  香港特别行政区: '香港',
  澳门特别行政区: '澳门',
  台湾省: '台湾'
};

function requestText(targetUrl, redirectCount = 0) {
  return new Promise((resolve, reject) => {
    const url = new URL(targetUrl);
    const client = url.protocol === 'https:' ? https : http;
    const request = client.request(url, {
      method: 'GET',
      // Use Node's direct socket path so this lookup ignores the app's system proxy setting.
      agent: false,
      timeout: 6000,
      headers: {
        Accept: 'application/json,text/plain,*/*',
        'User-Agent': 'XiaoV2BClient/0.1'
      }
    }, (response) => {
      if ([301, 302, 303, 307, 308].includes(response.statusCode) && response.headers.location && redirectCount < 3) {
        response.resume();
        resolve(requestText(new URL(response.headers.location, url).toString(), redirectCount + 1));
        return;
      }

      if (response.statusCode < 200 || response.statusCode >= 300) {
        response.resume();
        reject(new Error(`HTTP ${response.statusCode}`));
        return;
      }

      const chunks = [];
      let size = 0;
      response.on('data', (chunk) => {
        size += chunk.length;
        if (size > 262144) {
          request.destroy(new Error('定位响应过大'));
          return;
        }
        chunks.push(chunk);
      });
      response.on('end', () => resolve(Buffer.concat(chunks).toString('utf8')));
    });

    request.on('timeout', () => request.destroy(new Error('定位请求超时')));
    request.on('error', reject);
    request.end();
  });
}

async function requestJson(url) {
  const text = await requestText(url);
  return JSON.parse(text);
}

function clean(value) {
  return String(value || '').trim();
}

function countryName(value) {
  const normalized = clean(value);
  return COUNTRY_NAMES[normalized.toUpperCase()] || COUNTRY_ALIASES[normalized] || normalized;
}

function shortRegionName(region, resolvedCountry) {
  const normalized = clean(region);
  if (!normalized || !clean(resolvedCountry).startsWith('中国')) return normalized;
  if (CHINA_REGION_SHORT_NAMES[normalized]) return CHINA_REGION_SHORT_NAMES[normalized];
  return normalized
    .replace(/省$/, '')
    .replace(/市$/, '')
    .replace(/特别行政区$/, '')
    .replace(/自治区$/, '');
}

function splitChinaRegionCountry(resolvedCountry, region) {
  const regionFromCountry = {
    中国香港: '香港',
    中国澳门: '澳门',
    中国台湾: '台湾'
  }[resolvedCountry];

  if (!regionFromCountry) return { country: resolvedCountry, region };
  const resolvedRegion = region && region !== regionFromCountry ? region : regionFromCountry;
  return { country: '中国', region: resolvedRegion };
}

function isSameLocation(country, region) {
  if (!country || !region) return false;
  const countryRegion = country.replace(/^中国/, '');
  return country === region || country.endsWith(region) || Boolean(countryRegion && region.endsWith(countryRegion));
}

function buildLocation(country, city, region) {
  const namedCountry = countryName(country);
  const namedRegion = shortRegionName(clean(region) || clean(city), namedCountry);
  const { country: resolvedCountry, region: resolvedRegion } = splitChinaRegionCountry(namedCountry, namedRegion);
  if (resolvedCountry && resolvedRegion && !isSameLocation(resolvedCountry, resolvedRegion)) {
    return `${resolvedCountry} · ${resolvedRegion}`;
  }
  return resolvedCountry || resolvedRegion || '--';
}

function normalizeIpApi(data) {
  if (data?.status !== 'success') throw new Error('ip-api定位失败');
  return {
    ip: clean(data.query),
    country: countryName(data.country),
    city: clean(data.city),
    region: clean(data.regionName),
    provider: 'ip-api'
  };
}

function normalizeIpWho(data) {
  if (!data?.success) throw new Error('ipwho定位失败');
  return {
    ip: clean(data.ip),
    country: countryName(data.country || data.country_code),
    city: clean(data.city),
    region: clean(data.region),
    provider: 'ipwho'
  };
}

function normalizeIpIp(data) {
  if (data?.ret !== 'ok' || !data?.data) throw new Error('ipip定位失败');
  const location = Array.isArray(data.data.location) ? data.data.location : [];
  return {
    ip: clean(data.data.ip),
    country: countryName(location[0]),
    city: clean(location[2]),
    region: clean(location[1]),
    provider: 'ipip'
  };
}

function normalizeIpInfo(data) {
  return {
    ip: clean(data?.ip),
    country: countryName(data?.country),
    city: clean(data?.city),
    region: clean(data?.region),
    provider: 'ipinfo'
  };
}

function normalizeGeojs(data) {
  return {
    ip: clean(data?.ip),
    country: countryName(data?.country || data?.country_code),
    city: clean(data?.city),
    region: clean(data?.region),
    provider: 'geojs'
  };
}

function normalizeIfconfig(data) {
  return {
    ip: clean(data?.ip),
    country: countryName(data?.country || data?.country_iso),
    city: clean(data?.city),
    region: clean(data?.region_name),
    provider: 'ifconfig'
  };
}

function normalizeFreeIpApi(data) {
  return {
    ip: clean(data?.ipAddress),
    country: countryName(data?.countryName || data?.countryCode),
    city: clean(data?.cityName),
    region: clean(data?.regionName),
    provider: 'freeipapi'
  };
}

function normalizeIpOnly(data) {
  return {
    ip: clean(data?.ip),
    country: '',
    city: '',
    region: '',
    provider: 'ip-only'
  };
}

const providers = [
  { url: 'http://ip-api.com/json/?lang=zh-CN', normalize: normalizeIpApi },
  { url: 'https://ipwho.is/?lang=zh-CN', normalize: normalizeIpWho },
  { url: 'https://myip.ipip.net/json', normalize: normalizeIpIp },
  { url: 'https://get.geojs.io/v1/ip/geo.json', normalize: normalizeGeojs },
  { url: 'https://ifconfig.co/json', normalize: normalizeIfconfig },
  { url: 'https://freeipapi.com/api/json', normalize: normalizeFreeIpApi },
  { url: 'https://ipinfo.io/json', normalize: normalizeIpInfo },
  { url: 'https://api.ip.sb/jsonip', normalize: normalizeIpOnly }
];

async function getLocalLocation() {
  const errors = [];
  for (const provider of providers) {
    try {
      const normalized = provider.normalize(await requestJson(provider.url));
      const ip = clean(normalized.ip);
      if (!ip) throw new Error('未获取到IP地址');
      return {
        ...normalized,
        ip,
        locationText: buildLocation(normalized.country, normalized.city, normalized.region),
        updatedAt: Date.now()
      };
    } catch (error) {
      errors.push(`${provider.url}: ${error.message}`);
    }
  }

  throw new Error(`本地IP定位失败：${errors.join('；')}`);
}

module.exports = { getLocalLocation };
