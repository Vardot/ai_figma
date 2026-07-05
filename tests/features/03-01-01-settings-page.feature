Feature: AI Figma module - the single configuration page
  As a site builder
  I want one settings page to point the module at my Figma token and file
  So that the Drupal Canvas AI assistant can read my designs

  # AI Figma ships exactly one config page at /admin/config/ai/figma: the Figma
  # token (Key), the default file key, the API base URL and a "Test Figma
  # connection" action. Everything else runs through the Drupal Canvas AI
  # assistant, so there is deliberately no builder, nodes or layout admin page.

  Background:
    Given I am a logged in user with the "Webmaster" user
     And I navigate to "/admin/config/ai/figma"

  Scenario: The settings form is reachable for administrators
    Then the "ai figma settings form" element should be visible
     And the "drupal page heading" element should contain text "AI Figma"
     And I the page should not have PHP errors

  Scenario: The page exposes the Figma token Key, default file and API base fields
    Then the "ai figma settings figma token key" element should be visible
     And I should see a "Figma token" field
     And I should see a "Default Figma file key" field
     And I should see a "Figma API base URL" field

  Scenario: The page exposes the Test connection and Save actions
    Then I should see the button "Test Figma connection"
     And I should see the button "Save configuration"

  Scenario: Saving the form persists the configuration with no errors
    When I press "Save configuration"
    Then I should see "The configuration options have been saved."
     And the "ai figma settings form errors" element should have a count of 0
     And I the page should not have PHP errors

  Scenario: The settings page meets basic accessibility
    Then every form field should have an accessible label
     And the page should have no serious accessibility violations
