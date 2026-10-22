import { test } from '@e2e-dev/web';
import { credentials, expect } from 'e2e';

for (const role of ['hr', 'platform'] as const) {
  test.setup(`sign in as ${role}`, { sessions: [role] }, async ({ app, screen, session, browser }) => {
    const user = credentials.user(role);
    await app.open('/login');
    await screen.getByLabel('Email address').fill(user.username);
    await screen.getByLabel('Password').fill(user.password);
    await screen.getByRole('button', 'Log in').tap();
    await expect(browser).toHaveURL(role === 'hr' ? '/home' : '/platform');
    await session.save(role);
  });
}
