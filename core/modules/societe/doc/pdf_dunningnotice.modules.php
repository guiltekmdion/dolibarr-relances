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
	 * @var bool True for the statement of account: all the invoices to pay and their age, without formal notice
	 */
	protected $statement = false;

	/**
	 * @var ?array<int,stdClass> Invoices of the formal notice attached to an email: the invoices and the total of the email, else read again
	 */
	public $rows = null;

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

		// The invoices of the email, else the late invoices of the customer with the penalties due today, or all its invoices to pay for a statement
		$dunning = new Dunning($this->db);
		$now = dol_now();
		$rows = $this->rows;
		if (!is_array($rows)) {
			$rows = $dunning->getInvoices($user, $now, array((int) $object->id), 0, 0, $this->statement);
			if (!is_array($rows)) {
				$this->error = $dunning->error;
				return -1;
			}
			foreach ($rows as $id => $row) {
				if (!$this->statement && $row->refdate >= dol_get_first_hour($now)) {
					unset($rows[$id]);
				}
			}
		}
		if (empty($rows)) {
			$this->error = $outputlangs->trans($this->statement ? 'DunningStatementNoInvoice' : 'DunningNoticeNoLateInvoice');
			return 0;
		}
		$prefix = ($this->statement ? 'DunningStatement' : 'DunningNotice');

		$dir = $conf->societe->multidir_output[$object->entity ?: $conf->entity].'/'.((int) $object->id);
		$first = (is_array($this->rows) ? reset($this->rows) : null);	// The notice of an email: one file per email, not one per day
		$file = $dir.'/'.dol_sanitizeFileName($outputlangs->transnoentitiesnoconv($prefix.'File').'-'.dol_print_date($now, 'dayrfc').($first ? '-'.$first->ref : '')).'.pdf';
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
		$pdf->SetTitle($outputlangs->convToOutputCharset($outputlangs->transnoentities($prefix.'Subject')));
		$pdf->SetCreator('Dolibarr '.DOL_VERSION);
		$pdf->SetKeyWords($outputlangs->convToOutputCharset(implode(' ', array_column($rows, 'ref'))));	// The invoices of the document
		$pdf->SetMargins($this->marge_gauche, $this->marge_haute, $this->marge_droite);	// @phan-suppress-current-line PhanPluginSuspiciousParamOrder
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
		$pdf->MultiCell(90, 4, pdf_build_address($outputlangs, $mysoc, $object, '', 0, 'target'), 0, 'L');	// @phan-suppress-current-line PhanPluginSuspiciousParamPosition

		// Letter, with the table of the invoices of the reminders
		$html = $this->getLetter($object, $rows, $outputlangs, $now);
		$pdf->SetFont('', '', $fontsize - 1);
		$pdf->writeHTMLCell($this->page_largeur - $this->marge_gauche - $this->marge_droite, 0, $this->marge_gauche, $this->marge_haute + 70, $html, 0, 1);

		// Bank account of the invoices, for a payment by transfer: none if all the invoices are taken by direct debit
		if (isModEnabled('bank') && getDolGlobalInt('FACTURE_RIB_NUMBER') > 0 && count(array_filter(array_column($rows, 'debitinprogress'))) < count($rows)) {
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

	/**
	 * Text of the letter: subject, invoices, totals, ages (statement) or penalties and formal notice, credits of the customer, closing
	 *
	 * @param	Societe				$object			Customer
	 * @param	array<int,stdClass>	$rows			Invoices, as returned by Dunning::getInvoices()
	 * @param	Translate			$outputlangs	Language of the document
	 * @param	int					$now			Date of the document
	 * @return	string								HTML
	 */
	public function getLetter(Societe $object, array $rows, Translate $outputlangs, $now)
	{
		global $conf, $mysoc;

		$dunning = new Dunning($this->db);
		$subst = $dunning->getSubstitutions($rows, (object) array('label' => ''), $outputlangs, $now);
		$prefix = ($this->statement ? 'DunningStatement' : 'DunningNotice');
		$html = '<p style="text-align: right">'.dolPrintLabel(trim($mysoc->town.', '.dol_print_date($now, 'daytext', 'tzserver', $outputlangs), ', ')).'</p>';
		$html .= '<p><b>'.$outputlangs->trans($prefix.'Subject').'</b></p>';
		$reminded = !empty(array_filter(array_column($rows, 'last')));	// "Despite our previous reminders" only after one
		$html .= '<p>'.$outputlangs->trans($prefix.'Intro'.($this->statement || $reminded ? '' : 'First')).'</p>'.$subst['__DUNNING_INVOICES__'];
		$html .= '<p>'.$outputlangs->trans('DunningNoticeTotal', $subst['__DUNNING_TOTAL_REMAIN__']).'</p>';
		if ($this->statement) {	// The aged balance, then a simple closing
			$html .= '<table border="1" cellpadding="4" style="border-collapse: collapse"><tr>';
			$aging = $dunning->getAging($rows, $now);
			foreach (array_keys($aging) as $key) {
				$html .= '<th>'.$outputlangs->trans(Dunning::AGINGS[$key]).'</th>';
			}
			$html .= '</tr><tr>';
			foreach ($aging as $amount) {
				$html .= '<td style="text-align: right">'.price($amount, 0, $outputlangs, 1, -1, -1, $conf->currency).'</td>';
			}
			$html .= '</tr></table>';
		} else {
			$html .= ($subst['__DUNNING_PENALTIES_TEXT__'] !== '' ? '<p>'.dolPrintLabel($subst['__DUNNING_PENALTIES_TEXT__']).'</p>' : '');
			$html .= '<p>'.$outputlangs->trans('DunningNoticeBody', $subst['__DUNNING_TOTAL_WITH_PENALTIES__']).'</p>';
		}
		// Credit notes and discounts of the customer not used yet, read by the core: to deduct before paying
		$credit = (float) $object->getAvailableDiscounts();
		$creditnotes = (int) $object->getOpenCreditNotesNotYetConvertedIntoDiscount();
		if ($credit > 0 || $creditnotes > 0) {
			$html .= '<p>'.($credit > 0 ? $outputlangs->trans('DunningCreditAvailable', price($credit, 0, $outputlangs, 1, -1, -1, $conf->currency)).' ' : '');
			$html .= ($creditnotes > 0 ? $outputlangs->trans('DunningCreditNotesOpen', (string) $creditnotes) : '').'</p>';
		}
		$html .= '<p>'.$outputlangs->trans($prefix.'Closing').'</p><p>'.dolPrintLabel($mysoc->name).'</p>';

		return $html;
	}
}
