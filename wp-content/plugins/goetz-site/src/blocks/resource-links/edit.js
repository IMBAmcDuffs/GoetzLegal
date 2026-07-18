import { InspectorControls, RichText, useBlockProps } from '@wordpress/block-editor';
import { Button, PanelBody } from '@wordpress/components';
import { createElement, Fragment, useRef } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { LinkControl } from '../../components/link-control';
import { MediaControl } from '../../components/media-control';
import {
  createRepeaterKey,
  moveAt,
  removeAt,
  replaceAt,
  syncRepeaterKeys,
} from '../../components/repeater';

function normalizeLinks(value) {
  if (!Array.isArray(value)) {
    return [];
  }

  return value
    .filter((link) => link && typeof link === 'object' && !Array.isArray(link))
    .map((link) => {
      const normalized = {
        label: typeof link.label === 'string' ? link.label : '',
        url: typeof link.url === 'string' ? link.url : '',
      };

      if (Object.prototype.hasOwnProperty.call(link, 'newTab')) {
        normalized.newTab = Boolean(link.newTab);
      }

      return normalized;
    });
}

function normalizeGroups(value) {
  if (!Array.isArray(value)) {
    return [];
  }

  return value
    .filter((group) => group && typeof group === 'object' && !Array.isArray(group))
    .map((group) => ({
      heading: typeof group.heading === 'string' ? group.heading : '',
      links: normalizeLinks(group.links),
    }));
}

export function ResourceLinksEdit({ attributes = {}, setAttributes }) {
  const groups = normalizeGroups(attributes.groups);
  const groupKeys = useRef(null);
  const linkKeys = useRef(null);
  groupKeys.current = syncRepeaterKeys(
    groupKeys.current,
    groups.length,
    'resource-group'
  );
  linkKeys.current = Array.isArray(linkKeys.current)
    ? linkKeys.current.slice(0, groups.length)
    : [];
  groups.forEach((group, groupIndex) => {
    linkKeys.current[groupIndex] = syncRepeaterKeys(
      linkKeys.current[groupIndex],
      group.links.length,
      `resource-group-${groupIndex + 1}-link`
    );
  });

  const blockProps = useBlockProps({
    className: 'goetz-editor-preview goetz-editor-preview--resource-links',
  });
  const setGroups = (nextGroups) => setAttributes({ groups: nextGroups });
  const replaceGroup = (groupIndex, group) =>
    setGroups(replaceAt(groups, groupIndex, group));
  const updateGroup = (groupIndex, changes) =>
    replaceGroup(groupIndex, { ...groups[groupIndex], ...changes });
  const updateLink = (groupIndex, linkIndex, changes) => {
    const group = groups[groupIndex];
    updateGroup(groupIndex, {
      links: replaceAt(group.links, linkIndex, {
        ...group.links[linkIndex],
        ...changes,
      }),
    });
  };
  const addGroup = () => {
    groupKeys.current = [...groupKeys.current, createRepeaterKey('resource-group')];
    linkKeys.current = [...linkKeys.current, []];
    setGroups([...groups, { heading: '', links: [] }]);
  };
  const deleteGroup = (groupIndex) => {
    groupKeys.current = removeAt(groupKeys.current, groupIndex);
    linkKeys.current = removeAt(linkKeys.current, groupIndex);
    setGroups(removeAt(groups, groupIndex));
  };
  const moveGroup = (fromIndex, toIndex) => {
    groupKeys.current = moveAt(groupKeys.current, fromIndex, toIndex);
    linkKeys.current = moveAt(linkKeys.current, fromIndex, toIndex);
    setGroups(moveAt(groups, fromIndex, toIndex));
  };
  const addLink = (groupIndex) => {
    linkKeys.current[groupIndex] = [
      ...linkKeys.current[groupIndex],
      createRepeaterKey(`resource-group-${groupIndex + 1}-link`),
    ];
    updateGroup(groupIndex, {
      links: [
        ...groups[groupIndex].links,
        { label: '', url: '', newTab: false },
      ],
    });
  };
  const deleteLink = (groupIndex, linkIndex) => {
    linkKeys.current[groupIndex] = removeAt(
      linkKeys.current[groupIndex],
      linkIndex
    );
    updateGroup(groupIndex, {
      links: removeAt(groups[groupIndex].links, linkIndex),
    });
  };
  const moveLink = (groupIndex, fromIndex, toIndex) => {
    linkKeys.current[groupIndex] = moveAt(
      linkKeys.current[groupIndex],
      fromIndex,
      toIndex
    );
    updateGroup(groupIndex, {
      links: moveAt(groups[groupIndex].links, fromIndex, toIndex),
    });
  };

  return (
    <Fragment>
      <InspectorControls>
        <PanelBody title={__('Resource Links image', 'goetz-site')} initialOpen>
          <MediaControl
            label={__('Resource Links image', 'goetz-site')}
            imageId={attributes.imageId || 0}
            imageUrl={attributes.imageUrl || ''}
            imageAlt={attributes.imageAlt || ''}
            onChange={(media) => setAttributes(media)}
          />
        </PanelBody>
      </InspectorControls>
      <section {...blockProps}>
        <div className="goetz-editor-repeater">
          {groups.map((group, groupIndex) => {
            const groupNumber = groupIndex + 1;

            return (
              <section
                className="goetz-editor-repeater__item"
                key={groupKeys.current[groupIndex]}
              >
                <RichText
                  tagName="h3"
                  aria-label={sprintf(
                    __('Resource group %d heading', 'goetz-site'),
                    groupNumber
                  )}
                  value={group.heading}
                  allowedFormats={[]}
                  placeholder={__('Enter a group heading', 'goetz-site')}
                  onChange={(heading) => updateGroup(groupIndex, { heading })}
                />
                {group.links.map((link, linkIndex) => {
                  const linkNumber = linkIndex + 1;
                  const destinationLabel = sprintf(
                    __('Resource group %d link %d destination', 'goetz-site'),
                    groupNumber,
                    linkNumber
                  );

                  return (
                    <div
                      className="goetz-editor-repeater__item goetz-editor-repeater__item--nested"
                      key={linkKeys.current[groupIndex][linkIndex]}
                    >
                      <RichText
                        tagName="span"
                        aria-label={sprintf(
                          __('Resource group %d link %d label', 'goetz-site'),
                          groupNumber,
                          linkNumber
                        )}
                        value={link.label}
                        allowedFormats={[]}
                        placeholder={__('Enter link text', 'goetz-site')}
                        onChange={(label) =>
                          updateLink(groupIndex, linkIndex, { label })
                        }
                      />
                      <LinkControl
                        label={destinationLabel}
                        url={link.url}
                        newTab={link.newTab === true}
                        onChange={({ url, newTab }) =>
                          updateLink(groupIndex, linkIndex, { url, newTab })
                        }
                      />
                      <div className="goetz-editor-repeater__controls">
                        <Button
                          variant="secondary"
                          disabled={linkIndex === 0}
                          aria-label={sprintf(
                            __('Move resource group %d link %d up', 'goetz-site'),
                            groupNumber,
                            linkNumber
                          )}
                          onClick={() => moveLink(groupIndex, linkIndex, linkIndex - 1)}
                        >
                          {__('Move link up', 'goetz-site')}
                        </Button>
                        <Button
                          variant="secondary"
                          disabled={linkIndex === group.links.length - 1}
                          aria-label={sprintf(
                            __('Move resource group %d link %d down', 'goetz-site'),
                            groupNumber,
                            linkNumber
                          )}
                          onClick={() => moveLink(groupIndex, linkIndex, linkIndex + 1)}
                        >
                          {__('Move link down', 'goetz-site')}
                        </Button>
                        <Button
                          variant="tertiary"
                          isDestructive
                          aria-label={sprintf(
                            __('Remove resource group %d link %d', 'goetz-site'),
                            groupNumber,
                            linkNumber
                          )}
                          onClick={() => deleteLink(groupIndex, linkIndex)}
                        >
                          {__('Remove link', 'goetz-site')}
                        </Button>
                      </div>
                    </div>
                  );
                })}
                <Button
                  variant="secondary"
                  aria-label={sprintf(
                    __('Add link to resource group %d', 'goetz-site'),
                    groupNumber
                  )}
                  onClick={() => addLink(groupIndex)}
                >
                  {__('Add link', 'goetz-site')}
                </Button>
                <div className="goetz-editor-repeater__controls">
                  <Button
                    variant="secondary"
                    disabled={groupIndex === 0}
                    aria-label={sprintf(
                      __('Move resource group %d up', 'goetz-site'),
                      groupNumber
                    )}
                    onClick={() => moveGroup(groupIndex, groupIndex - 1)}
                  >
                    {__('Move group up', 'goetz-site')}
                  </Button>
                  <Button
                    variant="secondary"
                    disabled={groupIndex === groups.length - 1}
                    aria-label={sprintf(
                      __('Move resource group %d down', 'goetz-site'),
                      groupNumber
                    )}
                    onClick={() => moveGroup(groupIndex, groupIndex + 1)}
                  >
                    {__('Move group down', 'goetz-site')}
                  </Button>
                  <Button
                    variant="tertiary"
                    isDestructive
                    aria-label={sprintf(
                      __('Remove resource group %d', 'goetz-site'),
                      groupNumber
                    )}
                    onClick={() => deleteGroup(groupIndex)}
                  >
                    {__('Remove group', 'goetz-site')}
                  </Button>
                </div>
              </section>
            );
          })}
        </div>
        <Button
          variant="primary"
          aria-label={__('Add resource group', 'goetz-site')}
          onClick={addGroup}
        >
          {__('Add resource group', 'goetz-site')}
        </Button>
      </section>
    </Fragment>
  );
}

export function save() {
  return null;
}
