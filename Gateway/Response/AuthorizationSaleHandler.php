<?php
/**
 * Copyright © Amazon.com, Inc. or its affiliates. All Rights Reserved.
 *
 * Licensed under the Apache License, Version 2.0 (the "License").
 * You may not use this file except in compliance with the License.
 * A copy of the License is located at
 *
 *  http://aws.amazon.com/apache2.0
 *
 * or in the "license" file accompanying this file. This file is distributed
 * on an "AS IS" BASIS, WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either
 * express or implied. See the License for the specific language governing
 * permissions and limitations under the License.
 */

namespace Amazon\Pay\Gateway\Response;

use Amazon\Pay\Gateway\Helper\SubjectReader;
use Amazon\Pay\Model\Adapter\AmazonPayAdapter;
use Amazon\Pay\Model\AsyncManagement;
use Amazon\Pay\Model\Config\Source\AuthorizationMode;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Payment\Gateway\Response\HandlerInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;

class AuthorizationSaleHandler implements HandlerInterface
{
    /**
     * @var SubjectReader
     */
    private $subjectReader;

    /**
     * @var AsyncManagement
     */
    private $asyncManagement;

    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @var AmazonPayAdapter
     */
    private $amazonAdapter;

    /**
     * AuthorizationHandler constructor.
     * @param SubjectReader $subjectReader
     * @param AsyncManagement $asyncManagement
     * @param ScopeConfigInterface $scopeConfig
     * @param AmazonPayAdapter $amazonAdapter
     */
    public function __construct(
        SubjectReader $subjectReader,
        AsyncManagement $asyncManagement,
        ScopeConfigInterface $scopeConfig,
        AmazonPayAdapter $amazonAdapter
    ) {
        $this->subjectReader = $subjectReader;
        $this->asyncManagement = $asyncManagement;
        $this->scopeConfig = $scopeConfig;
        $this->amazonAdapter = $amazonAdapter;
    }

    /**
     * Handles response
     *
     * @param array $handlingSubject
     * @param array $response
     * @return void
     */
    public function handle(array $handlingSubject, array $response)
    {
        $paymentDO = $this->subjectReader->readPayment($handlingSubject);
        
        if ($paymentDO->getPayment() instanceof Payment) {
            /** @var Payment $payment */
            $payment = $paymentDO->getPayment();
            /** @var Order $order */
            $order = $payment->getOrder();

            $transactionId = $response['chargeId'] ?? $response['checkoutSessionId'];
            $payment->setTransactionId($transactionId);
            $payment->setIsTransactionClosed($handlingSubject['partial_capture'] ?? false);

            $this->updatePaymentTransactionAdditionalData($payment, $response);
            
            if ($this->scopeConfig->getValue('payment/amazon_payment/authorization_mode') ==
                AuthorizationMode::SYNC_THEN_ASYNC
                && !($handlingSubject['partial_capture'] ?? false)) {
                $payment->setIsTransactionPending(true);
            }

            // Subsequent charges on separate shipping will land here. Handle for CaptureInitiated in that case
            switch ($response['statusDetails']['state']) {
                case 'CaptureInitiated':
                    $payment->setIsTransactionPending(true);
                    $payment->setIsTransactionClosed(false);
                    $this->asyncManagement->queuePendingAuthorization($response['chargeId']);
                    break;
                case 'Open':
                    $amazonCompleteCheckoutResult = $this->amazonAdapter->finalizeCheckoutSession(
                        $order->getStoreId(),
                        $response['checkoutSessionId'],
                        $order->getGrandTotal(),
                        $order->getOrderCurrencyCode(),
                        $response['shippingAddress'] ?? null,
                        $response['billingAddress'] ?? null
                    );

                    $this->updatePaymentTransactionAdditionalData($payment, $amazonCompleteCheckoutResult);

                    if ($amazonCompleteCheckoutResult['status'] !== 200) {
                        throw new \RuntimeException(
                            "Unable to finalize Amazon Pay checkout session {$response['checkoutSessionId']}. " 
                            . ($amazonCompleteCheckoutResult['statusDetails']['reasonDescription'] ?? '')
                        );
                    }

                    $chargePermissionsId = $amazonCompleteCheckoutResult['chargePermissionId'] ?? null;

                    if (!$chargePermissionsId) {
                        throw new \RuntimeException(
                            "Missed charge permission ID in finalize Amazon Pay checkout session {$response['checkoutSessionId']} response."
                        );
                    }

                    $chargeId = $amazonCompleteCheckoutResult['chargeId'] ?? null;

                    if (!$chargeId) {
                        throw new \RuntimeException(
                            "Missed charge ID in finalize Amazon Pay checkout session {$response['checkoutSessionId']} response."
                        );
                    }

                    $updateChargePermissionResult = $this->amazonAdapter->updateChargePermission(
                        $order->getStoreId(),
                        $chargePermissionsId,
                        ['merchantReferenceId' => $order->getIncrementId()]
                    );

                    if ($updateChargePermissionResult['status'] !== 200) {
                        throw new \RuntimeException(
                            "Unable to update Amazon Pay charge permission {$chargePermissionsId}."
                        );
                    }

                    if ($amazonCompleteCheckoutResult['statusDetails']['state'] !== 'Completed') {
                        $captureChargeResult = $this->amazonAdapter->captureCharge(
                            $order->getStoreId(),
                            $chargeId,
                            $order->getGrandTotal(),
                            $order->getOrderCurrencyCode()
                        );

                        if ($captureChargeResult['status'] !== 200) {
                            throw new \RuntimeException(
                                "Unable to capture Amazon Pay charge {$chargeId}. " 
                                . ($captureChargeResult['statusDetails']['reasonDescription'] ?? '')
                            );
                        }
                    }

                    $amazonCharge = $this->amazonAdapter->getCharge($order->getStoreId(), $chargeId);

                    $this->updatePaymentTransactionAdditionalData($payment, $amazonCharge);
                    
                    if ($amazonCharge['statusDetails']['state'] !== 'Captured') {
                        throw new \RuntimeException(
                            "Unable to capture Amazon Pay charge {$chargeId}." 
                            . ($amazonCharge['statusDetails']['reasonDescription'] ?? '')
                        );
                    }

                    if ($invoice = $payment->getCreatedInvoice()) {
                        $invoice->setTransactionId($chargeId);
                    }

                    $payment->setTransactionId($chargeId)
                        ->setLastTransId($chargeId)
                        ->setIsTransactionClosed(true);

                    break;
                default:
                    break;
            }
        }
    }

    private function updatePaymentTransactionAdditionalData(Payment $payment, array $response): void
    {
        $payment->resetTransactionAdditionalInfo();

        if (isset($response['chargePermissionId'])) {
            $payment->setTransactionAdditionalInfo('charge_permission_id', $response['chargePermissionId']);
        }

        if (isset($response['statusDetails']['state'])) {
            $payment->setTransactionAdditionalInfo('state', $response['statusDetails']['state']);
        }
        
        if (isset($response['chargeId'])) {
            $payment->setTransactionAdditionalInfo('charge_id', $response['chargeId']);
        }
    }
}
