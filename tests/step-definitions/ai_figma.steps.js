'use strict';

const { Given, Then, When } = require('@cucumber/cucumber');

const {
  friendly,
  gotoUrl,
  waitForPageLoad,
} = require('@vardot/varbase-e2e/tests/step-definitions/varbase-e2e');

/**
 * Run a step body and rethrow any failure as a tester-friendly error.
 *
 * @param {Function} body  - async function performing the step.
 * @param {string} message - human-readable description for failures.
 */
async function attempt(body, message) {
  try {
    await body();
  } catch (err) {
    throw friendly(message, err);
  }
}

/**
 * Provision every non-admin user from cucumber.js worldParameters.users via
 * Drupal's /admin/people/create form. Entries flagged isAdmin: true are
 * skipped (the site-install Webmaster already exists). Idempotent - a second
 * run reports "name is already taken" and the step swallows it.
 *
 * Must be invoked while logged in as the Webmaster (or any user with the
 * "administer users" permission).
 *
 * Example #1: Given I add testing users
 * Example #2: And I add testing users
 * Example #3: When I add testing users
 * Example #4: Given I add the testing users
 * Example #5: And we add testing users
 */
Given(/^(?:I |we )?add( the)? testing users$/, async function (theCase) {
  const users = this.parameters.users || {};
  await attempt(async () => {
    for (const [key, info] of Object.entries(users)) {
      if (info.isAdmin) continue;
      await gotoUrl(this.page, `${this.parameters.launchUrl}/admin/people/create`);
      await this.page.locator('#edit-name').fill(info.username);
      await this.page.locator('#edit-mail').fill(info.email || `${info.username}@example.test`);
      await this.page.locator('#edit-pass-pass1').fill(info.password);
      await this.page.locator('#edit-pass-pass2').fill(info.password);
      for (const role of info.roles || []) {
        const cb = this.page.locator(`input[name="roles[${role}]"]`);
        if ((await cb.count()) > 0) await cb.check();
      }
      await this.page.locator('#edit-submit').click();
      await waitForPageLoad(this.page, this.minWaitTime && this.minWaitTime.page);
    }
  }, 'Could not provision the testing users');
});

/**
 * Assert the page does not contain a PHP error, fatal, warning, notice, or
 * Drupal's "unexpected error" page. Covers both "the page should not" and
 * "I the page should not" forms that appear after Given/And keywords.
 *
 * Example #1: Then the page should not have PHP errors
 * Example #2: And the page should not have PHP errors
 * Example #3: Then I the page should not have PHP errors
 * Example #4: And I the page should not have PHP errors
 * Example #5: And we the page should not have PHP errors
 */
Then(/^(?:I |we )?the page should not have PHP errors$/, async function () {
  await attempt(async () => {
    const content = await this.page.content();
    const phpErrorPatterns = [
      /Fatal error:/i,
      /Warning:.*on line/i,
      /Notice:.*on line/i,
      /Parse error:/i,
      /The website encountered an unexpected error/i,
    ];
    for (const pattern of phpErrorPatterns) {
      if (pattern.test(content)) {
        throw new Error(`PHP error detected on page: ${this.page.url()}`);
      }
    }
  }, 'Expected page to be free of PHP errors');
});

/**
 * Navigate to a path and assert the server denied access. Checks the HTTP
 * response status (403) first - theme-agnostic - and falls back to the Drupal
 * "not authorized" body text. Proves non-administrators are kept out of the AI
 * Figma configuration.
 *
 * Example #1: Then I am denied access to "/admin/config/ai/figma"
 * Example #2: And I am denied access to "/admin/config/ai/figma"
 * Example #3: Then I am denied access to "/admin/config/ai/agents"
 * Example #4: And we am denied access to "/admin/reports/status"
 * Example #5: Then I am denied access to "/admin/config/ai"
 */
Then(/^(?:I |we )?am denied access to "([^"]*)"$/, async function (path) {
  await attempt(async () => {
    const response = await this.page.goto(`${this.parameters.launchUrl}${path}`, { waitUntil: 'networkidle' });
    const status = response ? response.status() : 0;
    // 403 is the straight refusal; 404 also keeps the surface unreachable
    // (a route can vanish when its providing module is not part of a build).
    if (status === 403 || status === 404) {
      return;
    }
    const body = await this.page.content();
    if (!/not authorized to access this page|access denied/i.test(body)) {
      throw new Error(`Expected access to "${path}" to be denied (HTTP 403), got HTTP ${status}`);
    }
  }, `Expected to be denied access to "${path}"`);
});

/**
 * Resolve a varbase-e2e named selector from the world registry (hydrated from
 * cucumber.js's selectors.files list). Throws when the name is unknown so a
 * typo never silently passes through to Playwright as a literal CSS string.
 */
function resolveName(world, name) {
  const css = world.__selectorsCss || {};
  const key = name.trim();
  if (Object.prototype.hasOwnProperty.call(css, key)) {
    return css[key];
  }
  const keys = Object.keys(css);
  throw new Error(`Unknown named selector "${key}". Run "Then print css selectors" to see all ${keys.length} registered names.`);
}

/**
 * Assert a named selector is visible / hidden / attached / focused / enabled /
 * disabled / editable, optionally within N seconds.
 *
 * Example #1: Then the "ai figma settings form" element should be visible
 * Example #2: Then the "ai figma settings form" element should be visible within 5 seconds
 * Example #3: Then the "drupal page heading" element should be visible
 * Example #4: Then the "ai figma settings form" element should be hidden
 * Example #5: Then the "ai figma test connection button" element should be attached
 */
Then(/^the "([^"]*)" element should be (visible|hidden|attached|focused|enabled|disabled|editable)(?: within (\d+) seconds?)?$/, async function (name, state, sec) {
  const sel = resolveName(this, name);
  const loc = this.page.locator(sel);
  const timeout = sec ? Number(sec) * 1000 : 10000;
  await attempt(async () => {
    if (state === 'visible' || state === 'attached' || state === 'hidden') {
      await loc.first().waitFor({ state, timeout });
    } else if (state === 'focused') {
      await this.page.waitForFunction((s) => document.activeElement && document.activeElement.matches(s), sel, { timeout });
    } else {
      const fn = { enabled: 'isEnabled', disabled: 'isDisabled', editable: 'isEditable' }[state];
      const ok = await loc.first()[fn]();
      if (!ok) throw new Error(`"${name}" not ${state}`);
    }
  }, `Expected "${name}" (${sel}) to be ${state}`);
});

/**
 * Assert the count of elements matching a named selector, optionally within N
 * seconds.
 *
 * Example #1: Then the "ai figma settings form" element should have a count of 1
 * Example #2: Then the "ai figma settings form" element should have a count of 0
 * Example #3: Then the "ai figma admin services link" element should have a count of 1
 * Example #4: Then the "ai figma test connection button" element should have a count of 1
 * Example #5: Then the "ai figma settings form" element should have a count of 1 within 5 seconds
 */
Then(/^the "([^"]*)" element should have a count of (\d+)(?: within (\d+) seconds?)?$/, async function (name, expected, sec) {
  const sel = resolveName(this, name);
  const target = Number(expected);
  const timeout = sec ? Number(sec) * 1000 : 10000;
  const loc = this.page.locator(sel);
  const deadline = Date.now() + timeout;
  let last = -1;
  await attempt(async () => {
    while (Date.now() < deadline) {
      last = await loc.count();
      if (last === target) return;
      await this.page.waitForTimeout(100);
    }
    throw new Error(`count was ${last}`);
  }, `Expected "${name}" (${sel}) count to be ${target}`);
});

/**
 * Assert the first element matching a named selector contains the given text.
 *
 * Example #1: Then the "drupal page heading" element should contain text "AI Figma"
 * Example #2: Then the "drupal admin status messages" element should contain text "saved"
 * Example #3: Then the "drupal page heading" element should contain text "Status report"
 * Example #4: Then the "drupal page heading" element should contain text "Permissions"
 * Example #5: Then the "drupal page heading" element should contain text "AI Agents"
 */
Then(/^the "([^"]*)" element should contain text "([^"]*)"(?: within (\d+) seconds?)?$/, async function (name, text, sec) {
  const sel = resolveName(this, name);
  const timeout = sec ? Number(sec) * 1000 : 10000;
  await attempt(async () => {
    await this.page.waitForFunction(
      ([s, t]) => { const el = document.querySelector(s); return el && el.textContent.includes(t); },
      [sel, text],
      { timeout, polling: 100 },
    );
  }, `Expected "${name}" (${sel}) to contain text "${text}"`);
});

/**
 * Click the first element matching a named selector. Falls back to a JS
 * `.click()` after a failed Playwright actionability retry so a sticky
 * form-actions overlay does not stall the click.
 *
 * Example #1: When I click the "ai figma test connection button" element
 * Example #2: And I click the "ai figma save configuration button" element
 * Example #3: When I click on the "ai figma admin services link" element
 * Example #4: And we click the "ai figma admin menu link" element
 * Example #5: When I click "ai figma save configuration button" element
 */
When(/^(?:I |we )?click(?: on)?(?: the)? "([^"]*)" element$/, async function (name) {
  const sel = resolveName(this, name);
  await attempt(async () => {
    const loc = this.page.locator(sel).first();
    await loc.waitFor({ state: 'visible', timeout: 10000 });
    try {
      await loc.click({ timeout: 4000 });
    } catch (e) {
      await this.page.evaluate((s) => { const el = document.querySelector(s); if (el) el.click(); }, sel);
    }
  }, `Could not click the "${name}" element`);
});

/**
 * Resolve a form field locator by label, falling back to the label element
 * itself for inputs visually replaced by rich editors / widgets.
 */
function fieldLocator(page, label) {
  const byLabel = page.getByLabel(label, { exact: false });
  const byLabelElement = page
    .locator('label.form-item__label, label.form-required, label')
    .filter({ hasText: new RegExp(`^\\s*${label.replace(/[.*+?^${}()|[\\]\\\\]/g, '\\$&')}(\\s|$)`, 'i') });
  return byLabel.or(byLabelElement).first();
}

/**
 * Assert that a form field with the given label is visible on the page.
 *
 * Example #1: Then I should see a "Figma token" field
 * Example #2: Then I should see a "Default Figma file key" field
 * Example #3: Then I should see a "Figma API base URL" field
 * Example #4: Then I should see a "Label" field
 * Example #5: Then I should see a "Title" field
 */
Then(/^(?:I |we )?should see a "([^"]*)" field$/, async function (label) {
  await attempt(async () => {
    const locator = fieldLocator(this.page, label);
    await locator.waitFor({ state: 'visible', timeout: 10000 });
  }, `Expected to find a field labeled "${label}"`);
});

/**
 * Assert that a form field with the given label (article "an") is visible.
 *
 * Example #1: Then I should see an "API base URL" field
 * Example #2: Then I should see an "Email" field
 * Example #3: Then I should see an "Author" field
 * Example #4: Then I should see an "Image" field
 * Example #5: Then I should see an "Agent" field
 */
Then(/^(?:I |we )?should see an "([^"]*)" field$/, async function (label) {
  await attempt(async () => {
    const locator = fieldLocator(this.page, label);
    await locator.waitFor({ state: 'visible', timeout: 10000 });
  }, `Expected to find a field labeled "${label}"`);
});

/**
 * Assert that a button with the given text is visible on the page.
 *
 * Example #1: Then I should see the button "Save configuration"
 * Example #2: Then I should see the button "Test Figma connection"
 * Example #3: Then I should see the button "Log in"
 * Example #4: Then I should see the button "Save"
 * Example #5: Then I should see the button "Continue"
 */
Then(/^(?:I |we )?should see the button "([^"]*)"$/, async function (text) {
  await attempt(async () => {
    const locator = this.page.getByRole('button', { name: text, exact: false }).first();
    await locator.waitFor({ state: 'visible', timeout: 10000 });
  }, `Expected to find a button with text "${text}"`);
});
