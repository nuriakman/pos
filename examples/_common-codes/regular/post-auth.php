<?php

use Mews\Pos\PosInterface;

// ilgili bankanin _config.php dosyasi load ediyoruz.
// ornegin /examples/finansbank-payfor/regular/_config.php
require '_config.php';

$templateTitle = 'Post Auth Order (ön provizyonu kapama)';

function createPostPayOrder(string $gatewayClass, array $lastResponse, string $ip, ?float $postAuthAmount = null): array
{
    $postAuth = [
        'id'       => $lastResponse['order_id'],
        'amount'   => $postAuthAmount ?? $lastResponse['amount'],
        'currency' => $lastResponse['currency'],
        'ip'       => filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? $ip : '127.0.0.1',
    ];

    if (\Mews\Pos\Gateways\GarantiPos::class === $gatewayClass) {
        $postAuth['ref_ret_num'] = $lastResponse['ref_ret_num'];
    }
    if (\Mews\Pos\Gateways\PosNetV1Pos::class === $gatewayClass || \Mews\Pos\Gateways\PosNet::class === $gatewayClass) {
        $postAuth['installment'] = $lastResponse['installment_count'];
        $postAuth['ref_ret_num'] = $lastResponse['ref_ret_num'];
    }

    return $postAuth;
}

$lastResponse = $session->get('last_response');

$preAuthAmount = $lastResponse['amount'];
// otorizasyon kapama amount'u ön otorizasyon amount'tan daha fazla olabilir.
$postAuthAmount = $lastResponse['amount'] + 0.02;
$gatewayClass = get_class($pos);

$order = createPostPayOrder(
    $gatewayClass,
    $lastResponse,
    $ip,
    $postAuthAmount
);

// ($preAuthAmount < $postAuthAmount) durumda API isteğe ekstra değerler eklenmesi gerekiyor.
/** @var \Symfony\Component\EventDispatcher\EventDispatcher $eventDispatcher */
$eventDispatcher->addListener(
    \Mews\Pos\Event\RequestDataPreparedEvent::class,
    function (\Mews\Pos\Event\RequestDataPreparedEvent $event) use ($gatewayClass, $preAuthAmount, $postAuthAmount) {
        if (\Mews\Pos\Gateways\EstPos::class === $gatewayClass || \Mews\Pos\Gateways\EstV3Pos::class === $gatewayClass) {
            if ($preAuthAmount < $postAuthAmount) {
                $requestData                    = $event->getRequestData();
                $requestData['Extra']['PREAMT'] = $preAuthAmount;
                $event->setRequestData($requestData);
            }
        }
    });

$transaction = PosInterface::TX_TYPE_PAY_POST_AUTH;

require '../../_templates/_finish_non_secure_post_auth_payment.php';