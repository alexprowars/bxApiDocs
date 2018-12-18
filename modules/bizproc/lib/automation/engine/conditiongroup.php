<?php
namespace Bitrix\Bizproc\Automation\Engine;

use Bitrix\Bizproc\Automation\Target\BaseTarget;
use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

class ConditionGroup
{
	const TYPE_FIELD = 'field';
	//const TYPE_VARIABLE = 'variable'; //reserved

	const JOINER_AND = 'AND';// 0
	const JOINER_OR = 'OR';// 1

	private $type;
	private $items = [];

	public function __construct(array $params = null)
	{
		$this->setType(static::TYPE_FIELD);
		if ($params)
		{
			if (isset($params['type']))
			{
				$this->setType($params['type']);
			}
			if (isset($params['items']) && is_array($params['items']))
			{
				foreach ($params['items'] as list($item, $joiner))
				{
					if (!empty($item['field']))
					{
						$condition = new Condition($item);
						$this->addItem($condition, $joiner);
					}
				}
			}
		}
	}

	/**
	 * @param BaseTarget $target Automation target.
	 * @return bool
	 */
	public function evaluate(BaseTarget $target)
	{
		if (empty($this->items))
		{
			return true;
		}

		$documentType = $target->getDocumentType();
		$documentId = $documentType;
		$documentId[2] = $target->getDocumentId();

		$runtime = \CBPRuntime::getRuntime();
		$runtime->startRuntime();

		$documentService = $runtime->getService("DocumentService");
		$document = $documentService->getDocument($documentId, $documentType);
		$documentFields = $documentService->getDocumentFields($documentType);

		$result = array(0 => true);
		$i = 0;
		foreach ($this->items as $item)
		{
			/** @var Condition $condition */
			$condition = $item[0];
			$conditionField = $condition->getField();
			$joiner = ($item[1] === static::JOINER_OR) ? static::JOINER_OR : static::JOINER_AND;

			$conditionResult = true;

			if (array_key_exists($conditionField, $document))
			{
				$fld = $document[$conditionField];
				$type = null;

				if (isset($documentFields[$conditionField]))
				{
					$type = $documentFields[$conditionField]["BaseType"];
					if ($documentFields[$conditionField]['Type'] === 'UF:boolean')
					{
						$type = 'bool';
					}
				}

				if (!$condition->check($fld, $type, $target))
				{
					$conditionResult = false;
				}
			}

			if ($joiner == static::JOINER_OR)
			{
				++$i;
				$result[$i] = $conditionResult;
			}
			elseif (!$conditionResult)
			{
				$result[$i] = false;
			}
		}

		return (count(array_filter($result)) > 0);
	}

	/**
	 * @param string $type Type of condition.
	 * @return ConditionGroup This instance.
	 */
	public function setType($type)
	{
		if ($type === static::TYPE_FIELD)
		{
			$this->type = $type;
		}
		return $this;
	}

	/**
	 * @return mixed
	 */
	public function getType()
	{
		return $this->type;
	}

	/**
	 * @param Condition $condition Condition instance.
	 * @param string $joiner Condition joiner.
	 * @return $this This instance.
	 */
	public function addItem(Condition $condition, $joiner = self::JOINER_AND)
	{
		$this->items[] = [$condition, $joiner];
		return $this;
	}

	/**
	 * @return array Condition items.
	 */
	public function getItems()
	{
		return $this->items;
	}

	/**
	 * @return array Array presentation of condition group.
	 */
	public function toArray()
	{
		$itemsArray = [];

		/** @var Condition $condition */
		foreach ($this->getItems() as list($condition, $joiner))
		{
			$itemsArray[] = [$condition->toArray(), $joiner];
		}

		return ['type' => $this->getType(), 'items' => $itemsArray];
	}

	/**
	 * @param array $childActivity Child activity array.
	 * @return array New activity array.
	 */
	public function createBizprocActivity(array $childActivity)
	{
		$title = Loc::getMessage('BIZPROC_AUTOMATION_CONDITION_TITLE');
		$fieldCondition = [];
		$bizprocJoiner = 0;

		/** @var Condition $condition */
		foreach ($this->getItems() as list($condition, $joiner))
		{
			$fieldCondition[] = [
				$condition->getField(),
				$condition->getOperator(),
				$condition->getValue(),
				$bizprocJoiner
			];
			$bizprocJoiner = ($joiner === static::JOINER_OR) ? 1 : 0;
		}

		$activity = array(
			'Type' => 'IfElseActivity',
			'Name' => Robot::generateName(),
			'Properties' => array('Title' => $title),
			'Children' => array(
				array(
					'Type' => 'IfElseBranchActivity',
					'Name' => Robot::generateName(),
					'Properties' => array(
						'Title' => $title,
						'fieldcondition' => $fieldCondition
					),
					'Children' => array($childActivity)
				),
				array(
					'Type' => 'IfElseBranchActivity',
					'Name' => Robot::generateName(),
					'Properties' => array(
						'Title' => $title,
						'truecondition' => '1',
					),
					'Children' => array()
				)
			)
		);

		return $activity;
	}

	/**
	 * @param array &$activity Target activity array.
	 * @return false|Condition Condition instance of false.
	 */
	public static function convertBizprocActivity(array &$activity)
	{
		$conditionGroup = false;
		if (
			count($activity['Children']) === 2
			&& $activity['Children'][0]['Type'] === 'IfElseBranchActivity'
			&& $activity['Children'][1]['Type'] === 'IfElseBranchActivity'
			&& !empty($activity['Children'][0]['Properties']['fieldcondition'])
			&& !empty($activity['Children'][1]['Properties']['truecondition'])
			&& count($activity['Children'][0]['Children']) === 1
			&& count($activity['Children'][0]['Properties']['fieldcondition']) > 0
		)
		{
			$conditionGroup = new static();
			$bizprocConditions = $activity['Children'][0]['Properties']['fieldcondition'];

			foreach ($bizprocConditions as $index => $fieldCondition)
			{
				$conditionItem = new Condition(array(
					'field' => $fieldCondition[0],
					'operator' => $fieldCondition[1],
					'value' => $fieldCondition[2],
				));

				$nextCondition = isset($bizprocConditions[$index + 1]) ? $bizprocConditions[$index + 1] : null;
				$joiner = ($nextCondition && !empty($nextCondition[3])) ? static::JOINER_OR : static::JOINER_AND;

				$conditionGroup->addItem($conditionItem, $joiner);
			}

			$activity = $activity['Children'][0]['Children'][0];
		}

		return $conditionGroup;
	}
}