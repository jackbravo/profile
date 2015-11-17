<?php

/**
 * @file
 * Contains \Drupal\profile\Tests\ProfileAttachTest.
 */

namespace Drupal\profile\Tests;

use Drupal\Component\Render\FormattableMarkup;
use Drupal\simpletest\WebTestBase;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\field\Entity\FieldConfig;

/**
 * Tests attaching of profile entity forms to other forms.
 *
 * @group profile
 */
class ProfileAttachTest extends WebTestBase {

  /**
   * @var \Drupal\field\FieldStorageConfigInterface $field.
   */
  protected $field;

  /**
   * @var \Drupal\field\FieldConfigInterface $field.
   */
  protected $instance;

  /**
   * @var \Drupal\Core\Entity\Display\EntityViewDisplayInterface $display
   */
  protected $display;

  /**
   * @var \Drupal\profile\Entity\ProfileTypeInterface $type
   */
  protected $type;

  /**
   * @var \Drupal\Core\Entity\Display\EntityFormDisplayInterface $form
   */
  protected $form;

  public static $modules = ['profile', 'text'];

  function setUp() {
    parent::setUp();

    $this->type = entity_create('profile_type', [
      'id' => 'test',
      'label' => 'Test profile',
      'weight' => 0,
      'registration' => TRUE,
    ]);
    $this->type->save();

    $this->field = [
      'field_name' => 'profile_fullname',
      'type' => 'text',
      'entity_type' => 'profile',
      'cardinality' => 1,
      'translatable' => FALSE,
    ];
    $this->field = FieldStorageConfig::create($this->field);
    $this->field->save();

    $this->instance = [
      'entity_type' => 'profile',
      'field_name' => $this->field->getName(),
      'bundle' => $this->type->id(),
      'label' => 'Full name',
      'required' => TRUE,
      'widget' => [
        'type' => 'text_textfield',
      ],
    ];
    $this->instance = FieldConfig::create($this->instance);
    $this->instance->save();

    $this->display = entity_get_display('profile', 'test', 'default')
      ->setComponent($this->field->getName(), [
        'type' => 'text_default',
      ]);
    $this->display->save();

    $this->form = entity_get_form_display('profile', 'test', 'default')
      ->setComponent($this->field->getName(), [
        'type' => 'string_textfield',
      ]);
    $this->form->save();

    $this->checkPermissions([]);
  }

  /**
   * Test user registration integration.
   */
  function testUserRegisterForm() {
    $id = $this->type->id();
    $field_name = $this->field->getName();

    // Allow registration without administrative approval and log in user
    // directly after registering.
    \Drupal::configFactory()->getEditable('user.settings')
      ->set('register', USER_REGISTER_VISITORS)
      ->set('verify_mail', 0)
      ->save();
    user_role_grant_permissions(\Drupal\user\RoleInterface::AUTHENTICATED_ID, ['view own test profile']);

    // Verify that the additional profile field is attached and required.
    $name = $this->randomMachineName();
    $pass_raw = $this->randomMachineName();
    $edit = [
      'name' => $name,
      'mail' => $this->randomMachineName() . '@example.com',
      'pass[pass1]' => $pass_raw,
      'pass[pass2]' => $pass_raw,
    ];
    $this->drupalPostForm('user/register', $edit, t('Create new account'));
    $this->assertRaw(new FormattableMarkup('@name field is required.', ['@name' => $this->instance->getLabel()]));

    // Verify that we can register.
    $edit["entity_" . $id . "[$field_name][0][value]"] = $this->randomMachineName();
    $this->drupalPostForm(NULL, $edit, t('Create new account'));
    $this->assertText(new FormattableMarkup('Registration successful. You are now logged in.', []));

    $new_user = user_load_by_name($name);
    $this->assertTrue($new_user->isActive(), 'New account is active after registration.');

    // Verify that a new profile was created for the new user ID.
    $profile = \Drupal::entityTypeManager()
      ->getStorage('profile')
      ->loadByUser($new_user, $this->type->id());

    $this->assertEqual($profile->get($field_name)->value, $edit["entity_" . $id . "[$field_name][0][value]"], 'Field value found in loaded profile.');

    // Verify that the profile field value appears on the user account page.
    $this->drupalGet('user');
    $this->assertText($edit["entity_" . $id . "[$field_name][0][value]"], 'Field value found on user account page.');
  }

}
