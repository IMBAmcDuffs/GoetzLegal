jest.mock('@wordpress/block-editor', () => ({
  useBlockProps: jest.fn(() => ({})),
}));
jest.mock('@wordpress/element', () => ({
  createElement: jest.fn(),
}));

import { registerStableBlocks, stableBlocks } from './stable-blocks';

const expectedAttributes = {
  'goetz/attorney-card': {
    name: { type: 'string', default: '' },
    role: { type: 'string', default: '' },
    bio: { type: 'string', default: '' },
    email: { type: 'string', default: '' },
    imageUrl: { type: 'string', default: '' },
    imageAlt: { type: 'string', default: '' },
    profileUrl: { type: 'string', default: '' },
  },
  'goetz/cta': {
    eyebrow: { type: 'string', default: 'WE ARE AN EXPERIENCED TEAM' },
    heading: { type: 'string', default: 'NEED A LAWYER?' },
    buttonText: { type: 'string', default: 'Get Consultation' },
    buttonUrl: { type: 'string', default: '/contact/' },
  },
  'goetz/faq-list': {
    items: { type: 'array', default: [] },
  },
  'goetz/hero': {
    eyebrow: { type: 'string', default: 'GoetzLegal.com' },
    heading: {
      type: 'string',
      default: 'A law firm with seasoned trial attorneys in Fort Myers, Florida.',
    },
    content: { type: 'string', default: '' },
    imageUrl: { type: 'string', default: '' },
    imageAlt: { type: 'string', default: '' },
    buttonText: { type: 'string', default: 'Learn More About Us' },
    buttonUrl: { type: 'string', default: '/james-l-goetz/' },
  },
  'goetz/resource-links': {
    groups: { type: 'array', default: [] },
    imageUrl: { type: 'string', default: '' },
    imageAlt: { type: 'string', default: '' },
  },
};

describe('stable Goetz blocks', () => {
  test('keeps the permanent five-name registry and exact saved schemas', () => {
    expect(stableBlocks.map(({ name }) => name)).toEqual(Object.keys(expectedAttributes));

    stableBlocks.forEach((metadata) => {
      expect(metadata.attributes).toEqual(expectedAttributes[metadata.name]);
      expect(metadata.textdomain).toBe('goetz-site');
      expect(metadata.editorScript).toBe('goetz-site-block-editor');
    });
  });

  test('registers every stable dynamic block with one native placeholder editor', () => {
    const registrations = [];

    registerStableBlocks((name, settings) => registrations.push({ name, settings }));

    expect(registrations.map(({ name }) => name)).toEqual(Object.keys(expectedAttributes));
    registrations.forEach(({ settings }) => {
      expect(settings.edit).toBeInstanceOf(Function);
      expect(settings.save()).toBeNull();
    });
    expect(new Set(registrations.map(({ settings }) => settings.edit)).size).toBe(1);
  });
});
