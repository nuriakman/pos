<?php
/**
 * @license MIT
 */
namespace Mews\Pos\Tests\DataMapper;

use Mews\Pos\DataMapper\EstPosRequestDataMapper;
use Mews\Pos\Entity\Account\AbstractPosAccount;
use Mews\Pos\Entity\Card\AbstractCreditCard;
use Mews\Pos\Factory\AccountFactory;
use Mews\Pos\Factory\CreditCardFactory;
use Mews\Pos\Factory\PosFactory;
use Mews\Pos\Gateways\AbstractGateway;
use Mews\Pos\Gateways\EstPos;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * EstPosRequestDataMapperTest
 */
class EstPosRequestDataMapperTest extends TestCase
{
    /** @var AbstractPosAccount */
    private $account;

    /** @var AbstractCreditCard */
    private $card;

    /** @var EstPosRequestDataMapper */
    private $requestDataMapper;

    private $order;

    private $config;

    protected function setUp(): void
    {
        parent::setUp();

        $this->config = require __DIR__.'/../../config/pos_test.php';

        $this->account = AccountFactory::createEstPosAccount(
            'akbank',
            '700655000200',
            'ISBANKAPI',
            'ISBANK07',
            AbstractGateway::MODEL_3D_SECURE,
            'TRPS0200'
        );

        $this->order = [
            'id'          => 'order222',
            'ip'          => '127.0.0.1',
            'name'        => 'siparis veren',
            'email'       => 'test@test.com',
            'amount'      => '100.25',
            'installment' => 0,
            'currency'    => 'TRY',
            'success_url' => 'https://domain.com/success',
            'fail_url'    => 'https://domain.com/fail_url',
            'lang'        => 'tr',
            'rand'        => 'rand',
        ];

        $pos = PosFactory::createPosGateway($this->account, $this->config);

        $this->requestDataMapper = new EstPosRequestDataMapper(PosFactory::getGatewayCrypt(EstPos::class, new NullLogger()));
        $this->card              = CreditCardFactory::create($pos, '5555444433332222', '22', '01', '123', 'ahmet', AbstractCreditCard::CARD_TYPE_VISA);
    }

    /**
     * @return void
     */
    public function testMapRecurringFrequency()
    {
        $this->assertEquals('M', $this->requestDataMapper->mapRecurringFrequency('MONTH'));
        $this->assertEquals('M', $this->requestDataMapper->mapRecurringFrequency('M'));
    }

    /**
     * @return void
     */
    public function testMapCurrency()
    {
        $this->assertEquals('949', $this->requestDataMapper->mapCurrency('TRY'));
        $this->assertEquals('978', $this->requestDataMapper->mapCurrency('EUR'));
    }

    /**
     * @param string|int|null $installment
     * @param string|int      $expected
     *
     * @testWith ["0", ""]
     *           ["1", ""]
     *           ["2", "2"]
     *           [2, "2"]
     *
     * @return void
     */
    public function testMapInstallment($installment, $expected)
    {
        $actual = $this->requestDataMapper->mapInstallment($installment);
        $this->assertSame($expected, $actual);
    }

    /**
     * @return void
     */
    public function testCreateNonSecurePostAuthPaymentRequestData()
    {
        $order = (object) [
            'id' => '2020110828BC',
        ];

        $actual = $this->requestDataMapper->createNonSecurePostAuthPaymentRequestData($this->account, $order);

        $expectedData = $this->getSampleNonSecurePaymentPostRequestData($this->account, $order);
        $this->assertEquals($expectedData, $actual);
    }

    /**
     * @return void
     */
    public function testCreateNonSecurePaymentRequestData()
    {
        $actual = $this->requestDataMapper->createNonSecurePaymentRequestData($this->account, (object) $this->order, AbstractGateway::TX_PAY, $this->card);

        $expectedData = $this->getSampleNonSecurePaymentRequestData($this->account, (object) $this->order, $this->card);
        $this->assertEquals($expectedData, $actual);
    }

    /**
     * @return void
     */
    public function testCreateCancelRequestData()
    {
        $order = (object) [
            'id' => '2020110828BC',
        ];

        $actual = $this->requestDataMapper->createCancelRequestData($this->account, $order);

        $expectedData = $this->getSampleCancelXMLData($this->account, $order);
        $this->assertEquals($expectedData, $actual);
    }

    /**
     * @return void
     */
    public function testCreateCancelRecurringOrderRequestData()
    {
        $order = (object) [
            'id' => '2020110828BC',
            'recurringOrderInstallmentNumber' => '2',
        ];

        $actual = $this->requestDataMapper->createCancelRequestData($this->account, $order);

        $expectedData = $this->getSampleRecurringOrderCancelXMLData($this->account, $order);
        $this->assertEquals($expectedData, $actual);
    }

    /**
     * @return void
     */
    public function testCreateHistoryRequestData()
    {
        $order = [
            'order_id' => '2020110828BC',
        ];

        $actual = $this->requestDataMapper->createHistoryRequestData($this->account, (object) [], $order);

        $expectedData = $this->getSampleHistoryRequestData($this->account, $order);
        $this->assertEquals($expectedData, $actual);
    }

    /**
     * @return void
     */
    public function testCreate3DPaymentRequestData()
    {
        $order = (object) [
            'id'          => '2020110828BC',
            'email'       => 'samp@iexample.com',
            'name'        => 'john doe',
            'user_id'     => '1535',
            'ip'          => '192.168.1.0',
            'amount'      => 100.01,
            'installment' => '0',
            'currency'    => 'TRY',
            'success_url' => 'http://localhost/finansbank-payfor/3d/response.php',
            'fail_url'    => 'http://localhost/finansbank-payfor/3d/response.php',
        ];
        $responseData = [
            'md'   => '1',
            'xid'  => '100000005xid',
            'eci'  => '100000005eci',
            'cavv' => 'cavv',
        ];

        $actual = $this->requestDataMapper->create3DPaymentRequestData($this->account, $order, AbstractGateway::TX_PAY, $responseData);

        $expectedData = $this->getSample3DPaymentRequestData($this->account, $order, $responseData);
        $this->assertEquals($expectedData, $actual);
    }

    /**
     * @return void
     */
    public function testCreate3DPaymentRequestDataRecurringOrder()
    {
        $order = (object) [
            'id'                        => '2020110828BC',
            'email'                     => 'samp@iexample.com',
            'name'                      => 'john doe',
            'user_id'                   => '1535',
            'ip'                        => '192.168.1.0',
            'amount'                    => 100.01,
            'installment'               => 0,
            'currency'                  => 'TRY',
            'success_url'               => 'http://localhost/finansbank-payfor/3d/response.php',
            'fail_url'                  => 'http://localhost/finansbank-payfor/3d/response.php',
            'recurringFrequency'        => 3,
            'recurringFrequencyType'    => 'MONTH',
            'recurringInstallmentCount' => 4,
        ];

        $responseData = [
            'md'   => '1',
            'xid'  => '100000005xid',
            'eci'  => '100000005eci',
            'cavv' => 'cavv',
        ];

        $actual = $this->requestDataMapper->create3DPaymentRequestData($this->account, $order, AbstractGateway::TX_PAY, $responseData);

        $expectedData = $this->getSample3DPaymentRequestData($this->account, $order, $responseData);
        $this->assertEquals($expectedData, $actual);
    }


    /**
     * @return void
     */
    public function testGet3DFormData()
    {
        $txType = AbstractGateway::TX_PAY;
        $card       = $this->card;
        $gatewayURL = $this->config['banks'][$this->account->getBank()]['gateway_endpoints']['gateway_3d'];

        $inputs = [
            'clientid'  => $this->account->getClientId(),
            'storetype' => AbstractGateway::MODEL_3D_SECURE,
            'hash'      => 'S7UxUAohxaxzl35WxHyDfuQx0sg=',
            'firmaadi'  => $this->order['name'],
            'Email'     => $this->order['email'],
            'amount'    => $this->order['amount'],
            'oid'       => $this->order['id'],
            'okUrl'     => $this->order['success_url'],
            'failUrl'   => $this->order['fail_url'],
            'rnd'       => $this->order['rand'],
            'lang'      => 'tr',
            'currency'  => 949,
            'islemtipi' => 'Auth',
            'taksit'    => '',
        ];
        $form   = [
            'gateway' => $gatewayURL,
            'method'  => 'POST',
            'inputs'  => $inputs,
        ];
        //test without card
        $this->assertEquals($form, $this->requestDataMapper->create3DFormData(
            $this->account,
            (object) $this->order,
            AbstractGateway::MODEL_3D_SECURE,
            $txType,
            $gatewayURL
        ));

        //test with card
        if ($card) {
            $form['inputs']['cardType']                        = '1';
            $form['inputs']['pan']                             = $card->getNumber();
            $form['inputs']['Ecom_Payment_Card_ExpDate_Month'] = '01';
            $form['inputs']['Ecom_Payment_Card_ExpDate_Year']  = '22';
            $form['inputs']['cv2']                             = $card->getCvv();
        }

        $this->assertEquals($form, $this->requestDataMapper->create3DFormData(
            $this->account,
            (object) $this->order,
            AbstractGateway::MODEL_3D_SECURE,
            $txType,
            $gatewayURL,
            $card
        ));
    }

    /**
     * @return void
     */
    public function testGet3DHostFormData()
    {
        $gatewayURL = $this->config['banks'][$this->account->getBank()]['gateway_endpoints']['gateway_3d'];
        $inputs     = [
            'clientid'  => $this->account->getClientId(),
            'storetype' => '3d_host',
            'hash'      => 'S7UxUAohxaxzl35WxHyDfuQx0sg=',
            'firmaadi'  => $this->order['name'],
            'Email'     => $this->order['email'],
            'amount'    => $this->order['amount'],
            'oid'       => $this->order['id'],
            'okUrl'     => $this->order['success_url'],
            'failUrl'   => $this->order['fail_url'],
            'rnd'       => $this->order['rand'],
            'lang'      => 'tr',
            'currency'  => '949',
            'islemtipi'  => 'Auth',
            'taksit'    => '',
        ];
        $form       = [
            'gateway' => $gatewayURL,
            'method'  => 'POST',
            'inputs'  => $inputs,
        ];

        $this->assertEquals($form, $this->requestDataMapper->create3DFormData(
            $this->account,
            (object) $this->order,
            AbstractGateway::MODEL_3D_HOST,
            AbstractGateway::TX_PAY,
            $gatewayURL
        ));
    }

    /**
     * @return void
     */
    public function testCreateStatusRequestData()
    {
        $order = (object) [
            'id' => '2020110828BC',
        ];

        $actualData = $this->requestDataMapper->createStatusRequestData($this->account, $order);

        $expectedData = $this->getSampleStatusRequestData($this->account, $order);
        $this->assertEquals($expectedData, $actualData);
    }

    /**
     * @return void
     */
    public function testCreateRecurringStatusRequestData()
    {
        $order = (object) [
            'recurringId' => '2020110828BC',
        ];

        $actualData = $this->requestDataMapper->createStatusRequestData($this->account, $order);

        $expectedData = $this->getSampleRecurringStatusRequestData($this->account, $order);
        $this->assertEquals($expectedData, $actualData);
    }

    /**
     * @return void
     */
    public function testCreateRefundRequestData()
    {
        $order = (object) [
            'id'       => '2020110828BC',
            'amount'   => 50,
            'currency' => 'TRY',
        ];

        $actual = $this->requestDataMapper->createRefundRequestData($this->account, $order);

        $expectedData = $this->getSampleRefundXMLData($this->account, $order);
        $this->assertEquals($expectedData, $actual);
    }

    /**
     * @param AbstractPosAccount $account
     * @param                    $order
     * @param array              $responseData
     *
     * @return array
     */
    private function getSample3DPaymentRequestData(AbstractPosAccount $account, $order, array $responseData): array
    {
        $requestData = [
            'Name'                    => $account->getUsername(),
            'Password'                => $account->getPassword(),
            'ClientId'                => $account->getClientId(),
            'Type'                    => 'Auth',
            'IPAddress'               => $order->ip,
            'Email'                   => $order->email,
            'OrderId'                 => $order->id,
            'UserId'                  => $order->user_id ?? null,
            'Total'                   => 100.01,
            'Currency'                => '949',
            'Taksit'                  => '',
            'Number'                  => $responseData['md'],
            'PayerTxnId'              => $responseData['xid'],
            'PayerSecurityLevel'      => $responseData['eci'],
            'PayerAuthenticationCode' => $responseData['cavv'],
            'Mode'                    => 'P',
        ];
        if (isset($order->name)) {
            $requestData['BillTo'] = [
                'Name' => $order->name,
            ];
        }

        if (isset($order->recurringFrequency)) {
            $requestData['PbOrder'] = [
                'OrderType'              => 0,
                'OrderFrequencyInterval' => $order->recurringFrequency,
                'OrderFrequencyCycle'    => 'M',
                'TotalNumberPayments'    => $order->recurringInstallmentCount,
            ];
        }

        return $requestData;
    }

    /**
     * @param AbstractPosAccount $account
     * @param                    $order
     *
     * @return array
     */
    private function getSampleCancelXMLData(AbstractPosAccount $account, $order): array
    {
        return [
            'Name'     => $account->getUsername(),
            'Password' => $account->getPassword(),
            'ClientId' => $account->getClientId(),
            'OrderId'  => $order->id,
            'Type'     => 'Void',
        ];
    }

    /**
     * @param AbstractPosAccount $account
     * @param                    $order
     *
     * @return array
     */
    private function getSampleRecurringOrderCancelXMLData(AbstractPosAccount $account, $order): array
    {
        return [
            'Name'     => $account->getUsername(),
            'Password' => $account->getPassword(),
            'ClientId' => $account->getClientId(),
            'Extra'  => [
                'RECORDTYPE' => 'Order',
                'RECURRINGOPERATION' => 'Cancel',
                'RECORDID' => $order->id . '-' . $order->recurringOrderInstallmentNumber,
            ],
        ];
    }

    /**
     * @param AbstractPosAccount $account
     * @param                    $order
     * @param AbstractCreditCard $card
     *
     * @return array
     */
    private function getSampleNonSecurePaymentRequestData(AbstractPosAccount $account, $order, AbstractCreditCard $card): array
    {
        return [
            'Name'      => $account->getUsername(),
            'Password'  => $account->getPassword(),
            'ClientId'  => $account->getClientId(),
            'Type'      => 'Auth',
            'IPAddress' => $order->ip,
            'Email'     => $order->email,
            'OrderId'   => $order->id,
            'UserId'    => $order->user_id ?? null,
            'Total'     => '100.25',
            'Currency'  => '949',
            'Taksit'    => '',
            'Number'    => $card->getNumber(),
            'Expires'   => '01/22',
            'Cvv2Val'   => $card->getCvv(),
            'Mode'      => 'P',
            'BillTo'    => [
                'Name' => $order->name ?: null,
            ],
        ];
    }

    /**
     * @param AbstractPosAccount $account
     * @param                    $order
     *
     * @return array
     */
    private function getSampleNonSecurePaymentPostRequestData(AbstractPosAccount $account, $order): array
    {
        return [
            'Name'     => $account->getUsername(),
            'Password' => $account->getPassword(),
            'ClientId' => $account->getClientId(),
            'Type'     => 'PostAuth',
            'OrderId'  => $order->id,
        ];
    }

    /**
     * @param AbstractPosAccount $account
     * @param                    $order
     *
     * @return array
     */
    private function getSampleStatusRequestData(AbstractPosAccount $account, $order): array
    {
        return [
            'Name'     => $account->getUsername(),
            'Password' => $account->getPassword(),
            'ClientId' => $account->getClientId(),
            'OrderId'  => $order->id,
            'Extra'    => [
                'ORDERSTATUS' => 'QUERY',
            ],
        ];
    }

    /**
     * @param AbstractPosAccount $account
     * @param                    $order
     *
     * @return array
     */
    private function getSampleRecurringStatusRequestData(AbstractPosAccount $account, $order): array
    {
        return [
            'Name'     => $account->getUsername(),
            'Password' => $account->getPassword(),
            'ClientId' => $account->getClientId(),
            'Extra'    => [
                'ORDERSTATUS' => 'QUERY',
                'RECURRINGID' => $order->recurringId,
            ],
        ];
    }

    /**
     * @param AbstractPosAccount $account
     * @param                    $order
     *
     * @return array
     */
    private function getSampleRefundXMLData(AbstractPosAccount $account, $order): array
    {
        $data = [
            'Name'     => $account->getUsername(),
            'Password' => $account->getPassword(),
            'ClientId' => $account->getClientId(),
            'OrderId'  => $order->id,
            'Currency' => 949,
            'Type'     => 'Credit',
        ];

        if ($order->amount) {
            $data['Total'] = $order->amount;
        }

        return $data;
    }

    /**
     * @param AbstractPosAccount $account
     * @param                    $customQueryData
     *
     * @return array
     */
    private function getSampleHistoryRequestData(AbstractPosAccount $account, $customQueryData): array
    {
        return [
            'Name'     => $account->getUsername(),
            'Password' => $account->getPassword(),
            'ClientId' => $account->getClientId(),
            'OrderId'  => $customQueryData['order_id'],
            'Extra'    => [
                'ORDERHISTORY' => 'QUERY',
            ],
        ];
    }
}
