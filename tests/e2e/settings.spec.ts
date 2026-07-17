import { expect, test, type Page } from '@playwright/test';
import { randomUUID } from 'node:crypto';
import { isLoopbackURL } from './helpers/browser.mjs';

const settingsPath = '/wp-admin/options-general.php?page=goetz-site-settings';

interface SettingsSnapshot {
  values: Record<string, string>;
  checked: Record<string, boolean>;
}

interface TemporarySubscriber {
  username: string;
  password: string;
}

async function readSettingsSnapshot(page: Page): Promise<SettingsSnapshot> {
  return page.locator('form[data-goetz-site-settings-form]').evaluate((form) => {
    const values: Record<string, string> = {};
    const checked: Record<string, boolean> = {};
    form.querySelectorAll<HTMLInputElement | HTMLTextAreaElement>('[data-goetz-setting-key]').forEach((field) => {
      const key = field.dataset.goetzSettingKey;
      if (!key) {
        return;
      }
      values[key] = field.value;
      if (field instanceof HTMLInputElement && field.type === 'checkbox') {
        checked[key] = field.checked;
      }
    });
    return { values, checked };
  });
}

async function writeSettingsSnapshot(page: Page, snapshot: SettingsSnapshot): Promise<void> {
  await page.goto(settingsPath, { waitUntil: 'domcontentloaded' });
  const form = page.locator('form[data-goetz-site-settings-form]');
  await expect(form).toBeVisible();

  for (const [key, value] of Object.entries(snapshot.values)) {
    const field = form.locator(`[data-goetz-setting-key="${key}"]`);
    const type = await field.getAttribute('type');
    if (type === 'checkbox') {
      await field.setChecked(snapshot.checked[key] ?? false);
    } else if (type === 'hidden') {
      await field.evaluate((element, nextValue) => {
        (element as HTMLInputElement).value = nextValue;
      }, value);
    } else {
      await field.fill(value);
    }
  }

  await page.getByRole('button', { name: 'Save Changes' }).click();
  await expect(page.getByText('Settings saved.')).toBeVisible();
}

async function createTemporarySubscriber(page: Page): Promise<TemporarySubscriber> {
  const suffix = randomUUID();
  const subscriber = {
    username: `goetz-settings-${suffix}`,
    password: `Goetz-${suffix}-A9!`,
  };

  await page.goto('/wp-admin/user-new.php', { waitUntil: 'domcontentloaded' });
  await page.getByLabel(/Username.*required/i).fill(subscriber.username);
  await page.getByLabel(/Email.*required/i).fill(`${subscriber.username}@example.test`);
  const showPassword = page.getByRole('button', { name: /(?:generate|show) password/i });
  if (await showPassword.isVisible()) {
    await showPassword.click();
  }
  const passwordField = page.locator('#pass1');
  await expect(passwordField).toBeVisible();
  await passwordField.fill(subscriber.password);
  const notification = page.getByLabel('Send the new user an email about their account');
  if (await notification.isChecked()) {
    await notification.uncheck();
  }
  await page.getByLabel('Role').selectOption('subscriber');
  await Promise.all([
    page.waitForURL((url) => url.searchParams.get('update') === 'add'),
    page.locator('form#createuser').evaluate((form) => {
      (form as HTMLFormElement).requestSubmit();
    }),
  ]);
  await expect(page.getByText('New user created.')).toBeVisible();

  return subscriber;
}

async function deleteTemporarySubscriber(page: Page, username: string): Promise<void> {
  await page.goto(`/wp-admin/users.php?s=${encodeURIComponent(username)}`, {
    waitUntil: 'domcontentloaded',
  });
  const row = page.getByRole('row').filter({ hasText: username });
  if (await row.count() === 0) {
    return;
  }
  await row.getByRole('link', { name: 'Delete' }).click();
  await page.getByRole('button', { name: /confirm deletion/i }).click();
  await expect(page.getByText(/user deleted/i)).toBeVisible();
}

async function loginTemporarySubscriber(
  page: Page,
  baseURL: string,
  subscriber: TemporarySubscriber,
): Promise<void> {
  const expectedOrigin = new URL(baseURL).origin;
  await page.goto(`${expectedOrigin}/wp-login.php`, { waitUntil: 'domcontentloaded' });

  const loginForm = page.locator('#loginform');
  await expect(loginForm).toBeVisible();
  const action = await loginForm.getAttribute('action');
  expect(new URL(action ?? '', page.url()).origin).toBe(expectedOrigin);

  await Promise.all([
    page.waitForURL((url) => (
      url.origin === expectedOrigin &&
      (url.pathname === '/wp-admin' || url.pathname.startsWith('/wp-admin/'))
    )),
    loginForm.evaluate((form, credentials) => {
      const username = form.querySelector<HTMLInputElement>('#user_login');
      const password = form.querySelector<HTMLInputElement>('#user_pass');
      const submitter = form.querySelector<HTMLInputElement>('#wp-submit');

      if (!username || !password || !submitter) {
        throw new Error('WordPress login form is incomplete.');
      }

      username.value = credentials.username;
      password.value = credentials.password;
      username.dispatchEvent(new Event('input', { bubbles: true }));
      password.dispatchEvent(new Event('input', { bubbles: true }));
      (form as HTMLFormElement).requestSubmit(submitter);
    }, subscriber),
  ]);
}

test.describe('Site Settings', () => {
  test('administrator saves sanitized values, subscriber gets 403, and the exact original values are restored', async ({
    browser,
    page,
  }, testInfo) => {
    test.setTimeout(90_000);
    const configuredBaseURL = testInfo.project.use.baseURL;
    test.skip(
      typeof configuredBaseURL !== 'string' || !isLoopbackURL(configuredBaseURL),
      'Site Settings mutation coverage is local-only, including when remote authentication is opted in.',
    );

    let original: SettingsSnapshot | undefined;
    let subscriber: TemporarySubscriber | undefined;

    try {
      const response = await page.goto(settingsPath, { waitUntil: 'domcontentloaded' });
      expect(response?.status()).toBe(200);
      await expect(page.getByRole('heading', { name: 'Site Settings' })).toBeVisible();
      await expect(page.locator('input[name="_wpnonce"]')).toHaveCount(1);

      original = await readSettingsSnapshot(page);
      expect(Object.keys(original.values)).toHaveLength(19);

      const temporaryEmail = `settings-${randomUUID()}@example.test`;
      await page.getByLabel('Phone display').fill(' <b>(239) 555-0177</b> ');
      await page.getByLabel('Email').fill(temporaryEmail);
      await page.getByRole('button', { name: 'Save Changes' }).click();
      await expect(page.getByText('Settings saved.')).toBeVisible();
      await expect(page.getByLabel('Phone display')).toHaveValue('(239) 555-0177');
      await expect(page.getByLabel('Email')).toHaveValue(temporaryEmail);

      await page.goto('/', { waitUntil: 'domcontentloaded' });
      await expect(page.getByRole('banner').getByText('(239) 555-0177')).toBeVisible();
      await expect(page.getByRole('contentinfo').getByText('(239) 555-0177')).toBeVisible();
      await expect(page.getByRole('banner').getByRole('link', { name: temporaryEmail })).toHaveAttribute(
        'href',
        `mailto:${temporaryEmail}`,
      );

      await page.goto('/contact/', { waitUntil: 'domcontentloaded' });
      await expect(page.locator('.goetz-contact-info-list').getByRole('link', { name: '(239) 555-0177' })).toHaveAttribute(
        'href',
        'tel:+12399362841',
      );
      await expect(page.getByRole('link', { name: 'Driving Directions' })).toHaveAttribute(
        'href',
        'https://www.google.com/maps/search/?api=1&query=33%20Barkley%20Cir%20Ste%20100%2C%20Fort%20Myers%2C%20FL%2033907',
      );

      subscriber = await createTemporarySubscriber(page);
      const subscriberContext = await browser.newContext({ baseURL: configuredBaseURL });
      try {
        const subscriberPage = await subscriberContext.newPage();
        await loginTemporarySubscriber(subscriberPage, configuredBaseURL, subscriber);

        const denied = await subscriberPage.goto(settingsPath, { waitUntil: 'domcontentloaded' });
        expect(denied?.status()).toBe(403);
        await expect(subscriberPage.getByText(/not allowed to access this page/i)).toBeVisible();
        await expect(subscriberPage.getByRole('heading', { name: 'Site Settings' })).toHaveCount(0);
      } finally {
        await subscriberContext.close();
      }
    } finally {
      if (subscriber) {
        await deleteTemporarySubscriber(page, subscriber.username);
      }
      if (original) {
        await writeSettingsSnapshot(page, original);
        const restored = await readSettingsSnapshot(page);
        expect(restored).toEqual(original);
      }
    }
  });
});
