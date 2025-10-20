<?php

declare(strict_types=1);

namespace Amazon\Pay\Plugin;

use Amazon\Pay\Api\CheckoutSessionManagementInterface;
use Amazon\Pay\Gateway\Config\Config;
use Amazon\Pay\Model\Adapter\AmazonPayAdapter;
use Amazon\Pay\Service\PlacedOrderHolder;
use Closure;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\Data\PaymentInterface;
use Magento\Sales\Api\CreditmemoRepositoryInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order\Creditmemo;
use Magento\Sales\Model\Order\CreditmemoFactory;
use Magento\Sales\Model\Order\RefundAdapterInterface;

class OrderCancellation
{
    public function __construct(
        private CheckoutSessionManagementInterface $checkoutSessionManagement,
        private CartRepositoryInterface $quoteRepository,
        private PlacedOrderHolder $placedOrderHolder,
        private OrderRepositoryInterface $orderRepository,
        private AmazonPayAdapter $amazonPayAdapter,
        private CreditmemoFactory $creditmemoFactory,
        private CreditmemoRepositoryInterface $creditmemoRepository,
        private RefundAdapterInterface $refundAdapter,
    ) {
    }

    public function aroundPlaceOrder(
        CartManagementInterface $subject,
        Closure $proceed,
        int $cartId,
        PaymentInterface $payment = null
    ): int {
        try {
            return $proceed($cartId, $payment);
        } catch (\Throwable $e) {
            $quote = $this->quoteRepository->get((int) $cartId);

            $payment = $quote->getPayment();

            // Abort if the payment method is not relevant.
            if ($payment->getMethod() !== Config::CODE) {
                throw $e;
            }

            $errorMessagePrefix = 'Unable to cancel payment: ';

            /** @var \Magento\Sales\Model\Order|null */
            $order = $this->placedOrderHolder->retrieve();

            // Abort if the order object is not available
            if (!$order) {
                throw new \RuntimeException(
                    $errorMessagePrefix . "Order data unavailable. Reserved order ID: {$quote->getReservedOrderId()}",
                    $e->getCode(),
                    $e
                );
            }

            // Abort if the order object is not relevant for transaction.
            if ($order->getIncrementId() !== $quote->getReservedOrderId()) {
                throw new \RuntimeException(
                    $errorMessagePrefix . "Available order data ({$order->getIncrementId()}, {$order->getId()}) doesn't match the quote value: {$quote->getReservedOrderId()}",
                    $e->getCode(),
                    $e
                );
            }

            /** @var \Magento\Sales\Model\Order\Payment|null */
            $orderPayment = $order->getPayment();

            // Abort if the order lacks payment information.
            if (!$orderPayment) {
                throw $e;
            }

            // Cancel the order in case when it was saved.
            if ($order->getId()) {
                if ($order->canCancel()) {
                    $order->cancel();
                } elseif ($orderPayment->getMethodInstance()->canRefund()) {
                    $creditmemo = $this->creditmemoFactory->createByOrder($order);
                    $invoice = $order->getInvoiceCollection()->getFirstItem();

                    $creditmemo->setInvoice($invoice);
                    $creditmemo->setState(Creditmemo::STATE_REFUNDED);

                    $orderPayment->setCreatedInvoice($invoice)
                        ->setCreditmemo($creditmemo)
                        ->setParentTransactionId($orderPayment->getCreatedTransaction()->getTxnId());

                    $this->refundAdapter->refund($creditmemo, $order, true);

                    $this->creditmemoRepository->save($creditmemo);
                    $this->orderRepository->save($order);
                }

                throw $e;
            }

            // Abort if there is no transaction additional info.
            if (!$orderPayment->getTransactionAdditionalInfo()) {
                throw $e;
            }

            $methodInstance = $orderPayment->getMethodInstance();
            $methodInstance->setStore($order->getStoreId());

            $isPaymentCanceled = false;
            $errorMessage = "";

            $transactionState = $orderPayment->getTransactionAdditionalInfo()['state'] ?? null;

            switch ($transactionState) {
                case 'Completed':
                case 'Captured':
                    $chargeId = $orderPayment->getTransactionAdditionalInfo()['charge_id'] ?? null;

                    if (!$chargeId) {
                        $errorMessage = "Charge ID is missing.";
                        break;
                    }

                    $refundResponse = $this->amazonPayAdapter->createRefund(
                        $order->getStoreId(),
                        $chargeId,
                        $order->getGrandTotal(),
                        $order->getOrderCurrencyCode()
                    );

                    if ($refundResponse['status'] !== 201) {
                        $errorMessage = "Unable to refund Amazon Pay charge {$chargeId}. " 
                            . ($refundResponse['statusDetails']['reasonDescription'] ?? '');
                        break;
                    }

                    $isPaymentCanceled = true;
                    break;
                default:
                    $chargePermissionId = $orderPayment->getTransactionAdditionalInfo()['charge_permission_id'] ?? null;

                    if (!$chargePermissionId) {
                        $isPaymentCanceled = true;
                        break;
                    }

                    $cancelResponse = $this->amazonPayAdapter->closeChargePermission(
                        $order->getStoreId(),
                        $chargePermissionId,
                        'Unexpected order place error.',
                        true
                    );

                    if ($cancelResponse['status'] !== 200) {
                        $errorMessage = "Unable to close Amazon Pay charge permission {$chargePermissionId}. " 
                            . ($cancelResponse['statusDetails']['reasonDescription'] ?? '');
                        break;
                    }

                    break;
            }

            if ($isPaymentCanceled) {
                throw $e;
            }

            throw new \RuntimeException(
                $errorMessagePrefix . $errorMessage,
                $e->getCode(),
                $e
            );
        } finally {
            $this->placedOrderHolder->clear();
        }
    }
}
