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
 *      \file       htdocs/api/class/api_appidentifier.class.php
 *      \ingroup    api
 *      \brief      API endpoint to secure the API with a unique token per application installation and user.
 *
 *                  The application provides its name (and optionally its version) and the API returns the
 *                  stateless UUID that must be sent back as the "X-Identifier" header on each subsequent API call
 *                  so the access can be validated against the UUID bound to the API token.
 */

require_once DOL_DOCUMENT_ROOT.'/api/class/api.class.php';
require_once DOL_DOCUMENT_ROOT.'/api/class/appidentifier.class.php';

/**
 * API endpoint to compute the stateless application installation identifier.
 *
 * @access protected
 * @class  DolibarrApiAccess {@requires user,external}
 */
class Appidentifier extends DolibarrApi
{
	/**
	 * Constructor of the class
	 */
	public function __construct()
	{
		global $db;
		$this->db = $db;
	}

	/**
	 * Get the stateless UUID bound to the current user and the given application name.
	 *
	 * The UUID is derived (stateless) from: app_name + fk_user + instance unique id (installation secret).
	 * The client application must send it back through the "X-Identifier" HTTP header on each API call. The
	 * server validates it against the app_uuid stored on the API token (see DolibarrApiAccess::__isAllowed).
	 *
	 * @param   string  $app_name       Name of the client application / dapp installation
	 * @param   string  $app_version    Version of the client application (optional, only used for logging)
	 * @return  array                   Response with the derived identifier
	 * @phan-return array{success:array{code:int, identifier:string, fk_user:int}}
	 * @phpstan-return array{success:array{code:int, identifier:string, fk_user:int}}
	 *
	 * @url GET /identifier
	 */
	public function identifier($app_name, $app_version = '')
	{
		global $user;

		if (empty($app_name)) {
			throw new RestException(400, "The app_name parameter is required.");
		}

		$app_name = dol_string_nounprintableascii($app_name, 1);
		$app_version = dol_string_nounprintableascii($app_version, 1);

		$identifier = ApiAppIdentifier::deriveUuid($app_name, $user->id);

		return array(
			'success' => array(
				'code' => 200,
				'identifier' => $identifier,
				'fk_user' => $user->id,
			),
		);
	}
}
