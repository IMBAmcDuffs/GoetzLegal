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
  if (!['http:', 'https:'].includes(parsed.protocol) || parsed.username || parsed.password) {
    throw new Error(genericOriginError);
  }
  return parsed.origin;
}

export function resolveAuthenticatedExpectedOrigin(baseURL, declaredExpectedOrigin) {
  const baseOrigin = canonicalOrigin(baseURL);
  if (declaredExpectedOrigin !== undefined &&
    canonicalOrigin(declaredExpectedOrigin) !== baseOrigin) {
    throw new Error(genericOriginError);
  }
  return baseOrigin;
}

function assertExactOrigin(value, expectedOrigin, base) {
  const parsed = parseURL(value, base);
  if (parsed.origin !== expectedOrigin || parsed.username || parsed.password) {
    throw new Error(genericOriginError);
  }
}

function isApprovedAdminURL(value, expectedOrigin) {
  try {
    const parsed = value instanceof URL ? value : new URL(value);
    return !parsed.username && !parsed.password && parsed.origin === expectedOrigin &&
      (parsed.pathname === '/wp-admin' || parsed.pathname.startsWith('/wp-admin/'));
  } catch {
    return false;
  }
}

async function resolvedSubmissionTargets(loginForm) {
  return loginForm.evaluate((form) => {
    const submitter = form.querySelector('#wp-submit');
    const formAction = form.action;
    return {
      formAction,
      submitterAction: submitter?.hasAttribute('formaction')
        ? submitter.formAction
        : formAction,
      hasSubmitter: submitter !== null,
    };
  });
}

async function assertTrustedLoginSurface(page, loginForm, expectedOrigin) {
  const currentURL = page.url();
  assertExactOrigin(currentURL, expectedOrigin);

  const targets = await resolvedSubmissionTargets(loginForm);
  if (!targets?.hasSubmitter || !targets.formAction || !targets.submitterAction) {
    throw new Error(genericOriginError);
  }
  assertExactOrigin(targets.formAction, expectedOrigin, currentURL);
  assertExactOrigin(targets.submitterAction, expectedOrigin, currentURL);
}

async function withGenericFailure(operation, message) {
  try {
    return await operation();
  } catch {
    throw new Error(message);
  }
}

export async function guardedWordPressLogin(page, options) {
  const expectedOrigin = canonicalOrigin(options.expectedOrigin);
  const loginForm = page.locator('#loginform');
  const usernameInput = loginForm.locator('#user_login');
  const passwordInput = loginForm.locator('#user_pass');
  const submitButton = loginForm.locator('#wp-submit');

  await withGenericFailure(async () => {
    await page.goto(options.loginURL, { waitUntil: 'domcontentloaded' });
    await assertTrustedLoginSurface(page, loginForm, expectedOrigin);
  }, genericOriginError);

  await withGenericFailure(async () => {
    await usernameInput.fill(options.username);
    await passwordInput.fill(options.password);
    await assertTrustedLoginSurface(page, loginForm, expectedOrigin);
  }, genericOriginError);

  await withGenericFailure(() => Promise.all([
    page.waitForURL(
      (candidate) => isApprovedAdminURL(candidate, expectedOrigin),
      { timeout: options.timeout ?? 30_000 },
    ),
    submitButton.click(),
  ]), genericAdminError);

  if (!isApprovedAdminURL(page.url(), expectedOrigin)) {
    throw new Error(genericAdminError);
  }
}
