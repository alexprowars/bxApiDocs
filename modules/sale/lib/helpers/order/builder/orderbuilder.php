<?
namespace Bitrix\Sale\Helpers\Order\Builder;

use Bitrix\Crm\Order\Shipment;
use Bitrix\Main\ArgumentNullException;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ObjectException;
use Bitrix\Sale\Order;
use Bitrix\Sale\Helpers\Admin\Blocks\OrderBuyer;
use Bitrix\Sale\PaySystem\Manager;
use Bitrix\Sale\Services\PaySystem;
use Bitrix\Main\Type\Date;
use Bitrix\Main\Type\DateTime;
use Bitrix\Sale\Registry;
use \Bitrix\Sale\Delivery;
use Bitrix\Sale\Result;
use Bitrix\Sale\Configuration;

/**
 * Class OrderBuilder
 * @package Bitrix\Sale\Helpers\Order\Builder
 * @internal
 */
abstract class OrderBuilder
{
	/** @var OrderBuilderExist|OrderBuilderNew */
	protected $delegate = null;
	/** @var BasketBuilder  */
	protected $basketBuilder = null;

	/** @var SettingsContainer */
	protected $settingsContainer = null;

	/** @var Order  */
	protected $order = null;
	/** @var array */
	protected $formData = array();
	/** @var ErrorsContainer  */
	protected $errorsContainer = null;

	/** @var bool */
	protected $isStartField;

	/** @var Registry */
	protected $registry = null;

	public function __construct(SettingsContainer $settings)
	{
		$this->settingsContainer = $settings;
		$this->errorsContainer = new ErrorsContainer();
		$this->errorsContainer->setAcceptableErrorCodes(
			$this->settingsContainer->getItemValue('acceptableErrorCodes')
		);

		$this->registry = Registry::getInstance(Registry::REGISTRY_TYPE_ORDER);
	}

	public function build($data)
	{
		$this->createOrder($data)
			->setDiscounts() //?
			->setFields()
			->setProperties()
			->setUser()
			->buildBasket()
			->buildPayments()
			->buildShipments()
			->setDiscounts() //?
			->finalActions();
	}

	public function setBasketBuilder(BasketBuilder $basketBuilder)
	{
		$this->basketBuilder = $basketBuilder;
	}

	public function getRegistry()
	{
		return $this->registry;
	}

	public function createOrder(array $data)
	{
		$data["ID"] = (isset($data["ID"]) ? (int)$data["ID"] : 0);
		$this->formData = $data;
		$this->delegate = (int)$data['ID'] > 0 ? new OrderBuilderExist($this) : new OrderBuilderNew($this);

		if($this->order = $this->delegate->createOrder($data))
		{
			$this->isStartField = $this->order->isStartField();
		}

		return $this;
	}

	public function setFields()
	{
		$fields = ['RESPONSIBLE_ID', 'USER_DESCRIPTION', 'ORDER_TOPIC', 'ACCOUNT_NUMBER'];

		foreach($fields as $field)
		{
			if(isset($this->formData[$field]))
			{
				$r = $this->order->setField($field, $this->formData[$field]);

				if(!$r->isSuccess())
				{
					$this->getErrorsContainer()->addErrors($r->getErrors());
				}
			}
		}

		if(isset($this->formData["PERSON_TYPE_ID"]) && intval($this->formData["PERSON_TYPE_ID"]) > 0)
		{
			/** @var \Bitrix\Sale\Result $r */
			$r = $this->order->setPersonTypeId(intval($this->formData['PERSON_TYPE_ID']));
		}
		else
		{
			/** @var \Bitrix\Sale\Result $r */
			$r = $this->order->setPersonTypeId(
				OrderBuyer::getDefaultPersonType(
					$this->order->getSiteId()
				)
			);
		}

		if(!$r->isSuccess())
		{
			$this->getErrorsContainer()->addErrors($r->getErrors());
		}

		return $this;
	}

	public function setProperties()
	{
		if(!$this->formData["PROPERTIES"])
		{
			return $this;
		}

		$propCollection = $this->order->getPropertyCollection();
		$res = $propCollection->setValuesFromPost(
			$this->formData,
			$this->settingsContainer->getItemValue('propsFiles')
		);

		if(!$res->isSuccess())
		{
			$this->getErrorsContainer()->addErrors($res->getErrors());
		}

		return $this;
	}

	public function setUser()
	{
		$this->delegate->setUser();
		return $this;
	}

	public function setDiscounts()
	{
		if(isset($this->formData["DISCOUNTS"]) && is_array($this->formData["DISCOUNTS"]))
		{
			$this->order->getDiscount()->setApplyResult($this->formData["DISCOUNTS"]);

			$r = $this->order->getDiscount()->calculate();

			if($r->isSuccess())
			{
				$discountData = $r->getData();
				$this->order->applyDiscount($discountData);
			}
		}

		return $this;
	}

	public function buildBasket()
	{
		$this->delegate->buildBasket();
		return $this;
	}

	public function buildShipments()
	{
		if(!isset($this->formData["SHIPMENT"]) || !is_array($this->formData["SHIPMENT"]))
		{
			$shipments = $this->order->getShipmentCollection();
			$shipments->createItem();

			return $this;
		}

		global $USER;
		$basketResult = null;
		$shipmentCollection = $this->order->getShipmentCollection();

		foreach($this->formData["SHIPMENT"] as $item)
		{
			$shipmentId = intval($item['ID']);
			$isNew = ($shipmentId <= 0);
			$deliveryService = null;

			if($isNew)
			{
				$shipment = $shipmentCollection->createItem();
			}
			else
			{
				$shipment = $shipmentCollection->getItemById($shipmentId);

				if(!$shipment)
				{
					$this->errorsContainer->addError(new Error(Loc::getMessage("SALE_HLP_ORDERBUILDER_SHIPMENT_NOT_FOUND")));
					continue;
				}
			}

			$defaultFields = $shipment->getFieldValues();

			/** @var \Bitrix\Sale\BasketItem $product */
			$systemShipment = $shipmentCollection->getSystemShipment();
			$systemShipmentItemCollection = $systemShipment->getShipmentItemCollection();
			//We suggest that if  products is null - ShipmentBasket not loaded yet, if array ShipmentBasket loaded, but empty.
			$products = null;

			if(
				!isset($item['PRODUCT'])
				&& $shipment->getId() <= 0
			)
			{
				$products = array();
				$basket = $this->order->getBasket();
				
				if($basket)
				{
					$basketItems = $basket->getBasketItems();
					foreach($basketItems as $product)
					{
						$systemShipmentItem = $systemShipmentItemCollection->getItemByBasketCode($product->getBasketCode());
						
						if($product->isBundleChild() || !$systemShipmentItem || $systemShipmentItem->getQuantity() <= 0)
							continue;

						$products[] = array(
							'AMOUNT' => $systemShipmentItem->getQuantity(),
							'BASKET_CODE' => $product->getBasketCode()
						);
					}
				}
			}
			elseif(is_array($item['PRODUCT']))
			{
				$products = $item['PRODUCT'];
			}

			if($item['DEDUCTED'] == 'Y' && $products !== null)
			{
				$basketResult = $this->buildShipmentBasket($shipment, $products);

				if(!$basketResult->isSuccess())
				{
					$this->errorsContainer->addErrors($basketResult->getErrors());
				}
			}

			$extraServices = ($item['EXTRA_SERVICES']) ? $item['EXTRA_SERVICES'] : array();

			$shipmentFields = array(
				'COMPANY_ID' => (isset($item['COMPANY_ID']) && intval($item['COMPANY_ID']) > 0) ? intval($item['COMPANY_ID']) : 0,
				'DEDUCTED' => $item['DEDUCTED'],
				'DELIVERY_DOC_NUM' => $item['DELIVERY_DOC_NUM'],
				'TRACKING_NUMBER' => $item['TRACKING_NUMBER'],
				'CURRENCY' => $this->order->getCurrency(),
				'COMMENTS' => $item['COMMENTS'],
				'ACCOUNT_NUMBER' => $item['ACCOUNT_NUMBER']
			);

			if($isNew)
			{
				$deliveryStatusClassName = $this->registry->getDeliveryStatusClassName();
				$shipmentFields['STATUS_ID'] = $deliveryStatusClassName::getInitialStatus();
			}
			elseif (isset($item['STATUS_ID']) && $item['STATUS_ID'] !== $defaultFields['STATUS_ID'])
			{
				$shipmentFields['STATUS_ID'] = $item['STATUS_ID'];
			}

			if(empty($item['COMPANY_ID']))
			{
				$shipmentFields['COMPANY_ID'] = $this->order->getField('COMPANY_ID');
			}
			if(empty($item['RESPONSIBLE_ID']))
			{
				$shipmentFields['RESPONSIBLE_ID'] = $this->order->getField('RESPONSIBLE_ID');
				$shipmentFields['EMP_RESPONSIBLE_ID'] = $USER->GetID();
				$shipmentFields['DATE_RESPONSIBLE_ID'] = new DateTime();
			}

			if($item['DELIVERY_DOC_DATE'])
			{
				try
				{
					$shipmentFields['DELIVERY_DOC_DATE'] = new Date($item['DELIVERY_DOC_DATE']);
				}
				catch (ObjectException $exception)
				{
					$this->errorsContainer->addError(new Error(Loc::getMessage("SALE_HLP_ORDERBUILDER_DATE_FORMAT_ERROR")));
				}
			}

			$shipmentFields['DELIVERY_ID'] = ((int)$item['PROFILE_ID'] > 0) ? (int)$item['PROFILE_ID'] : (int)$item['DELIVERY_ID'];

			try
			{
				if($deliveryService = Delivery\Services\Manager::getObjectById($shipmentFields['DELIVERY_ID']))
				{
					if($deliveryService->isProfile())
					{
						$shipmentFields['DELIVERY_NAME'] = $deliveryService->getNameWithParent();
					}
					else
					{
						$shipmentFields['DELIVERY_NAME'] = $deliveryService->getName();
					}
				}
			}
			catch (ArgumentNullException $e)
			{
				$this->errorsContainer->addError(new Error(Loc::getMessage('SALE_HLP_ORDERBUILDER_DELIVERY_NOT_FOUND'), 'OB_DELIVERY_NOT_FOUND'));
				return $this;
			}

			$responsibleId = $shipment->getField('RESPONSIBLE_ID');

			if($item['RESPONSIBLE_ID'] != $responsibleId || empty($responsibleId))
			{
				if(isset($item['RESPONSIBLE_ID']))
				{
					$shipmentFields['RESPONSIBLE_ID'] = $item['RESPONSIBLE_ID'];
				}
				else
				{
					$shipmentFields['RESPONSIBLE_ID'] = $this->order->getField('RESPONSIBLE_ID');
				}

				if(!empty($shipmentFields['RESPONSIBLE_ID']))
				{
					$shipmentFields['EMP_RESPONSIBLE_ID'] = $USER->getID();
					$shipmentFields['DATE_RESPONSIBLE_ID'] = new DateTime();
				}
			}

			if($extraServices)
			{
				$shipment->setExtraServices($extraServices);
			}

			$setFieldsResult = $shipment->setFields($shipmentFields);

			if(!$setFieldsResult->isSuccess())
			{
				$this->errorsContainer->addErrors($setFieldsResult->getErrors());
			}

			$shipment->setStoreId($item['DELIVERY_STORE_ID']);

			if($item['DEDUCTED'] == 'N' && $products !== null)
			{
				$basketResult = $this->buildShipmentBasket($shipment, $products);

				if(!$basketResult->isSuccess())
				{
					$this->errorsContainer->addErrors($basketResult->getErrors());
				}
			}

			$fields = array(
				'CUSTOM_PRICE_DELIVERY' => $item['CUSTOM_PRICE_DELIVERY'] === 'Y' ? 'Y' : 'N',
				'ALLOW_DELIVERY' => $item['ALLOW_DELIVERY'],
				'PRICE_DELIVERY' => (float)str_replace(',', '.', $item['PRICE_DELIVERY'])
			);

			if(isset($item['BASE_PRICE_DELIVERY']))
			{
				$fields['BASE_PRICE_DELIVERY'] = (float)str_replace(',', '.', $item['BASE_PRICE_DELIVERY']);
			}
			$shipment = $this->delegate->setShipmentPriceFields($shipment, $fields);

			if($deliveryService && !empty($item['ADDITIONAL']))
			{
				$modifiedShipment = $deliveryService->processAdditionalInfoShipmentEdit($shipment, $item['ADDITIONAL']);

				if($modifiedShipment && get_class($modifiedShipment) == Registry::ENTITY_SHIPMENT)
				{
					$shipment = $modifiedShipment;
				}
			}
		}

		return $this;
	}

	/**
	 * @param Shipment $shipment
	 * @param array $shipmentBasket
	 * @return Result
	 * @throws ArgumentNullException
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\ArgumentOutOfRangeException
	 * @throws \Bitrix\Main\NotSupportedException
	 * @throws \Bitrix\Main\ObjectNotFoundException
	 * @throws \Bitrix\Main\SystemException
	 * @throws \Exception
	 */
	public function buildShipmentBasket(&$shipment, $shipmentBasket)
	{
		/**@var \Bitrix\Sale\Shipment $shipment */
		$result = new Result();
		$shippingItems = array();
		$idsFromForm = array();
		$basket = $this->order->getBasket();
		$shipmentItemCollection = $shipment->getShipmentItemCollection();
		$useStoreControl = Configuration::useStoreControl();

		if(is_array($shipmentBasket))
		{
			// PREPARE DATA FOR SET_FIELDS
			foreach ($shipmentBasket as $items)
			{
				$items['QUANTITY'] = floatval(str_replace(',', '.', $items['QUANTITY']));
				$items['AMOUNT'] = floatval(str_replace(',', '.', $items['AMOUNT']));
				if (isset($items['BASKET_ID']) && $items['BASKET_ID'] > 0)
				{
					if (!$basketItem = $basket->getItemById($items['BASKET_ID']))
					{
						$result->addError( new Error(
								Loc::getMessage('SALE_ORDER_SHIPMENT_BASKET_BASKET_ITEM_NOT_FOUND',  array(
									'#BASKET_ITEM_ID#' => $items['BASKET_ID'],
								)),
								'PROVIDER_UNRESERVED_SHIPMENT_ITEM_WRONG_BASKET_ITEM')
						);
						return $result;
					}
					/** @var \Bitrix\Sale\BasketItem $basketItem */
					$basketCode = $basketItem->getBasketCode();
				}
				else
				{
					$basketCode = $items['BASKET_CODE'];
					if(!$basketItem = $basket->getItemByBasketCode($basketCode))
					{
						$result->addError( new Error(
								Loc::getMessage('SALE_ORDER_SHIPMENT_BASKET_BASKET_ITEM_NOT_FOUND',  array(
									'#BASKET_ITEM_ID#' => $items['BASKET_ID'],
								)),
								'PROVIDER_UNRESERVED_SHIPMENT_ITEM_WRONG_BASKET_ITEM')
						);
						return $result;
					}
				}

				$tmp = array(
					'BASKET_CODE' => $basketCode,
					'AMOUNT' => $items['AMOUNT'],
					'ORDER_DELIVERY_BASKET_ID' => $items['ORDER_DELIVERY_BASKET_ID']
				);
				$idsFromForm[$basketCode] = array();

				if ($items['BARCODE_INFO'] && $useStoreControl)
				{
					foreach ($items['BARCODE_INFO'] as $item)
					{
						if ($basketItem->isBundleParent())
						{
							$shippingItems[] = $tmp;
							continue;
						}

						$tmp['BARCODE'] = array(
							'ORDER_DELIVERY_BASKET_ID' => $items['ORDER_DELIVERY_BASKET_ID'],
							'STORE_ID' => $item['STORE_ID'],
							'QUANTITY' => ($basketItem->isBarcodeMulti()) ? 1 : $item['QUANTITY']
						);

						$barcodeCount = 0;
						if ($item['BARCODE'])
						{
							foreach ($item['BARCODE'] as $barcode)
							{
								$idsFromForm[$basketCode]['BARCODE_IDS'][$barcode['ID']] = true;
								if ($barcode['ID'] > 0)
									$tmp['BARCODE']['ID'] = $barcode['ID'];
								else
									unset($tmp['BARCODE']['ID']);
								$tmp['BARCODE']['BARCODE'] = $barcode['VALUE'];
								$shippingItems[] = $tmp;
								$barcodeCount++;
							}
						}
						elseif (!$basketItem->isBarcodeMulti())
						{
							$shippingItems[] = $tmp;
							continue;
						}


						if ($basketItem->isBarcodeMulti())
						{
							while ($barcodeCount < $item['QUANTITY'])
							{
								unset($tmp['BARCODE']['ID']);
								$tmp['BARCODE']['BARCODE'] = '';
								$shippingItems[] = $tmp;
								$barcodeCount++;
							}
						}
					}
				}
				else
				{
					$shippingItems[] = $tmp;
				}
			}
		}


		// DELETE FROM COLLECTION
		/** @var \Bitrix\Sale\ShipmentItem $shipmentItem */
		foreach ($shipmentItemCollection as $shipmentItem)
		{
			if (!array_key_exists($shipmentItem->getBasketCode(), $idsFromForm))
			{
				/** @var Result $r */
				$r = $shipmentItem->delete();
				if (!$r->isSuccess())
					$result->addErrors($r->getErrors());
			}

			$shipmentItemStoreCollection = $shipmentItem->getShipmentItemStoreCollection();

			/** @var \Bitrix\Sale\ShipmentItemStore $shipmentItemStore */
			foreach ($shipmentItemStoreCollection as $shipmentItemStore)
			{
				$shipmentItemId = $shipmentItemStore->getId();
				if (!isset($idsFromForm[$shipmentItem->getBasketCode()]['BARCODE_IDS'][$shipmentItemId]))
				{
					$delResult = $shipmentItemStore->delete();
					if (!$delResult->isSuccess())
						$result->addErrors($delResult->getErrors());
				}
			}
		}

		$isStartField = $shipmentItemCollection->isStartField();

		// SET DATA
		foreach ($shippingItems as $shippingItem)
		{
			if ((int)$shippingItem['ORDER_DELIVERY_BASKET_ID'] <= 0)
			{
				$basketCode = $shippingItem['BASKET_CODE'];
				/** @var \Bitrix\Sale\Order $this->order */
				$basketItem = $this->order->getBasket()->getItemByBasketCode($basketCode);

				/** @var \Bitrix\Sale\BasketItem $basketItem */
				$shipmentItem = $shipmentItemCollection->createItem($basketItem);

				if ($shipmentItem === null)
				{
					$result->addError(
						new Error(
							Loc::getMessage('SALE_ORDER_SHIPMENT_BASKET_ERROR_ALREADY_SHIPPED')
						)
					);
					return $result;
				}

				unset($shippingItem['BARCODE']['ORDER_DELIVERY_BASKET_ID']);
			}
			else
			{
				$shipmentItem = $shipmentItemCollection->getItemById($shippingItem['ORDER_DELIVERY_BASKET_ID']);

				if($shipmentItem)
				{
					$basketItem = $shipmentItem->getBasketItem();
				}
				else //It's a possible case when we are creating new shipment.
				{
					/** @var \Bitrix\Crm\Order\Shipment $systemShipment */
					$systemShipment = $shipment->getCollection()->getSystemShipment();
					/** @var \Bitrix\Crm\Order\ShipmentItemCollection $systemShipmentItemCollection */
					$systemShipmentItemCollection = $systemShipment->getShipmentItemCollection();

					$shipmentItem = $systemShipmentItemCollection->getItemById($shippingItem['ORDER_DELIVERY_BASKET_ID']);

					if($shipmentItem)
					{
						$basketItem = $shipmentItem->getBasketItem();
						$shipmentItem = $shipmentItemCollection->createItem($basketItem);
						$shipmentItem->setField('QUANTITY', $shipmentItem->getField('QUANTITY'));
					}
					else
					{
						$result->addError(
							new Error(
								Loc::getMessage('SALE_HLP_ORDERBUILDER_SHIPMENT_ITEM_ERROR',[
									'#ID#' => $shippingItem['ORDER_DELIVERY_BASKET_ID']
								])
							)
						);

						continue;
					}
				}
			}

			if ($shippingItem['AMOUNT'] <= 0)
			{
				$result->addError(
					new Error(
						Loc::getMessage('SALE_ORDER_SHIPMENT_BASKET_ERROR_QUANTITY', array('#BASKET_ITEM#' => $basketItem->getField('NAME'))),
						'BASKET_ITEM_'.$basketItem->getBasketCode()
					)
				);
				continue;
			}



			if ($shipmentItem->getQuantity() < $shippingItem['AMOUNT'])
			{
				$this->order->setMathActionOnly(true);
				$setFieldResult = $shipmentItem->setField('QUANTITY', $shippingItem['AMOUNT']);
				$this->order->setMathActionOnly(false);

				if (!$setFieldResult->isSuccess())
					$result->addErrors($setFieldResult->getErrors());
			}

			if (!empty($shippingItem['BARCODE']) && $useStoreControl)
			{
				$barcode = $shippingItem['BARCODE'];

				/** @var \Bitrix\Sale\ShipmentItemStoreCollection $shipmentItemStoreCollection */
				$shipmentItemStoreCollection = $shipmentItem->getShipmentItemStoreCollection();
				if (!$basketItem->isBarcodeMulti())
				{
					/** @var Result $r */
					$r = $shipmentItemStoreCollection->setBarcodeQuantityFromArray($shipmentBasket[$basketItem->getId()]);
					if(!$r->isSuccess())
					{
						$result->addErrors($r->getErrors());
					}
				}

				if (isset($barcode['ID']) && intval($barcode['ID']) > 0)
				{
					/** @var \Bitrix\Sale\ShipmentItemStore $shipmentItemStore */
					if ($shipmentItemStore = $shipmentItemStoreCollection->getItemById($barcode['ID']))
					{
						unset($barcode['ID']);
						$setFieldResult = $shipmentItemStore->setFields($barcode);

						if (!$setFieldResult->isSuccess())
							$result->addErrors($setFieldResult->getErrors());
					}
				}
				else
				{
					$shipmentItemStore = $shipmentItemStoreCollection->createItem($basketItem);
					$setFieldResult = $shipmentItemStore->setFields($barcode);
					if (!$setFieldResult->isSuccess())
						$result->addErrors($setFieldResult->getErrors());
				}
			}

			$setFieldResult = $shipmentItem->setField('QUANTITY', $shippingItem['AMOUNT']);
			if (!$setFieldResult->isSuccess())
				$result->addErrors($setFieldResult->getErrors());
		}

		if ($isStartField)
		{
			$hasMeaningfulFields = $shipmentItemCollection->hasMeaningfulField();

			/** @var Result $r */
			$r = $shipmentItemCollection->doFinalAction($hasMeaningfulFields);
			if (!$r->isSuccess())
			{
				$result->addErrors($r->getErrors());
			}
		}

		return $result;
	}

	public function buildPayments()
	{
		global $USER;

		if(!is_array($this->formData["PAYMENT"]))
		{
			$payments = $this->order->getPaymentCollection();
			$payments->createItem();

			return $this;
		}

		$paymentCollection = $this->order->getPaymentCollection();

		foreach($this->formData["PAYMENT"] as $paymentData)
		{
			$paymentId = intval($paymentData['ID']);
			$isNew = ($paymentId <= 0);
			$hasError = false;

			/** @var \Bitrix\Sale\Payment $paymentItem */
			if($isNew)
			{
				$paymentItem = $paymentCollection->createItem();
			}
			else
			{
				$paymentItem = $paymentCollection->getItemById($paymentId);

				if(!$paymentItem)
				{
					$this->errorsContainer->addError(new Error('Can\'t find payment with id:"'.$paymentId.'"'));
					continue;
				}
			}

			$isReturn = (isset($paymentData['IS_RETURN']) && ($paymentData['IS_RETURN'] == 'Y' || $paymentData['IS_RETURN'] == 'P'));
			$psService = null;

			if((int)$paymentData['PAY_SYSTEM_ID'] > 0)
			{
				$psService = Manager::getObjectById((int)$paymentData['PAY_SYSTEM_ID']);
			}

			$paymentFields = array(
				'PAY_SYSTEM_ID' => $paymentData['PAY_SYSTEM_ID'],
				'COMPANY_ID' => (isset($paymentData['COMPANY_ID']) && intval($paymentData['COMPANY_ID']) > 0) ? intval($paymentData['COMPANY_ID']) : 0,
				'PAY_VOUCHER_NUM' => $paymentData['PAY_VOUCHER_NUM'],
				'PAY_RETURN_NUM' => $paymentData['PAY_RETURN_NUM'],
				'PAY_RETURN_COMMENT' => $paymentData['PAY_RETURN_COMMENT'],
				'COMMENTS' => $paymentData['COMMENTS'],
				'PAY_SYSTEM_NAME' => ($psService) ? $psService->getField('NAME') : ''
			);

			if($isNew)
			{
				if(empty($paymentData['COMPANY_ID']))
				{
					$paymentFields['COMPANY_ID'] = $this->order->getField('COMPANY_ID');
				}

				if(empty($paymentData['RESPONSIBLE_ID']))
				{
					$paymentFields['RESPONSIBLE_ID'] = $this->order->getField('RESPONSIBLE_ID');
					$paymentFields['EMP_RESPONSIBLE_ID'] = $USER->GetID();
					$paymentFields['DATE_RESPONSIBLE_ID'] = new DateTime();
				}
			}

			if($paymentItem->isPaid()
				&& isset($paymentFields['SUM'])
				&& abs(floatval($paymentFields['SUM']) - floatval($paymentItem->getSum())) > 0.001)
			{
				$this->errorsContainer->addError(new Error(Loc::getMessage("SALE_HLP_ORDERBUILDER_ERROR_PAYMENT_SUM")));
				$hasError = true;
			}

			/*
			 * We are editing an order. We have only one payment. So the payment fields are mostly in view mode.
			 * If we have changed the price of the order then the sum of the payment must be changed automaticaly by payment api earlier.
			 * But if the payment sum was received from the form we will erase the previous changes.
			 */
			if(isset($paymentData['SUM']))
			{
				$paymentFields['SUM'] = (float)str_replace(',', '.', $paymentData['SUM']);
			}

			if($paymentData['PRICE_COD'])
			{
				$paymentFields['PRICE_COD'] = $paymentData['PRICE_COD'];
			}

			if($isNew)
			{
				$paymentFields['DATE_BILL'] = new DateTime();
			}

			if(!empty($paymentData['PAY_RETURN_DATE']))
			{
				try
				{
					$paymentFields['PAY_RETURN_DATE'] = new \Bitrix\Main\Type\Date($paymentData['PAY_RETURN_DATE']);
				}
				catch (ObjectException $exception)
				{
					$this->errorsContainer->addError(new Error(Loc::getMessage("SALE_HLP_ORDERBUILDER_DATE_FORMAT_RES_ERROR")));
					$hasError = true;
				}
			}

			if(!empty($paymentData['PAY_VOUCHER_DATE']))
			{
				try
				{
					$paymentFields['PAY_VOUCHER_DATE'] = new Date($paymentData['PAY_VOUCHER_DATE']);
				}
				catch (ObjectException $exception)
				{
					$this->errorsContainer->addError(new Error(Loc::getMessage("SALE_HLP_ORDERBUILDER_DATE_FORMAT_VOU_ERROR")));
					$hasError = true;
				}
			}

			if(isset($paymentData['RESPONSIBLE_ID']))
			{
				$paymentFields['RESPONSIBLE_ID'] = !empty($paymentData['RESPONSIBLE_ID']) ? $paymentData['RESPONSIBLE_ID'] : $USER->GetID();

				if($paymentData['RESPONSIBLE_ID'] != $paymentItem->getField('RESPONSIBLE_ID'))
				{
					$paymentFields['DATE_RESPONSIBLE_ID'] = new DateTime();

					if(!$isNew)
					{
						$paymentFields['EMP_RESPONSIBLE_ID'] = $USER->GetID();
					}
				}
			}

			if(!$hasError)
			{
				if($paymentItem->isInner() && isset($paymentFields['SUM']) && $paymentFields['SUM'] === 0)
				{
					unset($paymentFields['SUM']);
				}

				$setResult = $paymentItem->setFields($paymentFields);

				if(!$setResult->isSuccess())
				{
					$this->errorsContainer->addErrors($setResult->getErrors());
				}

				if($isReturn && $paymentData['IS_RETURN'])
				{
					$setResult = $paymentItem->setReturn($paymentData['IS_RETURN']);

					if(!$setResult->isSuccess())
					{
						$this->errorsContainer->addErrors($setResult->getErrors());
					}
				}

				if(!empty($paymentData['PAID']))
				{
					$setResult = $paymentItem->setPaid($paymentData['PAID']);
				}

				if(!$setResult->isSuccess())
				{
					$this->errorsContainer->addErrors($setResult->getErrors());
				}
			}
		}

		return $this;
	}

	public function finalActions()
	{
		if($this->isStartField)
		{
			$hasMeaningfulFields = $this->order->hasMeaningfulField();
			$r = $this->order->doFinalAction($hasMeaningfulFields);

			if(!$r->isSuccess())
			{
				$this->errorsContainer->addErrors($r->getErrors());
			}
		}

		return $this;
	}

	public function getOrder()
	{
		return $this->order;
	}

	public function getSettingsContainer()
	{
		return $this->settingsContainer;
	}

	public function getErrorsContainer()
	{
		return $this->errorsContainer;
	}

	public function getFormData($fieldName = '')
	{
		if(strlen($fieldName) > 0)
		{
			$result = $this->formData[$fieldName];
		}
		else
		{
			$result = $this->formData;
		}

		return $result;
	}

	public function getBasketBuilder()
	{
		return $this->basketBuilder;
	}

	public static function getDefaultPersonType($siteId)
	{
		$personTypes = self::getBuyerTypesList($siteId);
		reset($personTypes);
		return key($personTypes);
	}

	public static function getBuyerTypesList($siteId)
	{
		static $result = array();

		if(!isset($result[$siteId]))
		{
			$result[$siteId] = array();

			$res = \Bitrix\Sale\Internals\PersonTypeTable::getList(array(
				'order' => array('SORT' => 'ASC', 'NAME' => 'ASC'),
				'filter' => array('=ACTIVE' => 'Y', '=PERSON_TYPE_SITE.SITE_ID' => $siteId)
			));

			while ($personType = $res->fetch())
			{
				$result[$siteId][$personType["ID"]] = htmlspecialcharsbx($personType["NAME"])." [".$personType["ID"]."]";
			}
		}

		return $result[$siteId];
	}

	public function getUserId()
	{
		if(intval($this->formData["USER_ID"]) > 0)
			return intval($this->formData["USER_ID"]);

		$userId = 0;

		if (!isset($this->formData["USER_ID"]) || (int)($this->formData["USER_ID"]) <= 0)
		{
			$settingValue = (int)$this->getSettingsContainer()->getItemValue('createUserIfNeed');

			if ($settingValue === \Bitrix\Sale\Helpers\Order\Builder\SettingsContainer::SET_ANONYMOUS_USER)
			{
				$userId = \CSaleUser::GetAnonymousUserID();
			}
			elseif ($settingValue === \Bitrix\Sale\Helpers\Order\Builder\SettingsContainer::ALLOW_NEW_USER_CREATION)
			{
				$userId = $this->createUserFromFormData();
			}
		}

		return $userId;
	}

	protected function createUserFromFormData()
	{
		$errors = array();
		$orderProps = $this->order->getPropertyCollection();

		if($email = $orderProps->getUserEmail())
			$email = $email->getValue();

		if($name = $orderProps->getPayerName())
			$name = $name->getValue();

		if($phone = $orderProps->getPhone())
			$phone = $phone->getValue();

		$userId = \CSaleUser::DoAutoRegisterUser(
			$email,
			$name,
			$this->formData["SITE_ID"],
			$errors,
			array('PERSONAL_PHONE' => $phone)
		);

		if(!empty($errors))
		{
			foreach($errors as $val)
			{
				$this->errorsContainer->addError(new Error($val["TEXT"]));
			}
		}

		return $userId;
	}
}