import path from 'node:path';
import { cleanupAuthState } from './helpers/auth-state.mjs';

export default async function globalTeardown(): Promise<void> {
  const storageState = process.env.GOETZ_AUTH_STATE_PATH ||
    path.resolve('../../__dev/playwright/auth-state/auth-state.json');
  await cleanupAuthState(storageState);
}
