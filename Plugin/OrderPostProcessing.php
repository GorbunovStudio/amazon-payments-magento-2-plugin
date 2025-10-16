<?php

declare(strict_types=1);

namespace Amazon\Pay\Plugin;

use Amazon\Pay\Api\CheckoutSessionManagementInterface;
use Amazon\Pay\Gateway\Config\Config;
use Amazon\Pay\Service\PlacedOrderHolder;
use Closure;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\Data\PaymentInterface;
use Magento\Sales\Api\Data\TransactionInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order\CreditmemoFactory;

class OrderPostProcessing
{
    public function __construct(
        private CheckoutSessionManagementInterface $checkoutSessionManagement,
        private CartRepositoryInterface $quoteRepository,
        private OrderRepositoryInterface $orderRepository,
        private PlacedOrderHolder $placedOrderHolder,
        private CreditmemoFactory $creditmemoFactory
    ) {
    }

    public function aroundPlaceOrder(
        CartManagementInterface $subject,
        Closure $proceed,
        int $cartId,
        PaymentInterface $payment = null
    ): int {
        try {
            $result = (int) $proceed($cartId, $payment);

            if (!$payment || $payment->getMethod() !== Config::CODE) {
                return $result;
            }

            $completeResult = $this->checkoutSessionManagement->completeCheckoutSession(
                $payment->getAdditionalData()['amazon_session_id'], 
                $cartId, 
                $result,
                true // better to have a new config value to choose flow type to complete
            );

            if (!isset($completeResult['success']) || $completeResult['success'] !== true) {
                throw new \RuntimeException(
                    'Unable to complete Amazon Pay checkout session: ' . $completeResult['message'] ?? 'Unknown error'
                );
            }

            return $result;
        } catch (\Throwable $e) {
            $quote = $this->quoteRepository->get((int) $cartId);

            $quotePayment = $quote->getPayment();

            // Abort if the payment method is not relevant.
            if ($quotePayment->getMethod() !== Config::CODE) {
                throw $e;
            }

            $amazonSessionId = $payment->getAdditionalData()['amazon_session_id'];

            $errorMessagePrefix = 'Unable to cancel payment: ';

            /** @var \Magento\Sales\Model\Order|null */
            $order = $this->placedOrderHolder->retrieve();

            // Abort if the order object is not available
            if (!$order) {
                throw new \RuntimeException(
                    $errorMessagePrefix . "Order data unavailable. Cart ID: {$quote->getId()}",
                    $e->getCode(),
                    $e
                );
            }

            // Abort if the order object is not relevant for transaction.
            if ($order->getQuoteId() !== $quote->getId()) {
                throw new \RuntimeException(
                    $errorMessagePrefix . "Available order data ({$order->getIncrementId()}, {$order->getId()}) doesn't match the cart: {$quote->getId()}",
                    $e->getCode(),
                    $e
                );
            }

            // Cancel the order in case when it was saved.
            if ($order->getId()) {
                $this->checkoutSessionManagement->revertOrder($amazonSessionId, $order, $quote, $e);

                throw $e;
            }

            /** @var \Magento\Sales\Model\Order\Payment|null */
            $orderPayment = $order->getPayment();

            // Abort if the order lacks payment information.
            if (!$orderPayment) {
                throw $e;
            }

            $methodInstance = $orderPayment->getMethodInstance();
            $methodInstance->setStore($order->getStoreId());

            $transaction = $orderPayment->getCreatedTransaction();

            $errorMessage = "";

            if (!$methodInstance->canRefund()) {
                $errorMessage = "Transaction can not be refunded.";
            }

            if (!$transaction) {
                $errorMessage = "Transaction information is missing.";
            }

            if ($errorMessage) {
                throw new \RuntimeException(
                    $errorMessagePrefix . $errorMessage,
                    $e->getCode(),
                    $e
                );
            }

            // Close charge permission if payment was not yet captured.
            if ($transaction->getTxnType() === TransactionInterface::TYPE_AUTH) {
                $this->checkoutSessionManagement->closeChargePermission($amazonSessionId, $order, $e);

                throw $e;
            }

            // Refund the captured payment.
            if ($transaction->getTxnType() === TransactionInterface::TYPE_CAPTURE) {
                $this->checkoutSessionManagement->refundCharge($amazonSessionId, $order);

                throw $e;
            }

            throw new \RuntimeException(
                $errorMessagePrefix . ' Unsupported transaction type: ' . $transaction->getTxnType(),
                $e->getCode(),
                $e
            );
        } finally {
            $this->placedOrderHolder->clear();
        }
    }
}
