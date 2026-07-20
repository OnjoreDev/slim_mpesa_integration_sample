<?php

declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;
use App\Models\Mpesa;
use Psr\Container\ContainerInterface;

class MpesaResponseController extends Controller
{

    private Mpesa $mpesa;

    public function __construct(ContainerInterface $containerInterface)
    {
        parent::__construct($containerInterface);
        $this->mpesa = $containerInterface->get(Mpesa::class);
    }

    public function handleCallBack(Request $request, Response $response): Response
    {
        $rawPayload = (string) $request->getBody(); //get payload from server
        $payload = json_decode($rawPayload, true); // convert the json payload into a php assoc array

        //log the information in the log file
        $this->logger->info("Mpesa raw payload " . $rawPayload);

        //get to the array with the index 'Body' 
        $stk = $payload['Body']['stkCallback'] ?? [];
        if (empty($stk["CheckoutRequestID"])) {
            $this->logger->error("Mpesa Callback Invalid structure");
            return $this->jsonResponse($response, ["ResultCode" => 1, "ResultDesc" => "Invalid payload"]);
        }

        $checkoutId = $stk['CheckoutRequestID'];
        $resultCode = (int)($stk['ResultCode'] ?? 1);

        if ($resultCode === 0) {
            $metadata = $stk['CallbackMetadata']['Item'] ?? [];
            $receipt = $this->extractReceipt($metadata);
            $this->mpesa->updateTransaction($checkoutId, 'completed', $receipt);
            $this->logger->info("Payment success for " . $checkoutId);
            return $this->jsonResponse($response, ['ResultCode' => 0, 'ResultDesc' => 'Success']);
        } else {
            $this->mpesa->updateTransactionStatus($checkoutId, 'failed');
            $this->logger->warning("Payment FAILED (Code $resultCode) for checkout: $checkoutId");
            return $this->jsonResponse($response, ['ResultCode' => 0, 'ResultDesc' => 'Failure processed']);
        }
    }

    //get the mpesa receipt number
    private function extractReceipt(array $items): string
    {
        foreach ($items as $item) {
            if (isset($item['Name']) && ($item['Name'] === 'MpesaReceiptNumber')) {
                return (string)$item['Value'];
            }
        }
        return 'N/A';
    }
}
