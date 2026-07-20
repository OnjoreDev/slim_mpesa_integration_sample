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
            return $this->jsonResponse($response,$result);

        } catch (Exception $e) {
            $this->logger->error("STK Push failed: " . $e->getMessage());
            return $this->jsonResponse($response, ['error' => $e->getMessage()], 500);
        }
    }
}
