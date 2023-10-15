<?php

use Mews\Pos\PosInterface;

require '_config.php';
$templateTitle = 'Refund Order';
require '../../_templates/_header.php';

$ord = $session->get('order') ?: getNewOrder($baseUrl, $ip, $request->get('currency', PosInterface::CURRENCY_TRY));

$transaction = PosInterface::TX_REFUND;

// Refund Order
$pos->refund([
    // order id veya ref_ret_num (ReferenceCode) saglanmasi gerekiyor. Ikisinden biri zorunlu.
    // daha iyi performance icin ref_ret_num tercih edilmelidir.
    'id'          => $ord['id'],
    'ref_ret_num' => $session->get('ref_ret_num'),
    /**
     * payment_model:
     * siparis olusturulurken kullanilan odeme modeli.
     */
    'payment_model' => PosInterface::MODEL_3D_SECURE,
    'amount'      => $ord['amount'],
    'currency'    => $ord['currency'],
]);

$response = $pos->getResponse();
require '../../_templates/_simple_response_dump.php';
require '../../_templates/_footer.php';
