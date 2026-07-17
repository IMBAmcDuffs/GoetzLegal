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
import { findByLabel } from './helpers';

describe('Attorney Card editor', () => {
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
