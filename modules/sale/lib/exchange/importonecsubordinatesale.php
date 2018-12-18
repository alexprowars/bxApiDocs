<?php
namespace Bitrix\Sale\Exchange;


use Bitrix\Sale\Exchange\Entity\SubordinateSale\EntityImportFactory;
use Bitrix\Sale\Exchange\OneC\DocumentBase;
use Bitrix\Sale\Exchange\OneC\SubordinateSale\ConverterFactory;
use Bitrix\Sale\Exchange\OneC\SubordinateSale\CriterionShipment;
use Bitrix\Sale\Exchange\OneC\SubordinateSale\DocumentFactory;
use Bitrix\Sale\Exchange\OneC\SubordinateSale\ShipmentDocument;
use Bitrix\Sale\Order;
use Bitrix\Sale\Shipment;

final class ImportOneCSubordinateSale extends ImportOneCPackage
{
	public static function configuration()
	{
		ManagerImport::registerInstance(static::getShipmentEntityTypeId(), OneC\ImportSettings::getCurrent(), new OneC\CollisionShipment(), new CriterionShipment());

		parent::configuration();
	}

	protected function convert(array $documents)
	{
		$documentOrder = $this->getDocumentByTypeId(EntityType::ORDER, $documents);

		if($documentOrder instanceof OneC\OrderDocument)
		{
			$fieldsOrder = $documentOrder->getFieldValues();
			$itemsOrder = $this->getProductsItems($fieldsOrder);

			if(is_array($fieldsOrder['SUBORDINATES']))
			{
				foreach ($fieldsOrder['SUBORDINATES'] as $subordinateDocumentFields)
				{
					$typeId = $this->resolveSubordinateDocumentTypeId($subordinateDocumentFields);

					if($typeId == static::getShipmentEntityTypeId())
					{
						$subordinateDocumentItems = array();
						$itemsSubordinate = $this->getProductsItems($subordinateDocumentFields);

						foreach ($itemsSubordinate as $itemSubordinate)
						{
							$xmlId = key($itemSubordinate);

							if($xmlId == self::DELIVERY_SERVICE_XMLID)
							{
								$itemSubordinate[$xmlId]['TYPE'] = ImportBase::ITEM_SERVICE;
								$subordinateDocumentItems[] = $itemSubordinate;
							}
							else
							{
								$item = $this->getItemByParam($xmlId, $itemsOrder);

								if($item !== null)
								{
									$item[$xmlId]['QUANTITY'] = $itemSubordinate[$xmlId]['QUANTITY'];
									$subordinateDocumentItems[] = $item;
								}
							}
						}

						unset($subordinateDocumentFields['ITEMS']);
						unset($subordinateDocumentFields['ITEMS_FIELDS']);

						if(count($subordinateDocumentItems)>0)
						{
							$subordinateDocumentFields['ITEMS'] = $subordinateDocumentItems;
						}
					}

					$document = OneC\DocumentImportFactory::create($typeId);
					$document->setFields($subordinateDocumentFields);
					$documents[] = $document;
				}
				$documentOrder->setField('SUBORDINATES', '');
			}

			//region Presset - генерируем фэйковую отгрузку
			/*
			 * генерируем фэйковую отгрузку, если выполнены условия
			 * 1 обмен с новым модулем от 1С,
			 * 2 отгрузки не переданы в подчиненных документах
			 * 3 все отгрузки по заказу в БУС в статусе не отгружено
			 * 4 и от 1С в табличной части заказа передана ORDER_DELIVERY
			 * */
			if(!$this->hasDocumentByTypeId(static::getShipmentEntityTypeId(), $documents))
			{
				if($this->deliveryServiceExists($itemsOrder))
				{
					//$deliveryItem
					$entityOrder = $this->convertDocument($documentOrder);
					if($entityOrder->getFieldValues()['TRAITS']['ID']>0)
					{
						self::load($entityOrder, ['ID'=>$entityOrder->getFieldValues()['TRAITS']['ID']]);
						/** @var Order $order */
						$order = $entityOrder->getEntity();
						if(!$order->isShipped())
						{
							$shipmentList = [];
							$shipmentIsShipped = false;
							/** @var Shipment $shipment */
							foreach ($order->getShipmentCollection() as $shipment)
							{
								if($shipment->isShipped())
								{
									$shipmentIsShipped = true;
									break;
								}

								if(!$shipment->isSystem())
								{
									$shipmentList[] = $shipment->getFieldValues();
								}
							}

							if(!$shipmentIsShipped)
							{
								if(count($shipmentList)>0)
								{
									//системная и реальная отгрузка
									$externalId = current($shipmentList)['ID_1C'];
									$shipmentFields['ID_1C'] = strlen($externalId)<=0? $documentOrder->getField('ID_1C'):$externalId;
									$shipmentFields['ID'] = current($shipmentList)['ID'];
								}
								else
								{
									//только системная отгрузка
									$shipmentFields['ID_1C'] = $documentOrder->getField('ID_1C');
								}
								// колличество и вся табличная часть всегда береться из заказа т.к. все отгрузки вводятся в 1С и на сайте вообще не может измениться что-то в отгрузке. (требования 1С)
								$shipmentFields['ITEMS'] = $itemsOrder;

								$documentShipment = new ShipmentDocument();
								$documentShipment->setFields($shipmentFields);
								$documents[] = $documentShipment;
							}
						}
					}
				}
			}
			//endregion
		}
		return parent::convert($documents);
	}

	/**
	 * @param array $fields
	 * @return int
	 */
	protected function resolveSubordinateDocumentTypeId(array $fields)
	{
		$typeId = EntityType::UNDEFINED;

		if(isset($fields['OPERATION']))
		{
			$typeId = EntityType::resolveID($fields['OPERATION']);
		}
		return $typeId;
	}

	/**
	 * @param $xmlId
	 * @param array $items
	 * @param array|null $params
	 * @return mixed|null
	 */
	protected function getItemByParam($key, array $items, array $params=null)
	{
		foreach ($items as $item)
		{
			if(array_key_exists($key, $item))
			{
				return $item;
			}
		}
		return null;
	}

	/**
	 * @param $typeId
	 * @return IConverter
	 */
	protected function converterFactoryCreate($typeId)
	{
		return ConverterFactory::create($typeId);
	}

	/**
	 * @param $typeId
	 * @return DocumentBase
	 */
	protected function documentFactoryCreate($typeId)
	{
		return DocumentFactory::create($typeId);
	}

	/**
	 * @param $typeId
	 * @return ImportBase
	 */
	protected function entityFactoryCreate($typeId)
	{
		return EntityImportFactory::create($typeId);
	}
}