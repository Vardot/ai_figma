Feature: AI Figma module admin pages load correctly
  As a site administrator
  I want the AI Figma settings page and the core admin surfaces to be reachable
  So that I can confirm the module is correctly installed and raises no PHP errors

  Background:
    Given I am a logged in user with the "Webmaster" user

  Scenario: Webmaster can access the AI Figma settings page
    When I navigate to "/admin/config/ai/figma"
    Then I should see "AI Figma"
     And the "ai figma settings form" element should be visible
     And I the page should not have PHP errors

  Scenario: The module is enabled on the modules page
    When I navigate to "/admin/modules"
    Then I should see "AI Figma"
     And I the page should not have PHP errors

  Scenario: The AI Figma settings link is discoverable from the AI configuration section
    When I navigate to "/admin/config/ai"
    Then the "ai figma admin services link" element should be visible
     And I the page should not have PHP errors
