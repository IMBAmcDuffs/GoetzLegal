const loopbackHosts = new Set(['localhost', '127.0.0.1', '::1', '[::1]']);

export function isLoopbackURL(value) {
  let hostname;
  try {
    hostname = typeof value === 'string' ? new URL(value).hostname : value.hostname;
  } catch {
    return false;
  }
  return loopbackHosts.has(hostname);
}

export function wordpressLaunchOptions(
  baseURL,
  useComposeHostGateway = process.env.GOETZ_COMPOSE_HOST_GATEWAY === '1',
) {
  if (!useComposeHostGateway || !isLoopbackURL(baseURL)) {
    return {};
  }

  return {
    args: [
      '--host-resolver-rules=MAP localhost host.docker.internal,MAP 127.0.0.1 host.docker.internal,MAP ::1 host.docker.internal',
    ],
  };
}
