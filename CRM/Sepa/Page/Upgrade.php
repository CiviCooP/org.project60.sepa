<?php
require_once 'CRM/Core/Page.php';

class CRM_Sepa_Page_Upgrade extends CRM_Core_Page {
  function run() {
    $messages = array();

    if (!CRM_Core_DAO::checkFieldExists('civicrm_sdd_txgroup', 'is_cor1')) {
      CRM_Core_DAO::executeQuery("ALTER TABLE `civicrm_sdd_txgroup` ADD `is_cor1` tinyint COMMENT 'Instrument for payments in this group will be COR1 (true/1) or CORE (false/0).' AFTER `reference`");
      $messages[] = 'Added `civicrm_sdd_txgroup`.`is_cor1`.';
    }

    if (!CRM_Core_DAO::checkFieldExists('civicrm_sdd_creditor', 'extra_advance_days')) {
      CRM_Core_DAO::executeQuery("ALTER TABLE `civicrm_sdd_creditor` ADD `extra_advance_days` int unsigned DEFAULT 1 COMMENT 'How many banking days (if any) to add on top of all minimum advance presentation deadlines defined in the SEPA rulebook.'");
      $messages[] = 'Added `civicrm_sdd_creditor`.`extra_advance_days`.';
    }
    if (!CRM_Core_DAO::checkFieldExists('civicrm_sdd_creditor', 'maximum_advance_days')) {
      CRM_Core_DAO::executeQuery("ALTER TABLE `civicrm_sdd_creditor` ADD `maximum_advance_days` tinyint DEFAULT 14 COMMENT 'When generating SEPA XML files, include payments up to this many calendar days from now. (14 is the minimum banks have to allow according to rulebook.)'");
      $messages[] = 'Added `civicrm_sdd_creditor`.`maximum_advance_days`.';
    /* Fix up comment typo we created in some versions. */
    } elseif (CRM_Core_DAO::singleValueQuery("SELECT COLUMN_COMMENT LIKE '%calender%' FROM INFORMATION_SCHEMA.COLUMNS WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = 'civicrm_sdd_creditor' AND `COLUMN_NAME` = 'maximum_advance_days'")) {
      CRM_Core_DAO::executeQuery("ALTER TABLE `civicrm_sdd_creditor` MODIFY `maximum_advance_days` tinyint DEFAULT 14 COMMENT 'When generating SEPA XML files, include payments up to this many calendar days from now. (14 is the minimum banks have to allow according to rulebook.)'");
      $messages[] = 'Fixed comment typo for `civicrm_sdd_creditor`.`maximum_advance_days`.';
    }
    if (!CRM_Core_DAO::checkFieldExists('civicrm_sdd_creditor', 'use_cor1')) {
      CRM_Core_DAO::executeQuery("ALTER TABLE `civicrm_sdd_creditor` ADD `use_cor1` tinyint DEFAULT 0 COMMENT 'Generate SEPA XML files using \"Local Instrument\" COR1 instead of CORE (along with the shorter minimum advance presentation deadlines) for domestic payments.'");
      $messages[] = 'Added `civicrm_sdd_creditor`.`use_cor1`.';
    }
    if (!CRM_Core_DAO::checkFieldExists('civicrm_sdd_creditor', 'group_batching_mode')) {
      CRM_Core_DAO::executeQuery("ALTER TABLE `civicrm_sdd_creditor` ADD `group_batching_mode` varchar(4) DEFAULT \"COR\" COMMENT 'How to batch TxGroups into files. NONE: every TxGroup in a separate file; TYPE: one file for each Sequence Type (FRST/RCUR/OOFF); COR: one file for all COR1 and one for all CORE; ALL: single file with all groups.'");
      $messages[] = 'Added `civicrm_sdd_creditor`.`group_batching_mode`.';
    }
    if (!CRM_Core_DAO::checkFieldExists('civicrm_sdd_creditor', 'month_wrap_policy')) {
      CRM_Core_DAO::executeQuery("ALTER TABLE `civicrm_sdd_creditor` ADD `month_wrap_policy` varchar(4) DEFAULT \"PRE\" COMMENT 'How to handle due dates of recurring payment installments (using \'month\' or \'year\' `frequency_unit`) that would wrap over into next month. PRE: move date before end of month; POST: wrap to 1st of next month; NONE: no explicit handling (February payments might wrap up to 3 days into March).'");
      $messages[] = 'Added `civicrm_sdd_creditor`.`month_wrap_policy`.';
    }

    $this->assign('messages', $messages);
    parent::run();
  }
}
