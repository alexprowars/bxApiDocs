<?php
namespace Bitrix\Im;

use Bitrix\Main\Application;

class Chat
{
	const TYPE_SYSTEM = 'S';
	const TYPE_PRIVATE = 'P';
	const TYPE_OPEN = 'O';
	const TYPE_THREAD = 'T';
	const TYPE_GROUP = 'C';
	const TYPE_OPEN_LINE = 'L';

	const LIMIT_SEND_EVENT = 30;

	const FILTER_LIMIT = 50;

	public static function getChatTypes()
	{
		return Array(self::TYPE_GROUP, self::TYPE_OPEN_LINE, self::TYPE_OPEN, self::TYPE_THREAD);
	}

	public static function getRelation($chatId, $params = [])
	{
		$chatId = intval($chatId);
		if ($chatId <= 0)
		{
			return false;
		}

		$connection = \Bitrix\Main\Application::getInstance()->getConnection();

		$selectFields = '';
		if (isset($params['SELECT']))
		{
			$params['SELECT'][] = 'ID';
			$params['SELECT'][] = 'USER_ID';
			$map = \Bitrix\Im\Model\RelationTable::getMap();
			foreach ($params['SELECT'] as $key => $value)
			{
				if (is_int($key) && isset($map[$value]))
				{
					$selectFields .= "R.{$value}, ";
					unset($map[$value]);
				}
				else if (!is_int($key) && isset($map[$key]))
				{
					$selectFields .= "R.{$key} {$connection->getSqlHelper()->forSql($value)}, ";
					unset($map[$value]);
				}
			}
		}
		if (!$selectFields)
		{
			$selectFields = 'R.*, ';
		}

		$withUserFields = false;
		if (isset($params['USER_DATA']) && $params['USER_DATA'] == 'Y')
		{
			$withUserFields = true;
			$list = Array('ACTIVE', 'EXTERNAL_AUTH_ID');
			foreach ($list as $key)
			{
				$selectFields .= "U.{$key} USER_DATA_{$key}, ";
			}
		}

		$skipConnectorRelation = $params['SKIP_CONNECTOR'] == 'Y';

		$whereFields = '';
		if (isset($params['FILTER']))
		{
			$map = \Bitrix\Im\Model\RelationTable::getMap();
			foreach ($params['FILTER'] as $key => $value)
			{
				if (!isset($map[$key]))
				{
					continue;
				}

				$whereFields .= " AND R.{$key} = {$connection->getSqlHelper()->forSql($value)}";
			}
		}

		$skipUnmodifiedRecords = false;
		if (isset($params['SKIP_RELATION_WITH_UNMODIFIED_COUNTERS']) && $params['SKIP_RELATION_WITH_UNMODIFIED_COUNTERS'] == 'Y')
		{
			$skipUnmodifiedRecords = true;
		}

		$sqlSelectCounter = 'R.LAST_ID, R.COUNTER, R.COUNTER PREVIOUS_COUNTER';

		$customCounter = false;
		$customMaxId = 0;
		$customMinId = 0;
		$counters = [];

		if (isset($params['REAL_COUNTERS']) && $params['REAL_COUNTERS'] != 'N' || $skipUnmodifiedRecords)
		{
			if (is_array($params['REAL_COUNTERS']) && isset($params['REAL_COUNTERS']['LAST_ID']) && intval($params['REAL_COUNTERS']['LAST_ID']) > 0)
			{
				$sqlSelectCounter = "R.COUNTER PREVIOUS_COUNTER, (
					SELECT COUNT(1) FROM b_im_message M WHERE M.CHAT_ID = R.CHAT_ID AND M.ID > ".intval($params['REAL_COUNTERS']['LAST_ID'])."
				) COUNTER";
			}
			else
			{
				$customCounter = true;
				$query = \Bitrix\Main\Application::getInstance()->getConnection()->query("
					SELECT ID FROM b_im_message M WHERE M.CHAT_ID = {$chatId} ORDER BY ID DESC LIMIT 100
				");
				while ($row = $query->fetch())
				{
					if (!$customMaxId)
					{
						$customMaxId = $row['ID'];
					}
					$counters[$row['ID']] = count($counters);
					$customMinId = $row['ID'];
				}
			}
		}

		$sql = "
			SELECT {$selectFields} {$sqlSelectCounter}
			FROM b_im_relation R
			".($withUserFields && !$skipConnectorRelation? "LEFT JOIN b_user U ON R.USER_ID = U.ID": "")."
			".($skipConnectorRelation? "INNER JOIN b_user U ON R.USER_ID = U.ID AND (EXTERNAL_AUTH_ID != 'imconnector' OR EXTERNAL_AUTH_ID IS NULL)": "")."
			WHERE R.CHAT_ID = {$chatId} {$whereFields}
		";
		$relations = array();
		$query = \Bitrix\Main\Application::getInstance()->getConnection()->query($sql);
		while ($row = $query->fetch())
		{
			if ($customCounter)
			{
				if (isset($counters[$row['LAST_ID']]))
				{
					$row['COUNTER'] = $counters[$row['LAST_ID']];
				}
				else if ($row['LAST_ID'] < $customMinId)
				{
					$row['COUNTER'] = count($counters);
				}
				else if ($row['LAST_ID'] > $customMaxId)
				{
					$row['COUNTER'] = 0;
				}
			}
			else
			{
				$row['COUNTER'] = $row['COUNTER'] > 99? 100: intval($row['COUNTER']);
			}

			$row['PREVIOUS_COUNTER'] = intval($row['PREVIOUS_COUNTER']);

			if ($skipUnmodifiedRecords && $row['COUNTER'] == $row['PREVIOUS_COUNTER'])
			{
				continue;
			}

			foreach ($row as $key => $value)
			{
				if (strpos($key, 'USER_DATA_') === 0)
				{
					$row['USER_DATA'][substr($key, 10)] = $value;
					unset($row[$key]);
				}
			}

			$relations[$row['USER_ID']] = $row;
		}

		return $relations;
	}

	public static function mute($chatId, $action, $userId = null)
	{
		$userId = \Bitrix\Im\Common::getUserId($userId);
		if (!$userId)
		{
			return false;
		}

		$chatId = intval($chatId);
		if (!$chatId)
		{
			return false;
		}

		$action = $action === true? 'Y': 'N';

		$relation = self::getRelation($chatId, Array(
			'SELECT' => Array('ID', 'NOTIFY_BLOCK'),
			'FILTER' => Array(
				'USER_ID' => $userId
			),
		));
		if (!$relation)
		{
			return false;
		}

		if ($relation[$userId]['NOTIFY_BLOCK'] == $action)
		{
			return true;
		}

		\Bitrix\Im\Model\RelationTable::update($relation[$userId]['ID'], array('NOTIFY_BLOCK' => $action));

		Recent::clearCache($userId);

		if (\Bitrix\Main\Loader::includeModule('pull'))
		{
			\Bitrix\Pull\Event::add($userId, Array(
				'module_id' => 'im',
				'command' => 'chatMuteNotify',
				'params' => Array(
					'dialogId' => 'chat'.$chatId,
					'mute' => $action == 'Y'
				),
				'extra' => \Bitrix\Im\Common::getPullExtra()
			));
		}

		return true;
	}

	public static function getMessageCount($chatId, $userId = null)
	{
		$chatId = intval($chatId);
		if (!$chatId)
		{
			return false;
		}

		$userId = \Bitrix\Im\Common::getUserId($userId);
		if (!$userId)
		{
			return false;
		}

		$relationData = \Bitrix\Im\Model\RelationTable::getList(Array(
			'select' => Array('START_ID'),
			'filter' => Array('=CHAT_ID' => $chatId, '=USER_ID' => $userId)
		))->fetch();

		if (!$relationData || $relationData['START_ID'] == 0)
		{
			$counter = \Bitrix\Im\Model\MessageTable::getList(array(
				'filter' => Array('CHAT_ID' => $chatId),
				'select' => array("CNT" => new \Bitrix\Main\Entity\ExpressionField('CNT', 'COUNT(1)')),
			))->fetch();
		}
		else
		{
			$counter = \Bitrix\Im\Model\MessageTable::getList(array(
				'filter' => Array('CHAT_ID' => $chatId, '>=ID' => $relationData['START_ID']),
				'select' => array("CNT" => new \Bitrix\Main\Entity\ExpressionField('CNT', 'COUNT(1)')),
			))->fetch();
		}

		return $counter && $counter['CNT'] > 0? intval($counter['CNT']): 0;
	}

	public static function hasAccess($chatId)
	{
		$chatId = intval($chatId);
		if (!$chatId)
		{
			return false;
		}

		return \Bitrix\Im\Dialog::hasAccess('chat'.$chatId);
	}

	public static function getMessages($chatId, $userId = null, $options = Array())
	{
		$userId = \Bitrix\Im\Common::getUserId($userId);
		if (!$userId)
		{
			return false;
		}

		$chatData = \Bitrix\Im\Model\ChatTable::getList(Array(
			'select' => Array(
				'CHAT_ID' => 'ID',
				'CHAT_TYPE' => 'TYPE',
				'RELATION_USER_ID' => 'RELATION.USER_ID',
				'RELATION_START_ID' => 'RELATION.START_ID',
				'RELATION_LAST_ID' => 'RELATION.LAST_ID'
			),
			'filter' => Array('=ID' => $chatId),
			'runtime' => Array(
				new \Bitrix\Main\Entity\ReferenceField(
					'RELATION',
					'\Bitrix\Im\Model\RelationTable',
					array(
						"=ref.CHAT_ID" => "this.ID",
						"=ref.USER_ID" => new \Bitrix\Main\DB\SqlExpression('?', $userId)
					),
					array("join_type"=>"LEFT")
				)
			)
		))->fetch();
		if (!$chatData)
		{
			return false;
		}

		$chatData['RELATION_START_ID'] = intval($chatData['RELATION_START_ID']);
		$chatData['RELATION_LAST_ID'] = intval($chatData['RELATION_LAST_ID']);

		$filter = Array(
			'=CHAT_ID' => $chatId
		);

		if (isset($options['FIRST_ID']))
		{
			$order = array();
			if ($chatData['RELATION_START_ID'] > 0 && intval($options['FIRST_ID']) < $chatData['RELATION_START_ID'])
			{
				$filter['>=ID'] = $chatData['RELATION_START_ID'];
			}
			else
			{
				if (intval($options['FIRST_ID']) > 0)
				{
					$filter['>ID'] = $options['FIRST_ID'];
				}
			}
		}
		else
		{
			$order = Array('CHAT_ID' => 'ASC', 'ID' => 'DESC');

			if ($chatData['RELATION_START_ID'] > 0)
			{
				$filter['>=ID'] = $chatData['RELATION_START_ID'];
			}

			if (isset($options['LAST_ID']) && intval($options['LAST_ID']) > 0)
			{
				$filter['<ID'] = intval($options['LAST_ID']);
			}
		}

		if (isset($options['LIMIT']))
		{
			$options['LIMIT'] = intval($options['LIMIT']);
			$limit = $options['LIMIT'] >= 50? 50: $options['LIMIT'];
		}
		else
		{
			$limit = 50;
		}

		$orm = \Bitrix\Im\Model\MessageTable::getList(array(
			'filter' => $filter,
			'select' => Array('ID', 'AUTHOR_ID', 'DATE_CREATE', 'MESSAGE'),
			'order' => $order,
			'limit' => $limit
		));

		$users = Array();
		$userOptions = $options['JSON'] == 'Y'? Array('JSON' => 'Y'): Array();

		$messages = Array();
		while($message = $orm->fetch())
		{
			$messages[$message['ID']] = Array(
				'ID' => (int)$message['ID'],
				'CHAT_ID' => (int)$chatId,
				'AUTHOR_ID' => (int)$message['AUTHOR_ID'],
				'DATE' => $message['DATE_CREATE'],
				'TEXT' => (string)$message['MESSAGE'],
				'UNREAD' => $chatData['RELATION_USER_ID'] > 0 && $chatData['RELATION_LAST_ID'] < $message['ID']
			);
			if ($message['AUTHOR_ID'] && !isset($users[$message['AUTHOR_ID']]))
			{
				$users[$message['AUTHOR_ID']] = User::getInstance($message['AUTHOR_ID'])->getArray($userOptions);
			}
		}

		$params = \CIMMessageParam::Get(array_keys($messages));

		$fileIds = Array();
		foreach ($params as $messageId => $param)
		{
			$messages[$messageId]['params'] = empty($param)? null: $param;

			if (isset($param['FILE_ID']))
			{
				foreach ($param['FILE_ID'] as $fileId)
				{
					$fileIds[$fileId] = $fileId;
				}
			}
		}

		$messages = \CIMMessageLink::prepareShow($messages, $params);

		$files = \CIMDisk::GetFiles($chatId, $fileIds);

		$result = Array(
			'CHAT_ID' => (int)$chatId,
			'MESSAGES' => $messages,
			'USERS' => $users,
			'FILES' => $files,
		);

		if ($options['JSON'])
		{
			foreach ($result['MESSAGES'] as $key => $value)
			{
				if ($value['DATE'] instanceof \Bitrix\Main\Type\DateTime)
				{
					$result['MESSAGES'][$key]['DATE'] = date('c', $value['DATE']->getTimestamp());
				}

				$result['MESSAGES'][$key] = array_change_key_case($result['MESSAGES'][$key], CASE_LOWER);
			}
			$result['MESSAGES'] = array_values($result['MESSAGES']);

			foreach ($result['FILES'] as $key => $value)
			{
				if ($value['date'] instanceof \Bitrix\Main\Type\DateTime)
				{
					$result['FILES'][$key]['date'] = date('c', $value['date']->getTimestamp());
				}

				foreach (['urlPreview', 'urlShow', 'urlDownload'] as $field)
				{
					$url = $result['FILES'][$key][$field];
					if (is_string($url) && $url && strpos($url, 'http') !== 0)
					{
						$result['FILES'][$key][$field] = \Bitrix\Im\Common::getPublicDomain().$url;
					}
				}

			}

			$result = array_change_key_case($result, CASE_LOWER);
		}

		return $result;
	}

	public static function getList($params = array())
	{
		$params = is_array($params)? $params: Array();

		if (!isset($params['CURRENT_USER']) && is_object($GLOBALS['USER']))
		{
			$params['CURRENT_USER'] = $GLOBALS['USER']->GetID();
		}

		$params['CURRENT_USER'] = intval($params['CURRENT_USER']);

		$userId = $params['CURRENT_USER'];
		if ($userId <= 0)
		{
			return false;
		}

		$enableLimit = false;
		if (isset($params['OFFSET']))
		{
			$filterLimit = intval($params['LIMIT']);
			$filterLimit = $filterLimit <= 0? self::FILTER_LIMIT: $filterLimit;

			$filterOffset = intval($params['OFFSET']);

			$enableLimit = true;
		}
		else
		{
			$filterLimit = false;
			$filterOffset = false;
		}

		$ormParams = self::getListParams($params);
		if (!$ormParams)
		{
			return false;
		}
		if ($enableLimit)
		{
			$ormParams['offset'] = $filterOffset;
			$ormParams['limit'] = $filterLimit;
		}
		if (isset($params['ORDER']))
		{
			$ormParams['order'] = $params['ORDER'];
		}

		$generalChatId = \CIMChat::GetGeneralChatId();

		$orm = \Bitrix\Im\Model\ChatTable::getList($ormParams);
		$chats = array();
		while ($row = $orm->fetch())
		{
			$avatar = \CIMChat::GetAvatarImage($row['AVATAR'], 100, false);
			$color = strlen($row['COLOR']) > 0? Color::getColor($row['COLOR']): Color::getColorByNumber($row['ID']);
			if ($row["TYPE"] == IM_MESSAGE_PRIVATE)
			{
				$chatType = 'private';
			}
			else if ($row["ENTITY_TYPE"] == 'CALL')
			{
				$chatType = 'call';
			}
			else if ($row["ENTITY_TYPE"] == 'LINES')
			{
				$chatType = 'lines';
			}
			else if ($row["ENTITY_TYPE"] == 'LIVECHAT')
			{
				$chatType = 'livechat';
			}
			else
			{
				if ($generalChatId == $row['ID'])
				{
					$row["ENTITY_TYPE"] = 'GENERAL';
				}
				$chatType = $row["TYPE"] == IM_MESSAGE_OPEN? 'open': 'chat';
			}

			$muteList = Array();
			if ($row['RELATION_NOTIFY_BLOCK'] == 'Y')
			{
				$muteList = Array($row['RELATION_USER_ID'] => true);
			}


			$chats[] = Array(
				'ID' => (int)$row['ID'],
				'NAME' => $row['TITLE'],
				'OWNER' => (int)$row['AUTHOR_ID'],
				'EXTRANET' => $row['EXTRANET'] == 'Y',
				'AVATAR' => $avatar,
				'COLOR' => $color,
				'TYPE' => $chatType,
				'ENTITY_TYPE' => (string)$row['ENTITY_TYPE'],
				'ENTITY_ID' => (string)$row['ENTITY_ID'],
				'ENTITY_DATA_1' => (string)$row['ENTITY_DATA_1'],
				'ENTITY_DATA_2' => (string)$row['ENTITY_DATA_2'],
				'ENTITY_DATA_3' => (string)$row['ENTITY_DATA_3'],
				'MUTE_LIST' => $muteList,
				'DATE_CREATE' => $row['DATE_CREATE'],
				'MESSAGE_TYPE' => $row["TYPE"],
			);

		}

		if ($params['JSON'])
		{
			foreach ($chats as $key => $chatData)
			{
				foreach ($chatData as $field => $value)
				{
					if ($value instanceof \Bitrix\Main\Type\DateTime)
					{
						$chats[$key][$field] = date('c', $value->getTimestamp());
					}
					else if (is_string($value) && $value && in_array($field, Array('AVATAR')) && strpos($value, 'http') !== 0)
					{
						$chats[$key][$field] = \Bitrix\Im\Common::getPublicDomain().$value;
					}
					else if (is_array($value))
					{
						$chats[$key][$field] = array_change_key_case($value, CASE_LOWER);
					}
				}
				$chats[$key] = array_change_key_case($chats[$key], CASE_LOWER);;
			}
		}

		return $chats;
	}

	public static function getListParams($params)
	{
		if (!isset($params['CURRENT_USER']) && is_object($GLOBALS['USER']))
		{
			$params['CURRENT_USER'] = $GLOBALS['USER']->GetID();
		}

		$params['CURRENT_USER'] = intval($params['CURRENT_USER']);

		$userId = $params['CURRENT_USER'];
		if ($userId <= 0)
		{
			return null;
		}

		$filter = [];
		$runtime = [];

		if (isset($params['FILTER']['SEARCH']))
		{
			$find = $params['FILTER']['SEARCH'];

			$helper = Application::getConnection()->getSqlHelper();
			if (Model\ChatIndexTable::getEntity()->fullTextIndexEnabled('SEARCH_CONTENT'))
			{
				$find = trim($find);
				$find = \Bitrix\Main\Search\Content::prepareStringToken($find);

				if (\Bitrix\Main\Search\Content::canUseFulltextSearch($find, \Bitrix\Main\Search\Content::TYPE_MIXED))
				{
					$filter['*INDEX.SEARCH_CONTENT'] = $find;
				}
				else
				{
					return null;
				}
			}
			else
			{
				if (strlen($find) < 3)
				{
					return null;
				}

				$filter['%=INDEX.SEARCH_TITLE'] = $helper->forSql($find).'%';
			}
		}

		if (User::getInstance($params['CURRENT_USER'])->isExtranet())
		{
			$filter['=TYPE'] = [self::TYPE_OPEN, self::TYPE_GROUP];
			$filter['=RELATION.USER_ID'] = $params['CURRENT_USER'];
		}
		else
		{
			$filter[] = [
				'LOGIC' => 'OR',
				[
					'=TYPE' => self::TYPE_OPEN,
				],
				[
					'=TYPE' => self::TYPE_GROUP,
					'=RELATION.USER_ID' => $params['CURRENT_USER']
				]
			];
		}

		$runtime[] = new \Bitrix\Main\Entity\ReferenceField(
			'RELATION',
			'Bitrix\Im\Model\RelationTable',
			array(
				"=ref.CHAT_ID" => "this.ID",
				"=ref.USER_ID" => new \Bitrix\Main\DB\SqlExpression('?', $params['CURRENT_USER']),
			),
			array("join_type"=>"LEFT")
		);

		return [
			'select' => [
				'*',
				'RELATION_USER_ID' => 'RELATION.USER_ID',
				'RELATION_NOTIFY_BLOCK' => 'RELATION.NOTIFY_BLOCK',
			],
			'filter' => $filter,
			'runtime' => $runtime
		];
	}
}