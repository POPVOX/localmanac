import { chromium } from 'playwright';

const tokenSourceUrl = process.argv[2] ?? '';
const timeout = Number.parseInt(process.env.VISIT_WICHITA_TOKEN_RESOLVER_TIMEOUT ?? '30000', 10);
const endpointPath = process.env.VISIT_WICHITA_TOKEN_ENDPOINT ?? '/includes/rest_v2/plugins_events_events_by_date/find/';
const userAgent = process.env.PLAYWRIGHT_USER_AGENT ?? 'LocalmanacBot/1.0';

const fail = (message) => {
  process.stderr.write(JSON.stringify({ error: message }));
  process.exit(1);
};

const extractTokenFromUrl = (value) => {
  try {
    const parsed = new URL(value);
    const token = parsed.searchParams.get('token');

    return typeof token === 'string' && token.trim() !== '' ? token.trim() : null;
  } catch {
    return null;
  }
};

if (tokenSourceUrl.trim() === '') {
  fail('Missing Visit Wichita token source URL.');
}

const run = async () => {
  let browser;

  try {
    browser = await chromium.launch({ headless: true });
    const context = await browser.newContext({ userAgent });
    const page = await context.newPage();

    page.setDefaultNavigationTimeout(timeout);
    page.setDefaultTimeout(timeout);

    let requestUrl = null;
    let token = null;

    page.on('request', (request) => {
      const url = request.url();

      if (!url.includes(endpointPath)) {
        return;
      }

      const foundToken = extractTokenFromUrl(url);

      if (foundToken !== null) {
        requestUrl = url;
        token = foundToken;
      }
    });

    await page.goto(tokenSourceUrl, { waitUntil: 'domcontentloaded', timeout });

    const start = Date.now();

    while (token === null && Date.now() - start < timeout) {
      await page.waitForTimeout(200);
    }

    if (token === null || requestUrl === null) {
      fail('No token-bearing Visit Wichita request was observed before timeout.');
    }

    process.stdout.write(JSON.stringify({ token, request_url: requestUrl }));
    await browser.close();
  } catch (error) {
    if (browser) {
      await browser.close().catch(() => {});
    }

    const message = error instanceof Error ? error.message : String(error);
    fail(message);
  }
};

run();
