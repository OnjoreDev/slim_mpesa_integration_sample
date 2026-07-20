<?php

declare(strict_types=1);

namespace App\Services;

use Exception;
use App\Models\Mpesa;
use App\Models\MpesaC2B;
use Monolog\Logger;

class MpesaService
{
    private Mpesa $mpesa;
    private Logger $logger;
    private MpesaC2B $c2b;
    public function __construct(Mpesa $mpesa, Logger $logger, MpesaC2B $c2b)
    {
        $this->mpesa = $mpesa;
        $this->logger = $logger;
        $this->c2b = $c2b;
    }

    //function to get the token
    public function getToken()
    {

        $url = $_ENV["MPESA_BASE_URL"] . "/oauth/v1/generate?grant_type=client_credentials";

        $credentials = $_ENV["MPESA_CONSUMER_KEY"] . ":" . $_ENV["MPESA_CONSUMER_SECRET"];

        $encodedCredentials =  base64_encode($credentials);

        $ch =  curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Content-Type: application/json",
            "Authorization: Basic " . $encodedCredentials
        ]);
        $result = curl_exec($ch);

        if (curl_errno($ch)) {
            $error = curl_error($ch);
            throw new Exception("Curl error " . $error);
        }
        $response = json_decode($result, true);

        if (isset($response["access_token"])) {
            return $response["access_token"];
        } else {
            throw new Exception("Failed to get Mpesa token " . $result);
        }
    }

    //initiate stk push
    public function initiateStk(int $amount, string $phone, array $meta)
    {
        $token = $this->getToken();
        $timestamp = date("YmdHis");
        $password = base64_encode($_ENV["MPESA_BUSINESS_SHORTCODE"] . $_ENV["MPESA_PASSKEY"] . $timestamp);

        //replace starting 0 in phone number with 254
        $formattedPhone = preg_replace('/^0/', '254', $phone);

        $payload = [
            "Password" => $password,
            "BusinessShortCode" => $_ENV["MPESA_BUSINESS_SHORTCODE"],
            "Timestamp" => $timestamp,
            "Amount" => (int)$amount,
            "PartyA" => $formattedPhone,
            "PartyB" => $_ENV["MPESA_BUSINESS_SHORTCODE"],
            "TransactionType" => "CustomerPayBillOnline",
            "PhoneNumber" => $formattedPhone,
            "TransactionDesc" => "Deposit to " . $meta['account_ref'], //what will be displayed on the stk push notification
            "AccountReference" => $meta['account_ref'],
            "CallBackURL" => $_ENV["MPESA_CALLBACK_URL"]
        ];

        $url = $_ENV["MPESA_BASE_URL"] . "/mpesa/stkpush/v1/processrequest";
        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Content-Type: application/json",
            "Authorization: Bearer " . $token
        ]);

        $response = curl_exec($ch);

        $decodedResponse = json_decode($response, true); //return associative array

        //add the record initially to the db and set the status to pending
        //create record in the database
        if (isset($decodedResponse['CheckoutRequestID'])) {
            $this->mpesa->createTransaction([
                'amount'              => $amount,
                'phone'        => $formattedPhone,
                'checkout_request_id' => $decodedResponse['CheckoutRequestID'],
                'merchant_request_id' => $decodedResponse['MerchantRequestID'],
                'status'              => 'pending'
            ]);
        }


        if (curl_errno($ch)) {
            throw new Exception("Error " . curl_error($ch));
        } else {
            return $decodedResponse;
        }
    }

    #Register URLs
    public function registerURLs()
    {
        $ch = curl_init();
        $url = $_ENV["MPESA_BASE_URL"] . "/mpesa/c2b/v2/registerurl";
        $token = $this->getToken();
        $payload = [
            "ShortCode" => "600984",
            "ResponseType" => "Completed",
            "ConfirmationURL" => $_ENV["MPESA_C2B_CONFIRMATION_URL"],
            "ValidationURL" => $_ENV["MPESA_C2B_VALIDATION_URL"],
        ];

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Content-Type: application/json",
            "Authorization: Bearer " . $token
        ]);

        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new Exception("Curl error: " . $error);
        }

        $decodedResponse = json_decode($result, true);

        // Return the response, or an error array if decode failed
        return [
            'status' => $httpCode,
            'response' => $decodedResponse ?? ['error' => 'Invalid response from M-Pesa', 'raw' => $result]
        ];
    }

    /**
     * Handle Validation logic
     */
    public function validateC2B(array $data): array
    {
        // You can add custom business rules here (e.g., check if BillRefNumber exists)
        $this->logger->info("C2B Validation request", $data);

        // Return success to M-Pesa
        return ["ResultCode" => 0, "ResultDesc" => "Accepted"];
    }

    /**
     * Handle Confirmation logic
     */

    public function confirmC2B(array $data): array
    {
        $transactionId = $data['TransID'] ?? null;

        if (!$transactionId) {
            return ["ResultCode" => 1, "ResultDesc" => "Missing Transaction ID"];
        }

        // 1. Check if it exists to ensure idempotency
        if ($this->c2b->exists($transactionId)) {
            $this->logger->info("Duplicate confirmation received", ['transaction_id' => $transactionId]);
            // Return 0 because we have already processed it; this stops Safaricom from retrying
            return ["ResultCode" => 0, "ResultDesc" => "Already processed"];
        }

        // 2. Proceed to save
        try {
            $this->c2b->createTransaction($data);
            return ["ResultCode" => 0, "ResultDesc" => "Accepted"];
        } catch (Exception $e) {
            $this->logger->error("Failed to save C2B transaction: " . $e->getMessage());
            return ["ResultCode" => 1, "ResultDesc" => "Internal Server Error"];
        }
    }

    #c-2-b payment
    public function simulateC2B(int $amount, string $phone, string $billRef)
    {
        $url = $_ENV["MPESA_BASE_URL"] . "/mpesa/c2b/v1/simulate";
        $token = $this->getToken();

        $payload = [
            "ShortCode"      => $_ENV["MPESA_BUSINESS_SHORTCODE"],
            "CommandID"      => "CustomerPayBillOnline", // or "CustomerBuyGoodsOnline"
            "Amount"         => $amount,
            "Msisdn"         => $phone, // Should be 254... format
            "BillRefNumber"  => $billRef
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Content-Type: application/json",
            "Authorization: Bearer " . $token
        ]);

        $result = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [
            "status" => $status,
            "response" => json_decode($result, true)
        ];
    }
}
