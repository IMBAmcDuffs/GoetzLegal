import {
  copyFileSync,
  existsSync,
  mkdirSync,
  readFileSync,
  writeFileSync,
} from 'node:fs';
import path from 'node:path';
import { expect, test, type Page } from '@playwright/test';
import { PNG } from 'pngjs';
import {
  ANTIALIAS_PIXEL_THRESHOLD,
  MAX_CHANGED_PIXEL_RATIO,
  MIN_COMPONENT_SSIM,
  assertVisualComparison,
  comparePngBuffers,
  extractAndResizePng,
  type VisualMask,
  validateFrozenFixtureSet,
} from './helpers/visual-compare';
import { settlePage } from './helpers/settle-page';

const fixtureDirectory = process.env.GOETZ_VISUAL_FIXTURE_DIR
  || (existsSync('/work/fixtures/legacy')
    ? '/work/fixtures/legacy'
    : path.resolve('../visual/fixtures/legacy'));

let retainedVisualArtifactDirectory: string | undefined;

function visualArtifactDirectory(): string {
  if (retainedVisualArtifactDirectory) return retainedVisualArtifactDirectory;
  const artifactRoot = path.resolve(process.env.GOETZ_ARTIFACT_DIR || '../../artifacts');
  const runId = `${new Date().toISOString().replace(/[:.]/gu, '-')}-${process.pid}`;
  retainedVisualArtifactDirectory = path.join(artifactRoot, 'visual-comparison', runId);
  mkdirSync(retainedVisualArtifactDirectory, { recursive: true });
  return retainedVisualArtifactDirectory;
}

function solidPng(
  width: number,
  height: number,
  color: readonly [number, number, number, number],
): Buffer {
  const png = new PNG({ width, height });
  for (let offset = 0; offset < png.data.length; offset += 4) {
    png.data[offset] = color[0];
    png.data[offset + 1] = color[1];
    png.data[offset + 2] = color[2];
    png.data[offset + 3] = color[3];
  }
  return PNG.sync.write(png);
}

test.describe('visual comparator contract', () => {
  test('accepts identical pixels at the documented thresholds', () => {
    const reference = solidPng(32, 24, [45, 45, 45, 255]);
    const comparison = comparePngBuffers(reference, reference);

    expect(MIN_COMPONENT_SSIM).toBe(0.98);
    expect(MAX_CHANGED_PIXEL_RATIO).toBe(0.03);
    expect(ANTIALIAS_PIXEL_THRESHOLD).toBe(0.15);
    expect(comparison).toMatchObject({
      changedPixels: 0,
      changedPixelRatio: 0,
      ssim: 1,
      passed: true,
    });
    expect(() => assertVisualComparison(comparison, 'identical synthetic image')).not.toThrow();
  });

  test('rejects a deliberate change beyond both component thresholds', () => {
    const reference = solidPng(32, 24, [45, 45, 45, 255]);
    const candidate = solidPng(32, 24, [255, 255, 255, 255]);
    const comparison = comparePngBuffers(reference, candidate);

    expect(comparison.changedPixelRatio).toBeGreaterThan(MAX_CHANGED_PIXEL_RATIO);
    expect(comparison.ssim).toBeLessThan(MIN_COMPONENT_SSIM);
    expect(comparison.passed).toBe(false);
    expect(() => assertVisualComparison(comparison, 'changed synthetic image')).toThrow(
      /changed synthetic image.*SSIM.*changed pixels/,
    );
  });

  test('documents the antialias threshold and ignores sub-threshold pixel noise', () => {
    const reference = solidPng(20, 20, [100, 100, 100, 255]);
    const candidatePng = PNG.sync.read(reference);
    candidatePng.data[0] = 101;
    candidatePng.data[1] = 101;
    candidatePng.data[2] = 101;
    const comparison = comparePngBuffers(reference, PNG.sync.write(candidatePng));

    expect(comparison.changedPixels).toBe(0);
    expect(comparison.passed).toBe(true);
  });

  test('accepts either SSIM or changed-pixel compliance without weakening either limit', () => {
    const reference = solidPng(20, 20, [100, 100, 100, 255]);
    const sparseCandidate = PNG.sync.read(reference);
    sparseCandidate.data[0] = 0;
    sparseCandidate.data[1] = 0;
    sparseCandidate.data[2] = 0;
    const sparseComparison = comparePngBuffers(reference, PNG.sync.write(sparseCandidate));
    expect(sparseComparison.ssim).toBeLessThan(MIN_COMPONENT_SSIM);
    expect(sparseComparison.changedPixelRatio).toBeLessThanOrEqual(MAX_CHANGED_PIXEL_RATIO);
    expect(sparseComparison.passed).toBe(true);

    const texturedReference = PNG.sync.read(solidPng(20, 20, [0, 0, 0, 255]));
    for (let index = 0; index < 400; index += 1) {
      const color = Math.floor(index / 20) < 10 ? 0 : 255;
      const offset = index * 4;
      texturedReference.data[offset] = color;
      texturedReference.data[offset + 1] = color;
      texturedReference.data[offset + 2] = color;
    }
    const texturedCandidate = PNG.sync.read(PNG.sync.write(texturedReference));
    for (let y = 2; y < 6; y += 1) {
      for (let x = 2; x < 6; x += 1) {
        const offset = ((y * 20) + x) * 4;
        texturedCandidate.data[offset] = 60;
        texturedCandidate.data[offset + 1] = 60;
        texturedCandidate.data[offset + 2] = 60;
      }
    }
    const texturedComparison = comparePngBuffers(
      PNG.sync.write(texturedReference),
      PNG.sync.write(texturedCandidate),
    );
    expect(texturedComparison.changedPixelRatio).toBeGreaterThan(MAX_CHANGED_PIXEL_RATIO);
    expect(texturedComparison.ssim).toBeGreaterThanOrEqual(MIN_COMPONENT_SSIM);
    expect(texturedComparison.passed).toBe(true);
  });

  test('refuses a legacy fixture whose raw PNG no longer matches geometry.json', ({}, testInfo) => {
    const stagedFixture = testInfo.outputPath('tampered-legacy');
    mkdirSync(stagedFixture, { recursive: true });
    const fixtureNames = [
      'geometry.json',
      'home-1440x900.png',
      'home-390x844.png',
      'home-989x844.png',
      'home-990x844.png',
    ];
    for (const fixtureName of fixtureNames) {
      copyFileSync(
        path.join(fixtureDirectory, fixtureName),
        path.join(stagedFixture, fixtureName),
      );
    }

    const tamperedPath = path.join(stagedFixture, 'home-390x844.png');
    const tampered = readFileSync(tamperedPath);
    tampered[tampered.length - 1] ^= 0xff;
    writeFileSync(tamperedPath, tampered);

    expect(() => validateFrozenFixtureSet(stagedFixture)).toThrow(/hash mismatch.*home-390x844\.png/);
    expect(() => validateFrozenFixtureSet(fixtureDirectory)).not.toThrow();
  });

  test('refuses unnamed or whole-component dynamic masks', ({}, testInfo) => {
    const stagedFixture = testInfo.outputPath('broad-mask-legacy');
    mkdirSync(stagedFixture, { recursive: true });
    for (const fixtureName of [
      'geometry.json',
      'home-1440x900.png',
      'home-390x844.png',
      'home-989x844.png',
      'home-990x844.png',
    ]) {
      copyFileSync(
        path.join(fixtureDirectory, fixtureName),
        path.join(stagedFixture, fixtureName),
      );
    }

    const geometryPath = path.join(stagedFixture, 'geometry.json');
    const geometry = JSON.parse(readFileSync(geometryPath, 'utf8'));
    geometry.dynamic_masks = [{
      name: '',
      viewport: '1440x900',
      component: 'hero',
      ...geometry.components['1440x900'].hero[0].rect,
    }];
    writeFileSync(geometryPath, `${JSON.stringify(geometry)}\n`);
    expect(() => validateFrozenFixtureSet(stagedFixture)).toThrow(/uniquely named/);

    geometry.dynamic_masks[0].name = 'entire hero section';
    writeFileSync(geometryPath, `${JSON.stringify(geometry)}\n`);
    expect(() => validateFrozenFixtureSet(stagedFixture)).toThrow(/whole component sections/);
  });

  test('corrected-font line masks leave non-text pixels in the same parent enforceable', () => {
    const reference = solidPng(20, 20, [100, 100, 100, 255]);
    const masks = createCorrectedFontLineMasks(
      'synthetic_parent',
      0,
      { x: 0, y: 0, width: 20, height: 20 },
      [{ x: 3, y: 8, width: 14, height: 3 }],
      20,
      20,
    );
    expect(masks).toEqual([{
      name: 'corrected-font-text:synthetic_parent[0]:line[0]',
      x: 2,
      y: 7,
      width: 16,
      height: 5,
    }]);

    const fontOnlyCandidate = PNG.sync.read(reference);
    for (let y = 8; y < 11; y += 1) {
      for (let x = 3; x < 17; x += 1) {
        const offset = ((y * 20) + x) * 4;
        fontOnlyCandidate.data[offset] = 255;
        fontOnlyCandidate.data[offset + 1] = 255;
        fontOnlyCandidate.data[offset + 2] = 255;
      }
    }
    const fontOnlyComparison = comparePngBuffers(
      reference,
      PNG.sync.write(fontOnlyCandidate),
      { masks },
    );
    expect(fontOnlyComparison.changedPixels).toBe(0);
    expect(fontOnlyComparison.passed).toBe(true);

    const nonTextCandidate = PNG.sync.read(PNG.sync.write(fontOnlyCandidate));
    for (let y = 0; y < 5; y += 1) {
      for (let x = 0; x < 5; x += 1) {
        const offset = ((y * 20) + x) * 4;
        nonTextCandidate.data[offset] = 255;
        nonTextCandidate.data[offset + 1] = 255;
        nonTextCandidate.data[offset + 2] = 255;
      }
    }
    const nonTextComparison = comparePngBuffers(
      reference,
      PNG.sync.write(nonTextCandidate),
      { masks },
    );
    expect(nonTextComparison.changedPixelRatio).toBeGreaterThan(MAX_CHANGED_PIXEL_RATIO);
    expect(nonTextComparison.ssim).toBeLessThan(MIN_COMPONENT_SSIM);
    expect(nonTextComparison.passed).toBe(false);
  });

  test('corrected-font DOM discovery excludes aria-hidden and icon-font glyphs', async ({ page }) => {
    await page.setContent(`
      <style>
        #parent { font-family: Roboto, "Helvetica Neue", Helvetica, Arial, sans-serif; }
        .icon-font { font-family: one; }
      </style>
      <div id="parent">
        <span>Readable Roboto copy</span>
        <span aria-hidden="true">Hidden Roboto glyph</span>
        <span class="goetz-practice-area-item__scale-glyph">Classified Roboto glyph</span>
        <span class="icon-font">&#xf24e;</span>
      </div>
    `);

    const evidence = await measureCorrectedFontTextEvidence(page, {
      synthetic_parent: '#parent',
    });
    expect(evidence.synthetic_parent).toHaveLength(1);
    expect(evidence.synthetic_parent[0].semanticText).toBe('Readable Roboto copy');
    expect(evidence.synthetic_parent[0].textRuns).toHaveLength(1);
    expect(evidence.synthetic_parent[0].textLineRects).toHaveLength(1);
    expect(evidence.synthetic_parent[0].textLineRects[0].width).toBeGreaterThan(0);
    expect(evidence.synthetic_parent[0].textLineRects[0].height).toBeGreaterThan(0);
  });

  test('corrected-font evidence measures visible descendants of a zero-height navigation proxy', async ({ page }) => {
    await page.setContent(`
      <style>
        #nav { height: 0; font-family: Roboto, "Helvetica Neue", Helvetica, Arial, sans-serif; }
        #nav a { display: block; height: 30px; line-height: 30px; }
      </style>
      <ul id="nav"><li><a href="#target">Home</a></li></ul><div id="target"></div>
    `);
    const evidence = await measureCorrectedFontTextEvidence(page, { primary_nav: '#nav' });
    expect(evidence.primary_nav[0].semanticText).toBe('Home');
    expect(evidence.primary_nav[0].textRuns).toHaveLength(1);
    expect(evidence.primary_nav[0].textLineRects[0].height).toBeGreaterThan(0);
  });

  test('corrected-font evidence accepts local Roboto-only headings and preserves footer text-node runs', async ({ page }) => {
    await page.setContent(`
      <style>
        #footer { font-family: Roboto, "Helvetica Neue", Helvetica, Arial, sans-serif; }
        #footer h2 { font-family: Roboto; }
      </style>
      <section id="footer">
        <h2>Contact Us</h2>
        <p><strong>Phone</strong> – <a href="tel:+12399362841">(239) 936-2841</a></p>
        <p><strong>E-Mail Address</strong></p>
      </section>
    `);
    const evidence = await measureCorrectedFontTextEvidence(page, { footer: '#footer' });
    expect(evidence.footer[0].textRuns.map(({ text }) => text)).toEqual([
      'Contact Us',
      'Phone',
      '–',
      '(239) 936-2841',
      'E-Mail Address',
    ]);
  });

  test('component collection never treats Array.map indexes as descendant-bound flags', async ({ page }) => {
    await page.setContent(`
      <style>.column { font-family: Roboto, "Helvetica Neue", Helvetica, Arial, sans-serif; }</style>
      <section class="column"><h2>First</h2><a href="#one">One</a></section>
      <section class="column"><h2>Second</h2><a href="#two">Two</a></section>
      <section class="column"><h2>Third</h2><a href="#three">Three</a></section>
      <div id="one"></div><div id="two"></div><div id="three"></div>
    `);
    const evidence = await measureCorrectedFontTextEvidence(page, { columns: '.column' });
    expect(evidence.columns.map(({ textRuns }) => textRuns.map(({ text }) => text))).toEqual([
      ['First', 'One'],
      ['Second', 'Two'],
      ['Third', 'Three'],
    ]);
  });

  test('major geometry contract rejects vertical displacement and duplicate sections', () => {
    const reference = [{
      rect: { x: 0, y: 100, width: 1440, height: 400 },
    }];

    expect(majorGeometryViolations(
      [{ rect: { x: 0, y: 103, width: 1440, height: 400 } }],
      reference,
      { position: 2, width: 14.4, height: 16 },
    )).toContain('y');
    expect(majorGeometryViolations(
      [
        { rect: { x: 0, y: 100, width: 1440, height: 400 } },
        { rect: { x: 0, y: 100, width: 1440, height: 400 } },
      ],
      reference,
      { position: 2, width: 14.4, height: 16 },
    )).toContain('count: expected 1, received 2');
  });

  test('zero-height primary navigation proxy requires exact visible paint geometry', () => {
    const reference = [{
      rect: { x: 518.234, y: 51, width: 892.953, height: 0 },
    }];
    const accessible = [{
      rect: { x: 518.234, y: 51, width: 892.953, height: 64 },
      paintRect: { x: 518.234, y: 51, width: 892.953, height: 64 },
    }];
    const tolerance = { position: 2, width: 2, height: 2 };

    expect(navigationProxyGeometryViolations(accessible, reference, tolerance)).toEqual([]);
    expect(navigationProxyGeometryViolations([{
      ...accessible[0],
      paintRect: { ...accessible[0].paintRect, y: 55 },
    }], reference, tolerance)).toContain('paint y');
    expect(navigationProxyGeometryViolations([{
      ...accessible[0],
      rect: { ...accessible[0].rect, width: 900 },
    }], reference, tolerance)).toContain('proxy width');
    expect(navigationProxyGeometryViolations([{
      ...accessible[0],
      paintRect: { ...accessible[0].paintRect, height: 70 },
    }], reference, tolerance)).toContain('paint height');
    expect(navigationProxyGeometryViolations([{
      ...accessible[0],
      rect: { ...accessible[0].rect, height: 1 },
    }], reference, tolerance)).toContain('container paint height');
    expect(navigationProxyGeometryViolations([{
      rect: accessible[0].rect,
    }], reference, tolerance)).toContain('paint bounds missing');
  });

  test('masked text contract rejects same-geometry copy and nested color mutations', () => {
    const expected = {
      semanticText: 'NEED A LAWYER?',
      textRuns: [{
        text: 'LAWYER?',
        fontSize: 64,
        lineHeight: 70.4,
        fontWeight: '700',
        color: 'rgb(121, 81, 169)',
      }],
    };
    expect(maskedTextContractViolations(expected, {
      ...expected,
      semanticText: 'NEED A LAWYER!',
    })).toContain('semantic text');
    expect(maskedTextContractViolations(expected, {
      ...expected,
      textRuns: [{ ...expected.textRuns[0], color: 'rgb(255, 255, 255)' }],
    })).toContain('text run 0 color');
    expect(normalizeSemanticText('contact the firm online .')).toBe('contact the firm online.');
    expect(normalizeSemanticText('A law firm with  seasoned trial  attorneys')).toBe(
      'A law firm with seasoned trial attorneys',
    );
    expect(normalizeSemanticText('NEED A LAWYER!')).not.toBe(normalizeSemanticText('NEED A LAWYER?'));
  });

  test('corrected-font masks stay below strict component and aggregate area ceilings', () => {
    expect(MAX_COMPONENT_MASKED_AREA_RATIO).toBe(0.50);
    expect(MAX_TOTAL_MASKED_AREA_RATIO).toBe(0.14);

    const narrow = maskCoverage([{ name: 'line', x: 2, y: 8, width: 16, height: 3 }], 20, 20);
    expect(maskBudgetViolations(narrow, narrow)).toEqual([]);

    const broad = maskCoverage([{ name: 'broad', x: 0, y: 0, width: 12, height: 20 }], 20, 20);
    expect(maskBudgetViolations(broad, broad)).toContain('component masked area');
    expect(maskBudgetViolations(narrow, {
      maskedPixels: 81,
      totalPixels: 400,
    })).toContain('aggregate masked area');
    expect(maskBudgetViolations(
      { maskedPixels: 501, totalPixels: 1000 },
      narrow,
    )).toContain('component masked area');
    expect(maskBudgetViolations(
      narrow,
      { maskedPixels: 141, totalPixels: 1000 },
    )).toContain('aggregate masked area');
  });

  test('capture readiness rejects an unpainted image and accepts real pixel variation', () => {
    const blank = solidPng(20, 20, [255, 255, 255, 255]);
    const painted = PNG.sync.read(blank);
    for (let y = 5; y < 15; y += 1) {
      for (let x = 5; x < 15; x += 1) {
        const offset = ((y * 20) + x) * 4;
        painted.data[offset] = 121;
        painted.data[offset + 1] = 81;
        painted.data[offset + 2] = 169;
      }
    }
    expect(paintedPixelVariationRatio(blank, { x: 0, y: 0, width: 20, height: 20 })).toBe(0);
    expect(paintedPixelVariationRatio(
      PNG.sync.write(painted),
      { x: 0, y: 0, width: 20, height: 20 },
    )).toBeGreaterThan(0.20);
  });

});

interface Rect {
  x: number;
  y: number;
  width: number;
  height: number;
}

interface MajorGeometryTolerance {
  position: number;
  width: number;
  height: number;
}

interface NavigationProxyGeometry {
  rect: Rect;
  paintRect?: Rect;
}

interface TextRunContract {
  text: string;
  fontSize: number;
  lineHeight: number;
  fontWeight: string;
  color: string;
}

interface MaskedTextContract {
  semanticText: string;
  textRuns: TextRunContract[];
}

interface MaskCoverage {
  maskedPixels: number;
  totalPixels: number;
}

const MAX_COMPONENT_MASKED_AREA_RATIO = 0.50;
const MAX_TOTAL_MASKED_AREA_RATIO = 0.14;
const LEGACY_DESKTOP_PRIMARY_NAV_PAINT_HEIGHT = 64;

function maskedTextContractViolations(
  expected: MaskedTextContract,
  actual: MaskedTextContract,
): string[] {
  const violations: string[] = [];
  if (actual.semanticText !== expected.semanticText) violations.push('semantic text');
  if (actual.textRuns.length !== expected.textRuns.length) {
    violations.push(`text run count: expected ${expected.textRuns.length}, received ${actual.textRuns.length}`);
  }
  for (let index = 0; index < Math.min(actual.textRuns.length, expected.textRuns.length); index += 1) {
    for (const property of ['text', 'fontSize', 'lineHeight', 'fontWeight', 'color'] as const) {
      if (actual.textRuns[index][property] !== expected.textRuns[index][property]) {
        violations.push(`text run ${index} ${property}`);
      }
    }
  }
  return violations;
}

function maskCoverage(masks: readonly VisualMask[], width: number, height: number): MaskCoverage {
  const pixels = new Set<number>();
  for (const mask of masks) {
    const left = Math.max(0, Math.floor(mask.x));
    const top = Math.max(0, Math.floor(mask.y));
    const right = Math.min(width, Math.ceil(mask.x + mask.width));
    const bottom = Math.min(height, Math.ceil(mask.y + mask.height));
    for (let y = top; y < bottom; y += 1) {
      for (let x = left; x < right; x += 1) pixels.add((y * width) + x);
    }
  }
  return { maskedPixels: pixels.size, totalPixels: width * height };
}

function maskBudgetViolations(component: MaskCoverage, aggregate: MaskCoverage): string[] {
  const violations: string[] = [];
  if (component.maskedPixels / component.totalPixels > MAX_COMPONENT_MASKED_AREA_RATIO) {
    violations.push('component masked area');
  }
  if (aggregate.maskedPixels / aggregate.totalPixels > MAX_TOTAL_MASKED_AREA_RATIO) {
    violations.push('aggregate masked area');
  }
  return violations;
}

function paintedPixelVariationRatio(bytes: Buffer, rect: Rect): number {
  const png = PNG.sync.read(bytes);
  const left = Math.max(0, Math.floor(rect.x));
  const top = Math.max(0, Math.floor(rect.y));
  const right = Math.min(png.width, Math.ceil(rect.x + rect.width));
  const bottom = Math.min(png.height, Math.ceil(rect.y + rect.height));
  if (right <= left || bottom <= top) return 0;

  const colors = new Map<number, number>();
  let pixels = 0;
  let dominant = 0;
  for (let y = top; y < bottom; y += 1) {
    for (let x = left; x < right; x += 1) {
      const offset = ((y * png.width) + x) * 4;
      const key = ((png.data[offset] >> 4) << 12)
        | ((png.data[offset + 1] >> 4) << 8)
        | ((png.data[offset + 2] >> 4) << 4)
        | (png.data[offset + 3] >> 4);
      const count = (colors.get(key) || 0) + 1;
      colors.set(key, count);
      dominant = Math.max(dominant, count);
      pixels += 1;
    }
  }
  return pixels === 0 ? 0 : 1 - (dominant / pixels);
}

function majorGeometryViolations(
  actuals: ReadonlyArray<{ rect: Rect }>,
  references: ReadonlyArray<{ rect: Rect }>,
  tolerance: MajorGeometryTolerance,
): string[] {
  const violations: string[] = [];
  if (actuals.length !== references.length) {
    violations.push(`count: expected ${references.length}, received ${actuals.length}`);
  }
  const dimensionTolerance: Record<keyof Rect, number> = {
    x: tolerance.position,
    y: tolerance.position,
    width: tolerance.width,
    height: tolerance.height,
  };
  for (let index = 0; index < Math.min(actuals.length, references.length); index += 1) {
    for (const dimension of ['x', 'y', 'width', 'height'] as const) {
      if (
        Math.abs(actuals[index].rect[dimension] - references[index].rect[dimension])
        > dimensionTolerance[dimension]
      ) {
        violations.push(dimension);
      }
    }
  }
  return violations;
}

function navigationProxyGeometryViolations(
  actuals: readonly NavigationProxyGeometry[],
  references: ReadonlyArray<{ rect: Rect }>,
  tolerance: MajorGeometryTolerance,
): string[] {
  const violations: string[] = [];
  if (actuals.length !== references.length) {
    violations.push(`count: expected ${references.length}, received ${actuals.length}`);
  }
  for (let index = 0; index < Math.min(actuals.length, references.length); index += 1) {
    const actual = actuals[index];
    const reference = references[index];
    if (reference.rect.height !== 0 || reference.rect.width <= 0) {
      violations.push(...majorGeometryViolations([actual], [reference], tolerance));
      continue;
    }

    // Enfold reports its desktop UL as a zero-height positioning proxy even
    // though seven 64px links visibly paint. Compare the accessible list's
    // position and width against that proxy without reproducing its broken
    // height, then enforce the visible descendant union separately.
    const proxyRect = { ...actual.rect, height: 0 };
    violations.push(...majorGeometryViolations(
      [{ rect: proxyRect }],
      [reference],
      tolerance,
    ).map((violation) => `proxy ${violation}`));

    if (!actual.paintRect) {
      violations.push('paint bounds missing');
      continue;
    }
    const expectedPaintRect = {
      ...reference.rect,
      height: LEGACY_DESKTOP_PRIMARY_NAV_PAINT_HEIGHT,
    };
    violations.push(...majorGeometryViolations(
      [{ rect: actual.paintRect }],
      [{ rect: expectedPaintRect }],
      tolerance,
    ).map((violation) => `paint ${violation}`));
    violations.push(...majorGeometryViolations(
      [{ rect: actual.rect }],
      [{ rect: actual.paintRect }],
      tolerance,
    ).map((violation) => `container paint ${violation}`));
  }
  return violations;
}

const CORRECTED_FONT_MASK_PADDING_PX = 1;

function createCorrectedFontLineMasks(
  component: string,
  componentIndex: number,
  componentRect: Rect,
  textLineRects: readonly Rect[],
  targetWidth: number,
  targetHeight: number,
): VisualMask[] {
  if (componentRect.width <= 0 || componentRect.height <= 0) return [];
  const scaleX = targetWidth / componentRect.width;
  const scaleY = targetHeight / componentRect.height;

  return textLineRects.flatMap((lineRect, lineIndex) => {
    const clippedLeft = Math.max(componentRect.x, lineRect.x);
    const clippedTop = Math.max(componentRect.y, lineRect.y);
    const clippedRight = Math.min(componentRect.x + componentRect.width, lineRect.x + lineRect.width);
    const clippedBottom = Math.min(componentRect.y + componentRect.height, lineRect.y + lineRect.height);
    if (clippedRight <= clippedLeft || clippedBottom <= clippedTop) return [];

    const left = Math.max(
      0,
      ((clippedLeft - componentRect.x) * scaleX) - CORRECTED_FONT_MASK_PADDING_PX,
    );
    const top = Math.max(
      0,
      ((clippedTop - componentRect.y) * scaleY) - CORRECTED_FONT_MASK_PADDING_PX,
    );
    const right = Math.min(
      targetWidth,
      ((clippedRight - componentRect.x) * scaleX) + CORRECTED_FONT_MASK_PADDING_PX,
    );
    const bottom = Math.min(
      targetHeight,
      ((clippedBottom - componentRect.y) * scaleY) + CORRECTED_FONT_MASK_PADDING_PX,
    );

    return [{
      name: `corrected-font-text:${component}[${componentIndex}]:line[${lineIndex}]`,
      x: left,
      y: top,
      width: right - left,
      height: bottom - top,
    }];
  });
}

interface LegacyComponent {
  text: string;
  rect: Rect;
  style: {
    color: string;
    font_size: string;
    line_height: string;
    font_weight: string;
  };
}

interface LegacyFixture {
  dynamic_masks: Array<{
    name: string;
    viewport: string;
    component: string;
    x: number;
    y: number;
    width: number;
    height: number;
  }>;
  files: Record<string, { pixel_width: number; pixel_height: number }>;
  viewports: Record<string, {
    document: { scroll_width: number; scroll_height: number };
  }>;
  components: Record<string, Record<string, LegacyComponent[]>>;
}

interface CandidateComponent {
  rect: Rect;
  paintRect?: Rect;
  color: string;
  fontSize: number;
  lineHeight: number;
  fontWeight: string;
  semanticText: string;
  textRuns: TextRunContract[];
  textLineRects: Rect[];
}

const sectionSelectors = [
  '.site-header',
  '.goetz-hero',
  '.goetz-welcome',
  '.goetz-practice-areas',
  '.goetz-attorney-grid',
  '.goetz-cta',
  '.site-footer',
] as const;

const componentSelectors: Record<string, string> = {
  header: '.site-header',
  topbar: '.site-header__top',
  header_main: '.site-header__nav-row',
  primary_nav: '.site-navigation__list, .site-navigation .menu',
  logo: '.site-branding-card .custom-logo',
  hero: '.goetz-hero',
  hero_heading: '.goetz-hero h1',
  hero_image: '.goetz-hero__image',
  hero_button: '.goetz-hero .goetz-button',
  welcome: '.goetz-welcome',
  welcome_heading: '.goetz-intro__heading',
  welcome_border_images: '.goetz-intro__image',
  welcome_scale_image: '.goetz-intro__icon',
  practice: '.goetz-practice-areas',
  practice_cells: '.goetz-practice-band__image, .goetz-practice-band__content',
  practice_headings: '.goetz-practice-areas__heading, .goetz-practice-area-item__label',
  practice_items: '.goetz-practice-area-item',
  practice_icons: '.goetz-practice-area-item__scale',
  attorneys: '.goetz-attorney-grid',
  attorney_scale_image: '.goetz-attorney-grid__mark',
  james_portrait: '.goetz-attorney-grid .goetz-attorney-card:first-child .goetz-attorney-card__image',
  gregory_portrait: '.goetz-attorney-grid .goetz-attorney-card:nth-child(2) .goetz-attorney-card__image',
  attorney_buttons: '.goetz-attorney-grid .goetz-attorney-card__links a',
  cta: '.goetz-cta',
  cta_heading: '.goetz-cta h2',
  cta_button: '.goetz-cta .goetz-button',
  footer: '.site-footer',
  footer_logo: '.site-footer__logo',
  footer_columns: '.site-footer__grid > section, .site-footer__bottom',
};

const majorComponents = ['header', 'hero', 'welcome', 'practice', 'attorneys', 'cta', 'footer'] as const;
const textComponents = [
  'hero_heading',
  'welcome_heading',
  'practice_headings',
  'attorney_buttons',
  'cta_heading',
  'cta_button',
] as const;
// The approved design excludes only the legacy site's broken cross-origin
// Roboto glyph rasterization. Six text-only leaf crops stay under exact
// geometry and computed-style assertions; parent crops use separately named,
// one-pixel-padded DOM text-line masks while images, icons, and backgrounds
// remain fully compared.
const correctedFontPixelExclusions = new Set<string>(textComponents);

const WHITE = 'rgb(255, 255, 255)';
const PURPLE = 'rgb(121, 81, 169)';
const PURPLE_LARGE_ON_DARK = 'rgb(146, 106, 192)';
const PURPLE_LINK_ON_DARK = 'rgb(176, 144, 220)';
const DARK = 'rgb(33, 37, 41)';
const FOOTER_TEXT = 'rgba(255, 255, 255, 0.58)';

function expectedRun(
  text: string,
  fontSize: number,
  lineHeight: number,
  fontWeight: string,
  color: string,
): TextRunContract {
  return { text, fontSize, lineHeight, fontWeight, color };
}

/**
 * Immutable semantic/style evidence transcribed from the frozen capture's
 * human-readable component text and computed typography. The capture stores
 * only outer-element styles, so emphasized nested runs use the approved brand
 * weights and WCAG-compliant dark-surface colors while correcting the broken
 * legacy Roboto request. Parent entries
 * intentionally compose these leaf contracts instead of trusting candidate
 * text, and joining runs with spaces preserves the approved hero wording across
 * responsive <br> elements.
 */
function expectedMaskedTextContract(
  viewportKey: string,
  component: string,
  index: number,
): MaskedTextContract {
  const width = Number.parseInt(viewportKey, 10);
  const compact = width <= 600;
  const tablet = width > 600 && width < 990;
  const contacts = [
    expectedRun('(239) 936-2841', compact ? 11 : 16, compact ? 11 : 16, compact ? '700' : '400', WHITE),
    expectedRun('info@goetzlegal.com', compact ? 11 : 16, compact ? 11 : 16, compact ? '700' : '400', WHITE),
  ];
  const nav = width < 990 ? [] : [
    expectedRun('Home', 16, 64, '400', PURPLE_LINK_ON_DARK),
    ...['James L. Goetz', 'Gregory W. Goetz', 'Staff', 'Questions', 'Links', 'Contact']
      .map((text) => expectedRun(text, 16, 64, '400', WHITE)),
  ];
  const heroHeadingSize = compact ? 24 : (tablet ? 40 : 50);
  const heroHeadingLine = compact ? 26.4 : (tablet ? 44 : 55);
  const heroHeading = [
    ...['A law firm with', 'seasoned trial', 'attorneys']
      .map((text) => expectedRun(text, heroHeadingSize, heroHeadingLine, '500', WHITE)),
    ...['in', 'Fort Myers,', 'Florida.']
      .map((text) => expectedRun(text, heroHeadingSize, heroHeadingLine, '700', PURPLE_LARGE_ON_DARK)),
  ];
  const hero = [
    expectedRun('GoetzLegal.com', 15, 23.25, '400', 'rgb(58, 63, 70)'),
    ...heroHeading,
    expectedRun(
      'Goetz & Goetz represents all individuals who need legal advice in regards to corporate, construction, real estate, probate, criminal and bankruptcy matters. Goetz & Goetz has been a legal resource in Fort Myers for over 50 years and has a vast amount of legal experience at your disposal.',
      15,
      23.25,
      '700',
      WHITE,
    ),
    expectedRun('Learn More About Us', 20, 24, '500', 'rgb(94, 94, 94)'),
  ];
  const introHeadingSize = compact ? 21 : (tablet ? 27 : 32);
  const introHeadingLine = compact ? 23.1 : (tablet ? 29.7 : 35.2);
  const welcomeHeading = [
    expectedRun('Mr. Goetz welcomes', introHeadingSize, introHeadingLine, '700', PURPLE),
    expectedRun(
      'you to browse this site to learn more about his firm and get information.',
      introHeadingSize,
      introHeadingLine,
      '500',
      DARK,
    ),
  ];
  const welcomeCopy = [
    expectedRun('If you would like to speak with Mr. Goetz, please call', 14, 23.1, '400', DARK),
    expectedRun('(239) 936-2841', 14, 23.1, '700', PURPLE),
    expectedRun('or contact the firm', 14, 23.1, '400', DARK),
    expectedRun('online', 14, 23.1, '700', PURPLE),
    expectedRun('.', 14, 23.1, '400', DARK),
  ];
  const practiceHeading = [
    expectedRun('Providing', introHeadingSize, introHeadingLine, '500', WHITE),
    expectedRun('Legal Advice', introHeadingSize, introHeadingLine, '700', PURPLE_LARGE_ON_DARK),
    expectedRun('in:', introHeadingSize, introHeadingLine, '500', WHITE),
  ];
  const practiceLabels = ['Corporate', 'Construction', 'Real Estate', 'Probate', 'Criminal', 'Bankruptcy', 'Appeals']
    .map((text) => expectedRun(text, 20, 22, '600', WHITE));
  const attorneysHeadingSize = tablet ? 27 : 32;
  const attorneysHeadingLine = tablet ? 29.7 : 35.2;
  const attorneyButtons = [
    expectedRun('Read Full Bio', 20, 24, '500', 'rgba(0, 0, 0, 0.6)'),
    expectedRun('Read Full Bio', 20, 24, '500', 'rgba(0, 0, 0, 0.6)'),
  ];
  const attorneys = [
    expectedRun('Attorneys', attorneysHeadingSize, attorneysHeadingLine, '400', 'rgb(32, 36, 44)'),
    expectedRun('James L.', 26, 28.6, '700', PURPLE),
    expectedRun('Goetz', 26, 28.6, '500', DARK),
    expectedRun(
      'James L. Goetz was born in Erie, Pennsylvania. He grew up in Oil City and Girard, Pennsylvania working on his father’s farm and coal mines until he went to college.',
      16,
      26.4,
      '400',
      DARK,
    ),
    attorneyButtons[0],
    expectedRun('Gregory W.', 26, 28.6, '700', PURPLE),
    expectedRun('Goetz', 26, 28.6, '500', DARK),
    expectedRun(
      'Mr. Gregory W. Goetz was born and raised here in Fort Myers, Florida. He attended Fort Myers High School and then was accepted to University of Florida.',
      16,
      26.4,
      '400',
      DARK,
    ),
    attorneyButtons[1],
  ];
  const ctaHeadingSize = compact ? 25 : (tablet ? 50 : 64);
  const ctaHeadingLine = compact ? 27.5 : (tablet ? 55 : 70.4);
  const ctaHeading = [
    expectedRun('NEED A', ctaHeadingSize, ctaHeadingLine, '500', WHITE),
    expectedRun('LAWYER?', ctaHeadingSize, ctaHeadingLine, '700', PURPLE_LARGE_ON_DARK),
  ];
  const cta = [
    expectedRun(
      'WE ARE AN EXPERIENCED TEAM',
      compact ? 18 : (tablet ? 23 : 28),
      compact ? 23.4 : (tablet ? 29.9 : 36.4),
      '400',
      WHITE,
    ),
    ...ctaHeading,
    expectedRun('Get Consultation', 20, 24, '500', DARK),
  ];
  const disclaimer = expectedRun(
    'The content of this Website is intended to provide general information about Goetz & Goetz. The information provided is not an offer to represent you or create an attorney-client relationship. The content of any E-mail communication, facsimile or correspondence sent to Goetz & Goetz or to any of its attorneys will not, in and of itself, create an attorney-client relationship.',
    13,
    21.45,
    '400',
    FOOTER_TEXT,
  );
  const footerNav = [
    expectedRun('Site Navigation', 28, 30.8, '600', PURPLE_LARGE_ON_DARK),
    ...['Home', 'James L. Goetz', 'Gregory W. Goetz', 'Staff', 'Questions', 'Links', 'Contact Us']
      .map((text) => expectedRun(text, 16, 26.4, '400', FOOTER_TEXT)),
  ];
  const footerContact = [
    expectedRun('Contact Us', 28, 30.8, '600', PURPLE_LARGE_ON_DARK),
    expectedRun('Fort Myers, Florida', 16, 26.4, '400', FOOTER_TEXT),
    expectedRun('Phone', 16, 26.4, '700', FOOTER_TEXT),
    expectedRun('–', 16, 26.4, '400', FOOTER_TEXT),
    expectedRun('(239) 936-2841', 16, 26.4, '400', FOOTER_TEXT),
    expectedRun('E-Mail Address', 16, 26.4, '700', FOOTER_TEXT),
    expectedRun('James L. Goetz', 16, 26.4, '400', FOOTER_TEXT),
    expectedRun('|', 16, 26.4, '400', FOOTER_TEXT),
    expectedRun('Gregory W. Goetz', 16, 26.4, '400', FOOTER_TEXT),
    expectedRun(
      'The hiring of a lawyer is an important decision that should not be based solely upon advertisements. Before you decide, ask us to send you free written information about our qualifications and experience.',
      13,
      21.45,
      '400',
      FOOTER_TEXT,
    ),
  ];
  const copyright = expectedRun(
    '© Copyright 2024 – Goetz & Goetz. All Rights Reserved',
    16,
    26.4,
    '400',
    'rgba(255, 255, 255, 0.62)',
  );

  let textRuns: TextRunContract[] = [];
  switch (component) {
    case 'header': textRuns = [...contacts, ...nav]; break;
    case 'topbar': textRuns = contacts; break;
    case 'header_main':
    case 'primary_nav': textRuns = nav; break;
    case 'hero': textRuns = hero; break;
    case 'hero_heading': textRuns = heroHeading; break;
    case 'hero_button': textRuns = [hero[hero.length - 1]]; break;
    case 'welcome': textRuns = [...welcomeHeading, ...welcomeCopy]; break;
    case 'welcome_heading': textRuns = welcomeHeading; break;
    case 'practice': textRuns = [...practiceHeading, ...practiceLabels]; break;
    case 'practice_cells': textRuns = index === 0 ? [] : [...practiceHeading, ...practiceLabels]; break;
    case 'practice_headings': textRuns = index === 0 ? practiceHeading : [practiceLabels[index - 1]]; break;
    case 'practice_items': textRuns = [practiceLabels[index]]; break;
    case 'attorneys': textRuns = attorneys; break;
    case 'attorney_buttons': textRuns = [attorneyButtons[index]]; break;
    case 'cta': textRuns = cta; break;
    case 'cta_heading': textRuns = ctaHeading; break;
    case 'cta_button': textRuns = [cta[cta.length - 1]]; break;
    case 'footer': textRuns = [disclaimer, ...footerNav, ...footerContact, copyright]; break;
    case 'footer_columns':
      textRuns = [
        [disclaimer],
        footerNav,
        footerContact,
        [copyright],
      ][index] || [];
      break;
    default: textRuns = [];
  }
  return {
    semanticText: normalizeSemanticText(textRuns.map(({ text }) => text).join(' ')),
    textRuns,
  };
}

function absoluteDifference(actual: number, expected: number, tolerance: number, label: string): void {
  expect.soft(
    Math.abs(actual - expected),
    `${label}: expected ${expected} +/- ${tolerance}, received ${actual}`,
  ).toBeLessThanOrEqual(tolerance);
}

function relativeToSection(rect: Rect, section: Rect): Rect {
  return {
    x: rect.x - section.x,
    y: rect.y - section.y,
    width: rect.width,
    height: rect.height,
  };
}

function parentSectionFor(component: string): string {
  if (['header', 'topbar', 'header_main', 'primary_nav', 'logo'].includes(component)) return 'header';
  if (component.startsWith('hero')) return 'hero';
  if (component.startsWith('welcome')) return 'welcome';
  if (component.startsWith('practice')) return 'practice';
  if (component.startsWith('attorney') || component === 'attorneys' || component.endsWith('_portrait')) return 'attorneys';
  if (component.startsWith('cta')) return 'cta';
  return 'footer';
}

interface MaskedTextEvidence extends MaskedTextContract {
  textLineRects: Rect[];
}

function normalizeSemanticText(text: string): string {
  return text
    .normalize('NFC')
    .replace(/\s+/gu, ' ')
    .replace(/\s+([.,;:!?])/gu, '$1')
    .trim();
}

async function measureCorrectedFontTextEvidence(
  page: Page,
  selectors: Record<string, string>,
): Promise<Record<string, MaskedTextEvidence[]>> {
  return page.evaluate((browserSelectors) => {
    const isIntendedRobotoStack = (fontFamily: string): boolean => {
      const families = fontFamily
        .split(',')
        .map((family) => family.trim().replace(/^['"]|['"]$/gu, '').toLowerCase());
      // Chromium can serialize a CSS-variable heading family as just
      // "Roboto" even when body copy retains the full fallback list. The
      // first family is the stable identity; icon fonts still fail this test.
      return families[0] === 'roboto';
    };

    const eligibleTextEvidence = (element: HTMLElement, useDescendantBounds = false) => {
      const ownBox = element.getBoundingClientRect();
      const descendantBoxes = useDescendantBounds
        ? Array.from(element.querySelectorAll<HTMLElement>('a'))
          .map((descendant) => descendant.getBoundingClientRect())
          .filter((rect) => rect.width > 0 && rect.height > 0)
        : [];
      const box = descendantBoxes.length > 0 ? {
        left: Math.min(...descendantBoxes.map((rect) => rect.left)),
        top: Math.min(...descendantBoxes.map((rect) => rect.top)),
        right: Math.max(...descendantBoxes.map((rect) => rect.right)),
        bottom: Math.max(...descendantBoxes.map((rect) => rect.bottom)),
      } : ownBox;
      const lines: Rect[] = [];
      const runs: TextRunContract[] = [];
      const walker = document.createTreeWalker(element, NodeFilter.SHOW_TEXT);
      let node = walker.nextNode();
      while (node) {
        const parent = node.parentElement;
        const hiddenOrIcon = parent?.closest(
          '[aria-hidden="true"], .goetz-practice-area-item__scale-glyph, .goetz-visually-hidden, style, script, template',
        );
        const computed = parent ? getComputedStyle(parent) : undefined;
        if (
          node.textContent?.trim()
          && parent
          && !hiddenOrIcon
          && computed
          && computed.display !== 'none'
          && computed.visibility === 'visible'
          && Number.parseFloat(computed.opacity) > 0
          && isIntendedRobotoStack(computed.fontFamily)
        ) {
          const range = document.createRange();
          range.selectNodeContents(node);
          let hasClippedLine = false;
          for (const line of Array.from(range.getClientRects())) {
            const left = Math.max(box.left, line.left);
            const top = Math.max(box.top, line.top);
            const right = Math.min(box.right, line.right);
            const bottom = Math.min(box.bottom, line.bottom);
            if (right > left && bottom > top) {
              hasClippedLine = true;
              lines.push({
                x: left,
                y: top + window.scrollY,
                width: right - left,
                height: bottom - top,
              });
            }
          }
          if (hasClippedLine) {
            runs.push({
              text: (node.textContent || '').normalize('NFC').replace(/\s+/gu, ' ').trim(),
              fontSize: Number.parseFloat(computed.fontSize),
              lineHeight: Number.parseFloat(computed.lineHeight),
              fontWeight: computed.fontWeight,
              color: computed.color,
            });
          }
          range.detach();
        }
        node = walker.nextNode();
      }
      return {
        semanticText: runs.map(({ text }) => text).join(' ').replace(/\s+/gu, ' ').trim(),
        textRuns: runs,
        textLineRects: lines,
      };
    };

    return Object.fromEntries(Object.entries(browserSelectors).map(([key, selector]) => {
      if (key === 'primary_nav') {
        const list = document.querySelector<HTMLElement>(selector);
        const toggle = document.querySelector<HTMLElement>('.site-menu-toggle');
        const source = window.innerWidth < 990 ? toggle : list;
        if (!source) throw new Error('Required primary navigation text proxy is missing.');
        return [key, [eligibleTextEvidence(source, window.innerWidth >= 990)]];
      }
      const elements = Array.from(document.querySelectorAll<HTMLElement>(selector));
      if (elements.length === 0) throw new Error(`Required visual text component is missing: ${selector}`);
      return [key, elements.map((element) => eligibleTextEvidence(element))];
    }));
  }, selectors);
}

async function measureHomepage(page: Page): Promise<Record<string, CandidateComponent[]>> {
  const geometry = await page.evaluate((selectors) => Object.fromEntries(
    Object.entries(selectors).map(([key, selector]) => {
      if (key === 'primary_nav') {
        const list = document.querySelector<HTMLElement>(selector);
        const toggle = document.querySelector<HTMLElement>('.site-menu-toggle');
        const source = window.innerWidth < 990 ? toggle : list;
        if (!source) throw new Error('Required primary navigation geometry proxy is missing.');
        const box = source.getBoundingClientRect();
        const style = getComputedStyle(source);
        const visibleLinkBoxes = window.innerWidth >= 990
          ? Array.from(source.querySelectorAll<HTMLElement>('a[href]'))
            .flatMap((link) => {
              const linkStyle = getComputedStyle(link);
              const rect = link.getBoundingClientRect();
              return (
                linkStyle.display !== 'none'
                && linkStyle.visibility === 'visible'
                && Number.parseFloat(linkStyle.opacity) > 0
                && rect.width > 0
                && rect.height > 0
              ) ? [rect] : [];
            })
          : [];
        const paintRect = visibleLinkBoxes.length > 0 ? {
          x: Math.min(...visibleLinkBoxes.map((rect) => rect.x)),
          y: Math.min(...visibleLinkBoxes.map((rect) => rect.y)) + window.scrollY,
          width: Math.max(...visibleLinkBoxes.map((rect) => rect.right))
            - Math.min(...visibleLinkBoxes.map((rect) => rect.left)),
          height: Math.max(...visibleLinkBoxes.map((rect) => rect.bottom))
            - Math.min(...visibleLinkBoxes.map((rect) => rect.top)),
        } : undefined;
        return [key, [{
          rect: {
            x: box.x,
            y: box.y + window.scrollY,
            width: box.width,
            height: window.innerWidth < 990 ? 0 : box.height,
          },
          paintRect,
          color: style.color,
          fontSize: Number.parseFloat(style.fontSize),
          lineHeight: Number.parseFloat(style.lineHeight),
          fontWeight: style.fontWeight,
        }]];
      }

      const components = Array.from(document.querySelectorAll<HTMLElement>(selector)).map((element) => {
        const box = element.getBoundingClientRect();
        const style = getComputedStyle(element);
        return {
          rect: {
            x: box.x,
            y: box.y + window.scrollY,
            width: box.width,
            height: box.height,
          },
          color: style.color,
          fontSize: Number.parseFloat(style.fontSize),
          lineHeight: Number.parseFloat(style.lineHeight),
          fontWeight: style.fontWeight,
        };
      });
      if (components.length === 0) throw new Error(`Required visual component is missing: ${selector}`);
      return [key, components];
    }),
  ), componentSelectors);
  const textEvidence = await measureCorrectedFontTextEvidence(page, componentSelectors);

  return Object.fromEntries(Object.entries(geometry).map(([component, candidates]) => [
    component,
    candidates.map((candidate, index) => ({
      ...candidate,
      semanticText: textEvidence[component][index].semanticText,
      textRuns: textEvidence[component][index].textRuns,
      textLineRects: textEvidence[component][index].textLineRects,
    })),
  ]));
}

interface SectionCapture {
  bytes: Buffer;
  path: string;
}

interface VisibleImageRect extends Rect {
  index: number;
  source: string;
}

async function capturePaintReadySections(
  page: Page,
  viewportKey: string,
  artifactDirectory: string,
): Promise<Record<string, SectionCapture>> {
  const captures: Record<string, SectionCapture> = {};
  for (const component of majorComponents) {
    const locator = page.locator(componentSelectors[component]).first();
    await locator.scrollIntoViewIfNeeded();
    const bytes = await locator.screenshot({
      animations: 'disabled',
      caret: 'hide',
      scale: 'css',
      type: 'png',
    });
    const imageRects = await locator.evaluate((section) => {
      const sectionRect = section.getBoundingClientRect();
      return Array.from(section.querySelectorAll<HTMLImageElement>('img')).flatMap((image) => {
        const style = getComputedStyle(image);
        const rect = image.getBoundingClientRect();
        if (
          !image.complete
          || image.naturalWidth <= 0
          || image.naturalHeight <= 0
          || style.display === 'none'
          || style.visibility !== 'visible'
          || Number.parseFloat(style.opacity) <= 0
          || rect.width <= 0
          || rect.height <= 0
        ) return [];
        const left = Math.max(sectionRect.left, rect.left);
        const top = Math.max(sectionRect.top, rect.top);
        const right = Math.min(sectionRect.right, rect.right);
        const bottom = Math.min(sectionRect.bottom, rect.bottom);
        if (right <= left || bottom <= top) return [];
        return [{
          x: left - sectionRect.left,
          y: top - sectionRect.top,
          width: right - left,
          height: bottom - top,
          source: image.currentSrc || image.src,
        }];
      });
    });
    for (const imageRect of imageRects) {
      const inset = Math.min(2, imageRect.width / 10, imageRect.height / 10);
      const paintedRatio = paintedPixelVariationRatio(bytes, {
        x: imageRect.x + inset,
        y: imageRect.y + inset,
        width: imageRect.width - (2 * inset),
        height: imageRect.height - (2 * inset),
      });
      expect.soft(
        paintedRatio,
        `${viewportKey} ${component} visible image did not paint: ${imageRect.source}`,
      ).toBeGreaterThan(0.02);
    }
    const capturePath = path.join(artifactDirectory, `section-${viewportKey}-${component}.png`);
    writeFileSync(capturePath, bytes, { flag: 'wx' });
    captures[component] = { bytes, path: capturePath };
  }
  await page.evaluate(() => window.scrollTo(0, 0));
  await page.waitForFunction(() => window.scrollY <= 1);
  return captures;
}

async function measureVisibleImageRects(page: Page): Promise<VisibleImageRect[]> {
  return page.locator('img').evaluateAll((images) => images.flatMap((image, index) => {
    const style = getComputedStyle(image);
    const rect = image.getBoundingClientRect();
    if (
      !image.complete
      || image.naturalWidth <= 0
      || image.naturalHeight <= 0
      || style.display === 'none'
      || style.visibility !== 'visible'
      || Number.parseFloat(style.opacity) <= 0
      || rect.width <= 0
      || rect.height <= 0
    ) return [];
    return [{
      index,
      x: rect.x,
      y: rect.y + window.scrollY,
      width: rect.width,
      height: rect.height,
      source: image.currentSrc || image.src,
    }];
  }));
}

async function capturePaintReadyFullPage(page: Page): Promise<{
  bytes: Buffer;
  attempts: number;
  imagePaintRatios: Array<{ source: string; ratio: number }>;
}> {
  const maximumAttempts = 3;
  for (let attempt = 1; attempt <= maximumAttempts; attempt += 1) {
    await page.evaluate(() => window.scrollTo(0, 0));
    await page.waitForFunction(() => window.scrollY <= 1);
    await page.evaluate(() => new Promise<void>((resolve) => requestAnimationFrame(() => (
      requestAnimationFrame(() => resolve())
    ))));
    const imageRects = await measureVisibleImageRects(page);
    const bytes = await page.screenshot({
      fullPage: true,
      animations: 'disabled',
      caret: 'hide',
      scale: 'css',
      type: 'png',
    });
    const imagePaintRatios = imageRects.map((imageRect) => {
      const inset = Math.min(2, imageRect.width / 10, imageRect.height / 10);
      return {
        source: imageRect.source,
        ratio: paintedPixelVariationRatio(bytes, {
          x: imageRect.x + inset,
          y: imageRect.y + inset,
          width: imageRect.width - (2 * inset),
          height: imageRect.height - (2 * inset),
        }),
      };
    });
    const unpainted = imagePaintRatios
      .map((evidence, index) => ({ ...evidence, image: imageRects[index] }))
      .filter(({ ratio }) => ratio <= 0.02);
    if (unpainted.length === 0) return { bytes, attempts: attempt, imagePaintRatios };
    if (attempt === maximumAttempts) {
      throw new Error(
        `Full-page capture left visible images unpainted after ${maximumAttempts} attempts: `
        + unpainted.map(({ source }) => source).join(', '),
      );
    }
    for (const { image } of unpainted) {
      const locator = page.locator('img').nth(image.index);
      await locator.scrollIntoViewIfNeeded();
      await locator.screenshot({ animations: 'disabled', caret: 'hide', scale: 'css', type: 'png' });
    }
  }
  throw new Error('Full-page capture readiness exhausted unexpectedly.');
}

test('visual comparator contract: live footer evidence keeps every stable text run', async ({ page }) => {
  test.skip(
    new URL(process.env.GOETZ_BASE_URL || 'http://localhost:8080').hostname === 'wordpress',
    'The internal diagnostic origin does not serve browser asset URLs; manager-backed local and public origins run this contract.',
  );
  await page.setViewportSize({ width: 1440, height: 900 });
  const response = await page.goto('/', { waitUntil: 'domcontentloaded' });
  expect(response?.ok()).toBeTruthy();
  await page.evaluate(async () => {
    if (document.fonts) await document.fonts.ready;
  });
  const evidence = await measureCorrectedFontTextEvidence(page, {
    footer_columns: componentSelectors.footer_columns,
  });
  const safeEvidence = evidence.footer_columns.map(({ textRuns }) => textRuns.map(({ text }) => text));
  console.info(`live-footer-text-evidence:${JSON.stringify(safeEvidence)}`);
  expect(safeEvidence[1]).toHaveLength(8);
  expect(safeEvidence[2]).toHaveLength(10);
});

for (const viewport of [
  { width: 1440, height: 900 },
  { width: 390, height: 844 },
  { width: 989, height: 844 },
  { width: 990, height: 844 },
]) {
  const viewportKey = `${viewport.width}x${viewport.height}`;

  test(`visual parity: homepage components and geometry match at ${viewportKey}`, async ({ page }) => {
    test.setTimeout(120_000);
    const fixture = validateFrozenFixtureSet(fixtureDirectory) as unknown as LegacyFixture;
    await page.setViewportSize(viewport);
    const response = await page.goto('/', { waitUntil: 'domcontentloaded' });
    expect(response?.ok()).toBeTruthy();
    await settlePage(page, {
      sectionSelectors,
      practiceItemSelector: '.goetz-practice-area-item',
      practiceIconSelector: '.goetz-practice-area-item__scale',
    });
    await page.waitForFunction(() => document.querySelector('.goetz-practice-areas')
      ?.classList.contains('is-animation-complete'));

    const candidate = await measureHomepage(page);
    const documentGeometry = await page.evaluate(() => ({
      width: document.documentElement.scrollWidth,
      height: document.documentElement.scrollHeight,
    }));
    const referenceDocument = fixture.viewports[viewportKey].document;
    expect(documentGeometry.width).toBe(viewport.width);
    absoluteDifference(
      documentGeometry.height,
      referenceDocument.scroll_height,
      referenceDocument.scroll_height * 0.05,
      `${viewportKey} document height`,
    );

    const referenceTextOverrides = new Set([
      'header',
      'header_main',
      'primary_nav',
      'cta',
      'footer',
      'footer_columns',
    ]);
    for (const [component, references] of Object.entries(fixture.components[viewportKey])) {
      for (let index = 0; index < references.length; index += 1) {
        const expectedText = expectedMaskedTextContract(viewportKey, component, index);
        const actualText: MaskedTextContract = {
          semanticText: normalizeSemanticText(candidate[component][index].semanticText),
          textRuns: candidate[component][index].textRuns,
        };
        expect.soft(
          maskedTextContractViolations(expectedText, actualText),
          `${viewportKey} ${component}[${index}] corrected-font semantic and nested style contract`,
        ).toEqual([]);

        // The frozen capture truncates parent text at 240 characters. Clean
        // entries must still match exactly or be an exact prefix of the
        // immutable human-readable contract; legacy inline-CSS/menu artifacts
        // are represented by the explicit parent compositions above.
        if (!referenceTextOverrides.has(component)) {
          const captured = normalizeSemanticText(references[index].text);
          expect.soft(
            expectedText.semanticText === captured || expectedText.semanticText.startsWith(captured),
            `${viewportKey} ${component}[${index}] immutable text contract is anchored to frozen capture`,
          ).toBe(true);
        }
      }
    }

    for (const component of majorComponents) {
      const references = fixture.components[viewportKey][component];
      expect.soft(
        majorGeometryViolations(candidate[component], references, {
          position: viewport.width < 990 ? 4 : 2,
          width: references[0].rect.width * 0.01,
          height: Math.max(16, references[0].rect.height * 0.03),
        }),
        `${viewportKey} ${component} count and absolute geometry`,
      ).toEqual([]);
    }

    const componentTolerance = viewport.width < 990 ? 4 : 2;
    for (const [component, references] of Object.entries(fixture.components[viewportKey])) {
      if (majorComponents.includes(component as typeof majorComponents[number])) continue;
      const actuals = candidate[component];
      expect(actuals, `${viewportKey} has a candidate selector for ${component}`).toBeTruthy();
      expect(actuals.length, `${viewportKey} ${component} count`).toBe(references.length);
      const parent = parentSectionFor(component);
      const actualParent = candidate[parent][0].rect;
      const referenceParent = fixture.components[viewportKey][parent][0].rect;
      if (component === 'primary_nav' && viewport.width >= 990) {
        const relativeActuals = actuals.map((actual) => ({
          rect: relativeToSection(actual.rect, actualParent),
          paintRect: actual.paintRect
            ? relativeToSection(actual.paintRect, actualParent)
            : undefined,
        }));
        const relativeReferences = references.map((reference) => ({
          rect: relativeToSection(reference.rect, referenceParent),
        }));
        expect.soft(
          navigationProxyGeometryViolations(
            relativeActuals,
            relativeReferences,
            { position: componentTolerance, width: componentTolerance, height: componentTolerance },
          ),
          `${viewportKey} ${component} zero-height legacy proxy and visible paint geometry`,
        ).toEqual([]);
        continue;
      }
      for (let index = 0; index < references.length; index += 1) {
        const actual = relativeToSection(actuals[index].rect, actualParent);
        const reference = relativeToSection(references[index].rect, referenceParent);
        for (const dimension of ['x', 'y', 'width', 'height'] as const) {
          absoluteDifference(
            actual[dimension],
            reference[dimension],
            componentTolerance,
            `${viewportKey} ${component}[${index}] ${dimension}`,
          );
        }
      }
    }

    for (const component of textComponents) {
      const references = fixture.components[viewportKey][component];
      for (let index = 0; index < references.length; index += 1) {
        absoluteDifference(
          candidate[component][index].fontSize,
          Number.parseFloat(references[index].style.font_size),
          1,
          `${viewportKey} ${component}[${index}] font size`,
        );
        absoluteDifference(
          candidate[component][index].lineHeight,
          Number.parseFloat(references[index].style.line_height),
          2,
          `${viewportKey} ${component}[${index}] line height`,
        );
        expect.soft(
          candidate[component][index].fontWeight,
          `${viewportKey} ${component}[${index}] font weight`,
        ).toBe(references[index].style.font_weight);
        expect.soft(
          candidate[component][index].color,
          `${viewportKey} ${component}[${index}] parent color`,
        ).toBe(references[index].style.color);
      }
    }

    const artifactDirectory = visualArtifactDirectory();
    const sectionCaptures = await capturePaintReadySections(page, viewportKey, artifactDirectory);
    const candidatePath = path.join(artifactDirectory, `candidate-home-${viewportKey}.png`);
    const fullPageCapture = await capturePaintReadyFullPage(page);
    writeFileSync(candidatePath, fullPageCapture.bytes, { flag: 'wx' });
    const candidatePage = fullPageCapture.bytes;
    const referencePage = readFileSync(path.join(fixtureDirectory, `home-${viewportKey}.png`));
    const rasterMetrics: Array<{
      component: string;
      index: number;
      ssim: number;
      changedPixels: number;
      changedPixelRatio: number;
      comparedPixels: number;
      passed: boolean;
      masks: string[];
      maskedPixels: number;
      maskedAreaRatio: number;
      diffPath: string;
    }> = [];
    const aggregateMaskCoverage: MaskCoverage = { maskedPixels: 0, totalPixels: 0 };

    for (const [component, references] of Object.entries(fixture.components[viewportKey])) {
      // Zero-area semantic wrappers have no raster pixels to compare; their
      // exact x/y/width/height contract is still enforced above.
      if (references.some(({ rect }) => rect.width <= 0 || rect.height <= 0)) continue;
      if (correctedFontPixelExclusions.has(component)) continue;
      for (let index = 0; index < references.length; index += 1) {
        const referenceRect = references[index].rect;
        const candidateRect = candidate[component][index].rect;
        const targetWidth = Math.max(1, Math.round(referenceRect.width));
        const targetHeight = Math.max(1, Math.round(referenceRect.height));
        const referenceCrop = extractAndResizePng(referencePage, referenceRect, targetWidth, targetHeight);
        const candidateCrop = extractAndResizePng(candidatePage, candidateRect, targetWidth, targetHeight);
        const fixtureMasks = fixture.dynamic_masks
          .filter((mask) => (
            mask.viewport === viewportKey
            && mask.component === component
            && mask.x >= referenceRect.x
            && mask.y >= referenceRect.y
            && mask.x + mask.width <= referenceRect.x + referenceRect.width
            && mask.y + mask.height <= referenceRect.y + referenceRect.height
          ))
          .map((mask) => ({
            name: mask.name,
            x: (mask.x - referenceRect.x) * (targetWidth / referenceRect.width),
            y: (mask.y - referenceRect.y) * (targetHeight / referenceRect.height),
            width: mask.width * (targetWidth / referenceRect.width),
            height: mask.height * (targetHeight / referenceRect.height),
          }));
        const correctedFontMasks = createCorrectedFontLineMasks(
          component,
          index,
          candidateRect,
          candidate[component][index].textLineRects,
          targetWidth,
          targetHeight,
        );
        const masks = [...fixtureMasks, ...correctedFontMasks];
        const coverage = maskCoverage(masks, targetWidth, targetHeight);
        aggregateMaskCoverage.maskedPixels += coverage.maskedPixels;
        aggregateMaskCoverage.totalPixels += coverage.totalPixels;
        expect.soft(
          maskBudgetViolations(coverage, { maskedPixels: 0, totalPixels: 1 }),
          `${viewportKey} ${component}[${index}] corrected-font component mask budget`,
        ).toEqual([]);
        const label = `${viewportKey} ${component}[${index}]`;
        const diffPath = path.join(artifactDirectory, `diff-${viewportKey}-${component}-${index}.png`);
        const comparison = comparePngBuffers(referenceCrop, candidateCrop, { masks, diffPath });
        rasterMetrics.push({
          component,
          index,
          ssim: comparison.ssim,
          changedPixels: comparison.changedPixels,
          changedPixelRatio: comparison.changedPixelRatio,
          comparedPixels: comparison.comparedPixels,
          passed: comparison.passed,
          masks: masks.map(({ name }) => name),
          maskedPixels: coverage.maskedPixels,
          maskedAreaRatio: coverage.maskedPixels / coverage.totalPixels,
          diffPath,
        });
        expect.soft(
          comparison.passed,
          `${label}: SSIM=${comparison.ssim.toFixed(5)}, changed=${(comparison.changedPixelRatio * 100).toFixed(3)}%, diff=${diffPath}`,
        ).toBe(true);
      }
    }
    expect.soft(
      maskBudgetViolations({ maskedPixels: 0, totalPixels: 1 }, aggregateMaskCoverage),
      `${viewportKey} corrected-font aggregate mask budget`,
    ).toEqual([]);
    writeFileSync(
      path.join(artifactDirectory, `metrics-${viewportKey}.json`),
      `${JSON.stringify({
        viewport: viewportKey,
        thresholds: {
          minimumSsim: MIN_COMPONENT_SSIM,
          maximumChangedPixelRatio: MAX_CHANGED_PIXEL_RATIO,
          antialiasPixelThreshold: ANTIALIAS_PIXEL_THRESHOLD,
        },
        candidatePath,
        fullPageCaptureAttempts: fullPageCapture.attempts,
        imagePaintRatios: fullPageCapture.imagePaintRatios,
        sectionCapturePaths: Object.fromEntries(
          Object.entries(sectionCaptures).map(([component, capture]) => [component, capture.path]),
        ),
        maskBudget: {
          maximumComponentRatio: MAX_COMPONENT_MASKED_AREA_RATIO,
          maximumAggregateRatio: MAX_TOTAL_MASKED_AREA_RATIO,
          aggregateMaskedPixels: aggregateMaskCoverage.maskedPixels,
          aggregateTotalPixels: aggregateMaskCoverage.totalPixels,
          aggregateMaskedAreaRatio: aggregateMaskCoverage.maskedPixels / aggregateMaskCoverage.totalPixels,
        },
        rasterMetrics,
      }, null, 2)}\n`,
      { flag: 'wx' },
    );
  });
}
