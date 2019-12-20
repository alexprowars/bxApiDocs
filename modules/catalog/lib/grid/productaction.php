<?php
namespace Bitrix\Catalog\Grid;

use Bitrix\Main,
	Bitrix\Main\Localization\Loc,
	Bitrix\Catalog;

class ProductAction
{
	const SET_FIELD = 'product_field';
	const CHANGE_PRICE = 'change_price';

	public static function updateSectionList(int $iblockId, array $sections, array $fields)
	{
		$result = new Main\Result();

		$iblockId = (int)$iblockId;
		if ($iblockId <= 0)
		{
			$result->addError(new Main\Error(
				Loc::getMessage('BX_CATALOG_PRODUCT_ACTION_ERR_BAD_IBLOCK_ID')
			));
			return $result;
		}

		$catalog = \CCatalogSku::GetInfoByIBlock($iblockId);
		if (empty($catalog) || $catalog['CATALOG_TYPE'] == \CCatalogSku::TYPE_PRODUCT)
		{
			$result->addError(new Main\Error(
				Loc::getMessage('BX_CATALOG_PRODUCT_ACTION_ERR_BAD_CATALOG')
			));
			return $result;
		}

		if (empty($fields))
		{
			$result->addError(new Main\Error(
				Loc::getMessage('BX_CATALOG_PRODUCT_ACTION_ERR_EMPTY_FIELDS')
			));
			return $result;
		}

		$blockedTypes = [];
		if (
			$catalog['CATALOG_TYPE'] == \CCatalogSku::TYPE_FULL
			&& (string)Main\Config\Option::get('catalog', 'show_catalog_tab_with_offers') !== 'Y'
		)
		{
			$blockedTypes[Catalog\ProductTable::TYPE_SKU] = true;
		}
		$setFields = [
			'WEIGHT' => true,
			'QUANTITY' => true,
			'QUANTITY_TRACE' => true,
			'CAN_BUY_ZERO' => true,
			'MEASURE' => true
		];
		$blackList = array_intersect_key($fields, $setFields);
		if (!empty($blackList))
		{
			$blockedTypes[Catalog\ProductTable::TYPE_SET] = true;
		}
		unset($blackList, $setFields);

		$sectionElements = self::getSectionProducts($iblockId, $sections);
		if (empty($sectionElements))
		{
			return $result;
		}

		foreach (array_keys($sectionElements) as $sectionId)
		{
			$elementResult = static::updateElementList(
				$iblockId,
				$sectionElements[$sectionId],
				$fields
			);
			if (!$elementResult->isSuccess())
			{

			}
		}
		unset($index);

		return $result;
	}

	public static function updateElementList(int $iblockId, array $elementIds, array $fields)
	{
		$result = new Main\Result();

		$iblockId = (int)$iblockId;
		if ($iblockId <= 0)
		{
			$result->addError(new Main\Error(
				Loc::getMessage('BX_CATALOG_PRODUCT_ACTION_ERR_BAD_IBLOCK_ID')
			));
			return $result;
		}
		Main\Type\Collection::normalizeArrayValuesByInt($elementIds, true);
		if (empty($elementIds))
		{
			$result->addError(new Main\Error(
				Loc::getMessage('BX_CATALOG_PRODUCT_ACTION_ERR_EMPTY_ELEMENTS')
			));
			return $result;
		}
		if (empty($fields))
		{
			$result->addError(new Main\Error(
				Loc::getMessage('BX_CATALOG_PRODUCT_ACTION_ERR_EMPTY_FIELDS')
			));
			return $result;
		}
		$catalog = \CCatalogSku::GetInfoByIBlock($iblockId);
		if (empty($catalog) || $catalog['CATALOG_TYPE'] == \CCatalogSku::TYPE_PRODUCT)
		{
			$result->addError(new Main\Error(
				Loc::getMessage('BX_CATALOG_PRODUCT_ACTION_ERR_BAD_CATALOG')
			));
			return $result;
		}
		$blockedTypes = [];
		if (
			$catalog['CATALOG_TYPE'] == \CCatalogSku::TYPE_FULL
			&& (string)Main\Config\Option::get('catalog', 'show_catalog_tab_with_offers') !== 'Y'
		)
		{
			$blockedTypes[Catalog\ProductTable::TYPE_SKU] = true;
		}
		$setFields = [
			'WEIGHT' => true,
			'QUANTITY' => true,
			'QUANTITY_TRACE' => true,
			'CAN_BUY_ZERO' => true,
			'MEASURE' => true
		];
		$blackList = array_intersect_key($fields, $setFields);
		if (!empty($blackList))
		{
			$blockedTypes[Catalog\ProductTable::TYPE_SET] = true;
		}
		unset($blackList, $setFields);

		$products = [];
		foreach (array_chunk($elementIds, 500) as $pageIds)
		{
			$iterator = Catalog\Model\Product::getList([
				'select' => ['ID', 'TYPE'],
				'filter' => ['@ID' => $pageIds]
			]);
			while ($row = $iterator->fetch())
			{
				$row['ID'] = (int)$row['ID'];
				$row['TYPE'] = (int)$row['TYPE'];
				$products[$row['ID']] = $row;
			}
			unset($row, $iterator);
		}
		unset($pageIds);

		$data = [
			'fields' => $fields,
			'external_fields' => [
				'IBLOCK_ID' => $iblockId
			]
		];
		$newData = $data;

		foreach ($elementIds as $id)
		{
			if (!isset($products[$id]))
			{
				$newData['fields']['ID'] = $id;
				$elementResult = Catalog\Model\Product::add($newData);
				if (!$elementResult->isSuccess())
				{
					$result->addError(new Main\Error(
						implode('; ', $elementResult->getErrorMessages()),
						$id
					));
				}
			}
			else
			{
				$type = $products[$id]['TYPE'];
				if (isset($blockedTypes[$type]))
				{
					switch ($type)
					{
						case Catalog\ProductTable::TYPE_SKU:
							$result->addError(new Main\Error(
								Loc::getMessage('BX_CATALOG_PRODUCT_ACTION_ERR_CANNOT_MODIFY_SKU'),
								$id
							));
							break;
						case Catalog\ProductTable::TYPE_SET:
							$result->addError(new Main\Error(
								Loc::getMessage('BX_CATALOG_PRODUCT_ACTION_ERR_CANNOT_MODIFY_SET'),
								$id
							));
							break;
					}
				}
				else
				{
					$elementResult = Catalog\Model\Product::update($id, $data);
					if (!$elementResult->isSuccess())
					{
						$result->addError(new Main\Error(
							implode('; ', $elementResult->getErrorMessages()),
							$id
						));
					}
				}
				unset($type);
			}
		}
		unset($elementResult, $id);
		unset($newData, $data);
		unset($blackList, $catalog);

		return $result;
	}

	public static function updateProductField(int $iblockId, int $elementId, array $fields)
	{

	}

	protected static function getSectionProducts(int $iblockId, array $sections)
	{
		global $USER;

		$result = null;

		if (!$USER->CanDoOperation('catalog_price'))
		{
			return false;
		}

		$iblockId = (int)$iblockId;
		if ($iblockId <= 0)
		{
			return $result;
		}
		Main\Type\Collection::normalizeArrayValuesByInt($sections, false);
		if (empty($sections))
		{
			return $result;
		}

		$dublicates = [];
		$result = [];
		foreach ($sections as $sectionId)
		{
			$result[$sectionId] = [];
			$elements = [];
			$iterator = \CIBlockElement::GetList(
				['ID' => 'ASC'],
				[
					'IBLOCK_ID' => $iblockId,
					'SECTION_ID' => $sectionId,
					'INCLUDE_SUBSECTIONS' => 'Y',
					'CHECK_PERMISSIONS' => 'Y',
					'MIN_PERMISSION' => 'R'
				],
				false,
				false,
				['ID']
			);
			while ($row = $iterator->fetch())
			{
				$id = (int)$row['ID'];
				if (isset($dublicates[$id]))
				{
					continue;
				}
				$dublicates[$id] = true;
				$elements[] = $id;
			}
			unset($id, $row, $iterator);

			if (!empty($elements))
			{
				$operations = \CIBlockElementRights::UserHasRightTo(
					$iblockId,
					$elements,
					'',
					\CIBlockRights::RETURN_OPERATIONS
				);
				foreach ($elements as $elementId)
				{
					if (
						isset($operations[$elementId]['element_edit'])
						&& isset($operations[$elementId]['element_edit_price'])
					)
					{
						$result[$sectionId][] = $elementId;
					}
				}
				unset($elementId);
				unset($operations);
			}
			unset($elements);

			if (empty($result[$sectionId]))
			{
				unset($result[$sectionId]);
			}
		}
		unset($sectionId);
		unset($dublicates);

		return $result;
	}
}