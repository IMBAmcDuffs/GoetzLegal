let keySequence = 0;

export function createRepeaterKey(prefix) {
  keySequence += 1;
  return `${prefix}-${keySequence}`;
}

export function syncRepeaterKeys(currentKeys, length, prefix) {
  const keys = Array.isArray(currentKeys) ? currentKeys.slice(0, length) : [];

  while (keys.length < length) {
    keys.push(createRepeaterKey(prefix));
  }

  return keys;
}

export function replaceAt(items, index, replacement) {
  return items.map((item, itemIndex) =>
    itemIndex === index ? replacement : item
  );
}

export function removeAt(items, index) {
  return items.filter((item, itemIndex) => itemIndex !== index);
}

export function moveAt(items, fromIndex, toIndex) {
  if (
    fromIndex === toIndex ||
    fromIndex < 0 ||
    toIndex < 0 ||
    fromIndex >= items.length ||
    toIndex >= items.length
  ) {
    return items.slice();
  }

  const nextItems = items.slice();
  const [movedItem] = nextItems.splice(fromIndex, 1);
  nextItems.splice(toIndex, 0, movedItem);
  return nextItems;
}
