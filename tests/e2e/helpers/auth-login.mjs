const genericOriginError = 'Authenticated login origin validation failed.';
const genericAdminError = 'Authenticated login did not reach an approved admin location.';

function parseURL(value, base) {
  try {
    return new URL(value, base);
  } catch {
    throw new Error(genericOriginError);
  }
}

function canonicalOrigin(value) {
  const parsed = parseURL(value);
  if (!['http:', 'https:'].includes(parsed.protocol)) {
    throw new Error(genericOriginError);
  }
  return parsed.origin;
}

function assertExactOrigin(value, expectedOrigin, base) {
  if (parseURL(value, base).origin !== expectedOrigin) {
    throw new Error(genericOriginError);
  }
}

function isApprovedAdminURL(value, expectedOrigin) {
  try {
    const parsed = value instanceof URL ? value : new URL(value);
    return parsed.origin === expectedOrigin &&
      (parsed.pathname === '/wp-admin' || parsed.pathname.startsWith('/wp-admin/'));
  } catch {
    return false;
  }
}

export async function guardedWordPressLogin(page, options) {
  const expectedOrigin = canonicalOrigin(options.expectedOrigin);

  await page.goto(options.loginURL, { waitUntil: 'domcontentloaded' });

  const currentURL = page.url();
  assertExactOrigin(currentURL, expectedOrigin);

  const loginForm = page.locator('#loginform');
  const formAction = await loginForm.getAttribute('action');
  if (!formAction) {
    throw new Error(genericOriginError);
  }
  assertExactOrigin(formAction, expectedOrigin, currentURL);

  await page.locator('#user_login').fill(options.username);
  await page.locator('#user_pass').fill(options.password);
  await Promise.all([
    page.waitForURL(
      (candidate) => isApprovedAdminURL(candidate, expectedOrigin),
      { timeout: options.timeout ?? 30_000 },
    ),
    page.locator('#wp-submit').click(),
  ]);

  if (!isApprovedAdminURL(page.url(), expectedOrigin)) {
    throw new Error(genericAdminError);
  }
}
