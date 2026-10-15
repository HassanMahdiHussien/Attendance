import type { E2EConfig } from 'e2e';
import { web } from '@e2e-dev/web';
import { chatgpt } from 'e2e/oauth/chatgpt';

// Agent steps use the developer's ChatGPT subscription (`npx e2e login openai`), never the Anthropic budget.
// Runs inside the Sail container (`npm run test:e2e`), where the app answers on http://localhost and the
// local demo accounts share SEED_DEFAULT_PASSWORD from .env (passed in by the npm script, never committed).
const password = process.env.SEED_DEFAULT_PASSWORD ?? '';

export default {
  tests: 'tests/e2e/**/*.e2e.ts',
  agents: {
    default: {
      model: chatgpt('gpt-6-luna'),
      system: 'You are a thorough QA agent for an Arabic/English HR disciplinary system used in Iraq. '
        + 'Verify every outcome on screen. Never issue, approve, archive or delete anything unless the goal says so.',
    },
  },
  credentials: {
    hr: { username: 'company.admin@example.test', password },
    reporter: { username: 'reporter@example.test', password },
    committee: { username: 'committee.legal@example.test', password },
  },
  targets: [{
    engine: web(),
    app: { url: process.env.APP_URL ?? 'http://localhost' },
  }],
} satisfies E2EConfig;
