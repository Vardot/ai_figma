// Webship-js (Playwright + Cucumber) configuration for the AI Figma module.
//
// Browser-only BDD. Point it at any running site that has the ai_figma module
// enabled (the GitHub Actions workflow installs Drupal Core / Drupal CMS /
// Varbase 11, enables ai_figma, serves it, then runs `yarn test`):
//
//   LAUNCH_URL=https://your-site.ddev.site npm test
//
// Loads webship-js's built-in step library plus this module's custom steps.

module.exports = {
  default: {
    timeout: 45000,
    requireModule: ['tsx/cjs'],
    require: [
      'node_modules/webship-js/tests/step-definitions/**/*.js',
      'tests/step-definitions/**/*.js',
    ],
    paths: ['tests/features/**/*.feature'],
    // Always-green CI lane. No @javascript and no @ai scenarios exist in this
    // suite (no live LLM calls), so nothing is excluded by default.
    format: [
      'pretty',
      'json:tests/reports/cucumber_report.json',
    ],
    worldParameters: {
      launchUrl: process.env.LAUNCH_URL || 'http://localhost',
      // Test users for each role the scenarios exercise.
      //
      // Webmaster is the site-install super-admin, created by
      //   drush site:install ... --account-name=webmaster --account-pass=dD.123123ddd
      // The rest are provisioned by `Given I add testing users` (see
      // tests/step-definitions/ai_figma.steps.js), which iterates this registry
      // and skips entries flagged `isAdmin: true`. Override the admin
      // credentials per environment with the LOGIN_USER / LOGIN_PASS env vars.
      users: {
        Webmaster: {
          username: process.env.LOGIN_USER || 'webmaster',
          email: 'webmaster@example.test',
          password: process.env.LOGIN_PASS || 'dD.123123ddd',
          isAdmin: true,
        },
        'Content editor': {
          username: 'content_editor_user',
          email: 'content_editor_user@example.test',
          password: 'dD.123123ddd',
          roles: ['content_editor'],
        },
        'Authenticated user': {
          username: 'authenticated_user',
          email: 'authenticated_user@example.test',
          password: 'dD.123123ddd',
          roles: [],
        },
      },
      minWaitTime: {
        page: 3000,
        before_scenario: 0,
        after_scenario: 0,
        before_step: 0,
        after_step: 0,
      },
      selectors: {
        css: {},
        xpath: {},
        filesPath: './tests/selectors/',
        // One file per content-management system / admin theme the matrix
        // targets, plus the module's own named selectors. webship-js merges
        // them into a single registry, so a scenario can address e.g.
        // "drupal page heading" whatever distro is under test.
        files: [
          'cms-drupal-core-claro.json',
          'cms-drupal-cms-gin.json',
          'cms-varbase-vartheme.json',
          'ai_figma.json',
        ],
        offset: 60,
        breakpoints: {
          xs: { width: 375, height: 667 },
          sm: { width: 576, height: 800 },
          md: { width: 768, height: 1024 },
          lg: { width: 992, height: 768 },
          xl: { width: 1200, height: 900, default: true },
          xxl: { width: 1400, height: 900 },
        },
      },
      screenshot: {
        dir: './tests/screenshots',
        purge: false,
        onFailed: true,
        onEveryStep: false,
        alwaysFullscreen: false,
        failedPrefix: 'failed_',
        filenamePattern: '{datetime}.{feature_file}.feature_{step_line}.{ext}',
        filenamePatternFailed: '{failed_prefix}{datetime}.{feature_file}.feature_{step_line}.{ext}',
        infoTypes: '',
      },
      video: {
        mode: 'on-failure',
        dir: './tests/videos',
        size: { width: 1280, height: 720 },
        filenamePattern: '{datetime}.{feature_file}.{scenario}.{status}.{ext}',
      },
      javascript: {
        mode: 'warn',
        levels: ['error'],
        ignore: '',
        beforeScenario: false,
        afterScenario: true,
      },
    },
  },
};
