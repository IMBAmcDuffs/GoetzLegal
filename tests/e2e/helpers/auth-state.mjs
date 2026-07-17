import { chmod, mkdir, open, readdir, rename, rm } from 'node:fs/promises';
import path from 'node:path';
import { randomUUID } from 'node:crypto';

async function removeTemporaryStateFiles(statePath) {
  const directory = path.dirname(statePath);
  const prefix = `${path.basename(statePath)}.tmp.`;
  let entries = [];

  try {
    entries = await readdir(directory);
  } catch (error) {
    if (error.code === 'ENOENT') return;
    throw error;
  }

  await Promise.all(
    entries
      .filter((entry) => entry.startsWith(prefix))
      .map((entry) => rm(path.join(directory, entry), { force: true })),
  );
}

async function restrictStateDirectory(statePath) {
  const directory = path.dirname(statePath);
  await mkdir(directory, { recursive: true, mode: 0o700 });
  await chmod(directory, 0o700);
}

export async function cleanupAuthState(statePath) {
  await rm(statePath, { force: true });
  await removeTemporaryStateFiles(statePath);
}

export async function prepareAuthState(statePath) {
  await restrictStateDirectory(statePath);
  await cleanupAuthState(statePath);
}

export async function writePrivateStateAtomic(statePath, writer) {
  await restrictStateDirectory(statePath);
  await removeTemporaryStateFiles(statePath);

  const temporaryPath = `${statePath}.tmp.${process.pid}.${randomUUID()}`;
  try {
    const temporaryFile = await open(temporaryPath, 'wx', 0o600);
    await temporaryFile.close();
    await chmod(temporaryPath, 0o600);
    await writer(temporaryPath);
    await chmod(temporaryPath, 0o600);
    await rename(temporaryPath, statePath);
    await chmod(statePath, 0o600);
  } catch (error) {
    await rm(temporaryPath, { force: true });
    throw error;
  }
}
