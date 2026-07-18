jest.mock('@wordpress/block-editor', () => ({
  InspectorControls: 'InspectorControls',
  RichText: 'RichText',
  URLInputButton: 'URLInputButton',
  MediaUpload: 'MediaUpload',
  MediaUploadCheck: 'MediaUploadCheck',
  useBlockProps: jest.fn((props) => props),
}));
jest.mock('@wordpress/components', () => ({
  BaseControl: 'BaseControl',
  Button: 'Button',
  PanelBody: 'PanelBody',
  TextControl: 'TextControl',
  ToggleControl: 'ToggleControl',
}));
jest.mock('@wordpress/element', () => ({
  Fragment: 'Fragment',
  createElement: (...args) => require('./helpers').createElement(...args),
}));
jest.mock('@wordpress/i18n', () => ({ __: (value) => value }));

import { AttorneyCardEdit, save } from '../../src/blocks/attorney-card/edit';
import metadata from '../../blocks/attorney-card/block.json';
import { findAll, findByLabel } from './helpers';

describe('Attorney Card editor', () => {
  test('offers a dedicated profile-page style without changing the default card', () => {
    expect(metadata.styles).toEqual([
      { name: 'default', label: 'Card', isDefault: true },
      { name: 'profile', label: 'Attorney Profile' },
    ]);
  });

  test('renders the profile style as an attorney-profile preview', () => {
    const tree = AttorneyCardEdit({
      attributes: {
        className: 'is-style-profile',
        name: 'James L. Goetz',
        role: 'Partner',
        bio: 'Attorney biography',
        email: 'james@example.test',
        imageId: 8,
        imageUrl: 'https://example.test/james.jpg',
        imageAlt: 'James Goetz',
        profileUrl: '/james-l-goetz/',
      },
      setAttributes: jest.fn(),
    });
    const [preview] = findAll(
      tree,
      (node) => node.type === 'article' && node.props?.className?.includes('goetz-editor-preview--attorney-card')
    );

    expect(preview.props.className).toContain('goetz-editor-preview--attorney-profile');
    expect(preview.props.className).toContain('goetz-attorney-card');
    expect(findAll(tree, (node) => node.props?.className === 'goetz-attorney-card__image')).toHaveLength(1);
    expect(findAll(tree, (node) => node.props?.className === 'goetz-attorney-card__body')).toHaveLength(1);
    expect(findAll(tree, (node) => node.props?.className === 'goetz-attorney-card__mark')).toHaveLength(1);
    expect(findAll(tree, (node) => node.props?.className === 'goetz-attorney-card__links')).toHaveLength(1);
    expect(findByLabel(tree, 'Attorney name').props.tagName).toBe('h2');
  });

  test('renders a useful standalone card preview while preserving its H2 hierarchy', () => {
    const tree = AttorneyCardEdit({
      attributes: {
        className: '',
        name: 'Jane Goetz',
        role: 'Partner',
        bio: 'Attorney biography',
        imageUrl: 'https://example.test/jane.jpg',
      },
      setAttributes: jest.fn(),
    });
    const [preview] = findAll(
      tree,
      (node) => node.type === 'article' && node.props?.className?.includes('goetz-editor-preview--attorney-card')
    );

    expect(preview.props.className).not.toContain('goetz-editor-preview--attorney-profile');
    expect(preview.props.className).toContain('goetz-attorney-card');
    expect(findAll(tree, (node) => node.props?.className === 'goetz-attorney-card__image')).toHaveLength(1);
    expect(findAll(tree, (node) => node.props?.className === 'goetz-attorney-card__body')).toHaveLength(1);
    expect(findAll(tree, (node) => node.props?.className === 'goetz-attorney-card__mark')).toHaveLength(0);
    expect(findAll(tree, (node) => node.props?.className === 'goetz-attorney-card__links')).toHaveLength(0);
    expect(findByLabel(tree, 'Attorney name').props.tagName).toBe('h2');
  });

  test.each([
    ['explicit', 'Trial Attorneys', 'h3'],
    ['default', require('../../blocks/attorney-grid/block.json').attributes.heading.default, 'h3'],
    ['blank', '', 'h2'],
    ['whitespace', '   ', 'h2'],
    ['non-scalar', 42, 'h2'],
  ])('uses the semantic name heading for %s grid context', (_case, gridHeading, tagName) => {
    const tree = AttorneyCardEdit({
      attributes: {
        className: 'is-style-profile',
        name: 'Grid Attorney',
      },
      context: { 'goetz/attorneyGridHeading': gridHeading },
      setAttributes: jest.fn(),
    });

    expect(findByLabel(tree, 'Attorney name').props.tagName).toBe(tagName);
  });

  test('previews the given and middle names with the grid accent treatment', () => {
    const tree = AttorneyCardEdit({
      attributes: { name: '  James   L.   Goetz  ' },
      context: { 'goetz/attorneyGridHeading': 'Attorneys' },
      setAttributes: jest.fn(),
    });
    const nameField = findByLabel(tree, 'Attorney name');
    const accents = findAll(
      tree,
      (node) => node.props?.className === 'goetz-attorney-card__accent-preview'
    );

    expect(nameField.props.className).toContain('goetz-attorney-card__heading');
    expect(accents).toHaveLength(1);
    expect(accents[0].type).toBe('span');
    expect(accents[0].props['aria-hidden']).toBe('true');
    expect(accents[0].props.children).toBe('James L.');
  });

  test('renders every current field and changes only the edited attribute', () => {
    const attributes = {
      name: 'Jane Goetz',
      role: 'Partner',
      bio: 'Trial attorney',
      email: 'jane@example.test',
      imageId: 8,
      imageUrl: 'https://example.test/jane.jpg',
      imageAlt: 'Jane portrait',
      profileUrl: '/jane/',
      profileNewTab: false,
    };
    const setAttributes = jest.fn();
    const tree = AttorneyCardEdit({ attributes, setAttributes });

    expect(findByLabel(tree, 'Attorney name').props.value).toBe('Jane Goetz');
    expect(findByLabel(tree, 'Attorney role').props.value).toBe('Partner');
    expect(findByLabel(tree, 'Attorney biography').props.value).toBe('Trial attorney');
    expect(findByLabel(tree, 'Attorney email').props.value).toBe('jane@example.test');

    findByLabel(tree, 'Attorney biography').props.onChange('Changed biography');
    expect(setAttributes).toHaveBeenLastCalledWith({ bio: 'Changed biography' });

    findByLabel(tree, 'Attorney profile link').props.onChange({
      url: '/changed-profile/',
      newTab: true,
    });
    expect(setAttributes).toHaveBeenLastCalledWith({
      profileUrl: '/changed-profile/',
      profileNewTab: true,
    });
    expect(setAttributes).not.toHaveBeenCalledWith(
      expect.objectContaining({ name: expect.anything() })
    );
  });

  test('maps media changes without dropping the fallback fields', () => {
    const setAttributes = jest.fn();
    const tree = AttorneyCardEdit({ attributes: {}, setAttributes });

    findByLabel(tree, 'Attorney image').props.onChange({
      imageId: 22,
      imageUrl: 'https://example.test/portrait.jpg',
      imageAlt: 'Portrait',
    });
    expect(setAttributes).toHaveBeenLastCalledWith({
      imageId: 22,
      imageUrl: 'https://example.test/portrait.jpg',
      imageAlt: 'Portrait',
    });
  });

  test('is dynamic', () => {
    expect(save()).toBeNull();
  });
});
