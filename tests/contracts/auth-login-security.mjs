import { mkdtemp, readdir, rm, writeFile } from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import { guardedWordPressLogin } from '../e2e/helpers/auth-login.mjs';
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

await prepareAuthState(statePath);
await writePrivateStateAtomic(statePath, async (temporaryPath) => {
  await writeFile(temporaryPath, '{"synthetic":"stale"}\n');
});
await prepareAuthState(statePath);

let redirectedFillCount = 0;
let redirectedClickCount = 0;
const redirectedPage = {
  currentURL: `${expectedOrigin}/wp-login.php`,
  async goto() {
    this.currentURL = `${redirectOrigin}/wp-login.php`;
  },
  url() {
    return this.currentURL;
  },
  locator() {
    return {
      async getAttribute() {
        return `${expectedOrigin}/wp-login.php`;
      },
      async fill() {
        redirectedFillCount += 1;
      },
      async click() {
        redirectedClickCount += 1;
      },
    };
  },
  async waitForURL() {
    throw new Error('waitForURL must not run after a cross-origin redirect');
  },
};

let redirectedBrowserClosed = false;
const redirectingBrowserType = {
  async launch() {
    return {
      async newPage() {
        return redirectedPage;
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
assert(redirectedFillCount === 0, 'credentials were filled after a cross-origin redirect');
assert(redirectedClickCount === 0, 'login was submitted after a cross-origin redirect');
assert(redirectedBrowserClosed, 'browser was not closed after rejected login');
await assertStateAbsent();

let actionFillCount = 0;
let actionClickCount = 0;
const crossOriginActionPage = {
  currentURL: `${expectedOrigin}/wp-login.php`,
  async goto() {},
  url() {
    return this.currentURL;
  },
  locator(selector) {
    return {
      async getAttribute(name) {
        if (selector === '#loginform' && name === 'action') {
          return `${redirectOrigin}/wp-login.php`;
        }
        return null;
      },
      async fill() {
        actionFillCount += 1;
      },
      async click() {
        actionClickCount += 1;
      },
    };
  },
  async waitForURL() {
    throw new Error('waitForURL must not run for a cross-origin form action');
  },
};

try {
  await guardedWordPressLogin(crossOriginActionPage, {
    loginURL: `${expectedOrigin}/wp-login.php`,
    expectedOrigin,
    username: syntheticUsername,
    password: syntheticPassword,
  });
  throw new Error('cross-origin form action unexpectedly reached credential entry');
} catch (error) {
  assertSafeError(error);
}
assert(actionFillCount === 0, 'credentials were filled for a cross-origin form action');
assert(actionClickCount === 0, 'cross-origin login form was submitted');

let acceptedFillCount = 0;
let acceptedClickCount = 0;
let hostileAdminAccepted = true;
const acceptedPage = {
  currentURL: `${expectedOrigin}/wp-login.php`,
  async goto() {},
  url() {
    return this.currentURL;
  },
  locator(selector) {
    return {
      async getAttribute(name) {
        if (selector === '#loginform' && name === 'action') {
          return `${expectedOrigin}/wp-login.php`;
        }
        return null;
      },
      async fill() {
        acceptedFillCount += 1;
      },
      async click() {
        acceptedClickCount += 1;
      },
    };
  },
  async waitForURL(predicate) {
    hostileAdminAccepted = predicate(new URL(`${redirectOrigin}/wp-admin/`));
    assert(
      predicate(new URL(`${expectedOrigin}/wp-admin`)),
      'same-origin slashless admin path was rejected',
    );
    assert(
      predicate(new URL(`${expectedOrigin}/wp-admin/profile.php`)),
      'same-origin approved admin path was rejected',
    );
    this.currentURL = `${expectedOrigin}/wp-admin/profile.php`;
  },
};

await guardedWordPressLogin(acceptedPage, {
  loginURL: `${expectedOrigin}/wp-login.php`,
  expectedOrigin,
  username: syntheticUsername,
  password: syntheticPassword,
});
assert(!hostileAdminAccepted, 'cross-origin admin redirect passed the wait predicate');
assert(acceptedFillCount === 2, 'same-origin login did not fill both credentials');
assert(acceptedClickCount === 1, 'same-origin login was not submitted exactly once');

await rm(fixture, { recursive: true, force: true });
process.stdout.write('auth-login-security: PASS\n');
