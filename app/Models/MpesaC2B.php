<?php

declare(strict_types=1);

namespace App\Models;

use PDO;
use DateTime;

class MpesaC2B extends Model
{

    public function createTransaction(array $data): bool
    {
        $sql = "INSERT INTO mpesa_c2b_transactions 
            (transaction_id, short_code, amount, phone_number, account_reference, first_name, middle_name, last_name, transaction_time, raw_payload) 
            VALUES (:tid, :sc, :amt, :phone, :ref, :fname, :mname, :lname, :ttime, :raw)";

        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([
            ':tid'   => $data['TransID'],
            ':sc'    => $data['BusinessShortCode'],
            ':amt'   => $data['TransAmount'],
            ':phone' => $data['MSISDN'],
            ':ref'   => $data['BillRefNumber'],
            ':fname' => $data['FirstName'] ?? null,
            ':mname' => $data['MiddleName'] ?? null,
            ':lname' => $data['LastName'] ?? null,
            ':ttime' => $this->formatDate($data['TransTime']),
            ':raw'   => json_encode($data)
        ]);
    }
    /**
     * Helper to format M-Pesa's TransTime (YmdHis) to MySQL DATETIME (Y-m-d H:i:s)
     */
    private function formatDate(string $timeString): string
    {
        $date = DateTime::createFromFormat('YmdHis', $timeString);

        // Fallback to current time if format is invalid
        if (!$date) {
            return date('Y-m-d H:i:s');
        }

        return $date->format('Y-m-d H:i:s');
    }

    
    //helps to check if transaction exists
    public function exists(string $transactionId): bool
    {
        $sql = "SELECT COUNT(*) FROM mpesa_c2b_transactions WHERE transaction_id = :tid";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':tid' => $transactionId]);

        // Return true if count is greater than 0
        return (int)$stmt->fetchColumn() > 0;
    }
}
