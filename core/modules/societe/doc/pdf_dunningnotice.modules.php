<?php
/* Copyright (C) 2026		Maxime DION				<m.dion@guiltek.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

declare(strict_types=1);

/**
 * \file    dunning/core/modules/societe/doc/pdf_dunningnotice.modules.php
 * \ingroup dunning
 * \brief   Formal notice to pay of a customer (PDF): its late invoices, the penalties due and the payment details
 */

require_once DOL_DOCUMENT_ROOT.'/core/modules/societe/modules_societe.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/pdf.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/company.lib.php';
dol_include_once('/dunning/class/dunning.class.php');


/**
 * Formal notice to pay of a customer: a document of the thirdparty (tab Documents), that a reminder level can attach to its email
 */
class pdf_dunningnotice extends ModeleThirdPartyDoc
{
	/**
	 * Constructor
	 *
	 * @param	DoliDB	$db		Database handler
	 */
	public function __construct($db)
	{
		global $langs, $mysoc;

		$langs->load('dunning@dunning');
		$this->db = $db;
		$this->name = 'dunningnotice';
		$this->description = $langs->trans('DunningNoticeModel');
		$this->type = 'pdf';
		$formatarray = pdf_getFormat();
		$this->page_largeur = $formatarray['width'];
		$this->page_hauteur = $formatarray['height'];
		$this->format = array($this->page_largeur, $this->page_hauteur);
		$this->marge_gauche = getDolGlobalInt('MAIN_PDF_MARGIN_LEFT', 10);
		$this->marge_droite = getDolGlobalInt('MAIN_PDF_MARGIN_RIGHT', 10);
		$this->marge_haute = getDolGlobalInt('MAIN_PDF_MARGIN_TOP', 10);
		$this->marge_basse = getDolGlobalInt('MAIN_PDF_MARGIN_BOTTOM', 10);
		$this->emetteur = $mysoc;
	}

	// phpcs:disable PEAR.NamingConventions.ValidFunctionName.ScopeNotCamelCaps
	/**
	 * Write the formal notice of a customer in its directory of documents
	 *
	 * @param	Societe		$object				Customer
	 * @param	Translate	$outputlangs		Language of the document
	 * @param	string		$srctemplatepath	Not used
	 * @param	int<0,1>	$hidedetails		Not used
	 * @param	int<0,1>	$hidedesc			Not used
	 * @param	int<0,1>	$hideref			Not used
	 * @return	int<-1,1>						1 if OK, <=0 if KO (no late invoice, error)
	 */
	public function write_file($object, $outputlangs, $srctemplatepath = '', $hidedetails = 0, $hidedesc = 0, $hideref = 0)
	{
		// phpcs:enable
		global $conf, $langs, $user, $mysoc;

		if (!is_object($outputlangs)) {
			$outputlangs = $langs;
		}
		$outputlangs->loadLangs(array('main', 'dict', 'companies', 'bills', 'dunning@dunning'));

		// The late invoices of the customer, with the penalties due today
		$dunning = new Dunning($this->db);
		$now = dol_now();
		$rows = $dunning->getInvoices($user, $now, array((int) $object->id));
		if (!is_array($rows)) {
			$this->error = $dunning->error;
			return -1;
		}
		foreach ($rows as $id => $row) {
			if ($row->refdate >= dol_get_first_hour($now)) {
				unset($rows[$id]);
			}
		}
		if (empty($rows)) {
			$this->error = $outputlangs->trans('DunningNoticeNoLateInvoice');
			return 0;
		}
		$subst = $dunning->getSubstitutions($rows, (object) array('label' => ''), $outputlangs, $now);

		$dir = $conf->societe->multidir_output[$object->entity ?: $conf->entity].'/'.((int) $object->id);
		$file = $dir.'/'.dol_sanitizeFileName($outputlangs->transnoentitiesnoconv('DunningNoticeFile').'-'.dol_print_date($now, 'dayrfc')).'.pdf';
		if (dol_mkdir($dir) < 0) {
			$this->error = $outputlangs->transnoentities('ErrorCanNotCreateDir', $dir);
			return -1;
		}

		$pdf = pdf_getInstance($this->format);
		$fontsize = pdf_getPDFFontSize($outputlangs);
		$pdf->setAutoPageBreak(true, $this->marge_basse + 20);
		if (class_exists('TCPDF')) {
			$pdf->setPrintHeader(false);
			$pdf->setPrintFooter(false);
		}
		$pdf->SetFont(pdf_getPDFFont($outputlangs));
		$pdf->Open();
		$pdf->SetTitle($outputlangs->convToOutputCharset($outputlangs->transnoentities('DunningNoticeSubject')));
		$pdf->SetCreator('Dolibarr '.DOL_VERSION);
		$pdf->SetMargins($this->marge_gauche, $this->marge_haute, $this->marge_droite);
		$pdf->AddPage();
		pdf_pagehead($pdf, $outputlangs, $this->page_hauteur);

		// Sender: logo or name, then address
		$posy = $this->marge_haute;
		$logo = $conf->mycompany->dir_output.'/logos/'.$mysoc->logo;
		$pdf->SetXY($this->marge_gauche, $posy);
		if ($mysoc->logo && is_readable($logo)) {
			$height = pdf_getHeightForLogo($logo);
			$pdf->Image($logo, $this->marge_gauche, $posy, 0, $height);
			$pdf->SetY($posy + $height + 2);
		} else {
			$pdf->SetFont('', 'B', $fontsize + 2);
			$pdf->MultiCell(100, 5, $outputlangs->convToOutputCharset($mysoc->name), 0, 'L');
		}
		$pdf->SetFont('', '', $fontsize - 1);
		$pdf->SetX($this->marge_gauche);
		$pdf->MultiCell(90, 4, pdf_build_address($outputlangs, $mysoc), 0, 'L');

		// Recipient
		$posx = $this->page_largeur - $this->marge_droite - 90;
		$pdf->SetXY($posx, $this->marge_haute + 35);
		$pdf->SetFont('', 'B', $fontsize);
		$pdf->MultiCell(90, 5, pdfBuildThirdpartyName($object, $outputlangs), 0, 'L');
		$pdf->SetX($posx);
		$pdf->SetFont('', '', $fontsize - 1);
		$pdf->MultiCell(90, 4, pdf_build_address($outputlangs, $mysoc, $object, '', 0, 'target'), 0, 'L');

		// Letter, with the table of the invoices of the reminders
		$html = '<p style="text-align: right">'.dolPrintLabel(trim($mysoc->town.', '.dol_print_date($now, 'daytext', 'tzserver', $outputlangs), ', ')).'</p>';
		$html .= '<p><b>'.$outputlangs->trans('DunningNoticeSubject').'</b></p>';
		$html .= '<p>'.$outputlangs->trans('DunningNoticeIntro').'</p>'.$subst['__DUNNING_INVOICES__'];
		$html .= '<p>'.$outputlangs->trans('DunningNoticeTotal', $subst['__DUNNING_TOTAL_REMAIN__']).'</p>';
		$html .= ($subst['__DUNNING_PENALTIES_TEXT__'] !== '' ? '<p>'.dolPrintLabel($subst['__DUNNING_PENALTIES_TEXT__']).'</p>' : '');
		$html .= '<p>'.$outputlangs->trans('DunningNoticeBody', $subst['__DUNNING_TOTAL_WITH_PENALTIES__']).'</p>';
		$html .= '<p>'.$outputlangs->trans('DunningNoticeClosing').'</p><p>'.dolPrintLabel($mysoc->name).'</p>';
		$pdf->SetFont('', '', $fontsize - 1);
		$pdf->writeHTMLCell($this->page_largeur - $this->marge_gauche - $this->marge_droite, 0, $this->marge_gauche, $this->marge_haute + 70, $html, 0, 1);

		// Bank account of the invoices, for a payment by transfer
		if (isModEnabled('bank') && getDolGlobalInt('FACTURE_RIB_NUMBER') > 0) {
			require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/account.class.php';
			$account = new Account($this->db);
			if ($account->fetch(getDolGlobalInt('FACTURE_RIB_NUMBER')) > 0) {
				pdf_bank($pdf, $outputlangs, $this->marge_gauche, $pdf->GetY() + 4, $account, 0, $fontsize);
			}
		}

		$pdf->setAutoPageBreak(false);	// The footer is written in the bottom margin
		pdf_pagefoot($pdf, $outputlangs, '', $mysoc, $this->marge_basse, $this->marge_gauche, $this->page_hauteur, $object, 0, 1);
		$pdf->Close();
		$pdf->Output($file, 'F');
		dolChmod($file);
		$this->result = array('fullpath' => $file);

		return 1;
	}
}
