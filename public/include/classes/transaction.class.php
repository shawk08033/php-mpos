<?php

// Make sure we are called from index.php
if (!defined('SECURITY'))
  die('Hacking attempt');

class Transaction extends Base {
  protected $table = 'transactions';
  public $num_rows = 0, $insert_id = 0;

  /**
   * Add a new transaction to our class table
   * We also store the inserted ID in case the user needs it
   * @param account_id int Account ID to book transaction for
   * @param amount float Coin amount
   * @param type string Transaction type [Credit, Debit_AP, Debit_MP, Fee, Donation, Orphan_Credit, Orphan_Fee, Orphan_Donation]
   * @param block_id int Block ID to link transaction to [optional]
   * @param coin_address string Coin address for this transaction [optional]
   * @return bool
   **/
  public function addTransaction($account_id, $amount, $type='Credit', $block_id=NULL, $coin_address=NULL) {
    $stmt = $this->mysqli->prepare("INSERT INTO $this->table (account_id, amount, block_id, type, coin_address) VALUES (?, ?, ?, ?, ?)");
    if ($this->checkStmt($stmt) && $stmt->bind_param("idiss", $account_id, $amount, $block_id, $type, $coin_address) && $stmt->execute()) {
      $this->insert_id = $stmt->insert_id;
      return true;
    }
    return $this->sqlError();
  }

  /*
   * Mark transactions of a user as archived
   * @param account_id int Account ID
   * @param txid int Transaction ID to start from
   * @param bool boolean True or False
   **/
  public function setArchived($account_id, $txid) {
    // Fetch last archived transaction for user, we must exclude our Debits though! There might be unarchived/archived
    // records before our last payout
    $stmt = $this->mysqli->prepare("SELECT IFNULL(MAX(id), 0) AS id FROM $this->table WHERE archived = 1 AND account_id = ? AND type NOT IN ('Debit_MP','Debit_AP','TXFee')");
    if ($this->checkStmt($stmt) && $stmt->bind_param('i', $account_id) && $stmt->execute() && $result = $stmt->get_result())
      $last_id = $result->fetch_object()->id;
    $this->debug->append('Found last archived transaction: ' . $last_id);
    // Update all transactions, mark as archived for user previous to $txid and higher than last archived transaction
    $stmt = $this->mysqli->prepare("
      UPDATE $this->table AS t
      LEFT JOIN " . $this->block->getTableName() . " AS b
      ON b.id = t.block_id
      SET archived = 1
      WHERE t.archived = 0 AND t.account_id = ? AND t.id <= ? AND t.id > ? AND (b.confirmations >= ? OR b.confirmations IS NULL)
      ");
    if ($this->checkStmt($stmt) && $stmt->bind_param('iiii', $account_id, $txid, $last_id, $this->config['confirmations']) && $stmt->execute())
      return true;
    return $this->sqlError();
  }

  /**
   * Fetch a transaction summary by type with total amounts
   * @param account_id int Account ID, NULL for all
   * @return data array type and total
   **/
  public function getTransactionSummary($account_id=NULL) {
    if ($data = $this->memcache->get(__FUNCTION__ . $account_id)) return $data;
    $sql = "
      SELECT
        SUM(t.amount) AS total, t.type AS type
      FROM transactions AS t
      LEFT OUTER JOIN blocks AS b
      ON b.id = t.block_id
      WHERE ( b.confirmations > 0 OR b.id IS NULL )";
    if (!empty($account_id)) {
      $sql .= " AND t.account_id = ? ";
      $this->addParam('i', $account_id);
    }
    $sql .= " GROUP BY t.type";
    $stmt = $this->mysqli->prepare($sql);
    if (!empty($account_id)) {
      if (!($this->checkStmt($stmt) && call_user_func_array( array($stmt, 'bind_param'), $this->getParam()) && $stmt->execute()))
        return false;
      $result = $stmt->get_result();
    } else {
      if (!($this->checkStmt($stmt) && $stmt->execute()))
        return false;
      $result = $stmt->get_result();
    }
    if ($result) {
      $aData = NULL;
      while ($row = $result->fetch_assoc()) {
        $aData[$row['type']] = $row['total'];
      }
      // Cache data for a while, query takes long on many rows
      return $this->memcache->setCache(__FUNCTION__ . $account_id, $aData, 60);
    }
    return $this->sqlError();
  }

  /**
   * Get all transactions from start for account_id
   * @param start int Starting point, id of transaction
   * @param filter array Filter to limit transactions
   * @param limit int Only display this many transactions
   * @param account_id int Account ID
   * @return data array Database fields as defined in SELECT
   **/
  public function getTransactions($start=0, $filter=NULL, $limit=30, $account_id=NULL) {
    $this->debug->append("STA " . __METHOD__, 4);
    $sql = "
      SELECT
        SQL_CALC_FOUND_ROWS
        t.id AS id,
        a.username as username,
        t.type AS type,
        t.amount AS amount,
        t.coin_address AS coin_address,
        t.timestamp AS timestamp,
        b.height AS height,
        b.blockhash AS blockhash,
        b.confirmations AS confirmations
      FROM $this->table AS t
      LEFT JOIN " . $this->block->getTableName() . " AS b ON t.block_id = b.id
      LEFT JOIN " . $this->user->getTableName() . " AS a ON t.account_id = a.id";
    if (!empty($account_id)) {
      $sql .= " WHERE ( t.account_id = ? ) ";
      $this->addParam('i', $account_id);
    }
    if (is_array($filter)) {
      $aFilter = array();
      foreach ($filter as $key => $value) {
        if (!empty($value)) {
          switch ($key) {
          case 'type':
            $aFilter[] = "( t.type = ? )";
            $this->addParam('s', $value);
            break;
          case 'status':
            switch ($value) {
            case 'Confirmed':
              if (empty($filter['type']) || ($filter['type'] != 'Debit_AP' && $filter['type'] != 'Debit_MP' && $filter['type'] != 'TXFee' && $filter['type'] != 'Credit_PPS' && $filter['type'] != 'Fee_PPS' && $filter['type'] != 'Donation_PPS')) {
                $aFilter[] = "( b.confirmations >= " . $this->config['confirmations'] . " OR ISNULL(b.confirmations) )";
              }
                break;
            case 'Unconfirmed':
              $aFilter[] = "( b.confirmations < " . $this->config['confirmations'] . " AND b.confirmations >= 0 )";
                break;
            case 'Orphan':
              $aFilter[] = "( b.confirmations = -1 )";
                break;
            }
            break;
            case 'account':
              $aFilter[] = "( LOWER(a.username) = LOWER(?) )";
              $this->addParam('s', $value);
              break;
            case 'address':
              $aFilter[] = "( t.coin_address = ? )";
              $this->addParam('s', $value);
              break;
          }
        }
      }
      if (!empty($aFilter)) {
      	empty($account_id) ? $sql .= " WHERE " : $sql .= " AND ";
        $sql .= implode(' AND ', $aFilter);
      }
    }
    $sql .= "
      ORDER BY id DESC
      LIMIT ?,?
      ";
    // Add some other params to query
    $this->addParam('i', $start);
    $this->addParam('i', $limit);
    $stmt = $this->mysqli->prepare($sql);
    if ($this->checkStmt($stmt) && call_user_func_array( array($stmt, 'bind_param'), $this->getParam()) && $stmt->execute() && $result = $stmt->get_result()) {
      // Fetch matching row count
      $num_rows = $this->mysqli->prepare("SELECT FOUND_ROWS() AS num_rows");
      if ($num_rows->execute() && $row_count = $num_rows->get_result()->fetch_object()->num_rows)
        $this->num_rows = $row_count;
      return $result->fetch_all(MYSQLI_ASSOC);
    }
    return $this->sqlError();
  }

  /**
   * Get all different transaction types
   * @return mixed array/bool Return types on succes, false on failure
   **/
  public function getTypes() {
    $stmt = $this->mysqli->prepare("SELECT DISTINCT type FROM $this->table");
    if ($this->checkStmt($stmt) && $stmt->execute() && $result = $stmt->get_result()) {
      $aData = array('' => '');
      while ($row = $result->fetch_assoc()) {
        $aData[$row['type']] = $row['type'];
      }
      return $aData;
    }
    return $this->sqlError();
  }

  /**
   * Get all donation transactions
   * Used on donors page
   * return data array Donors and amounts
   **/
  public function getDonations() {
    $this->debug->append("STA " . __METHOD__, 4);
    $stmt = $this->mysqli->prepare("
      SELECT
        SUM(t.amount) AS donation,
        a.username AS username,
        a.is_anonymous AS is_anonymous,
        a.donate_percent AS donate_percent
      FROM $this->table AS t
      LEFT JOIN " . $this->user->getTableName() . " AS a
      ON t.account_id = a.id
      LEFT JOIN blocks AS b
      ON t.block_id = b.id
      WHERE
      (
        ( t.type = 'Donation' AND b.confirmations >= " . $this->config['confirmations'] . " ) OR
        t.type = 'Donation_PPS'
      )
      GROUP BY a.username
      ORDER BY donation DESC
      ");
    if ($this->checkStmt($stmt) && $stmt->execute() && $result = $stmt->get_result())
      return $result->fetch_all(MYSQLI_ASSOC);
    return $this->sqlError();
  }

  /**
   * Get total balance for all users locked in wallet
   * This includes any outstanding unconfirmed transactions!
   * @param none
   * @return data double Amount locked for users
   **/
  public function getLockedBalance() {
    $this->debug->append("STA " . __METHOD__, 4);
    $stmt = $this->mysqli->prepare("
      SELECT
        ROUND((
          SUM( IF( ( t.type IN ('Credit','Bonus') AND b.confirmations >= ? ) OR t.type = 'Credit_PPS', t.amount, 0 ) ) -
          SUM( IF( t.type IN ('Debit_MP', 'Debit_AP'), t.amount, 0 ) ) -
          SUM( IF( ( t.type IN ('Donation','Fee') AND b.confirmations >= ? ) OR ( t.type IN ('Donation_PPS', 'Fee_PPS', 'TXFee') ), t.amount, 0 ) )
        ), 8) AS balance
      FROM $this->table AS t
      LEFT JOIN " . $this->block->getTableName() . " AS b
      ON t.block_id = b.id
      WHERE archived = 0");
    if ($this->checkStmt($stmt) && $stmt->bind_param('ii', $this->config['confirmations'], $this->config['confirmations']) && $stmt->execute() && $stmt->bind_result($dBalance) && $stmt->fetch())
      return $dBalance;
    return $this->sqlError();
  }

  /**
   * Get an accounts total balance, ignore archived entries
   * @param account_id int Account ID
   * @return data float Credit - Debit - Fees - Donation
   **/
  public function getBalance($account_id) {
    $this->debug->append("STA " . __METHOD__, 4);
    $stmt = $this->mysqli->prepare("
      SELECT
        IFNULL(ROUND((
          SUM( IF( ( t.type IN ('Credit','Bonus') AND b.confirmations >= ? ) OR t.type = 'Credit_PPS', t.amount, 0 ) ) -
          SUM( IF( t.type IN ('Debit_MP', 'Debit_AP'), t.amount, 0 ) ) -
          SUM( IF( ( t.type IN ('Donation','Fee') AND b.confirmations >= ? ) OR ( t.type IN ('Donation_PPS', 'Fee_PPS', 'TXFee') ), t.amount, 0 ) )
        ), 8), 0) AS confirmed,
        IFNULL(ROUND((
          SUM( IF( t.type IN ('Credit','Bonus') AND b.confirmations < ? AND b.confirmations >= 0, t.amount, 0 ) ) -
          SUM( IF( t.type IN ('Donation','Fee') AND b.confirmations < ? AND b.confirmations >= 0, t.amount, 0 ) )
        ), 8), 0) AS unconfirmed,
        IFNULL(ROUND((
          SUM( IF( t.type IN ('Credit','Bonus') AND b.confirmations = -1, t.amount, 0) ) -
          SUM( IF( t.type IN ('Donation','Fee') AND b.confirmations = -1, t.amount, 0) )
        ), 8), 0) AS orphaned
      FROM $this->table AS t
      LEFT JOIN " . $this->block->getTableName() . " AS b
      ON t.block_id = b.id
      WHERE t.account_id = ?
      AND archived = 0
      ");
    if ($this->checkStmt($stmt) && $stmt->bind_param("iiiii", $this->config['confirmations'], $this->config['confirmations'], $this->config['confirmations'], $this->config['confirmations'], $account_id) && $stmt->execute() && $result = $stmt->get_result())
      return $result->fetch_assoc();
    return $this->sqlError();
  }
}

$transaction = new Transaction();
$transaction->setMemcache($memcache);
$transaction->setDebug($debug);
$transaction->setMysql($mysqli);
$transaction->setConfig($config);
$transaction->setBlock($block);
$transaction->setUser($user);
$transaction->setErrorCodes($aErrorCodes);

?>
