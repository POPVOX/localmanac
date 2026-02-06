import { chromium } from 'playwright';

const url = process.argv[2];

if (!url) {
  console.error('Missing URL');
  process.exit(1);
}

const timeout = Number.parseInt(process.env.PLAYWRIGHT_TIMEOUT ?? '30000', 10);
const waitSelector = process.env.PLAYWRIGHT_WAIT_SELECTOR ?? '';
const userAgent = process.env.PLAYWRIGHT_USER_AGENT ?? 'LocalmanacBot/1.0';

const run = async () => {
  const browser = await chromium.launch({ headless: true });
  const context = await browser.newContext({ userAgent });
  const page = await context.newPage();

  page.setDefaultNavigationTimeout(timeout);
  page.setDefaultTimeout(timeout);

  await page.goto(url, { waitUntil: 'domcontentloaded', timeout });

  if (waitSelector) {
    await page.waitForSelector(waitSelector, { timeout });
  }

  try {
    await page.waitForLoadState('networkidle', { timeout: Math.min(timeout, 10000) });
  } catch {
    // Ignore network idle timeouts.
  }

  const html = await page.content();
  const finalUrl = page.url();

  await browser.close();

  process.stdout.write(JSON.stringify({ url: finalUrl, html }));
};

run().catch((error) => {
  console.error(error instanceof Error ? error.message : String(error));
  process.exit(1);
});
