<?php
// +----------------------------------------------------------------------+
// | Anuko Time Tracker
// +----------------------------------------------------------------------+
// | Copyright (c) Anuko International Ltd. (https://www.anuko.com)
// +----------------------------------------------------------------------+
// | LIBERAL FREEWARE LICENSE: This source code document may be used
// | by anyone for any purpose, and freely redistributed alone or in
// | combination with other software, provided that the license is obeyed.
// |
// | There are only two ways to violate the license:
// |
// | 1. To redistribute this code in source form, with the copyright
// |    notice or license removed or altered. (Distributing in compiled
// |    forms without embedded copyright notices is permitted).
// |
// | 2. To redistribute modified versions of this code in *any* form
// |    that bears insufficient indications that the modifications are
// |    not the work of the original author(s).
// |
// | This license applies to this document only, not any other software
// | that it may be combined with.
// |
// +----------------------------------------------------------------------+
// | Contributors:
// | https://www.anuko.com/time_tracker/credits.htm
// +----------------------------------------------------------------------+

class CustomFields {

  // Definitions of custom field types.

  const TYPE_TEXT = 1;     // A text field.
  const TYPE_DROPDOWN = 2; // A dropdown field with pre-defined values.

  var $fields = array();  // Array of custom fields for group.
  var $options = array(); // Array of options for a dropdown custom field.

  // Constructor.
  function __construct() {
    global $user;
    $mdb2 = getConnection();

    $group_id = $user->getGroup();
    $org_id = $user->org_id;

    // Get fields.
    $sql = "select id, type, label, required from tt_custom_fields".
      " where group_id = $group_id and org_id = $org_id and status = 1 and type > 0";
    $res = $mdb2->query($sql);
    if (!is_a($res, 'PEAR_Error')) {
      while ($val = $res->fetchRow()) {
        $this->fields[] = array('id'=>$val['id'],'type'=>$val['type'],'label'=>$val['label'],'required'=>$val['required'],'value'=>'');
      }
    }

    // If we have a dropdown obtain options for it.
    if ((count($this->fields) > 0) && ($this->fields[0]['type'] == CustomFields::TYPE_DROPDOWN)) {

      $sql = "select id, value from tt_custom_field_options".
        " where field_id = ".$this->fields[0]['id']." and group_id = $group_id and org_id = $org_id and status = 1 order by value";
      $res = $mdb2->query($sql);
      if (!is_a($res, 'PEAR_Error')) {
        while ($val = $res->fetchRow()) {
          $this->options[$val['id']] = $val['value'];
        }
      }
    }
  }

  function insert($log_id, $field_id, $option_id, $value) {
    global $user;
    $mdb2 = getConnection();

    $group_id = $user->getGroup();
    $org_id = $user->org_id;

    $sql = "insert into tt_custom_field_log (group_id, org_id, log_id, field_id, option_id, value)".
      " values($group_id, $org_id, $log_id, $field_id, ".$mdb2->quote($option_id).", ".$mdb2->quote($value).")";
    $affected = $mdb2->exec($sql);
    return (!is_a($affected, 'PEAR_Error'));
  }

  function update($log_id, $field_id, $option_id, $value) {
    if (!$field_id)
      return true; // Nothing to update.

    // Remove older custom field values, if any.
    $res = $this->delete($log_id);
    if (!$res)
      return false;

    if (!$value && !$option_id)
      return true; // Do not insert NULL values.

    return $this->insert($log_id, $field_id, $option_id, $value);
  }

  function delete($log_id) {
    global $user;
    $mdb2 = getConnection();

    $group_id = $user->getGroup();
    $org_id = $user->org_id;

    $sql = "update tt_custom_field_log set status = null".
      " where log_id = $log_id and group_id = $group_id and org_id = $org_id";
    $affected = $mdb2->exec($sql);
    return (!is_a($affected, 'PEAR_Error'));
  }

  function get($log_id) {
    global $user;
    $mdb2 = getConnection();

    $group_id = $user->getGroup();
    $org_id = $user->org_id;

    $sql = "select id, field_id, option_id, value from tt_custom_field_log".
      " where log_id = $log_id and group_id = $group_id and org_id = $org_id and status = 1";
    $res = $mdb2->query($sql);
    if (!is_a($res, 'PEAR_Error')) {
      $fields = array();
      while ($val = $res->fetchRow()) {
        $fields[] = $val;
      }
      return $fields;
    }
    return false;
  }

  // insertOption adds a new option to a custom field.
  static function insertOption($field_id, $option_name) {
    global $user;
    $mdb2 = getConnection();

    $group_id = $user->getGroup();
    $org_id = $user->org_id;

    // Check if the option exists.
    $id = 0;
    $sql = "select id from tt_custom_field_options".
      " where field_id = $field_id and group_id = $group_id and org_id = $org_id and value = ".$mdb2->quote($option_name);
    $res = $mdb2->query($sql);
    if (is_a($res, 'PEAR_Error'))
      return false;
    if ($val = $res->fetchRow()) $id = $val['id'];

    // Insert option.
    if (!$id) {
      $sql = "insert into tt_custom_field_options (group_id, org_id, field_id, value)".
        " values($group_id, $org_id, $field_id, ".$mdb2->quote($option_name).")";
      $affected = $mdb2->exec($sql);
      if (is_a($affected, 'PEAR_Error'))
        return false;
    }
    return true;
  }

  // updateOption updates option name.
  static function updateOption($id, $option_name) {
    global $user;
    $mdb2 = getConnection();

    $group_id = $user->getGroup();
    $org_id = $user->org_id;

    $sql = "update tt_custom_field_options set value = ".$mdb2->quote($option_name).
       " where id = $id and group_id = $group_id and org_id = $org_id";
    $affected = $mdb2->exec($sql);
    return (!is_a($affected, 'PEAR_Error'));
  }

  // delete Option deletes an option and all custom field log entries that used it.
  static function deleteOption($id) {
    global $user;
    $mdb2 = getConnection();

    $group_id = $user->getGroup();
    $org_id = $user->org_id;

    $field_id = CustomFields::getFieldIdForOption($id);
    if (!$field_id) return false;

    // Delete log entries with this option. TODO: why? Research impact.
    $sql = "update tt_custom_field_log set status = null".
      " where field_id = $field_id and group_id = $group_id and org_id = $org_id and value = ".$mdb2->quote($id);
    $affected = $mdb2->exec($sql);
    if (is_a($affected, 'PEAR_Error'))
      return false;

    // Delete the option.
    $sql = "update tt_custom_field_options set status = null".
      " where id = $id and group_id = $group_id and org_id = $org_id";
    $affected = $mdb2->exec($sql);
    return (!is_a($affected, 'PEAR_Error'));
  }

  // getOptions returns an array of options for a custom field.
  static function getOptions($field_id) {
    global $user;
    $mdb2 = getConnection();

    $group_id = $user->getGroup();
    $org_id = $user->org_id;

    // Get options.
    $sql = "select id, value from tt_custom_field_options".
      " where field_id = $field_id and group_id = $group_id and org_id = $org_id and status = 1 order by value";
    $res = $mdb2->query($sql);
    if (!is_a($res, 'PEAR_Error')) {
      $options = array();
      while ($val = $res->fetchRow()) {
        $options[$val['id']] = $val['value'];
      }
      return $options;
    }
    return false;
  }

  // getOptionName returns an option name for a custom field.
  static function getOptionName($id) {
    global $user;
    $mdb2 = getConnection();

    $group_id = $user->getGroup();
    $org_id = $user->org_id;

    $sql = "select value from tt_custom_field_options".
      " where id = $id and group_id = $group_id and org_id = $org_id and status = 1";
    $res = $mdb2->query($sql);
    if (!is_a($res, 'PEAR_Error')) {
      $val = $res->fetchRow();
      $name = $val['value'];
      return $name;
    }
    return false;
  }

  // getFields returns an array of custom fields for group.
  static function getFields() {
    global $user;
    $mdb2 = getConnection();

    $group_id = $user->getGroup();
    $org_id = $user->org_id;

    $fields = array();
    $sql = "select id, type, label from tt_custom_fields".
      " where group_id = $group_id and org_id = $org_id and status = 1 and type > 0";
    $res = $mdb2->query($sql);
    if (!is_a($res, 'PEAR_Error')) {
      while ($val = $res->fetchRow()) {
        $fields[] = array('id'=>$val['id'],'type'=>$val['type'],'label'=>$val['label']);
      }
      return $fields;
    }
    return false;
  }

  // getField returns a custom field.
  static function getField($id) {
    global $user;
    $mdb2 = getConnection();

    $group_id = $user->getGroup();
    $org_id = $user->org_id;

    $sql = "select label, type, required from tt_custom_fields".
      " where id = $id and group_id = $group_id and org_id = $org_id";
    $res = $mdb2->query($sql);
    if (!is_a($res, 'PEAR_Error')) {
      $val = $res->fetchRow();
      if (!$val)
        return false;
      return $val;
    }
    return false;
  }

  // getFieldIdForOption returns field id from an associated option id.
  static function getFieldIdForOption($option_id) {
    global $user;
    $mdb2 = getConnection();

    $group_id = $user->getGroup();
    $org_id = $user->org_id;

    $sql = "select field_id from tt_custom_field_options".
      " where id = $option_id and group_id = $group_id and org_id = $org_id";
    $res = $mdb2->query($sql);
    if (!is_a($res, 'PEAR_Error')) {
      $val = $res->fetchRow();
      $field_id = $val['field_id'];
      return $field_id;
    }
    return false;
  }

  // The insertField inserts a custom field for group.
  static function insertField($field_name, $field_type, $required) {
    global $user;
    $mdb2 = getConnection();

    $group_id = $user->getGroup();
    $org_id = $user->org_id;

    $sql = "insert into tt_custom_fields (group_id, org_id, type, label, required, status)".
      " values($group_id, $org_id, $field_type, ".$mdb2->quote($field_name).", $required, 1)";
    $affected = $mdb2->exec($sql);
    return (!is_a($affected, 'PEAR_Error'));
  }

  // The updateField updates custom field for group.
  static function updateField($id, $name, $type, $required) {
    global $user;
    $mdb2 = getConnection();

    $group_id = $user->getGroup();
    $org_id = $user->org_id;

    $sql = "update tt_custom_fields set label = ".$mdb2->quote($name).", type = $type, required = $required".
      " where id = $id and group_id = $group_id and org_id = $org_id";
    $affected = $mdb2->exec($sql);
    return (!is_a($affected, 'PEAR_Error'));
  }

  // The deleteField deletes a custom field, its options and log entries for group.
  static function deleteField($field_id) {
    global $user;
    $mdb2 = getConnection();

    $group_id = $user->getGroup();
    $org_id = $user->org_id;

    // Mark log entries as deleted. TODO: why are we doing this? Research impact.
    $sql = "update tt_custom_field_log set status = null".
      " where field_id = $field_id and group_id = $group_id and org_id = $org_id";
    $affected = $mdb2->exec($sql);
    if (is_a($affected, 'PEAR_Error'))
      return false;

    // Mark field options as deleted.
    $sql = "update tt_custom_field_options set status = null".
      " where field_id = $field_id and group_id = $group_id and org_id = $org_id";
    $affected = $mdb2->exec($sql);
    if (is_a($affected, 'PEAR_Error'))
      return false;

    // Mark custom field as deleted.
    $sql = "update tt_custom_fields set status = null".
      " where id = $field_id and group_id = $group_id and org_id = $org_id";
    $affected = $mdb2->exec($sql);
    return (!is_a($affected, 'PEAR_Error'));
  }
}
