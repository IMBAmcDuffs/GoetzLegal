export function findAll(node, predicate, matches = []) {
  if (Array.isArray(node)) {
    node.forEach((child) => findAll(child, predicate, matches));
    return matches;
  }

  if (!node || typeof node !== 'object') {
    return matches;
  }

  if (predicate(node)) {
    matches.push(node);
  }

  const children = node.props?.children;
  const childList = Array.isArray(children) ? children : [children];
  childList.forEach((child) => findAll(child, predicate, matches));

  return matches;
}

export function findByLabel(node, label) {
  const [match] = findAll(
    node,
    (candidate) =>
      candidate.props?.label === label || candidate.props?.['aria-label'] === label
  );

  if (!match) {
    throw new Error(`Could not find editor control labelled "${label}".`);
  }

  return match;
}

export function createElement(type, props, ...children) {
  return {
    type,
    props: {
      ...(props || {}),
      children,
    },
  };
}
