<?php
/**
 * @license MIT
 */

namespace Mews\Pos;

use Mews\Pos\Entity\Account\AbstractPosAccount;
use Mews\Pos\Entity\Card\AbstractCreditCard;
use Mews\Pos\Exceptions\UnsupportedPaymentModelException;
use Symfony\Component\HttpFoundation\Request;

/**
 * Interface PosInterface
 */
interface PosInterface
{
    /** @var string */
    public const LANG_TR = 'tr';

    /** @var string */
    public const LANG_EN = 'en';

    /** @var string */
    public const TX_PAY = 'pay';

    /** @var string */
    public const TX_PRE_PAY = 'pre';

    /** @var string */
    public const TX_POST_PAY = 'post';

    /** @var string */
    public const TX_CANCEL = 'cancel';

    /** @var string */
    public const TX_REFUND = 'refund';

    /** @var string */
    public const TX_STATUS = 'status';

    /** @var string */
    public const TX_HISTORY = 'history';

    /** @var string */
    public const MODEL_3D_SECURE = '3d';

    /** @var string */
    public const MODEL_3D_PAY = '3d_pay';

    /** @var string */
    public const MODEL_3D_PAY_HOSTING = '3d_pay_hosting';

    /** @var string */
    public const MODEL_3D_HOST = '3d_host';

    /** @var string */
    public const MODEL_NON_SECURE = 'regular';

    /** @var string */
    public const CURRENCY_TRY = 'TRY';

    /** @var string */
    public const CURRENCY_USD = 'USD';

    /** @var string */
    public const CURRENCY_EUR = 'EUR';

    /** @var string */
    public const CURRENCY_GBP = 'GBP';

    /** @var string */
    public const CURRENCY_JPY = 'JPY';

    /** @var string */
    public const CURRENCY_RUB = 'RUB';

    /**
     * returns form data, key values, necessary for 3D payment
     *
     * @param array<string, mixed>                          $order
     * @param PosInterface::MODEL_*                         $paymentModel
     * @param PosInterface::TX_PAY|PosInterface::TX_PRE_PAY $txType
     * @param AbstractCreditCard|null                       $card
     *
     * @return array{gateway: string, method: 'POST'|'GET', inputs: array<string, string>}
     */
    public function get3DFormData(array $order, string $paymentModel, string $txType, ?AbstractCreditCard $card = null): array;

    /**
     * Regular Payment
     *
     * @param array<string, mixed>                                                    $order
     * @param AbstractCreditCard                                                      $card
     * @param PosInterface::TX_PAY|PosInterface::TX_PRE_PAY|PosInterface::TX_POST_PAY $txType
     *
     * @return PosInterface
     */
    public function makeRegularPayment(array $order, AbstractCreditCard $card, string $txType): PosInterface;

    /**
     * Make 3D Payment
     *
     * @param Request                                       $request
     * @param array<string, mixed>                          $order
     * @param PosInterface::TX_PAY|PosInterface::TX_PRE_PAY $txType
     * @param AbstractCreditCard                            $card simdilik sadece PayFlexV4Pos icin card isteniyor.
     *
     * @return PosInterface
     */
    public function make3DPayment(Request $request, array $order, string $txType, AbstractCreditCard $card = null): PosInterface;

    /**
     * Make 3D Pay Payment
     *
     * @param Request $request
     *
     * @return PosInterface
     */
    public function make3DPayPayment(Request $request): PosInterface;

    /**
     * Just returns formatted data of host payment response
     *
     * @param Request $request
     *
     * @return PosInterface
     */
    public function make3DHostPayment(Request $request): PosInterface;

    /**
     * Make Payment
     *
     * @param PosInterface::MODEL_*                                                   $paymentModel
     * @param array<string, mixed>                                                    $order
     * @param PosInterface::TX_PAY|PosInterface::TX_PRE_PAY|PosInterface::TX_POST_PAY $txType
     * @param AbstractCreditCard                                                      $card
     *
     * @return PosInterface
     *
     * @throws UnsupportedPaymentModelException
     */
    public function payment(string $paymentModel, array $order, string $txType, AbstractCreditCard $card): PosInterface;

    /**
     * Refund Order
     *
     * @param array<string, mixed> $order
     *
     * @return PosInterface
     */
    public function refund(array $order): PosInterface;

    /**
     * Cancel Order
     *
     * @param array<string, mixed> $order
     *
     * @return PosInterface
     */
    public function cancel(array $order): PosInterface;

    /**
     * Order Status
     *
     * @param array<string, mixed> $order
     *
     * @return PosInterface
     */
    public function status(array $order): PosInterface;

    /**
     * Order History
     *
     * @param array<string, mixed> $meta
     *
     * @return PosInterface
     */
    public function history(array $meta): PosInterface;

    /**
     * Is success
     *
     * @return bool
     */
    public function isSuccess(): bool;

    /**
     * returns the latest response
     *
     * @return array<string, mixed>|null
     */
    public function getResponse(): ?array;

    /**
     * Enable/Disable test mode
     *
     * @param bool $testMode
     *
     * @return PosInterface
     */
    public function setTestMode(bool $testMode): PosInterface;

    /**
     * Enable/Disable test mode
     *
     * @return bool
     */
    public function isTestMode(): bool;

    /**
     * @return array<AbstractCreditCard::CARD_TYPE_*, string>
     */
    public function getCardTypeMapping(): array;

    /**
     * @return AbstractPosAccount
     */
    public function getAccount(): AbstractPosAccount;
}
