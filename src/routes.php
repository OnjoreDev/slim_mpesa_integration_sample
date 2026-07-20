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

    //Mpesa c2b routes
    $app->post('/api/v1/c2b/confirmation',[MpesaController::class,'confirmation']);

    $app->post('/api/v1/c2b/validation',[MpesaController::class,'validation']);

    $app->post('/api/v1/c2b/simulate',[MpesaController::class,'simulate']);

    $app->post('/api/v1/c2b/registerUrls',[MpesaController::class,'registerUrls']);



};