<?php

namespace Bitrix\Mail\Helper\Mailbox;

use Bitrix\Mail\Helper\MessageFolder;
use Bitrix\Main;
use Bitrix\Mail;

class Imap extends Mail\Helper\Mailbox
{

	//const SYNC_TIME_QUOTA = 120;

	protected $client;

	protected function __construct($mailbox)
	{
		parent::__construct($mailbox);

		$mailbox = &$this->mailbox;
		$options = &$mailbox['OPTIONS'];

		if (empty($options['imap']) || !is_array($options['imap']))
		{
			$options['imap'] = array();
		}

		$imapOptions = &$options['imap'];
		if (empty($imapOptions[MessageFolder::INCOME]) || !is_array($imapOptions[MessageFolder::INCOME]))
		{
			$imapOptions[MessageFolder::INCOME] = array();
		}
		if (empty($imapOptions[MessageFolder::OUTCOME]) || !is_array($imapOptions[MessageFolder::OUTCOME]))
		{
			$imapOptions[MessageFolder::OUTCOME] = array();
		}

		$imapOptions[MessageFolder::OUTCOME] = array_diff($imapOptions[MessageFolder::OUTCOME], $imapOptions[MessageFolder::INCOME]);

		$this->client = new Mail\Imap(
			$mailbox['SERVER'],
			$mailbox['PORT'],
			$mailbox['USE_TLS'] == 'Y' || $mailbox['USE_TLS'] == 'S',
			$mailbox['USE_TLS'] == 'Y',
			$mailbox['LOGIN'],
			$mailbox['PASSWORD']
		);
	}

	public function getSyncStatus()
	{
		$meta = Mail\MailMessageUidTable::getList(array(
			'select' => array(
				new Main\Entity\ExpressionField('TOTAL', 'COUNT(%s)', 'MESSAGE_ID'),
			),
			'filter' => array(
				'=MAILBOX_ID' => $this->mailbox['ID'],
				'>MESSAGE_ID' => 0,
			),
		))->fetch();

		if ($meta['TOTAL'] > 0 && $this->mailbox['OPTIONS']['imap']['total'] > 0)
		{
			return $meta['TOTAL'] / $this->mailbox['OPTIONS']['imap']['total'];
		}
		else
		{
			return parent::getSyncStatus();
		}
	}

	protected function syncInternal()
	{
		$count = $this->syncMailbox();
		if (false === $count)
		{
			$this->errors = new Main\ErrorCollection($this->client->getErrors()->toArray());
		}

		return $count;
	}

	protected function createMessage(Main\Mail\Mail $message, array $fields = array())
	{
		$dir = reset($this->mailbox['OPTIONS']['imap'][MessageFolder::OUTCOME]) ?: 'INBOX';

		$fields = array_merge(
			$fields,
			array(
				'DIR_MD5' => md5($dir),
				'DIR_UIDV' => 0,
				'MSG_UID' => 0,
			)
		);

		return parent::createMessage($message, $fields);
	}

	public function uploadMessage(Main\Mail\Mail $message, array $excerpt = null)
	{
		$dir = reset($this->mailbox['OPTIONS']['imap'][MessageFolder::OUTCOME]) ?: 'INBOX';

		$data = $this->client->select($dir, $error);

		if (false === $data)
		{
			$this->errors = new Main\ErrorCollection($this->client->getErrors()->toArray());

			return false;
		}

		if (!empty($excerpt['__unique_headers']))
		{
			if ($this->client->searchByHeader(false, $dir, $excerpt['__unique_headers'], $error))
			{
				return false;
			}
		}

		$flags = array('\Seen');
		if (!empty($excerpt['ID']) && preg_grep('/^ \x5c \* $/ix', $data['permanentflags']))
		{
			$flags[] = sprintf('bxuid%s', $excerpt['ID']);
		}

		$result = $this->client->append(
			$dir,
			$flags,
			new \DateTime,
			sprintf(
				'%1$s%3$s%3$s%2$s',
				$message->getHeaders(),
				$message->getBody(),
				$message->getMailEol()
			),
			$error
		);

		if (false === $result)
		{
			$this->errors = new Main\ErrorCollection($this->client->getErrors()->toArray());

			return false;
		}

		$this->syncDir($dir);

		return $result;
	}

	public function cacheDirs()
	{
		$dirs = $this->client->listMailboxes('*', $error, true);
		if (false === $dirs)
		{
			$this->errors = new Main\ErrorCollection($this->client->getErrors()->toArray());

			return false;
		}

		$imapDirs = array();
		$disabledDirs = array();

		$outcomeDirs = array();
		$trashDirs = array();
		$spamDirs = array();
		$inboxDirs = array();
		$inboxTrees = array();

		foreach ($dirs as $i => $item)
		{
			if (strtoupper($item['name']) === 'INBOX')
			{
				if (!$inboxDirs)
				{
					$inboxDirs = [$item['name']];
				}
				else
				{
					$disabledDirs[] = $item['name'];
				}
			}
		}

		foreach ($dirs as $i => $item)
		{
			$imapDirs[$item['name']] = $item['path'];

			if (in_array(reset($item['path']), $inboxDirs))
			{
				$inboxTrees[$item['name']] = $item['path'];
			}

			if (preg_grep('/^ \x5c Noselect $/ix', $item['flags']))
			{
				$disabledDirs[] = $item['name'];
			}

			if (preg_grep('/^ \x5c Sent $/ix', $item['flags']))
			{
				$outcomeDirs[] = $item['name'];
			}

			if (preg_grep('/^ \x5c Trash $/ix', $item['flags']))
			{
				$trashDirs[] = $item['name'];
			}

			if (preg_grep('/^ \x5c ( Junk | Spam ) $/ix', $item['flags']))
			{
				$spamDirs[] = $item['name'];
			}
		}

		$options = &$this->mailbox['OPTIONS'];

		$options['imap']['dirs'] = $inboxTrees + $imapDirs;
		$options['imap']['disabled'] = $disabledDirs;

		$options['imap'][MessageFolder::INCOME] = $inboxDirs;

		if (empty($options['imap'][MessageFolder::OUTCOME]))
		{
			$options['imap'][MessageFolder::OUTCOME] = $outcomeDirs;
		}

		if (empty($options['imap'][MessageFolder::TRASH]))
		{
			$options['imap'][MessageFolder::TRASH] = $trashDirs;
		}

		if (empty($options['imap'][MessageFolder::SPAM]))
		{
			$options['imap'][MessageFolder::SPAM] = $spamDirs;
		}

		if (!empty($options['imap']['!sync_dirs']))
		{
			$options['imap']['sync_dirs'] = $options['imap']['!sync_dirs'];
		}

		if (!empty($options['imap']['sync_dirs']))
		{
			$options['imap']['ignore'] = array_values(array_diff(
				array_keys($imapDirs),
				(array) $options['imap']['sync_dirs']
			));

			unset($options['imap']['sync_dirs']);
		}

		if (!array_key_exists('ignore', $options['imap']))
		{
			$options['imap']['ignore'] = array_merge(
				(array) $options['imap'][MessageFolder::TRASH],
				(array) $options['imap'][MessageFolder::SPAM]
			);
		}

		$options['imap']['ignore'] = array_unique(array_merge(
			(array) $options['imap']['ignore'],
			$options['imap']['disabled']
		));

		Mail\MailboxTable::update(
			$this->mailbox['ID'],
			array(
				'OPTIONS' => $options,
			)
		);

		return $dirs;
	}

	public function cacheMeta()
	{
		$dirs = $this->cacheDirs();
		if (false === $dirs)
		{
			return false;
		}

		$meta = array();

		foreach ($dirs as $i => $item)
		{
			if (!in_array($item['name'], (array) $this->mailbox['OPTIONS']['imap']['ignore']))
			{
				if (!preg_grep('/^ \x5c Noselect $/ix', $item['flags']))
				{
					$data = $this->client->examine($item['name'], $error);
					if (false === $data)
					{
						$this->warnings->add($this->client->getErrors()->toArray());
					}
					else
					{
						$item = array_merge($item, $data);
					}
				}
			}

			$meta[$item['name']] = $item;
		}

		$options = &$this->mailbox['OPTIONS'];

		$options['imap']['total'] = array_reduce(
			$meta,
			function ($sum, $item)
			{
				return $sum + $item['exists'];
			},
			0
		);

		Mail\MailboxTable::update(
			$this->mailbox['ID'],
			array(
				'OPTIONS' => $options,
			)
		);

		return $meta;
	}

	protected function getFolderToMessagesMap($messages)
	{
		if (isset($messages['MSG_UID']))
		{
			$messages = [$messages];
		}
		$data = [];
		$result = new Main\Result();
		foreach ($messages as $message)
		{
			$id = $message['MSG_UID'];
			$folderFrom = MessageFolder::getFolderNameByHash($message['DIR_MD5'], $this->mailbox['OPTIONS']);
			$data[$folderFrom][] = $id;
			$results[$folderFrom][] = $message;
		}
		return $result->setData($data);
	}

	public function markUnseen($messages)
	{
		$result = $this->getFolderToMessagesMap($messages);
		foreach ($result->getData() as $folderFrom => $ids)
		{
			$result = $this->client->unseen($ids, $folderFrom);
			if (!$result->isSuccess() || !$this->client->getErrors()->isEmpty())
			{
				break;
			}
		}
		return $result;
	}

	public function markSeen($messages)
	{
		$result = $this->getFolderToMessagesMap($messages);
		foreach ($result->getData() as $folderFrom => $ids)
		{
			$result = $this->client->seen($ids, $folderFrom);
			if (!$result->isSuccess() || !$this->client->getErrors()->isEmpty())
			{
				break;
			}
		}
		return $result;
	}

	public function moveMailsToFolder($messages, $folderTo)
	{
		$result = $this->getFolderToMessagesMap($messages);
		$moveResult = new Main\Result();
		foreach ($result->getData() as $folderFrom => $ids)
		{
			$moveResult = $this->client->moveMails($ids, $folderFrom, $folderTo);
			if (!$moveResult->isSuccess() || !$this->client->getErrors()->isEmpty())
			{
				break;
			}
		}

		return $moveResult;
	}

	public function deleteMails($messages)
	{
		$result = $this->getFolderToMessagesMap($messages);

		foreach ($result->getData() as $folderName => $messageId)
		{
			$result = $this->client->delete($messageId, $folderName);
		}

		return $result;
	}

	public function syncMailbox()
	{
		if (!$this->client->authenticate($error))
		{
			return false;
		}

		$meta = $this->cacheMeta();
		if (false === $meta)
		{
			return false;
		}

		$count = 0;

		$queue = array(
			'inbox' => array(),
			MessageFolder::OUTCOME => array(),
			'other' => array(),
			MessageFolder::TRASH => array(),
			MessageFolder::SPAM => array(),
		);
		foreach ($meta as $dir => $item)
		{
			if (in_array($dir, (array) $this->mailbox['OPTIONS']['imap']['ignore']))
			{
				continue;
			}

			if ('inbox' == strtolower(reset($item['path'])))
			{
				$queue['inbox'][$dir] = $item;
			}
			else if (in_array($dir, (array) $this->mailbox['OPTIONS']['imap'][MessageFolder::OUTCOME]))
			{
				$queue[MessageFolder::OUTCOME][$dir] = $item;
			}
			else if (in_array($dir, (array) $this->mailbox['OPTIONS']['imap'][MessageFolder::TRASH]))
			{
				$queue[MessageFolder::TRASH][$dir] = $item;
			}
			else if (in_array($dir, (array) $this->mailbox['OPTIONS']['imap'][MessageFolder::SPAM]))
			{
				$queue[MessageFolder::SPAM][$dir] = $item;
			}
			else
			{
				$queue['other'][$dir] = $item;
			}
		}

		$meta = call_user_func_array('array_merge', $queue);

		foreach ($meta as $dir => $item)
		{
			if ($item['exists'] > 0)
			{
				$count += $this->syncDir($dir);

				if ($this->isTimeQuotaExceeded())
				{
					break;
				}
			}
		}

		if (!$this->isTimeQuotaExceeded())
		{
			$this->unregisterMessages(array(
				'!@DIR_MD5' => array_map(
					'md5',
					array_diff(
						array_keys($meta),
						(array) $this->mailbox['OPTIONS']['imap']['ignore']
					)
				),
			));

			foreach ($meta as $dir => $item)
			{
				$this->resyncDir($dir);

				if ($this->isTimeQuotaExceeded())
				{
					break;
				}
			}
		}

		return $count;
	}

	public function syncDir($dir)
	{
		$count = 0;

		if (in_array($dir, (array) $this->mailbox['OPTIONS']['imap']['ignore']))
		{
			return $count;
		}

		while ($range = $this->getSyncRange($dir, $uidtoken))
		{
			$reverse = $range[0] > $range[1];

			sort($range);

			$messages = $this->client->fetch(
				true,
				$dir,
				join(':', $range),
				'(UID FLAGS INTERNALDATE RFC822.SIZE BODY.PEEK[HEADER])',
				$error
			);

			if (empty($messages))
			{
				if (false === $messages)
				{
					$this->warnings->add($this->client->getErrors()->toArray());
				}
				else
				{
					// @TODO: log
				}

				break;
			}

			$reverse ? krsort($messages) : ksort($messages);

			$this->blacklistMessages($dir, $messages);

			$this->prepareMessages($dir, $uidtoken, $messages);

			foreach ($messages as $id => $item)
			{
				if ($this->syncMessage($dir, $uidtoken, $item))
				{
					$count++;
				}

				if ($this->isTimeQuotaExceeded())
				{
					break 2;
				}
			}
		}

		if (false === $range)
		{
			$this->warnings->add($this->client->getErrors()->toArray());
		}

		return $count;
	}

	public function resyncDir($dir)
	{
		if (in_array($dir, (array) $this->mailbox['OPTIONS']['imap']['ignore']))
		{
			$this->unregisterMessages(array(
				'=DIR_MD5' => md5($dir),
			));

			return;
		}

		$meta = $this->client->select($dir, $error);
		if (false === $meta)
		{
			$this->warnings->add($this->client->getErrors()->toArray());

			return;
		}

		$uidtoken = $meta['uidvalidity'];

		if ($meta['exists'] > 0)
		{
			if ($uidtoken > 0)
			{
				$this->unregisterMessages(array(
					'=DIR_MD5'  => md5($dir),
					'<DIR_UIDV' => $uidtoken,
				));
			}
		}
		else
		{
			$this->unregisterMessages(array(
				'=DIR_MD5' => md5($dir),
			));

			return;
		}

		$fetcher = function ($range) use ($dir)
		{
			$messages = $this->client->fetch(false, $dir, $range, '(UID FLAGS)', $error);

			if (empty($messages))
			{
				if (false === $messages)
				{
					$this->warnings->add($this->client->getErrors()->toArray());
				}
				else
				{
					// @TODO: log
				}

				return;
			}

			krsort($messages);

			return $messages;
		};

		$messages = $fetcher($meta['exists'] > 10000 ? '1,*' : '1:*');

		if (empty($messages))
		{
			return;
		}

		$range = array(
			reset($messages)['UID'],
			end($messages)['UID'],
		);
		sort($range);

		$this->unregisterMessages(array(
			'=DIR_MD5' => md5($dir),
			'>MSG_UID' => 0,
			array(
				'LOGIC' => 'OR',
				'<MSG_UID' => $range[0],
				'>MSG_UID' => $range[1],
			),
		));

		if (!($meta['exists'] > 10000))
		{
			$this->resyncMessages($dir, $uidtoken, $messages);

			return;
		}

		$range1 = $meta['exists'];
		while ($range1 > 0)
		{
			$rangeSize = $range1 > 10000 ? 8000 : $range1;
			$range0 = max($range1 - $rangeSize, 1);

			$messages = $fetcher(sprintf('%u:%u', $range0, $range1));

			if (empty($messages))
			{
				return;
			}

			$this->resyncMessages($dir, $uidtoken, $messages);

			if ($this->isTimeQuotaExceeded())
			{
				return;
			}

			$range1 -= $rangeSize;
		}
	}

	protected function blacklistMessages($dir, &$messages)
	{
		$trashDirs = (array) $this->mailbox['OPTIONS']['imap'][MessageFolder::TRASH];
		$spamDirs = (array) $this->mailbox['OPTIONS']['imap'][MessageFolder::SPAM];

		$targetDir = reset($spamDirs) ?: reset($trashDirs) ?: null;

		if (empty($targetDir) || in_array($dir, array_merge($trashDirs, $spamDirs)))
		{
			return;
		}

		$blacklist = array(
			'email'  => array(),
			'domain' => array(),
		);

		$res = Mail\BlacklistTable::getList(array(
			'select' => array('ITEM_TYPE', 'ITEM_VALUE'),
			'filter' => array(
				'=SITE_ID' => $this->mailbox['LID'],
				array(
					'LOGIC' => 'OR',
					'=MAILBOX_ID' => $this->mailbox['ID'],
					array(
						'=MAILBOX_ID' => 0,
						'@USER_ID' => array(0, $this->mailbox['USER_ID']),
					),
				),
			),
		));
		while ($item = $res->fetch())
		{
			if (Mail\Blacklist\ItemType::DOMAIN == $item['ITEM_TYPE'])
			{
				$blacklist['domain'][] = $item['ITEM_VALUE'];
			}
			else
			{
				$blacklist['email'][] = $item['ITEM_VALUE'];
			}
		}

		if (empty($blacklist['email']) && empty($blacklist['domain']))
		{
			return;
		}

		$targetMessages = array();

		foreach ($messages as $id => $item)
		{
			$parsedHeader = \CMailMessage::parseHeader($item['BODY[HEADER]'], $this->mailbox['LANG_CHARSET']);

			$parsedFrom = array_unique(array_map('strtolower', array_filter(array_merge(
				\CMailUtil::extractAllMailAddresses($parsedHeader->getHeader('FROM')),
				\CMailUtil::extractAllMailAddresses($parsedHeader->getHeader('REPLY-TO'))
			), 'trim')));

			if (!empty($blacklist['email']))
			{
				if (array_intersect($parsedFrom, $blacklist['email']))
				{
					$targetMessages[$id] = $item['UID'];

					continue;
				}
			}

			if (!empty($blacklist['domain']))
			{
				foreach ($parsedFrom as $email)
				{
					$domain = substr($email, strrpos($email, '@'));
					if (in_array($domain, $blacklist['domain']))
					{
						$targetMessages[$id] = $item['UID'];

						continue 2;
					}
				}
			}
		}

		if (!empty($targetMessages))
		{
			if ($this->client->moveMails($targetMessages, $dir, $targetDir)->isSuccess())
			{
				$messages = array_diff_key($messages, $targetMessages);
			}
		}
	}

	protected function prepareMessages($dir, $uidtoken, &$messages)
	{
		$excerpt = array();

		$range = array(
			reset($messages)['UID'],
			end($messages)['UID'],
		);
		sort($range);

		$result = $this->listMessages(array(
			'select' => array('ID'),
			'filter' => array(
				'=DIR_MD5' => md5($dir),
				'=DIR_UIDV' => $uidtoken,
				'>=MSG_UID' => $range[0],
				'<=MSG_UID' => $range[1],
			),
		), false);

		while ($item = $result->fetch())
		{
			$excerpt[] = $item['ID'];
		}

		$uids = array();
		$hashes = array();
		foreach ($messages as $id => $item)
		{
			$messageUid = md5(sprintf('%s:%u:%u', $dir, $uidtoken, $item['UID']));

			if (in_array($messageUid, $excerpt))
			{
				unset($messages[$id]);
				continue;
			}

			$excerpt[] = $uids[$id] = $messageUid;

			$hashes[$id] = md5(sprintf(
				'%s:%s:%u',
				trim($item['BODY[HEADER]']),
				$item['INTERNALDATE'],
				$item['RFC822.SIZE']
			));

			$messages[$id]['__internaldate'] = Main\Type\DateTime::createFromPhp(
				\DateTime::createFromFormat(
					'j-M-Y H:i:s O',
					ltrim(trim($item['INTERNALDATE']), '0')
				) ?: new \DateTime
			);

			$messages[$id]['__fields'] = array(
				'ID'           => $messageUid,
				'DIR_MD5'      => md5($dir),
				'DIR_UIDV'     => $uidtoken,
				'MSG_UID'      => $item['UID'],
				'INTERNALDATE' => $messages[$id]['__internaldate'],
				'IS_SEEN'      => preg_grep('/^ \x5c Seen $/ix', $item['FLAGS']) ? 'Y' : 'N',
				'HEADER_MD5'   => $hashes[$id],
				'MESSAGE_ID'   => 0,
			);

			if ($bxuid = preg_grep('/^bxuid:?[a-f0-9]+$/i', $item['FLAGS']))
			{
				$messages[$id]['__replaces'] = preg_replace('/^bxuid:?/i', '', end($bxuid));
			}
			else if (preg_match('/X-Bitrix-Mail-Message-UID:\s*([a-f0-9]+)/i', $item['BODY[HEADER]'], $matches))
			{
				$messages[$id]['__replaces'] = $matches[1];
			}
		}

		$hashesMap = array();
		foreach ($hashes as $id => $hash)
		{
			if (!array_key_exists($hash, $hashesMap))
			{
				$hashesMap[$hash] = array();
			}

			$hashesMap[$hash][] = $id;
		}

		$result = $this->listMessages(array(
			'select' => array('HEADER_MD5', 'MESSAGE_ID', 'DATE_INSERT'),
			'filter' => array(
				'@HEADER_MD5' => array_keys($hashesMap),
			),
		), false);

		while ($item = $result->fetch())
		{
			foreach ((array) $hashesMap[$item['HEADER_MD5']] as $id)
			{
				$messages[$id]['__created'] = $item['DATE_INSERT'];
				$messages[$id]['__fields']['MESSAGE_ID'] = $item['MESSAGE_ID'];
			}
		}

		$result = $this->listMessages(array(
			'select' => array('ID', 'MESSAGE_ID', 'DATE_INSERT'),
			'filter' => array(
				'@ID' => array_values($uids),
			),
		), false);

		while ($item = $result->fetch())
		{
			$id = array_search($item['ID'], $uids);
			$messages[$id]['__created'] = $item['DATE_INSERT'];
			$messages[$id]['__fields']['MESSAGE_ID'] = $item['MESSAGE_ID'];
			$messages[$id]['__replaces'] = $item['ID'];
		}
	}

	protected function resyncMessages($dir, $uidtoken, &$messages)
	{
		$excerpt = array();

		$range = array(
			reset($messages)['UID'],
			end($messages)['UID'],
		);
		sort($range);

		$result = $this->listMessages(array(
			'select' => array('ID', 'IS_SEEN'),
			'filter' => array(
				'=DIR_MD5' => md5($dir),
				'=DIR_UIDV' => $uidtoken,
				'>=MSG_UID' => $range[0],
				'<=MSG_UID' => $range[1],
			),
		), false);
		$oldMails = [];
		while ($item = $result->fetch())
		{
			$excerpt[$item['ID']] = $item['IS_SEEN'];
			$oldMails[$item['ID']] = $item;
		}

		$update = array(
			'Y' => array(),
			'N' => array(),
		);
		foreach ($messages as $id => $item)
		{
			$messageUid = md5(sprintf('%s:%u:%u', $dir, $uidtoken, $item['UID']));

			if (array_key_exists($messageUid, $excerpt))
			{
				if (!in_array($excerpt[$messageUid], array('S', 'U')))
				{
					$messageSeen = preg_grep('/^ \x5c Seen $/ix', $item['FLAGS']) ? 'Y' : 'N';
					if ($messageSeen != $excerpt[$messageUid])
					{
						$update[$messageSeen][] = $messageUid;
					}
				}

				unset($excerpt[$messageUid]);
			}
			else
			{
				/*
				addMessage2Log(
					sprintf(
						'IMAP: message lost (%u:%s:%u:%s)',
						$this->mailbox['ID'], $dir, $uidtoken, $item['UID']
					),
					'mail', 0, false
				);
				*/
			}
		}

		foreach ($update as $seen => $uids)
		{
			if (!empty($uids))
			{
				$mailsData = array_filter($oldMails, function ($oldEmailData) use ($uids)
				{
					return in_array($oldEmailData['ID'], $uids);
				});
				foreach ($mailsData as $index => $oldMail)
				{
					$mailsData[$index]['IS_SEEN'] = $seen;
				}
				$this->updateMessagesRegistry(
					array(
						'@ID' => $uids,
					),
					array(
						'IS_SEEN' => $seen,
					),
					$mailsData
				);
			}
		}

		if (!empty($excerpt))
		{
			$this->unregisterMessages(array(
				'@ID' => array_keys($excerpt),
			));
		}
	}

	protected function syncMessage($dir, $uidtoken, $message)
	{
		static $hashesMap = array();

		$fields = $message['__fields'];

		if ($fields['MESSAGE_ID'] > 0)
		{
			$hashesMap[$fields['HEADER_MD5']] = $fields['MESSAGE_ID'];
		}
		else
		{
			if (array_key_exists($fields['HEADER_MD5'], $hashesMap) && $hashesMap[$fields['HEADER_MD5']] > 0)
			{
				$fields['MESSAGE_ID'] = $hashesMap[$fields['HEADER_MD5']];
			}
		}

		if (!$this->registerMessage($fields, $message['__replaces']))
		{
			return false;
		}

		if (!empty($this->mailbox['OPTIONS']['sync_from']))
		{
			if ($message['__internaldate']->getTimestamp() < $this->mailbox['OPTIONS']['sync_from'])
			{
				return false;
			}
		}

		if (!empty($message['__created']) && !empty($this->mailbox['OPTIONS']['resync_from']))
		{
			if ($message['__created']->getTimestamp() < $this->mailbox['OPTIONS']['resync_from'])
			{
				return false;
			}
		}

		if ($fields['MESSAGE_ID'] > 0)
		{
			return true;
		}

		$body = $this->client->fetch(
			true,
			$dir,
			$message['UID'],
			'(BODY.PEEK[])',
			$error
		);

		$messageId = 0;

		if (!empty($body['BODY[]']))
		{
			$messageId = $this->cacheMessage(
				$body['BODY[]'],
				array(
					'outcome' => in_array($dir, $this->mailbox['OPTIONS']['imap'][MessageFolder::OUTCOME]),
					'seen'    => $fields['IS_SEEN'] == 'Y',
					'hash'    => $fields['HEADER_MD5'],
				)
			);
		}

		if ($messageId > 0)
		{
			$hashesMap[$fields['HEADER_MD5']] = $messageId;

			$this->linkMessage($fields['ID'], $messageId);
		}
		else
		{
			$this->unregisterMessages(array(
				'=ID' => $fields['ID'],
			));
		}

		return $messageId > 0;
	}

	protected function getSyncRange($dir, &$uidtoken)
	{
		$meta = $this->client->select($dir, $error);
		if (false === $meta)
		{
			$this->warnings->add($this->client->getErrors()->toArray());

			return null;
		}

		if (!($meta['exists'] > 0))
		{
			return null;
		}

		$uidtoken = $meta['uidvalidity'];

		$rangeGetter = function ($min, $max) use ($dir, $uidtoken, &$rangeGetter)
		{
			$size = $max - $min + 1;

			$set = array();
			$d = $size < 1000 ? 100 : pow(10, round(ceil(log10($size) - 0.7) / 2) * 2 - 2);
			for ($i = $min; $i <= $max; $i = $i + $d)
			{
				$set[] = $i;
			}

			if (count($set) > 1 && end($set) + 100 >= $max)
			{
				array_pop($set);
			}

			$set[] = $max;

			$set = $this->client->fetch(false, $dir, join(',', $set), '(UID)', $error);
			if (empty($set))
			{
				return false;
			}

			ksort($set);

			static $uidMin, $uidMax;

			if (!isset($uidMin, $uidMax))
			{
				$minmax = $this->getUidRange($dir, $uidtoken);

				if ($minmax)
				{
					$uidMin = $minmax['MIN'];
					$uidMax = $minmax['MAX'];
				}
				else
				{
					$uidMin = $uidMax = (end($set)['UID'] + 1);
				}
			}

			if (count($set) == 1)
			{
				$uid = reset($set)['UID'];

				if ($uid > $uidMax || $uid < $uidMin)
				{
					return array($uid, $uid);
				}
			}
			else if (end($set)['UID'] > $uidMax)
			{
				$max = current($set)['id'];
				do
				{
					$exmax = $max;

					$max = current($set)['id'];
					$min = prev($set)['id'];
				}
				while (current($set)['UID'] > $uidMax && prev($set) && next($set));

				if ($max - $min > 200)
				{
					return $rangeGetter($min, $max);
				}
				else
				{
					if ($set[$max]['UID'] - $uidMax < 100)
					{
						$max = $exmax;
					}

					return array(
						max($set[$min]['UID'], $uidMax + 1),
						$set[$max]['UID'],
					);
				}
			}
			else if (reset($set)['UID'] < $uidMin)
			{
				$min = current($set)['id'];
				do
				{
					$exmin = $min;

					$min = current($set)['id'];
					$max = next($set)['id'];
				}
				while (current($set)['UID'] < $uidMin && next($set) && prev($set));

				if ($max - $min > 200)
				{
					return $rangeGetter($min, $max);
				}
				else
				{
					if ($uidMin - $set[$min]['UID'] < 100)
					{
						$min = $exmin;
					}

					return array(
						min($set[$max]['UID'], $uidMin - 1),
						$set[$min]['UID'],
					);
				}
			}

			return null;
		};

		return $rangeGetter(1, $meta['exists']);
	}

	protected function getUidRange($dir, $uidtoken)
	{
		$filter = array(
			'=DIR_MD5' => md5($dir),
			'=DIR_UIDV' => $uidtoken,
			'>MSG_UID' => 0,
		);

		$min = $this->listMessages(
			array(
				'select' => array(
					'MIN' => 'MSG_UID',
				),
				'filter' => $filter,
				'order' => array(
					'MSG_UID' => 'ASC',
				),
				'limit' => 1,
			),
			false
		)->fetch();

		$max = $this->listMessages(
			array(
				'select' => array(
					'MAX' => 'MSG_UID',
				),
				'filter' => $filter,
				'order' => array(
					'MSG_UID' => 'DESC',
				),
				'limit' => 1,
			),
			false
		)->fetch();

		if ($min && $max)
		{
			return array(
				'MIN' => $min['MIN'],
				'MAX' => $max['MAX'],
			);
		}

		return null;
	}

}
