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
 *      \brief      API endpoint to introspect the external application control mode.
 *
 *                  Returns the control mode (API_ENABLE_CONTROL_APP_CONNEXION) and the list of HTTP headers the
 *                  client application is expected to send (X-App-Signature, X-App-Instance, X-App-Type,
 *                  X-App-Name, X-App-Version) so the client can adapt its behavior.
 */

require_once DOL_DOCUMENT_ROOT.'/api/class/api.class.php';
require_once DOL_DOCUMENT_ROOT.'/api/class/appidentifier.class.php';
require_once DOL_DOCUMENT_ROOT.'/includes/restler/framework/Luracast/Restler/RestException.php';

use Luracast\Restler\RestException;

/**
 * API endpoint to introspect the external application control mode.
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
	 * Get the external application control mode and the expected client headers.
	 *
	 * @return  array
	 * @phan-return array{success:array{code:int, control_mode:int, expected_headers:string[]}}
	 * @phpstan-return array{success:array{code:int, control_mode:int, expected_headers:string[]}}
	 *
	 * @url GET /mode
	 */
	public function mode()
	{
		return array(
			'success' => array(
				'code' => 200,
				'control_mode' => ApiAppIdentifier::getControlMode(),
				'expected_headers' => array('X-App-Signature', 'X-App-Instance', 'X-App-Type', 'X-App-Name', 'X-App-Version'),
			),
		);
	}
}
