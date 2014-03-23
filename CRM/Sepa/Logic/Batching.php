<?php

/**
 * Sepa Direct Debit Batching Logic
 * Author: Project60 
 * 
 * See Batching.md for more info (Seriously, read it. Now. Until you understand it.)
 */

/**
 * This class contains the logic to create SDD batches on both levels.
 */
class CRM_Sepa_Logic_Batching extends CRM_Sepa_Logic_Base {

  public static function batch_initial_contribution($objectId, $objectRef) {
    self::debug('Running initial contribution batching process for mandate M#' . $objectId);
    $mandate = new CRM_Sepa_BAO_SEPAMandate();
    $mandate->get('id', $objectId);

    // Get the first contribution for this mandate
    $contrib = $mandate->findContribution();

    self::batchContribution($contrib, $mandate);
  }

  /**
   * Batch a contribution into a TXG.
   * @param CRM_Sepa_BAO_SEPATransaction $bao
   */
  public static function batchContribution(CRM_Contribute_BAO_Contribution $contrib, CRM_Sepa_BAO_SEPAMandate $mandate) {
    self::debug('Batching contribution C#'. $contrib->id);

    // what are the criteria to find an existing suitable batch ?
    $payment_instrument_id = $contrib->payment_instrument_id;
    $creditor_id = $mandate->creditor_id;
    CRM_Sepa_Logic_Batching::batchContributionByCreditor ($contrib,$creditor_id,$payment_instrument_id);
}

  /**
   * Batch a contribution into a TXG.
   * @param CRM_Sepa_BAO_SEPATransaction $bao
   */
  public static function batchContributionByCreditor (CRM_Contribute_BAO_Contribution $contrib, $creditor_id,$payment_instrument_id) {
    $type = CRM_Core_OptionGroup::getValue('payment_instrument', $contrib->payment_instrument_id, 'value', 'String', 'name');
    self::debug(' Contribution is of type '. $type);

    $receive_date = substr($contrib->receive_date, 0, 10);
    $txGroup = self::findTxGroup($creditor_id, $type, $receive_date, $receive_date);

    // if not found, create a nex batch 
    if ($txGroup === null) {
      $txGroup = self::createTxGroup($creditor_id, $type, $receive_date,$payment_instrument_id);
    }

    // now add the tx to teh batch
    self::addToTxGroup($contrib, $txGroup);
    return $txGroup;
  }

  /**
   */
  public static function batchForSubmit($submitDate, $creditorId) {
    $maximumAdvanceDays = 14; // Default per SEPA Rulebook.
    $dateRangeEnd = date('Y-m-d', strtotime("$submitDate + $maximumAdvanceDays days"));

    #$result = civicrm_api3('SepaCreditor', 'getsingle', array(
    #  'id' => $creditorId,
    #  'api.SepaTransactionGroup.get' => array(
    #    'sdd_creditor_id' => '$value.id',
    #    'status_id' => CRM_Core_OptionGroup::getValue('batch_status', 'Open', 'name'),
    #    #'filter.collection_date_high' => $dateRangeEnd,
    #    'return' => array('id', 'type', 'collection_date'),
    #  ),
    #  'return' => 'api.SepaTransactionGroup.get',
    #));
    #if (!$result['count']) {
    #  throw new API_Exception("No matching Creditor found.");
    #}
    #
    #$pendingGroups = array();
    #foreach ($result['api.SepaTransactionGroup.get']['values'] as $group) {

    $result = civicrm_api3('SepaTransactionGroup', 'get', array(
      'options' => array('limit' => 1234567890),
      'status_id' => CRM_Core_OptionGroup::getValue('batch_status', 'Open', 'name'),
      'sdd_creditor_id' => $creditorId,
      #'filter.collection_date_high' => $dateRangeEnd,
      'return' => array('id', 'type', 'collection_date'),
    ));

    $pendingGroups = array();
    foreach ($result['values'] as $group) {
      $bestCollectionDate = self::adjustBankDays($group['collection_date'], 0);
      if ($bestCollectionDate > $dateRangeEnd) {
        continue;
      }
      $group['is_cor1'] = true; /* DiCo hack */
      $advanceDays = $group['is_cor1'] ? 1 : ($group['type'] == 'RCUR' ? 2 : 5);
      $earliestCollectionDate = self::adjustBankDays($submitDate, $advanceDays);
      $collectionDate = max($earliestCollectionDate, $bestCollectionDate);

      $pendingGroups[$group['type']][$collectionDate][] = $group['id'];
    }

    if (!empty($pendingGroups)) {
      $creditor = civicrm_api3('SepaCreditor', 'getsingle', array('id' => $creditorId));
      $tag = (isset($creditor['tag'])) ? $creditor['tag'] : $creditor['mandate_prefix'];

      $sddFile = self::createSddFile((object)array('latest_submission_date' => date('Ymd', strtotime($submitDate))), $tag);

      foreach ($pendingGroups as $type => $dates) {
        foreach ($dates as $collectionDate => $ids) {
          $paymentInstrumentId = CRM_Core_OptionGroup::getValue('payment_instrument', $type, 'name');
          $txGroup = self::createTxGroup($creditorId, $type, $collectionDate, $paymentInstrumentId, $sddFile->id);

          $result = civicrm_api3('SepaTransactionGroup', 'get', array(
            'options' => array('limit' => 1234567890),
            'id' => array('IN' => $ids),
            'api.SepaContributionGroup.get' => array(
              'options' => array('limit' => 1234567890),
              'txgroup_id' => '$value.id',
              'api.SepaContributionGroup.create' => array(
                'id' => '$value.id', /* Make this explicit, as otherwise it's very confusing why this call updates the existing record rather than adding a new one... */
                'txgroup_id' => $txGroup->id,
                'contribution_id' => '$value.contribution_id',
              ),
            ),
            'api.SepaTransactionGroup.delete' => array(
              'id' => '$value.id',
            ),
          ));
        }
      }

      civicrm_api3('SepaSddFile', 'generatexml', array('id' => $sddFile->id));
    }
  }

  /**
   */
  public static function cancelSubmit($params) {
    $result = civicrm_api3('SepaTransactionGroup', 'get', array_merge($params, array(
      'options' => array('limit' => 1234567890),
      'api.SepaContributionGroup.getdetail' => array(
        'options' => array('limit' => 1234567890),
        'id' => '$value.id',
      ),
      'api.SepaContributionGroup.get' => array(
        'options' => array('limit' => 1234567890),
        'txgroup_id' => '$value.id',
        'api.SepaContributionGroup.delete' => array(
          'id' => '$value.id',
        ),
      ),
    )));
    if (!$result['count']) {
      throw new API_Exception("No matching Transaction Group found.");
    }

    foreach ($result['values'] as $group) {
      foreach ($group['api.SepaContributionGroup.getdetail']['values'] as $contributionGroup) {
        $contribution = new CRM_Contribute_BAO_Contribution();
        $contribution->get('id', $contributionGroup['contribution_id']);

        $mandate = new CRM_Sepa_BAO_SEPAMandate();
        $mandate->get('id', $contributionGroup['mandate_id']);

        self::batchContribution($contribution, $mandate);
      }
    }
  }

  /**
   * Find an appropriate civicrm_batch instance.
   * 
   * @param int $payment_instrument_id
   * @param string $type
   * @param date $earliest_date
   * @param date $latest_date
   * 
   * @return CRM_Sepa_BAO_SEPATransactionGroup or null
   */
  public static function findTxGroup($creditor_id, $type, $earliest_date, $latest_date) {
    CRM_Sepa_Logic_Base::debug("Locating suitable TXG with CRED=$creditor_id, TYPE=$type and COLLDATE IN " . substr($earliest_date,0,10) . ' / ' . substr($latest_date,0,10));
    $openStatus = CRM_Core_OptionGroup::getValue('batch_status', 'Open', 'name', 'String', 'value');
    $query = "SELECT 
                id 
              FROM
                civicrm_sdd_txgroup
              WHERE         
                status_id = " . $openStatus . "
                AND
                type = '" . $type . "'
                AND
                sdd_creditor_id = " . (int) $creditor_id . "
                AND 
                collection_date >= '" . $earliest_date . "'
                AND 
                collection_date <= '" . $latest_date . "'
              ORDER BY 
                collection_date ASC
              LIMIT 1
              ";
    $dao = CRM_Core_DAO::executeQuery($query);
    if ($dao->fetch()) {
      $txgroup = new CRM_Sepa_BAO_SEPATransactionGroup();
      $txgroup->get('id', $dao->id);
      return $txgroup;
    }
    return null;
  }

  public static function createTxGroup($creditor_id, $type, $receive_date, $payment_instrument_id, $sddFileId = null) {
    $collection_date = substr($receive_date, 0, 10);
    CRM_Sepa_Logic_Base::debug("Creating new TXG( CRED=$creditor_id, TYPE=$type, COLLDATE=" . $collection_date . ')');

    $status = isset($sddFileId) ? 'Closed' : 'Open';

    $session = CRM_Core_Session::singleton();
    $reference = time() . rand(); // Just need something unique at this point. (Will generate a nicer one once we have the auto ID from the DB -- see further down.)
    $params = array(
        'reference' => $reference,
        'type' => $type,
        'sdd_creditor_id' => $creditor_id,
        'status_id' => CRM_Core_OptionGroup::getValue('batch_status', $status, 'name', 'String', 'value'),
        'payment_instrument_id' => $payment_instrument_id,
        'collection_date' => $collection_date,
        'created_date' => date('Ymdhis'),
        'modified_date' => date('Ymdhis'),
        'created_id' => $session->get('userID'),
        'modified_id' => $session->get('userID'),
        'sdd_file_id' => $sddFileId,
    );
    $result = civicrm_api3('SepaTransactionGroup', 'create', $params);
    if ($result['is_error']) {
      CRM_Core_Error::fatal($result["error_message"]);
      return null;
    }
    $txgroup_id = $result['id'];

    // Now that we have the auto ID, create the proper reference.
    $prefix = ($status == 'Open') ? 'PENDING' : 'TXG';
    $creditorPrefix = civicrm_api3('SepaCreditor', 'getvalue', array('id' => $creditor_id, 'return' => 'mandate_prefix'));
    $reference = "$prefix-$creditorPrefix-$creditor_id-$type-$collection_date-$txgroup_id";
    civicrm_api3('SEPATransactionGroup', 'create', array('id' => $txgroup_id, 'reference' => $reference)); // Not very efficient, but easier than fiddling with BAO mess...

    $txgroup = new CRM_Sepa_BAO_SEPATransactionGroup();
    $txgroup->get('id', $txgroup_id);
    return $txgroup;
  }

  public static function addToTxGroup($contrib, $txGroup) {
    self::debug('Adding C#' . $contrib->id . ' to TXG#' . $txGroup->id);
    $params = array(
        'contribution_id' => $contrib->id,
        'txgroup_id' => $txGroup->id,
        'version' => 3,
    );
    $result = civicrm_api('SepaContributionGroup', 'create', $params);
    //TODO need error andling here
  }

  public static function batchTxGroup($objectId, $objectRef) {
    self::debug('Batching TXG#'. $objectId);

    $cred = civicrm_api3('SepaCreditor','getsingle',array('id'=>$objectRef->sdd_creditor_id));
    
    // look for the earliest SDD File (based on latest_submission_date)
    $sddFile = self::findSddFile($objectRef, $cred['tag']);

    // if not found, create a new SDD File 
    if ($sddFile === null) {
      $sddFile = self::createSddFile($objectRef, $cred['tag']);
    }

    // now add the txGroup to the SDD File
    self::addToSddFile($objectRef, $sddFile);
  }

  public static function findSddFile($txgroup, $tag) {
    self::debug('Locating suitable SDDFILE with LATEST_SUBMISSION=' . substr($txgroup->latest_submission_date,0,8) . ' and TAG = ' . $tag);
    $openStatus = CRM_Core_OptionGroup::getValue('batch_status', 'Open', 'name', 'String', 'value');
    $query = "SELECT 
                id 
              FROM
                civicrm_sdd_file
              WHERE         
                status_id = " . $openStatus . "
                AND 
                latest_submission_date = '" . $txgroup->latest_submission_date . "'
                AND 
                tag = '" . $tag . "'
              ORDER BY 
                latest_submission_date ASC
              LIMIT 1
              ";
    $dao = CRM_Core_DAO::executeQuery($query);
    if ($dao->fetch()) {
      $sddfile = new CRM_Sepa_BAO_SEPASddFile();
      $sddfile->get('id', $dao->id);
      return $sddfile;
    }
    return null;
    
  }

  public static function createSddFile($txgroup, $tag) {
    self::debug('Creating new SDDFILE( LATEST_SUBMISSION=' . substr($txgroup->latest_submission_date,0,8) . ', TAG=' . $tag . ')');

    // Just need something unique at this point. (Will generate a nicer one once we have the auto ID from the DB -- see further down.)
    $filename = $reference = time() . rand();

    $session = CRM_Core_Session::singleton();
    $params = array(
        'reference' => $reference,
        'filename' => $filename,
        'status_id' => CRM_Core_OptionGroup::getValue('batch_status', 'Open', 'name', 'String', 'value'),
        'latest_submission_date' => $txgroup->latest_submission_date,
        'tag' => $tag,
        'created_date' => date('Ymdhis'),
        'created_id' => $session->get('userID'),
        'version' => 3,
    );
    $result = civicrm_api('SepaSddFile', 'create', $params);
    if ($result['is_error']) {
      CRM_Core_Error::fatal(ts("ERROR creating SDDFILE"));
    }
    $sddfile_id = $result['id'];

    // Now that we have the auto ID, create the proper reference.
    $reference = "SDDXML-" . $tag . '-' . substr($txgroup->latest_submission_date, 0, 8) . '-' . $sddfile_id;
    $filename = str_replace('-', '_', $reference . ".xml");
    civicrm_api3('SEPASddFile', 'create', array('id' => $sddfile_id, 'reference' => $reference, 'filename' => $filename)); // Not very efficient, but easier than fiddling with BAO mess...

    $sddfile = new CRM_Sepa_BAO_SepaSddFile();
    $sddfile->get('id', $sddfile_id);
    return $sddfile;
    
  }

  public static function addToSddFile($txGroup, $sddFile) {
    self::debug('Adding TXG#' . $txGroup->id . ' to SDDFILE#' . $sddFile->id, '', 'info');
    $params = array(
        'id' => $txGroup->id,
        'sdd_file_id' => $sddFile->id,
        'version' => 3,
    );
    $result = civicrm_api('SepaTransactionGroup', 'update', $params);
  }

}

