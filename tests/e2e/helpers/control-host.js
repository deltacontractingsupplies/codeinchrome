import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

/**
 * The control server to reach over SSH (root@<ip>). Never written in the
 * repository: CIC_CONTROL_SSH, else CIC_CONTROL_HOST from the operator's
 * uncommitted infra/hosts.local.env.
 */
export function controlSsh() {
  if (process.env.CIC_CONTROL_SSH) return process.env.CIC_CONTROL_SSH;
  const file = fileURLToPath(new URL('../../../infra/hosts.local.env', import.meta.url));
  let text = '';
  try {
    text = readFileSync(file, 'utf8');
  } catch {
    throw new Error('Set CIC_CONTROL_SSH=root@<control ip>, or create infra/hosts.local.env with CIC_CONTROL_HOST="h2:<ip>".');
  }
  const ip = text.match(/^CIC_CONTROL_HOST="[^:"]+:([^"]+)"/m)?.[1];
  if (!ip) throw new Error('infra/hosts.local.env has no CIC_CONTROL_HOST="h2:<ip>" line.');
  return `root@${ip}`;
}
