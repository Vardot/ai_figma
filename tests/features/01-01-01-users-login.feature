Feature: The administrator can log in
  As a site administrator
  I want to log in as the install super-admin
  So that the suite has a known-good administrator fixture before the
  admin-only scenarios run

  # The functional lane only needs the administrator: every scenario in this
  # suite asserts admin pages render with no PHP errors, and denial is proven
  # with the built-in anonymous user - so no extra test users are provisioned
  # (which keeps the lane fast and free of user-creation flakiness across the
  # Drupal Core, Drupal CMS and Varbase distributions the matrix targets).

  Scenario: The administrator can log in
    Given I am a logged in user with the "Webmaster" user
    When I navigate to "/user"
    Then I should be on the "/user/" page
     And I the page should not have PHP errors
