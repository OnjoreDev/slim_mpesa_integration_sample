<?php

declare(strict_types=1);

namespace App\Services;

use Exception;
use App\Models\Mpesa;
use Monolog\Logger;

class MpesaService
{
    private Mpesa $mpesa;
    private Logger $logger;
    public function __construct(Mpesa $mpesa, Logger $logger)
    {
        $this->mpesa = $mpesa;
        $this->logger = $logger;
    }

    //function to get the token
    public function getToken()
    {

        $url = $_ENV["MPESA_BASE_URL"]."/oauth/v1/generate?grant_type=client_credentials";

        $credentials = $_ENV["MPESA_CONSUMER_KEY"].":".$_ENV["MPESA_CONSUMER_SECRET"];

        $encodedCredentials =  base64_encode($credentials);

        $ch =  curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER,[
            "Content-Type: application/json",
            "Authorization: Basic " .$encodedCredentials
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
}
