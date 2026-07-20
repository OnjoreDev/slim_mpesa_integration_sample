<?php

declare(strict_types=1);


use Slim\App;
use App\Controllers\MpesaController;
use App\Controllers\MpesaResponseController;

return function (App $app) {

    //gettoken
    $app->get('/api/v1/token',[MpesaController::class,'getToken']);

    // Mpesa STK Push routes
    $app->post('/api/v1/stk/initiate', [MpesaController::class, 'initiateStkPush']);
    
    // Mpesa Callback route (must match the URL defined in your .env)
    $app->post('/api/v1/payment-hook', [MpesaResponseController::class, 'handleCallBack']);
};