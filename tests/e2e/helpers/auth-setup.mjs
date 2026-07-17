import { guardedWordPressLogin } from './auth-login.mjs';
import {
  cleanupAuthState,
  prepareAuthState,
  writePrivateStateAtomic,
} from './auth-state.mjs';

export async function runAuthenticatedSetup(options) {
  await prepareAuthState(options.storageState);

  let browser;
  let failure;
  try {
    browser = await options.browserType.launch(options.launchOptions);
    const page = await browser.newPage();
    await guardedWordPressLogin(page, options.login);
    await writePrivateStateAtomic(options.storageState, async (temporaryPath) => {
      await page.context().storageState({ path: temporaryPath });
    });
  } catch (error) {
    failure = error;
  }

  if (browser) {
    try {
      await browser.close();
    } catch (error) {
      failure ??= error;
    }
  }

  if (failure !== undefined) {
    await cleanupAuthState(options.storageState);
    throw failure;
  }
}
