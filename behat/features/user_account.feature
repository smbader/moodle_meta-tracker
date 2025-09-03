Feature: User account management
  As a user
  I want to be able to register, login, and logout

  Scenario: Register a new user
    Given I am on "/register.php"
    When I submit the registration form with fullname "Behat User", email "behatuser@example.com" and password "behatpass"
    Then I should see "Registration successful"

  Scenario: Login with the new user
    Given I am on "/login.php"
    When I submit the login form with email "behatuser@example.com" and password "behatpass"
    Then I should see "Welcome, Behat User"

  Scenario: Logout
    Given I am logged in as "behatuser@example.com" with password "behatpass"
    When I go to "/logout.php"
    Then I should see "You have been logged out"
