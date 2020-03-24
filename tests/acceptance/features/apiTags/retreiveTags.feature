@api @systemtags-app-required @TestAlsoOnExternalUserBackend @skipOnOcis @issue-ocis-reva-51
Feature: tags

  Background:
    Given these users have been created with default attributes and skeleton files:
      | username |
      | user0    |
      | user1    |
    And as user "user0"

  Scenario: Getting tags only works with access to the file
    Given the administrator has created a "normal" tag with name "MyFirstTag"
    And user "user0" has uploaded file "filesForUpload/textfile.txt" to "/myFileToTag.txt"
    When the user adds tag "MyFirstTag" to file "/myFileToTag.txt" using the WebDAV API
    Then file "/myFileToTag.txt" should have the following tags for the user
      | name       | type   |
      | MyFirstTag | normal |
    And the HTTP status when user "user1" requests tags for file "/myFileToTag.txt" owned by "user0" should be "404"

  @files_sharing-app-required
  Scenario: Static tags should be available while accessing the file if it is assigned to file
    Given the administrator has created a "static" tag with name "StaticTag"
    And user "user0" has uploaded file "filesForUpload/textfile.txt" to "/myFileToTag.txt"
    And user "user0" has shared file "/myFileToTag.txt" with the administrator
    When the administrator adds tag "StaticTag" to file "/myFileToTag.txt" using the WebDAV API
    Then file "/myFileToTag.txt" should have the following tags for the user
      | name      | type   |
      | StaticTag | static |
    And the HTTP status when user "user1" requests tags for file "/myFileToTag.txt" owned by "user0" should be "404"

  Scenario: Retrieve tag on a resource using REPORT method
    Given the administrator has created a "normal" tag with name "NormalTag"
    And user "user0" has uploaded file "filesForUpload/textfile.txt" to "myFileToTag.txt"
    And user "user0" has added tag "NormalTag" to file "myFileToTag.txt"
    When user reports for tag "NormalTag" using the webDAV API
    Then as user "user0" the response should contain file "myFileToTag.txt"
