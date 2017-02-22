<?php

/**
 * Class SPCivi.
 * This is a base class to use CiviCRM functionality from Drupal modules, and is used by several other modules.
 * It is intended to work both locally and remotely, as long as users use $spcivi->api().
 */
class SPCivi {

  /** @var static $instance */
  protected static $instance;

  /** @var \civicrm_api3 $apiClass CiviCRM API class */
  protected $apiClass;

  /** Some calls are cached for this request */
  private $customGroupCache = [];
  private $customFieldsCache = [];

  /**
   * Get instance
   * @return static|bool Instance or false
   */
  public static function getInstance() {
    if (!static::$instance) {
      static::$instance = new static;
    }

    static::$instance->initialize();

    return static::$instance;
  }

  /**
   * Initialize CiviCRM API.
   * If this is a local installation, call civicrm_initialize(). Otherwise, initalize civicrm_api3.
   * @return bool Success
   */
  public function initialize() {

    if (function_exists('civicrm_initialize')) {

      if (!civicrm_initialize()) {
        drupal_set_message('Could not initialize CiviCRM.');
        return FALSE;
      }
      $this->apiClass = new \civicrm_api3;

    } else {

      $this->apiClass = new \civicrm_api3([
        'server'  => variable_get('spciviapi_civicrm_server', 'https://www.spnet.nl/'),
        'path'    => variable_get('spciviapi_civicrm_path', 'sites/default/modules/civicrm/extern/rest.php'),
        'key'     => variable_get('spciviapi_civicrm_key'),
        'api_key' => variable_get('spciviapi_civicrm_userkey'),
      ]);
    }

    return TRUE;
  }

  /**
   * Call the CiviCRM API class. We're using the \civicrm_api3 class because it allows both local and remote requests.
   * But we're stubbornly converting objects to arrays and returning the result, to mimic the civicrm_api3 method.
   * TODO: refactor modules that use this class to use objects sometime.
   * @param array ...$vars API parameters
   * @return array API result
   */
  public function api(...$vars) {

    call_user_func_array([$this->apiClass, 'call'], $vars);
    $result = $this->apiClass->lastResult;
    // var_dump($result);

    if (isset($vars['object'])) {
      return $result;
    } else {
      return json_decode(json_encode($result), TRUE);
    }
  }

  /**
   * Get the CiviCRM API class. (Fix + didn't notice more modules used this function)
   * @return \civicrm_api3 API class
   */
  public function getApi() {
    return $this->apiClass;
  }

  /**
   * Find a custom field ID by name
   * @param string $groupName CustomGroup name
   * @param string $fieldName CustomField name
   * @return int CustomField id
   * @throws \CiviCRM_API3_Exception Exception
   */
  public function getCustomFieldId($groupName, $fieldName) {

    $cacheKey = $groupName . '_' . $fieldName;
    if (array_key_exists($cacheKey, $this->customFieldsCache)) {
      return $this->customFieldsCache[ $cacheKey ];
    }

    $groupId = $this->getCustomGroupId($groupName);
    $fieldId = $this->api('CustomField', 'getvalue', [
      'group_id' => $groupId,
      'name'     => $fieldName,
      'return'   => 'id',
    ]);

    $this->customFieldsCache[ $cacheKey ] = $fieldId;

    return $fieldId;
  }

  /**
   * Find a custom group ID by name
   * @param string $groupName CustomGroup name
   * @return int CustomGroup id
   * @throws \CiviCRM_API3_Exception Exception
   */
  public function getCustomGroupId($groupName) {

    if (array_key_exists($groupName, $this->customGroupCache)) {
      return $this->customGroupCache[ $groupName ];
    };

    $groupId = $this->api('CustomGroup', 'getvalue', ['name' => $groupName, 'return' => 'id']);
    $this->customGroupCache[ $groupName ] = $groupId;

    return $groupId;
  }

  /**
   * Haal een relationship type id op basis van een naam op
   * @param string $name_a_b Name_A_B
   * @return int|bool Relationship Type ID or false
   */
  public function getRelationshipTypeIdByNameAB($name_a_b) {
    try {
      $result = $this->api('RelationshipType', 'getsingle', array('name_a_b' => $name_a_b));
      return $result['id'];
    } catch (\CiviCRM_API3_Exception $e) {
      return FALSE;
    }
  }

  /**
   * Autocomplete data voor inschrijfformulier e.d., rekening houden met ACL's. Is in gebruik door zowel de module
   * spregioconf als door speventreg. Afkomstig uit spkaderfunctiesafdeling, misschien daar ook veralgemeniseren.
   * @param string $string Search string
   * @return string JSON output
   */
  public function getContactAutoCompleteData($string = '') {

    $aclContactCache = \Civi::service('acl_contact_cache');
    $aclWhere = $aclContactCache->getAclWhereClause(CRM_Core_Permission::VIEW, 'contact_a');
    $aclFrom = $aclContactCache->getAclJoin(CRM_Core_Permission::VIEW, 'contact_a');

    $params = [];
    $sql    = "SELECT contact_a.id, contact_a.display_name
          FROM civicrm_contact contact_a
          {$aclFrom}
          WHERE contact_a.contact_type = 'Individual' AND contact_a.is_deleted = 0 AND contact_a.is_deceased = 0 AND {$aclWhere}
          ";
    if (!empty($string)) {
      $sql .= " AND (contact_a.display_name LIKE %1 OR contact_a.sort_name LIKE %1 OR CONVERT(contact_a.id, CHAR) LIKE %1)";
      $params[1] = ['%' . $string . '%', 'String'];
    }
    $sql .= " ORDER BY contact_a.sort_name LIMIT 0,10";
    $return = [];
    $dao    = CRM_Core_DAO::executeQuery($sql, $params);
    while ($dao->fetch()) {
      $name             = $dao->display_name . " (lidnr: " . $dao->id . ")";
      $return[$dao->id] = $name;
    }

    return $return;
  }

}
