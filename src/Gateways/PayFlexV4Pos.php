<?php
/**
 * @license MIT
 */
namespace Mews\Pos\Gateways;

use Exception;
use InvalidArgumentException;
use LogicException;
use Mews\Pos\DataMapper\PayFlexV4PosRequestDataMapper;
use Mews\Pos\DataMapper\ResponseDataMapper\PayFlexV4PosResponseDataMapper;
use Mews\Pos\Entity\Account\AbstractPosAccount;
use Mews\Pos\Entity\Account\PayFlexAccount;
use Mews\Pos\Entity\Card\AbstractCreditCard;
use Mews\Pos\Exceptions\UnsupportedPaymentModelException;
use Mews\Pos\Exceptions\UnsupportedTransactionTypeException;
use Mews\Pos\PosInterface;
use Psr\Log\LogLevel;
use Symfony\Component\HttpFoundation\Request;
use function gettype;
use function is_string;
use function sprintf;

/**
 * PayFlex MPI ISD v4 gateway'i destekler (INNOVA BİLİŞİM ÇÖZÜMLERİ A.Ş)
 * Dokumanlar: http://sanalpos.innova.com.tr/
 */
class PayFlexV4Pos extends AbstractGateway
{
    /** @var string */
    public const NAME = 'PayFlexV4';

    /** @var PayFlexAccount */
    protected $account;

    /** @var PayFlexV4PosRequestDataMapper */
    protected $requestDataMapper;

    /** @var PayFlexV4PosResponseDataMapper */
    protected $responseDataMapper;

    /** @return PayFlexAccount */
    public function getAccount(): AbstractPosAccount
    {
        return $this->account;
    }

    /**
     * @inheritDoc
     */
    public function make3DPayment(Request $request, array $order, string $txType, AbstractCreditCard $card = null): PosInterface
    {
        $request = $request->request;
        $status = $request->get('Status');
        // 3D authorization failed
        if ('Y' !== $status && 'A' !== $status) {
            $this->response = $this->responseDataMapper->map3DPaymentData($request->all(), []);

            return $this;
        }

        if ('A' === $status) {
            // TODO Half 3D Secure
            $this->response = $this->responseDataMapper->map3DPaymentData($request->all(), []);

            return $this;
        }

        $this->logger->log(LogLevel::DEBUG, 'finishing payment', ['md_status' => $status]);
        /** @var array{Eci: string, Cavv: string, VerifyEnrollmentRequestId: string} $requestData */
        $requestData = $request->all();
        // NOT: diger gatewaylerden farkli olarak payflex kredit bilgilerini bu asamada da istiyor.
        $requestData  = $this->requestDataMapper->create3DPaymentRequestData($this->account, $order, $txType, $requestData, $card);
        $contents     = $this->serializer->encode($requestData, $txType);
        $bankResponse = $this->send($contents, $txType);

        $this->response = $this->responseDataMapper->map3DPaymentData($request->all(), $bankResponse);
        $this->logger->log(LogLevel::DEBUG, 'finished 3D payment', ['mapped_response' => $this->response]);

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function make3DPayPayment(Request $request): PosInterface
    {
        throw new UnsupportedPaymentModelException();
    }

    /**
     * @inheritDoc
     */
    public function make3DHostPayment(Request $request): PosInterface
    {
        throw new UnsupportedPaymentModelException();
    }

    /**
     * @inheritDoc
     */
    public function history(array $meta): PosInterface
    {
        throw new UnsupportedTransactionTypeException();
    }

    /**
     * {@inheritDoc}
     */
    public function get3DFormData(array $order, string $paymentModel, string $txType, AbstractCreditCard $card = null): array
    {
        if (!$card instanceof AbstractCreditCard) {
            throw new LogicException('Kredi kartı veya sipariş bilgileri eksik!');
        }

        $data = $this->sendEnrollmentRequest($order, $card, $txType);

        $status = $data['Message']['VERes']['Status'];
        /**
         * Status values:
         * Y:Kart 3-D Secure programına dâhil
         * N:Kart 3-D Secure programına dâhil değil
         * U:İşlem gerçekleştirilemiyor
         * E:Hata durumu
         */
        if ('E' === $status) {
            $this->logger->log(LogLevel::ERROR, 'enrollment fail response', $data);
            throw new Exception($data['ErrorMessage'], $data['MessageErrorCode']);
        }

        if ('N' === $status) {
            //half secure olarak devam et yada satisi iptal et.
            $this->logger->log(LogLevel::ERROR, 'enrollment fail response', $data);
            throw new Exception('Kart 3-D Secure programına dâhil değil');
        }

        if ('U' === $status) {
            $this->logger->log(LogLevel::ERROR, 'enrollment fail response', $data);
            throw new Exception('İşlem gerçekleştirilemiyor');
        }

        $this->logger->log(LogLevel::DEBUG, 'preparing 3D form data');

        return $this->requestDataMapper->create3DFormData($this->account, null, $paymentModel, $txType, '', null, $data['Message']['VERes']);
    }

    /**
     * Müşteriden kredi kartı bilgilerini aldıktan sonra GET 7/24 MPI’a kart “Kredi Kartı Kayıt Durumu”nun
     * (Enrollment Status) sorulması, yani kart 3-D Secure programına dâhil mi yoksa değil mi sorgusu
     *
     * @param array<string, int|string|float|null> $order
     * @param AbstractCreditCard                   $card
     * @param PosInterface::TX_*                   $txType
     *
     * @return array<string, mixed>
     *
     * @throws Exception
     */
    public function sendEnrollmentRequest(array $order, AbstractCreditCard $card, string $txType): array
    {
        $requestData = $this->requestDataMapper->create3DEnrollmentCheckRequestData($this->account, $order, $card);

        return $this->send($requestData, $txType, $this->get3DGatewayURL());
    }

    /**
     * @inheritDoc
     *
     * @return array<string, mixed>
     */
    protected function send($contents, string $txType, ?string $url = null): array
    {
        $url = $url ?: $this->getApiURL();
        $this->logger->log(LogLevel::DEBUG, 'sending request', ['url' => $url]);

        if (!is_string($contents)) {
            throw new InvalidArgumentException(sprintf('Argument type must be XML string, %s provided.', gettype($contents)));
        }

        $response = $this->client->post($url, ['form_params' => ['prmstr' => $contents]]);
        $this->logger->log(LogLevel::DEBUG, 'request completed', ['status_code' => $response->getStatusCode()]);

        return $this->data = $this->serializer->decode($response->getBody()->getContents(), $txType);
    }
}
