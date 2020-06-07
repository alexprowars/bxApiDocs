<?php
namespace Bitrix\Landing\DataLoader;

use \Bitrix\Landing\Block;
use \Bitrix\Landing\Hook;
use \Bitrix\Landing\Landing as LandingCore;
use \Bitrix\Landing\Landing\Cache;

class Landing extends \Bitrix\Landing\Source\DataLoader
{
	/**
	 * Gets all meta data for landing's ids data.
	 * @param array $ids
	 * @return array
	 */
	protected function getMetadata(array $ids)
	{
		$images = [];
		$res = \Bitrix\Landing\Internals\HookDataTable::getList([
			'select' => [
				'VALUE', 'ENTITY_ID', 'CODE'
			],
			'filter' => [
				'=HOOK' => 'METAOG',
				'=CODE' => ['IMAGE', 'DESCRIPTION'],
				'=PUBLIC' => Hook::getEditMode() ? 'N' : 'Y',
				'=ENTITY_TYPE' => Hook::ENTITY_TYPE_LANDING,
				'ENTITY_ID' => $ids
			]
		]);
		while ($row = $res->fetch())
		{
			if (!isset($images[$row['ENTITY_ID']]))
			{
				$images[$row['ENTITY_ID']] = [];
			}
			if ($row['CODE'] == 'IMAGE')
			{
				if (intval($row['VALUE']) > 0)
				{
					$row['VALUE'] = \Bitrix\Landing\File::getFilePath($row['VALUE']);
				}
			}
			$images[$row['ENTITY_ID']][$row['CODE']] = $row['VALUE'];
		}

		return $images;
	}

	/**
	 * Returns search parameter value.
	 * @return string
	 */
	protected function getSearchQuery()
	{
		static $currentRequest = null;

		if ($currentRequest === null)
		{
			$context = \Bitrix\Main\Application::getInstance()->getContext();
			$currentRequest = $context->getRequest();
		}

		return trim($currentRequest->get('q'));
	}

	/**
	 * Gets data for dynamic blocks.
	 * @return array
	 */
	public function getElementListData()
	{
		$this->seo->clear();
		$needPreviewPicture = false;
		$needPreviewText = false;
		$needLink = true;//always

		// select
		$select = $this->getPreparedSelectFields();
		if (empty($select))
		{
			return [];
		}

		// filter
		$filter = $this->getInternalFilter();
		$contextFilter = $this->getOptionsValue('context_filter');
		$cache = $this->getOptionsValue('cache');
		if (empty($filter))
		{
			$filter = [];
		}
		if (isset($contextFilter['SITE_ID']))
		{
			$filter['SITE_ID'] = $contextFilter['SITE_ID'];
		}
		if (isset($contextFilter['LANDING_ACTIVE']))
		{
			$filter['=ACTIVE'] = $contextFilter['LANDING_ACTIVE'];
		}

		// select, order
		$order = [];
		$select[] = 'ID';
		$rawOrder = $this->getOrder();
		if (isset($rawOrder['by']) && isset($rawOrder['order']))
		{
			$order[$rawOrder['by']] = $rawOrder['order'];
			if (!in_array($rawOrder['by'], $select))
			{
				$select[] = $rawOrder['by'];
			}
		}
		foreach ($select as $i => $code)
		{
			if ($code == 'IMAGE')
			{
				$needPreviewPicture = true;
				unset($select[$i]);
			}
			else if ($code == 'DESCRIPTION')
			{
				$needPreviewText = true;
				unset($select[$i]);
			}
			else if ($code == 'LINK')
			{
				$needLink = true;
				unset($select[$i]);
			}
		}

		// limit
		$limit = $this->getLimit();
		if ($limit <= 0)
		{
			$limit = 10;
		}

		// runtime (to exclude areas)
		$runtime = [];
		$runtime[] = new \Bitrix\Main\Entity\ReferenceField(
			'AREAS',
			'Bitrix\Landing\Internals\TemplateRefTable',
			[
				'=this.ID' => 'ref.LANDING_ID'
			]
		);
		$filter['==AREAS.ID'] = null;

		$query = $this->getSearchQuery();
		if ($query)
		{
			if ($cache instanceof \CPHPCache)
			{
				$cache->abortDataCache();
			}

			if (strlen($query) < 3)
			{
				return [];
			}

			// search in blocks
			$blockFilter = [];
			if (isset($filter['SITE_ID']))
			{
				$blockFilter['LANDING.SITE_ID'] = $filter['SITE_ID'];
			}
			$blocks = Block::search($query, $blockFilter);
			$landingBlocksIds = [];
			foreach ($blocks as $block)
			{
				$landingBlocksIds[] = $block['LID'];
			}

			// merge filter with search query
			$filter[] = [
				'LOGIC' => 'OR',
				'TITLE' => '%' . $query . '%',
				'*%SEARCH_CONTENT' => $query,
				'ID' => $landingBlocksIds ? $landingBlocksIds : [-1]
			];
		}

		// get data
		$result = [];
		$res = LandingCore::getList([
			'select' => $select,
			'filter' => $filter,
			'order' => $order,
			'limit' => $limit,
			'runtime' => $runtime
		]);
		while ($row = $res->fetch())
		{
			Cache::register($row['ID']);
			$result[$row['ID']] = [
				'TITLE' => $row['TITLE']
			];
		}

		// get meta data
		$metaData = [];
		if (
			$needPreviewPicture ||
			$needPreviewText
		)
		{
			$metaData = $this->getMetadata(
				array_keys($result)
			);
		}

		// and feel result data with meta data
		foreach ($result as $id => &$item)
		{
			if (
				$needPreviewPicture &&
				isset($metaData[$id]['IMAGE'])
			)
			{
				$item['IMAGE'] = [
					'src' => $metaData[$id]['IMAGE'],
					'alt' => isset($item['TITLE'])
						? $item['TITLE']
						: ''
				];
			}
			if (
				$needPreviewText &&
				isset($metaData[$id]['DESCRIPTION'])
			)
			{
				$item['DESCRIPTION'] = $metaData[$id]['DESCRIPTION'];
			}
			if ($needLink)
			{
				$item['LINK'] = '#landing' . $id;
			}
		}
		unset($item);

		return array_values($result);
	}

	/**
	 * Gets data item of dynamic blocks.
	 * @param int $element Element's key.
	 * @return array
	 */
	public function getElementData($element)
	{
		$this->seo->clear();

		$element = intval($element);
		if ($element <= 0)
		{
			return [];
		}

		// select
		$select = $this->getPreparedSelectFields();
		if (empty($select))
		{
			return [];
		}

		// filter
		$filter = $this->getInternalFilter();
		if (empty($filter))
		{
			return [];
		}
		$filter['ID'] = $element;
		$select[] = 'ID';

		// get data
		$res = LandingCore::getList([
			'select' => $select,
			'filter' => $filter,
		]);
		$row = $res->fetch();
		if (empty($row))
		{
			return [];
		}

		Cache::register($row['ID']);
		$this->seo->setTitle($row['TITLE']);

		return [$row];
	}
}