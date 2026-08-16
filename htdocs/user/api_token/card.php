<?php
/* Copyright (C) 2005-2017  Laurent Destailleur         <eldy@users.sourceforge.net>
 * Copyright (C) 2010-2015  Regis Houssin               <regis.houssin@inodbox.com>
 * Copyright (C) 2013	    Florian Henry               <florian.henry@open-concept.pro.com>
 * Copyright (C) 2018       Ferran Marcet               <fmarcet@2byte.es>
 * Copyright (C) 2024-2025  Frédéric France             <frederic.france@free.fr>
 * Copyright (C) 2024-2025	MDW							<mdeweerd@users.noreply.github.com>
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
 *       \file       htdocs/user/api_toke/card.php
 *       \brief      Page to show user token and corresponding perm
 */

// Load Dolibarr environment
require '../../main.inc.php';
/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var HookManager $hookmanager
 * @var Translate $langs
 * @var User $user
 */
require_once DOL_DOCUMENT_ROOT.'/core/lib/functions2.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/usergroups.lib.php';
require_once DOL_DOCUMENT_ROOT.'/api/class/appidentifier.class.php';

// Load translation files required by page
$langs->loadLangs(array('admin', 'users', 'errors'));
$error = 0;

// Security check
$id = GETPOSTINT('id');
$action = GETPOST('action', 'aZ09');

if (empty($id) && $action != 'add' && $action != 'create') {
	accessforbidden();
}

$socid = 0;
if ($user->socid > 0) {
	$socid = $user->socid;
}
$feature2 = (($socid && $user->hasRight("user", "self", "write")) ? '' : 'user');

// Retrieve needed GETPOSTS for this file
$toselect = GETPOST('toselect', 'array');
$tokenid = GETPOST('tokenid', 'aZ09');
$confirm = GETPOST('confirm', 'alpha');
$module = GETPOST('module', 'alpha');
$rights = GETPOSTINT('rights');
$cancel = GETPOST('cancel', 'alpha');
$backtopage = GETPOST('backtopage', 'alpha');

// SQL query to retrieve the selected token
$sql = "SELECT oat.rowid as token_id, oat.token, oat.entity, oat.state as rights, oat.datec as date_creation, oat.tms as date_modification, oat.app_signature, oat.app_instance_token, oat.app_type, oat.app_name, oat.app_version, oat.last_ip, oat.lastaccess";
if (isModEnabled('multicompany')) {
	$sql .= ", e.label";
}
$sql .= " FROM ".MAIN_DB_PREFIX."oauth_token as oat";
if (isModEnabled('multicompany')) {
	$sql .= " JOIN ".$db->prefix()."entity as e ON oat.entity = e.rowid";
}
$sql .= " WHERE oat.rowid = ".((int) $tokenid);

$resql = $db->query($sql);

$object = new User($db);
$object->fetch($id, '', '', 1);
$object->loadRights();

// Deny access if user not using api
if (empty($object->api_key)) {
	accessforbidden();
}

$form = new Form($db);
$token = $db->fetch_object($resql);

$entity = $conf->entity;

$result = restrictedArea($user, 'user', $id, 'user&user', $feature2);

// $user is current user, $id is id of edited user
$canreaduser = ($user->admin || ($user->id == $id));
$canedittoken = ($user->admin || (($user->id == $id) && $user->hasRight("user", "self", "write")));

if (!$canreaduser) {
	accessforbidden();
}


/*
 * Actions
 */

$parameters = array('id' => $socid);
$reshook = $hookmanager->executeHooks('doActions', $parameters, $object, $action); // Note that $action and $object may have been modified by some hooks
if ($reshook < 0) {
	setEventMessages($hookmanager->error, $hookmanager->errors, 'errors');
}

if (empty($reshook)) {
	if (empty($backtopage)) {
		$backtopage = 'list.php?id='.$object->id;
	}

	if ($cancel) {
		if (!empty($backtopage)) {
			header("Location: ".$backtopage);
			exit;
		}
		$action = '';
	}

	if ($action == 'add' && $canedittoken) {
		$tokenstring = GETPOST('api_key', 'alphanohtml');
		$userid = GETPOSTINT('user');
		$useridtoadd = !empty($userid) && $userid > 0 ? $userid : $id;
		$appname = GETPOST('app_name', 'alphanohtml');
		$appversion = GETPOST('app_version', 'alphanohtml');
		$apptype = GETPOST('app_type', 'alphanohtml');

		if (empty($tokenstring)) {
			setEventMessages($langs->trans("ErrorFieldRequired", $langs->transnoentitiesnoconv("Token")), null, 'errors');
			$action = 'create';
			$error++;
		}

		if (empty($useridtoadd)) {
			setEventMessages($langs->trans("ErrorFieldRequired", $langs->transnoentitiesnoconv("User")), null, 'errors');
			$action = 'create';
			$error++;
		}

		// Check if a token already exists for the dolibarr api service duplicates
		$nbtotalofrecords = '';
		$sqlforcount = 'SELECT COUNT(*) as nbtotalofrecords';
		$sqlforcount .= " FROM ".MAIN_DB_PREFIX."oauth_token as oat";
		$sqlforcount .= " WHERE token = '".$db->escape(dolEncrypt($tokenstring, '', '', 'dolibarr'))."'";
		$sqlforcount .= " AND service = 'dolibarr_rest_api'";
		$resql = $db->query($sqlforcount);
		if ($resql) {
			$objforcount = $db->fetch_object($resql);
			$nbtotalofrecords = $objforcount->nbtotalofrecords;
		} else {
			dol_print_error($db);
			$error++;
		}

		if (isset($nbtotalofrecords) && $nbtotalofrecords > 0) {
			setEventMessages($langs->trans("ErrorFieldExist", $langs->transnoentitiesnoconv("Token")), null, 'errors');
			$action = 'create';
			$error++;
		}

		$db->begin();

		if (!$error) {
			// App metadata captured at creation (name/version/type). The app signature and instance token are NOT set here:
			// in strict mode they are auto-bound to the token on the first API call (handshake) by ApiAppIdentifier.
			$sql = "INSERT INTO ".MAIN_DB_PREFIX."oauth_token (service, token, state, fk_user, entity, datec, app_name, app_version, app_type)";
			$sql .= " VALUES ('dolibarr_rest_api', '".$db->escape(dolEncrypt($tokenstring, '', '', 'dolibarr'))."', 0, ".((int) $useridtoadd).", ".((int) $entity).", '".$db->idate(dol_now())."',";
			$sql .= ($appname !== '' ? " '".$db->escape($appname)."'" : " NULL").",";
			$sql .= ($appversion !== '' ? " '".$db->escape($appversion)."'" : " NULL").",";
			$sql .= ($apptype !== '' ? " '".$db->escape($apptype)."'" : " NULL").")";
			$resql = $db->query($sql);
			if (!$resql) {
				$error++;
			}

			// TODO Manage also ACL permission per token


			// TODO Manage also IP permission per token
		}

		if ($error) {
			dol_print_error($db);
			$db->rollback();
		} else {
			$insertedtokenid = $db->last_insert_id(MAIN_DB_PREFIX."oauth_token");
			$db->commit();

			header("Location: " . dolBuildUrl($_SERVER["PHP_SELF"], ['id' => $useridtoadd, 'tokenid' => $insertedtokenid]));
			exit;
		}
	} elseif ($action == 'confirm_delete' && $confirm == 'yes' && $canedittoken) {
		// Remove token
		$sql = "DELETE FROM ".MAIN_DB_PREFIX."oauth_token";
		$sql .= " WHERE rowid = ".((int) $tokenid);

		$resql = $db->query($sql);

		if ($resql) {
			header('Location: list.php?id='.((int) $object->id));
			exit;
		} else {
			dol_print_error($db);
		}
	} elseif (($action == 'validateapp' || $action == 'invalidateapp') && $user->admin && !empty($tokenid)) {
		// Admin validation of the application bound to the token (mode 3)
		$appIdentifier = new ApiAppIdentifier($db);
		$newstatus = ($action == 'validateapp') ? 1 : 0;
		$result = $appIdentifier->setAppStatus($tokenid, $newstatus);
		if ($result < 0) {
			setEventMessages($appIdentifier->error, $appIdentifier->errors, 'errors');
		} else {
			setEventMessages($langs->trans($newstatus ? 'AppStatusValidated' : 'AppStatusPending'), null, 'mesgs');
		}
		header("Location: ".dolBuildUrl($_SERVER["PHP_SELF"], ['id' => $object->id, 'tokenid' => $tokenid]));
		exit;
	}
}


/*
 * View
 */

if ($object->id > 0) {
	$person_name = !empty($object->firstname) ? $object->lastname.", ".$object->firstname : $object->lastname;
	$title = $person_name." - ".$langs->trans('ApiTokens');
} else {
	$title = $langs->trans("NewToken");
}
$help_url = '';

llxHeader('', $title, $help_url, '', 0, 0, '', '', '', 'mod-user page-card_param_ihm');

$formconfirm = '';

if ($action == 'delete') {
	$formconfirm = $form->formconfirm($_SERVER["PHP_SELF"].'?id='.$object->id.'&tokenid='.$token->token_id, $langs->trans('DeleteToken'), $langs->trans('ConfirmDeleteToken'), 'confirm_delete', '', 0, 1);
}

print $formconfirm;

if ($action == 'create') {
	print load_fiche_titre($title, '', 'user');
	print '<form action="'.$_SERVER["PHP_SELF"].'?id='.$object->id.'" method="post">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="add">';
	print '<input type="hidden" name="backtopage" value="'.$backtopage.'">';

	print dol_get_fiche_head();

	print '<table class="border centpercent tableforfieldcreate">';

	if ($user->admin && empty($id)) {
		print '<tr class="field_ref"><td class="titlefieldcreate fieldrequired">'.$langs->trans('User').'</td>';
		print '<td class="valuefieldcreate">';
		print $form->select_dolusers('', 'user', 1, null, 0, '', '', (string) $object->entity, 0, 0, '', 0, '', 'minwidth200 maxwidth500');
		print '</td></tr>';
	} else {
		print '<tr class="field_ref"><td class="titlefieldcreate fieldrequired">'.$langs->trans('User').'</td><td class="valuefieldcreate">'.($person_name ?? '').'</td></tr>';
	}

	print '<tr><td class="titlefieldcreate fieldrequired">'.$langs->trans("Token").'</td>';
	print '<td>';
	print '<input class="minwidth300 maxwidth400 widthcentpercentminusx" minlength="12" maxlength="128" type="text" id="api_key" name="api_key" value="'.GETPOST('api_key', 'alphanohtml').'" autocomplete="off">';
	if (!empty($conf->use_javascript_ajax)) {
		print img_picto($langs->transnoentities('Generate'), 'refresh', 'id="generate_api_key" class="linkobject paddingleft"');
	}
	print '</td></tr>';

	// Application installation binding (security: unique token per app installation and user).
	print '<tr><td class="titlefieldcreate">'.$langs->trans("ApplicationName").'</td>';
	print '<td>';
	print '<input class="minwidth300 maxwidth400 widthcentpercentminusx" maxlength="255" type="text" id="app_name" name="app_name" value="'.GETPOST('app_name', 'alphanohtml').'" placeholder="'.$langs->transnoentitiesnoconv('ApplicationNameExample').'" autocomplete="off">';
	print '</td></tr>';
	print '<tr><td class="titlefieldcreate">'.$langs->trans("ApplicationVersion").'</td>';
	print '<td>';
	print '<input class="minwidth300 maxwidth400 widthcentpercentminusx" maxlength="64" type="text" id="app_version" name="app_version" value="'.GETPOST('app_version', 'alphanohtml').'" placeholder="'.$langs->transnoentitiesnoconv('ApplicationVersionExample').'" autocomplete="off">';
	print '</td></tr>';
	print '<tr><td class="titlefieldcreate">'.$langs->trans("ApplicationType").'</td>';
	print '<td>';
	print '<input class="minwidth300 maxwidth400 widthcentpercentminusx" maxlength="20" type="text" id="app_type" name="app_type" value="'.GETPOST('app_type', 'alphanohtml').'" placeholder="'.$langs->transnoentitiesnoconv('ApplicationTypeExample').'" autocomplete="off">';
	print '</td></tr>';
	print "</table>\n";

	print dol_get_fiche_end();

	print '<div class="center">';
	print '<input class="button" name="add" value="'.$langs->trans("Create").'" type="submit">';
	print '<input class="button button-cancel" value="'.$langs->trans("Cancel").'" name="cancel" type="submit">';
	print '</div>';

	print "</form>";
} elseif ($id > 0 && !empty($token)) {
	$arrayofselected = is_array($toselect) ? $toselect : array();

	$head = user_prepare_head($object);

	$title = $langs->trans("User");

	print dol_get_fiche_head($head, 'apitoken', $title, -1, 'user');

	$tokenvalue = dolDecrypt($token->token);

	$linkback  = '<a href="'.DOL_URL_ROOT.'/user/api_token/list.php?id='.$id.'">'.$langs->trans("BackToTokenList").'</a>';
	$linkback .= '<a href="'.DOL_URL_ROOT.'/user/list.php">'.$langs->trans("BackToList").'</a>';

	$morehtmlref = '<a href="'.DOL_URL_ROOT.'/user/vcard.php?id='.$object->id.'&output=file&file='.urlencode(dol_sanitizeFileName($object->getFullName($langs).'.vcf')).'" class="refid" rel="noopener">';
	$morehtmlref .= img_picto($langs->trans("Download").' '.$langs->trans("VCard"), 'vcard.png', 'class="valignmiddle marginleftonly paddingrightonly"');
	$morehtmlref .= '</a>';

	$urltovirtualcard = '/user/virtualcard.php?id='.((int) $object->id);
	$morehtmlref .= dolButtonToOpenUrlInDialogPopup('publicvirtualcard', $langs->transnoentitiesnoconv("PublicVirtualCardUrl").' - '.$object->getFullName($langs), img_picto($langs->trans("PublicVirtualCardUrl"), 'card', 'class="valignmiddle marginleftonly paddingrightonly"'), $urltovirtualcard, '', 'nohover');

	dol_banner_tab($object, 'api_token_card', $linkback, $user->admin, 'rowid', 'ref', $morehtmlref);

	// Tokens info
	print '<div class="fichecenter">';
	print '<div class="underbanner clearboth"></div>';
	print '<table class="border centpercent tableforfield">';

	// Login
	print '<tr><td class="titlefield">'.$langs->trans("Login").'</td>';
	if (!empty($object->ldap_sid) && $object->status == 0) {
		print '<td class="error">';
		print $langs->trans("LoginAccountDisableInDolibarr");
		print '</td>';
	} else {
		print '<td>';
		$addadmin = '';
		if (isModEnabled('multicompany') && !empty($object->admin) && empty($object->entity)) {
			$addadmin .= img_picto($langs->trans("SuperAdministratorDesc"), "superadmin", 'class="paddingleft valignmiddle"');
		} elseif (!empty($object->admin)) {
			$addadmin .= img_picto($langs->trans("AdministratorDesc"), "admin", 'class="paddingleft valignmiddle"');
		}
		print showValueWithClipboardCPButton($object->login).$addadmin;
		print '</td>';
	}
	print '</tr>'."\n";

	// Token
	print '<tr><td class="titlefield">'.$langs->trans("Token").'</td>';
	print '<td>';
	print showValueWithClipboardCPButton($tokenvalue, 1, $tokenvalue);
	print '</td>';
	print '</tr>'."\n";

	// Creation date
	print '<tr><td class="titlefield">'.$langs->trans("DateCreation").'</td>';
	print '<td>';
	print dol_print_date($db->jdate($token->date_creation), 'dayhour');
	print '</td>';
	print '</tr>'."\n";

	// Modification date
	print '<tr><td class="titlefield">'.$langs->trans("DateModification").'</td>';
	print '<td>';
	print dol_print_date($db->jdate($token->date_modification), 'dayhour');
	print '</td>';
	print '</tr>'."\n";

	// Application installation binding (security: unique token per app installation and user)
	print '<tr><td class="titlefield">'.$langs->trans("ApplicationName").'</td>';
	print '<td>'.(empty($token->app_name) ? '<span class="opacitymedium">'.$langs->trans('NotDefined').'</span>' : dol_escape_htmltag($token->app_name)).'</td>';
	print '</tr>'."\n";

	print '<tr><td class="titlefield">'.$langs->trans("ApplicationVersion").'</td>';
	print '<td>'.(empty($token->app_version) ? '<span class="opacitymedium">'.$langs->trans('NotDefined').'</span>' : dol_escape_htmltag($token->app_version)).'</td>';
	print '</tr>'."\n";

	print '<tr><td class="titlefield">'.$langs->trans("ApplicationSignature").'</td>';
	print '<td>'.(empty($token->app_signature) ? '<span class="opacitymedium">'.$langs->trans('NotBoundByHandshake').'</span>' : showValueWithClipboardCPButton($token->app_signature)).'</td>';
	print '</tr>'."\n";

	print '<tr><td class="titlefield">'.$langs->trans("ApplicationInstance").'</td>';
	print '<td>'.(empty($token->app_instance_token) ? '<span class="opacitymedium">'.$langs->trans('NotBoundByHandshake').'</span>' : showValueWithClipboardCPButton($token->app_instance_token)).'</td>';
	print '</tr>'."\n";

	print '<tr><td class="titlefield">'.$langs->trans("ApplicationType").'</td>';
	print '<td>'.(empty($token->app_type) ? '<span class="opacitymedium">'.$langs->trans('NotDefined').'</span>' : dol_escape_htmltag($token->app_type)).'</td>';
	print '</tr>'."\n";

	// Application validation status (mode 3: admin validation)
	print '<tr><td class="titlefield">'.$langs->trans("AppStatus").'</td>';
	print '<td>';
	if (empty($token->app_signature)) {
		print '<span class="opacitymedium">'.$langs->trans('NotBoundByHandshake').'</span>';
	} else {
		print ($token->app_status == 1) ? '<span class="badge badge-status4">'.$langs->trans('AppStatusValidated').'</span>' : '<span class="badge badge-status8">'.$langs->trans('AppStatusPending').'</span>';
	}
	print '</td>';
	print '</tr>'."\n";

	print '<tr><td class="titlefield">'.$langs->trans("LastAccessIP").'</td>';
	print '<td>'.(empty($token->last_ip) ? '<span class="opacitymedium">'.$langs->trans('NotRecorded').'</span>' : dol_escape_htmltag($token->last_ip)).'</td>';
	print '</tr>'."\n";

	print '<tr><td class="titlefield">'.$langs->trans("LastAccess").'</td>';
	print '<td>'.(empty($token->lastaccess) ? '<span class="opacitymedium">'.$langs->trans('NotRecorded').'</span>' : dol_print_date($db->jdate($token->lastaccess), 'dayhour')).'</td>';
	print '</tr>'."\n";

	print '</table>';
	print '<div class="tabsAction">';
	// Admin validation buttons (only when an app is bound to the token)
	if ($user->admin && !empty($token->app_signature)) {
		if ($token->app_status != 1) {
			print dolGetButtonAction($langs->trans('ValidateApp'), '', 'default', $_SERVER["PHP_SELF"].'?id='.$object->id.'&tokenid='.$token->token_id.'&action=validateapp&token='.newToken(), '', $user->admin);
		} else {
			print dolGetButtonAction($langs->trans('InvalidateApp'), '', 'delete', $_SERVER["PHP_SELF"].'?id='.$object->id.'&tokenid='.$token->token_id.'&action=invalidateapp&token='.newToken(), '', $user->admin);
		}
	}
	print dolGetButtonAction($langs->trans('Delete'), '', 'delete', $_SERVER["PHP_SELF"].'?id='.$object->id.'&tokenid='.$token->token_id.'&action=delete&token='.newToken(), '', $canedittoken);
	print '</div>';
	print '</div>';

	print dol_get_fiche_end();


	print load_fiche_titre($langs->trans("ListOfRightsForToken"), '', 'fa-at');

	print '<!-- Rights section -->'."\n";

	if ($user->admin) {
		print info_admin($langs->trans("WarningOnlyPermissionOfActivatedModules"));
	}

	print 'TODO If no ACL given, show message to say permissions are the one of user. If ACL set, show ACL active (common to user permission)and ACL no more active (not own by user)';
}

if (isModEnabled('api') && $action == 'create') {
	include_once DOL_DOCUMENT_ROOT.'/core/lib/security2.lib.php';
	print dolJSToSetRandomPassword('api_key', 'generate_api_key', 1);
}

// End of page
llxFooter();
$db->close();
