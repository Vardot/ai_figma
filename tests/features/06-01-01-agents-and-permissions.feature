Feature: AI Figma agents surface and permissions
  As a site administrator
  I want the AI agents list and the permissions page to load cleanly
  So that I can confirm the module integrates with the Drupal AI framework

  # AI Figma ships AI Agent function-call TOOLS (read Figma design context, build
  # Canvas pages from the theme's own components) that are surfaced to the Drupal
  # Canvas AI orchestrator at install time. These scenarios assert the AI agents
  # admin surface loads with no PHP errors and that the module's own permission
  # is registered - they never trigger a live LLM call, so they pass in CI with
  # no provider key configured. Asserting a specific orchestrator label needs
  # canvas_ai present and is left to live-CI tuning.

  Background:
    Given I am a logged in user with the "Webmaster" user

  Scenario: The AI agents configuration page loads with no PHP errors
    When I navigate to "/admin/config/ai/agents"
    Then the "drupal page heading" element should be visible
     And I the page should not have PHP errors

  Scenario: The AI Figma administer permission is registered
    When I navigate to "/admin/people/permissions"
    Then I should see "Administer AI Figma"
     And I the page should not have PHP errors

  Scenario: The Figma design context permission is registered
    When I navigate to "/admin/people/permissions"
    Then I should see "Use Figma design context"
     And I the page should not have PHP errors
