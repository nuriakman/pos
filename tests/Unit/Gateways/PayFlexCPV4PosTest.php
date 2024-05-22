<?php
/**
 * @license MIT
 */

namespace Mews\Pos\Tests\Unit\Gateways;

use Exception;
use Mews\Pos\Client\HttpClient;
use Mews\Pos\Crypt\CryptInterface;
use Mews\Pos\DataMapper\RequestDataMapper\PayFlexCPV4PosRequestDataMapper;
use Mews\Pos\DataMapper\RequestDataMapper\RequestDataMapperInterface;
use Mews\Pos\DataMapper\ResponseDataMapper\PayFlexCPV4PosResponseDataMapper;
use Mews\Pos\DataMapper\ResponseDataMapper\ResponseDataMapperInterface;
use Mews\Pos\Entity\Account\PayFlexAccount;
use Mews\Pos\Entity\Card\CreditCardInterface;
use Mews\Pos\Event\RequestDataPreparedEvent;
use Mews\Pos\Exceptions\UnsupportedPaymentModelException;
use Mews\Pos\Exceptions\UnsupportedTransactionTypeException;
use Mews\Pos\Factory\AccountFactory;
use Mews\Pos\Factory\CreditCardFactory;
use Mews\Pos\Gateways\PayFlexCPV4Pos;
use Mews\Pos\PosInterface;
use Mews\Pos\Serializer\SerializerInterface;
use Mews\Pos\Tests\Unit\DataMapper\RequestDataMapper\PayFlexCPV4PosRequestDataMapperTest;
use Mews\Pos\Tests\Unit\DataMapper\ResponseDataMapper\PayFlexCPV4PosResponseDataMapperTest;
use Mews\Pos\Tests\Unit\HttpClientTestTrait;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * @covers \Mews\Pos\Gateways\PayFlexCPV4Pos
 * @covers  \Mews\Pos\Gateways\AbstractGateway
 */
class PayFlexCPV4PosTest extends TestCase
{
    use HttpClientTestTrait;

    private PayFlexAccount $account;

    /** @var PayFlexCPV4Pos */
    private PosInterface $pos;

    private array $config;

    private CreditCardInterface $card;

    private array $order = [];

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
            'name'              => 'VakifBank-PayFlex-Common-Payment',
            'class'             => PayFlexCPV4Pos::class,
            'gateway_endpoints' => [
                'payment_api' => 'https://cptest.vakifbank.com.tr/CommonPayment/api/RegisterTransaction',
                'gateway_3d'  => 'https://cptest.vakifbank.com.tr/CommonPayment/api/VposTransaction',
                'query_api'   => 'https://cptest.vakifbank.com.tr/CommonPayment/SecurePayment',
            ],
        ];

        $this->account = AccountFactory::createPayFlexAccount(
            'vakifbank-cp',
            '000000000111111',
            '3XTgER89as',
            'VP999999',
            PosInterface::MODEL_3D_SECURE
        );


        $this->order = [
            'id'          => 'order222',
            'amount'      => 100.00,
            'installment' => 0,
            'currency'    => PosInterface::CURRENCY_TRY,
            'success_url' => 'https://domain.com/success',
            'fail_url'    => 'https://domain.com/fail_url',
            'ip'          => '127.0.0.1',
        ];

        $this->requestMapperMock   = $this->createMock(PayFlexCPV4PosRequestDataMapper::class);
        $this->responseMapperMock  = $this->createMock(PayFlexCPV4PosResponseDataMapper::class);
        $this->serializerMock      = $this->createMock(SerializerInterface::class);
        $this->cryptMock           = $this->createMock(CryptInterface::class);
        $this->httpClientMock      = $this->createMock(HttpClient::class);
        $this->loggerMock          = $this->createMock(LoggerInterface::class);
        $this->eventDispatcherMock = $this->createMock(EventDispatcherInterface::class);

        $this->requestMapperMock->expects(self::any())
            ->method('getCrypt')
            ->willReturn($this->cryptMock);

        $this->pos = new PayFlexCPV4Pos(
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

        $this->card = CreditCardFactory::createForGateway($this->pos, '5555444433332222', '2021', '12', '122', 'ahmet', CreditCardInterface::CARD_TYPE_VISA);
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
     * @return void
     *
     * @throws Exception
     */
    public function testGet3DFormDataSuccess(): void
    {
        $enrollmentResponse = PayFlexCPV4PosRequestDataMapperTest::threeDFormDataProvider()->current()['queryParams'];
        $txType             = PosInterface::TX_TYPE_PAY_AUTH;
        $paymentModel       = PosInterface::MODEL_3D_SECURE;
        $requestData        = ['request-data'];
        $card               = $this->card;
        $order              = $this->order;

        $this->requestMapperMock->expects(self::once())
            ->method('create3DEnrollmentCheckRequestData')
            ->with(
                $this->pos->getAccount(),
                $order,
                $txType,
                $paymentModel,
                $card
            )
            ->willReturn($requestData);

        $this->configureClientResponse(
            $txType,
            $this->config['gateway_endpoints']['payment_api'],
            $requestData,
            $requestData,
            'response-body',
            $enrollmentResponse,
            $order,
            $paymentModel
        );

        $this->requestMapperMock->expects(self::once())
            ->method('create3DFormData')
            ->with(
                null,
                [],
                null,
                null,
                null,
                null,
                $enrollmentResponse
            )
            ->willReturn(['3d-form-data']);

        $result = $this->pos->get3DFormData($order, $paymentModel, $txType, $card);

        $this->assertSame(['3d-form-data'], $result);
    }

    /**
     * @return void
     *
     * @throws Exception
     */
    public function testGet3DFormDataEnrollmentFail(): void
    {
        $txType       = PosInterface::TX_TYPE_PAY_AUTH;
        $paymentModel = PosInterface::MODEL_3D_SECURE;
        $card         = $this->card;
        $order        = $this->order;
        $requestData  = ['request-data'];

        $this->requestMapperMock->expects(self::once())
            ->method('create3DEnrollmentCheckRequestData')
            ->with(
                $this->pos->getAccount(),
                $order,
                $txType,
                $paymentModel,
                $card
            )
            ->willReturn($requestData);

        $enrollmentResponse = [
            'CommonPaymentUrl' => null,
            'PaymentToken'     => null,
            'ErrorCode'        => '5007',
            'ResponseMessage'  => 'Güvenlik Numarası Hatalı',
        ];
        $this->configureClientResponse(
            $txType,
            $this->config['gateway_endpoints']['payment_api'],
            $requestData,
            $requestData,
            'response-body',
            $enrollmentResponse,
            $order,
            $paymentModel
        );

        $this->requestMapperMock->expects(self::never())
            ->method('create3DFormData');

        $this->expectException(Exception::class);

        $this->pos->get3DFormData($order, $paymentModel, $txType, $card);
    }

    public function testMake3DPayment(): void
    {
        $request = Request::create('', 'POST');

        $this->expectException(UnsupportedPaymentModelException::class);
        $this->pos->make3DPayment($request, [], PosInterface::TX_TYPE_PAY_AUTH);
    }

    /**
     * @dataProvider make3DPayPaymentDataProvider
     */
    public function testMake3DPayPayment(
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
            $this->cryptMock->expects(self::never())
                ->method('check3DHash');
        }

        $this->responseMapperMock->expects(self::never())
            ->method('extractMdStatus');

        $this->responseMapperMock->expects(self::never())
            ->method('is3dAuthSuccess');

        $create3DPaymentStatusRequestData = [
            'create3DPaymentStatusRequestData',
        ];
        if ($is3DSuccess) {
            $this->requestMapperMock->expects(self::once())
                ->method('create3DPaymentStatusRequestData')
                ->with($this->account, $request->query->all())
                ->willReturn($create3DPaymentStatusRequestData);

            $this->configureClientResponse(
                $txType,
                $this->config['gateway_endpoints']['query_api'],
                $create3DPaymentStatusRequestData,
                $create3DPaymentStatusRequestData,
                'response-body',
                $paymentResponse,
                $order,
                PosInterface::MODEL_3D_PAY
            );

            $this->responseMapperMock->expects(self::once())
                ->method('map3DPayResponseData')
                ->with($request->query->all(), $txType, $order)
                ->willReturn($expectedResponse);
        } else {
            $this->responseMapperMock->expects(self::once())
                ->method('map3DPayResponseData')
                ->with($request->query->all(), $txType, $order)
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

        $this->pos->make3DPayPayment($request, $order, $txType);

        $result = $this->pos->getResponse();
        $this->assertSame($expectedResponse, $result);
        $this->assertSame($isSuccess, $this->pos->isSuccess());
    }

    /**
     * @dataProvider makeRegularPaymentDataProvider
     */
    public function testMakeRegularPayment(array $order, string $txType, string $apiUrl): void
    {
        $account     = $this->pos->getAccount();
        $card        = $this->card;
        $requestData = ['createNonSecurePaymentRequestData'];
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
            $order,
            PosInterface::MODEL_NON_SECURE
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
            $order,
            PosInterface::MODEL_NON_SECURE
        );

        $this->responseMapperMock->expects(self::once())
            ->method('mapPaymentResponse')
            ->with($decodedResponse, $txType, $order)
            ->willReturn(['result']);

        $this->pos->makeRegularPostPayment($order);
    }

    public function testStatusRequest(): void
    {
        $this->expectException(UnsupportedTransactionTypeException::class);
        $this->pos->status([]);
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
            $order,
            PosInterface::MODEL_NON_SECURE
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
            $order,
            PosInterface::MODEL_NON_SECURE
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

    public static function make3DPayPaymentDataProvider(): array
    {
        $testData = iterator_to_array(
                        PayFlexCPV4PosResponseDataMapperTest::threesDPayResponseDataProvider()
                    );

        return [
            'auth_fail'                    => [
                'order'           => $testData['fail_response_from_gateway_1']['order'],
                'txType'          => $testData['fail_response_from_gateway_1']['txType'],
                'request'         => Request::create(
                    '',
                    'GET',
                    $testData['fail_response_from_gateway_1']['bank_response']
                ),
                'paymentResponse' => [],
                'expected'        => $testData['fail_response_from_gateway_1']['expectedData'],
                'is3DSuccess'     => false,
                'isSuccess'       => false,
            ],
            'success'                      => [
                'order'           => $testData['success_response_from_gateway_1']['order'],
                'txType'          => $testData['success_response_from_gateway_1']['txType'],
                'request'         => Request::create(
                    '',
                    'GET',
                    $testData['success_response_from_gateway_1']['bank_response']
                ),
                'paymentResponse' => $testData['success_response_from_gateway_1']['bank_response'],
                'expected'        => $testData['success_response_from_gateway_1']['expectedData'],
                'is3DSuccess'     => true,
                'isSuccess'       => true,
            ],
        ];
    }

    public static function makeRegularPaymentDataProvider(): array
    {
        return [
            [
                'order'   => [
                    'id' => '2020110828BC',
                ],
                'txType'  => PosInterface::TX_TYPE_PAY_AUTH,
                'api_url' => 'https://cptest.vakifbank.com.tr/CommonPayment/api/RegisterTransaction',
            ],
            [
                'order'   => [
                    'id' => '2020110828BC',
                ],
                'txType'  => PosInterface::TX_TYPE_PAY_PRE_AUTH,
                'api_url' => 'https://cptest.vakifbank.com.tr/CommonPayment/api/RegisterTransaction',
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
                'api_url' => 'https://cptest.vakifbank.com.tr/CommonPayment/api/RegisterTransaction',
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
                'api_url' => 'https://cptest.vakifbank.com.tr/CommonPayment/api/RegisterTransaction',
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
                'api_url' => 'https://cptest.vakifbank.com.tr/CommonPayment/api/RegisterTransaction',
            ],
        ];
    }

    private function configureClientResponse(
        string $txType,
        string $apiUrl,
        array  $requestData,
        $encodedRequestData,
        string $responseContent,
        array  $decodedResponse,
        array  $order,
        string $paymentModel
    ): void
    {
        if ($requestData === $encodedRequestData) {
            $this->serializerMock->expects(self::never())
                ->method('encode');
        } else {
            $this->serializerMock->expects(self::once())
                ->method('encode')
                ->with($requestData, $txType)
                ->willReturn($encodedRequestData);
        }

        $this->serializerMock->expects(self::once())
            ->method('decode')
            ->with($responseContent, $txType)
            ->willReturn($decodedResponse);

        $this->prepareClient(
            $this->httpClientMock,
            $responseContent,
            $apiUrl,
            is_string($encodedRequestData) ? ['body' => $encodedRequestData] : ['form_params' => $encodedRequestData],
        );

        $this->eventDispatcherMock->expects(self::once())
            ->method('dispatch')
            ->with($this->callback(function ($dispatchedEvent) use ($txType, $requestData, $order, $paymentModel) {
                return $dispatchedEvent instanceof RequestDataPreparedEvent
                    && get_class($this->pos) === $dispatchedEvent->getGatewayClass()
                    && $txType === $dispatchedEvent->getTxType()
                    && $requestData === $dispatchedEvent->getRequestData()
                    && $order === $dispatchedEvent->getOrder()
                    && $paymentModel === $dispatchedEvent->getPaymentModel()
                    ;
            }));
    }
}
