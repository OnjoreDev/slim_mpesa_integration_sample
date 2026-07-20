<?php

declare(strict_types=1);

namespace App\Models;

//use PDO;

class Mpesa extends Model
{
  private $table_name = "mpesa_transactions";

  //create a transaction
  public function createTransaction(array $data): bool
  {
    $sql = "INSERT INTO " . $this->table_name . "(amount, phone, checkout_request_id, merchant_request_id, status) 
           VALUES(?,?,?,?,?)";

    $stmt = $this->pdo->prepare($sql);

    //bind parameters to values
    return $stmt->execute([
      $data["amount"],
      $data["phone"],
      $data["checkout_request_id"],
      $data["merchant_request_id"],
      "pending" //initial status
    ]);
  }

  public function updateTransaction(string $checkoutRequestId, string $status, string $receiptNumber): bool
  {
    // Added a comma and ensured spaces exist between keywords
    $sql = "UPDATE " . $this->table_name . " 
            SET status = :status, 
                mpesa_receipt_number = :receiptnumber 
            WHERE checkout_request_id = :checkout_request_id";

    $stmt = $this->pdo->prepare($sql);

    return $stmt->execute([
      ":status" => $status,
      ":receiptnumber" => $receiptNumber,
      ":checkout_request_id" => $checkoutRequestId
    ]);
  }

  public function updateTransactionStatus(string $checkout_request_id, string $status): bool {
    // Added a space before WHERE
    $sql = "UPDATE " . $this->table_name . " 
            SET status = :status 
            WHERE checkout_request_id = :checkout_request_id";
    
    $stmt = $this->pdo->prepare($sql);
    
    return $stmt->execute([
        ":checkout_request_id" => $checkout_request_id,
        ":status" => $status
    ]);
}
}
