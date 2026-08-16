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
 *                  The "X-Identifier" UUID is stateless: it is derived from the application name,
 *                  the user id and the Dolibarr instance unique id (secret of the installation).
 *                  It can be recomputed on both sides without any storage round-trip and is used
 *                  to validate that a token is used from the application installation it was bound to.
 */

require_once DOL_DOCUMENT_ROOT.'/core/lib/functions.lib.php';

/**
 * Helper to secure the API with a unique token per application installation and user.
 *
 * The UUID is derived (stateless) from: app_name + fk_user + instance unique id (installation secret).
 * It is stored on the token row so the access can be validated and the metadata (app name, version, IP)
 * memorized at each API call.
 */
class ApiAppIdentifier
{
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
	 * Derive the stateless UUID bound to an application installation and a user.
	 *
	 * The UUID is stable for a given (app_name, app_install_id, fk_user, dolibarr installation) tuple, so the
	 * client application and the server can recompute it without exchanging it again. It is meant to be
	 * sent back by the client through the "X-Identifier" HTTP header at each API call.
	 *
	 * @param   string  $appName        Name of the client application / dapp installation
	 * @param   int     $fkUser         Id of the user the token is bound to
	 * @param   string  $appInstallId   Unique installation identifier of the client app (dapp). '' to ignore.
	 * @return  string                   The derived UUID (64 hex chars)
	 */
	public static function deriveUuid($appName, $fkUser, $appInstallId = '')
	{
		global $conf;

		$instanceUniqueId = '';
		if (is_object($conf) && is_object($conf->file) && !empty($conf->file->instance_unique_id)) {
			$instanceUniqueId = $conf->file->instance_unique_id;
		}

		// A stable, non-printable-ascii-safe payload.
		$payload = 'dolibarr_app_identifier|'.(string) $appName.'|'.(string) $appInstallId.'|'.(int) $fkUser.'|'.$instanceUniqueId;

		// Returns a stable 64 chars hex string (sha256, no salt). It is used only as a derived identifier, not as a secret.
		return dol_hash($payload, '5', 1);
	}

	/**
	 * Read the "X-Identifier" value from the current request headers.
	 *
	 * @return string	The UUID sent by the client, '' if not provided.
	 */
	public static function getClientIdentifier()
	{
		$identifier = '';
		if (isset($_SERVER['HTTP_X_IDENTIFIER'])) {
			$identifier = $_SERVER['HTTP_X_IDENTIFIER'];
		} elseif (isset($_SERVER['HTTP_XIDENTIFIER'])) {
			$identifier = $_SERVER['HTTP_XIDENTIFIER'];
		} else {
			$headers = getallheaders();
			// getallheaders() normalizes header names in a php/web-server dependent way.
			if (!empty($headers)) {
				foreach ($headers as $name => $value) {
					if (strcasecmp((string) $name, 'X-Identifier') === 0) {
						$identifier = $value;
						break;
					}
				}
			}
		}

		return dol_string_nounprintableascii($identifier, 1);
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
	 * Read the unique installation identifier of the client app from the request headers.
	 *
	 * @return string
	 */
	public static function getClientInstallId()
	{
		$installId = '';
		if (isset($_SERVER['HTTP_X_APP_INSTALL_ID'])) {
			$installId = $_SERVER['HTTP_X_APP_INSTALL_ID'];
		} elseif (isset($_SERVER['HTTP_XAPPINSTALLID'])) {
			$installId = $_SERVER['HTTP_XAPPINSTALLID'];
		}

		return dol_string_nounprintableascii($installId, 1);
	}

	/**
	 * Update the access metadata (last access date, last IP, app name, app version) of a token row.
	 *
	 * This is called at each successful API access to memorize the application installation that
	 * used the token. The app_uuid is validated separately against the client provided X-Identifier.
	 *
	 * @param   int     $tokenId    Id of the oauth_token row
	 * @param   string  $appName    Application name sent by the client
	 * @param   string  $appVersion Application version sent by the client
	 * @return  int                 0 if OK, <0 if KO
	 */
	public function updateAccessMetadata($tokenId, $appName, $appVersion)
	{
		global $conf;

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
	 * @return  array<string,string|int>|null   Associative array with app_uuid, app_name, app_version, last_ip, fk_user, or null if not found
	 */
	public function fetchTokenMetadata($tokenId)
	{
		if (empty($tokenId) || $tokenId <= 0) {
			return null;
		}

		$sql = "SELECT oat.rowid, oat.app_uuid, oat.app_name, oat.app_version, oat.last_ip, oat.fk_user";
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
			'app_uuid' => $obj->app_uuid !== null ? (string) $obj->app_uuid : '',
			'app_name' => $obj->app_name !== null ? (string) $obj->app_name : '',
			'app_version' => $obj->app_version !== null ? (string) $obj->app_version : '',
			'last_ip' => $obj->last_ip !== null ? (string) $obj->last_ip : '',
			'fk_user' => (int) $obj->fk_user,
		);
	}

	/**
	 * Validate that the client provided X-Identifier matches the UUID bound to the token.
	 *
	 * When the token has no app_uuid stored (token created before the feature), the validation
	 * is skipped to keep backward compatibility. When an app_uuid is stored but no client
	 * identifier is provided, access is denied.
	 *
	 * @param   int     $tokenId            Id of the oauth_token row
	 * @param   string  $clientIdentifier   UUID sent by the client through X-Identifier
	 * @return  bool                        true if access is allowed, false otherwise
	 */
	public function validateIdentifier($tokenId, $clientIdentifier)
	{
		$metadata = $this->fetchTokenMetadata($tokenId);
		if (!is_array($metadata)) {
			// Token row not found, do not block here: the token lookup itself already failed upstream.
			return true;
		}

		$storedUuid = $metadata['app_uuid'];
		if ($storedUuid === '') {
			// Backward compatibility: token created before the feature, no UUID bound.
			return true;
		}

		if ($clientIdentifier === '') {
			dol_syslog("ApiAppIdentifier::validateIdentifier KO: token has a bound UUID but no X-Identifier header was provided", LOG_NOTICE);
			return false;
		}

		if (!hash_equals($storedUuid, $clientIdentifier)) {
			dol_syslog("ApiAppIdentifier::validateIdentifier KO: X-Identifier does not match the UUID bound to the token", LOG_NOTICE);
			return false;
		}

		return true;
	}
}
