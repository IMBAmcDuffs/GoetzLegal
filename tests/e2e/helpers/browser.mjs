const loopbackHosts = new Set(['localhost', '127.0.0.1', '::1']);

export function wordpressLaunchOptions(
  baseURL,
  useComposeHostGateway = process.env.GOETZ_COMPOSE_HOST_GATEWAY === '1',
) {
  let hostname;
  try {
    hostname = new URL(baseURL).hostname;
  } catch {
    return {};
  }

  if (!useComposeHostGateway || !loopbackHosts.has(hostname)) {
    return {};
  }

  return {
    args: [
      '--host-resolver-rules=MAP localhost host.docker.internal,MAP 127.0.0.1 host.docker.internal,MAP ::1 host.docker.internal',
    ],
  };
}
