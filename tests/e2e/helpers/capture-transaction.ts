import { createHash } from 'node:crypto';
import {
  lstat,
  readFile,
  readdir,
  rename,
  rm,
  stat,
  unlink,
} from 'node:fs/promises';
import path from 'node:path';
import type { Page } from '@playwright/test';
import { PNG } from 'pngjs';

export const LEGACY_STAGING_PREFIX = '.legacy-staging-';
export const LEGACY_VIEWPORT_KEYS = ['1440x900', '390x844', '989x844', '990x844'] as const;
export const LEGACY_PNG_NAMES = LEGACY_VIEWPORT_KEYS.map((key) => `home-${key}.png`);
export const LEGACY_FIXTURE_NAMES = [...LEGACY_PNG_NAMES, 'geometry.json'] as const;

type ScreenshotPage = Pick<Page, 'screenshot' | 'url'>;

interface FileRecord {
  sha256: string;
  bytes: number;
  pixel_width: number;
  pixel_height: number;
}

interface GeometryManifest {
  schema_version: number;
  reference_url: string;
  reference_origin: string;
  captured_at_utc: string;
  browser_name: string;
  browser_version: string;
  device_scale_factor: number;
  read_only_contract: Record<string, unknown>;
  dynamic_masks: unknown[];
  viewport_order: unknown[];
  viewports: Record<string, Record<string, unknown>>;
  components: Record<string, unknown>;
  files: Record<string, Partial<FileRecord>>;
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}

async function exists(targetPath: string): Promise<boolean> {
  try {
    await lstat(targetPath);
    return true;
  } catch (error) {
    if ((error as NodeJS.ErrnoException).code === 'ENOENT') {
      return false;
    }
    throw error;
  }
}

async function sha256(filePath: string): Promise<string> {
  return createHash('sha256').update(await readFile(filePath)).digest('hex');
}

function assertExactReferenceLocation(page: ScreenshotPage, target: URL): void {
  let finalURL: URL;
  try {
    finalURL = new URL(page.url());
  } catch {
    throw new Error('Reference homepage returned an invalid final URL.');
  }
  if (finalURL.origin !== target.origin || finalURL.href !== target.href) {
    throw new Error('Reference homepage final origin or URL did not match the approved target.');
  }
}

export async function captureApprovedScreenshot(
  page: ScreenshotPage,
  target: URL,
  screenshotPath: string,
): Promise<Buffer> {
  const screenshot = await page.screenshot({
    path: screenshotPath,
    fullPage: true,
    animations: 'disabled',
    caret: 'hide',
    scale: 'css',
  });
  assertExactReferenceLocation(page, target);
  return screenshot;
}

function parseManifest(bytes: Buffer): GeometryManifest {
  let value: unknown;
  try {
    value = JSON.parse(bytes.toString('utf8'));
  } catch {
    throw new Error('Staged capture manifest is not valid JSON.');
  }
  if (
    !isRecord(value)
    || value.schema_version !== 1
    || typeof value.reference_url !== 'string'
    || typeof value.reference_origin !== 'string'
    || typeof value.captured_at_utc !== 'string'
    || typeof value.browser_name !== 'string'
    || value.browser_name === ''
    || typeof value.browser_version !== 'string'
    || value.browser_version === ''
    || value.device_scale_factor !== 1
    || !isRecord(value.read_only_contract)
    || !Array.isArray(value.dynamic_masks)
    || !Array.isArray(value.viewport_order)
    || !isRecord(value.viewports)
    || !isRecord(value.components)
    || !isRecord(value.files)
  ) {
    throw new Error('Staged capture manifest does not satisfy schema version 1.');
  }
  let referenceURL: URL;
  try {
    referenceURL = new URL(value.reference_url);
  } catch {
    throw new Error('Staged capture manifest reference URL is invalid.');
  }
  if (
    referenceURL.protocol !== 'https:'
    || referenceURL.username !== ''
    || referenceURL.password !== ''
    || referenceURL.pathname !== '/'
    || referenceURL.search !== ''
    || referenceURL.hash !== ''
    || referenceURL.origin !== value.reference_origin
  ) {
    throw new Error('Staged capture manifest reference URL or origin is invalid.');
  }
  if (!/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{3})?Z$/.test(value.captured_at_utc)) {
    throw new Error('Staged capture manifest UTC timestamp is invalid.');
  }
  return value as unknown as GeometryManifest;
}

function assertExactNames(actual: readonly string[], expected: readonly string[], label: string): void {
  const actualSorted = [...actual].sort();
  const expectedSorted = [...expected].sort();
  if (JSON.stringify(actualSorted) !== JSON.stringify(expectedSorted)) {
    throw new Error(`${label} must contain the exact five-file fixture set.`);
  }
}

function assertFileRecord(record: unknown, fileName: string): asserts record is FileRecord {
  if (
    !isRecord(record)
    || typeof record.sha256 !== 'string'
    || !/^[a-f0-9]{64}$/.test(record.sha256)
    || !Number.isSafeInteger(record.bytes)
    || (record.bytes as number) <= 0
    || !Number.isSafeInteger(record.pixel_width)
    || (record.pixel_width as number) <= 0
    || !Number.isSafeInteger(record.pixel_height)
    || (record.pixel_height as number) <= 0
  ) {
    throw new Error(`Staged capture manifest has invalid file metadata: ${fileName}`);
  }
}

export async function validateStagedFixtureSet(
  stagingDir: string,
  expectedTarget?: URL,
): Promise<void> {
  const directoryStat = await lstat(stagingDir);
  if (!directoryStat.isDirectory() || directoryStat.isSymbolicLink()) {
    throw new Error('Staged capture transaction must be a real directory.');
  }

  const entries = await readdir(stagingDir, { withFileTypes: true });
  assertExactNames(entries.map(({ name }) => name), LEGACY_FIXTURE_NAMES, 'Staged capture transaction');
  for (const entry of entries) {
    if (!entry.isFile() || entry.isSymbolicLink()) {
      throw new Error(`Staged capture entry must be a regular file: ${entry.name}`);
    }
    if ((await stat(path.join(stagingDir, entry.name))).size <= 0) {
      throw new Error(`Staged capture entry must be non-empty: ${entry.name}`);
    }
  }

  const manifest = parseManifest(await readFile(path.join(stagingDir, 'geometry.json')));
  if (
    expectedTarget
    && (
      manifest.reference_url !== expectedTarget.href
      || manifest.reference_origin !== expectedTarget.origin
    )
  ) {
    throw new Error('Staged capture reference does not match the current approved target.');
  }
  assertExactNames(
    manifest.viewport_order.map((value) => String(value)),
    LEGACY_VIEWPORT_KEYS,
    'Staged capture viewport order',
  );
  assertExactNames(Object.keys(manifest.viewports), LEGACY_VIEWPORT_KEYS, 'Staged capture viewports');
  assertExactNames(Object.keys(manifest.components), LEGACY_VIEWPORT_KEYS, 'Staged capture components');
  assertExactNames(Object.keys(manifest.files), LEGACY_PNG_NAMES, 'Staged capture manifest files');

  if (
    JSON.stringify(manifest.read_only_contract.allowed_methods) !== JSON.stringify(['GET', 'HEAD'])
    || !Array.isArray(manifest.read_only_contract.blocked_requests)
    || manifest.read_only_contract.service_workers !== 'blocked'
    || manifest.read_only_contract.downloads !== 'blocked'
    || manifest.read_only_contract.popups !== 'blocked'
    || manifest.read_only_contract.fixture_overwrite !== 'refused'
  ) {
    throw new Error('Staged capture manifest read-only contract is invalid.');
  }

  for (const [index, viewportKey] of LEGACY_VIEWPORT_KEYS.entries()) {
    const fileName = LEGACY_PNG_NAMES[index];
    const [expectedWidth, expectedHeight] = viewportKey.split('x').map(Number);
    const fileRecord = manifest.files[fileName];
    assertFileRecord(fileRecord, fileName);
    const filePath = path.join(stagingDir, fileName);
    const actualStat = await stat(filePath);
    if (actualStat.size !== fileRecord.bytes) {
      throw new Error(`Staged capture byte-count mismatch: ${fileName}`);
    }
    if (await sha256(filePath) !== fileRecord.sha256) {
      throw new Error(`Staged capture hash mismatch: ${fileName}`);
    }

    let png: PNG;
    try {
      png = PNG.sync.read(await readFile(filePath));
    } catch {
      throw new Error(`Staged capture PNG is invalid: ${fileName}`);
    }
    if (
      png.width !== fileRecord.pixel_width
      || png.height !== fileRecord.pixel_height
      || png.width !== expectedWidth
      || png.height < expectedHeight
    ) {
      throw new Error(`Staged capture PNG dimension mismatch: ${fileName}`);
    }

    const viewportRecord = manifest.viewports[viewportKey];
    if (
      !isRecord(viewportRecord)
      || !isRecord(viewportRecord.viewport)
      || viewportRecord.viewport.width !== expectedWidth
      || viewportRecord.viewport.height !== expectedHeight
      || viewportRecord.viewport.device_scale_factor !== 1
      || typeof viewportRecord.http_status !== 'number'
      || viewportRecord.http_status < 200
      || viewportRecord.http_status >= 300
      || viewportRecord.final_url !== manifest.reference_url
      || !isRecord(viewportRecord.document)
      || viewportRecord.document.scroll_width !== expectedWidth
      || typeof viewportRecord.document.scroll_height !== 'number'
      || viewportRecord.document.scroll_height < expectedHeight
      || !isRecord(viewportRecord.settlement)
      || !Array.isArray(viewportRecord.settlement.scrollPositions)
      || viewportRecord.settlement.scrollPositions.length < 2
      || viewportRecord.settlement.scrollPositions.some((position) => (
        typeof position !== 'number' || !Number.isFinite(position) || position < 0
      ))
      || typeof viewportRecord.settlement.finalScrollY !== 'number'
      || viewportRecord.settlement.finalScrollY > 1
      || viewportRecord.images_complete !== true
      || viewportRecord.returned_to_top !== true
      || !isRecord(viewportRecord.png)
    ) {
      throw new Error(`Staged capture viewport schema is invalid: ${viewportKey}`);
    }
    const viewportPNG = viewportRecord.png;
    assertFileRecord(viewportPNG, `${viewportKey}.png`);
    if (
      viewportPNG.file !== fileName
      || viewportPNG.sha256 !== fileRecord.sha256
      || viewportPNG.bytes !== fileRecord.bytes
      || viewportPNG.pixel_width !== fileRecord.pixel_width
      || viewportPNG.pixel_height !== fileRecord.pixel_height
    ) {
      throw new Error(`Staged capture viewport metadata mismatch: ${viewportKey}`);
    }
  }
}

function assertSiblingTransaction(stagingDir: string, finalDir: string): void {
  if (
    path.dirname(stagingDir) !== path.dirname(finalDir)
    || !path.basename(stagingDir).startsWith(LEGACY_STAGING_PREFIX)
    || path.basename(finalDir) !== 'legacy'
  ) {
    throw new Error('Capture publication requires a sibling legacy staging transaction.');
  }
}

export async function publishCaptureTransaction(
  stagingDir: string,
  finalDir: string,
  expectedTarget: URL,
): Promise<void> {
  if (!(expectedTarget instanceof URL)) {
    throw new Error('Capture publication requires the current approved target.');
  }
  assertSiblingTransaction(stagingDir, finalDir);
  await validateStagedFixtureSet(stagingDir, expectedTarget);
  if (await exists(finalDir)) {
    throw new Error('Immutable capture target already exists: legacy');
  }
  try {
    await rename(stagingDir, finalDir);
  } catch (error) {
    if (['EEXIST', 'ENOTEMPTY'].includes((error as NodeJS.ErrnoException).code || '')) {
      throw new Error('Immutable capture target already exists: legacy');
    }
    throw error;
  }
  await validateStagedFixtureSet(finalDir, expectedTarget);
}

async function discardInvalidStage(stagingPath: string): Promise<void> {
  const stagingStat = await lstat(stagingPath);
  if (stagingStat.isDirectory() && !stagingStat.isSymbolicLink()) {
    await rm(stagingPath, { recursive: true, force: true });
    return;
  }
  await unlink(stagingPath);
}

export async function recoverCaptureTransaction(
  parentDir: string,
  finalDir: string,
  expectedTarget: URL,
): Promise<'none' | 'recovered'> {
  if (!(expectedTarget instanceof URL)) {
    throw new Error('Capture recovery requires the current approved target.');
  }
  if (path.dirname(finalDir) !== parentDir || path.basename(finalDir) !== 'legacy') {
    throw new Error('Capture recovery requires the fixed sibling legacy directory.');
  }
  if (await exists(finalDir)) {
    throw new Error('Immutable capture target already exists: legacy');
  }

  const candidates = (await readdir(parentDir, { withFileTypes: true }))
    .filter(({ name }) => name.startsWith(LEGACY_STAGING_PREFIX))
    .map(({ name }) => path.join(parentDir, name))
    .sort();
  const valid: string[] = [];
  for (const candidate of candidates) {
    try {
      await validateStagedFixtureSet(candidate, expectedTarget);
      valid.push(candidate);
    } catch {
      await discardInvalidStage(candidate);
    }
  }

  if (valid.length > 1) {
    throw new Error('Multiple validated capture transactions require manual review.');
  }
  if (valid.length === 0) {
    return 'none';
  }

  await publishCaptureTransaction(valid[0], finalDir, expectedTarget);
  return 'recovered';
}
