import { createHash } from 'node:crypto';
import {
  lstatSync,
  readFileSync,
  readdirSync,
  writeFileSync,
} from 'node:fs';
import path from 'node:path';
import pixelmatch from 'pixelmatch';
import { PNG } from 'pngjs';

export const MIN_COMPONENT_SSIM = 0.98;
export const MAX_CHANGED_PIXEL_RATIO = 0.03;

/**
 * Pixelmatch's normalized per-pixel color-distance threshold. The value is
 * intentionally documented and fixed so routine browser antialiasing noise is
 * ignored without hiding content, layout, or color changes.
 */
export const ANTIALIAS_PIXEL_THRESHOLD = 0.15;

export interface VisualMask {
  name: string;
  x: number;
  y: number;
  width: number;
  height: number;
}

export interface FixtureVisualMask extends VisualMask {
  viewport: string;
  component: string;
}

export interface VisualComparison {
  width: number;
  height: number;
  comparedPixels: number;
  changedPixels: number;
  changedPixelRatio: number;
  ssim: number;
  passed: boolean;
}

export interface ComparePngOptions {
  masks?: readonly VisualMask[];
  diffPath?: string;
}

export interface PngRect {
  x: number;
  y: number;
  width: number;
  height: number;
}

interface FixtureFileRecord {
  sha256: string;
  bytes: number;
  pixel_width: number;
  pixel_height: number;
}

export interface FrozenGeometryFixture {
  schema_version: number;
  viewport_order: string[];
  dynamic_masks: FixtureVisualMask[];
  viewports: Record<string, unknown>;
  components: Record<string, Record<string, unknown[]>>;
  files: Record<string, FixtureFileRecord>;
}

const EXPECTED_VIEWPORTS = ['1440x900', '390x844', '989x844', '990x844'] as const;
const EXPECTED_PNG_FILES = EXPECTED_VIEWPORTS.map((viewport) => `home-${viewport}.png`);

function sha256(bytes: Buffer): string {
  return createHash('sha256').update(bytes).digest('hex');
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}

function exactNames(actual: readonly string[], expected: readonly string[]): boolean {
  return JSON.stringify([...actual].sort()) === JSON.stringify([...expected].sort());
}

function parseGeometry(bytes: Buffer): FrozenGeometryFixture {
  let parsed: unknown;
  try {
    parsed = JSON.parse(bytes.toString('utf8'));
  } catch {
    throw new Error('Frozen legacy geometry.json is not valid JSON.');
  }

  if (
    !isRecord(parsed)
    || parsed.schema_version !== 1
    || !Array.isArray(parsed.viewport_order)
    || !Array.isArray(parsed.dynamic_masks)
    || !isRecord(parsed.viewports)
    || !isRecord(parsed.components)
    || !isRecord(parsed.files)
  ) {
    throw new Error('Frozen legacy geometry.json does not satisfy schema version 1.');
  }

  return parsed as unknown as FrozenGeometryFixture;
}

function assertRegularFile(filePath: string): void {
  const metadata = lstatSync(filePath);
  if (!metadata.isFile() || metadata.isSymbolicLink()) {
    throw new Error(`Frozen legacy fixture entry must be a regular file: ${path.basename(filePath)}`);
  }
}

export function validateFrozenFixtureSet(fixtureDirectory: string): FrozenGeometryFixture {
  const directoryMetadata = lstatSync(fixtureDirectory);
  if (!directoryMetadata.isDirectory() || directoryMetadata.isSymbolicLink()) {
    throw new Error('Frozen legacy fixture must be a real directory.');
  }

  const expectedNames = ['geometry.json', ...EXPECTED_PNG_FILES];
  const actualNames = readdirSync(fixtureDirectory);
  if (!exactNames(actualNames, expectedNames)) {
    throw new Error('Frozen legacy fixture must contain the exact geometry manifest and four PNGs.');
  }

  const geometryPath = path.join(fixtureDirectory, 'geometry.json');
  assertRegularFile(geometryPath);
  const geometry = parseGeometry(readFileSync(geometryPath));
  if (
    !exactNames(geometry.viewport_order.map(String), EXPECTED_VIEWPORTS)
    || !exactNames(Object.keys(geometry.viewports), EXPECTED_VIEWPORTS)
    || !exactNames(Object.keys(geometry.components), EXPECTED_VIEWPORTS)
    || !exactNames(Object.keys(geometry.files), EXPECTED_PNG_FILES)
  ) {
    throw new Error('Frozen legacy geometry.json has an incomplete viewport or file set.');
  }

  for (const viewport of EXPECTED_VIEWPORTS) {
    if (!isRecord(geometry.components[viewport]) || Object.keys(geometry.components[viewport]).length === 0) {
      throw new Error(`Frozen legacy component geometry is empty: ${viewport}`);
    }
  }

  const maskNames = new Set<string>();
  for (const candidate of geometry.dynamic_masks) {
    if (
      !isRecord(candidate)
      || !exactNames(Object.keys(candidate), [
        'name',
        'viewport',
        'component',
        'x',
        'y',
        'width',
        'height',
      ])
      || typeof candidate.name !== 'string'
      || candidate.name.trim() === ''
      || maskNames.has(candidate.name)
      || typeof candidate.viewport !== 'string'
      || !EXPECTED_VIEWPORTS.includes(candidate.viewport as typeof EXPECTED_VIEWPORTS[number])
      || typeof candidate.component !== 'string'
      || !Number.isFinite(candidate.x)
      || !Number.isFinite(candidate.y)
      || !Number.isFinite(candidate.width)
      || !Number.isFinite(candidate.height)
      || (candidate.width as number) <= 0
      || (candidate.height as number) <= 0
    ) {
      throw new Error('Frozen legacy dynamic masks must be uniquely named component rectangles.');
    }
    maskNames.add(candidate.name);

    const components = geometry.components[candidate.viewport]?.[candidate.component];
    if (!Array.isArray(components) || components.length === 0) {
      throw new Error(`Frozen legacy dynamic mask names an unknown component: ${candidate.name}`);
    }
    const mask = candidate as unknown as FixtureVisualMask;
    const containingComponents = components.filter((component) => {
      if (!isRecord(component) || !isRecord(component.rect)) return false;
      const rect = component.rect;
      return [rect.x, rect.y, rect.width, rect.height].every(Number.isFinite)
        && mask.x >= (rect.x as number)
        && mask.y >= (rect.y as number)
        && mask.x + mask.width <= (rect.x as number) + (rect.width as number)
        && mask.y + mask.height <= (rect.y as number) + (rect.height as number);
    });
    if (containingComponents.length !== 1) {
      throw new Error(`Frozen legacy dynamic mask must fit one named component: ${candidate.name}`);
    }
    const containingRect = (containingComponents[0] as { rect: PngRect }).rect;
    if (
      mask.x <= containingRect.x
      && mask.y <= containingRect.y
      && mask.x + mask.width >= containingRect.x + containingRect.width
      && mask.y + mask.height >= containingRect.y + containingRect.height
    ) {
      throw new Error('Frozen legacy dynamic masks cannot cover whole component sections.');
    }
  }

  for (const [index, fileName] of EXPECTED_PNG_FILES.entries()) {
    const filePath = path.join(fixtureDirectory, fileName);
    assertRegularFile(filePath);
    const bytes = readFileSync(filePath);
    const record = geometry.files[fileName];
    if (
      !isRecord(record)
      || typeof record.sha256 !== 'string'
      || !/^[a-f0-9]{64}$/.test(record.sha256)
      || !Number.isSafeInteger(record.bytes)
      || record.bytes <= 0
      || !Number.isSafeInteger(record.pixel_width)
      || record.pixel_width <= 0
      || !Number.isSafeInteger(record.pixel_height)
      || record.pixel_height <= 0
    ) {
      throw new Error(`Frozen legacy file metadata is invalid: ${fileName}`);
    }
    if (sha256(bytes) !== record.sha256) {
      throw new Error(`Frozen legacy hash mismatch: ${fileName}`);
    }
    if (bytes.length !== record.bytes) {
      throw new Error(`Frozen legacy byte-count mismatch: ${fileName}`);
    }

    let png: PNG;
    try {
      png = PNG.sync.read(bytes);
    } catch {
      throw new Error(`Frozen legacy PNG is invalid: ${fileName}`);
    }
    const expectedWidth = Number(EXPECTED_VIEWPORTS[index].split('x')[0]);
    if (
      png.width !== expectedWidth
      || png.width !== record.pixel_width
      || png.height !== record.pixel_height
    ) {
      throw new Error(`Frozen legacy PNG geometry mismatch: ${fileName}`);
    }
  }

  return geometry;
}

function decodePng(bytes: Buffer, label: string): PNG {
  try {
    return PNG.sync.read(bytes);
  } catch {
    throw new Error(`${label} is not a valid PNG.`);
  }
}

export function extractAndResizePng(
  sourceBytes: Buffer,
  rect: PngRect,
  targetWidth: number,
  targetHeight: number,
): Buffer {
  const source = decodePng(sourceBytes, 'Source image');
  if (
    !Number.isFinite(rect.x)
    || !Number.isFinite(rect.y)
    || !Number.isFinite(rect.width)
    || !Number.isFinite(rect.height)
    || rect.x < 0
    || rect.y < 0
    || rect.width <= 0
    || rect.height <= 0
    || rect.x + rect.width > source.width + 1
    || rect.y + rect.height > source.height + 1
    || !Number.isSafeInteger(targetWidth)
    || !Number.isSafeInteger(targetHeight)
    || targetWidth <= 0
    || targetHeight <= 0
  ) {
    throw new Error('PNG extraction requires a finite in-bounds rectangle and positive integer target size.');
  }

  const output = new PNG({ width: targetWidth, height: targetHeight });
  for (let targetY = 0; targetY < targetHeight; targetY += 1) {
    const sourceY = Math.max(0, Math.min(
      source.height - 1,
      Math.floor(rect.y + (((targetY + 0.5) * rect.height) / targetHeight)),
    ));
    for (let targetX = 0; targetX < targetWidth; targetX += 1) {
      const sourceX = Math.max(0, Math.min(
        source.width - 1,
        Math.floor(rect.x + (((targetX + 0.5) * rect.width) / targetWidth)),
      ));
      const sourceOffset = ((sourceY * source.width) + sourceX) * 4;
      const targetOffset = ((targetY * targetWidth) + targetX) * 4;
      output.data[targetOffset] = source.data[sourceOffset];
      output.data[targetOffset + 1] = source.data[sourceOffset + 1];
      output.data[targetOffset + 2] = source.data[sourceOffset + 2];
      output.data[targetOffset + 3] = source.data[sourceOffset + 3];
    }
  }

  return PNG.sync.write(output);
}

function validateMasks(masks: readonly VisualMask[], width: number, height: number): void {
  const names = new Set<string>();
  for (const mask of masks) {
    if (
      typeof mask.name !== 'string'
      || mask.name.trim() === ''
      || names.has(mask.name)
      || !Number.isFinite(mask.x)
      || !Number.isFinite(mask.y)
      || !Number.isFinite(mask.width)
      || !Number.isFinite(mask.height)
      || mask.width <= 0
      || mask.height <= 0
      || mask.x < 0
      || mask.y < 0
      || mask.x + mask.width > width
      || mask.y + mask.height > height
    ) {
      throw new Error('Visual masks must be uniquely named, finite rectangles inside the compared image.');
    }
    names.add(mask.name);
  }
}

function maskedOffsets(
  masks: readonly VisualMask[],
  width: number,
  height: number,
): Set<number> {
  validateMasks(masks, width, height);
  const offsets = new Set<number>();
  for (const mask of masks) {
    const left = Math.floor(mask.x);
    const top = Math.floor(mask.y);
    const right = Math.ceil(mask.x + mask.width);
    const bottom = Math.ceil(mask.y + mask.height);
    for (let y = top; y < bottom; y += 1) {
      for (let x = left; x < right; x += 1) {
        offsets.add((y * width) + x);
      }
    }
  }
  return offsets;
}

function compositeLuminance(data: Buffer, pixelIndex: number): number {
  const offset = pixelIndex * 4;
  const alpha = data[offset + 3] / 255;
  const red = (data[offset] * alpha) + (255 * (1 - alpha));
  const green = (data[offset + 1] * alpha) + (255 * (1 - alpha));
  const blue = (data[offset + 2] * alpha) + (255 * (1 - alpha));
  return (0.2126 * red) + (0.7152 * green) + (0.0722 * blue);
}

function structuralSimilarity(
  reference: Buffer,
  candidate: Buffer,
  totalPixels: number,
  masked: ReadonlySet<number>,
): number {
  let count = 0;
  let referenceMean = 0;
  let candidateMean = 0;
  for (let index = 0; index < totalPixels; index += 1) {
    if (masked.has(index)) continue;
    referenceMean += compositeLuminance(reference, index);
    candidateMean += compositeLuminance(candidate, index);
    count += 1;
  }
  if (count === 0) {
    throw new Error('Visual masks cannot cover the entire compared image.');
  }
  referenceMean /= count;
  candidateMean /= count;

  let referenceVariance = 0;
  let candidateVariance = 0;
  let covariance = 0;
  for (let index = 0; index < totalPixels; index += 1) {
    if (masked.has(index)) continue;
    const referenceDelta = compositeLuminance(reference, index) - referenceMean;
    const candidateDelta = compositeLuminance(candidate, index) - candidateMean;
    referenceVariance += referenceDelta * referenceDelta;
    candidateVariance += candidateDelta * candidateDelta;
    covariance += referenceDelta * candidateDelta;
  }
  const denominator = Math.max(1, count - 1);
  referenceVariance /= denominator;
  candidateVariance /= denominator;
  covariance /= denominator;

  const c1 = (0.01 * 255) ** 2;
  const c2 = (0.03 * 255) ** 2;
  const numerator = (2 * referenceMean * candidateMean + c1) * (2 * covariance + c2);
  const divisor = (
    (referenceMean ** 2 + candidateMean ** 2 + c1)
    * (referenceVariance + candidateVariance + c2)
  );
  return divisor === 0 ? 1 : Math.max(-1, Math.min(1, numerator / divisor));
}

function assertDiffArtifactPath(diffPath: string): void {
  const artifactRoot = path.resolve(process.env.GOETZ_ARTIFACT_DIR || '../../artifacts');
  const resolved = path.resolve(diffPath);
  if (resolved !== artifactRoot && !resolved.startsWith(`${artifactRoot}${path.sep}`)) {
    throw new Error('Visual diff output must stay below the configured artifacts directory.');
  }
}

export function comparePngBuffers(
  referenceBytes: Buffer,
  candidateBytes: Buffer,
  options: ComparePngOptions = {},
): VisualComparison {
  const reference = decodePng(referenceBytes, 'Reference image');
  const candidate = decodePng(candidateBytes, 'Candidate image');
  if (reference.width !== candidate.width || reference.height !== candidate.height) {
    throw new Error(
      `Compared PNG dimensions differ: ${reference.width}x${reference.height} vs ${candidate.width}x${candidate.height}.`,
    );
  }

  const totalPixels = reference.width * reference.height;
  const masks = options.masks ?? [];
  const masked = maskedOffsets(masks, reference.width, reference.height);
  const referencePixels = Buffer.from(reference.data);
  const candidatePixels = Buffer.from(candidate.data);
  for (const pixelIndex of masked) {
    const offset = pixelIndex * 4;
    for (let channel = 0; channel < 4; channel += 1) {
      referencePixels[offset + channel] = channel === 3 ? 255 : 127;
      candidatePixels[offset + channel] = channel === 3 ? 255 : 127;
    }
  }

  const diff = new PNG({ width: reference.width, height: reference.height });
  const changedPixels = pixelmatch(
    referencePixels,
    candidatePixels,
    diff.data,
    reference.width,
    reference.height,
    {
      threshold: ANTIALIAS_PIXEL_THRESHOLD,
      includeAA: false,
    },
  );
  const comparedPixels = totalPixels - masked.size;
  if (comparedPixels <= 0) {
    throw new Error('Visual masks cannot cover the entire compared image.');
  }
  const changedPixelRatio = changedPixels / comparedPixels;
  const ssim = structuralSimilarity(referencePixels, candidatePixels, totalPixels, masked);
  const passed = ssim >= MIN_COMPONENT_SSIM || changedPixelRatio <= MAX_CHANGED_PIXEL_RATIO;

  if (options.diffPath) {
    assertDiffArtifactPath(options.diffPath);
    writeFileSync(options.diffPath, PNG.sync.write(diff), { flag: 'wx' });
  }

  return {
    width: reference.width,
    height: reference.height,
    comparedPixels,
    changedPixels,
    changedPixelRatio,
    ssim,
    passed,
  };
}

export function assertVisualComparison(comparison: VisualComparison, label: string): void {
  if (!comparison.passed) {
    throw new Error(
      `${label} failed: SSIM ${comparison.ssim.toFixed(5)} is below ${MIN_COMPONENT_SSIM.toFixed(2)} `
      + `and changed pixels ${(comparison.changedPixelRatio * 100).toFixed(3)}% exceed `
      + `${(MAX_CHANGED_PIXEL_RATIO * 100).toFixed(0)}%.`,
    );
  }
}
