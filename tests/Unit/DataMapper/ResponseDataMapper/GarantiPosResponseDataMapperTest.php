<?php
/**
 * @license MIT
 */

namespace Mews\Pos\Tests\Unit\DataMapper\ResponseDataMapper;

use Mews\Pos\DataMapper\RequestDataMapper\GarantiPosRequestDataMapper;
use Mews\Pos\DataMapper\ResponseDataMapper\GarantiPosResponseDataMapper;
use Mews\Pos\Factory\CryptFactory;
use Mews\Pos\Gateways\GarantiPos;
use Mews\Pos\PosInterface;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\NullLogger;

/**
 * @covers \Mews\Pos\DataMapper\ResponseDataMapper\GarantiPosResponseDataMapper
 */
class GarantiPosResponseDataMapperTest extends TestCase
{
    private GarantiPosResponseDataMapper $responseDataMapper;

    protected function setUp(): void
    {
        parent::setUp();

        $crypt                    = CryptFactory::createGatewayCrypt(GarantiPos::class, new NullLogger());
        $requestDataMapper        = new GarantiPosRequestDataMapper($this->createMock(EventDispatcherInterface::class), $crypt);
        $this->responseDataMapper = new GarantiPosResponseDataMapper(
            $requestDataMapper->getCurrencyMappings(),
            $requestDataMapper->getTxTypeMappings(),
            $requestDataMapper->getSecureTypeMappings(),
            new NullLogger()
        );
    }

    /**
     * @dataProvider paymentTestDataProvider
     */
    public function testMapPaymentResponse(array $order, string $txType, array $responseData, array $expectedData)
    {
        $actualData = $this->responseDataMapper->mapPaymentResponse($responseData, $txType, $order);
        unset($actualData['all']);
        $this->assertSame($expectedData, $actualData);
    }

    /**
     * @dataProvider threeDPaymentDataProvider
     */
    public function testMap3DPaymentData(array $order, string $txType, array $threeDResponseData, array $paymentResponse, array $expectedData)
    {
        $actualData = $this->responseDataMapper->map3DPaymentData(
            $threeDResponseData,
            $paymentResponse,
            $txType,
            $order
        );
        unset($actualData['all'], $actualData['3d_all']);
        \ksort($expectedData);
        \ksort($actualData);
        $this->assertSame($expectedData, $actualData);
    }

    /**
     * @dataProvider threeDPayPaymentDataProvider
     */
    public function testMap3DPayResponseData(array $order, string $txType, array $responseData, array $expectedData)
    {
        $actualData = $this->responseDataMapper->map3DPayResponseData($responseData, $txType, $order);
        unset($actualData['all'], $actualData['3d_all']);
        \ksort($expectedData);
        \ksort($actualData);
        $this->assertSame($expectedData, $actualData);
    }

    /**
     * @dataProvider statusTestDataProvider
     */
    public function testMapStatusResponse(array $responseData, array $expectedData)
    {
        $actualData = $this->responseDataMapper->mapStatusResponse($responseData);
        unset($actualData['all']);
        $this->assertSame($expectedData, $actualData);
    }

    /**
     * @dataProvider refundTestDataProvider
     */
    public function testMapRefundResponse(array $responseData, array $expectedData)
    {
        $actualData = $this->responseDataMapper->mapRefundResponse($responseData);
        unset($actualData['all']);
        $this->assertSame($expectedData, $actualData);
    }

    /**
     * @dataProvider cancelTestDataProvider
     */
    public function testMapCancelResponse(array $responseData, array $expectedData)
    {
        $actualData = $this->responseDataMapper->mapCancelResponse($responseData);
        unset($actualData['all']);
        $this->assertSame($expectedData, $actualData);
    }

    public static function paymentTestDataProvider(): array
    {
        return [
            'success1' => [
                'order'        => [
                    'currency' => PosInterface::CURRENCY_TRY,
                    'amount'   => 1.01,
                ],
                'txType'       => PosInterface::TX_TYPE_PAY,
                'responseData' => [
                    'Mode'        => '',
                    'Terminal'    => [
                        'ProvUserID' => 'PROVAUT',
                        'UserID'     => 'PROVAUT',
                        'ID'         => '30691298',
                        'MerchantID' => '7000679',
                    ],
                    'Customer'    => [
                        'IPAddress' => '172.26.0.1',
                    ],
                    'Order'       => [
                        'OrderID' => '20221101D723',
                        'GroupID' => '',
                    ],
                    'Transaction' => [
                        'Response'         => [
                            'Source'     => 'HOST',
                            'Code'       => '00',
                            'ReasonCode' => '00',
                            'Message'    => 'Approved',
                            'ErrorMsg'   => '',
                            'SysErrMsg'  => '',
                        ],
                        'RetrefNum'        => '230508300434',
                        'AuthCode'         => '304919',
                        'BatchNum'         => '004951',
                        'SequenceNum'      => '000015',
                        'ProvDate'         => '20221101 13:14:19',
                        'CardNumberMasked' => '428220******8015',
                        'CardHolderName'   => 'HA*** YIL***',
                        'CardType'         => 'FLEXI',
                        'HashData'         => '1AAF91AE8000A94BF0B3FF42222E75E5837C98B9',
                        'HostMsgList'      => '',
                        'RewardInqResult'  => [
                            'RewardList' => '',
                            'ChequeList' => '',
                        ],
                        'GarantiCardInd'   => 'Y',
                    ],
                ],
                'expectedData' => [
                    'trans_id'         => null,
                    'transaction_type' => 'pay',
                    'payment_model'    => 'regular',
                    'group_id'         => null,
                    'order_id'         => '20221101D723',
                    'currency'         => 'TRY',
                    'amount'           => 1.01,
                    'auth_code'        => '304919',
                    'ref_ret_num'      => '230508300434',
                    'proc_return_code' => '00',
                    'status'           => 'approved',
                    'status_detail'    => 'approved',
                    'error_code'       => null,
                    'error_message'    => null,
                ],
            ],
            //fail case
            [
                'order'        => [
                    'currency' => PosInterface::CURRENCY_TRY,
                    'amount'   => 1.01,
                ],
                'txType'       => PosInterface::TX_TYPE_PAY,
                'responseData' => [
                    'Mode'        => '',
                    'Terminal'    => [
                        'ProvUserID' => 'PROVAUT',
                        'UserID'     => 'PROVAUT',
                        'ID'         => '30691298',
                        'MerchantID' => '7000679',
                    ],
                    'Customer'    => [
                        'IPAddress' => '172.26.0.1',
                    ],
                    'Order'       => [
                        'OrderID' => '2022110189E1',
                        'GroupID' => '',
                    ],
                    'Transaction' => [
                        'Response'         => [
                            'Source'     => 'GVPS',
                            'Code'       => '92',
                            'ReasonCode' => '0002',
                            'Message'    => 'Declined',
                            'ErrorMsg'   => 'Giriş yaptığınız işlem tipi için zorunlu alanları kontrol ediniz',
                            'SysErrMsg'  => 'TxnAmount field must not be zero DOUBLE value because of the Mandatory Rule:zero',
                        ],
                        'RetrefNum'        => '',
                        'AuthCode'         => '',
                        'BatchNum'         => '',
                        'SequenceNum'      => '',
                        'ProvDate'         => '20221101 13:19:22',
                        'CardNumberMasked' => '428220******8015',
                        'CardHolderName'   => '',
                        'CardType'         => '',
                        'HashData'         => 'FCA7BDA4204E448FF2695358D22E3B75125DC396',
                        'HostMsgList'      => '',
                        'RewardInqResult'  => [
                            'RewardList' => '',
                            'ChequeList' => '',
                        ],
                        'GarantiCardInd'   => 'Y',
                    ],
                ],
                'expectedData' => [
                    'trans_id'         => null,
                    'transaction_type' => 'pay',
                    'payment_model'    => 'regular',
                    'group_id'         => null,
                    'order_id'         => '2022110189E1',
                    'currency'         => 'TRY',
                    'amount'           => 1.01,
                    'auth_code'        => null,
                    'ref_ret_num'      => null,
                    'proc_return_code' => '92',
                    'status'           => 'declined',
                    'status_detail'    => 'invalid_transaction',
                    'error_code'       => '0002',
                    'error_message'    => 'Giriş yaptığınız işlem tipi için zorunlu alanları kontrol ediniz',
                ],
            ],
        ];
    }


    public static function threeDPaymentDataProvider(): array
    {
        return [
            'paymentFail1'               => [
                'order'              => [],
                'txType'             => PosInterface::TX_TYPE_PAY,
                'threeDResponseData' => [
                    'xid'                   => 'RszfrwEYe/8xb7rnrPuh6C9pZSQ=',
                    'mdstatus'              => '1',
                    'mderrormessage'        => 'Authenticated',
                    'txnstatus'             => 'Y',
                    'eci'                   => '02',
                    'cavv'                  => 'jCm0m+u/0hUfAREHBAMBcfN+pSo=',
                    'paressyntaxok'         => 'true',
                    'paresverified'         => 'true',
                    'version'               => '2.0',
                    'ireqcode'              => '',
                    'ireqdetail'            => '',
                    'vendorcode'            => '',
                    'cavvalgorithm'         => '3',
                    'md'                    => 'G1YfkxEZ8Noemg4MRspO20vEiXaEk51ANsgVc6NOy8kHpgH0Bj2jGdc4n47VV2IxRcLSwiw3+DC4zpyj2qtCo8LA5ACL2pHmusSpDmp+kAJOIQTFpsCfJ53tob4+xTUbctQuxBd4u+Bqs1looyNEeg==',
                    'terminalid'            => '30691298',
                    'oid'                   => '20221101295D',
                    'authcode'              => '',
                    'response'              => '',
                    'errmsg'                => '',
                    'hostmsg'               => '',
                    'procreturncode'        => '',
                    'transid'               => '20221101295D',
                    'hostrefnum'            => '',
                    'rnd'                   => 'Nvx8y+0R3sR5mfDVLtVD',
                    'hash'                  => 'K1eaT12s4oPbvQDfA6YIMCfH6HQ=',
                    'hashparams'            => 'clientid:oid:authcode:procreturncode:response:mdstatus:cavv:eci:md:rnd:',
                    'hashparamsval'         => '3069129820221101295D1jCm0m+u/0hUfAREHBAMBcfN+pSo=02G1YfkxEZ8Noemg4MRspO20vEiXaEk51ANsgVc6NOy8kHpgH0Bj2jGdc4n47VV2IxRcLSwiw3+DC4zpyj2qtCo8LA5ACL2pHmusSpDmp+kAJOIQTFpsCfJ53tob4+xTUbctQuxBd4u+Bqs1looyNEeg==Nvx8y+0R3sR5mfDVLtVD',
                    'clientid'              => '30691298',
                    'MaskedPan'             => '428220***8015',
                    'apiversion'            => 'v0.01',
                    'orderid'               => '20221101295D',
                    'txninstallmentcount'   => '',
                    'terminaluserid'        => 'PROVAUT',
                    'secure3dhash'          => 'DCC371FD21BCFDEE9F9B4B86D3CD304C34D3FD51',
                    'secure3dsecuritylevel' => '3D',
                    'txncurrencycode'       => '949',
                    'errorurl'              => 'http://localhost/garanti/3d/response.php',
                    'terminalmerchantid'    => '7000679',
                    'mode'                  => 'TEST',
                    'terminalprovuserid'    => 'PROVAUT',
                    'txnamount'             => '0',
                    'successurl'            => 'http://localhost/garanti/3d/response.php',
                    'customeripaddress'     => '172.26.0.1',
                    'txntype'               => 'sales',
                ],
                'paymentData'        => [
                    'Mode'        => '',
                    'Terminal'    => [
                        'ProvUserID' => 'PROVAUT',
                        'UserID'     => 'PROVAUT',
                        'ID'         => '30691298',
                        'MerchantID' => '7000679',
                    ],
                    'Customer'    => [
                        'IPAddress' => '172.26.0.1',
                    ],
                    'Order'       => [
                        'OrderID' => '20221101295D',
                        'GroupID' => '',
                    ],
                    'Transaction' => [
                        'Response'         => [
                            'Source'     => 'GVPS',
                            'Code'       => '92',
                            'ReasonCode' => '0002',
                            'Message'    => 'Declined',
                            'ErrorMsg'   => 'Giriş yaptığınız işlem tipi için zorunlu alanları kontrol ediniz',
                            'SysErrMsg'  => 'TxnAmount field must not be zero DOUBLE value because of the Mandatory Rule:zero',
                        ],
                        'RetrefNum'        => '',
                        'AuthCode'         => '',
                        'BatchNum'         => '',
                        'SequenceNum'      => '',
                        'ProvDate'         => '20221101 14:02:40',
                        'CardNumberMasked' => '',
                        'CardHolderName'   => '',
                        'CardType'         => '',
                        'HashData'         => '520A24F019779AEA141ECA8C2F2B3654C65286FE',
                        'HostMsgList'      => '',
                        'RewardInqResult'  => [
                            'RewardList' => '',
                            'ChequeList' => '',
                        ],
                    ],
                ],
                'expectedData'       => [
                    'order_id'             => '20221101295D',
                    'trans_id'             => '20221101295D',
                    'auth_code'            => null,
                    'ref_ret_num'          => null,
                    'transaction_security' => 'Full 3D Secure',
                    'proc_return_code'     => '92',
                    'md_status'            => '1',
                    'status'               => 'declined',
                    'status_detail'        => 'invalid_transaction',
                    'masked_number'        => '428220***8015',
                    'amount'               => 0.0,
                    'currency'             => PosInterface::CURRENCY_TRY,
                    'tx_status'            => 'Y',
                    'eci'                  => '02',
                    'cavv'                 => 'jCm0m+u/0hUfAREHBAMBcfN+pSo=',
                    'error_code'           => '0002',
                    'error_message'        => 'Giriş yaptığınız işlem tipi için zorunlu alanları kontrol ediniz',
                    'md_error_message'     => null,
                    'group_id'             => null,
                    'batch_num'            => null,
                    'transaction_type'     => 'pay',
                    'payment_model'        => '3d',
                ],
            ],
            'paymentFail_wrong_cvc_code' => [
                'order'              => [],
                'txType'             => PosInterface::TX_TYPE_PAY,
                'threeDResponseData' => [
                    'xid'                   => 'fbd8e1ec-3d98-499d-9578-cf5380f208bc',
                    'mdstatus'              => '1',
                    'mderrormessage'        => 'Y-status/Challenge authentication via ACS: https://gbemv3dsecure.garanti.com.tr/web/creq',
                    'txnstatus'             => null,
                    'eci'                   => '02',
                    'cavv'                  => 'xgT+4XVHAAAAAAAAAAAAAAAAAAA=',
                    'paressyntaxok'         => null,
                    'paresverified'         => null,
                    'version'               => null,
                    'ireqcode'              => null,
                    'ireqdetail'            => null,
                    'vendorcode'            => null,
                    'cavvalgorithm'         => null,
                    'md'                    => 'aW5kZXg6MDJrx8O9qwUvrCPAHSeJG+tDd41i3MI4NE2sFbvci41eCZnWHTzhbenpZpxHwicr3CWCseFLj49EJGq31hSU1Ll+j4PQ3y2dm+BzWtOIhoc7eqN7mtmCUt1bnoOk1bHvo49vm44jgIjzXcXY7kLFj+VdhG71kIx40nXmFstuuNn3kQ==',
                    'terminalid'            => '30691298',
                    'oid'                   => '20231223D98E',
                    'authcode'              => null,
                    'response'              => null,
                    'errmsg'                => null,
                    'hostmsg'               => null,
                    'procreturncode'        => null,
                    'transid'               => '20231223D98E',
                    'hostrefnum'            => null,
                    'rnd'                   => '/SXt7jTwxd7XjieE1z9H',
                    'hash'                  => 'C8B7F490BBC076A280B8FFBF33608D3CF73E4E6272699C3A57D9BA4B16905EEE9BE6FC41FCE0401FF66EB2E74441EC12A12BCC00F861F922FE7126307D42F456',
                    'hashparams'            => 'clientid:oid:authcode:procreturncode:response:mdstatus:cavv:eci:md:rnd:',
                    'hashparamsval'         => '3069129820231223D98E1xgT+4XVHAAAAAAAAAAAAAAAAAAA=02aW5kZXg6MDJrx8O9qwUvrCPAHSeJG+tDd41i3MI4NE2sFbvci41eCZnWHTzhbenpZpxHwicr3CWCseFLj49EJGq31hSU1Ll+j4PQ3y2dm+BzWtOIhoc7eqN7mtmCUt1bnoOk1bHvo49vm44jgIjzXcXY7kLFj+VdhG71kIx40nXmFstuuNn3kQ==/SXt7jTwxd7XjieE1z9H',
                    'clientid'              => '30691298',
                    'MaskedPan'             => '55496087****1500',
                    'apiversion'            => '512',
                    'orderid'               => '20231223D98E',
                    'txninstallmentcount'   => null,
                    'terminaluserid'        => 'PROVAUT',
                    'secure3dhash'          => '4D82C430D5C860D7B78D180DFA7F03C0C75ED796E97A9486762B6F09F66F18399111E3501CD56D560D01CF3D96399B637BE6A8531190144264585AEAB372483F',
                    'secure3dsecuritylevel' => '3D',
                    'txncurrencycode'       => '949',
                    'errorurl'              => 'http://localhost/garanti/3d/response.php',
                    'terminalmerchantid'    => '7000679',
                    'mode'                  => 'TEST',
                    'terminalprovuserid'    => 'PROVAUT',
                    'txnamount'             => '101',
                    'successurl'            => 'http://localhost/garanti/3d/response.php',
                    'txntype'               => 'sales',
                    'customeripaddress'     => '172.26.0.1',
                ],
                'paymentData'        => [
                    'Mode'        => null,
                    'Terminal'    => [
                        'ProvUserID' => 'PROVAUT',
                        'UserID'     => 'PROVAUT',
                        'ID'         => '30691298',
                        'MerchantID' => '7000679',
                    ],
                    'Customer'    => [
                        'IPAddress'    => '172.26.0.1',
                        'EmailAddress' => null,
                    ],
                    'Order'       => [
                        'OrderID' => '20231223D98E',
                        'GroupID' => null,
                    ],
                    'Transaction' => [
                        'Response'         => [
                            'Source'     => 'HOST',
                            'Code'       => '12',
                            'ReasonCode' => '12',
                            'Message'    => 'Declined',
                            'ErrorMsg'   => 'İşleminizi gerçekleştiremiyoruz.Tekrar deneyiniz',
                            'SysErrMsg'  => 'CVC2/4CSC HATALI',
                        ],
                        'RetrefNum'        => '335709663083',
                        'AuthCode'         => null,
                        'BatchNum'         => '005546',
                        'SequenceNum'      => '000082',
                        'ProvDate'         => '20231223 19:28:20',
                        'CardNumberMasked' => '55496087****1500',
                        'CardHolderName'   => '4517******* 4517**********',
                        'CardType'         => 'BONUS',
                        'HashData'         => '9DDE1AFD673462C49AD5CBEB13139DE550D4F863A34842843270713577659F38C510B0BBF98DE6BCAA4ABDE382B3597672B9E508E67D0941DF26789132E281DE',
                        'HostMsgList'      => null,
                        'RewardInqResult'  => [
                            'RewardList' => null,
                            'ChequeList' => null,
                        ],
                        'GarantiCardInd'   => 'Y',
                    ],
                ],
                'expectedData'       => [
                    'order_id'             => '20231223D98E',
                    'trans_id'             => '20231223D98E',
                    'auth_code'            => null,
                    'ref_ret_num'          => '335709663083',
                    'transaction_security' => 'Full 3D Secure',
                    'proc_return_code'     => '12',
                    'md_status'            => '1',
                    'status'               => 'declined',
                    'status_detail'        => 'invalid_transaction',
                    'masked_number'        => '55496087****1500',
                    'amount'               => 1.01,
                    'currency'             => 'TRY',
                    'tx_status'            => null,
                    'eci'                  => '02',
                    'cavv'                 => 'xgT+4XVHAAAAAAAAAAAAAAAAAAA=',
                    'error_code'           => '12',
                    'error_message'        => 'İşleminizi gerçekleştiremiyoruz.Tekrar deneyiniz',
                    'md_error_message'     => null,
                    'group_id'             => '000082',
                    'batch_num'            => '005546',
                    'transaction_type'     => 'pay',
                    'payment_model'        => '3d',
                ],
            ],
            'success1'                   => [
                'order'              => [],
                'txType'             => PosInterface::TX_TYPE_PAY,
                'threeDResponseData' => [
                    'xid'                   => '748ac354-4bfe-4b40-aa12-5ea025b7399b',
                    'mdstatus'              => '1',
                    'mderrormessage'        => 'Y-status/Challenge authentication via ACS: https://gbemv3dsecure.garanti.com.tr/web/creq',
                    'txnstatus'             => null,
                    'eci'                   => '02',
                    'cavv'                  => 'xgRWtC2UAAAAAAAAAAAAAAAAAAA=',
                    'paressyntaxok'         => null,
                    'paresverified'         => null,
                    'version'               => null,
                    'ireqcode'              => null,
                    'ireqdetail'            => null,
                    'vendorcode'            => null,
                    'cavvalgorithm'         => null,
                    'md'                    => 'aW5kZXg6MDJrx8O9qwUvrCPAHSeJG+tDncPcvXkhbmvZPQakkqHX/hMEIzcDkmnDsIBA8BD5zX/aDIAerqJ/h7GIw2VTtNaGjN7JZhmwVSL65/agw5g0JbmcRy40JE3ZjoEvP060kaUVxk66R8U+NJ2jSDj2mYeF ▶',
                    'terminalid'            => '30691298',
                    'oid'                   => '202312238064',
                    'authcode'              => null,
                    'response'              => null,
                    'errmsg'                => null,
                    'hostmsg'               => null,
                    'procreturncode'        => null,
                    'transid'               => '202312238064',
                    'hostrefnum'            => null,
                    'rnd'                   => 'QFEBiW9lrfqK1olQ5UqN',
                    'hash'                  => '7C717431E3763C5C9CCAFE7B905B29A120982D4840DFC61926A5737C0B8BA6D4D00DA1C481E429E12D89D827D09B36074913BAD792A91E95DBFCD3CB68A0FDB5',
                    'hashparams'            => 'clientid:oid:authcode:procreturncode:response:mdstatus:cavv:eci:md:rnd:',
                    'hashparamsval'         => '306912982023122380641xgRWtC2UAAAAAAAAAAAAAAAAAAA=02aW5kZXg6MDJrx8O9qwUvrCPAHSeJG+tDncPcvXkhbmvZPQakkqHX/hMEIzcDkmnDsIBA8BD5zX/aDIAerqJ/h7GIw2VTtNaGjN7JZhmwVSL65 ▶',
                    'clientid'              => '30691298',
                    'MaskedPan'             => '55496087****1500',
                    'apiversion'            => '512',
                    'orderid'               => '202312238064',
                    'txninstallmentcount'   => null,
                    'terminaluserid'        => 'PROVAUT',
                    'secure3dhash'          => '8088CAB6FA21AB437D2F9296C0B378D44C7A71CEF3E4854DD3D0376321BA4AB3213813BDBE1F7003F6D8FE4E4D43429D252DF7C130BB03C0411626574C9E2051',
                    'secure3dsecuritylevel' => '3D',
                    'txncurrencycode'       => '949',
                    'errorurl'              => 'http://localhost/garanti/3d/response.php',
                    'terminalmerchantid'    => '7000679',
                    'mode'                  => 'TEST',
                    'terminalprovuserid'    => 'PROVAUT',
                    'txnamount'             => '101',
                    'successurl'            => 'http://localhost/garanti/3d/response.php',
                    'txntype'               => 'sales',
                    'customeripaddress'     => '172.26.0.1',
                ],
                'paymentData'        => [
                    'Mode'        => null,
                    'Terminal'    => [
                        'ProvUserID' => 'PROVAUT',
                        'UserID'     => 'PROVAUT',
                        'ID'         => '30691298',
                        'MerchantID' => '7000679',
                    ],
                    'Customer'    => [
                        'IPAddress'    => '172.26.0.1',
                        'EmailAddress' => null,
                    ],
                    'Order'       => [
                        'OrderID' => '202312238064',
                        'GroupID' => null,
                    ],
                    'Transaction' => [
                        'Response'         => [
                            'Source'     => 'HOST',
                            'Code'       => '00',
                            'ReasonCode' => '00',
                            'Message'    => 'Approved',
                            'ErrorMsg'   => null,
                            'SysErrMsg'  => null,
                        ],
                        'RetrefNum'        => '335709663080',
                        'AuthCode'         => '103550',
                        'BatchNum'         => '005546',
                        'SequenceNum'      => '000080',
                        'ProvDate'         => '20231223 19:24:30',
                        'CardNumberMasked' => '55496087****1500',
                        'CardHolderName'   => '4517******* 4517**********',
                        'CardType'         => 'BONUS',
                        'HashData'         => '1724AAE56E9EF08EAF70633AB5F56F55E538A18201A3A98E03D1DDFC4E2A3185FF6421261F96B3F3B052F0090D5CC15F3254051304F0589BD2061F2622B320A0',
                        'HostMsgList'      => null,
                        'RewardInqResult'  => [
                            'RewardList' => null,
                            'ChequeList' => null,
                        ],
                        'GarantiCardInd'   => 'Y',
                    ],
                ],
                'expectedData'       => [
                    'order_id'             => '202312238064',
                    'trans_id'             => '202312238064',
                    'auth_code'            => '103550',
                    'ref_ret_num'          => '335709663080',
                    'transaction_security' => 'Full 3D Secure',
                    'proc_return_code'     => '00',
                    'md_status'            => '1',
                    'status'               => 'approved',
                    'status_detail'        => 'approved',
                    'masked_number'        => '55496087****1500',
                    'amount'               => 1.01,
                    'currency'             => 'TRY',
                    'tx_status'            => null,
                    'eci'                  => '02',
                    'cavv'                 => 'xgRWtC2UAAAAAAAAAAAAAAAAAAA=',
                    'error_code'           => null,
                    'error_message'        => null,
                    'md_error_message'     => null,
                    'group_id'             => '000080',
                    'batch_num'            => '005546',
                    'transaction_type'     => 'pay',
                    'payment_model'        => '3d',
                ],
            ],
        ];
    }


    public function threeDPayPaymentDataProvider(): array
    {
        return [
            'success1'     => [
                'order'        => [],
                'txType'       => PosInterface::TX_TYPE_PAY,
                'paymentData'  => [
                    'xid'                   => 'RszfrwEYe/8xb7rnrPuh6C9pZSQ=',
                    'mdstatus'              => '1',
                    'mderrormessage'        => 'Authenticated',
                    'txnstatus'             => 'Y',
                    'eci'                   => '02',
                    'cavv'                  => 'jCm0m+u/0hUfAREHBAMBcfN+pSo=',
                    'paressyntaxok'         => 'true',
                    'paresverified'         => 'true',
                    'version'               => '2.0',
                    'ireqcode'              => '',
                    'ireqdetail'            => '',
                    'vendorcode'            => '',
                    'cavvalgorithm'         => '3',
                    'md'                    => 'G1YfkxEZ8Noemg4MRspO20vEiXaEk51A7ajPU4mKMSU5LSbRZ/DYiHzgrGsFz6Ow7ditodw/u5116kO5t/Gvv4yZ89KOHO06jIquCipc01ocHKHSyQU187XPZksYUFppPDpqjtgAGiQUXRGSuJJRig==',
                    'terminalid'            => '30691298',
                    'oid'                   => '20221101657A',
                    'authcode'              => '304919',
                    'response'              => 'Approved',
                    'errmsg'                => '',
                    'hostmsg'               => '',
                    'procreturncode'        => '00',
                    'transid'               => '20221101657A',
                    'hostrefnum'            => '230508300426',
                    'rnd'                   => 'pfEyUZI0g2djbK4UiqKx',
                    'hash'                  => '76Ga8XKh8ynllnNt2rPkq2Q0Oa4=',
                    'hashparams'            => 'clientid:oid:authcode:procreturncode:response:mdstatus:cavv:eci:md:rnd:',
                    'hashparamsval'         => '3069129820221101657A30491900Approved1jCm0m+u/0hUfAREHBAMBcfN+pSo=02G1YfkxEZ8Noemg4MRspO20vEiXaEk51A7ajPU4mKMSU5LSbRZ/DYiHzgrGsFz6Ow7ditodw/u5116kO5t/Gvv4yZ89KOHO06jIquCipc01ocHKHSyQU187XPZksYUFppPDpqjtgAGiQUXRGSuJJRig==pfEyUZI0g2djbK4UiqKx',
                    'clientid'              => '30691298',
                    'MaskedPan'             => '428220***8015',
                    'apiversion'            => 'v0.01',
                    'orderid'               => '20221101657A',
                    'txninstallmentcount'   => '2',
                    'terminaluserid'        => 'PROVAUT',
                    'secure3dhash'          => '06A4AA2C344F7F1E1CF7610E64797D9282A0D638',
                    'secure3dsecuritylevel' => '3D_PAY',
                    'txncurrencycode'       => '949',
                    'errorurl'              => 'http://localhost/garanti/3d-pay/response.php',
                    'terminalmerchantid'    => '7000679',
                    'mode'                  => 'TEST',
                    'terminalprovuserid'    => 'PROVAUT',
                    'txnamount'             => '101',
                    'successurl'            => 'http://localhost/garanti/3d-pay/response.php',
                    'customeripaddress'     => '172.26.0.1',
                    'txntype'               => 'sales',
                ],
                'expectedData' => [
                    'order_id'             => '20221101657A',
                    'trans_id'             => '20221101657A',
                    'auth_code'            => '304919',
                    'ref_ret_num'          => '230508300426',
                    'transaction_security' => 'Full 3D Secure',
                    'proc_return_code'     => '00',
                    'md_status'            => '1',
                    'status'               => 'approved',
                    'status_detail'        => 'approved',
                    'masked_number'        => '428220***8015',
                    'amount'               => 1.01,
                    'currency'             => PosInterface::CURRENCY_TRY,
                    'tx_status'            => 'Y',
                    'eci'                  => '02',
                    'cavv'                 => 'jCm0m+u/0hUfAREHBAMBcfN+pSo=',
                    'error_code'           => null,
                    'error_message'        => null,
                    'md_error_message'     => null,
                    'transaction_type'     => 'pay',
                    'payment_model'        => '3d_pay',
                ],
            ],
            'authFail'     => [
                'order'        => [],
                'txType'       => PosInterface::TX_TYPE_PAY,
                'paymentData'  => [
                    'mdstatus'              => '7',
                    'mderrormessage'        => 'Sistem Hatasi',
                    'errmsg'                => 'Sistem Hatasi',
                    'clientid'              => '30691298',
                    'oid'                   => '2022110159A0',
                    'response'              => 'Error',
                    'procreturncode'        => '99',
                    'apiversion'            => 'v0.01',
                    'orderid'               => '2022110159A0',
                    'txninstallmentcount'   => '',
                    'terminaluserid'        => 'PROVAUT',
                    'secure3dhash'          => '8C191C2BB01B2E77DAF0CD71436001E561A8ED56',
                    'secure3dsecuritylevel' => '3D_PAY',
                    'txncurrencycode'       => '949',
                    'errorurl'              => 'http://localhost/garanti/3d-pay/response.php',
                    'terminalmerchantid'    => '7000679',
                    'mode'                  => 'TEST',
                    'terminalprovuserid'    => 'PROVAUT',
                    'txnamount'             => '0',
                    'successurl'            => 'http://localhost/garanti/3d-pay/response.php',
                    'customeripaddress'     => '172.26.0.1',
                    'txntype'               => 'sales',
                    'terminalid'            => '30691298',
                ],
                'expectedData' => [
                    'order_id'             => '2022110159A0',
                    'trans_id'             => null,
                    'auth_code'            => null,
                    'ref_ret_num'          => null,
                    'transaction_security' => 'MPI fallback',
                    'proc_return_code'     => '99',
                    'md_status'            => '7',
                    'status'               => 'declined',
                    'status_detail'        => 'general_error',
                    'masked_number'        => null,
                    'amount'               => 0.0,
                    'currency'             => PosInterface::CURRENCY_TRY,
                    'tx_status'            => null,
                    'eci'                  => null,
                    'cavv'                 => null,
                    'error_code'           => '99',
                    'error_message'        => 'Sistem Hatasi',
                    'md_error_message'     => 'Sistem Hatasi',
                    'transaction_type'     => 'pay',
                    'payment_model'        => '3d_pay',
                ],
            ],
            'paymentFail1' => [
                'order'        => [],
                'txType'       => PosInterface::TX_TYPE_PAY,
                'paymentData'  => [
                    'xid'                   => 'RszfrwEYe/8xb7rnrPuh6C9pZSQ=',
                    'mdstatus'              => '1',
                    'mderrormessage'        => 'Authenticated',
                    'txnstatus'             => 'Y',
                    'eci'                   => '02',
                    'cavv'                  => 'jCm0m+u/0hUfAREHBAMBcfN+pSo=',
                    'paressyntaxok'         => 'true',
                    'paresverified'         => 'true',
                    'version'               => '2.0',
                    'ireqcode'              => '',
                    'ireqdetail'            => '',
                    'vendorcode'            => '',
                    'cavvalgorithm'         => '3',
                    'md'                    => 'G1YfkxEZ8Noemg4MRspO20vEiXaEk51ANsgVc6NOy8kP0D9xGRZDbWgvgfOt1WTolrbReg1xoLFLHlZ6uZPLz/t34VcRCRNzRuGqMkRi/+r0cKRbNRVp5TJPl7blsqS8ykvTCtee14fMPIv0bohT6A==',
                    'terminalid'            => '30691298',
                    'oid'                   => '20221101A0F9',
                    'authcode'              => ' ',
                    'response'              => 'Declined',
                    'errmsg'                => 'TxnAmount field must not be zero DOUBLE value because of the Mandatory Rule:zero',
                    'hostmsg'               => 'Giriş yaptığınız işlem tipi için zorunlu alanları kontrol ediniz',
                    'procreturncode'        => '92',
                    'transid'               => '20221101A0F9',
                    'hostrefnum'            => '',
                    'rnd'                   => 's5sBV3uf+DTkj2PXQc2/',
                    'hash'                  => 'F7iI4nt48tiTs5OSfOSeXy325v8=',
                    'hashparams'            => 'clientid:oid:authcode:procreturncode:response:mdstatus:cavv:eci:md:rnd:',
                    'hashparamsval'         => '3069129820221101A0F9 92Declined1jCm0m+u/0hUfAREHBAMBcfN+pSo=02G1YfkxEZ8Noemg4MRspO20vEiXaEk51ANsgVc6NOy8kP0D9xGRZDbWgvgfOt1WTolrbReg1xoLFLHlZ6uZPLz/t34VcRCRNzRuGqMkRi/+r0cKRbNRVp5TJPl7blsqS8ykvTCtee14fMPIv0bohT6A==s5sBV3uf+DTkj2PXQc2/',
                    'clientid'              => '30691298',
                    'MaskedPan'             => '428220***8015',
                    'apiversion'            => 'v0.01',
                    'orderid'               => '20221101A0F9',
                    'txninstallmentcount'   => '',
                    'terminaluserid'        => 'PROVAUT',
                    'secure3dhash'          => '65CA0086F1859CF01CC3CE692B20E432853B35E7',
                    'secure3dsecuritylevel' => '3D_PAY',
                    'txncurrencycode'       => '949',
                    'errorurl'              => 'http://localhost/garanti/3d-pay/response.php',
                    'terminalmerchantid'    => '7000679',
                    'mode'                  => 'TEST',
                    'terminalprovuserid'    => 'PROVAUT',
                    'txnamount'             => '0',
                    'successurl'            => 'http://localhost/garanti/3d-pay/response.php',
                    'customeripaddress'     => '172.26.0.1',
                    'txntype'               => 'sales',
                ],
                'expectedData' => [
                    'order_id'             => '20221101A0F9',
                    'trans_id'             => '20221101A0F9',
                    'auth_code'            => null,
                    'ref_ret_num'          => null,
                    'transaction_security' => 'Full 3D Secure',
                    'proc_return_code'     => '92',
                    'md_status'            => '1',
                    'status'               => 'declined',
                    'status_detail'        => 'invalid_transaction',
                    'masked_number'        => '428220***8015',
                    'amount'               => 0.0,
                    'currency'             => PosInterface::CURRENCY_TRY,
                    'tx_status'            => 'Y',
                    'eci'                  => '02',
                    'cavv'                 => 'jCm0m+u/0hUfAREHBAMBcfN+pSo=',
                    'error_code'           => '92',
                    'error_message'        => 'TxnAmount field must not be zero DOUBLE value because of the Mandatory Rule:zero',
                    'md_error_message'     => null,
                    'transaction_type'     => 'pay',
                    'payment_model'        => '3d_pay',
                ],
            ],
        ];
    }


    public function statusTestDataProvider(): array
    {
        return
            [
                'success1' => [
                    'responseData' => [
                        'Mode'        => '',
                        'Terminal'    => [
                            'ProvUserID' => 'PROVAUT',
                            'UserID'     => 'PROVAUT',
                            'ID'         => '30691298',
                            'MerchantID' => '7000679',
                        ],
                        'Customer'    => [
                            'IPAddress' => '172.26.0.1',
                        ],
                        'Order'       => [
                            'OrderID'        => '20221101EB13',
                            'GroupID'        => '',
                            'OrderInqResult' => [
                                // bu kisimdaki veriler baska response'dan alindi
                                'ChargeType'         => 'S',
                                'PreAuthAmount'      => '0',
                                'PreAuthDate'        => '',
                                'AuthAmount'         => '101',
                                'AuthDate'           => '2023-01-07 21:27:59.271',
                                'RecurringInfo'      => 'N',
                                'RecurringStatus'    => '',
                                'Status'             => 'APPROVED',
                                'RemainingBNSAmount' => '0',
                                'UsedFBBAmount'      => '0',
                                'UsedChequeType'     => '',
                                'UsedChequeCount'    => '0',
                                'UsedChequeAmount'   => '0',
                                'UsedBnsAmount'      => '0',
                                'InstallmentCnt'     => '0',
                                'CardNumberMasked'   => '428220******8015',
                                'CardRef'            => '',
                                'Code'               => '00',
                                'ReasonCode'         => '00',
                                'SysErrMsg'          => '',
                                'RetrefNum'          => '300708704369',
                                'GPID'               => '',
                                'AuthCode'           => '304919',
                                'BatchNum'           => '5168',
                                'SequenceNum'        => '21',
                                'ProvDate'           => '2023-01-07 21:27:59.253',
                                'CardHolderName'     => 'HA*** YIL***',
                                'CardType'           => 'FLEXI',
                            ],
                        ],
                        'Transaction' => [
                            'Response'         => [
                                'Source'     => 'HOST',
                                'Code'       => '00',
                                'ReasonCode' => '00',
                                'Message'    => 'Approved',
                                'ErrorMsg'   => '',
                                'SysErrMsg'  => '',
                            ],
                            'RetrefNum'        => '230508300896',
                            'AuthCode'         => '304919',
                            'BatchNum'         => '004951',
                            'SequenceNum'      => '000026',
                            'ProvDate'         => '20221101 15:56:43',
                            'CardNumberMasked' => '428220******8015',
                            'CardHolderName'   => 'HA*** YIL***',
                            'CardType'         => 'FLEXI',
                            'HashData'         => '6A03BADEA1D76DEB1C8014E07E5ADFAFE3E07F3C',
                            'HostMsgList'      => '',
                            'RewardInqResult'  => [
                                'RewardList' => '',
                                'ChequeList' => '',
                            ],
                            'GarantiCardInd'   => 'Y',
                        ],
                    ],
                    'expectedData' => [
                        'order_id'         => '20221101EB13',
                        'group_id'         => null,
                        'amount'           => 1.01,
                        'trans_id'         => null,
                        'auth_code'        => '304919',
                        'ref_ret_num'      => '230508300896',
                        'proc_return_code' => '00',
                        'status'           => 'approved',
                        'status_detail'    => 'approved',
                        'error_code'       => '00',
                        'error_message'    => null,
                    ],
                ],
                'fail1'    => [
                    'responseData' => [
                        'Mode'        => '',
                        'Terminal'    => [
                            'ProvUserID' => 'PROVAUT',
                            'UserID'     => 'PROVAUT',
                            'ID'         => '30691298',
                            'MerchantID' => '7000679',
                        ],
                        'Customer'    => [
                            'IPAddress' => '172.26.0.1',
                        ],
                        'Order'       => [
                            'OrderID'        => '20221101295D',
                            'GroupID'        => '',
                            'OrderInqResult' => [
                                'ChargeType'         => '',
                                'PreAuthAmount'      => '0',
                                'PreAuthDate'        => '',
                                'AuthAmount'         => '0',
                                'AuthDate'           => '',
                                'RecurringInfo'      => '',
                                'RecurringStatus'    => '',
                                'Status'             => '',
                                'RemainingBNSAmount' => '0',
                                'UsedFBBAmount'      => '0',
                                'UsedChequeType'     => '',
                                'UsedChequeCount'    => '0',
                                'UsedChequeAmount'   => '0',
                                'UsedBnsAmount'      => '0',
                                'InstallmentCnt'     => '0',
                                'CardNumberMasked'   => 'null',
                                'CardRef'            => '',
                                'Code'               => '',
                                'ReasonCode'         => '',
                                'SysErrMsg'          => '',
                                'RetrefNum'          => '',
                                'GPID'               => '',
                                'AuthCode'           => '',
                                'BatchNum'           => '0',
                                'SequenceNum'        => '0',
                                'ProvDate'           => '',
                                'CardHolderName'     => '',
                                'CardType'           => '',
                            ],
                        ],
                        'Transaction' => [
                            'Response'         => [
                                'Source'     => 'GVPS',
                                'Code'       => '92',
                                'ReasonCode' => '0110',
                                'Message'    => 'Declined',
                                'ErrorMsg'   => 'İşlem bulunamadı',
                                'SysErrMsg'  => 'ErrorId: 0110',
                            ],
                            'RetrefNum'        => '',
                            'AuthCode'         => '',
                            'BatchNum'         => '',
                            'SequenceNum'      => '',
                            'ProvDate'         => '20221101 15:50:44',
                            'CardNumberMasked' => '',
                            'CardHolderName'   => '',
                            'CardType'         => '',
                            'HashData'         => '2C5E7171202254F3A721166A2F8D4C1EE9582C13',
                            'HostMsgList'      => '',
                            'RewardInqResult'  => [
                                'RewardList' => '',
                                'ChequeList' => '',
                            ],
                        ],
                    ],
                    'expectedData' => [
                        'order_id'         => '20221101295D',
                        'group_id'         => null,
                        'amount'           => 0.0,
                        'trans_id'         => null,
                        'auth_code'        => null,
                        'ref_ret_num'      => null,
                        'proc_return_code' => '92',
                        'status'           => 'declined',
                        'status_detail'    => 'invalid_transaction',
                        'error_code'       => '92',
                        'error_message'    => 'İşlem bulunamadı',
                    ],
                ],
            ];
    }

    public function cancelTestDataProvider(): array
    {
        return
            [
                'fail1' => [
                    'responseData' => [
                        'Mode'        => '',
                        'Terminal'    => [
                            'ProvUserID' => 'PROVRFN',
                            'UserID'     => 'PROVRFN',
                            'ID'         => '30691298',
                            'MerchantID' => '7000679',
                        ],
                        'Customer'    => [
                            'IPAddress' => '172.26.0.1',
                        ],
                        'Order'       => [
                            'OrderID' => '20221101C9B8',
                            'GroupID' => '',
                        ],
                        'Transaction' => [
                            'Response'         => [
                                'Source'     => 'HOST',
                                'Code'       => '05',
                                'ReasonCode' => '05',
                                'Message'    => 'Declined',
                                'ErrorMsg'   => 'İşleminizi gerçekleştiremiyoruz.Tekrar deneyiniz',
                                'SysErrMsg'  => 'RPC-05 condition was raised',
                            ],
                            'RetrefNum'        => '230508300968',
                            'AuthCode'         => '304919',
                            'BatchNum'         => '004951',
                            'SequenceNum'      => '000033',
                            'ProvDate'         => '20221101 16:22:11',
                            'CardNumberMasked' => '428220******8015',
                            'CardHolderName'   => '',
                            'CardType'         => '',
                            'HashData'         => '5820A79661E0B894407469B4F764BD58BDC270F1',
                            'HostMsgList'      => '',
                            'RewardInqResult'  => [
                                'RewardList' => '',
                                'ChequeList' => '',
                            ],
                        ],
                    ],
                    'expectedData' => [
                        'order_id'         => '20221101C9B8',
                        'group_id'         => null,
                        'trans_id'         => null,
                        'auth_code'        => '304919',
                        'ref_ret_num'      => '230508300968',
                        'proc_return_code' => '05',
                        'error_code'       => '05',
                        'error_message'    => 'İşleminizi gerçekleştiremiyoruz.Tekrar deneyiniz',
                        'status'           => 'declined',
                        'status_detail'    => 'reject',
                    ],
                ],
            ];
    }

    public function refundTestDataProvider(): array
    {
        return
            [
                'fail1' => [
                    'responseData' => [
                        'Mode'        => '',
                        'Terminal'    => [
                            'ProvUserID' => 'PROVRFN',
                            'UserID'     => 'PROVRFN',
                            'ID'         => '30691298',
                            'MerchantID' => '7000679',
                        ],
                        'Customer'    => [
                            'IPAddress' => '172.26.0.1',
                        ],
                        'Order'       => [
                            'OrderID' => '20221101EB13',
                            'GroupID' => '',
                        ],
                        'Transaction' => [
                            'Response'         => [
                                'Source'     => 'GVPS',
                                'Code'       => '92',
                                'ReasonCode' => '0208',
                                'Message'    => 'Declined',
                                'ErrorMsg'   => 'İade etmek istediğiniz işlem geçerli değil',
                                'SysErrMsg'  => 'ErrorId: 0208',
                            ],
                            'RetrefNum'        => '230508300918',
                            'AuthCode'         => '',
                            'BatchNum'         => '004951',
                            'SequenceNum'      => '000028',
                            'ProvDate'         => '20221101 16:01:45',
                            'CardNumberMasked' => '',
                            'CardHolderName'   => '',
                            'CardType'         => '',
                            'HashData'         => 'B565B6FF2D9B5C3D36B4CC92459EB92B77886DCC',
                            'HostMsgList'      => '',
                            'RewardInqResult'  => [
                                'RewardList' => '',
                                'ChequeList' => '',
                            ],
                        ],
                    ],
                    'expectedData' => [
                        'order_id'         => '20221101EB13',
                        'group_id'         => null,
                        'trans_id'         => null,
                        'auth_code'        => null,
                        'ref_ret_num'      => '230508300918',
                        'proc_return_code' => '92',
                        'error_code'       => '92',
                        'error_message'    => 'İade etmek istediğiniz işlem geçerli değil',
                        'status'           => 'declined',
                        'status_detail'    => 'invalid_transaction',
                    ],
                ],
            ];
    }

    public function historyTestDataProvider(): array
    {
        return
            [
                'success1' => [
                    'responseData' => [
                        'Mode'        => '',
                        'Terminal'    => [
                            'ProvUserID' => 'PROVAUT',
                            'UserID'     => 'PROVAUT',
                            'ID'         => '30691298',
                            'MerchantID' => '7000679',
                        ],
                        'Customer'    => [
                            'IPAddress' => '172.26.0.1',
                        ],
                        'Order'       => [
                            'OrderID'            => '20221101EB13',
                            'GroupID'            => '',
                            'OrderHistInqResult' => [
                                'OrderTxnList' => [
                                    'OrderTxn' => [
                                        0 => [
                                            'Type'               => 'sales',
                                            'Status'             => '00',
                                            'PreAuthAmount'      => '0',
                                            'AuthAmount'         => '101',
                                            'PreAuthDate'        => '',
                                            'AuthDate'           => '20221101',
                                            'VoidDate'           => '',
                                            'RetrefNum'          => '230508300896',
                                            'AuthCode'           => '304919',
                                            'ReturnCode'         => '00',
                                            'BatchNum'           => '4951',
                                            'RemainingBNSAmount' => '0',
                                            'UsedFBBAmount'      => '0',
                                            'UsedChequeType'     => '',
                                            'UsedChequeCount'    => '0',
                                            'UsedChequeAmount'   => '0',
                                            'CurrencyCode'       => '949',
                                            'Settlement'         => 'N',
                                        ],
                                        1 => [
                                            'Type'               => 'refund',
                                            'Status'             => '01',
                                            'PreAuthAmount'      => '0',
                                            'AuthAmount'         => '101',
                                            'PreAuthDate'        => '',
                                            'AuthDate'           => '',
                                            'VoidDate'           => '',
                                            'RetrefNum'          => '230508300913',
                                            'AuthCode'           => '',
                                            'ReturnCode'         => '92',
                                            'BatchNum'           => '4951',
                                            'RemainingBNSAmount' => '0',
                                            'UsedFBBAmount'      => '0',
                                            'UsedChequeType'     => '',
                                            'UsedChequeCount'    => '0',
                                            'UsedChequeAmount'   => '0',
                                            'CurrencyCode'       => '0',
                                            'Settlement'         => 'N',
                                        ],
                                        2 => [
                                            'Type'               => 'refund',
                                            'Status'             => '01',
                                            'PreAuthAmount'      => '0',
                                            'AuthAmount'         => '101',
                                            'PreAuthDate'        => '',
                                            'AuthDate'           => '',
                                            'VoidDate'           => '',
                                            'RetrefNum'          => '230508300918',
                                            'AuthCode'           => '',
                                            'ReturnCode'         => '92',
                                            'BatchNum'           => '4951',
                                            'RemainingBNSAmount' => '0',
                                            'UsedFBBAmount'      => '0',
                                            'UsedChequeType'     => '',
                                            'UsedChequeCount'    => '0',
                                            'UsedChequeAmount'   => '0',
                                            'CurrencyCode'       => '0',
                                            'Settlement'         => 'N',
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        'Transaction' => [
                            'Response'         => [
                                'Source'     => 'GVPS',
                                'Code'       => '00',
                                'ReasonCode' => '',
                                'Message'    => 'Approved',
                                'ErrorMsg'   => '',
                                'SysErrMsg'  => '',
                            ],
                            'RetrefNum'        => '',
                            'AuthCode'         => '',
                            'BatchNum'         => '',
                            'SequenceNum'      => '',
                            'ProvDate'         => '20221101 16:11:30',
                            'CardNumberMasked' => '',
                            'CardHolderName'   => '',
                            'CardType'         => '',
                            'HashData'         => 'F7FB1830A48C729CD18DFDB47F2B6E2CB8258F21',
                            'HostMsgList'      => '',
                            'RewardInqResult'  => [
                                'RewardList' => '',
                                'ChequeList' => '',
                            ],
                        ],
                    ],
                    'expectedData' => [
                        'order_id'         => '20221101EB13',
                        'group_id'         => null,
                        'trans_id'         => null,
                        'auth_code'        => null,
                        'ref_ret_num'      => null,
                        'proc_return_code' => '00',
                        'status'           => 'approved',
                        'status_detail'    => 'approved',
                        'error_code'       => '00',
                        'error_message'    => '',
                        'order_txn'        => null,
                    ],
                ],
            ];
    }
}
