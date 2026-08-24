<?php
/* Copyright (C) 2025      Laurent Destailleur  <eldy@users.sourceforge.net>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 *      \file       htdocs/api/class/appidentifier.class.php
 *      \ingroup    api
 *      \brief      Helper to secure the API with a unique token per application installation and user.
 *
 *                  The control mode is driven by the constant API_ENABLE_CONTROL_APP_CONNEXION:
 *                  - 0: Disabled (standard token behavior).
 *                  - 1: Log only: the app signature, version, type and last IP are memorized on each API access,
 *                       but the access is never blocked.
 *                  - 2: Strict: the first API call that provides an app signature/instance binds it to the token
 *                       (handshake). Subsequent calls must provide the same signature and instance token, otherwise
 *                       the access is denied (401).
 */

require_once DOL_DOCUMENT_ROOT.'/core/lib/functions.lib.php';

/**
 * Helper to secure the API with a unique token per application installation and user.
 *
 * Works with the llx_oauth_token table (service='dolibarr_rest_api') when the constant API_IN_TOKEN_TABLE is set.
 */
class ApiAppIdentifier
{
	/** Control mode: disabled (standard token behavior). */
	public const MODE_DISABLED = 0;
	/** Control mode: log only (memorize app metadata without blocking). */
	public const MODE_LOG_ONLY = 1;
	/** Control mode: strict (handshake + signature/instance validation). */
	public const MODE_STRICT = 2;
	/** Control mode: admin validation (handshake on first connection, then blocked until admin validates). */
	public const MODE_ADMIN_VALIDATION = 3;

	/**
	 * @var DoliDB	Database handler
	 */
	public $db;

	/**
	 * Constructor
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Return the current external application control mode.
	 *
	 * @return int  0 (disabled), 1 (log only) or 2 (strict)
	 */
	public static function getControlMode()
	{
		return (int) getDolGlobalString('API_ENABLE_CONTROL_APP_CONNEXION', '0');
	}

	/**
	 * Read the client application signature from the request headers.
	 *
	 * @return string
	 */
	public static function getClientAppSignature()
	{
		$appSignature = '';
		if (isset($_SERVER['HTTP_X_APP_SIGNATURE'])) {
			$appSignature = $_SERVER['HTTP_X_APP_SIGNATURE'];
		} elseif (isset($_SERVER['HTTP_XAPPSIGNATURE'])) {
			$appSignature = $_SERVER['HTTP_XAPPSIGNATURE'];
		}

		return dol_string_nounprintableascii($appSignature, 1);
	}

	/**
	 * Read the client application instance/device token from the request headers.
	 *
	 * @return string
	 */
	public static function getClientAppInstance()
	{
		$appInstance = '';
		if (isset($_SERVER['HTTP_X_APP_INSTANCE'])) {
			$appInstance = $_SERVER['HTTP_X_APP_INSTANCE'];
		} elseif (isset($_SERVER['HTTP_XAPPINSTANCE'])) {
			$appInstance = $_SERVER['HTTP_XAPPINSTANCE'];
		}

		return dol_string_nounprintableascii($appInstance, 1);
	}

	/**
	 * Read the client application type from the request headers.
	 *
	 * @return string
	 */
	public static function getClientAppType()
	{
		$appType = '';
		if (isset($_SERVER['HTTP_X_APP_TYPE'])) {
			$appType = $_SERVER['HTTP_X_APP_TYPE'];
		} elseif (isset($_SERVER['HTTP_XAPPTYPE'])) {
			$appType = $_SERVER['HTTP_XAPPTYPE'];
		}

		return dol_string_nounprintableascii($appType, 1);
	}

	/**
	 * Read the client application name from the request headers.
	 *
	 * @return string
	 */
	public static function getClientAppName()
	{
		$appName = '';
		if (isset($_SERVER['HTTP_X_APP_NAME'])) {
			$appName = $_SERVER['HTTP_X_APP_NAME'];
		} elseif (isset($_SERVER['HTTP_XAPPNAME'])) {
			$appName = $_SERVER['HTTP_XAPPNAME'];
		}

		return dol_string_nounprintableascii($appName, 1);
	}

	/**
	 * Read the client application version from the request headers.
	 *
	 * @return string
	 */
	public static function getClientAppVersion()
	{
		$appVersion = '';
		if (isset($_SERVER['HTTP_X_APP_VERSION'])) {
			$appVersion = $_SERVER['HTTP_X_APP_VERSION'];
		} elseif (isset($_SERVER['HTTP_XAPPVERSION'])) {
			$appVersion = $_SERVER['HTTP_XAPPVERSION'];
		}

		return dol_string_nounprintableascii($appVersion, 1);
	}

	/**
	 * Update the access metadata (last access date, last IP, app name, app version, app type) of a token row.
	 *
	 * This is called at each successful API access to memorize the application installation that used the token.
	 *
	 * @param   int     $tokenId    Id of the oauth_token row
	 * @param   string  $appName    Application name sent by the client
	 * @param   string  $appVersion Application version sent by the client
	 * @param   string  $appType    Application type sent by the client
	 * @return  int                 0 if OK, <0 if KO
	 */
	public function updateAccessMetadata($tokenId, $appName, $appVersion, $appType = '')
	{
		if (empty($tokenId) || $tokenId <= 0) {
			return -1;
		}

		$ipremote = getUserRemoteIP();

		$sql = "UPDATE ".$this->db->prefix()."oauth_token SET";
		$sql .= " lastaccess = '".$this->db->idate(dol_now('gmt'))."',";
		$sql .= " last_ip = ".($ipremote ? "'".$this->db->escape($ipremote)."'" : "NULL");
		if ($appName !== '') {
			$sql .= ", app_name = '".$this->db->escape($appName)."'";
		}
		if ($appVersion !== '') {
			$sql .= ", app_version = '".$this->db->escape($appVersion)."'";
		}
		if ($appType !== '') {
			$sql .= ", app_type = '".$this->db->escape($appType)."'";
		}
		$sql .= " WHERE rowid = ".((int) $tokenId);
		$sql .= " AND service = 'dolibarr_rest_api'";

		$resql = $this->db->query($sql);
		if (!$resql) {
			dol_syslog("ApiAppIdentifier::updateAccessMetadata error: ".$this->db->error(), LOG_ERR);
			return -2;
		}

		return 0;
	}

	/**
	 * Fetch a token row by its rowid and return the application metadata stored on it.
	 *
	 * @param   int     $tokenId    Id of the oauth_token row
	 * @return  array<string,string|int>|null   Associative array with app metadata, or null if not found
	 */
	public function fetchTokenMetadata($tokenId)
	{
		if (empty($tokenId) || $tokenId <= 0) {
			return null;
		}

		$sql = "SELECT oat.rowid, oat.app_signature, oat.app_instance_token, oat.app_type, oat.app_name, oat.app_version, oat.last_ip, oat.fk_user";
		$sql .= " FROM ".$this->db->prefix()."oauth_token AS oat";
		$sql .= " WHERE oat.rowid = ".((int) $tokenId);
		$sql .= " AND oat.service = 'dolibarr_rest_api'";

		$resql = $this->db->query($sql);
		if (!$resql) {
			dol_syslog("ApiAppIdentifier::fetchTokenMetadata error: ".$this->db->error(), LOG_ERR);
			return null;
		}
		if ($this->db->num_rows($resql) != 1) {
			return null;
		}

		$obj = $this->db->fetch_object($resql);

		return array(
			'rowid' => (int) $obj->rowid,
			'app_signature' => $obj->app_signature !== null ? (string) $obj->app_signature : '',
			'app_instance_token' => $obj->app_instance_token !== null ? (string) $obj->app_instance_token : '',
			'app_type' => $obj->app_type !== null ? (string) $obj->app_type : '',
			'app_name' => $obj->app_name !== null ? (string) $obj->app_name : '',
			'app_version' => $obj->app_version !== null ? (string) $obj->app_version : '',
			'last_ip' => $obj->last_ip !== null ? (string) $obj->last_ip : '',
			'fk_user' => (int) $obj->fk_user,
		);
	}

	/**
	 * Bind the application signature/instance/type to a token row (handshake).
	 *
	 * Called on the first strict-mode API call that provides an app signature: the signature, instance token and
	 * type are stored on the token so subsequent calls can be validated against them.
	 *
	 * @param   int     $tokenId        Id of the oauth_token row
	 * @param   string  $appSignature   Application signature sent by the client
	 * @param   string  $appInstance    Application instance/device token sent by the client
	 * @param   string  $appType         Application type sent by the client
	 * @return  int                     0 if OK, <0 if KO
	 */
	public function bindApplication($tokenId, $appSignature, $appInstance, $appType = '')
	{
		if (empty($tokenId) || $tokenId <= 0) {
			return -1;
		}

		$sql = "UPDATE ".$this->db->prefix()."oauth_token SET";
		$sql .= " app_signature = ".($appSignature !== '' ? "'".$this->db->escape($appSignature)."'" : "NULL");
		$sql .= ", app_instance_token = ".($appInstance !== '' ? "'".$this->db->escape($appInstance)."'" : "NULL");
		if ($appType !== '') {
			$sql .= ", app_type = '".$this->db->escape($appType)."'";
		}
		$sql .= " WHERE rowid = ".((int) $tokenId);
		$sql .= " AND service = 'dolibarr_rest_api'";

		$resql = $this->db->query($sql);
		if (!$resql) {
			dol_syslog("ApiAppIdentifier::bindApplication error: ".$this->db->error(), LOG_ERR);
			return -2;
		}

		return 0;
	}

	/**
	 * Validate that the client provided app signature/instance match the ones bound to the token (strict mode).
	 *
	 * When the token has no app_signature bound yet (first strict call), the handshake is performed: the provided
	 * signature/instance are bound to the token and access is allowed. When a signature is already bound, the client
	 * must provide the exact same signature and instance token.
	 *
	 * @param   int     $tokenId        Id of the oauth_token row
	 * @param   string  $appSignature   Application signature sent by the client
	 * @param   string  $appInstance    Application instance/device token sent by the client
	 * @param   int     $controlMode    Control mode (MODE_STRICT or MODE_ADMIN_VALIDATION). Defaults to strict.
	 * @return  array{0:bool,1:string}  [true if access allowed, message]
	 */
	public function validateApplication($tokenId, $appSignature, $appInstance, $controlMode = ApiAppIdentifier::MODE_STRICT)
	{
		$metadata = $this->fetchTokenMetadata($tokenId);
		if (!is_array($metadata)) {
			// Token row not found, do not block here: the token lookup itself already failed upstream.
			return array(true, '');
		}

		$storedSignature = $metadata['app_signature'];
		$storedInstance = $metadata['app_instance_token'];

		// Handshake: no signature bound yet. Bind the provided one if any.
		if ($storedSignature === '') {
			if ($appSignature !== '') {
				$this->bindApplication($tokenId, $appSignature, $appInstance, ApiAppIdentifier::getClientAppType());
				dol_syslog("ApiAppIdentifier::validateApplication: handshake done, app signature/instance bound to token ".$tokenId, LOG_INFO);
			}
			// In admin-validation mode, the app is now bound but pending validation.
			if ($controlMode == ApiAppIdentifier::MODE_ADMIN_VALIDATION) {
				return array(false, 'ApiErrorAppNotValidated');
			}
			return array(true, '');
		}

		// Admin-validation mode: access is blocked until the app is validated by an administrator.
		if ($controlMode == ApiAppIdentifier::MODE_ADMIN_VALIDATION) {
			if ((int) $metadata['app_status'] !== 1) {
				dol_syslog("ApiAppIdentifier::validateApplication KO: app is bound but pending administrator validation for token ".$tokenId, LOG_NOTICE);
				return array(false, 'ApiErrorAppNotValidated');
			}
			return array(true, '');
		}

		// Strict validation: the provided signature and instance must match the bound ones.
		if ($appSignature === '' || !hash_equals($storedSignature, $appSignature)) {
			dol_syslog("ApiAppIdentifier::validateApplication KO: app signature does not match the one bound to the token", LOG_NOTICE);
			return array(false, 'ApiErrorAppMismatch');
		}
		if ($storedInstance !== '' && ($appInstance === '' || !hash_equals($storedInstance, $appInstance))) {
			dol_syslog("ApiAppIdentifier::validateApplication KO: app instance token does not match the one bound to the token", LOG_NOTICE);
			return array(false, 'ApiErrorAppMismatch');
		}

		return array(true, '');
	}

	/**
	 * Set the validation status of the application bound to a token (admin validation).
	 *
	 * @param   int     $tokenId    Id of the oauth_token row
	 * @param   int     $status     New status: 0=pending, 1=validated by admin
	 * @return  int                 0 if OK, <0 if KO
	 */
	public function setAppStatus($tokenId, $status)
	{
		if (empty($tokenId) || $tokenId <= 0) {
			return -1;
		}

		$sql = "UPDATE ".$this->db->prefix()."oauth_token SET app_status = ".((int) $status);
		$sql .= " WHERE rowid = ".((int) $tokenId);
		$sql .= " AND service = 'dolibarr_rest_api'";

		$resql = $this->db->query($sql);
		if (!$resql) {
			dol_syslog("ApiAppIdentifier::setAppStatus error: ".$this->db->error(), LOG_ERR);
			return -2;
		}

		return 0;
	}
}
