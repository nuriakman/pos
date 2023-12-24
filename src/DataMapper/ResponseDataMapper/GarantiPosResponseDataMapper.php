<?php
/**
 * @license MIT
 */

namespace Mews\Pos\DataMapper\ResponseDataMapper;

use Mews\Pos\Exceptions\NotImplementedException;
use Mews\Pos\PosInterface;

/**
 * @phpstan-type PaymentStatusModel array{Order: array<string, string|array<string, string|null>>, Response: array<string, string>, Transaction: array<string, string>|array{Response: array<string, string>}}
 */
class GarantiPosResponseDataMapper extends AbstractResponseDataMapper
{
    /**
     * Response Codes
     *
     * @var array<int|string, string>
     */
    protected array $codes = [
        self::PROCEDURE_SUCCESS_CODE => self::TX_APPROVED,

        '96' => 'general_error',
        '01' => 'bank_call',
        '02' => 'bank_call',
        '05' => 'reject',
        '09' => 'try_again',
        '12' => 'invalid_transaction',
        '28' => 'reject',
        '51' => 'insufficient_balance',
        '54' => 'expired_card',
        '57' => 'does_not_allow_card_holder',
        '62' => 'restricted_card',
        '77' => 'request_rejected',
        '92' => 'invalid_transaction',
        '99' => 'general_error',
    ];

    /**
     * @param PaymentStatusModel $rawPaymentResponseData
     * {@inheritDoc}
     */
    public function mapPaymentResponse(array $rawPaymentResponseData, string $txType, array $order): array
    {
        /** @var PaymentStatusModel $rawPaymentResponseData */
        $rawPaymentResponseData = $this->emptyStringsToNull($rawPaymentResponseData);
        $procReturnCode         = $this->getProcReturnCode($rawPaymentResponseData);
        $this->logger->debug('mapping payment response', [$rawPaymentResponseData]);
        $status = self::TX_DECLINED;
        if (self::PROCEDURE_SUCCESS_CODE === $procReturnCode) {
            $status = self::TX_APPROVED;
        }

        $defaultResponse = $this->getDefaultPaymentResponse($txType, PosInterface::MODEL_NON_SECURE);
        $transaction     = $rawPaymentResponseData['Transaction'];

        $mappedResponse = [
            'order_id'         => $rawPaymentResponseData['Order']['OrderID'],
            'group_id'         => $rawPaymentResponseData['Order']['GroupID'],
            'auth_code'        => self::TX_APPROVED === $status ? $transaction['AuthCode'] : null,
            'ref_ret_num'      => self::TX_APPROVED === $status ? $transaction['RetrefNum'] : null,
            'proc_return_code' => $procReturnCode,
            'status'           => $status,
            'currency'         => $order['currency'],
            'amount'           => $order['amount'],
            'status_detail'    => $this->getStatusDetail($procReturnCode),
            'error_code'       => self::TX_APPROVED !== $status ? $transaction['Response']['ReasonCode'] : null,
            'error_message'    => self::TX_APPROVED !== $status ? $transaction['Response']['ErrorMsg'] : null,
            'all'              => $rawPaymentResponseData,
        ];

        $this->logger->debug('mapped payment response', $mappedResponse);

        return $this->mergeArraysPreferNonNullValues($defaultResponse, $mappedResponse);
    }

    /**
     * @param PaymentStatusModel|null $rawPaymentResponseData
     * {@inheritdoc}
     */
    public function map3DPaymentData(array $raw3DAuthResponseData, ?array $rawPaymentResponseData, string $txType, array $order): array
    {
        $raw3DAuthResponseData = $this->emptyStringsToNull($raw3DAuthResponseData);
        /** @var PaymentStatusModel|null $rawPaymentResponseData */
        $rawPaymentResponseData = $this->emptyStringsToNull($rawPaymentResponseData);
        $this->logger->debug('mapping 3D payment data', [
            '3d_auth_response'   => $raw3DAuthResponseData,
            'provision_response' => $rawPaymentResponseData,
        ]);

        $commonResult = $this->map3DCommonResponseData($raw3DAuthResponseData);

        // todo refactor
        if (\in_array($raw3DAuthResponseData['mdstatus'], ['1', '2', '3', '4'], true)) {
            //these data only available on success
            $commonResult['auth_code']     = $raw3DAuthResponseData['authcode'];
            $commonResult['trans_id']      = $raw3DAuthResponseData['transid'];
            $commonResult['ref_ret_num']   = $raw3DAuthResponseData['hostrefnum'];
            $commonResult['masked_number'] = $raw3DAuthResponseData['MaskedPan'];
            $commonResult['tx_status']     = $raw3DAuthResponseData['txnstatus'];
            $commonResult['eci']           = $raw3DAuthResponseData['eci'];
            $commonResult['cavv']          = $raw3DAuthResponseData['cavv'];
        }

        $paymentStatus          = self::TX_DECLINED;
        $paymentModel           = $this->mapSecurityType($raw3DAuthResponseData['secure3dsecuritylevel']);
        $defaultPaymentResponse = $this->getDefaultPaymentResponse($txType, $paymentModel);
        $mappedPaymentResponse  = [];
        if (self::TX_APPROVED === $commonResult['status'] && null !== $rawPaymentResponseData) {
            $transaction    = $rawPaymentResponseData['Transaction'];
            $procReturnCode = $this->getProcReturnCode($rawPaymentResponseData);
            if (self::PROCEDURE_SUCCESS_CODE === $procReturnCode) {
                $paymentStatus = self::TX_APPROVED;
            }

            $mappedPaymentResponse = [
                'group_id'         => $transaction['SequenceNum'] ?? null,
                'auth_code'        => $transaction['AuthCode'] ?? null,
                'ref_ret_num'      => $transaction['RetrefNum'] ?? null,
                'batch_num'        => $transaction['BatchNum'] ?? null,
                'error_code'       => self::TX_APPROVED === $paymentStatus ? null : $transaction['Response']['ReasonCode'],
                'error_message'    => self::TX_APPROVED === $paymentStatus ? null : $transaction['Response']['ErrorMsg'],
                'all'              => $rawPaymentResponseData,
                'proc_return_code' => $procReturnCode,
                'status'           => $paymentStatus,
                'status_detail'    => $this->getStatusDetail($procReturnCode),
            ];

            $mappedPaymentResponse = $this->mergeArraysPreferNonNullValues($defaultPaymentResponse, $mappedPaymentResponse);
        }

        if ([] === $mappedPaymentResponse) {
            return $this->mergeArraysPreferNonNullValues($defaultPaymentResponse, $commonResult);
        }

        return $this->mergeArraysPreferNonNullValues($commonResult, $mappedPaymentResponse);
    }

    /**
     * {@inheritdoc}
     */
    public function map3DPayResponseData(array $raw3DAuthResponseData, string $txType, array $order): array
    {
        $raw3DAuthResponseData = $this->emptyStringsToNull($raw3DAuthResponseData);

        $threeDAuthResult = $this->map3DCommonResponseData($raw3DAuthResponseData);
        $threeDAuthStatus = $threeDAuthResult['status'];
        $paymentStatus = self::TX_DECLINED;
        $procReturnCode = $raw3DAuthResponseData['procreturncode'];
        if (self::TX_APPROVED === $threeDAuthStatus && self::PROCEDURE_SUCCESS_CODE === $procReturnCode) {
            $paymentStatus = self::TX_APPROVED;
        }
        $paymentModel           = $this->mapSecurityType($raw3DAuthResponseData['secure3dsecuritylevel']);
        $defaultPaymentResponse = $this->getDefaultPaymentResponse(
            $this->mapTxType($raw3DAuthResponseData['txntype']) ?? $txType,
            $paymentModel
        );
        $defaultPaymentResponse['status'] = $paymentStatus;

        if (self::TX_APPROVED === $threeDAuthStatus) {
            $threeDAuthResult['auth_code']     = $raw3DAuthResponseData['authcode'];
            $threeDAuthResult['trans_id']      = $raw3DAuthResponseData['transid'];
            $threeDAuthResult['ref_ret_num']   = $raw3DAuthResponseData['hostrefnum'];
            $threeDAuthResult['masked_number'] = $raw3DAuthResponseData['MaskedPan'];
            $threeDAuthResult['tx_status']     = $raw3DAuthResponseData['txnstatus'];
            $threeDAuthResult['eci']           = $raw3DAuthResponseData['eci'];
            $threeDAuthResult['cavv']          = $raw3DAuthResponseData['cavv'];
        }

        if (self::TX_APPROVED !== $paymentStatus) {
            $defaultPaymentResponse['error_message'] = $raw3DAuthResponseData['errmsg'];
            $defaultPaymentResponse['error_code']    = $procReturnCode;
        }

        return $this->mergeArraysPreferNonNullValues($threeDAuthResult, $defaultPaymentResponse);
    }

    /**
     * {@inheritdoc}
     */
    public function map3DHostResponseData(array $raw3DAuthResponseData, string $txType, array $order): array
    {
        throw new NotImplementedException();
    }

    /**
     * {@inheritdoc}
     */
    public function mapRefundResponse(array $rawResponseData): array
    {
        return $this->mapCancelResponse($rawResponseData);
    }

    /**
     * @param PaymentStatusModel|array<string, string> $rawResponseData
     * {@inheritdoc}
     */
    public function mapCancelResponse(array $rawResponseData): array
    {
        /** @var PaymentStatusModel $rawResponseData */
        $rawResponseData = $this->emptyStringsToNull($rawResponseData);
        $procReturnCode  = $this->getProcReturnCode($rawResponseData);
        $status          = self::TX_DECLINED;
        if (self::PROCEDURE_SUCCESS_CODE === $procReturnCode) {
            $status = self::TX_APPROVED;
        }

        $transaction = $rawResponseData['Transaction'];


        return [
            'order_id' => $rawResponseData['Order']['OrderID'] ?? null,
            'group_id' => $rawResponseData['Order']['GroupID'] ?? null,
            'trans_id' => null,
            'auth_code'        => $transaction['AuthCode'] ?? null,
            'ref_ret_num'      => $transaction['RetrefNum'] ?? null,
            'proc_return_code' => $procReturnCode,
            'error_code'       => $transaction['Response']['Code'] ?? null,
            'error_message'    => $transaction['Response']['ErrorMsg'] ?? null,
            'status'           => $status,
            'status_detail'    => $this->getStatusDetail($procReturnCode),
            'all'              => $rawResponseData,
        ];
    }

    /**
     * @param PaymentStatusModel|array<string, string> $rawResponseData
     * {@inheritdoc}
     */
    public function mapStatusResponse(array $rawResponseData): array
    {
        /** @var PaymentStatusModel $rawResponseData */
        $rawResponseData = $this->emptyStringsToNull($rawResponseData);
        $procReturnCode  = $this->getProcReturnCode($rawResponseData);
        $status          = self::TX_DECLINED;
        if (self::PROCEDURE_SUCCESS_CODE === $procReturnCode) {
            $status = self::TX_APPROVED;
        }

        $transaction = $rawResponseData['Transaction'];
        $amount = $rawResponseData['Order']['OrderInqResult']['AuthAmount'];

        return [
            'order_id'         => $rawResponseData['Order']['OrderID'] ?? null,
            'group_id'         => $rawResponseData['Order']['GroupID'] ?? null,
            'amount'           => null !== $amount ? $this->formatAmount($amount) : null,
            'trans_id'         => null,
            'auth_code'        => $transaction['AuthCode'] ?? null,
            'ref_ret_num'      => $transaction['RetrefNum'] ?? null,
            'proc_return_code' => $procReturnCode,
            'status'           => $status,
            'status_detail'    => $this->getStatusDetail($procReturnCode),
            'error_code'       => $transaction['Response']['Code'] ?? null,
            'error_message'    => $transaction['Response']['ErrorMsg'] ?? null,
            'all'              => $rawResponseData,
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function mapHistoryResponse(array $rawResponseData): array
    {
        return $this->emptyStringsToNull($rawResponseData);
    }

    /**
     * 100001 => 1000.01
     * @param string $amount
     *
     * @return float
     */
    protected function formatAmount(string $amount): float
    {
        return ((float) $amount) / 100;
    }

    /**
     * returns mapped data of the common response data among all 3d models.
     *
     * @param array<string, string> $raw3DAuthResponseData
     *
     * @return array<string, mixed>
     */
    protected function map3DCommonResponseData(array $raw3DAuthResponseData): array
    {
        $procReturnCode = $raw3DAuthResponseData['procreturncode'];
        $mdStatus       = $raw3DAuthResponseData['mdstatus'];

        $status = self::TX_DECLINED;

        if (\in_array($mdStatus, ['1', '2', '3', '4']) && 'Error' !== $raw3DAuthResponseData['response']) {
            $status = self::TX_APPROVED;
        }

        return [
            'order_id'             => $raw3DAuthResponseData['oid'],
            'trans_id'             => null,
            'auth_code'            => null,
            'ref_ret_num'          => null,
            'transaction_security' => $this->mapResponseTransactionSecurity($mdStatus),
            'transaction_type'     => $this->mapTxType($raw3DAuthResponseData['txntype']),
            'proc_return_code'     => $procReturnCode,
            'md_status'            => $raw3DAuthResponseData['mdstatus'],
            'status'               => $status,
            'status_detail'        => $this->getStatusDetail($procReturnCode),
            'masked_number'        => null,
            'amount'               => $this->formatAmount($raw3DAuthResponseData['txnamount']),
            'currency'             => $this->mapCurrency($raw3DAuthResponseData['txncurrencycode']),
            'tx_status'            => null,
            'eci'                  => null,
            'cavv'                 => null,
            'error_code'           => 'Error' === $raw3DAuthResponseData['response'] ? $procReturnCode : null,
            'error_message'        => self::TX_APPROVED === $status ? null : $raw3DAuthResponseData['errmsg'],
            'md_error_message'     => self::TX_APPROVED === $status ? null : $raw3DAuthResponseData['mderrormessage'],
            '3d_all'               => $raw3DAuthResponseData,
        ];
    }

    /**
     * @param string $mdStatus
     *
     * @return string
     */
    protected function mapResponseTransactionSecurity(string $mdStatus): string
    {
        if (!in_array($mdStatus, ['1', '2', '3', '4'])) {
            return 'MPI fallback';
        }

        if ('1' === $mdStatus) {
            return 'Full 3D Secure';
        }

        // ['2', '3', '4']
        return 'Half 3D Secure';
    }

    /**
     * Get Status Detail Text
     *
     * @param string|null $procReturnCode
     *
     * @return string|null
     */
    protected function getStatusDetail(?string $procReturnCode): ?string
    {
        return $this->codes[$procReturnCode] ?? null;
    }

    /**
     * Get ProcReturnCode
     *
     * @phpstan-param PaymentStatusModel $response
     *
     * @param array $response
     *
     * @return string|null
     */
    protected function getProcReturnCode(array $response): ?string
    {
        return $response['Transaction']['Response']['Code'] ?? null;
    }
}
