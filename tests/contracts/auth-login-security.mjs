import { mkdtemp, readdir, rm, writeFile } from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import {
  guardedWordPressLogin,
  resolveAuthenticatedExpectedOrigin,
} from '../e2e/helpers/auth-login.mjs';
import { runAuthenticatedSetup } from '../e2e/helpers/auth-setup.mjs';
import {
  prepareAuthState,
  writePrivateStateAtomic,
} from '../e2e/helpers/auth-state.mjs';

const expectedOrigin = 'http://127.0.0.1:41801';
const redirectOrigin = 'http://127.0.0.1:41802';
const syntheticUsername = 'synthetic-origin-user';
const syntheticPassword = 'synthetic-origin-password';
const fixture = await mkdtemp(path.join(os.tmpdir(), 'goetz-auth-origin.'));
const statePath = path.join(fixture, 'auth-state.json');

function assert(condition, message) {
  if (!condition) throw new Error(message);
}

function assertSafeError(error) {
  const message = String(error);
  for (const forbidden of [
    syntheticUsername,
    syntheticPassword,
    expectedOrigin,
    redirectOrigin,
    'http://',
  ]) {
    assert(!message.includes(forbidden), 'origin guard error disclosed sensitive context');
  }
}

async function assertStateAbsent() {
  const entries = await readdir(fixture);
  assert(!entries.includes('auth-state.json'), 'final auth state survived rejected login');
  assert(
    !entries.some((entry) => entry.startsWith('auth-state.json.tmp.')),
    'temporary auth state survived rejected login',
  );
}

assert(
  resolveAuthenticatedExpectedOrigin(
    'HTTPS://EXAMPLE.INVALID:443/subpath',
    'https://example.invalid',
  ) === 'https://example.invalid',
  'authenticated origin comparison did not normalize a default port',
);
for (const [baseURL, declaredOrigin] of [
  [`${expectedOrigin}/subpath`, redirectOrigin],
  ['http://synthetic-user@127.0.0.1:41801', expectedOrigin],
  [expectedOrigin, 'http://127.0.0.1:99999'],
]) {
  try {
    resolveAuthenticatedExpectedOrigin(baseURL, declaredOrigin);
    throw new Error('unsafe authenticated origin pair was accepted');
  } catch (error) {
    assertSafeError(error);
  }
}

function loginPage(options = {}) {
  const counts = { fills: 0, clicks: 0, storageWrites: 0 };
  const page = {
    currentURL: options.initialURL || `${expectedOrigin}/wp-login.php`,
    async goto() {
      if (options.gotoError) throw options.gotoError;
      if (options.gotoURL) this.currentURL = options.gotoURL;
    },
    url() {
      return this.currentURL;
    },
    locator(selector) {
      if (selector === '#loginform') return formLocator;
      return controlLocator(selector);
    },
    async waitForURL(predicate) {
      if (options.waitForURL) return options.waitForURL(predicate, this);
      throw new Error('waitForURL was not expected in this scenario');
    },
    context() {
      return {
        async storageState({ path: outputPath }) {
          counts.storageWrites += 1;
          await writeFile(outputPath, '{"synthetic":"state"}\n');
        },
      };
    },
  };

  function controlLocator(selector) {
    return {
      async fill() {
        counts.fills += 1;
        if (options.fillError) throw options.fillError;
      },
      async click() {
        counts.clicks += 1;
        if (options.onClick) await options.onClick(page, selector);
      },
    };
  }

  const formLocator = {
    async getAttribute(name) {
      if (name !== 'action') return null;
      return options.rawAction ?? `${expectedOrigin}/wp-login.php`;
    },
    async evaluate(callback) {
      const formAction = options.resolvedFormAction ??
        options.rawAction ?? `${expectedOrigin}/wp-login.php`;
      const hasSubmitter = options.hasSubmitter ?? true;
      const hasSubmitterOverride = Object.hasOwn(options, 'resolvedSubmitterAction');
      const submitter = hasSubmitter ? {
        formAction: options.resolvedSubmitterAction ?? formAction,
        hasAttribute(name) {
          return name === 'formaction' && hasSubmitterOverride;
        },
      } : null;
      return callback({
        action: formAction,
        querySelector(selector) {
          return selector === '#wp-submit' ? submitter : null;
        },
      });
    },
    locator(selector) {
      return controlLocator(selector);
    },
  };

  return { page, counts };
}

await prepareAuthState(statePath);
await writePrivateStateAtomic(statePath, async (temporaryPath) => {
  await writeFile(temporaryPath, '{"synthetic":"stale"}\n');
});

const redirected = loginPage({
  gotoURL: `${redirectOrigin}/wp-login.php`,
  rawAction: `${expectedOrigin}/wp-login.php`,
  resolvedFormAction: `${expectedOrigin}/wp-login.php`,
});
let redirectedBrowserClosed = false;
const redirectingBrowserType = {
  async launch() {
    return {
      async newPage() {
        return redirected.page;
      },
      async close() {
        redirectedBrowserClosed = true;
      },
    };
  },
};

try {
  await runAuthenticatedSetup({
    browserType: redirectingBrowserType,
    storageState: statePath,
    login: {
      loginURL: `${expectedOrigin}/wp-login.php`,
      expectedOrigin,
      username: syntheticUsername,
      password: syntheticPassword,
    },
  });
  throw new Error('cross-origin redirect unexpectedly reached credential entry');
} catch (error) {
  assertSafeError(error);
}
assert(redirected.counts.fills === 0, 'credentials were filled after a cross-origin redirect');
assert(redirected.counts.clicks === 0, 'login was submitted after a cross-origin redirect');
assert(redirectedBrowserClosed, 'browser was not closed after rejected login');
await assertStateAbsent();

const crossOriginAction = loginPage({
  rawAction: `${redirectOrigin}/wp-login.php`,
  resolvedFormAction: `${redirectOrigin}/wp-login.php`,
});
try {
  await guardedWordPressLogin(crossOriginAction.page, {
    loginURL: `${expectedOrigin}/wp-login.php`,
    expectedOrigin,
    username: syntheticUsername,
    password: syntheticPassword,
  });
  throw new Error('cross-origin form action unexpectedly reached credential entry');
} catch (error) {
  assertSafeError(error);
}
assert(crossOriginAction.counts.fills === 0, 'credentials were filled for a cross-origin form action');
assert(crossOriginAction.counts.clicks === 0, 'cross-origin login form was submitted');

const hostileBase = loginPage({
  rawAction: 'wp-login.php',
  resolvedFormAction: `${redirectOrigin}/wp-login.php`,
});
try {
  await guardedWordPressLogin(hostileBase.page, {
    loginURL: `${expectedOrigin}/wp-login.php`,
    expectedOrigin,
    username: syntheticUsername,
    password: syntheticPassword,
  });
  throw new Error('hostile resolved form action unexpectedly reached credential entry');
} catch (error) {
  assertSafeError(error);
}
assert(hostileBase.counts.fills === 0, 'credentials were filled through a hostile base URL');
assert(hostileBase.counts.clicks === 0, 'login was submitted through a hostile base URL');

const hostileSubmitter = loginPage({
  rawAction: `${expectedOrigin}/wp-login.php`,
  resolvedFormAction: `${expectedOrigin}/wp-login.php`,
  resolvedSubmitterAction: `${redirectOrigin}/collect`,
});
try {
  await guardedWordPressLogin(hostileSubmitter.page, {
    loginURL: `${expectedOrigin}/wp-login.php`,
    expectedOrigin,
    username: syntheticUsername,
    password: syntheticPassword,
  });
  throw new Error('hostile submitter action unexpectedly reached credential entry');
} catch (error) {
  assertSafeError(error);
}
assert(hostileSubmitter.counts.fills === 0, 'credentials were filled for a hostile submitter action');
assert(hostileSubmitter.counts.clicks === 0, 'hostile submitter action was clicked');

await prepareAuthState(statePath);
await writePrivateStateAtomic(statePath, async (temporaryPath) => {
  await writeFile(temporaryPath, '{"synthetic":"stale-again"}\n');
});
const rejectedAdmin = loginPage({
  waitForURL(predicate) {
    assert(
      !predicate(new URL(`${redirectOrigin}/wp-admin/`)),
      'cross-origin admin redirect passed the wait predicate',
    );
    throw new Error(
      `synthetic waiter exposed ${redirectOrigin}/wp-admin and ${syntheticPassword}`,
    );
  },
});
let rejectedAdminBrowserClosed = false;
try {
  await runAuthenticatedSetup({
    browserType: {
      async launch() {
        return {
          async newPage() {
            return rejectedAdmin.page;
          },
          async close() {
            rejectedAdminBrowserClosed = true;
          },
        };
      },
    },
    storageState: statePath,
    login: {
      loginURL: `${expectedOrigin}/wp-login.php`,
      expectedOrigin,
      username: syntheticUsername,
      password: syntheticPassword,
    },
  });
  throw new Error('cross-origin admin redirect unexpectedly completed setup');
} catch (error) {
  assertSafeError(error);
}
assert(rejectedAdmin.counts.fills === 2, 'trusted login did not fill both credentials');
assert(rejectedAdmin.counts.clicks === 1, 'trusted login was not submitted once');
assert(rejectedAdmin.counts.storageWrites === 0, 'rejected admin redirect wrote auth state');
assert(rejectedAdminBrowserClosed, 'browser was not closed after rejected admin redirect');
await assertStateAbsent();

await prepareAuthState(statePath);
await writePrivateStateAtomic(statePath, async (temporaryPath) => {
  await writeFile(temporaryPath, '{"synthetic":"navigation-stale"}\n');
});
const rejectedNavigation = loginPage({
  gotoError: new Error(
    `synthetic navigation exposed ${redirectOrigin}/wp-login and ${syntheticPassword}`,
  ),
});
let rejectedNavigationBrowserClosed = false;
try {
  await runAuthenticatedSetup({
    browserType: {
      async launch() {
        return {
          async newPage() {
            return rejectedNavigation.page;
          },
          async close() {
            rejectedNavigationBrowserClosed = true;
          },
        };
      },
    },
    storageState: statePath,
    login: {
      loginURL: `${expectedOrigin}/wp-login.php`,
      expectedOrigin,
      username: syntheticUsername,
      password: syntheticPassword,
    },
  });
  throw new Error('failed navigation unexpectedly completed setup');
} catch (error) {
  assertSafeError(error);
}
assert(rejectedNavigation.counts.fills === 0, 'failed navigation filled credentials');
assert(rejectedNavigation.counts.clicks === 0, 'failed navigation submitted login');
assert(rejectedNavigation.counts.storageWrites === 0, 'failed navigation wrote auth state');
assert(rejectedNavigationBrowserClosed, 'browser was not closed after failed navigation');
await assertStateAbsent();

let hostileAdminAccepted = true;
const accepted = loginPage({
  waitForURL(predicate, page) {
    hostileAdminAccepted = predicate(new URL(`${redirectOrigin}/wp-admin/`));
    assert(
      predicate(new URL(`${expectedOrigin}/wp-admin`)),
      'same-origin slashless admin path was rejected',
    );
    assert(
      predicate(new URL(`${expectedOrigin}/wp-admin/profile.php`)),
      'same-origin approved admin path was rejected',
    );
    page.currentURL = `${expectedOrigin}/wp-admin/profile.php`;
  },
});
await guardedWordPressLogin(accepted.page, {
  loginURL: `${expectedOrigin}/wp-login.php`,
  expectedOrigin,
  username: syntheticUsername,
  password: syntheticPassword,
});
assert(!hostileAdminAccepted, 'cross-origin admin redirect passed the wait predicate');
assert(accepted.counts.fills === 2, 'same-origin login did not fill both credentials');
assert(accepted.counts.clicks === 1, 'same-origin login was not submitted exactly once');

await rm(fixture, { recursive: true, force: true });
process.stdout.write('auth-login-security: PASS\n');
