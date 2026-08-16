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
 * or see https://www.gnu.org/licenses/
 */

/**
 *      \file       test/phpunit/ApiAppControlTest.php
 *      \ingroup    test
 *      \brief      PHPUnit test for the external application control (handshake)
 *      \remarks    To run this script as CLI:  phpunit ApiAppControlTest.php
 */

global $conf, $user, $langs, $db;

//define('TEST_DB_FORCE_TYPE', 'mysql'); // This is to force using mysql driver
require_once dirname(__FILE__).'/../../htdocs/master.inc.php';
require_once dirname(__FILE__).'/../../htdocs/api/class/appidentifier.class.php';
require_once dirname(__FILE__).'/CommonClassTest.class.php';

if (empty($user->id)) {
	print "Load permissions for admin user nb 1\n";
	$user->fetch(1);
	$user->loadRights();
}

$conf->global->MAIN_DISABLE_ALL_MAILS = 1;

/**
 * Class for PHPUnit tests
 *
 * @backupGlobals disabled
 * @backupStaticAttributes enabled
 * @remarks backupGlobals must be disabled to have db,conf,user and lang not erased.
 */
class ApiAppControlTest extends CommonClassTest
{
	/**
	 * Id of the test token created in setUpBeforeClass.
	 *
	 * @var int
	 */
	private static $tokenId = 0;

	/**
	 * A stable signature used for the tests.
	 */
	private const TEST_SIGNATURE = 'test_app_signature_123456';

	/**
	 * A stable instance token used for the tests.
	 */
	private const TEST_INSTANCE = 'test_app_instance_654321';

	/**
	 * Create a test token row before the tests run.
	 *
	 * @return void
	 */
	public static function setUpBeforeClass(): void
	{
		global $db;
		parent::setUpBeforeClass();

		// Insert a test token row (the transaction is rolled back at the end, so no persistent data).
		$sql = "INSERT INTO ".$db->prefix()."oauth_token (service, token, tokenstring, state, fk_user, entity, datec)";
		$sql .= " VALUES ('dolibarr_rest_api', '".$db->escape(dolEncrypt(self::TEST_SIGNATURE, '', '', 'dolibarr'))."',";
		$sql .= " '".$db->escape(dolEncrypt(self::TEST_SIGNATURE, '', '', 'dolibarr'))."', 0, 1, 1, '".$db->idate(dol_now())."')";
		$resql = $db->query($sql);
		if ($resql) {
			self::$tokenId = (int) $db->last_insert_id($db->prefix()."oauth_token");
		}
	}

	/**
	 * Test that the control mode is read from the configuration constant.
	 *
	 * @return void
	 */
	public function testGetControlMode()
	{
		global $conf;
		$conf = $this->savconf;

		// Default mode (disabled) when the constant is not set.
		$conf->global->API_ENABLE_CONTROL_APP_CONNEXION = '0';
		$mode = ApiAppIdentifier::getControlMode();
		print __METHOD__." mode=".$mode."\n";
		$this->assertEquals(ApiAppIdentifier::MODE_DISABLED, $mode);

		$conf->global->API_ENABLE_CONTROL_APP_CONNEXION = '2';
		$mode = ApiAppIdentifier::getControlMode();
		print __METHOD__." mode=".$mode."\n";
		$this->assertEquals(ApiAppIdentifier::MODE_STRICT, $mode);
	}

	/**
	 * Test the handshake: the first strict-mode call binds the signature/instance to the token.
	 *
	 * @return void
	 */
	public function testHandshakeBindsApplication()
	{
		global $db, $conf;
		$conf = $this->savconf;
		$db = $this->savdb;

		$this->assertNotEmpty(self::$tokenId, "A test token row must have been created in setUpBeforeClass");

		$appIdentifier = new ApiAppIdentifier($db);

		// Clear any previously bound signature.
		$db->query("UPDATE ".$db->prefix()."oauth_token SET app_signature = NULL, app_instance_token = NULL WHERE rowid = ".((int) self::$tokenId));

		// First strict call: no signature bound yet, so the handshake must bind it and allow access.
		list($allowed, $errcode) = $appIdentifier->validateApplication(self::$tokenId, self::TEST_SIGNATURE, self::TEST_INSTANCE);
		print __METHOD__." allowed=".var_export($allowed, true)." errcode=".$errcode."\n";
		$this->assertTrue($allowed);
		$this->assertEquals('', $errcode);

		// The signature must now be bound to the token.
		$metadata = $appIdentifier->fetchTokenMetadata(self::$tokenId);
		$this->assertIsArray($metadata);
		$this->assertEquals(self::TEST_SIGNATURE, $metadata['app_signature']);
		$this->assertEquals(self::TEST_INSTANCE, $metadata['app_instance_token']);
	}

	/**
	 * Test the strict validation: a call with a mismatching signature is rejected.
	 *
	 * @return void
	 */
	public function testStrictRejectsMismatchingSignature()
	{
		global $db, $conf;
		$conf = $this->savconf;
		$db = $this->savdb;

		$appIdentifier = new ApiAppIdentifier($db);

		// Bind a reference signature first.
		$appIdentifier->bindApplication(self::$tokenId, self::TEST_SIGNATURE, self::TEST_INSTANCE);

		// A call with a different signature must be rejected.
		list($allowed, $errcode) = $appIdentifier->validateApplication(self::$tokenId, 'wrong_signature', self::TEST_INSTANCE);
		print __METHOD__." allowed=".var_export($allowed, true)." errcode=".$errcode."\n";
		$this->assertFalse($allowed);
		$this->assertEquals('ApiErrorAppMismatch', $errcode);
	}

	/**
	 * Test the strict validation: a call with the matching signature is allowed.
	 *
	 * @return void
	 */
	public function testStrictAllowsMatchingSignature()
	{
		global $db, $conf;
		$conf = $this->savconf;
		$db = $this->savdb;

		$appIdentifier = new ApiAppIdentifier($db);

		// Bind the reference signature.
		$appIdentifier->bindApplication(self::$tokenId, self::TEST_SIGNATURE, self::TEST_INSTANCE);

		// A call with the same signature/instance must be allowed.
		list($allowed, $errcode) = $appIdentifier->validateApplication(self::$tokenId, self::TEST_SIGNATURE, self::TEST_INSTANCE);
		print __METHOD__." allowed=".var_export($allowed, true)." errcode=".$errcode."\n";
		$this->assertTrue($allowed);
		$this->assertEquals('', $errcode);
	}

	/**
	 * Test the access metadata update (last IP, app name, version, type).
	 *
	 * @return void
	 */
	public function testUpdateAccessMetadata()
	{
		global $db, $conf;
		$conf = $this->savconf;
		$db = $this->savdb;

		$appIdentifier = new ApiAppIdentifier($db);

		$result = $appIdentifier->updateAccessMetadata(self::$tokenId, 'MyTestApp', '1.0.0', 'Mobile');
		print __METHOD__." result=".$result."\n";
		$this->assertEquals(0, $result);

		$metadata = $appIdentifier->fetchTokenMetadata(self::$tokenId);
		$this->assertIsArray($metadata);
		$this->assertEquals('MyTestApp', $metadata['app_name']);
		$this->assertEquals('1.0.0', $metadata['app_version']);
		$this->assertEquals('Mobile', $metadata['app_type']);
		$this->assertNotEmpty($metadata['last_ip']);
	}
}
