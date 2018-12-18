<?
namespace Bitrix\Sale\Helpers\Order\Builder;

use Bitrix\Crm\Order\Payment;
use Bitrix\Crm\Order\Shipment;

final class Director
{
	public function createOrder(OrderBuilder $builder, array $data)
	{
		try{
			$builder->build($data);
		}
		catch(BuildingException $e)
		{
			return null;
		}

		return $builder->getOrder();
	}

	/**
	 * @param OrderBuilder $builder
	 * @param array $shipmentData
	 * @return Shipment
	 * @throws \Bitrix\Main\ArgumentNullException
	 */
	public function getUpdatedShipment(OrderBuilder $builder, array $shipmentData)
	{
		try{
			$builder->createOrder(
					array(
						'ID' => $shipmentData['ORDER_ID'],
						'SITE_ID' => $shipmentData['SITE_ID'],
						'SHIPMENT' => array($shipmentData)
					))
				->setDiscounts() //?
				->buildShipments()
				->setDiscounts() //?
				->finalActions();
		}
		catch(BuildingException $e)
		{
			return null;
		}

		$order = $builder->getOrder();
		$collection = $order->getShipmentCollection();

		if((int)$shipmentData['ID'] > 0)
		{
			return $collection->getItemById($shipmentData['ID']);
		}
		else
		{
			foreach($collection as $shipment)
			{
				if($shipment->getId() <= 0)
				{
					return $shipment;
				}
			}
		}

		return null;
	}

	/**
	 * @param OrderBuilder $builder
	 * @param array $shipmentData
	 * @return Payment
	 * @throws \Bitrix\Main\ArgumentNullException
	 */
	public function getUpdatedPayment(OrderBuilder $builder, array $paymentData)
	{
		try{
			$builder->createOrder(
				array(
					'ID' => $paymentData['ORDER_ID'],
					'SITE_ID' => $paymentData['SITE_ID'],
					'PAYMENT' => array($paymentData)
				))
				->setDiscounts()
				->buildPayments()
				->setDiscounts()
				->finalActions();
		}
		catch(BuildingException $e)
		{
			return null;
		}

		$order = $builder->getOrder();
		$collection = $order->getPaymentCollection();

		if((int)$paymentData['ID'] > 0)
		{
			return $collection->getItemById($paymentData['ID']);
		}
		else
		{
			foreach($collection as $payment)
			{
				if($payment->getId() <= 0)
				{
					return $payment;
				}
			}
		}

		return null;
	}
}