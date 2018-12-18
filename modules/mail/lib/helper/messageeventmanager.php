<?php
namespace Bitrix\Mail\Helper;

use Bitrix\Mail\MailMessageTable;
use Bitrix\Mail\MailMessageUidTable;
use Bitrix\Main\Event;
use Bitrix\Main\Loader;
use Bitrix\Main\Type\DateTime;

class MessageEventManager
{
	public static function onMailMessageDeleted(Event $event)
	{
		$manager = new static();
		$manager->processOnMailMessageDeletedEvent($event);
		return $manager;
	}

	private function processOnMailMessageDeletedEvent(Event $event)
	{
		$params = $event->getParameters();
		$filter = empty($params['DELETED_BY_FILTER']) ? [] : $params['DELETED_BY_FILTER'];
		$fieldsData = empty($params['MAIL_FIELDS_DATA']) ? [] : $params['MAIL_FIELDS_DATA'];
		$this->handleRemovedEvent($fieldsData, $filter);
	}

	public static function onMailMessageModified(Event $event)
	{
		$manager = new static();
		$manager->processOnMailMessageModified($event);
		return $manager;
	}

	private function processOnMailMessageModified(Event $event)
	{
		$params = $event->getParameters();
		$updatedFieldValues = empty($params['UPDATED_FIELDS_VALUES']) ? [] : $params['UPDATED_FIELDS_VALUES'];
		$fieldsData = empty($params['MAIL_FIELDS_DATA']) ? [] : $params['MAIL_FIELDS_DATA'];
		$filter = empty($params['UPDATED_BY_FILTER']) ? [] : $params['UPDATED_BY_FILTER'];
		if (!empty($updatedFieldValues) && isset($updatedFieldValues['IS_SEEN']))
		{
			$fieldsData = $this->getMailsFieldsData($fieldsData, ['HEADER_MD5', 'MAILBOX_USER_ID', 'IS_SEEN'], $filter);
			$this->sendMessageModifiedEvent($fieldsData);
		}
		if (!empty($updatedFieldValues) && isset($updatedFieldValues['DIR_MD5']))
		{
			$folderHash = empty($updatedFieldValues['DIR_MD5']) ? null : $updatedFieldValues['DIR_MD5'];
			$mailboxOptions = !empty($fieldsData[0]) && !empty($fieldsData[0]['MAILBOX_OPTIONS']) ? $fieldsData[0]['MAILBOX_OPTIONS'] : [];
			if (!empty($folderHash) && !empty($mailboxOptions))
			{
				$isTrashFolder = $folderHash === MessageFolder::getFolderHashByType(MessageFolder::TRASH, $mailboxOptions);
				$isSpamFolder = $folderHash === MessageFolder::getFolderHashByType(MessageFolder::SPAM, $mailboxOptions);
				$folderName = MessageFolder::getFolderNameByHash($folderHash, $mailboxOptions);
				$isDisabledFolder = MessageFolder::isDisabledFolder($folderName, $mailboxOptions);

				if ($isTrashFolder || $isSpamFolder || $isDisabledFolder)
				{
					$this->handleRemovedEvent($fieldsData, $filter);
				}
			}
		}
	}

	protected function sendMessageModifiedEvent($fieldsData)
	{
		foreach ($fieldsData as $fields)
		{
			$event = new Event(
				'mail', 'OnMessageModified',
				[
					'user' => $fields['MAILBOX_USER_ID'],
					'hash' => $fields['HEADER_MD5'],
					'seen' => $fields['IS_SEEN'] === 'Y',
				]
			);
			$event->send();
		}
	}

	private function handleRemovedEvent($fieldsData, $filter)
	{
		$fieldsData = $this->getMailsFieldsData($fieldsData, ['HEADER_MD5', 'MAILBOX_USER_ID'], $filter);
		$this->sendMessageDeletedEvent($fieldsData);
	}

	protected function sendMessageDeletedEvent($fieldsData)
	{
		foreach ($fieldsData as $fields)
		{
			$event = new Event(
				'mail', 'OnMessageObsolete',
				[
					'user' => $fields['MAILBOX_USER_ID'],
					'hash' => $fields['HEADER_MD5'],
				]
			);
			$event->send();
		}
	}

	private function getMailsFieldsData($eventData, $requiredKeys, $filter)
	{
		$fieldsData = array_filter($eventData, function ($item) use ($requiredKeys)
		{
			$hasAllKeys = true;
			foreach ($requiredKeys as $requiredKey)
			{
				$hasAllKeys = $hasAllKeys && isset($item[$requiredKey]);
			}
			return $hasAllKeys;
		});

		if (empty($fieldsData) && !empty($filter))
		{
			$fieldsData = $this->getMailMessagesList($filter);
		}
		$results = [];
		foreach ($fieldsData as $index => $mailFieldsData)
		{
			$results[$mailFieldsData['HEADER_MD5']] = $mailFieldsData;
		}
		return $results;
	}

	protected function getMailMessagesList($filter)
	{
		$dateLastMonth = new DateTime();
		$dateLastMonth->add('-1 MONTH');
		return MailMessageUidTable::getList([
				'select' => ['HEADER_MD5', 'IS_SEEN', 'MAILBOX_USER_ID' => 'MAILBOX.USER_ID'],
				'filter' => array_merge($filter, [
					'>=INTERNALDATE' => $dateLastMonth,
				]),
			]
		)->fetchAll();
	}

	/**
	 * @param array $data
	 * @return array
	 * @throws \Bitrix\Main\ObjectException
	 * @throws \Exception
	 */
	public static function onMailEventMailRead(array $data)
	{
		$messageId = $data['msgid'];
		if($messageId)
		{
			$message = MailMessageTable::getList([
				'select' => [
					'OPTIONS', 'ID', 'READ_CONFIRMED',
				],
				'filter' => [
					'=MSG_ID' => $messageId,
					'READ_CONFIRMED' => null,
				]
			])->fetch();
			if($message)
			{
				$readTime = new DateTime();
				$result = MailMessageTable::update($message['ID'], [
					'READ_CONFIRMED' => $readTime,
				]);
				if($result->isSuccess())
				{
					if(Loader::includeModule("pull"))
					{
						\CPullWatch::addToStack(static::getPullTagName($message['ID']), [
							'module_id' => 'mail',
							'command' => 'onMessageRead',
							'params' => [
								'messageId' => $message['ID'],
								'readTime' => $readTime->getTimestamp(),
							],
						]);
					}
				}
			}
		}

		return $data;
	}

	public static function getPullTagName($messageId)
	{
		return 'MAILMESSAGEREADED'.$messageId;
	}
}