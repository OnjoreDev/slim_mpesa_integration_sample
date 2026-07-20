<?php

declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;
use App\Services\MpesaService;
use Psr\Container\ContainerInterface;
use Exception;

class MpesaController extends Controller
{

    private MpesaService $mpesaService;

    public function __construct(ContainerInterface $container)
    {
        parent::__construct($container);
        $this->mpesaService = $container->get(MpesaService::class);
    }

    public function getToken(Request $request, Response $response): Response
    {
        try {
            $token = $this->mpesaService->getToken();
            return $this->jsonResponse($response, ['token' => $token]);
        } catch (Exception $e) {
            $this->logger->error("Token generation has failed :" . $e->getMessage());
            return $this->jsonResponse($response, ["error" => "Could not generate token"]);
        }
    }

    public function initiateStkPush(Request $request, Response $response): Response
    {
        //get the payload
        $data = $request->getParsedBody();
        $amount = (int)($data["amount"] ?? 0);
        $phone = $data["phone"] ?? "";
        $accountRef = $data["account_ref"] ?? "Top Up";

        if ($amount <= 0 || empty($phone)) {
            return $this->jsonResponse($response, ['error' => 'Invalid amount or phone'], 400);
        }

        try {
            $result = $this->mpesaService->initiateStk($amount, $phone, ["account_ref" => $accountRef]);
            $this->logger->info("STK Push Initiated", ["result" => $result]);
            return $this->jsonResponse($response, $result);
        } catch (Exception $e) {
            $this->logger->error("STK Push failed: " . $e->getMessage());
            return $this->jsonResponse($response, ['error' => $e->getMessage()], 500);
        }
    }

    //----------------------------------------------------------------------
    //-------------Mpesa c2b--------------------------------------------------------
    //----------------------------------------------------------------------

    // In App\Controllers\MpesaController.php

    public function registerUrls(Request $request, Response $response): Response
    {
        try {
            $result = $this->mpesaService->registerURLs();

            $this->logger->info("URL Registration response", $result);

            return $this->jsonResponse($response, $result['response'], $result['status']);
        } catch (Exception $e) {
            $this->logger->error("URL Registration failed: " . $e->getMessage());
            return $this->jsonResponse($response, ['error' => 'Registration failed'], 500);
        }
    }
    //validation 
    public function validation(Request $request, Response $response): Response
    {
        $data = json_decode((string)$request->getBody(), true);
        $result = $this->mpesaService->validateC2B($data);
        return $this->jsonResponse($response, $result);
    }


    //confirmation
    public function confirmation(Request $request, Response $response): Response
    {
        $data = json_decode((string)$request->getBody(), true);
        $result = $this->mpesaService->confirmC2B($data);
        return $this->jsonResponse($response, $result);
    }

    //function to simulate c2b 
    public function simulate(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $amount = (int)($data["amount"] ?? 0);
        $phone  = $data["phone"] ?? "";
        $ref    = $data["account_ref"] ?? "TEST";

        if ($amount <= 0 || empty($phone)) {
            return $this->jsonResponse($response, ['error' => 'Invalid amount or phone'], 400);
        }

        try {
            $result = $this->mpesaService->simulateC2B($amount, $phone, $ref);
            return $this->jsonResponse($response, $result['response'], $result['status']);
        } catch (Exception $e) {
            $this->logger->error("C2B Simulation failed: " . $e->getMessage());
            return $this->jsonResponse($response, ['error' => 'Simulation failed'], 500);
        }
    }
}
