import { test } from '@e2e-dev/web';
import { expect } from 'e2e';

test('a reporter submits a violation report and sees it in their list', { session: 'reporter' }, async ({ app, agent, screen, browser }) => {
  // The model does not know today's date, so the incident time is given exactly.
  const yesterday = new Date(Date.now() - 86_400_000).toISOString().slice(0, 10);
  await app.open('/reports/new');
  await agent.act('fill the report form for a safety violation at the first available branch and location, '
    + 'for any listed employee, with the description "{d}", leaving the incident date and time empty',
  { params: { d: 'Worker entered the production line without a helmet (e2e test).' } });
  await screen.getByLabel('Incident date and time').fill(`${yesterday}T09:00`);
  await screen.getByRole('button', 'Send report').tap();
  await expect(browser).toHaveURL('/reports');
  await expect(screen.getByRole('status')).toContainText('Report sent');
  await agent.assert('the list contains a report with status received or under review');
});

test('HR sees the new report in the inbox and every case page shows its deadlines', { session: 'hr' }, async ({ app, agent }) => {
  await app.open('/reports/inbox');
  await agent.assert('at least one report is waiting for review, with actions to create a case or dismiss it');
  await app.open('/cases');
  await agent.act('open the first case on the board');
  await agent.assert('the case page shows the case number, the employee name, a due-dates table and a five-stage progress rail');
});

test('a committee member sees their approvals screen without HR-only actions', { session: 'committee' }, async ({ app, agent }) => {
  await app.open('/home');
  await agent.assert('the page offers recommendations awaiting approval or says there is nothing to approve, '
    + 'and it does not offer to open a new case or import employees');
});

test('switching to Arabic turns the workspace right-to-left with Western digits', { session: 'hr' }, async ({ app, agent, browser }) => {
  await app.open('/home');
  await agent.act('switch the interface language to Arabic using the account menu');
  await expect(browser.locator('html')).toHaveAttribute('dir', 'rtl');
  await agent.assert('the navigation labels are in Arabic and every number visible on the page uses Western digits 0-9, not Arabic-Indic digits');
  await agent.act('switch the interface language back to English');
  await expect(browser.locator('html')).toHaveAttribute('dir', 'ltr');
});
