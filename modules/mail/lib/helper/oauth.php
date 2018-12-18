<?php

namespace Bitrix\Mail\Helper;

use Bitrix\Main;
use Bitrix\Mail;

abstract class OAuth
{

	protected $oauthEntity;

	protected $service, $storedUid;

	public static function getKnownServices()
	{
		static $knownServices;

		if (is_null($knownServices))
		{
			$knownServices = array(
				OAuth\Google::getServiceName(),
				OAuth\LiveId::getServiceName(),
				OAuth\Yandex::getServiceName(),
				//OAuth\Mailru::getServiceName(),
			);
		}

		return $knownServices;
	}

	public static function getInstance($service = null)
	{
		if (get_called_class() != get_class())
		{
			$className = get_called_class();
			$service = $className::getServiceName();
		}
		else
		{
			if (!in_array($service, self::getKnownServices()))
			{
				return false;
			}

			$className = sprintf('%s\OAuth\%s', __NAMESPACE__, $service);
		}

		if (!Main\Loader::includeModule('socialservices'))
		{
			return false;
		}

		$instance = new $className;

		$instance->service = $service;
		$instance->storedUid = sprintf('%x%x', time(), rand(0, 0xffffffff));

		if (!$instance->check())
		{
			return false;
		}

		return $instance;
	}

	public static function getInstanceByMeta($meta)
	{
		if ($meta = static::parseMeta($meta))
		{
			if ($instance = self::getInstance($meta['service']))
			{
				if ('oauthb' == $meta['type'])
				{
					$instance->storedUid = $meta['key'];
				}

				return $instance;
			}
		}
	}

	public function buildMeta()
	{
		return sprintf(
			"\x00oauthb\x00%s\x00%s",
			$this->getServiceName(),
			$this->getStoredUid()
		);
	}

	public static function parseMeta($meta)
	{
		$regex = sprintf(
			'/^\x00(oauthb)\x00(%s)\x00([a-f0-9]+)$/',
			join(
				'|',
				array_map(
					function ($item)
					{
						return preg_quote($item, '/');
					},
					self::getKnownServices()
				)
			)
		);

		if (!preg_match($regex, $meta, $matches))
		{
			if (!preg_match('/^\x00(oauth)\x00(google|liveid)\x00(\d+)$/', $meta, $matches))
			{
				return null;
			}
		}

		return array(
			'type' => $matches[1],
			'service' => $matches[2],
			'key' => $matches[3],
		);
	}

	private static function getSocservToken($service, $key)
	{
		if (Main\Loader::includeModule('socialservices'))
		{
			switch ($service)
			{
				case 'google':
					$oauthClient = new \CSocServGoogleOAuth($key);
					$oauthClient->getUrl('modal', array('https://mail.google.com/'));
					break;
				case 'liveid':
					$oauthClient = new \CSocServLiveIDOAuth($key);
					$oauthClient->getUrl('modal', array('wl.imap', 'wl.offline_access'));
					break;
			}

			if (!empty($oauthClient))
			{
				return $oauthClient->getStorageToken() ?: false;
			}
		}

		return null;
	}

	public static function getTokenByMeta($meta)
	{
		if ($meta = static::parseMeta($meta))
		{
			if ('oauthb' == $meta['type'])
			{
				if ($oauthHelper = self::getInstance($meta['service']))
				{
					return $oauthHelper->getStoredToken($meta['key']) ?: false;
				}
			}
			else if ('oauth' == $meta['type'])
			{
				return self::getSocservToken($meta['service'], $meta['key']);
			}
		}
	}

	public static function getUserDataByMeta($meta, $secure = true)
	{
		if ($meta = static::parseMeta($meta))
		{
			if ($oauthHelper = self::getInstance($meta['service']))
			{
				if ('oauthb' == $meta['type'])
				{
					$oauthHelper->getStoredToken($meta['key']);
				}
				else if ('oauth' == $meta['type'])
				{
					if ($token = self::getSocservToken($meta['service'], $meta['key']))
					{
						$oauthHelper->getOAuthEntity()->setToken($token);
					}
				}

				return $oauthHelper->getUserData($secure);
			}
		}

		return null;
	}

	public function getOAuthEntity()
	{
		return $this->oauthEntity;
	}

	public function getStoredUid()
	{
		return $this->storedUid;
	}

	public function getRedirect($final = true)
	{
		return isModuleInstalled('bitrix24') && !$final
			? $this->getControllerUrl() . '/redirect.php'
			: \CHttp::urn2uri('/bitrix/tools/mail_oauth.php');
	}

	public function getUrl()
	{
		global $APPLICATION;

		\CSocServAuthManager::setUniqueKey();

		if (isModuleInstalled('bitrix24'))
		{
			$state = sprintf(
				'%s?%s',
				$this->getRedirect(),
				http_build_query(array(
					'check_key' => $_SESSION['UNIQUE_KEY'],
					'dummy' => 'https://dummy.bitrix24.com/',
					'state' => rawurlencode(http_build_query(array(
						'service' => $this->service,
						'uid' => $this->storedUid,
					))),
				))
			);
		}
		else
		{
			$state = http_build_query(array(
				'check_key' => $_SESSION['UNIQUE_KEY'],
				'service' => $this->service,
				'uid' => $this->storedUid,
			));
		}

		return $this->oauthEntity->getAuthUrl($this->getRedirect(false), $state);
	}

	public function getStoredToken($uid = null)
	{
		$token = null;

		if (!empty($uid))
		{
			$this->storedUid = $uid;
		}

		$item = Mail\Internals\OAuthTable::getList(array(
			'filter' => array(
				'=UID' => $this->storedUid,
			),
			'order' => array(
				'ID' => 'DESC',
			),
		))->fetch();

		if (!empty($item))
		{
			$this->oauthEntity->setToken($token = $item['TOKEN']);

			if (empty($token) || $item['TOKEN_EXPIRES'] > 0 && $item['TOKEN_EXPIRES'] < time())
			{
				$this->oauthEntity->setToken(null);

				if (!empty($item['REFRESH_TOKEN']))
				{
					$this->oauthEntity->getNewAccessToken($item['REFRESH_TOKEN']);
				}

				$token = $this->oauthEntity->getToken();
			}
		}

		return $token;
	}

	public function getAccessToken($code = null)
	{
		if ($code)
		{
			$this->oauthEntity->setCode($code);
		}

		$oauthData = $_SESSION['OAUTH_DATA'];

		$result = $this->oauthEntity->getAccessToken($this->getRedirect(false));

		$_SESSION['OAUTH_DATA'] = $oauthData;

		return $result;
	}

	public function getUserData($secure = true)
	{
		try
		{
			$userData = $this->oauthEntity->getCurrentUser();
		}
		catch (Main\SystemException $e)
		{
		}

		if (!empty($userData))
		{
			return array_merge(
				$this->mapUserData($userData),
				$secure ? array() : array('__data' => $userData)
			);
		}
	}

	abstract protected function mapUserData(array $userData);

	public static function getServiceName()
	{
		throw new Main\ObjectException('abstract');
	}

	public function handleResponse($state)
	{
		$this->storedUid = $state['uid'];

		if (!empty($_REQUEST['code']) && \CSocServAuthManager::checkUniqueKey())
		{
			$this->getAccessToken($_REQUEST['code']);

			if ($userData = $this->getUserData(false))
			{
				Mail\Internals\OAuthTable::add(array(
					'UID' => $this->getStoredUid(),
					'TOKEN' => $userData['__data']['access_token'],
					'REFRESH_TOKEN' => $userData['__data']['refresh_token'],
					'TOKEN_EXPIRES' => $userData['__data']['expires_in'],
				));

				unset($userData['__data']);

				?>

				<script type="text/javascript">

				targetWindow = window.opener ? window.opener : window;

				targetWindow.BX.onCustomEvent(
					'OnMailOAuthBCompleted',
					[
						'<?=\CUtil::jsEscape($this->getStoredUid()) ?>',
						'<?=\CUtil::jsEscape($this->getUrl()) ?>',
						<?=Main\Web\Json::encode($userData) ?>
					],
					true
				);

				if (targetWindow !== window)
				{
					window.close();
				}

				</script>

				<?
			}
		}
	}

}
