import { test } from '@e2e-dev/web';
import { expect } from 'e2e';

test('HR can reach the attendance placeholder and employee list', { session: 'hr' }, async ({ app, agent, browser }) => {
  await app.open('/home');
  await agent.assert('the page shows the attendance dashboard placeholder');
  await app.open('/employees');
  await agent.assert('the employee list is available');
  await expect(browser).toHaveURL('/employees');
});

test('switching to Arabic turns the workspace right-to-left with Western digits', { session: 'hr' }, async ({ app, agent, browser }) => {
  await app.open('/home');
  await agent.act('switch the interface language to Arabic using the account menu');
  await expect(browser.locator('html')).toHaveAttribute('dir', 'rtl');
  await agent.assert('the navigation labels are in Arabic and every number visible on the page uses Western digits 0-9');
  await agent.act('switch the interface language back to English');
  await expect(browser.locator('html')).toHaveAttribute('dir', 'ltr');
});
