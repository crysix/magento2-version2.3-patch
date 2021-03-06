<?php
namespace Heidelpay\Gateway\Helper;

use Heidelpay\MessageCodeMapper\MessageCodeMapper;
use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\HTTP\ZendClientFactory;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Invoice;

/**
 * Heidelpay payment helper
 *
 * The payment helper is a collection of function to prepare an send
 *
 * @license    Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 * @copyright  Copyright © 2016-present heidelpay GmbH. All rights reserved.
 *
 * @link       http://dev.heidelpay.com/magento2
 *
 * @author     Jens Richter
 *
 * @package    Heidelpay
 * @subpackage Magento2
 * @category   Magento2
 */
class Payment extends AbstractHelper
{
    protected $_invoiceOrderEmail = true;
    protected $_debug = false;

    /** @var ZendClientFactory */
    protected $httpClientFactory;

    /** @var \Magento\Framework\DB\TransactionFactory */
    protected $transactionFactory;

    /** @var \Magento\Framework\Locale\Resolver */
    protected $localeResolver;

    /**
     * @param Context $context
     * @param \Magento\Framework\HTTP\ZendClientFactory $httpClientFactory
     * @param \Magento\Framework\DB\TransactionFactory $transactionFactory
     * @param \Magento\Framework\Locale\Resolver $localeResolver
     */
    public function __construct(
        Context $context,
        \Magento\Framework\HTTP\ZendClientFactory $httpClientFactory,
        \Magento\Framework\DB\TransactionFactory $transactionFactory,
        \Magento\Framework\Locale\Resolver $localeResolver
    ) {
        $this->httpClientFactory = $httpClientFactory;
        $this->transactionFactory = $transactionFactory;
        $this->localeResolver = $localeResolver;

        parent::__construct($context);
    }

    public function splitPaymentCode($PAYMENT_CODE)
    {
        return preg_split('/\./', $PAYMENT_CODE);
    }

    /**
     * @param array                      $data
     * @param \Magento\Sales\Model\Order $order
     * @param bool                       $message
     */
    public function mapStatus($data, $order, $message = false)
    {
        $paymentCode = $this->splitPaymentCode($data['PAYMENT_CODE']);

        $message = !empty($message) ? $message : $data['PROCESSING_RETURN'];

        $quoteID = ($order->getLastQuoteId() === false)
            ? $order->getQuoteId()
            : $order->getLastQuoteId(); // last_quote_id workaround for trusted shop buyerprotection

        // If an order has been canceled, closed or complete -> do not change order status.
        if ($order->getStatus() == Order::STATE_CANCELED
            || $order->getStatus() == Order::STATE_CLOSED
            || $order->getStatus() == Order::STATE_COMPLETE
        ) {
            // you can use this event for example to get a notification when a canceled order has been paid
            return;
        }

        if ($data['PROCESSING_RESULT'] == 'NOK') {
            $order->getPayment()->getMethodInstance()->cancelledTransactionProcessing($order, $message);
        } elseif ($this->isProcessing($paymentCode[1], $data)) {
            $order->getPayment()->getMethodInstance()->processingTransactionProcessing($data, $order);
        } else {
            $order->getPayment()->getMethodInstance()->pendingTransactionProcessing($data, $order, $message);
        }
    }

    /**
     * function to format amount
     *
     * @param mixed $number
     *
     * @return string
     */
    public function format($number)
    {
        return number_format($number, 2, '.', '');
    }

    /**
     * helper to generate customer payment error messages
     *
     * @param string|null $errorCode
     *
     * @return string
     * @throws \Heidelpay\MessageCodeMapper\Exceptions\MissingLocaleFileException
     */
    public function handleError($errorCode = null)
    {
        $messageCodeMapper = new MessageCodeMapper($this->localeResolver->getLocale());
        return $messageCodeMapper->getMessage($errorCode);
    }

    /**
     * Checks if the currency in the data set matches the currency in the order.
     *
     * @param Order $order
     * @param array $data
     *
     * @return bool
     */
    public function isMatchingAmount(Order $order, $data)
    {
        if (!isset($data['PRESENTATION_AMOUNT'])) {
            return false;
        }

        return $this->format($order->getGrandTotal()) == $data['PRESENTATION_AMOUNT'];
    }

    /**
     * Checks if the currency in the data set matches the currency in the order.
     *
     * @param Order $order
     * @param array $data
     *
     * @return bool
     */
    public function isMatchingCurrency(Order $order, $data)
    {
        if (!isset($data['PRESENTATION_CURRENCY'])) {
            return false;
        }

        return $order->getOrderCurrencyCode() == $data['PRESENTATION_CURRENCY'];
    }

    /**
     * Checks if the data indicates a processing payment transaction.
     *
     * @param string $paymentCode
     * @param array  $data
     *
     * @return bool
     */
    public function isProcessing($paymentCode, $data)
    {
        if (!isset($data['PROCESSING_RESULT']) && !isset($data['PROCESSING_STATUS_CODE'])) {
            return false;
        }

        return in_array($paymentCode, ['CP', 'DB', 'FI', 'RC'])
            && $data['PROCESSING_RESULT'] == 'ACK'
            && $data['PROCESSING_STATUS_CODE'] != 80;
    }

    /**
     * @param array $data
     *
     * @return bool
     */
    public function isPreAuthorization(array $data)
    {
        if (!isset($data['PAYMENT_CODE'])) {
            return false;
        }

        $paymentCode = $this->splitPaymentCode($data['PAYMENT_CODE']);

        if ($paymentCode[1] == 'PA') {
            return true;
        }

        return false;
    }

    /**
     * Determines if the payment code and type are for a receipt.
     *
     * @param string $paymentMethod
     * @param string $paymentType
     *
     * @return bool
     */
    public function isReceiptAble($paymentMethod, $paymentType)
    {
        if ($paymentType !== 'RC') {
            return false;
        }

        switch ($paymentMethod) {
            case 'DD':
            case 'PP':
            case 'IV':
            case 'OT':
            case 'PC':
            case 'MP':
            case 'HP':
                $return = true;
                break;

            default:
                $return = false;
                break;
        }

        return $return;
    }

    /**
     * Checks if the given paymentcode is viable for a refund transaction.
     *
     * @param string $paymentcode
     *
     * @return bool
     */
    public function isRefundable($paymentcode)
    {
        if ($paymentcode === 'DB' || $paymentcode === 'CP' || $paymentcode === 'RC') {
            return true;
        }

        return false;
    }

    /**
     * Saves a transaction by the given invoice.
     *
     * @param Invoice $invoice
     */
    public function saveTransaction(Invoice $invoice)
    {
        $transaction = $this->transactionFactory->create();
        $transaction->addObject($invoice)
            ->addObject($invoice->getOrder())
            ->save();
    }
}
