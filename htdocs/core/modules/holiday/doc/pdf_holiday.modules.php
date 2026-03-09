<?php
/* Copyright (C) 2004-2014 Laurent Destailleur  <eldy@users.sourceforge.net>
 * Copyright (C) 2026      Anthony Berton       <bertonanthony@gmail.com>
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
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 * or see http://www.gnu.org/
 */

/**
 *	\file       htdocs/core/modules/propale/doc/pdf_azur.modules.php
 *	\ingroup    propale
 *	\brief      Fichier de la classe permettant de generer les propales au modele Propale
 */
require_once DOL_DOCUMENT_ROOT.'/core/modules/holiday/modules_holiday.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/functions2.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/pdf.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/extrafields.class.php'; //GM_Chargement des champs suplementaire



/**
 *	Class to generate PDF proposal GEST-MAG
 */
class pdf_holiday extends ModelePDFHoliday
{
	/**
	 * @var DoliDb Database handler
	 */
	public $db;

	/**
	 * @var string model name
	 */
	public $name;

	/**
	 * @var string model description (short text)
	 */
	public $description;

	/**
	 * @var string Save the name of generated file as the main doc when generating a doc with this template
	 */
	public $update_main_doc_field;

	/**
	 * @var string document type
	 */
	public $type;

	/**
	 * @var array() Minimum version of PHP required by module.
	 * e.g.: PHP ≥ 5.4 = array(5, 4)
	 */
	public $phpmin = array(5, 4);

	/**
	 * Dolibarr version of the loaded document
	 * @public string
	 */
	public $version = 'dolibarr';

	/**
	 * @var int page_largeur
	 */
	public $page_largeur;

	/**
	 * @var int page_hauteur
	 */
	public $page_hauteur;

	/**
	 * @var array format
	 */
	public $format;

	/**
	 * @var int marge_gauche
	 */
	public $marge_gauche;

	/**
	 * @var int marge_droite
	 */
	public $marge_droite;

	/**
	 * @var int marge_haute
	 */
	public $marge_haute;

	/**
	 * @var int marge_basse
	 */
	public $marge_basse;

	/**
	 * Issuer
	 * @var Company object that emits
	 */
	public $emetteur;


	/**
	 *	Constructor
	 *
	 *  @param		DoliDB		$db      Database handler
	 */
	public function __construct($db)
	{
		global $conf, $langs, $mysoc;

		// Translations
		$langs->loadLangs(array("main", "holiday"));

		$this->db = $db;
		$this->name = "Holiday";
		$this->description = $langs->trans('DocModelHolidayDescription');
		$this->update_main_doc_field = 1;		// Save the name of generated file as the main doc when generating a doc with this template

		// Dimension page
		$this->type = 'pdf';
		$formatarray=pdf_getFormat();
		$this->page_largeur = $formatarray['width'];
		$this->page_hauteur = $formatarray['height'];
		$this->format = array($this->page_largeur,$this->page_hauteur);
		$this->marge_gauche=isset($conf->global->MAIN_PDF_MARGIN_LEFT)?$conf->global->MAIN_PDF_MARGIN_LEFT:10;
		$this->marge_droite=isset($conf->global->MAIN_PDF_MARGIN_RIGHT)?$conf->global->MAIN_PDF_MARGIN_RIGHT:10;
		$this->marge_haute =isset($conf->global->MAIN_PDF_MARGIN_TOP)?$conf->global->MAIN_PDF_MARGIN_TOP:10;
		$this->marge_basse =isset($conf->global->MAIN_PDF_MARGIN_BOTTOM)?$conf->global->MAIN_PDF_MARGIN_BOTTOM:10;

		// Get source company
		$this->emetteur=$mysoc;
		if (empty($this->emetteur->country_code)) $this->emetteur->country_code=substr($langs->defaultlang, -2);    // By default, if was not defined
	}

    // phpcs:disable PEAR.NamingConventions.ValidFunctionName.ScopeNotCamelCaps
	/**
	 *  Function to build pdf onto disk
	 *
	 *  @param		Object		$object				Object to generate
	 *  @param		Translate	$outputlangs		Lang output object
	 *  @param		string		$srctemplatepath	Full path of source filename for generator using a template file
	 *  @param		int			$hidedetails		Do not show line details
	 *  @param		int			$hidedesc			Do not show desc
	 *  @param		int			$hideref			Do not show ref
	 *  @return     int             				1=OK, 0=KO
	 */
	public function write_file($object, $outputlangs, $srctemplatepath = '', $hidedetails = 0, $hidedesc = 0, $hideref = 0)
	{

        // phpcs:enable
		global $user, $langs, $conf, $mysoc, $db, $hookmanager;

		if (!is_object($outputlangs)) $outputlangs = $langs;
		// For backward compatibility with FPDF, force output charset to ISO, because FPDF expect text to be encoded in ISO
		if (! empty($conf->global->MAIN_USE_FPDF)) $outputlangs->charset_output='ISO-8859-1';

		if ($conf->holiday->multidir_output[1]) {
			$object->fetch_thirdparty();

			$deja_regle = 0;

			// Definition of $dir and $file
			if ($object->specimen) {
				$dir = $conf->holiday->multidir_output[1];
				$file = $dir."/SPECIMEN.pdf";
			} else {
				$objectref = dol_sanitizeFileName($object->ref);
				$dir = $conf->holiday->multidir_output[$object->entity]."/".$objectref;
				$file = $dir ."/".$objectref."-Holiday.pdf";
			}

			if (! file_exists($dir)) {
				if (dol_mkdir($dir) < 0) {
					$this->error=$langs->transnoentities("ErrorCanNotCreateDir", $dir);
					return 0;
				}
			}

			if (file_exists($dir)) {
				// Add pdfgeneration hook
				if (! is_object($hookmanager)) {
					include_once DOL_DOCUMENT_ROOT.'/core/class/hookmanager.class.php';
					$hookmanager=new HookManager($this->db);
				}
				$hookmanager->initHooks(array('pdfgeneration'));
				$parameters=array('file'=>$file,'object'=>$object,'outputlangs'=>$outputlangs);
				global $action;
				$reshook=$hookmanager->executeHooks('beforePDFCreation', $parameters, $object, $action);    // Note that $action and $object may have been modified by some hooks

				// Create pdf instance
					$pdf=pdf_getInstance($this->format);
					$default_font_size = pdf_getPDFFontSize($outputlangs);	// Must be after pdf_getInstance
					$pdf->SetAutoPageBreak(1, 0);

				if (class_exists('TCPDF')) {
					$pdf->setPrintHeader(false);
					$pdf->setPrintFooter(false);
				}
				$pdf->SetFont(pdf_getPDFFont($outputlangs));

				$pdf->Open();
				$pagenb=0;
				$pdf->SetDrawColor(128, 128, 128);

				$pdf->SetTitle($outputlangs->convToOutputCharset("Holiday")." - ".$outputlangs->convToOutputCharset($object->ref));
				$pdf->SetSubject($outputlangs->transnoentities("PdfUserHoliday"));
				$pdf->SetCreator("Dolibarr ".DOL_VERSION);
				$pdf->SetAuthor($outputlangs->convToOutputCharset($user->getFullName($outputlangs)));
				$pdf->SetKeyWords($outputlangs->convToOutputCharset($object->ref)." ".$outputlangs->transnoentities("PdfUserHoliday"));
				if (! empty($conf->global->MAIN_DISABLE_PDF_COMPRESSION)) $pdf->SetCompression(false);

				$pdf->SetMargins($this->marge_gauche, $this->marge_haute, $this->marge_droite);   // Left, Top, Right

				// New page
				$pdf->AddPage();
				if (! empty($tplidx)) $pdf->useTemplate($tplidx);
				$pagenb++;

				$heightforinfotot = 30;	// Height reserved to output the info and total part
				$heightforfooter = $this->marge_basse + 20;	// Height reserved to output the footer (value include bottom margin)
				if ($conf->global->MAIN_GENERATE_DOCUMENTS_SHOW_FOOT_DETAILS >0) $heightforfooter+= 6;

				$top_shift = $this->pagehead($pdf, $object, 1, $outputlangs);
				$pdf->SetFont('', '', $default_font_size - 1);
				$pdf->MultiCell(0, 3, '');		// Set interline to 3
				$pdf->SetTextColor(0, 0, 0);


				// Holiday
				$this->holiday($pdf, $object, $outputlangs, $top_shift);

				// Pied de page
				$this->pagefoot($pdf, $object, $outputlangs);


				$pdf->Close();

				$pdf->Output($file, 'F');

				//Add pdfgeneration hook
				$hookmanager->initHooks(array('pdfgeneration'));
				$parameters=array('file'=>$file,'object'=>$object,'outputlangs'=>$outputlangs);
				global $action;
				$reshook=$hookmanager->executeHooks('afterPDFCreation', $parameters, $this, $action);    // Note that $action and $object may have been modified by some hooks

				if (! empty($conf->global->MAIN_UMASK))
				@chmod($file, octdec($conf->global->MAIN_UMASK));

				$this->result = array('fullpath'=>$file);

				return 1;   // Pas d'erreur
			} else {
				$this->error=$langs->trans("ErrorCanNotCreateDir", $dir);
				return 0;
			}
		} else {
			$this->error=$langs->trans("ErrorConstantNotDefined", "PROP_OUTPUTDIR");
			return 0;
		}
	}


	/**
	 *  Show top header of page.
	 *
	 *  @param	PDF			$pdf     		Object PDF
	 *  @param  Object		$object     	Object to show
	 *  @param  int	    	$showaddress    0=no, 1=yes
	 *  @param  Translate	$outputlangs	Object lang for output
	 *  @return	void
	 */
	public function pagehead(&$pdf, $object, $showaddress, $outputlangs)
	{
		global $conf,$langs;

		// Load traductions files requiredby by page
		$outputlangs->loadLangs(array("main", "propal", "companies", "bills"));

		$default_font_size = pdf_getPDFFontSize($outputlangs);

		pdf_pagehead($pdf, $outputlangs, $this->page_hauteur);

		//  Show Draft Watermark
		if ($object->statut==0 && (! empty($conf->global->PROPALE_DRAFT_WATERMARK)) ) {
			pdf_watermark($pdf, $outputlangs, $this->page_hauteur, $this->page_largeur, 'mm', $conf->global->PROPALE_DRAFT_WATERMARK);
		}

		$pdf->SetTextColor(0, 0, 60);
		$pdf->SetFont('', 'B', $default_font_size + 3);

		$posy=$this->marge_haute;
		$posx=$this->page_largeur-$this->marge_droite-100;

		$pdf->SetXY($this->marge_gauche, $posy);



		// Logo
		if (empty($conf->global->PDF_DISABLE_MYCOMPANY_LOGO)) {
			$logo=$conf->mycompany->dir_output.'/logos/'.$this->emetteur->logo;
			if ($this->emetteur->logo) {
				$logodir = $conf->mycompany->dir_output;
				if (!empty($conf->mycompany->multidir_output[$object->entity])) {
					$logodir = $conf->mycompany->multidir_output[$object->entity];
				}
				if (empty($conf->global->MAIN_PDF_USE_LARGE_LOGO)) {
					$logo = $logodir.'/logos/thumbs/'.$this->emetteur->logo_small;
				} else {
					$logo = $logodir.'/logos/'.$this->emetteur->logo;
				}
				if (is_readable($logo)) {
					//GM_Logo
					$pdf->Image($logo, $this->marge_gauche + 1, $posy, 70, 0);
				} else {
					$pdf->SetTextColor(200, 0, 0);
					$pdf->SetFont('', 'B', $default_font_size - 2);
					$pdf->MultiCell(100, 3, $outputlangs->transnoentities("ErrorLogoFileNotFound", $logo), 0, 'L');
					$pdf->MultiCell(100, 3, $outputlangs->transnoentities("ErrorGoToGlobalSetup"), 0, 'L');
				}
			} else {
				$text=$this->emetteur->name;
				$pdf->MultiCell(100, 4, $outputlangs->convToOutputCharset($text), 0, 'C');
			}
		}

		//Titre
		$posx = $this->page_largeur/2;
		$widthbox = ($this->page_largeur - $this->marge_gauche - $this->marge_droite) / 2;
		$pdf->SetXY($posx, $posy);
		$pdf->MultiCell(100, 4, $outputlangs->transnoentities("Holiday").' '.$object->ref, 0, 'C');
		$pdf->SetXY($posx, $pdf->GetY());
		$pdf->MultiCell(100, 4, dol_print_date($object->date_valid, 'day'), 0, 'C');

		$top_shift = $posy + 20;
		return $top_shift;
	}

	/**
	 *  Holiday
	 *
	 *	@param	PDF			$pdf     		Object PDF
	 *  @param  Object		$object     	Object to show
	 *  @param  Translate	$outputlangs	Object lang for output
	 *  @param	int			$posy			Posy
	 *  @return				$pdf
	 */
	public function holiday(&$pdf, $object, $outputlangs, $posy)
	{
		global $db;

		$largeur = $this->page_largeur-$this->marge_gauche-$this->marge_droite;
		$widthbox = $largeur / 2;
		$heightbox = 20;
		$boxleftposx=$this->marge_gauche;
		$boxrightposx=$this->marge_gauche+$widthbox;


		$tmpUser = new User($db);
		$tmpUser->fetch($object->fk_user);
		$typeleaves = $object->getTypes(1, 1);
		$pdf->SetXY($boxleftposx, $posy);

		// Line
		$pdf->line($this->marge_gauche, $posy, $this->page_largeur-$this->marge_gauche, $posy);	// line prend une position y en 2eme param et 4eme param*/

		// Info user
		$posy = $pdf->GetY() + 5;
		$pdf->SetXY($boxleftposx, $posy);
		$pdf->SetFont('', 'B', 10);
		$pdf->MultiCell($widthbox, $heightbox, $outputlangs->transnoentities("User")." : ", 0, 'L');

		$pdf->SetXY($boxrightposx, $posy);
		$pdf->SetFont('', '', 10);
		$pdf->MultiCell($widthbox, $heightbox, $tmpUser->getFullName($outputlangs), 0, 'L');


		// Période demandé
		$posy = $pdf->GetY();
		$pdf->SetXY($boxleftposx, $posy);
		$pdf->SetFont('', 'B', 10);
		$pdf->MultiCell($widthbox, $heightbox, $outputlangs->transnoentities("Period")." : ", 0, 'L');

		$pdf->SetXY($boxrightposx, $posy);
		$pdf->SetFont('', '', 10);
		$pdf->MultiCell($widthbox, $heightbox, dol_print_date($object->date_debut, 'day')." au ".dol_print_date($object->date_fin, 'day'), 0, 'L');

		// Type de congé
		$posy = $pdf->GetY();
		$pdf->SetXY($boxleftposx, $posy);
		$pdf->SetFont('', 'B', 10);
		$pdf->MultiCell($widthbox, $heightbox, $outputlangs->transnoentities("LeaveType")." : ", 0, 'L');

		$pdf->SetXY($boxrightposx,  $posy);
		$pdf->SetFont('', '', 10);
		$pdf->MultiCell($widthbox, $heightbox, $typeleaves[$object->fk_type]['label'], 0, 'L');

		// Déscription
		$posy = $pdf->GetY();
		$pdf->SetXY($boxleftposx,  $posy);
		$pdf->SetFont('', 'B', 10);
		$pdf->MultiCell($widthbox, $heightbox, $outputlangs->transnoentities("Description")." : ", 0, 'L');

		$pdf->SetXY($boxrightposx, $posy);
		$pdf->SetFont('', '', 10);
		$pdf->MultiCell($widthbox, $heightbox, $object->description, 0, 'L');

		// Date
		$posy = $pdf->GetY();
		$pdf->SetXY($boxleftposx,  $posy);
		$pdf->SetFont('', 'B', 10);
		$pdf->writeHTML('Le '.date("j / m / Y").' (JJ/MM/AAAA) *');

		// Cadre signature
		$pdf->SetXY($boxrightposx, $posy);
		$pdf->MultiCell(60, 20, "", 1, 'L');
	}

	/**
	 *   	Show footer of page. Need this->emetteur object
	 *
	 * @param	PDF			$pdf     			PDF
	 * @param	Object		$object				Object to show
	 * @param	Translate	$outputlangs		Object lang for output
	 * @param	int			$hidefreetext		1=Hide free text
	 * @return				$pdf
	 */
	public function pagefoot(&$pdf, $object, $outputlangs, $hidefreetext = 0)
	{
		$pdf->SetXY($this->marge_gauche, $this->page_hauteur-$this->marge_basse-16);
	}
}
