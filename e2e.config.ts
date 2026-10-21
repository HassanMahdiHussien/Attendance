import type { E2EConfig } from 'e2e';
import { web } from '@e2e-dev/web';
import { chatgpt } from 'e2e/oauth/chatgpt';

const password = process.env.SEED_DEFAULT_PASSWORD ?? '';

export default {
  tests: 'tests/e2e/**/*.e2e.ts',
  agents: {
    default: {
      model: chatgpt('gpt-6-luna'),
      system: 'You are a thorough QA agent for an Arabic/English attendance workspace used in Iraq. '
        + 'Verify every outcome on screen.',
    },
  },
  credentials: {
    hr: { username: 'hr@example.test', password },
    platform: { username: 'platform.admin@example.test', password },
  },
  targets: [{
    engine: web(),
    app: { url: process.env.APP_URL ?? 'http://localhost' },
  }],
} satisfies E2EConfig;
