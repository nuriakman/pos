<?php
/**
 * @license MIT
 */

namespace Mews\Pos\Tests\Unit\Gateways;

use Mews\Pos\Client\HttpClient;
use Mews\Pos\Crypt\CryptInterface;
use Mews\Pos\DataMapper\RequestDataMapper\RequestDataMapperInterface;
use Mews\Pos\DataMapper\ResponseDataMapper\ResponseDataMapperInterface;
use Mews\Pos\Entity\Account\PosNetAccount;
use Mews\Pos\Entity\Card\CreditCardInterface;
use Mews\Pos\Event\RequestDataPreparedEvent;
use Mews\Pos\Exceptions\UnsupportedPaymentModelException;
use Mews\Pos\Exceptions\UnsupportedTransactionTypeException;
use Mews\Pos\Factory\AccountFactory;
use Mews\Pos\Factory\CreditCardFactory;
use Mews\Pos\Gateways\PosNetV1Pos;
use Mews\Pos\PosInterface;
use Mews\Pos\Serializer\SerializerInterface;
use Mews\Pos\Tests\Unit\DataMapper\ResponseDataMapper\PosNetV1PosResponseDataMapperTest;
use Mews\Pos\Tests\Unit\HttpClientTestTrait;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * @covers \Mews\Pos\Gateways\PosNetV1Pos
 * @covers \Mews\Pos\Gateways\AbstractGateway
 */
class PosNetV1PosTest extends TestCase
{
    use HttpClientTestTrait;

    private PosNetAccount $account;

    private array $config;

    private CreditCardInterface $card;

    /** @var PosNetV1Pos */
    private PosInterface $pos;

    /** @var RequestDataMapperInterface & MockObject */
    private MockObject $requestMapperMock;

    /** @var ResponseDataMapperInterface & MockObject */
    private MockObject $responseMapperMock;

    /** @var CryptInterface & MockObject */
    private MockObject $cryptMock;

    /** @var HttpClient & MockObject */
    private MockObject $httpClientMock;

    /** @var LoggerInterface & MockObject */
    private MockObject $loggerMock;

    /** @var EventDispatcherInterface & MockObject */
    private MockObject $eventDispatcherMock;

    /** @var SerializerInterface & MockObject */
    private MockObject $serializerMock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->config = [
            'name'              => 'Albaraka',
            'class'             => PosNetV1Pos::class,
            'gateway_endpoints' => [
                'payment_api' => 'https://epostest.albarakaturk.com.tr/ALBMerchantService/MerchantJSONAPI.svc',
                'gateway_3d'  => 'https://epostest.albarakaturk.com.tr/ALBSecurePaymentUI/SecureProcess/SecureVerification.aspx',
            ],
        ];

        $this->account = AccountFactory::createPosNetAccount(
            'albaraka',
            '6700950031',
            '67540050',
            '1010028724242434',
            PosInterface::MODEL_3D_SECURE,
            '10,10,10,10,10,10,10,10'
        );

        $this->requestMapperMock   = $this->createMock(RequestDataMapperInterface::class);
        $this->responseMapperMock  = $this->createMock(ResponseDataMapperInterface::class);
        $this->serializerMock      = $this->createMock(SerializerInterface::class);
        $this->cryptMock           = $this->createMock(CryptInterface::class);
        $this->httpClientMock      = $this->createMock(HttpClient::class);
        $this->loggerMock          = $this->createMock(LoggerInterface::class);
        $this->eventDispatcherMock = $this->createMock(EventDispatcherInterface::class);

        $this->requestMapperMock->expects(self::any())
            ->method('getCrypt')
            ->willReturn($this->cryptMock);

        $this->pos = new PosNetV1Pos(
            $this->config,
            $this->account,
            $this->requestMapperMock,
            $this->responseMapperMock,
            $this->serializerMock,
            $this->eventDispatcherMock,
            $this->httpClientMock,
            $this->loggerMock,
        );

        $this->pos->setTestMode(true);

        $this->card = CreditCardFactory::createForGateway($this->pos, '5555444433332222', '21', '12', '122', 'ahmet');
    }

    /**
     * @return void
     */
    public function testInit(): void
    {
        $this->requestMapperMock->expects(self::once())
            ->method('getCurrencyMappings')
            ->willReturn([PosInterface::CURRENCY_TRY => '949']);
        $this->assertSame([PosInterface::CURRENCY_TRY], $this->pos->getCurrencies());
        $this->assertSame($this->config, $this->pos->getConfig());
        $this->assertSame($this->account, $this->pos->getAccount());
    }

    /**
     * @dataProvider getApiURLDataProvider
     */
    public function testGetApiURL(string $txType, string $mappedTxType, string $expected): void
    {
        $this->requestMapperMock->expects(self::once())
            ->method('mapTxType')
            ->with($txType)
            ->willReturn($mappedTxType);

        $this->assertSame($expected, $this->pos->getApiURL($txType));
    }

    /**
     * @testWith [true]
     * [false]
     */
    public function testGet3DFormData(
        bool $isWithCard
    ): void
    {
        $card         = $isWithCard ? $this->card : null;
        $order        = ['id' => '124'];
        $paymentModel = PosInterface::MODEL_3D_SECURE;
        $txType       = PosInterface::TX_TYPE_PAY_AUTH;

        $this->requestMapperMock->expects(self::once())
            ->method('create3DFormData')
            ->with(
                $this->pos->getAccount(),
                $order,
                $paymentModel,
                $txType,
                'https://epostest.albarakaturk.com.tr/ALBSecurePaymentUI/SecureProcess/SecureVerification.aspx',
                $card
            )
            ->willReturn(['formData']);

        $actual = $this->pos->get3DFormData($order, $paymentModel, $txType, $card);

        $this->assertSame(['formData'], $actual);
    }

    /**
     * @dataProvider make3DPaymentDataProvider
     */
    public function testMake3DPayment(
        array   $order,
        string  $txType,
        Request $request,
        array   $paymentResponse,
        array   $expectedResponse,
        bool    $is3DSuccess,
        bool    $isSuccess
    ): void
    {
        if ($is3DSuccess) {
            $this->cryptMock->expects(self::once())
                ->method('check3DHash')
                ->with($this->account, $request->request->all())
                ->willReturn(true);
        }

        $this->responseMapperMock->expects(self::once())
            ->method('extractMdStatus')
            ->with($request->request->all())
            ->willReturn('3d-status');

        $this->responseMapperMock->expects(self::once())
            ->method('is3dAuthSuccess')
            ->with('3d-status')
            ->willReturn($is3DSuccess);

        $create3DPaymentRequestData = [
            'create3DPaymentRequestData',
        ];
        if ($is3DSuccess) {
            $this->requestMapperMock->expects(self::once())
                ->method('mapTxType')
                ->with($txType)
                ->willReturn('Sale');
            $this->requestMapperMock->expects(self::once())
                ->method('create3DPaymentRequestData')
                ->with($this->account, $order, $txType, $request->request->all())
                ->willReturn($create3DPaymentRequestData);

            $this->configureClientResponse(
                $txType,
                'https://epostest.albarakaturk.com.tr/ALBMerchantService/MerchantJSONAPI.svc/Sale',
                $create3DPaymentRequestData,
                'request-body',
                'response-body',
                $paymentResponse,
                $order
            );

            $this->responseMapperMock->expects(self::once())
                ->method('map3DPaymentData')
                ->with($request->request->all(), $paymentResponse, $txType, $order)
                ->willReturn($expectedResponse);
        } else {
            $this->responseMapperMock->expects(self::once())
                ->method('map3DPaymentData')
                ->with($request->request->all(), null, $txType, $order)
                ->willReturn($expectedResponse);
            $this->requestMapperMock->expects(self::never())
                ->method('create3DPaymentRequestData');
            $this->serializerMock->expects(self::never())
                ->method('encode');
            $this->serializerMock->expects(self::never())
                ->method('decode');
            $this->eventDispatcherMock->expects(self::never())
                ->method('dispatch');
        }

        $this->pos->make3DPayment($request, $order, $txType);

        $result = $this->pos->getResponse();
        $this->assertSame($expectedResponse, $result);
        $this->assertSame($isSuccess, $this->pos->isSuccess());
    }

    public function testMake3DHostPayment(): void
    {
        $request = Request::create('', 'POST');

        $this->expectException(UnsupportedPaymentModelException::class);
        $this->pos->make3DHostPayment($request, [], PosInterface::TX_TYPE_PAY_AUTH);
    }

    public function testMake3DPayPayment(): void
    {
        $request = Request::create('', 'POST');

        $this->expectException(UnsupportedPaymentModelException::class);
        $this->pos->make3DPayPayment($request, [], PosInterface::TX_TYPE_PAY_AUTH);
    }

    /**
     * @dataProvider makeRegularPaymentDataProvider
     */
    public function testMakeRegularPayment(array $order, string $txType, string $mappedTxType, string $apiUrl): void
    {
        $account     = $this->pos->getAccount();
        $card        = $this->card;
        $requestData = ['createNonSecurePaymentRequestData'];

        $this->requestMapperMock->expects(self::once())
            ->method('mapTxType')
            ->with($txType)
            ->willReturn($mappedTxType);

        $this->requestMapperMock->expects(self::once())
            ->method('createNonSecurePaymentRequestData')
            ->with($account, $order, $txType, $card)
            ->willReturn($requestData);

        $decodedResponse = ['decodedData'];
        $this->configureClientResponse(
            $txType,
            $apiUrl,
            $requestData,
            'request-body',
            'response-body',
            $decodedResponse,
            $order
        );

        $this->responseMapperMock->expects(self::once())
            ->method('mapPaymentResponse')
            ->with($decodedResponse, $txType, $order)
            ->willReturn(['result']);

        $this->pos->makeRegularPayment($order, $card, $txType);
    }

    /**
     * @dataProvider makeRegularPostAuthPaymentDataProvider
     */
    public function testMakeRegularPostAuthPayment(array $order, string $apiUrl): void
    {
        $account     = $this->pos->getAccount();
        $txType      = PosInterface::TX_TYPE_PAY_POST_AUTH;
        $requestData = ['createNonSecurePostAuthPaymentRequestData'];

        $this->requestMapperMock->expects(self::once())
            ->method('mapTxType')
            ->with($txType)
            ->willReturn('Capture');

        $this->requestMapperMock->expects(self::once())
            ->method('createNonSecurePostAuthPaymentRequestData')
            ->with($account, $order)
            ->willReturn($requestData);

        $decodedResponse = ['decodedData'];
        $this->configureClientResponse(
            $txType,
            $apiUrl,
            $requestData,
            'request-body',
            'response-body',
            $decodedResponse,
            $order
        );

        $this->responseMapperMock->expects(self::once())
            ->method('mapPaymentResponse')
            ->with($decodedResponse, $txType, $order)
            ->willReturn(['result']);

        $this->pos->makeRegularPostPayment($order);
    }


    /**
     * @dataProvider statusRequestDataProvider
     */
    public function testStatusRequest(array $order, string $apiUrl): void
    {
        $account     = $this->pos->getAccount();
        $txType      = PosInterface::TX_TYPE_STATUS;
        $requestData = ['createStatusRequestData'];

        $this->requestMapperMock->expects(self::once())
            ->method('mapTxType')
            ->with($txType)
            ->willReturn('TransactionInquiry');

        $this->requestMapperMock->expects(self::once())
            ->method('createStatusRequestData')
            ->with($account, $order)
            ->willReturn($requestData);

        $decodedResponse = ['decodedData'];
        $this->configureClientResponse(
            $txType,
            $apiUrl,
            $requestData,
            'request-body',
            'response-body',
            $decodedResponse,
            $order
        );

        $this->responseMapperMock->expects(self::once())
            ->method('mapStatusResponse')
            ->with($decodedResponse)
            ->willReturn(['result']);

        $this->pos->status($order);
    }

    /**
     * @dataProvider cancelRequestDataProvider
     */
    public function testCancelRequest(array $order, string $apiUrl): void
    {
        $account     = $this->pos->getAccount();
        $txType      = PosInterface::TX_TYPE_CANCEL;
        $requestData = ['createCancelRequestData'];

        $this->requestMapperMock->expects(self::once())
            ->method('mapTxType')
            ->with($txType)
            ->willReturn('Reverse');

        $this->requestMapperMock->expects(self::once())
            ->method('createCancelRequestData')
            ->with($account, $order)
            ->willReturn($requestData);

        $decodedResponse = ['decodedData'];
        $this->configureClientResponse(
            $txType,
            $apiUrl,
            $requestData,
            'request-body',
            'response-body',
            $decodedResponse,
            $order
        );

        $this->responseMapperMock->expects(self::once())
            ->method('mapCancelResponse')
            ->with($decodedResponse)
            ->willReturn(['result']);

        $this->pos->cancel($order);
    }

    /**
     * @dataProvider refundRequestDataProvider
     */
    public function testRefundRequest(array $order, string $apiUrl): void
    {
        $account     = $this->pos->getAccount();
        $txType      = PosInterface::TX_TYPE_REFUND;
        $requestData = ['createRefundRequestData'];

        $this->requestMapperMock->expects(self::once())
            ->method('mapTxType')
            ->with($txType)
            ->willReturn('Return');

        $this->requestMapperMock->expects(self::once())
            ->method('createRefundRequestData')
            ->with($account, $order)
            ->willReturn($requestData);

        $decodedResponse = ['decodedData'];
        $this->configureClientResponse(
            $txType,
            $apiUrl,
            $requestData,
            'request-body',
            'response-body',
            $decodedResponse,
            $order
        );

        $this->responseMapperMock->expects(self::once())
            ->method('mapRefundResponse')
            ->with($decodedResponse)
            ->willReturn(['result']);

        $this->pos->refund($order);
    }

    public function testHistoryRequest(): void
    {
        $this->expectException(UnsupportedTransactionTypeException::class);
        $this->pos->history([]);
    }

    public function testOrderHistoryRequest(): void
    {
        $this->expectException(UnsupportedTransactionTypeException::class);
        $this->pos->orderHistory([]);
    }

    public static function getApiURLDataProvider(): iterable
    {
        yield [
            'txType'       => PosInterface::TX_TYPE_PAY_AUTH,
            'mappedTxType' => 'Sale',
            'expected'     => 'https://epostest.albarakaturk.com.tr/ALBMerchantService/MerchantJSONAPI.svc/Sale',
        ];

        yield [
            'txType'       => PosInterface::TX_TYPE_CANCEL,
            'mappedTxType' => 'Reverse',
            'expected'     => 'https://epostest.albarakaturk.com.tr/ALBMerchantService/MerchantJSONAPI.svc/Reverse',
        ];
    }

    public static function make3DPaymentDataProvider(): array
    {
        $dataSamples = iterator_to_array(PosNetV1PosResponseDataMapperTest::threeDPaymentDataProvider());

        return [
            'auth_fail'                    => [
                'order'           => $dataSamples['3d_auth_fail_1']['order'],
                'txType'          => $dataSamples['3d_auth_fail_1']['txType'],
                'request'         => Request::create(
                    '',
                    'POST',
                    $dataSamples['3d_auth_fail_1']['threeDResponseData']
                ),
                'paymentResponse' => $dataSamples['3d_auth_fail_1']['paymentData'],
                'expected'        => $dataSamples['3d_auth_fail_1']['expectedData'],
                'is3DSuccess'     => false,
                'isSuccess'       => false,
            ],
            '3d_auth_success_payment_fail' => [
                'order'           => $dataSamples['3d_auth_success_payment_fail']['order'],
                'txType'          => $dataSamples['3d_auth_success_payment_fail']['txType'],
                'request'         => Request::create(
                    '',
                    'POST',
                    $dataSamples['3d_auth_success_payment_fail']['threeDResponseData']
                ),
                'paymentResponse' => $dataSamples['3d_auth_success_payment_fail']['paymentData'],
                'expected'        => $dataSamples['3d_auth_success_payment_fail']['expectedData'],
                'is3DSuccess'     => true,
                'isSuccess'       => false,
            ],
            'success'                      => [
                'order'           => $dataSamples['success1']['order'],
                'txType'          => $dataSamples['success1']['txType'],
                'request'         => Request::create('', 'POST', $dataSamples['success1']['threeDResponseData']),
                'paymentResponse' => $dataSamples['success1']['paymentData'],
                'expected'        => $dataSamples['success1']['expectedData'],
                'is3DSuccess'     => true,
                'isSuccess'       => true,
            ],
        ];
    }

    public static function makeRegularPaymentDataProvider(): array
    {
        return [
            [
                'order'        => [
                    'id' => '2020110828BC',
                ],
                'txType'       => PosInterface::TX_TYPE_PAY_AUTH,
                'mappedTxType' => 'Sale',
                'api_url'      => 'https://epostest.albarakaturk.com.tr/ALBMerchantService/MerchantJSONAPI.svc/Sale',
            ],
            [
                'order'        => [
                    'id' => '2020110828BC',
                ],
                'txType'       => PosInterface::TX_TYPE_PAY_PRE_AUTH,
                'mappedTxType' => 'Auth',
                'api_url'      => 'https://epostest.albarakaturk.com.tr/ALBMerchantService/MerchantJSONAPI.svc/Auth',
            ],
        ];
    }

    public static function makeRegularPostAuthPaymentDataProvider(): array
    {
        return [
            [
                'order'   => [
                    'id' => '2020110828BC',
                ],
                'api_url' => 'https://epostest.albarakaturk.com.tr/ALBMerchantService/MerchantJSONAPI.svc/Capture',
            ],
        ];
    }

    public static function statusRequestDataProvider(): array
    {
        return [
            [
                'order'   => [
                    'id' => '2020110828BC',
                ],
                'api_url' => 'https://epostest.albarakaturk.com.tr/ALBMerchantService/MerchantJSONAPI.svc/TransactionInquiry',
            ],
        ];
    }

    public static function cancelRequestDataProvider(): array
    {
        return [
            [
                'order'   => [
                    'id' => '2020110828BC',
                ],
                'api_url' => 'https://epostest.albarakaturk.com.tr/ALBMerchantService/MerchantJSONAPI.svc/Reverse',
            ],
        ];
    }

    public static function refundRequestDataProvider(): array
    {
        return [
            [
                'order'   => [
                    'id' => '2020110828BC',
                ],
                'api_url' => 'https://epostest.albarakaturk.com.tr/ALBMerchantService/MerchantJSONAPI.svc/Return',
            ],
        ];
    }

    private function configureClientResponse(
        string $txType,
        string $apiUrl,
        array  $requestData,
        string $encodedRequestData,
        string $responseContent,
        array  $decodedResponse,
        array  $order
    ): void
    {
        $this->serializerMock->expects(self::once())
            ->method('encode')
            ->with($requestData, $txType)
            ->willReturn($encodedRequestData);

        $this->serializerMock->expects(self::once())
            ->method('decode')
            ->with($responseContent, $txType)
            ->willReturn($decodedResponse);

        $this->prepareClient(
            $this->httpClientMock,
            $responseContent,
            $apiUrl,
            [
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
                'body'    => $encodedRequestData,
            ],
        );

        $this->eventDispatcherMock->expects(self::once())
            ->method('dispatch')
            ->with($this->callback(function ($dispatchedEvent) use ($txType, $requestData, $order) {
                return $dispatchedEvent instanceof RequestDataPreparedEvent
                    && get_class($this->pos) === $dispatchedEvent->getGatewayClass()
                    && $txType === $dispatchedEvent->getTxType()
                    && $requestData === $dispatchedEvent->getRequestData()
                    && $order === $dispatchedEvent->getOrder()
                    ;
            }));
    }
}
