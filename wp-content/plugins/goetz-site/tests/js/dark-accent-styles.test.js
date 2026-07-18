import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const readBlockStyle = (block, file) =>
  readFileSync(resolve(__dirname, `../../blocks/${block}/${file}`), 'utf8');

describe('dark-surface accent styles', () => {
  test.each([
    ['hero', 'style.css', '.goetz-editor-preview--hero h1'],
    ['hero', 'view.css', '.goetz-hero h1'],
    ['cta', 'style.css', '.goetz-editor-preview--cta h2'],
    ['cta', 'view.css', '.goetz-cta h2'],
    ['practice-areas', 'style.css', '.goetz-practice-areas__heading'],
  ])('%s %s gives emphasized heading text the accessible production accent', (
    block,
    file,
    selector
  ) => {
    const css = readBlockStyle(block, file);
    const escapedSelector = selector.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

    expect(css).toMatch(
      new RegExp(
        `${escapedSelector} (?:strong|b),[\\s\\S]*?${escapedSelector} (?:b|strong) \\{[^}]*color:\\s*#926ac0;`,
        'i'
      )
    );
  });

  test.each([
    ['hero', 'style.css'],
    ['hero', 'view.css'],
    ['cta', 'style.css'],
    ['cta', 'view.css'],
  ])('%s %s does not retain the low-contrast legacy heading accent', (block, file) => {
    const css = readBlockStyle(block, file);

    expect(css).not.toMatch(
      /(?:h1|h2) (?:b|strong),[\s\S]*?(?:h1|h2) (?:strong|b) \{[^}]*color:\s*#7951a9;/i
    );
  });
});
