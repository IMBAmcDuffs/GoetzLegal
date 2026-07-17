jest.mock('@wordpress/block-editor', () => {
  const InnerBlocks = function InnerBlocks() {};
  InnerBlocks.ButtonBlockAppender = 'InnerBlocks.ButtonBlockAppender';
  InnerBlocks.Content = 'InnerBlocks.Content';

  return {
    InnerBlocks,
    InspectorControls: 'InspectorControls',
    MediaUpload: 'MediaUpload',
    MediaUploadCheck: 'MediaUploadCheck',
    RichText: 'RichText',
    URLInput: 'URLInput',
    URLInputButton: 'URLInputButton',
    useBlockProps: jest.fn(() => ({})),
  };
});
jest.mock('@wordpress/components', () => ({
  BaseControl: 'BaseControl',
  Button: 'Button',
  PanelBody: 'PanelBody',
  TextControl: 'TextControl',
  ToggleControl: 'ToggleControl',
}));
jest.mock('@wordpress/element', () => ({
  Fragment: 'Fragment',
  createElement: (type, props, ...children) => ({
    type,
    props: { ...(props || {}), children },
  }),
  useRef: jest.fn((value) => ({ current: value })),
}));
jest.mock('@wordpress/i18n', () => ({
  __: (value) => value,
  sprintf: (template, ...values) =>
    values.reduce((result, value) => result.replace('%d', value), template),
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
    imageId: { type: 'number', default: 0 },
    profileNewTab: { type: 'boolean', default: false },
  },
  'goetz/cta': {
    eyebrow: { type: 'string', default: 'WE ARE AN EXPERIENCED TEAM' },
    heading: { type: 'string', default: 'NEED A LAWYER?' },
    buttonText: { type: 'string', default: 'Get Consultation' },
    buttonUrl: { type: 'string', default: '/contact/' },
    backgroundImageId: { type: 'number', default: 0 },
    backgroundImageUrl: { type: 'string', default: '' },
    buttonNewTab: { type: 'boolean', default: false },
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
    imageId: { type: 'number', default: 0 },
    buttonNewTab: { type: 'boolean', default: false },
  },
  'goetz/practice-area-item': {
    label: { type: 'string', default: '' },
  },
  'goetz/practice-areas': {
    heading: {
      type: 'string',
      default: 'Providing <strong>Legal Advice</strong> in:',
    },
    backgroundImageId: { type: 'number', default: 0 },
    backgroundImageUrl: { type: 'string', default: '' },
    backgroundImageAlt: { type: 'string', default: '' },
    scaleImageId: { type: 'number', default: 0 },
    scaleImageUrl: { type: 'string', default: '' },
    scaleImageAlt: { type: 'string', default: '' },
  },
  'goetz/resource-links': {
    groups: { type: 'array', default: [] },
    imageUrl: { type: 'string', default: '' },
    imageAlt: { type: 'string', default: '' },
    imageId: { type: 'number', default: 0 },
  },
  'goetz/welcome': {
    leftImageId: { type: 'number', default: 0 },
    leftImageUrl: { type: 'string', default: '' },
    leftImageAlt: { type: 'string', default: '' },
    rightImageId: { type: 'number', default: 0 },
    rightImageUrl: { type: 'string', default: '' },
    rightImageAlt: { type: 'string', default: '' },
    heading: {
      type: 'string',
      default:
        '<strong>Mr. Goetz welcomes</strong> you to browse this site to learn more about his firm and get information.',
    },
    contentPrefix: {
      type: 'string',
      default: 'If you would like to speak with Mr. Goetz, please call',
    },
    phoneLabel: { type: 'string', default: '' },
    phoneUrl: { type: 'string', default: '' },
    contentJoin: { type: 'string', default: 'or contact the firm' },
    onlineLabel: { type: 'string', default: 'online' },
    onlineUrl: { type: 'string', default: '' },
  },
};

describe('stable Goetz blocks', () => {
  test('keeps the registered editor names and exact saved schemas', () => {
    expect(stableBlocks.map(({ name }) => name)).toEqual(Object.keys(expectedAttributes));

    stableBlocks.forEach((metadata) => {
      expect(metadata.apiVersion).toBe(3);
      expect(metadata.attributes).toEqual(expectedAttributes[metadata.name]);
      expect(metadata.textdomain).toBe('goetz-site');
      expect(metadata.editorScript).toBe('goetz-site-block-editor');
      expect(metadata.supports).toEqual(
        metadata.name === 'goetz/practice-area-item'
          ? { html: false, inserter: false }
          : { html: false }
      );
    });

    const welcome = stableBlocks.find(({ name }) => name === 'goetz/welcome');
    expect(welcome.render).toBe('file:./render.php');
    expect(welcome.style).toBe('file:./style.css');
    expect(welcome).not.toHaveProperty('viewScript');

    const practiceAreas = stableBlocks.find(
      ({ name }) => name === 'goetz/practice-areas'
    );
    expect(practiceAreas.providesContext).toEqual({
      'goetz/scaleImageId': 'scaleImageId',
      'goetz/scaleImageUrl': 'scaleImageUrl',
      'goetz/scaleImageAlt': 'scaleImageAlt',
    });
    expect(practiceAreas.viewScript).toBe('file:./view.js');

    const practiceAreaItem = stableBlocks.find(
      ({ name }) => name === 'goetz/practice-area-item'
    );
    expect(practiceAreaItem.parent).toEqual(['goetz/practice-areas']);
    expect(practiceAreaItem.usesContext).toEqual([
      'goetz/scaleImageId',
      'goetz/scaleImageUrl',
      'goetz/scaleImageAlt',
    ]);
  });

  test('registers native editors and preserves only the parent InnerBlocks content', () => {
    const registrations = [];

    registerStableBlocks((name, settings) => registrations.push({ name, settings }));

    expect(registrations.map(({ name }) => name)).toEqual(Object.keys(expectedAttributes));
    registrations.forEach(({ name, settings }) => {
      expect(settings.edit).toBeInstanceOf(Function);
      if (name === 'goetz/practice-areas') {
        expect(settings.save()).toEqual(expect.objectContaining({
          type: 'InnerBlocks.Content',
        }));
      } else {
        expect(settings.save()).toBeNull();
      }
    });
    expect(new Set(registrations.map(({ settings }) => settings.edit)).size).toBe(8);
  });
});
