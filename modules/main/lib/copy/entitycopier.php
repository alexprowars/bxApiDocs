<?php
namespace Bitrix\Main\Copy;

use Bitrix\Main\Result;
use Bitrix\Main\Type\Dictionary;

class EntityCopier implements Copyable
{
	protected $implementer;
	protected $implementerName;

	/**
	 * @var Result
	 */
	protected $result;

	/**
	 * @var Copyable[]
	 */
	private $entitiesToCopy = [];

	public function __construct(CopyImplementer $implementer)
	{
		$this->implementer = $implementer;
		$this->implementerName = get_class($this->implementer);

		$this->result = new Result();
	}

	/**
	 * Adding entities to be copied later by the parent.
	 *
	 * @param Copyable $entity
	 */
	public function addEntityToCopy(Copyable $entity)
	{
		$this->entitiesToCopy[] = $entity;
	}

	/**
	 * Starts copying added entities.
	 *
	 * @param ContainerCollection $containerManager
	 */
	protected function startCopyEntities(ContainerCollection $containerManager)
	{
		$results = [];

		foreach ($this->entitiesToCopy as $entity)
		{
			$results[] = $entity->copy($containerManager);
		}

		$data = [];
		foreach ($results as $result)
		{
			$data = $data + $result->getData();
			$this->result->addErrors($result->getErrors());
		}
		if ($data)
		{
			$this->result->setData($data);
		}
	}

	/**
	 * Copies entity.
	 *
	 * @param ContainerCollection $containerCollection
	 * @return Result
	 */
	public function copy(ContainerCollection $containerCollection)
	{
		$result = [$this->implementerName => []];

		foreach ($containerCollection as $container)
		{
			$entityIdToCopy = $this->getEntityIdToCopy($container);
			if (!$entityIdToCopy)
			{
				continue;
			}

			$fields = $this->getFields($container, $entityIdToCopy);

			if (empty($fields))
			{
				$result[$this->implementerName][$entityIdToCopy] = false;
			}
			else
			{
				$dictionary = $this->createDictionary($container, $fields);

				$fields = $this->prepareFieldsToCopy($container, $fields);

				$copiedEntityId = $this->addEntity($container, $fields);
				if (!$copiedEntityId)
				{
					$result[$this->implementerName][$entityIdToCopy] = false;
					$this->result->addErrors($this->implementer->getErrors());
					continue;
				}

				$copyChildrenResult = $this->copyChildren($container, $entityIdToCopy, $copiedEntityId);
				if ($copyChildrenResult->getErrors())
				{
					$this->result->addErrors($copyChildrenResult->getErrors());
				}
				$result[$this->implementerName] = $result[$this->implementerName] + $copyChildrenResult->getData();

				$result[$this->implementerName][$entityIdToCopy] = $copiedEntityId;

				$this->setCopiedEntityId($container, $copiedEntityId);

				$this->setDictionary($container, $dictionary);
			}
		}

		$this->startCopyEntities($containerCollection);

		$result[$this->implementerName] = $result[$this->implementerName] + $this->result->getData();
		$this->result->setData($result);

		return $this->result;
	}

	/**
	 * Returns the identifier map of the parent copied entity.
	 *
	 * @return array
	 */
	public function getMapIdsCopiedEntity()
	{
		return $this->getMapIdsParentEntity(current($this->result->getData()));
	}

	private function getMapIdsParentEntity(array $data)
	{
		$mapIdsCopiedEntity = [];
		foreach ($data as $key => $values)
		{
			if ($key == $this->implementerName && is_array($values))
			{
				$mapIdsCopiedEntity = $mapIdsCopiedEntity + $this->getMapIdsParentEntity($values);
			}
			elseif (is_int($key))
			{
				$mapIdsCopiedEntity[$key] = $values;
			}
		}
		return $mapIdsCopiedEntity;
	}

	protected function getEntityIdToCopy(Container $container)
	{
		return $container->getEntityId();
	}

	protected function getFields(Container $container, $entityId)
	{
		return $this->implementer->getFields($container, $entityId);
	}

	protected function createDictionary(Container $container, array $fields)
	{
		return new Dictionary();
	}

	protected function prepareFieldsToCopy(Container $container, $fields)
	{
		$fields = $this->implementer->prepareFieldsToCopy($container, $fields);

		return $fields;
	}

	protected function addEntity(Container $container, $fields)
	{
		return $this->implementer->add($container, $fields);
	}

	protected function copyChildren(Container $container, $entityIdToCopy, $copiedEntityId)
	{
		return $this->implementer->copyChildren($container, $entityIdToCopy, $copiedEntityId);
	}

	protected function setCopiedEntityId(Container $container, $copiedEntityId)
	{
		$container->setCopiedEntityId($copiedEntityId);
	}

	protected function setDictionary(Container $container, Dictionary $dictionary)
	{
		$container->setDictionary($dictionary);
	}
}