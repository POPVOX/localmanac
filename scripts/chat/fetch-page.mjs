import { chromium } from 'playwright';
import { mkdir, readFile } from 'node:fs/promises';
import path from 'node:path';

const url = process.argv[2];

if (!url) {
  console.error('Missing URL');
  process.exit(1);
}

const timeout = Number.parseInt(process.env.PLAYWRIGHT_TIMEOUT ?? '30000', 10);
const waitSelector = process.env.PLAYWRIGHT_WAIT_SELECTOR ?? '';
const userAgent = process.env.PLAYWRIGHT_USER_AGENT ?? 'LocalmanacBot/1.0';
const storageStatePath = process.env.PLAYWRIGHT_STORAGE_STATE_PATH ?? '';
const proxyServer = process.env.PLAYWRIGHT_PROXY_SERVER ?? '';
const proxyUsername = process.env.PLAYWRIGHT_PROXY_USERNAME ?? '';
const proxyPassword = process.env.PLAYWRIGHT_PROXY_PASSWORD ?? '';
const proxyBypass = process.env.PLAYWRIGHT_PROXY_BYPASS ?? '';
const refreshOnBlocked = ['1', 'true', 'yes', 'on'].includes(
  (process.env.PLAYWRIGHT_REFRESH_ON_BLOCKED ?? '1').toLowerCase()
);
const refreshAttempts = Math.max(
  0,
  Number.parseInt(process.env.PLAYWRIGHT_REFRESH_ATTEMPTS ?? '1', 10) || 0
);
const autoScroll = ['1', 'true', 'yes', 'on'].includes(
  (process.env.PLAYWRIGHT_AUTO_SCROLL ?? '0').toLowerCase()
);
const maxScrollSteps = Math.max(
  0,
  Number.parseInt(process.env.PLAYWRIGHT_MAX_SCROLL_STEPS ?? '8', 10) || 0
);
const scrollPauseMs = Math.max(
  100,
  Number.parseInt(process.env.PLAYWRIGHT_SCROLL_PAUSE_MS ?? '500', 10) || 500
);

const challengeMarkers = [
  'px-captcha',
  'access to this page has been denied',
  'before we continue',
  'cf-chl-',
  'checking your browser',
  'javascript required',
  'verify you are human',
];

const isChallengePage = (html) => {
  const lower = (html ?? '').toLowerCase();
  return challengeMarkers.some((marker) => lower.includes(marker));
};

const resolveProxy = () => {
  if (!proxyServer) {
    return undefined;
  }

  return {
    server: proxyServer,
    ...(proxyUsername ? { username: proxyUsername } : {}),
    ...(proxyPassword ? { password: proxyPassword } : {}),
    ...(proxyBypass ? { bypass: proxyBypass } : {}),
  };
};

const resolveStorageState = async () => {
  if (!storageStatePath) {
    return undefined;
  }

  try {
    const contents = await readFile(storageStatePath, 'utf8');
    const parsed = JSON.parse(contents);

    if (parsed && typeof parsed === 'object') {
      return parsed;
    }
  } catch {
    // Ignore missing/invalid storage state and continue without it.
  }

  return undefined;
};

const settlePage = async (page) => {
  if (waitSelector) {
    await page.waitForSelector(waitSelector, { timeout });
  }

  try {
    await page.waitForLoadState('networkidle', { timeout: Math.min(timeout, 10000) });
  } catch {
    // Ignore network idle timeouts.
  }
};

const scrollForLazyLoad = async (page) => {
  if (!autoScroll || maxScrollSteps <= 0) {
    return;
  }

  let previousHeight = await page.evaluate(() => {
    const bodyHeight = document.body?.scrollHeight ?? 0;
    const docHeight = document.documentElement?.scrollHeight ?? 0;

    return Math.max(bodyHeight, docHeight);
  });
  let stableIterations = 0;

  for (let step = 0; step < maxScrollSteps; step += 1) {
    await page.evaluate(() => {
      window.scrollTo(0, document.body?.scrollHeight ?? document.documentElement?.scrollHeight ?? 0);
    });
    await page.waitForTimeout(scrollPauseMs);

    try {
      await page.waitForLoadState('networkidle', { timeout: Math.min(timeout, 2500) });
    } catch {
      // Ignore idle timeouts while scrolling.
    }

    const currentHeight = await page.evaluate(() => {
      const bodyHeight = document.body?.scrollHeight ?? 0;
      const docHeight = document.documentElement?.scrollHeight ?? 0;

      return Math.max(bodyHeight, docHeight);
    });

    if (currentHeight <= previousHeight + 5) {
      stableIterations += 1;
    } else {
      stableIterations = 0;
      previousHeight = currentHeight;
    }

    if (stableIterations >= 2) {
      break;
    }
  }
};

const navigateAndExtract = async (context, targetUrl, { warmup = false, reloadOnChallenge = false } = {}) => {
  const page = await context.newPage();

  if (warmup) {
    try {
      const origin = new URL(targetUrl).origin;
      await page.goto(origin, { waitUntil: 'domcontentloaded', timeout });
      await settlePage(page);
    } catch {
      // Ignore warmup errors and continue to target URL.
    }
  }

  await page.goto(targetUrl, { waitUntil: 'domcontentloaded', timeout });
  await settlePage(page);
  await scrollForLazyLoad(page);

  let html = await page.content();
  let finalUrl = page.url();

  if (reloadOnChallenge && isChallengePage(html)) {
    try {
      await page.waitForTimeout(2000);
      await page.reload({ waitUntil: 'domcontentloaded', timeout });
      await settlePage(page);
      html = await page.content();
      finalUrl = page.url();
    } catch {
      // Ignore reload errors; keep original response content.
    }
  }

  return { html, finalUrl };
};

const run = async () => {
  const proxy = resolveProxy();
  const browser = await chromium.launch({
    headless: true,
    ...(proxy ? { proxy } : {}),
  });
  const storageState = await resolveStorageState();
  let context = await browser.newContext({
    userAgent,
    ...(storageState ? { storageState } : {}),
  });
  let { html, finalUrl } = await navigateAndExtract(context, url);

  if (isChallengePage(html) && refreshOnBlocked && refreshAttempts > 0) {
    for (let attempt = 1; attempt <= refreshAttempts; attempt += 1) {
      await context.close();

      context = await browser.newContext({ userAgent });
      const refreshed = await navigateAndExtract(context, url, {
        warmup: true,
        reloadOnChallenge: true,
      });

      html = refreshed.html;
      finalUrl = refreshed.finalUrl;

      if (!isChallengePage(html)) {
        break;
      }
    }
  }

  if (storageStatePath) {
    await mkdir(path.dirname(storageStatePath), { recursive: true });
    await context.storageState({ path: storageStatePath });
  }

  await context.close();
  await browser.close();

  process.stdout.write(JSON.stringify({ url: finalUrl, html }));
};

run().catch((error) => {
  console.error(error instanceof Error ? error.message : String(error));
  process.exit(1);
});
