Feature: AI Figma module status report
  As a site administrator
  I want the status report to report the AI Figma module state
  So that I can see at a glance whether the Figma token is configured

  # hook_requirements() registers an "AI Figma" line on the status report:
  # "Figma token configured" (OK) when a Key holds a token, or "No Figma token
  # configured" (a warning, not an error) on a fresh install. Either way the
  # "AI Figma" title renders and the page must be free of PHP errors, so this
  # scenario asserts the stable parts and never couples to the token state of a
  # given environment.

  Background:
    Given I am a logged in user with the "Webmaster" user

  Scenario: The status report lists the AI Figma requirement with no PHP errors
    When I navigate to "/admin/reports/status"
    Then the "drupal page heading" element should contain text "Status report"
     And I should see "AI Figma"
     And I the page should not have PHP errors
