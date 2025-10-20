<?php

declare(strict_types=1);

namespace Amazon\Pay\Observer;

use Amazon\Pay\Service\PlacedOrderHolder;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Api\Data\OrderInterface;

class HoldPlacedOrderObserver implements ObserverInterface
{
    public function __construct(
        private PlacedOrderHolder $placedOrderHolder
    ) {
    }

    public function execute(Observer $observer): void
    {
        /** @var OrderInterface $order */
        $order = $observer->getEvent()->getOrder();

        $this->placedOrderHolder->hold($order);
    }
}
